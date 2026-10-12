<?php
declare(strict_types=1);
namespace App\Gateway;

use App\Core\Database as DB;
use App\Core\Http;
use App\Services\Billing;
use App\Services\Channels;

/** OpenAI 兼容网关：GET /v1/models、POST /v1/chat/completions */
final class OpenAIController
{
    public static function models(array $p = []): never
    {
        GatewayAuth::authenticate();
        $rows = DB::all("SELECT models FROM channels WHERE status = 'active'");
        $models = [];
        $prices = [];
        foreach (DB::all('SELECT model FROM model_prices WHERE enabled = 1') as $p) { $prices[$p['model']] = true; }
        foreach ($rows as $r) {
            foreach (Channels::channelModels($r) as $m) {
                $models[$m] = $m;
            }
        }
        ksort($models);
        $data = [];
        foreach (array_keys($models) as $m) {
            $data[] = ['id' => $m, 'object' => 'model', 'created' => 1700000000, 'owned_by' => 'sub2api-php'];
        }
        Http::json(['object' => 'list', 'data' => $data]);
    }

    public static function chatCompletions(array $p = []): never
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
            $upstreamModel = Channels::upstreamModel($ch, $model);
            if (!Billing::startRequest($requestId, (int)$key['user_id'], (int)$key['id'], (int)$ch['id'], $model, $upstreamModel, $stream, $multiplier)) {
                // 渠道重试：复用同一请求记录并重置账务状态；重放已完成请求则拒绝
                if (!Billing::reattach($requestId, (int)$ch['id'], $upstreamModel)) {
                    Http::apiError('重复的请求', 'invalid_request_error', 409);
                }
            }
            $url = rtrim($ch['base_url'], '/') . '/chat/completions';
            $payload = $body;
            $payload['model'] = $upstreamModel;
            if ($stream) { $payload['stream_options'] = ['include_usage' => true]; }
            $headers = ['Authorization: Bearer ' . Channels::decryptedKey($ch)];
            $connectTimeout = (int)$ch['connect_timeout'];
            $timeout = (int)$ch['request_timeout'];

            if (!$stream) {
                $res = UpstreamClient::postJson($url, $headers, $payload, $connectTimeout, $timeout);
                $duration = (int)((microtime(true) - $t0) * 1000);
                if ($res['error'] !== null || $res['status'] === 0) {
                    $lastError = $res['error'] ?? '网络错误';
                    Billing::fail($requestId, $lastError, $duration);
                    Channels::recordError((int)$ch['id'], $lastError);
                    continue; // 未输出任何内容，可故障切换
                }
                if ($res['status'] >= 400) {
                    $lastError = self::extractError($res['body']) . ' (HTTP ' . $res['status'] . ')';
                    Billing::fail($requestId, $lastError, $duration);
                    Channels::recordError((int)$ch['id'], $lastError);
                    // 4xx 客户端类错误（鉴权/参数）无切换意义时仍尝试下一渠道（上游 Key 失效等 401 场景有意义）
                    if (in_array($res['status'], [400, 404, 413, 422], true)) {
                        Http::apiError($lastError, 'invalid_request_error', $res['status']);
                    }
                    continue;
                }
                $usage = is_array($res['body']['usage'] ?? null) ? Billing::normalizeUsage($res['body']['usage']) : null;
                $source = $usage !== null ? 'upstream' : 'estimated';
                if ($usage === null) { $usage = self::estimateUsage($body, $res['body']); }
                Channels::recordSuccess((int)$ch['id']);
                $settle = Billing::settle($requestId, $usage, $source, $duration);
                if (!($settle['ok'] ?? false) && ($settle['billing_status'] ?? '') === 'insufficient') {
                    Http::apiError('账户余额不足，本次请求未完成扣费，请联系管理员充值', 'insufficient_quota', 429);
                }
                if (is_array($res['body'])) { $res['body']['id'] = $res['body']['id'] ?? $requestId; }
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
                    if (str_contains($data, '[DONE]')) { $sawDone = true; }
                    // 解析 usage（最后一个 chunk 携带）
                    foreach (preg_split('/\r?\n/', $data) ?: [] as $line) {
                        if (preg_match('/^data:\s*(\{.*)$/', $line, $m)) {
                            $j = json_decode($m[1], true);
                            if (is_array($j) && is_array($j['usage'] ?? null)) { $streamUsage = Billing::normalizeUsage($j['usage']); }
                        }
                    }
                };
                $res = UpstreamClient::postSse($url, $headers, $payload, $onChunk, $connectTimeout, $timeout);
                $duration = (int)((microtime(true) - $t0) * 1000);
                if (!$res['sent']) {
                    // 尚未向客户端输出，可安全切换/返回错误
                    $lastError = $res['error'] ?? '上游无响应';
                    Billing::fail($requestId, $lastError, $duration);
                    Channels::recordError((int)$ch['id'], $lastError);
                    continue;
                }
                if (($res['error'] !== null || !$sawDone) && !$clientAborted) {
                    // 已开始输出但上游中断/未正常完成：记录失败（不扣费），不能重试避免重复输出
                    Billing::fail($requestId, '流式响应中断: ' . mb_substr($res['error'] ?? '上游流未正常结束', 0, 300), $duration);
                    Channels::recordError((int)$ch['id'], $res['error'] ?? '流未以 [DONE] 结束');
                    echo "event: error\ndata: " . json_encode(['error' => ['message' => '上游流中断']]) . "\n\n";
                    exit;
                }
                Channels::recordSuccess((int)$ch['id']);
                $usage = $streamUsage ?? self::estimateUsage($body, null);
                $source = $streamUsage !== null ? 'upstream' : 'estimated';
                $settle = Billing::settle($requestId, $usage, $source, $duration, $clientAborted ? '客户端中断流式响应' : null);
                exit;
            }
        }
        // 所有渠道失败
        Billing::fail($requestId, $lastError ?? '无可用渠道');
        Http::apiError('所有上游渠道请求失败: ' . ($lastError ?? 'unknown'), 'upstream_error', 502);
    }

    private static function sseHeaders(): void
    {
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
        while (ob_get_level() > 0) { ob_end_flush(); }
        flush();
    }

    private static function extractError(?array $body): string
    {
        $msg = $body['error']['message'] ?? $body['message'] ?? null;
        return is_string($msg) ? $msg : '未知上游错误';
    }

    /** 无 usage 时的粗略估算（标记 estimated，不得冒充精确计量） */
    /** 供其他适配器复用的估算入口 */
    public static function estimatePublic(array $reqBody, ?array $respBody): array
    {
        return self::estimateUsage($reqBody, $respBody);
    }

    private static function estimateUsage(array $reqBody, ?array $respBody): array
    {
        $inChars = 0;
        foreach (($reqBody['messages'] ?? []) as $m) {
            $c = $m['content'] ?? '';
            if (is_string($c)) { $inChars += mb_strlen($c); }
            elseif (is_array($c)) { $inChars += mb_strlen(json_encode($c, JSON_UNESCAPED_UNICODE) ?: ''); }
        }
        $outChars = 0;
        if (is_array($respBody)) {
            $content = $respBody['choices'][0]['message']['content'] ?? '';
            $outChars += mb_strlen(is_string($content) ? $content : (json_encode($content, JSON_UNESCAPED_UNICODE) ?: ''));
        }
        return [
            'input_tokens' => (int)ceil($inChars / 4),
            'output_tokens' => (int)ceil($outChars / 4),
        ];
    }
}
