<?php
declare(strict_types=1);
namespace App\Gateway;

use App\Core\Http;
use App\Services\Billing;
use App\Services\Channels;

/** Anthropic Claude Messages 兼容网关：POST /v1/messages（透传 anthropic_compatible 渠道） */
final class AnthropicController
{
    public static function messages(array $p = []): never
    {
        $t0 = microtime(true);
        $auth = GatewayAuth::authenticate();
        $key = $auth['key'];
        $body = Http::body();
        $model = (string)($body['model'] ?? '');
        if ($model === '') { Http::apiError('缺少 model 参数', 'invalid_request_error', 400); }
        $stream = (bool)($body['stream'] ?? false);
        $requestId = 'req_' . bin2hex(random_bytes(16));

        $channels = GatewayAuth::checkModel($model, $key);
        $multiplier = GatewayAuth::groupMultiplier($key['group_id'] !== null ? (int)$key['group_id'] : null);
        $lastError = null;

        foreach ($channels as $ch) {
            if ($ch['type'] !== 'anthropic_compatible') { $lastError = '无 anthropic 兼容渠道'; continue; }
            $upstreamModel = Channels::upstreamModel($ch, $model);
            if (!Billing::startRequest($requestId, (int)$key['user_id'], (int)$key['id'], (int)$ch['id'], $model, $upstreamModel, $stream, $multiplier)) {
                if (!Billing::reattach($requestId, (int)$ch['id'], $upstreamModel)) {
                    Http::apiError('重复的请求', 'invalid_request_error', 409);
                }
            }
            $url = rtrim($ch['base_url'], '/') . '/v1/messages';
            $payload = $body;
            $payload['model'] = $upstreamModel;
            $headers = ['x-api-key: ' . Channels::decryptedKey($ch), 'anthropic-version: 2023-06-01'];
            $connectTimeout = (int)$ch['connect_timeout'];
            $timeout = (int)$ch['request_timeout'];

            if (!$stream) {
                $res = UpstreamClient::postJson($url, $headers, $payload, $connectTimeout, $timeout);
                $duration = (int)((microtime(true) - $t0) * 1000);
                if ($res['error'] !== null || $res['status'] === 0) {
                    $lastError = $res['error'] ?? '网络错误';
                    Billing::fail($requestId, $lastError, $duration);
                    Channels::recordError((int)$ch['id'], $lastError);
                    continue;
                }
                if ($res['status'] >= 400) {
                    $lastError = self::extractError($res['body']) . ' (HTTP ' . $res['status'] . ')';
                    Billing::fail($requestId, $lastError, $duration);
                    Channels::recordError((int)$ch['id'], $lastError);
                    Http::json($res['body'], $res['status']);
                }
                $usage = is_array($res['body']['usage'] ?? null) ? Billing::normalizeUsage($res['body']['usage']) : null;
                $source = $usage !== null ? 'upstream' : 'estimated';
                if ($usage === null) { $usage = OpenAIController::estimatePublic($body, null); }
                Channels::recordSuccess((int)$ch['id']);
                Billing::settle($requestId, $usage, $source, $duration);
                Http::json($res['body']);
            } else {
                $streamUsage = null;
                $clientAborted = false;
                $sawDone = false;
                self::sseHeaders();
                $onChunk = function (string $data) use (&$streamUsage, &$clientAborted, &$sawDone) {
                    echo $data;
                    while (ob_get_level() > 0) { @ob_flush(); }
                    flush();
                    if (connection_aborted()) { $clientAborted = true; return; }
                    if (str_contains($data, '[DONE]') || (is_array($j ?? null) && ($j['type'] ?? '') === 'message_stop')) { $sawDone = true; }
                    foreach (preg_split('/\r?\n/', $data) ?: [] as $line) {
                        if (preg_match('/^data:\s*(\{.*)$/', $line, $m)) {
                            $j = json_decode($m[1], true);
                            if (is_array($j) && is_array($j['usage'] ?? null)) { $streamUsage = Billing::normalizeUsage($j['usage']); }
                            if (is_array($j) && ($j['type'] ?? '') === 'message_stop') { $sawDone = true; }
                        }
                    }
                };
                $res = UpstreamClient::postSse($url, $headers, $payload, $onChunk, $connectTimeout, $timeout);
                $duration = (int)((microtime(true) - $t0) * 1000);
                if (!$res['sent']) {
                    $lastError = $res['error'] ?? '上游无响应';
                    Billing::fail($requestId, $lastError, $duration);
                    Channels::recordError((int)$ch['id'], $lastError);
                    continue;
                }
                if (($res['error'] !== null || !$sawDone) && !$clientAborted) {
                    Billing::fail($requestId, '流式响应中断: ' . mb_substr($res['error'] ?? '上游流未正常结束', 0, 300), $duration);
                    exit;
                }
                Channels::recordSuccess((int)$ch['id']);
                $usage = $streamUsage ?? OpenAIController::estimatePublic($body, null);
                $source = $streamUsage !== null ? 'upstream' : 'estimated';
                Billing::settle($requestId, $usage, $source, $duration, $clientAborted ? '客户端中断流式响应' : null);
                exit;
            }
        }
        Billing::fail($requestId, $lastError ?? '无可用渠道');
        Http::apiError('所有上游渠道请求失败: ' . ($lastError ?? 'unknown'), 'upstream_error', 502);
    }

    /** Anthropic usage → 统一结构 */
    private static function normalizeUsage(array $u, ?array $prev = null): array
    {
        $in = (int)($u['input_tokens'] ?? ($prev['input_tokens'] ?? 0));
        $out = (int)($u['output_tokens'] ?? ($prev['output_tokens'] ?? 0));
        return ['input_tokens' => $in, 'output_tokens' => $out];
    }

    private static function sseHeaders(): void
    {
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        while (ob_get_level() > 0) { ob_end_flush(); }
        flush();
    }

    private static function extractError(?array $body): string
    {
        $msg = $body['error']['message'] ?? $body['message'] ?? null;
        return is_string($msg) ? $msg : '未知上游错误';
    }
}
