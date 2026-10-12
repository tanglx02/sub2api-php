<?php
/**
 * Sub2API-PHP 一键安装向导
 * 步骤：环境检测 → 数据库 → 管理员 → 完成并锁定
 */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');
define('S2A_ROOT', dirname(__DIR__));

$installedFile = S2A_ROOT . '/data/config.php';
$installed = is_file($installedFile) && (function () use ($installedFile) {
    $c = include $installedFile; return is_array($c) && !empty($c['installed']);
})();
if ($installed) {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><h2>系统已安装</h2><p>安装向导已锁定。如需重新安装，请先备份并删除 <code>data/config.php</code>。</p><p><a href="/">前往控制台</a></p>';
    exit;
}

require_once S2A_ROOT . '/app/Core/Migrator.php';

session_start();
$step = (int)($_GET['step'] ?? 1);
$errors = [];
$okChecks = [];

/** @var PDO|null $pdo */
$pdo = $_SESSION['install_pdo'] ?? null;

function envChecks(): array
{
    return [
        ['PHP >= 8.2', version_compare(PHP_VERSION, '8.2.0', '>='), '当前 ' . PHP_VERSION],
        ['PDO 扩展', extension_loaded('pdo'), '必需'],
        ['pdo_mysql（使用 MySQL 时必需）', extension_loaded('pdo_mysql'), 'MySQL 支持'],
        ['pdo_sqlite（使用 SQLite 时必需）', extension_loaded('pdo_sqlite'), 'SQLite 支持'],
        ['cURL 扩展', extension_loaded('curl'), '转发上游请求必需'],
        ['OpenSSL 扩展', extension_loaded('openssl'), '上游密钥加密必需'],
        ['JSON 扩展', extension_loaded('json'), '必需'],
        ['mbstring 扩展', extension_loaded('mbstring'), '必需'],
        ['data 目录可写', is_writable(S2A_ROOT . '/data') || @mkdir(S2A_ROOT . '/data', 0755, true), S2A_ROOT . '/data'],
    ];
}

// 步骤 2：数据库连接与建表
if ($step === 2 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $driver = ($_POST['driver'] ?? 'sqlite') === 'mysql' ? 'mysql' : 'sqlite';
    try {
        if ($driver === 'mysql') {
            $host = trim($_POST['host'] ?? 'localhost');
            $port = (int)($_POST['port'] ?? 3306);
            $db = trim($_POST['database'] ?? '');
            $user = trim($_POST['username'] ?? '');
            $pass = (string)($_POST['password'] ?? '');
            if ($db === '' || $user === '') { throw new RuntimeException('请填写数据库名和用户名'); }
            $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";
            $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } else {
            $path = S2A_ROOT . '/data/sub2api.db';
            if (is_file($path)) { throw new RuntimeException('SQLite 数据库文件已存在，请先删除 data/sub2api.db 再重新安装'); }
            $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('PRAGMA journal_mode=WAL');
        }
        $log = App\Core\Migrator::migrate($pdo, $driver, S2A_ROOT);
        $_SESSION['install_driver'] = $driver;
        if ($driver === 'mysql') {
            $_SESSION['install_db'] = ['host' => $host, 'port' => $port, 'database' => $db, 'username' => $user, 'password' => $pass];
        } else {
            $_SESSION['install_db'] = ['sqlite_path' => $path];
        }
        $_SESSION['install_pdo'] = null; // 不序列化 PDO
        $_SESSION['migrated'] = true;
        $step = 3;
    } catch (Throwable $e) {
        $errors[] = '数据库连接/建表失败：' . $e->getMessage() . '（请检查连接信息，MySQL 需先手动创建空数据库）';
        $step = 2;
    }
}

