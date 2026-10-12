<?php
declare(strict_types=1);
namespace App\Core;

/** HTTP 请求/响应工具 */
final class Http
{
    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function ok(mixed $data = null): never
    {
        self::json(['success' => true, 'data' => $data]);
    }

    public static function error(string $message, int $status = 400, mixed $extra = null): never
    {
        $payload = ['success' => false, 'error' => ['message' => $message, 'type' => 'sub2api_error']];
        if ($extra !== null) { $payload['error']['detail'] = $extra; }
        self::json($payload, $status);
    }

    /** OpenAI 风格错误 */
    public static function apiError(string $message, string $type = 'invalid_request_error', int $status = 400): never
    {
        self::json(['error' => ['message' => $message, 'type' => $type, 'code' => $status]], $status);
    }

    public static function method(): string
    {
        $m = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        // 部分主机 WAF 禁用 PUT/DELETE：允许 POST + _method 覆写
        if ($m === 'POST') {
            $ov = $_POST['_method'] ?? null;
            if ($ov === null) {
                $raw = file_get_contents('php://input') ?: '';
                $j = json_decode($raw, true);
                if (is_array($j) && isset($j['_method'])) { $ov = $j['_method']; }
            }
            if (is_string($ov)) {
                $ov = strtoupper(trim($ov));
                if (in_array($ov, ['PUT', 'DELETE', 'PATCH'], true)) { return $ov; }
            }
        }
        return $m;
    }

    public static function body(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        $ct = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($ct, 'application/json')) {
            $d = json_decode($raw, true);
            return is_array($d) ? $d : [];
        }
        return $_POST ?: (json_decode($raw, true) ?: []);
    }

    public static function input(string $key, mixed $default = null): mixed
    {
        $b = self::body();
        return $b[$key] ?? $_GET[$key] ?? $default;
    }

    public static function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return isset($_SERVER[$key]) ? trim((string)$_SERVER[$key]) : (isset($_SERVER[$name]) ? trim((string)$_SERVER[$name]) : null);
    }

    public static function bearerToken(): ?string
    {
        $h = self::header('Authorization') ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', $h, $m)) { return trim($m[1]); }
        return self::header('X-Api-Key') ?? ($_GET['key'] ?? null);
    }

    public static function clientIp(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}
