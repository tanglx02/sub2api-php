<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database as DB;
use App\Core\Http;

/** 登录 / 登出 / 会话 */
final class AuthController
{
    public static function login(): never
    {
        $email = strtolower(trim((string)Http::input('email', '')));
        $password = (string)Http::input('password', '');
        $ip = Http::clientIp();
        if ($email === '' || $password === '') { Http::error('请输入邮箱和密码', 422); }
        if (!Auth::checkLoginAttempts($ip, $email)) {
            Http::error('登录失败次数过多，请 15 分钟后重试', 429);
        }
        $user = DB::one('SELECT * FROM users WHERE email = ?', [$email]);
        if ($user === null || !password_verify($password, $user['password_hash'])) {
            Auth::recordLoginAttempt($ip, $email, false);
            Http::error('邮箱或密码错误', 401);
        }
        if ($user['status'] !== 'active') {
            Auth::recordLoginAttempt($ip, $email, false);
            Http::error('账号已被禁用', 403);
        }
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            DB::run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        }
        Auth::recordLoginAttempt($ip, $email, true);
        Auth::login($user);
        self::logAction((int)$user['id'], 'login');
        Http::ok([
            'user' => self::publicUser($user),
            'csrf_token' => Auth::csrfToken(),
        ]);
    }

    public static function logout(): never
    {
        $u = Auth::requireUser();
        Auth::logout();
        Http::ok();
    }

    public static function me(): never
    {
        $u = Auth::requireUser();
        Http::ok(['user' => self::publicUser($u), 'csrf_token' => Auth::csrfToken()]);
    }

    public static function changePassword(): never
    {
        $u = Auth::requireUser();
        $old = (string)Http::input('old_password', '');
        $new = (string)Http::input('new_password', '');
        if (!password_verify($old, $u['password_hash'])) { Http::error('原密码错误', 422); }
        if (strlen($new) < 8) { Http::error('新密码至少 8 位', 422); }
        DB::run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $u['id']]);
        self::logAction((int)$u['id'], 'change_password');
        Http::ok();
    }

    public static function publicUser(array $u): array
    {
        return [
            'id' => (int)$u['id'],
            'email' => $u['email'],
            'role' => $u['role'],
            'balance' => (float)$u['balance'],
            'status' => $u['status'],
            'request_count' => (int)$u['request_count'],
            'total_spent' => (float)$u['total_spent'],
        ];
    }

    public static function logAction(int $uid, string $action, array $detail = []): void
    {
        \App\Core\Audit::log($uid, $action, detail: $detail);
    }
}
