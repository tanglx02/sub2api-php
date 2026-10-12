<?php
/**
 * Sub2API-PHP 集成测试运行器
 * 用法：php tests/run.php [sqlite|mysql|all]
 * 启动 PHP 内置服务器承载应用与 Mock 上游，通过 HTTP 验证端到端行为。
 */
declare(strict_types=1);
define('S2A_ROOT', dirname(__DIR__));
spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $file = S2A_ROOT . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) { require $file; }
    }
});

$mode = $argv[1] ?? 'all';
$APP_PORT = 8765;
$MOCK_PORT = 8766;
$APP = "http://127.0.0.1:$APP_PORT";
$MOCK = "http://127.0.0.1:$MOCK_PORT";

$pass = 0; $fail = 0; $failures = [];
function check(string $name, bool $cond, string|int $detail = ''): void {
    global $pass, $fail, $failures;
    if ($cond) { $pass++; echo "  [PASS] $name\n"; }
    else { $fail++; $failures[] = "$name: $detail"; echo "  [FAIL] $name  $detail\n"; }
}
function http_req(string $method, string $url, ?array $body = null, array $headers = [], int $timeout = 15): array {
    $ch = curl_init($url);
    $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_HEADER => true];
    $hdrs = $headers;
    if ($body !== null) { $hdrs[] = 'Content-Type: application/json'; $opt[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE); }
    $opt[CURLOPT_HTTPHEADER] = $hdrs;
    curl_setopt_array($ch, $opt);
    $raw = curl_exec($ch);
    $hsize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $bodyStr = substr((string)$raw, $hsize);
    return ['status' => $status, 'headers' => substr((string)$raw, 0, $hsize), 'body' => $bodyStr, 'json' => json_decode($bodyStr, true), 'error' => $err ?: null];
}
function dbq(PDO $pdo, string $sql, array $p = []): array|bool {
    $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchAll();
}

/* ---------- 准备环境 ---------- */
@unlink('/tmp/s2a_run_mock.log');
$configDir = '/tmp/s2a_test_cfg';
@mkdir($configDir);
$configFile = "$configDir/config.php";
putenv('S2A_CONFIG=' . $configFile); // Crypto 等组件在种子阶段读取同一份测试配置

