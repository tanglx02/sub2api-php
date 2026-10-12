<?php
declare(strict_types=1);
namespace App\Gateway;

use App\Core\Database as DB;
use App\Core\Http;
use App\Core\Crypto;

/** API 网关鉴权与限流（Bearer sk-key → api_keys → users） */
final class GatewayAuth
{
    /** @return array{key:array,user:array} */
    public static function authenticate(): array
    {
        $token = Http::bearerToken();
        if (!$token || !str_starts_with($token, 'sk-')) {
            Http::apiError('缺少有效的 API Key（Authorization: Bearer sk-...）', 'authentication_error', 401);
        }
        $key = DB::one('SELECT * FROM api_keys WHERE key_hash = ?', [hash('sha256', $token)]);
        if ($key === null) { Http::apiError('API Key 无效', 'authentication_error', 401); }
        if ($key['status'] !== 'active') { Http::apiError('API Key 已被禁用', 'authentication_error', 403); }
        if ($key['expires_at'] !== null && $key['expires_at'] < gmdate('Y-m-d H:i:s')) {
            Http::apiError('API Key 已过期', 'authentication_error', 403);
        }
        if ((float)$key['quota_limit'] > 0 && (float)$key['quota_used'] >= (float)$key['quota_limit']) {
            Http::apiError('API Key 配额已用尽', 'insufficient_quota', 429);
        }
        $user = DB::one('SELECT * FROM users WHERE id = ?', [(int)$key['user_id']]);
        if ($user === null || $user['status'] !== 'active') {
            Http::apiError('用户不可用', 'authentication_error', 403);
        }
        if ((float)$user['balance'] <= 0) {
            Http::apiError('账户余额不足，请联系管理员充值', 'insufficient_quota', 429);
        }
        self::rateLimit((int)$key['id'], (int)$key['rate_limit_rpm']);
        return ['key' => $key, 'user' => $user];
    }

    /** 每分钟窗口限流（数据库计数，普通虚拟主机可用） */
    private static function rateLimit(int $keyId, int $rpm): void
    {
        if ($rpm <= 0) { return; }
        $win = gmdate('YmdHi');
        try {
            if (DB::$driver === 'mysql') {
                DB::run('INSERT INTO rate_counters (key_id, window_key, cnt) VALUES (?,?,1) ON DUPLICATE KEY UPDATE cnt = cnt + 1', [$keyId, $win]);
            } else {
                DB::run('INSERT INTO rate_counters (key_id, window_key, cnt) VALUES (?,?,1)
                         ON CONFLICT(key_id, window_key) DO UPDATE SET cnt = cnt + 1', [$keyId, $win]);
            }
        } catch (\PDOException) { /* 限流计数失败时放行，不阻断业务 */ }
        $n = (int)DB::value('SELECT cnt FROM rate_counters WHERE key_id = ? AND window_key = ?', [$keyId, $win]);
        if ($n > $rpm) {
            Http::apiError('请求过于频繁（超过 ' . $rpm . ' 次/分钟）', 'rate_limit_error', 429);
        }
    }

    /** 检查模型是否对该 Key 可用 */
    public static function checkModel(string $model, array $key): array
    {
        if (!preg_match('/^[\w.\-\/:]{1,100}$/', $model)) {
            Http::apiError('无效的模型名称', 'invalid_request_error', 400);
        }
        $groupId = $key['group_id'] !== null ? (int)$key['group_id'] : null;
        $channels = \App\Services\Channels::pick($model, $groupId);
        if ($channels === []) {
            Http::apiError('模型 ' . $model . ' 不可用或无可用渠道', 'model_not_found', 404);
        }
        return $channels;
    }

    public static function groupMultiplier(?int $groupId): float
    {
        if ($groupId === null) { return 1.0; }
        $g = DB::one('SELECT rate_multiplier FROM groups WHERE id = ?', [$groupId]);
        return $g ? max(0.0, (float)$g['rate_multiplier']) : 1.0;
    }
}
