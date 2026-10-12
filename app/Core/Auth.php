<?php
declare(strict_types=1);
namespace App\Core;

/** 会话认证、CSRF、登录失败限制 */
final class Auth
{
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) { return; }
        session_set_cookie_params([
            'lifetime' => 0, 'path' => '/', 'httponly' => true,
            'samesite' => 'Lax', 'secure' => (($_SERVER['HTTPS'] ?? '') === 'on'),
        ]);
        ini_set('session.name', 'S2ASESSID');
        session_start();
    }

    public static function login(array $user): void
    {
        self::startSession();
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$user['id'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }

    public static function logout(): void
    {
        self::startSession();
        $_SESSION = [];
        session_destroy();
    }

    public static function user(): ?array
    {
        self::startSession();
        if (empty($_SESSION['uid'])) { return null; }
        $u = Database::one('SELECT * FROM users WHERE id = ? AND status = ?', [$_SESSION['uid'], 'active']);
        return $u;
    }

    public static function requireUser(): array
    {
        $u = self::user();
        if (!$u) { Http::error('未登录或会话已过期', 401); }
        return $u;
    }

    public static function requireAdmin(): array
    {
        $u = self::requireUser();
        if ($u['role'] !== 'admin') { Http::error('需要管理员权限', 403); }
        return $u;
    }

    public static function csrfToken(): string
    {
        self::startSession();
        return $_SESSION['csrf'] ?? ($_SESSION['csrf'] = bin2hex(random_bytes(16)));
    }

    /** Web 管理接口 CSRF 校验（API 网关走 Bearer，不适用） */
    public static function checkCsrf(): void
    {
        self::startSession();
        $path = self::currentRequestPath();
        if ($path === '/api/auth/login') { return; } // 登录前无会话令牌
        $token = Http::header('X-CSRF-Token') ?? (Http::body()['csrf_token'] ?? '');
        if (!hash_equals((string)($_SESSION['csrf'] ?? ''), (string)$token)) {
            Http::error('CSRF 校验失败，请刷新页面重试', 419);
        }
    }

    private static function currentRequestPath(): string
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        if ($base !== '' && str_starts_with($uri, $base)) { $uri = substr($uri, strlen($base)); }
        return '/' . trim($uri, '/');
    }

    /** 登录失败限制：同 IP+邮箱 15 分钟内最多 8 次 */
    public static function checkLoginAttempts(string $ip, string $email): bool
    {
        $since = gmdate('Y-m-d H:i:s', time() - 900);
        $n = (int)Database::value(
            'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND email = ? AND success = 0 AND created_at > ?',
            [$ip, $email, $since]);
        return $n < 8;
    }

    public static function recordLoginAttempt(string $ip, string $email, bool $success): void
    {
        Database::run('INSERT INTO login_attempts (ip, email, success, created_at) VALUES (?,?,?,?)',
            [$ip, $email, $success ? 1 : 0, gmdate('Y-m-d H:i:s')]);
    }
}
