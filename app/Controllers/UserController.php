<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Audit;
use App\Core\Database as DB;
use App\Core\Http;
use App\Core\Crypto;

/** 普通用户端 API：我的 Key / 用量 / 兑换 */
final class UserController
{
    private static function guard(): array
    {
        $u = Auth::requireUser();
        Auth::checkCsrf();
        return $u;
    }

    public static function myKeys(): never
    {
        $u = self::guard();
        $rows = DB::all(
            'SELECT k.id, k.key_prefix, k.name, k.group_id, g.name AS group_name, k.status, k.expires_at, k.quota_limit, k.quota_used,
                    k.rate_limit_rpm, k.request_count, k.last_used_at, k.created_at
             FROM api_keys k LEFT JOIN groups g ON g.id = k.group_id WHERE k.user_id = ? ORDER BY k.id DESC', [(int)$u['id']]);
        Http::ok(['items' => $rows]);
    }

    public static function myKeyCreate(): never
    {
        $u = self::guard();
        $name = mb_substr(trim((string)Http::input('name', '默认 Key')), 0, 100);
        $groupId = Http::input('group_id');
        $gid = $groupId !== null && $groupId !== '' ? (int)$groupId : null;
        if ($gid !== null && !DB::one("SELECT id FROM groups WHERE id = ? AND status = 'active'", [$gid])) { $gid = null; }
        $quota = max(0.0, (float)Http::input('quota_limit', 0));
        $rpm = max(0, (int)Http::input('rate_limit_rpm', 0));
        $expires = Http::input('expires_at');
        $expires = ($expires !== null && $expires !== '') ? $expires : null;
        $key = Crypto::generateApiKey();
        DB::run('INSERT INTO api_keys (user_id, key_hash, key_prefix, name, group_id, quota_limit, rate_limit_rpm, expires_at, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?)',
            [(int)$u['id'], Crypto::apiKeyHash($key), Crypto::apiKeyPrefix($key), $name, $gid, $quota, $rpm, $expires, gmdate('Y-m-d H:i:s')]);
        $keyId = DB::lastId();
        Audit::log((int)$u['id'], 'key_create', detail: ['target_id' => $keyId]);
        Http::ok(['key' => $key, 'id' => $keyId]); // 明文仅此一次返回
    }

    public static function myKeyUpdate(array $p): never
    {
        $u = self::guard();
        $id = (int)$p['id'];
        $k = DB::one('SELECT * FROM api_keys WHERE id = ? AND user_id = ?', [$id, (int)$u['id']]);
        if (!$k) { Http::error('Key 不存在', 404); }
        $fields = [];
        $args = [];
        $status = Http::input('status');
        if ($status !== null && in_array($status, ['active', 'disabled'], true)) { $fields[] = 'status = ?'; $args[] = $status; }
        $name = Http::input('name');
        if ($name !== null && trim((string)$name) !== '') { $fields[] = 'name = ?'; $args[] = mb_substr(trim((string)$name), 0, 100); }
        $groupId = Http::input('group_id');
        if ($groupId !== null) {
            $gid = $groupId !== '' && $groupId !== 0 ? (int)$groupId : null;
            if ($gid !== null && !DB::one("SELECT id FROM groups WHERE id = ? AND status = 'active'", [$gid])) { Http::error('分组不存在或已停用', 422); }
            $fields[] = 'group_id = ?'; $args[] = $gid;
        }
        if (Http::input('quota_limit') !== null) { $fields[] = 'quota_limit = ?'; $args[] = max(0.0, (float)Http::input('quota_limit')); }
        if (Http::input('rate_limit_rpm') !== null) { $fields[] = 'rate_limit_rpm = ?'; $args[] = max(0, (int)Http::input('rate_limit_rpm')); }
        if (Http::input('expires_at') !== null) {
            $expires = Http::input('expires_at');
            $fields[] = 'expires_at = ?'; $args[] = ($expires !== '' && $expires !== false) ? $expires : null;
        }
        if ($fields === []) { Http::error('没有需要更新的字段', 422); }
        $args[] = $id;
        DB::run('UPDATE api_keys SET ' . implode(', ', $fields) . ' WHERE id = ?', $args);
        Http::ok();
    }

    public static function myKeyDelete(array $p): never
    {
        $u = self::guard();
        DB::run('DELETE FROM api_keys WHERE id = ? AND user_id = ?', [(int)$p['id'], (int)$u['id']]);
        Http::ok();
    }

    public static function myUsage(): never
    {
        $u = self::guard();
        $rows = DB::all(
            'SELECT model, COUNT(*) AS requests, SUM(input_tokens) AS input_tokens, SUM(output_tokens) AS output_tokens,
                    SUM(actual_cost) AS cost
             FROM usage_logs WHERE user_id = ? GROUP BY model ORDER BY requests DESC LIMIT 50', [(int)$u['id']]);
        $tx = DB::all('SELECT type, amount, balance_after, note, created_at FROM balance_transactions WHERE user_id = ? ORDER BY id DESC LIMIT 20', [(int)$u['id']]);
        Http::ok(['by_model' => $rows, 'transactions' => $tx, 'balance' => (float)$u['balance']]);
    }

    public static function redeem(): never
    {
        $u = self::guard();
        $code = strtoupper(trim((string)Http::input('code', '')));
        if ($code === '') { Http::error('请输入兑换码', 422); }
        DB::begin();
        try {
            $rc = DB::one("SELECT * FROM redeem_codes WHERE code = ? AND status = 'unused'", [$code]);
            if (!$rc) { DB::rollback(); Http::error('兑换码无效或已被使用', 404); }
            $stmt = DB::run("UPDATE redeem_codes SET status = 'used', used_by = ?, used_at = ? WHERE id = ? AND status = 'unused'",
                [(int)$u['id'], gmdate('Y-m-d H:i:s'), $rc['id']]);
            if ($stmt->rowCount() === 0) { DB::rollback(); Http::error('兑换码已被使用', 409); }
            DB::run('UPDATE users SET balance = balance + ? WHERE id = ?', [(float)$rc['value'], (int)$u['id']]);
            $bal = (float)DB::value('SELECT balance FROM users WHERE id = ?', [(int)$u['id']]);
            DB::run('INSERT INTO balance_transactions (user_id, type, amount, balance_after, note, created_at) VALUES (?,?,?,?,?,?)',
                [(int)$u['id'], 'recharge', (float)$rc['value'], $bal, '兑换码充值 ' . $code, gmdate('Y-m-d H:i:s')]);
            DB::commit();
            Http::ok(['balance' => $bal]);
        } catch (\Throwable $e) {
            DB::rollback();
            throw $e;
        }
    }
}