// 步骤 3：创建管理员并写配置
if ($step === 3 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (empty($_SESSION['migrated'])) { throw new RuntimeException('请先完成数据库步骤'); }
        $email = strtolower(trim($_POST['email'] ?? ''));
        $pass = (string)($_POST['password'] ?? '');
        $pass2 = (string)($_POST['password2'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { throw new RuntimeException('管理员邮箱格式不正确'); }
        if (strlen($pass) < 8) { throw new RuntimeException('管理员密码至少 8 位'); }
        if ($pass !== $pass2) { throw new RuntimeException('两次输入的密码不一致'); }

        $driver = $_SESSION['install_driver'] ?? 'sqlite';
        $dbCfg = $_SESSION['install_db'] ?? ['sqlite_path' => S2A_ROOT . '/data/sub2api.db'];
        $dbCfg['driver'] = $driver;
        $pdo = $driver === 'mysql'
            ? new PDO("mysql:host={$dbCfg['host']};port={$dbCfg['port']};dbname={$dbCfg['database']};charset=utf8mb4", $dbCfg['username'], $dbCfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION])
            : new PDO('sqlite:' . $dbCfg['sqlite_path'], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        // 初始化种子数据（幂等）
        $now = gmdate('Y-m-d H:i:s');
        $pdo->prepare('INSERT INTO users (email, password_hash, role, balance, created_at) VALUES (?,?,?,?,?)')
            ->execute([$email, password_hash($pass, PASSWORD_DEFAULT), 'admin', 100.0, $now]);
        $pdo->prepare('INSERT INTO groups (name, description, rate_multiplier, created_at) VALUES (?,?,?,?)')
            ->execute(['default', '默认分组', 1.0, $now]);
        $cronToken = bin2hex(random_bytes(16));
        $seed = [
            ['cron_token', $cronToken],
            ['site_name', 'Sub2API-PHP'],
            ['log_retention_days', '90'],
        ];
        $st = $pdo->prepare('INSERT INTO settings (skey, svalue) VALUES (?,?)');
        foreach ($seed as $s) { $st->execute($s); }
        // 默认任务
        $tasks = [
            ['渠道连通性检测', 'channel_health_check', 60],
            ['用量统计与账务整理', 'usage_stats_maintain', 30],
            ['日志清理', 'log_cleanup', 1440],
            ['渠道模型同步', 'channel_model_sync', 30],
        ];
        $tt = $pdo->prepare('INSERT INTO tasks (name, handler, enabled, interval_minutes, next_run_at) VALUES (?,?,1,?,?)');
        foreach ($tasks as $t) { $tt->execute([$t[0], $t[1], $t[2], $now]); }
        // DeepSeek 价格模板（USD / 1M，可在后台修改）
        $pt = $pdo->prepare('INSERT INTO model_prices (model, input_price, output_price, enabled, note) VALUES (?,?,?,?,?)');
        $pt->execute(['deepseek-chat', 0.27, 1.1, 1, 'DeepSeek 官方价格模板，请以官方文档为准并按需修改']);
        $pt->execute(['deepseek-reasoner', 0.55, 2.19, 1, 'DeepSeek 官方价格模板，请以官方文档为准并按需修改']);

        // 写配置文件
        $appKey = 'base64:' . base64_encode(random_bytes(32));
        $cfg = [
            'installed' => true,
            'installed_at' => $now,
            'app_key' => $appKey,
            'cron_token' => $cronToken,
            'database' => $dbCfg,
        ];
        $export = "<?php\n// Sub2API-PHP 配置（自动生成，请勿提交到公共仓库）\nreturn " . var_export($cfg, true) . ";\n";
        if (@file_put_contents($installedFile, $export, LOCK_EX) === false) {
            throw new RuntimeException('无法写入 data/config.php，请检查 data 目录写权限');
        }
        @chmod($installedFile, 0640);
        // 尝试把数据库文件移出 Web 可访问范围由 .htaccess 保护
        session_destroy();
        header('Location: ?step=4');
        exit;
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
        $step = 3;
    }
}

$allOk = true;
foreach (envChecks() as [$name, $ok, $note]) { if (!$ok) { $allOk = false; } }
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sub2API-PHP 安装向导</title>
<style>
body{font-family:-apple-system,"PingFang SC","Microsoft YaHei",sans-serif;background:#f5f7fb;color:#1f2733;margin:0;padding:30px}
.wrap{max-width:680px;margin:0 auto}
h1{font-size:22px}
.steps{display:flex;gap:8px;margin:18px 0}
.step{flex:1;padding:8px;text-align:center;border-radius:8px;background:#e8edf6;color:#7a8699;font-size:13px}
.step.on{background:#2f6bff;color:#fff}
.step.done{background:#12a150;color:#fff}
.card{background:#fff;border:1px solid #e5e9f2;border-radius:12px;padding:22px;margin-bottom:16px}
label{display:block;font-size:13px;color:#7a8699;margin:12px 0 4px}
input,select{width:100%;padding:9px 11px;border:1px solid #e5e9f2;border-radius:8px;font-size:14px}
.btn{display:inline-block;padding:10px 18px;border-radius:8px;border:0;background:#2f6bff;color:#fff;cursor:pointer;font-size:14px;margin-top:16px}
.btn[disabled]{opacity:.5;cursor:not-allowed}
.err{background:#fdecea;color:#d93025;padding:10px 14px;border-radius:8px;margin-bottom:12px;font-size:14px}
.ok{color:#12a150}.bad{color:#d93025;font-weight:600}
table{width:100%;border-collapse:collapse;font-size:14px}
td{padding:7px 4px;border-bottom:1px solid #eef1f6}
.hint{color:#7a8699;font-size:13px;line-height:1.7}
code{background:#f2f5fb;padding:2px 6px;border-radius:6px}
</style>
</head>
<body>
<div class="wrap">
<h1>Sub2API-PHP 安装向导</h1>
<div class="steps">
  <div class="step <?= $step > 1 ? 'done' : ($step === 1 ? 'on' : '') ?>">1. 环境检测</div>
  <div class="step <?= $step > 2 ? 'done' : ($step === 2 ? 'on' : '') ?>">2. 数据库</div>
  <div class="step <?= $step > 3 ? 'done' : ($step === 3 ? 'on' : '') ?>">3. 管理员</div>
  <div class="step <?= $step === 4 ? 'on' : '' ?>">4. 完成</div>
</div>

<?php foreach ($errors as $e): ?><div class="err"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

<?php if ($step === 1): ?>
<div class="card">
  <table>
  <?php foreach (envChecks() as [$name, $ok, $note]): if (!$ok) { $allOkFlag = false; } ?>
    <tr><td><?= htmlspecialchars($name) ?></td><td class="<?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? '通过' : '未通过' ?></td><td class="hint"><?= htmlspecialchars($note) ?></td></tr>
  <?php endforeach; ?>
  </table>
  <?php if (!$allOk): ?>
    <p class="hint" style="margin-top:12px">部分必需项未通过，请联系主机服务商安装对应 PHP 扩展后刷新本页。</p>
  <?php endif; ?>
  <form method="get" action="?"><input type="hidden" name="step" value="2">
    <button class="btn" <?= $allOk ? '' : 'disabled' ?>>下一步：配置数据库</button>
  </form>
</div>

<?php elseif ($step === 2): ?>
<div class="card">
  <form method="post">
    <label>数据库类型</label>
    <select name="driver" id="driver" onchange="document.getElementById('mysqlBox').style.display=this.value==='mysql'?'':'none'">
      <option value="mysql">MySQL 8.0+（推荐）</option>
      <option value="sqlite">SQLite 3（轻量可选）</option>
    </select>
    <div id="mysqlBox">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 14px">
        <div><label>主机</label><input name="host" value="<?= htmlspecialchars($_POST['host'] ?? 'localhost') ?>"></div>
        <div><label>端口</label><input name="port" value="<?= htmlspecialchars($_POST['port'] ?? '3306') ?>"></div>
      </div>
      <label>数据库名（请先在主机面板创建）</label><input name="database" value="<?= htmlspecialchars($_POST['database'] ?? '') ?>">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 14px">
        <div><label>用户名</label><input name="username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"></div>
        <div><label>密码</label><input name="password" type="password"></div>
      </div>
    </div>
    <p class="hint">SQLite 模式将创建 <code>data/sub2api.db</code>。虚拟主机无 MySQL 时选择此项。</p>
    <button class="btn">测试连接并建表</button>
  </form>
</div>

<?php elseif ($step === 3): ?>
<div class="card">
  <p>数据库与表结构创建成功。</p>
  <form method="post">
    <label>管理员邮箱</label><input name="email" type="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 14px">
      <div><label>密码（≥8位）</label><input name="password" type="password"></div>
      <div><label>确认密码</label><input name="password2" type="password"></div>
    </div>
    <p class="hint">管理员初始余额 100.00 USD，安装后可在后台调整。系统将自动生成应用加密密钥与 Cron Token，并写入 DeepSeek 模型价格模板。</p>
    <button class="btn">完成安装</button>
  </form>
</div>

<?php elseif ($step === 4): ?>
<div class="card">
  <h2 style="color:#12a150">安装完成 🎉</h2>
  <p class="hint" style="margin-top:12px">
    1. 安装向导已锁定（<code>data/config.php</code> 存在时无法再次运行）。<br>
    2. 前往 <a href="/">控制台登录</a>，配置上游渠道（DeepSeek 填 Base URL <code>https://api.deepseek.com</code> 与 API Key）。<br>
    3. 建议配置 Cron：每 5 分钟执行 <code>curl "<?= htmlspecialchars(dirname($_SERVER['SCRIPT_NAME'])) ?>/../cron.php?token=你的cron_token"</code>（token 在后台「定时任务」页可见）。<br>
    4. 如主机为 Apache，请确认 <code>.htaccess</code> 生效；Nginx 需配置所有请求回退到 <code>index.php</code>（见 INSTALL.md）。
  </p>
  <a class="btn" href="/">前往控制台</a>
</div>
<?php endif; ?>
</div>
</body>
</html>
