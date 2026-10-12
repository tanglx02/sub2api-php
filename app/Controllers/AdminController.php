<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Audit;
use App\Core\Database as DB;
use App\Core\Http;
use App\Core\Crypto;
use App\Services\Channels;

/** 管理后台 API（全部方法要求管理员会话） */
final class AdminController
{
    private static function guard(): array
    {
        $u = Auth::requireUser();
        Auth::checkCsrf();
        if ($u['role'] !== 'admin') { Http::error('需要管理员权限', 403); }
        return $u;
    }

    /** 通用分页参数 */
    private static function pageParams(int $defaultSize = 20): array
    {
        $page = max(1, (int)Http::input('page', 1));
        $size = min(200, max(1, (int)Http::input('page_size', $defaultSize)));
        return [$page, $size, ($page - 1) * $size];
    }

    // ---------------- 仪表盘 ----------------
    public static function dashboard(): never
    {
        self::guard();
        $today = gmdate('Y-m-d') . ' 00:00:00';
        $stats = [
            'users_total' => (int)DB::value('SELECT COUNT(*) FROM users'),
            'keys_total' => (int)DB::value('SELECT COUNT(*) FROM api_keys WHERE status = ?', ['active']),
            'channels_total' => (int)DB::value('SELECT COUNT(*) FROM channels'),
            'channels_active' => (int)DB::value("SELECT COUNT(*) FROM channels WHERE status = 'active'"),
            'requests_today' => (int)DB::value('SELECT COUNT(*) FROM usage_logs WHERE created_at >= ?', [$today]),
            'cost_today' => (float)DB::value('SELECT COALESCE(SUM(actual_cost),0) FROM usage_logs WHERE created_at >= ?', [$today]),
            'errors_today' => (int)DB::value("SELECT COUNT(*) FROM usage_logs WHERE created_at >= ? AND status = 'failed'", [$today]),
            'tokens_today' => (int)DB::value('SELECT COALESCE(SUM(total_tokens),0) FROM usage_logs WHERE created_at >= ?', [$today]),
            'balance_total' => (float)DB::value('SELECT COALESCE(SUM(balance),0) FROM users'),
        ];
        $trend = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = gmdate('Y-m-d', time() - $i * 86400);
            $from = $d . ' 00:00:00';
            $to = $d . ' 23:59:59';
            $trend[] = [
                'date' => $d,
                'requests' => (int)DB::value('SELECT COUNT(*) FROM usage_logs WHERE created_at BETWEEN ? AND ?', [$from, $to]),
                'cost' => (float)DB::value('SELECT COALESCE(SUM(actual_cost),0) FROM usage_logs WHERE created_at BETWEEN ? AND ?', [$from, $to]),
            ];
        }
        $topModels = DB::all('SELECT model, COUNT(*) AS n, COALESCE(SUM(actual_cost),0) AS cost FROM usage_logs WHERE created_at >= ? GROUP BY model ORDER BY n DESC LIMIT 8', [$today]);
        Http::ok(['stats' => $stats, 'trend' => $trend, 'top_models' => $topModels]);
    }

    /** 统计报表：/api/admin/stats?days=14 */
    public static function stats(): never
    {
        self::guard();
        $days = max(1, min(90, (int)(Http::input('days', 14))));
        $since = gmdate('Y-m-d 00:00:00', time() - ($days - 1) * 86400);

        $summary = [
            'requests_total' => (int)DB::value('SELECT COUNT(*) FROM usage_logs WHERE created_at >= ?', [$since]),
            'tokens_total' => (int)DB::value('SELECT COALESCE(SUM(total_tokens),0) FROM usage_logs WHERE created_at >= ?', [$since]),
            'input_tokens' => (int)DB::value('SELECT COALESCE(SUM(input_tokens),0) FROM usage_logs WHERE created_at >= ?', [$since]),
            'output_tokens' => (int)DB::value('SELECT COALESCE(SUM(output_tokens),0) FROM usage_logs WHERE created_at >= ?', [$since]),
            'cost_total' => (float)DB::value('SELECT COALESCE(SUM(actual_cost),0) FROM usage_logs WHERE created_at >= ?', [$since]),
            'requests_completed' => (int)DB::value("SELECT COUNT(*) FROM usage_logs WHERE created_at >= ? AND billing_status = 'completed'", [$since]),
            'requests_failed' => (int)DB::value("SELECT COUNT(*) FROM usage_logs WHERE created_at >= ? AND status = 'failed'", [$since]),
            'avg_duration_ms' => (int)DB::value('SELECT COALESCE(AVG(duration_ms),0) FROM usage_logs WHERE created_at >= ? AND duration_ms > 0', [$since]),
            'users_total' => (int)DB::value('SELECT COUNT(*) FROM users'),
            'keys_total' => (int)DB::value('SELECT COUNT(*) FROM api_keys'),
            'balance_total' => (float)DB::value('SELECT COALESCE(SUM(balance),0) FROM users'),
        ];
        $summary['success_rate'] = $summary['requests_total'] > 0
            ? round($summary['requests_completed'] / max(1, $summary['requests_completed'] + $summary['requests_failed']) * 100, 2)
            : 100.0;

        $byDay = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = gmdate('Y-m-d', time() - $i * 86400);
            $byDay[] = [
                'date' => $d,
                'requests' => (int)DB::value('SELECT COUNT(*) FROM usage_logs WHERE created_at BETWEEN ? AND ?', [$d . ' 00:00:00', $d . ' 23:59:59']),
                'cost' => (float)DB::value('SELECT COALESCE(SUM(actual_cost),0) FROM usage_logs WHERE created_at BETWEEN ? AND ?', [$d . ' 00:00:00', $d . ' 23:59:59']),
                'tokens' => (int)DB::value('SELECT COALESCE(SUM(total_tokens),0) FROM usage_logs WHERE created_at BETWEEN ? AND ?', [$d . ' 00:00:00', $d . ' 23:59:59']),
            ];
        }

        $byModel = DB::all("SELECT model, COUNT(*) AS requests, COALESCE(SUM(total_tokens),0) AS tokens, COALESCE(SUM(actual_cost),0) AS cost
                            FROM usage_logs WHERE created_at >= ? GROUP BY model ORDER BY requests DESC LIMIT 20", [$since]);
        $byUser = DB::all("SELECT u.email, COUNT(*) AS requests, COALESCE(SUM(l.actual_cost),0) AS cost, COALESCE(SUM(l.total_tokens),0) AS tokens
                           FROM usage_logs l JOIN users u ON u.id = l.user_id
                           WHERE l.created_at >= ? GROUP BY l.user_id ORDER BY cost DESC LIMIT 10", [$since]);
        $byKey = DB::all("SELECT k.name, COUNT(*) AS requests, COALESCE(SUM(l.actual_cost),0) AS cost
                          FROM usage_logs l JOIN api_keys k ON k.id = l.api_key_id
                          WHERE l.created_at >= ? GROUP BY l.api_key_id ORDER BY cost DESC LIMIT 10", [$since]);
        $byStatus = DB::all("SELECT billing_status, COUNT(*) AS n FROM usage_logs WHERE created_at >= ? GROUP BY billing_status", [$since]);
        $byChannel = DB::all("SELECT c.name, COUNT(*) AS requests, COALESCE(SUM(l.actual_cost),0) AS cost
                              FROM usage_logs l JOIN channels c ON c.id = l.channel_id
                              WHERE l.created_at >= ? GROUP BY l.channel_id ORDER BY requests DESC LIMIT 10", [$since]);

        Http::ok(['summary' => $summary, 'by_day' => $byDay, 'by_model' => $byModel, 'by_user' => $byUser,
                  'by_key' => $byKey, 'by_status' => $byStatus, 'by_channel' => $byChannel, 'days' => $days]);
    }

    public static function systemStatus(): never
    {
        self::guard();
        Http::ok([
            'php_version' => PHP_VERSION,
            'extensions' => [
                'pdo' => extension_loaded('pdo'), 'pdo_mysql' => extension_loaded('pdo_mysql'),
                'pdo_sqlite' => extension_loaded('pdo_sqlite'), 'curl' => extension_loaded('curl'),
                'openssl' => extension_loaded('openssl'), 'json' => extension_loaded('json'),
                'mbstring' => extension_loaded('mbstring'),
            ],
            'db_driver' => DB::$driver,
            'db_version' => (string)DB::pdo()->getAttribute(\PDO::ATTR_SERVER_VERSION),
            'app_version' => '1.0.0',
            'server_time' => gmdate('Y-m-d H:i:s') . ' UTC',
            'data_writable' => is_writable(dirname(__DIR__, 2) . '/data'),
            'migrations' => DB::all('SELECT version, applied_at FROM migrations ORDER BY version'),
        ]);
    }

    // ---------------- 用户管理 ----------------
    public static function users(): never
    {
        self::guard();
        [$page, $size, $offset] = self::pageParams();
        $q = trim((string)Http::input('q', ''));
        $where = '1=1';
        $args = [];
        if ($q !== '') { $where .= ' AND email LIKE ?'; $args[] = '%' . $q . '%'; }
        $total = (int)DB::value("SELECT COUNT(*) FROM users WHERE $where", $args);
        $rows = DB::all("SELECT id, email, role, balance, status, concurrency, total_spent, request_count, created_at FROM users WHERE $where ORDER BY id DESC LIMIT $size OFFSET $offset", $args);
        Http::ok(['total' => $total, 'items' => $rows]);
    }

    public static function userCreate(): never
    {
        $admin = self::guard();
        $email = strtolower(trim((string)Http::input('email', '')));
        $password = (string)Http::input('password', '');
        $role = Http::input('role', 'user') === 'admin' ? 'admin' : 'user';
        $balance = (float)Http::input('balance', 0);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { Http::error('邮箱格式不正确', 422); }
        if (strlen($password) < 8) { Http::error('密码至少 8 位', 422); }
        if (DB::one('SELECT id FROM users WHERE email = ?', [$email])) { Http::error('邮箱已存在', 409); }
        DB::run('INSERT INTO users (email, password_hash, role, balance, created_at) VALUES (?,?,?,?,?)',
            [$email, password_hash($password, PASSWORD_DEFAULT), $role, $balance, gmdate('Y-m-d H:i:s')]);
        $uid = DB::lastId();
        if ($balance > 0) {
            DB::run('INSERT INTO balance_transactions (user_id, type, amount, balance_after, note, operator_id, created_at) VALUES (?,?,?,?,?,?,?)',
                [$uid, 'recharge', $balance, $balance, '初始余额', $admin['id'], gmdate('Y-m-d H:i:s')]);
        }
        Audit::log((int)$admin['id'], 'user_create', detail: ['target' => $email]);
        Http::ok(['id' => $uid]);
    }

    public static function userUpdate(array $p): never
    {
        $admin = self::guard();
        $id = (int)$p['id'];
        $u = DB::one('SELECT * FROM users WHERE id = ?', [$id]);
        if (!$u) { Http::error('用户不存在', 404); }
        $fields = [];
        $args = [];
        foreach (['status' => 'status', 'role' => 'role'] as $k => $col) {
            $v = Http::input($k);
            if ($v !== null && in_array($v, ['active', 'disabled'], true) || ($k === 'role' && in_array($v, ['admin', 'user'], true))) {
                $fields[] = "$col = ?";
                $args[] = $v;
            }
        }
        $newBalance = Http::input('balance');
        if ($newBalance !== null && is_numeric($newBalance)) {
            $newBalance = (float)$newBalance;
            $fields[] = 'balance = ?';
            $args[] = $newBalance;
            $diff = $newBalance - (float)$u['balance'];
            DB::run('INSERT INTO balance_transactions (user_id, type, amount, balance_after, note, operator_id, created_at) VALUES (?,?,?,?,?,?,?)',
                [$id, 'adjust', $diff, $newBalance, '管理员调整', $admin['id'], gmdate('Y-m-d H:i:s')]);
        }
        $newPwd = Http::input('password');
        if ($newPwd !== null && $newPwd !== '') {
            if (strlen((string)$newPwd) < 8) { Http::error('密码至少 8 位', 422); }
            $fields[] = 'password_hash = ?';
            $args[] = password_hash((string)$newPwd, PASSWORD_DEFAULT);
        }
        if ($fields === []) { Http::error('没有需要更新的字段', 422); }
        $args[] = $id;
        DB::run('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?', $args);
        Audit::log((int)$admin['id'], 'user_update', detail: ['target_id' => $id]);
        Http::ok();
    }

    public static function userDelete(array $p): never
    {
        $admin = self::guard();
        $id = (int)$p['id'];
        if ($id === (int)$admin['id']) { Http::error('不能删除自己', 422); }
        DB::run('DELETE FROM users WHERE id = ?', [$id]);
        DB::run('DELETE FROM api_keys WHERE user_id = ?', [$id]);
        Audit::log((int)$admin['id'], 'user_delete', detail: ['target_id' => $id]);
        Http::ok();
    }

    public static function userTransactions(array $p): never
    {
        self::guard();
        [$page, $size, $offset] = self::pageParams();
        $uid = (int)$p['id'];
        $total = (int)DB::value('SELECT COUNT(*) FROM balance_transactions WHERE user_id = ?', [$uid]);
        $rows = DB::all("SELECT * FROM balance_transactions WHERE user_id = ? ORDER BY id DESC LIMIT $size OFFSET $offset", [$uid]);
        Http::ok(['total' => $total, 'items' => $rows]);
    }

    // ---------------- API Key 管理 ----------------
    public static function keys(): never
    {
        self::guard();
        [$page, $size, $offset] = self::pageParams();
        $uid = Http::input('user_id');
        $where = '1=1';
        $args = [];
        if ($uid !== null && $uid !== '') { $where .= ' AND k.user_id = ?'; $args[] = (int)$uid; }
        $total = (int)DB::value("SELECT COUNT(*) FROM api_keys k WHERE $where", $args);
        $rows = DB::all(
            "SELECT k.id, k.user_id, u.email, k.key_prefix, k.name, k.group_id, g.name AS group_name, k.status, k.expires_at,
                    k.quota_limit, k.quota_used, k.rate_limit_rpm, k.request_count, k.last_used_at, k.created_at
             FROM api_keys k LEFT JOIN users u ON u.id = k.user_id LEFT JOIN groups g ON g.id = k.group_id
             WHERE $where ORDER BY k.id DESC LIMIT $size OFFSET $offset", $args);
        Http::ok(['total' => $total, 'items' => $rows]);
    }

    public static function keyUpdate(array $p): never
    {
        $admin = self::guard();
        $id = (int)$p['id'];
        $fields = [];
        $args = [];
        foreach (['status' => ['active', 'disabled'], 'name' => null, 'rate_limit_rpm' => null] as $k => $allowed) {
            $v = Http::input($k);
            if ($v === null) { continue; }
            if (is_array($allowed) && !in_array($v, $allowed, true)) { continue; }
            $fields[] = "$k = ?";
            $args[] = $k === 'rate_limit_rpm' ? max(0, (int)$v) : $v;
        }
        $quota = Http::input('quota_limit');
        if ($quota !== null && is_numeric($quota)) { $fields[] = 'quota_limit = ?'; $args[] = max(0, (float)$quota); }
        $expires = Http::input('expires_at');
        if ($expires !== null) { $fields[] = 'expires_at = ?'; $args[] = $expires === '' ? null : $expires; }
        if ($fields === []) { Http::error('没有需要更新的字段', 422); }
        $args[] = $id;
        DB::run('UPDATE api_keys SET ' . implode(', ', $fields) . ' WHERE id = ?', $args);
        Audit::log((int)$admin['id'], 'key_update', detail: ['target_id' => $id]);
        Http::ok();
    }

    public static function keyDelete(array $p): never
    {
        $admin = self::guard();
        DB::run('DELETE FROM api_keys WHERE id = ?', [(int)$p['id']]);
        Audit::log((int)$admin['id'], 'key_delete', detail: ['target_id' => (int)$p['id']]);
        Http::ok();
    }

    public static function keyRotate(array $p): never
    {
        $admin = self::guard();
        $id = (int)$p['id'];
        if (!DB::one('SELECT id FROM api_keys WHERE id = ?', [$id])) { Http::error('Key 不存在', 404); }
        $newKey = Crypto::generateApiKey();
        DB::run('UPDATE api_keys SET key_hash = ?, key_prefix = ?, status = ?, quota_used = 0 WHERE id = ?',
            [Crypto::apiKeyHash($newKey), Crypto::apiKeyPrefix($newKey), 'active', $id]);
        Audit::log((int)$admin['id'], 'key_rotate', detail: ['target_id' => $id]);
        Http::ok(['key' => $newKey]); // 仅轮换时一次性返回
    }

    // ---------------- 渠道管理 ----------------
    public static function channels(): never
    {
        self::guard();
        $rows = DB::all('SELECT * FROM channels ORDER BY priority ASC, id ASC');
        foreach ($rows as &$r) {
            unset($r['api_key_enc']);
            $r['api_key_masked'] = 'sk-****';
            $r['models'] = json_decode((string)($r['models'] ?? '[]'), true) ?: [];
            $r['model_mapping'] = json_decode((string)($r['model_mapping'] ?? '{}'), true) ?: [];
            $r['group_ids'] = array_map('intval', array_column(DB::all('SELECT group_id FROM channel_groups WHERE channel_id = ?', [(int)$r['id']]), 'group_id'));
        }
        Http::ok(['items' => $rows]);
    }

    public static function channelCreate(): never
    {
        $admin = self::guard();
        $d = Http::body();
        $type = in_array($d['type'] ?? '', ['openai_compatible', 'deepseek', 'anthropic_compatible'], true) ? $d['type'] : 'openai_compatible';
        $baseUrl = rtrim(trim((string)($d['base_url'] ?? '')), '/');
        $apiKey = trim((string)($d['api_key'] ?? ''));
        if ($baseUrl === '' || $apiKey === '') { Http::error('Base URL 与 API Key 不能为空', 422); }
        if (!UpstreamUrlValid($baseUrl)) { Http::error('Base URL 不合法或指向内网地址', 422); }
        $models = is_array($d['models'] ?? null) ? array_values(array_filter(array_map('strval', $d['models']))) : [];
        if ($models === []) { Http::error('至少配置一个模型', 422); }
        $mapping = is_array($d['model_mapping'] ?? null) ? $d['model_mapping'] : [];
        DB::run('INSERT INTO channels (name, type, base_url, api_key_enc, models, model_mapping, priority, weight, status, connect_timeout, request_timeout, model_sync_interval, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [trim((string)($d['name'] ?? '未命名渠道')), $type, $baseUrl, Crypto::encrypt($apiKey),
             json_encode($models, JSON_UNESCAPED_SLASHES), json_encode($mapping ?: new \stdClass(), JSON_UNESCAPED_SLASHES),
             max(1, min(999, (int)($d['priority'] ?? 50))), max(1, (int)($d['weight'] ?? 1)),
             ($d['status'] ?? 'active') === 'disabled' ? 'disabled' : 'active',
             max(1, min(60, (int)($d['connect_timeout'] ?? 10))), max(5, min(3600, (int)($d['request_timeout'] ?? 300))),
             max(0, min(10080, (int)($d['model_sync_interval'] ?? 0))),
             gmdate('Y-m-d H:i:s')]);
        $cid = DB::lastId();
        self::saveChannelGroups($cid, $d['group_ids'] ?? []);
        Audit::log((int)$admin['id'], 'channel_create', detail: ['target_id' => $cid, 'name' => $d['name'] ?? '']);
        Http::ok(['id' => $cid]);
    }

    public static function channelUpdate(array $p): never
    {
        $admin = self::guard();
        $id = (int)$p['id'];
        $ch = DB::one('SELECT * FROM channels WHERE id = ?', [$id]);
        if (!$ch) { Http::error('渠道不存在', 404); }
        $d = Http::body();
        $fields = [];
        $args = [];
        if (isset($d['name'])) { $fields[] = 'name = ?'; $args[] = trim((string)$d['name']); }
        if (isset($d['type']) && in_array($d['type'], ['openai_compatible', 'deepseek', 'anthropic_compatible'], true)) { $fields[] = 'type = ?'; $args[] = $d['type']; }
        if (!empty($d['base_url'])) {
            $baseUrl = rtrim(trim((string)$d['base_url']), '/');
            if (!UpstreamUrlValid($baseUrl)) { Http::error('Base URL 不合法或指向内网地址', 422); }
            $fields[] = 'base_url = ?'; $args[] = $baseUrl;
        }
        if (!empty($d['api_key'])) { $fields[] = 'api_key_enc = ?'; $args[] = Crypto::encrypt(trim((string)$d['api_key'])); }
        if (isset($d['models']) && is_array($d['models'])) {
            $models = array_values(array_filter(array_map('strval', $d['models'])));
            if ($models === []) { Http::error('至少配置一个模型', 422); }
            $fields[] = 'models = ?'; $args[] = json_encode($models, JSON_UNESCAPED_SLASHES);
        }
        if (isset($d['model_mapping']) && is_array($d['model_mapping'])) {
            $fields[] = 'model_mapping = ?'; $args[] = json_encode($d['model_mapping'], JSON_UNESCAPED_SLASHES);
        }
        foreach (['priority' => 'int', 'weight' => 'int', 'connect_timeout' => 'int', 'request_timeout' => 'int', 'model_sync_interval' => 'int'] as $k => $cast) {
            if (isset($d[$k]) && is_numeric($d[$k])) { $fields[] = "$k = ?"; $args[] = max(0, min(10080, (int)$d[$k])); }
        }
        if (isset($d['status']) && in_array($d['status'], ['active', 'disabled'], true)) { $fields[] = 'status = ?'; $args[] = $d['status']; }
        if ($fields !== []) {
            $args[] = $id;
            DB::run('UPDATE channels SET ' . implode(', ', $fields) . ' WHERE id = ?', $args);
        }
        if (isset($d['group_ids']) && is_array($d['group_ids'])) { self::saveChannelGroups($id, $d['group_ids']); }
        Audit::log((int)$admin['id'], 'channel_update', detail: ['target_id' => $id]);
        Http::ok();
    }

    public static function channelDelete(array $p): never
    {
        $admin = self::guard();
        $id = (int)$p['id'];
        DB::run('DELETE FROM channels WHERE id = ?', [$id]);
        DB::run('DELETE FROM channel_groups WHERE channel_id = ?', [$id]);
        Audit::log((int)$admin['id'], 'channel_delete', detail: ['target_id' => $id]);
        Http::ok();
    }

    public static function channelTest(array $p): never
    {
        self::guard();
        $ch = DB::one('SELECT * FROM channels WHERE id = ?', [(int)$p['id']]);
        if (!$ch) { Http::error('渠道不存在', 404); }
        $res = \App\Gateway\UpstreamClient::testChannel($ch['base_url'], Channels::decryptedKey($ch), $ch['type'], (int)$ch['connect_timeout']);
        DB::run('UPDATE channels SET last_test_at = ?, last_test_ok = ? WHERE id = ?',
            [gmdate('Y-m-d H:i:s'), $res['ok'] ? 1 : 0, $ch['id']]);
        if (!$res['ok']) { DB::run('UPDATE channels SET last_error = ? WHERE id = ?', [$res['error'], $ch['id']]); }
        Audit::log((int)Auth::requireUser()['id'], 'channel_test', detail: ['target_id' => (int)$ch['id'], 'result' => $res['ok']]);
        $res['ok'] ? Http::ok($res) : Http::error($res['error'], 502);
    }

    /**
     * 一键获取上游全部模型：POST /api/admin/channels/fetch-models
     * body: {base_url, type, api_key?, channel_id?}  — channel_id 存在且未传 api_key 时复用已存密钥
     */
    public static function channelFetchModels(): never
    {
        self::guard();
        $d = Http::body();
        $type = in_array($d['type'] ?? '', ['openai_compatible', 'deepseek', 'anthropic_compatible'], true) ? $d['type'] : 'openai_compatible';
        $apiKey = trim((string)($d['api_key'] ?? ''));
        $baseUrl = rtrim(trim((string)($d['base_url'] ?? '')), '/');
        if ((int)($d['channel_id'] ?? 0) > 0) {
            $ch = DB::one('SELECT * FROM channels WHERE id = ?', [(int)$d['channel_id']]);
            if (!$ch) { Http::error('渠道不存在', 404); }
            $baseUrl = $baseUrl !== '' ? $baseUrl : $ch['base_url'];
            $type = $ch['type'];
            if ($apiKey === '') { $apiKey = Channels::decryptedKey($ch); }
        }
        if ($baseUrl === '' || $apiKey === '') { Http::error('Base URL 与 API Key 不能为空', 422); }
        if (!UpstreamUrlValid($baseUrl)) { Http::error('Base URL 不合法或指向内网地址', 422); }
        $res = \App\Gateway\UpstreamClient::fetchModels($baseUrl, $apiKey, $type);
        if (!$res['ok']) { Http::error($res['error'], 502); }
        Http::ok(['models' => $res['models'], 'total' => count($res['models'])]);
    }

    /** 手动触发某渠道模型同步（合并新增，不删除旧模型）：POST /api/admin/channels/{id}/model-sync */
    public static function channelModelSync(array $p): never
    {
        self::guard();
        $res = \App\Services\ModelSync::syncChannel((int)$p['id']);
        $res['ok'] ? Http::ok($res) : Http::error($res['error'] ?? '同步失败', 502);
    }

    private static function saveChannelGroups(int $cid, mixed $groupIds): void
    {
        DB::run('DELETE FROM channel_groups WHERE channel_id = ?', [$cid]);
        if (!is_array($groupIds)) { return; }
        foreach ($groupIds as $gid) {
            $gid = (int)$gid;
            if ($gid > 0 && DB::one('SELECT id FROM groups WHERE id = ?', [$gid])) {
                DB::run('INSERT INTO channel_groups (channel_id, group_id) VALUES (?,?)', [$cid, $gid]);
            }
        }
    }

    // ---------------- 分组管理 ----------------
    public static function groups(): never
    {
        self::guard();
        $rows = DB::all('SELECT * FROM groups ORDER BY id ASC');
        foreach ($rows as &$g) {
            $g['channel_ids'] = array_map('intval', array_column(DB::all('SELECT channel_id FROM channel_groups WHERE group_id = ?', [(int)$g['id']]), 'channel_id'));
        }
        Http::ok(['items' => $rows]);
    }

    public static function groupCreate(): never
    {
        self::guard();
        $name = trim((string)Http::input('name', ''));
        if ($name === '') { Http::error('分组名不能为空', 422); }
        if (DB::one('SELECT id FROM groups WHERE name = ?', [$name])) { Http::error('分组名已存在', 409); }
        $mult = max(0.0, (float)Http::input('rate_multiplier', 1.0));
        DB::run('INSERT INTO groups (name, description, rate_multiplier, created_at) VALUES (?,?,?,?)',
            [$name, trim((string)Http::input('description', '')), $mult, gmdate('Y-m-d H:i:s')]);
        $gid = DB::lastId();
        $cids = Http::input('channel_ids');
        if (is_array($cids)) {
            foreach ($cids as $cid) {
                $cid = (int)$cid;
                if ($cid > 0 && DB::one('SELECT id FROM channels WHERE id = ?', [$cid])) {
                    DB::run('INSERT IGNORE INTO channel_groups (channel_id, group_id) VALUES (?,?)', [$cid, $gid]);
                }
            }
        }
        Http::ok(['id' => $gid]);
    }

    public static function groupUpdate(array $p): never
    {
        self::guard();
        $id = (int)$p['id'];
        $fields = [];
        $args = [];
        $name = Http::input('name');
        if ($name !== null && trim((string)$name) !== '') { $fields[] = 'name = ?'; $args[] = trim((string)$name); }
        if (Http::input('description') !== null) { $fields[] = 'description = ?'; $args[] = trim((string)Http::input('description')); }
        $mult = Http::input('rate_multiplier');
        if ($mult !== null && is_numeric($mult)) { $fields[] = 'rate_multiplier = ?'; $args[] = max(0.0, (float)$mult); }
        $status = Http::input('status');
        if ($status !== null && in_array($status, ['active', 'disabled'], true)) { $fields[] = 'status = ?'; $args[] = $status; }
        if ($fields !== []) {
            $args[] = $id;
            DB::run('UPDATE groups SET ' . implode(', ', $fields) . ' WHERE id = ?', $args);
        }
        // 分组侧绑定渠道（双向同步 channel_groups）
        $cids = Http::input('channel_ids');
        if (is_array($cids)) {
            DB::run('DELETE FROM channel_groups WHERE group_id = ?', [$id]);
            foreach ($cids as $cid) {
                $cid = (int)$cid;
                if ($cid > 0 && DB::one('SELECT id FROM channels WHERE id = ?', [$cid])) {
                    DB::run('INSERT IGNORE INTO channel_groups (channel_id, group_id) VALUES (?,?)', [$cid, $id]);
                }
            }
        }
        Http::ok();
    }

    public static function groupDelete(array $p): never
    {
        self::guard();
        $id = (int)$p['id'];
        DB::run('DELETE FROM groups WHERE id = ?', [$id]);
        DB::run('DELETE FROM channel_groups WHERE group_id = ?', [$id]);
        DB::run('UPDATE api_keys SET group_id = NULL WHERE group_id = ?', [$id]);
        Http::ok();
    }

    // ---------------- 模型价格 ----------------
    public static function modelPrices(): never
    {
        self::guard();
        Http::ok(['items' => DB::all('SELECT * FROM model_prices ORDER BY model ASC')]);
    }

    public static function modelPriceSave(): never
    {
        self::guard();
        $model = trim((string)Http::input('model', ''));
        if (!preg_match('/^[\w.\-\/:]{1,100}$/', $model)) { Http::error('模型名不合法', 422); }
        $in = max(0.0, (float)Http::input('input_price', 0));
        $out = max(0.0, (float)Http::input('output_price', 0));
        $enabled = (int)(bool)Http::input('enabled', 1);
        $note = trim((string)Http::input('note', ''));
        if (DB::one('SELECT model FROM model_prices WHERE model = ?', [$model])) {
            DB::run('UPDATE model_prices SET input_price = ?, output_price = ?, enabled = ?, note = ? WHERE model = ?', [$in, $out, $enabled, $note, $model]);
        } else {
            DB::run('INSERT INTO model_prices (model, input_price, output_price, enabled, note) VALUES (?,?,?,?,?)', [$model, $in, $out, $enabled, $note]);
        }
        Http::ok();
    }

    public static function modelPriceDelete(array $p): never
    {
        self::guard();
        DB::run('DELETE FROM model_prices WHERE model = ?', [(string)$p['model']]);
        Http::ok();
    }

    // ---------------- 日志 / 流水 ----------------
    public static function usageLogs(): never
    {
        self::guard();
        [$page, $size, $offset] = self::pageParams(30);
        $where = '1=1';
        $args = [];
        $uid = Http::input('user_id');
        if ($uid !== null && $uid !== '') { $where .= ' AND l.user_id = ?'; $args[] = (int)$uid; }
        $model = trim((string)Http::input('model', ''));
        if ($model !== '') { $where .= ' AND l.model LIKE ?'; $args[] = '%' . $model . '%'; }
        $status = trim((string)Http::input('status', ''));
        if (in_array($status, ['completed', 'failed', 'pending'], true)) { $where .= ' AND l.status = ?'; $args[] = $status; }
        $total = (int)DB::value("SELECT COUNT(*) FROM usage_logs l WHERE $where", $args);
        $rows = DB::all(
            "SELECT l.*, u.email FROM usage_logs l LEFT JOIN users u ON u.id = l.user_id
             WHERE $where ORDER BY l.id DESC LIMIT $size OFFSET $offset", $args);
        Http::ok(['total' => $total, 'items' => $rows]);
    }

    public static function transactions(): never
    {
        self::guard();
        [$page, $size, $offset] = self::pageParams(30);
        $where = '1=1';
        $args = [];
        $uid = Http::input('user_id');
        if ($uid !== null && $uid !== '') { $where .= ' AND t.user_id = ?'; $args[] = (int)$uid; }
        $total = (int)DB::value("SELECT COUNT(*) FROM balance_transactions t WHERE $where", $args);
        $rows = DB::all("SELECT t.*, u.email FROM balance_transactions t LEFT JOIN users u ON u.id = t.user_id WHERE $where ORDER BY t.id DESC LIMIT $size OFFSET $offset", $args);
        Http::ok(['total' => $total, 'items' => $rows]);
    }

    public static function auditLogs(): never
    {
        self::guard();
        [$page, $size, $offset] = self::pageParams(30);
        $total = (int)DB::value('SELECT COUNT(*) FROM audit_logs');
        $rows = DB::all("SELECT a.*, u.email FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT $size OFFSET $offset");
        Http::ok(['total' => $total, 'items' => $rows]);
    }

    // ---------------- 系统设置 ----------------
    public static function settings(): never
    {
        self::guard();
        Http::ok(['items' => DB::all('SELECT * FROM settings ORDER BY skey')]);
    }

    public static function settingSave(): never
    {
        $admin = self::guard();
        $key = trim((string)Http::input('key', ''));
        $value = (string)Http::input('value', '');
        if (!preg_match('/^[\w.\-]{1,100}$/', $key)) { Http::error('设置键名不合法', 422); }
        self::upsertSetting($key, $value);
        Audit::log((int)$admin['id'], 'setting_save', detail: ['key' => $key]);
        Http::ok();
    }

    public static function upsertSetting(string $key, string $value): void
    {
        if (DB::$driver === 'mysql') {
            DB::run('INSERT INTO settings (skey, svalue) VALUES (?,?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)', [$key, $value]);
        } else {
            DB::run('INSERT INTO settings (skey, svalue) VALUES (?,?) ON CONFLICT(skey) DO UPDATE SET svalue = excluded.svalue', [$key, $value]);
        }
    }

    // ---------------- 兑换码 ----------------
    public static function redeemCodes(): never
    {
        self::guard();
        [$page, $size, $offset] = self::pageParams();
        $total = (int)DB::value('SELECT COUNT(*) FROM redeem_codes');
        $rows = DB::all("SELECT r.*, u.email AS used_by_email FROM redeem_codes r LEFT JOIN users u ON u.id = r.used_by ORDER BY r.id DESC LIMIT $size OFFSET $offset");
        Http::ok(['total' => $total, 'items' => $rows]);
    }

    public static function redeemCreate(): never
    {
        $admin = self::guard();
        $value = (float)Http::input('value', 0);
        $count = min(100, max(1, (int)Http::input('count', 1)));
        if ($value <= 0) { Http::error('面值必须大于 0', 422); }
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $code = 'RC-' . strtoupper(bin2hex(random_bytes(8)));
            DB::run('INSERT INTO redeem_codes (code, value, created_at) VALUES (?,?,?)', [$code, $value, gmdate('Y-m-d H:i:s')]);
            $codes[] = $code;
        }
        Audit::log((int)$admin['id'], 'redeem_create', detail: ['count' => $count, 'value' => $value]);
        Http::ok(['codes' => $codes]);
    }

    public static function redeemDelete(array $p): never
    {
        self::guard();
        DB::run("DELETE FROM redeem_codes WHERE id = ? AND status = 'unused'", [(int)$p['id']]);
        Http::ok();
    }

    // ---------------- 定时任务 ----------------
    public static function tasks(): never
    {
        self::guard();
        Http::ok(['items' => DB::all('SELECT * FROM tasks ORDER BY id ASC')]);
    }

    public static function taskUpdate(array $p): never
    {
        self::guard();
        $id = (int)$p['id'];
        $fields = [];
        $args = [];
        $enabled = Http::input('enabled');
        if ($enabled !== null) { $fields[] = 'enabled = ?'; $args[] = (int)(bool)$enabled; }
        $interval = Http::input('interval_minutes');
        if ($interval !== null && is_numeric($interval)) {
            $iv = max(1, (int)$interval);
            $fields[] = 'interval_minutes = ?';
            $args[] = $iv;
        }
        if ($fields === []) { Http::error('没有需要更新的字段', 422); }
        $args[] = $id;
        DB::run('UPDATE tasks SET ' . implode(', ', $fields) . ' WHERE id = ?', $args);
        Http::ok();
    }

    public static function taskRun(array $p): never
    {
        $admin = self::guard();
        $res = \App\Services\TaskRunner::runById((int)$p['id'], (int)$admin['id']);
        $res['ok'] ? Http::ok($res) : Http::error($res['message'] ?? '执行失败', 500);
    }

    public static function taskRuns(array $p): never
    {
        self::guard();
        [$page, $size, $offset] = self::pageParams(30);
        $tid = (int)$p['id'];
        $total = (int)DB::value('SELECT COUNT(*) FROM task_runs WHERE task_id = ?', [$tid]);
        $rows = DB::all("SELECT * FROM task_runs WHERE task_id = ? ORDER BY id DESC LIMIT $size OFFSET $offset", [$tid]);
        Http::ok(['total' => $total, 'items' => $rows]);
    }
}

/** Base URL 安全校验（含 SSRF 防护） */
function UpstreamUrlValid(string $url): bool
{
    return \App\Gateway\UpstreamClient::validateBaseUrl($url);
}
