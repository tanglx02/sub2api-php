<?php
/**
 * Sub2API-PHP cron 入口。
 * 用法：
 *   php cron.php                 # 本机 cron
 *   curl "https://你的域名/cron.php?token=安装时生成的 CRON_TOKEN"   # 外部触发
 */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');
define('S2A_ROOT', __DIR__);
spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $file = S2A_ROOT . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) { require $file; }
    }
});

use App\Core\Config;
use App\Core\Database as DB;

if (!Config::installed()) { http_response_code(403); exit(json_encode(['error' => 'not installed'])); }
$cfg = Config::all();
$token = $_GET['token'] ?? '';
// CLI: php cron.php [token]；HTTP: ?token=...
if (PHP_SAPI === 'cli') { $token = $argv[1] ?? ''; }
$expected = (string)($cfg['cron_token'] ?? '');
if ($expected === '' || !hash_equals($expected, (string)$token)) {
    http_response_code(403);
    header('Content-Type: application/json');
    exit(json_encode(['success' => false, 'error' => ['message' => '无效的 cron token']]));
}
DB::connect((array)$cfg['database']);
require_once S2A_ROOT . '/app/services_loaded.php';
$results = \App\Services\TaskRunner::runDue();
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success' => true, 'data' => $results], JSON_UNESCAPED_UNICODE);
