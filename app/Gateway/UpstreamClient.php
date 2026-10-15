<?php
declare(strict_types=1);
namespace App\Gateway;

/** 上游 HTTP 客户端（cURL），支持 JSON 与 SSE 流式转发 */
final class UpstreamClient
{
    /** 部分主机缺 CA 证书包：若 data/cacert.pem 存在则显式指定 */
    private static function sslOpts(): array
    {
        $ca = dirname(__DIR__, 2) . '/data/cacert.pem';
        return is_file($ca) ? [CURLOPT_CAINFO => $ca] : [];
    }

    /**
     * 非流式请求。返回 ['status'=>int,'body'=>array,'raw'=>string,'error'=>?string]
     */
    public static function postJson(string $url, array $headers, array $payload, int $connectTimeout = 10, int $timeout = 300): array
    {
        $ch = curl_init($url);
        $hdrs = ['Content-Type: application/json', 'Accept: application/json'];
        foreach ($headers as $h) { $hdrs[] = $h; }
        curl_setopt_array($ch, self::sslOpts() + [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => $hdrs,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            return ['status' => 0, 'body' => null, 'raw' => '', 'error' => '上游网络错误: ' . $err];
        }
        $body = json_decode((string)$raw, true);
        return ['status' => $status, 'body' => is_array($body) ? $body : null, 'raw' => (string)$raw, 'error' => null];
    }

    /**
     * SSE 流式请求。每收到一个数据块调用 $onChunk(string $data)。
     * 返回 ['status'=>int,'error'=>?string,'sent'=>bool]
     * 注意：一旦 $sent=true（已向客户端输出），调用方不得重试其他上游。
     */
    public static function postSse(string $url, array $headers, array $payload, \Closure $onChunk, int $connectTimeout = 10, int $timeout = 300): array
    {
        $ch = curl_init($url);
        $hdrs = ['Content-Type: application/json', 'Accept: text/event-stream'];
        foreach ($headers as $h) { $hdrs[] = $h; }
        $sent = false;
        $status = 0;
        $errBuf = '';
        curl_setopt_array($ch, self::sslOpts() + [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => $hdrs,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$sent, &$status, &$errBuf, $onChunk) {
                if ($status === 0) { $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); }
                if ($status >= 400) { $errBuf .= $data; return strlen($data); }
                $sent = true;
                $onChunk($data);
                return strlen($data);
            },
            CURLOPT_HEADERFUNCTION => function ($ch, $h) use (&$status) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', trim($h), $m)) { $status = (int)$m[1]; }
                return strlen($h);
            },
        ]);
        curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($status >= 400) {
            return ['status' => $status, 'error' => $errBuf !== '' ? $errBuf : ('上游错误 HTTP ' . $status), 'sent' => $sent];
        }
        if ($err !== '' && !$sent) {
            return ['status' => 0, 'error' => '上游网络错误: ' . $err, 'sent' => false];
        }
        return ['status' => $status, 'error' => null, 'sent' => $sent];
    }

    /** 连通性测试：GET /models 或 base 探活 */
    public static function testChannel(string $baseUrl, string $apiKey, string $type, int $timeout = 10): array
    {
        $path = $type === 'anthropic_compatible' ? '/v1/models' : '/v1/models';
        $ch = curl_init(rtrim($baseUrl, '/') . $path);
        $hdrs = ['Authorization: Bearer ' . $apiKey];
        if ($type === 'anthropic_compatible') {
            $hdrs = ['x-api-key: ' . $apiKey, 'anthropic-version: 2023-06-01'];
        }
        curl_setopt_array($ch, self::sslOpts() + [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $hdrs,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
        ]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) { return ['ok' => false, 'error' => '连接失败: ' . $err]; }
        if ($status >= 200 && $status < 300) {
            // 必须是合法 JSON，防止把上游网页首页误判为连通
            if (!is_array(json_decode((string)$raw, true))) {
                return ['ok' => false, 'error' => 'HTTP 200 但返回的不是 JSON（通常是 Base URL 填错，例如应以 /v1 结尾）: ' . mb_substr(trim((string)$raw), 0, 150)];
            }
            return ['ok' => true];
        }
        return ['ok' => false, 'error' => 'HTTP ' . $status . ': ' . mb_substr((string)$raw, 0, 300)];
    }

    /**
     * 获取上游全部模型列表（GET /v1/models）。
     * 返回 ['ok'=>bool,'models'=>string[],'error'=>?string]
     */
    public static function fetchModels(string $baseUrl, string $apiKey, string $type, int $timeout = 15): array
    {
        $ch = curl_init(rtrim($baseUrl, '/') . '/v1/models');
        $hdrs = ['Authorization: Bearer ' . $apiKey];
        if ($type === 'anthropic_compatible') {
            $hdrs = ['x-api-key: ' . $apiKey, 'anthropic-version: 2023-06-01'];
        }
        curl_setopt_array($ch, self::sslOpts() + [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $hdrs,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
        ]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) { return ['ok' => false, 'models' => [], 'error' => '连接失败: ' . $err]; }
        if ($status < 200 || $status >= 300) { return ['ok' => false, 'models' => [], 'error' => 'HTTP ' . $status . ': ' . mb_substr((string)$raw, 0, 300)]; }
        $body = json_decode((string)$raw, true);
        $models = [];
        foreach (($body['data'] ?? []) as $m) {
            $id = is_array($m) ? (string)($m['id'] ?? '') : (string)$m;
            if ($id !== '') { $models[] = $id; }
        }
        $models = array_values(array_unique($models));
        sort($models);
        return ['ok' => true, 'models' => $models, 'error' => null];
    }

    /** SSRF 防护：仅允许 http/https 且拒绝常见内网地址 */
    public static function validateBaseUrl(string $url): bool
    {
        $p = parse_url($url);
        if (!$p || !isset($p['scheme'], $p['host'])) { return false; }
        if (!in_array($p['scheme'], ['http', 'https'], true)) { return false; }
        $host = strtolower($p['host']);
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || $host === '0.0.0.0') { return false; }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            // 默认拒绝私网/保留地址（SSRF 防护）；管理设置 allow_private_upstream=1 时放行（用于本机 Mock 测试）
            $allowPrivate = false;
            try {
                if (\App\Core\Config::installed()) {
                    $v = \App\Core\Database::value("SELECT svalue FROM settings WHERE skey = 'allow_private_upstream'");
                    $allowPrivate = $v === '1';
                }
            } catch (\Throwable) {}
            if (!$allowPrivate) {
                $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
                if (!filter_var($host, FILTER_VALIDATE_IP, $flags)) { return false; }
            }
        }
        return true;
    }
}