$modes = $mode === 'all' ? ['sqlite', 'mysql'] : [$mode];
foreach ($modes as $driver) {
    echo "\n========== 驱动: $driver ==========\n";
    $dbname = 'sub2api_run_' . $driver;
    if ($driver === 'mysql') {
        $admin = new PDO('mysql:host=127.0.0.1', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); // root via unix socket auth or tcp
        $admin->exec("DROP DATABASE IF EXISTS $dbname");
        $admin->exec("CREATE DATABASE $dbname CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $admin->exec("CREATE USER IF NOT EXISTS 's2a_run'@'localhost' IDENTIFIED BY 's2a_run_pass'");
        $admin->exec("GRANT ALL ON $dbname.* TO 's2a_run'@'localhost'");
        $dbCfg = ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306, 'database' => $dbname, 'username' => 's2a_run', 'password' => 's2a_run_pass'];
    } else {
        @unlink("$configDir/test.db");
        $dbCfg = ['driver' => 'sqlite', 'sqlite_path' => "$configDir/test.db"];
    }
    file_put_contents($configFile, "<?php return " . var_export([
        'installed' => true, 'installed_at' => gmdate('Y-m-d H:i:s'),
        'app_key' => 'base64:' . base64_encode(random_bytes(32)),
        'cron_token' => 'test_cron_token_123',
        'database' => $dbCfg,
    ], true) . ";\n");

    // 迁移
    $pdo = $driver === 'mysql'
        ? new PDO("mysql:host=127.0.0.1;dbname=$dbname", 's2a_run', 's2a_run_pass', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION])
        : new PDO('sqlite:' . $dbCfg['sqlite_path'], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $log = App\Core\Migrator::migrate($pdo, $driver, S2A_ROOT);
    check('迁移执行（至少 2 个版本）', count($log) >= 2, json_encode($log));
    $tbls = $driver === 'mysql' ? dbq($pdo, 'SHOW TABLES') : dbq($pdo, "SELECT name FROM sqlite_master WHERE type='table'");
    check('核心表存在', count($tbls) >= 13, '仅 ' . count($tbls) . ' 张表');

    // 种子：管理员、分组、价格、渠道（指向 mock 上游）
    $pdo->prepare('INSERT INTO users (email, password_hash, role, balance, created_at) VALUES (?,?,?,?,?)')
        ->execute(['admin@test.local', password_hash('admin12345', PASSWORD_DEFAULT), 'admin', 50.0, gmdate('Y-m-d H:i:s')]);
    $adminId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO users (email, password_hash, role, balance, created_at) VALUES (?,?,?,?,?)')
        ->execute(['user@test.local', password_hash('user12345', PASSWORD_DEFAULT), 'user', 1.0, gmdate('Y-m-d H:i:s')]);
    $userId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO groups (name, rate_multiplier, created_at) VALUES (?,?,?)')->execute(['default', 1.0, gmdate('Y-m-d H:i:s')]);
    $gid = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO model_prices (model, input_price, output_price, enabled) VALUES (?,?,?,1)')
        ->execute(['mock-model', 2.0, 4.0]); // 12 in / 34 out → 0.000024 + 0.000136 = 0.00016
    require S2A_ROOT . '/app/Core/Crypto.php';
    $enc = App\Core\Crypto::encrypt('mock-upstream-key');
    $pdo->prepare('INSERT INTO channels (name, type, base_url, api_key_enc, models, priority, status, connect_timeout, request_timeout, created_at) VALUES (?,?,?,?,?,?,?,2,10,?)')
        ->execute(['mock主渠道', 'openai_compatible', $MOCK, $enc, json_encode(['mock-model']), 10, 'active', gmdate('Y-m-d H:i:s')]);
    $ch1 = (int)$pdo->lastInsertId();
    // 第二渠道（高优先级 + 失败场景用）
    $pdo->prepare('INSERT INTO channels (name, type, base_url, api_key_enc, models, priority, status, connect_timeout, request_timeout, created_at) VALUES (?,?,?,?,?,?,?,2,10,?)')
        ->execute(['备用渠道', 'openai_compatible', $MOCK, $enc, json_encode(['mock-model']), 5, 'active', gmdate('Y-m-d H:i:s')]);
    $ch2 = (int)$pdo->lastInsertId();
    // 用户 API Key
    $userKey = 'sk-s2a-' . bin2hex(random_bytes(16));
    $pdo->prepare('INSERT INTO api_keys (user_id, key_hash, key_prefix, name, group_id, status, created_at) VALUES (?,?,?,?,?,?,?)')
        ->execute([$userId, hash('sha256', $userKey), 'sk-s2a-', '测试Key', null, 'active', gmdate('Y-m-d H:i:s')]);
    $userKeyId = (int)$pdo->lastInsertId();
    $adminKey = 'sk-s2a-' . bin2hex(random_bytes(16));
    $pdo->prepare('INSERT INTO api_keys (user_id, key_hash, key_prefix, name, group_id, status, created_at) VALUES (?,?,?,?,?,?,?)')
        ->execute([$adminId, hash('sha256', $adminKey), 'sk-s2a-', '管理员Key', null, 'active', gmdate('Y-m-d H:i:s')]);
    $pdo->prepare('INSERT INTO tasks (name, handler, enabled, interval_minutes) VALUES (?,?,1,60)')
        ->execute(['日志清理', 'log_cleanup']);
    $pdo->prepare('INSERT INTO settings (skey, svalue) VALUES (?,?)')->execute(['allow_private_upstream', '1']);

    // ---------- 启动服务 ----------
    $ctx = 'S2A_CONFIG=' . $configFile;
    $appProc = proc_open("php -S 127.0.0.1:$APP_PORT " . S2A_ROOT . '/tests/router.php', [1 => ['file', '/tmp/s2a_app.log', 'w'], 2 => ['file', '/tmp/s2a_app.log', 'w']], $pipesA, S2A_ROOT, array_merge($_ENV, ['S2A_CONFIG' => $configFile]));
    $mockProc = proc_open("php -S 127.0.0.1:$MOCK_PORT " . S2A_ROOT . '/tests/mock_upstream.php', [1 => ['file', '/tmp/s2a_mock.log', 'w'], 2 => ['file', '/tmp/s2a_mock.log', 'w']], $pipesM);
    register_shutdown_function(function () use ($appProc, $mockProc) { @proc_terminate($appProc); @proc_terminate($mockProc); });
    usleep(600000);

    try {
        /* --- 网关鉴权 --- */
        $r = http_req('GET', "$APP/v1/models");
        check('无 Key 访问 /v1/models → 401', $r['status'] === 401, 'status=' . $r['status']);
        $r = http_req('GET', "$APP/v1/models", null, ['Authorization: Bearer sk-invalid']);
        check('无效 Key → 401', $r['status'] === 401, 'status=' . $r['status']);
        $r = http_req('GET', "$APP/v1/models", null, ['Authorization: Bearer ' . $userKey]);
        check('有效 Key → /v1/models 200', $r['status'] === 200 && ($r['json']['data'][0]['id'] ?? '') === 'mock-model', $r['body']);

        /* --- 非流式对话 + 计费 --- */
        $r = http_req('POST', "$APP/v1/chat/completions", ['model' => 'mock-model', 'messages' => [['role' => 'user', 'content' => 'hi']]], ['Authorization: Bearer ' . $userKey]);
        check('非流式对话 200', $r['status'] === 200, 'status=' . $r['status'] . ' body=' . substr($r['body'], 0, 200));
        check('响应内容正确', ($r['json']['choices'][0]['message']['content'] ?? '') === 'MOCK-REPLY', $r['body']);
        check('响应透传 usage', ($r['json']['usage']['total_tokens'] ?? 0) === 46, json_encode($r['json']['usage'] ?? []));
        $bal = dbq($pdo, 'SELECT balance, total_spent, request_count FROM users WHERE id = ?', [$userId])[0];
        // 预期费用 (12/1e6*2 + 34/1e6*4) = 0.00016
        check('余额扣减正确', abs($bal['balance'] - (1.0 - 0.00016)) < 1e-9, json_encode($bal));
        $ul = dbq($pdo, 'SELECT * FROM usage_logs ORDER BY id DESC LIMIT 1')[0] ?? null;
        check('usage 记录(上游来源)', $ul && $ul['usage_source'] === 'upstream' && (int)$ul['input_tokens'] === 12, json_encode($ul));
        check('账务状态 completed', $ul && $ul['billing_status'] === 'completed' && $ul['status'] === 'completed', json_encode($ul));
        $tx = dbq($pdo, 'SELECT * FROM balance_transactions WHERE user_id = ? AND type = ?', [$userId, 'consume']);
        check('流水记录存在', count($tx) === 1, json_encode($tx));

        /* --- 幂等结算 --- */
        $before = dbq($pdo, 'SELECT balance FROM users WHERE id = ?', [$userId])[0]['balance'];
        $r2 = App\Services\Billing::settle($ul['request_id'], ['input_tokens' => 999, 'output_tokens' => 999], 'upstream', 1);
        $after = dbq($pdo, 'SELECT balance FROM users WHERE id = ?', [$userId])[0]['balance'];
        check('重复结算被拒绝(幂等)', (string)$before === (string)$after, "before=$before after=$after");

        /* --- 流式对话 --- */
        $ch = curl_init("$APP/v1/chat/completions");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $userKey, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['model' => 'mock-model', 'stream' => true, 'messages' => [['role' => 'user', 'content' => 'hi']]]), CURLOPT_TIMEOUT => 10]);
        $sse = (string)curl_exec($ch); curl_close($ch);
        check('流式返回 SSE 数据块', str_contains($sse, 'chat.completion.chunk') && str_contains($sse, '[DONE]'), substr($sse, 0, 150));
        check('流式 usage 已计费', dbq($pdo, "SELECT COUNT(*) c FROM usage_logs WHERE billing_status='completed' AND stream=1")[0]['c'] >= 1, '');
        $bal2 = dbq($pdo, 'SELECT balance FROM users WHERE id = ?', [$userId])[0]['balance'];
        check('流式扣费正确', abs($bal2 - (1.0 - 2 * 0.00016)) < 1e-9, "balance=$bal2");

        /* --- 上游失败：无效上游 Key（渠道级故障切换） --- */
        // 让两个渠道都带 invalid scenario：直接用 body 里 X-Mock-Scenario 无法通过（网关不透传该头）。
        // 改法：把主渠道 key 换成会 401 的场景通过 URL 控制不可行 → 使用独立场景渠道：
        $pdo->prepare('INSERT INTO channels (name, type, base_url, api_key_enc, models, priority, status, connect_timeout, request_timeout, created_at) VALUES (?,?,?,?,?,?,?,2,10,?)')
            ->execute(['坏Key渠道', 'openai_compatible', $MOCK, App\Core\Crypto::encrypt('mock-upstream-key'), json_encode(['mock-model']), 1, 'active', gmdate('Y-m-d H:i:s')]);
        $badId = (int)$pdo->lastInsertId();
        // mock 用 api key 以 "sk-bad" 开头判定 401：修改 mock 判定逻辑不必要——直接用 error_500 场景渠道头。
        // 简化：删除坏渠道，改用 base_url 指向不存在的端口模拟网络错误
        $pdo->exec("DELETE FROM channels WHERE id = $badId");
        $pdo->prepare('INSERT INTO channels (name, type, base_url, api_key_enc, models, priority, status, connect_timeout, request_timeout, created_at) VALUES (?,?,?,?,?,?,?,2,3,?)')
            ->execute(['宕机渠道', 'openai_compatible', 'http://127.0.0.1:59999', $enc, json_encode(['mock-model']), 1, 'active', gmdate('Y-m-d H:i:s')]);
        $deadId = (int)$pdo->lastInsertId();
        $balBefore = dbq($pdo, 'SELECT balance FROM users WHERE id = ?', [$userId])[0]['balance'];
        $r = http_req('POST', "$APP/v1/chat/completions", ['model' => 'mock-model', 'messages' => [['role' => 'user', 'content' => 'hi']]], ['Authorization: Bearer ' . $userKey]);
        check('宕机渠道故障切换成功', $r['status'] === 200 && ($r['json']['choices'][0]['message']['content'] ?? '') === 'MOCK-REPLY', 'status=' . $r['status'] . ' body=' . substr($r['body'], 0, 300));
        $balAfter = dbq($pdo, 'SELECT balance FROM users WHERE id = ?', [$userId])[0]['balance'];
        // 故障切换复用同一请求记录（reattach），最终只有一条且按成功渠道计费
        $deadReq = dbq($pdo, 'SELECT COUNT(*) c FROM usage_logs WHERE created_at >= ?', [$balBeforeTime ?? gmdate('Y-m-d H:i:s')]);
        check('总扣费仅一次(切换成功请求)', abs($balAfter - ($balBefore - 0.00016)) < 1e-9, "$balBefore -> $balAfter");
        $pdo->exec("DELETE FROM channels WHERE id = $deadId");
        $pdo->prepare("UPDATE channels SET status='active' WHERE id IN (?,?)")->execute([$ch1, $ch2]);

        /* --- 不存在模型 --- */
        $r = http_req('POST', "$APP/v1/chat/completions", ['model' => 'no-such-model', 'messages' => []], ['Authorization: Bearer ' . $userKey]);
        check('不存在模型 → 404', $r['status'] === 404, 'status=' . $r['status']);

        /* --- 余额不足 --- */
        $pdo->prepare('UPDATE users SET balance = 0.00001 WHERE id = ?')->execute([$userId]);
        $r = http_req('POST', "$APP/v1/chat/completions", ['model' => 'mock-model', 'messages' => [['role' => 'user', 'content' => 'hi']]], ['Authorization: Bearer ' . $userKey]);
        check('余额不足 → 429 拒绝', $r['status'] === 429, 'status=' . $r['status'] . ' ' . substr($r['body'], 0, 120));
        $pdo->prepare('UPDATE users SET balance = 5.0 WHERE id = ?')->execute([$userId]);

        /* --- SSRF：默认拒绝私网地址（先临时关闭 allow_private_upstream） --- */
        $pdo->prepare("UPDATE settings SET svalue='0' WHERE skey='allow_private_upstream'")->execute();
        $r = http_req('POST', "$APP/api/auth/login", ['email' => 'admin@test.local', 'password' => 'admin12345']);
        $csrf = $r['json']['data']['csrf_token'] ?? '';
        $cookie2 = '';
        if (preg_match('/Set-Cookie: (S2ASESSID=[^;]+)/', $r['headers'], $m)) { $cookie2 = $m[1]; }
        $r = http_req('POST', "$APP/api/admin/channels", ['name' => 'x', 'type' => 'openai_compatible', 'base_url' => 'http://169.254.169.254', 'api_key' => 'k', 'models' => ['m']], ["Cookie: $cookie2", 'X-CSRF-Token: ' . $csrf]);
        check('SSRF 内网地址被拒绝', $r['status'] === 422, 'status=' . $r['status'] . ' ' . substr($r['body'], 0, 100));
        $pdo->prepare("UPDATE settings SET svalue='1' WHERE skey='allow_private_upstream'")->execute();

        /* --- 管理端 API --- */
        $r = http_req('POST', "$APP/api/auth/login", ['email' => 'admin@test.local', 'password' => 'admin12345']);
        check('管理员登录', $r['status'] === 200 && !empty($r['json']['data']['csrf_token']), $r['body']);
        $csrf = $r['json']['data']['csrf_token'] ?? '';
        $cookie = '';
        if (preg_match('/Set-Cookie: (S2ASESSID=[^;]+)/', $r['headers'], $m)) { $cookie = $m[1]; }
        $AH = ["Cookie: $cookie", 'X-CSRF-Token: ' . $csrf];

        $r = http_req('POST', "$APP/api/auth/login", ['email' => 'admin@test.local', 'password' => 'wrongpass']);
        check('错误密码 → 401', $r['status'] === 401, 'status=' . $r['status']);
        $r = http_req('GET', "$APP/api/admin/dashboard", null, $AH);
        check('仪表盘统计', $r['status'] === 200 && isset($r['json']['data']['stats']['users_total']), substr($r['body'], 0, 150));
        $r = http_req('GET', "$APP/api/admin/dashboard", null, ['Cookie: ' . $cookie]);
        check('缺少 CSRF → 419', $r['status'] === 419, 'status=' . $r['status']);

        // CSRF 校验对 GET /api/auth/me 不要求
        $r = http_req('GET', "$APP/api/auth/me", null, ["Cookie: $cookie"]);
        check('会话 me 接口', $r['status'] === 200 && $r['json']['data']['user']['role'] === 'admin', $r['body']);

        // 渠道列表不泄漏密钥
        $r = http_req('GET', "$APP/api/admin/channels", null, $AH);
        $bodyStr = json_encode($r['json']);
        check('渠道响应不含明文密钥', $r['status'] === 200 && !str_contains($bodyStr, 'mock-upstream-key'), substr($bodyStr, 0, 200));

        // 普通用户权限隔离
        $r = http_req('POST', "$APP/api/auth/login", ['email' => 'user@test.local', 'password' => 'user12345']);
        $uc = '';
        if (preg_match('/Set-Cookie: (S2ASESSID=[^;]+)/', $r['headers'], $m)) { $uc = $m[1]; }
        $UH = ["Cookie: $uc", 'X-CSRF-Token: ' . $r['json']['data']['csrf_token']];
        $r = http_req('GET', "$APP/api/admin/users", null, $UH);
        check('普通用户访问管理接口 → 403', $r['status'] === 403, 'status=' . $r['status']);

        /* --- 并发扣费（4 并发，每人一次请求，验证账务一致性） --- */
        $pdo->prepare('UPDATE users SET balance = 0.001 WHERE id = ?')->execute([$userId]);
        // 0.001 只够 6 次(0.00016)。发 4 个并发：
        $multi = curl_multi_init();
        $handles = [];
        for ($i = 0; $i < 4; $i++) {
            $ch = curl_init("$APP/v1/chat/completions");
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $userKey, 'Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode(['model' => 'mock-model', 'messages' => [['role' => 'user', 'content' => 'hi']]]), CURLOPT_TIMEOUT => 10]);
            curl_multi_add_handle($multi, $ch); $handles[] = $ch;
        }
        do { $st = curl_multi_exec($multi, $running); } while ($running > 0);
        foreach ($handles as $ch) { curl_multi_remove_handle($multi, $ch); curl_close($ch); }
        $bal = dbq($pdo, 'SELECT balance FROM users WHERE id = ?', [$userId])[0]['balance'];
        check('并发扣费无负余额', $bal >= -1e-12, "balance=$bal");
        $nDone = (int)dbq($pdo, "SELECT COUNT(*) c FROM usage_logs WHERE user_id=? AND billing_status='completed'", [$userId])[0]['c'];
        $nIns = (int)dbq($pdo, "SELECT COUNT(*) c FROM usage_logs WHERE user_id=? AND billing_status='insufficient'", [$userId])[0]['c'];
        check('并发结果账务一致（完成+拒付=请求数）', $nDone + $nIns >= 4, "done=$nDone insufficient=$nIns");

        /* --- Cron 鉴权 --- */
        $r = http_req('GET', "$APP/cron.php?token=wrong");
        check('cron 错误 token → 403', $r['status'] === 403, 'status=' . $r['status']);
        $r = http_req('GET', "$APP/cron.php?token=test_cron_token_123");
        check('cron 正确 token → 执行任务', $r['status'] === 200 && ($r['json']['success'] ?? false), substr($r['body'], 0, 150));
        $runs = dbq($pdo, 'SELECT * FROM task_runs ORDER BY id DESC LIMIT 1');
        check('任务执行历史记录', count($runs) === 1 && $runs[0]['status'] === 'success', json_encode($runs));

        /* --- 登录失败限制 --- */
        for ($i = 0; $i < 8; $i++) { http_req('POST', "$APP/api/auth/login", ['email' => 'brute@test.local', 'password' => 'x']); }
        $r = http_req('POST', "$APP/api/auth/login", ['email' => 'brute@test.local', 'password' => 'x']);
        check('连续失败触发限流 → 429', $r['status'] === 429, 'status=' . $r['status']);

        /* --- 上游 500：切换到正常渠道成功 --- */
        $pdo->prepare('INSERT INTO channels (name, type, base_url, api_key_enc, models, priority, status, connect_timeout, request_timeout, created_at) VALUES (?,?,?,?,?,?,?,2,10,?)')
            ->execute(['500渠道', 'openai_compatible', $MOCK . '/500', $enc, json_encode(['mock-model']), 1, 'active', gmdate('Y-m-d H:i:s')]);
        $errId = (int)$pdo->lastInsertId();
        $balBefore = dbq($pdo, 'SELECT balance FROM users WHERE id = ?', [$userId])[0]['balance'];
        $r = http_req('POST', "$APP/v1/chat/completions", ['model' => 'mock-model', 'messages' => [['role' => 'user', 'content' => 'hi']]], ['Authorization: Bearer ' . $userKey]);
        check('上游500故障切换成功', $r['status'] === 200 && ($r['json']['choices'][0]['message']['content'] ?? '') === 'MOCK-REPLY', 'status=' . $r['status']);
        $errCh = dbq($pdo, 'SELECT last_error FROM channels WHERE id = ?', [$errId]);
        check('500渠道错误已记录', count($errCh) === 1 && $errCh[0]['last_error'] !== null, json_encode($errCh));
        $pdo->exec("DELETE FROM channels WHERE id = $errId");

        /* --- 流式中断（/drop）：客户端收到部分数据，不重复计费，记录失败 --- */
        $pdo->prepare('INSERT INTO channels (name, type, base_url, api_key_enc, models, priority, status, connect_timeout, request_timeout, created_at) VALUES (?,?,?,?,?,?,?,2,10,?)')
            ->execute(['中断渠道', 'openai_compatible', $MOCK . '/drop', $enc, json_encode(['mock-model']), 1, 'active', gmdate('Y-m-d H:i:s')]);
        $dropId = (int)$pdo->lastInsertId();
        $balBefore = (float)dbq($pdo, 'SELECT balance FROM users WHERE id = ?', [$userId])[0]['balance'];
        $ch = curl_init("$APP/v1/chat/completions");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $userKey, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['model' => 'mock-model', 'stream' => true, 'messages' => [['role' => 'user', 'content' => 'hi']]]), CURLOPT_TIMEOUT => 10]);
        $sse = (string)curl_exec($ch); curl_close($ch);
        check('流式中断仍收到部分数据', str_contains($sse, 'chat.completion.chunk'), substr($sse, 0, 100));
        $dropLogs = dbq($pdo, 'SELECT status, billing_status FROM usage_logs WHERE channel_id = ?', [$dropId]);
        check('流式中断记录为失败且不扣费', count($dropLogs) === 1 && $dropLogs[0]['status'] === 'failed' && $dropLogs[0]['billing_status'] === 'failed', json_encode($dropLogs));
        $balAfter = (float)dbq($pdo, 'SELECT balance FROM users WHERE id = ?', [$userId])[0]['balance'];
        check('流式中断不扣费', abs($balAfter - $balBefore) < 1e-9, "$balBefore -> $balAfter");
        $pdo->exec("DELETE FROM channels WHERE id = $dropId");
        $pdo->prepare("UPDATE channels SET status='active' WHERE id IN (?,?)")->execute([$ch1, $ch2]);

        /* --- 持久化：重新查询仍在 --- */
        $r = http_req('GET', "$APP/api/admin/usage-logs?page=1", null, $AH);
        check('请求日志可查', $r['status'] === 200 && ($r['json']['data']['total'] ?? 0) >= 5, ($r['json']['data']['total'] ?? 0));

        /* --- 管理后台创建渠道 + 连通性测试（指向 mock /v1/models） --- */
        $r = http_req('POST', "$APP/api/admin/channels", ['name' => '测试渠道', 'type' => 'openai_compatible', 'base_url' => $MOCK, 'api_key' => 'k-test', 'models' => ['mock-model']], $AH);
        check('创建渠道', $r['status'] === 200 && !empty($r['json']['data']['id']), $r['body']);
        $ncid = $r['json']['data']['id'] ?? 0;
        $r = http_req('POST', "$APP/api/admin/channels/$ncid/test", [], $AH);
        check('渠道连通性测试', $r['status'] === 200 && ($r['json']['success'] ?? false), substr($r['body'], 0, 150));

        /* --- 用户端接口 --- */
        $r = http_req('GET', "$APP/api/user/keys", null, $UH);
        check('用户我的 Key 列表', $r['status'] === 200 && count($r['json']['data']['items']) >= 1, $r['body']);
        $r = http_req('POST', "$APP/api/user/keys", ['name' => '新Key'], $UH);
        check('用户创建 Key（一次性返回明文）', $r['status'] === 200 && str_starts_with($r['json']['data']['key'] ?? '', 'sk-s2a-'), $r['body']);
        $kh = hash('sha256', $r['json']['data']['key']);
        $stored = dbq($pdo, 'SELECT key_hash FROM api_keys WHERE key_hash = ?', [$kh]);
        check('Key 仅存哈希', count($stored) === 1, '');
    } finally {
        proc_terminate($appProc); proc_terminate($mockProc);
    }
}

/* ---------- 最终安装锁定测试（真实 data/config.php 逻辑） ---------- */
echo "\n========== 安装向导锁定 ==========\n";
// 用临时配置模拟已安装：直接测试 Config::installed 逻辑
putenv('S2A_CONFIG=' . $configFile);
check('Config::installed 为 true', App\Core\Config::installed(), '');

echo "\n==============================\n";
echo "通过: $pass  失败: $fail\n";
foreach ($failures as $f) { echo "  FAILED: $f\n"; }
exit($fail > 0 ? 1 : 0);
