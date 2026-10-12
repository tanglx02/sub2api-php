<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

define('S2A_ROOT', __DIR__);
spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $file = S2A_ROOT . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) { require $file; }
    }
});

use App\Core\Config;
use App\Core\Database as DB;
use App\Core\Http;
use App\Core\Router;

$root = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$path = Router::currentPath();

// ---------- 安装向导 ----------
if (!Config::installed()) {
    if ($path === '/install' || str_starts_with($path, '/install/')) {
        require S2A_ROOT . '/install/index.php';
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($root . '/install') . '">';
    echo '<p>系统尚未安装，正在跳转到安装向导… <a href="' . htmlspecialchars($root . '/install') . '">点击继续</a></p>';
    exit;
}

// ---------- 静态资源 ----------
if (preg_match('#^/assets/(.+)$#', $path, $m) || preg_match('#^/favicon\.ico$#', $path)) {
    $file = S2A_ROOT . '/public/' . ltrim($path, '/');
    if (is_file($file)) {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $types = ['css' => 'text/css', 'js' => 'application/javascript', 'svg' => 'image/svg+xml',
                  'png' => 'image/png', 'ico' => 'image/x-icon', 'html' => 'text/html'];
        header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
        readfile($file);
        exit;
    }
    http_response_code(404);
    exit('Not Found');
}

// ---------- API 与网关路由 ----------
require_once S2A_ROOT . '/app/routes.php';

$isApi = str_starts_with($path, '/api/') || str_starts_with($path, '/v1/');
if (!$isApi) {
    // 非 API 路径：输出单页应用
    header('Content-Type: text/html; charset=utf-8');
    readfile(S2A_ROOT . '/public/index.html');
    exit;
}

$router = new Router();

// API 网关（Bearer 认证，无 CSRF/Session）
$router->add('GET', '/v1/models', [App\Gateway\OpenAIController::class, 'models']);
$router->add('POST', '/v1/chat/completions', [App\Gateway\OpenAIController::class, 'chatCompletions']);
$router->add('POST', '/v1/messages', [App\Gateway\AnthropicController::class, 'messages']);

// 管理/用户 Web API（Session + CSRF）
$g = 'App\Controllers\AdminController';
$u = 'App\Controllers\UserController';
$a = 'App\Controllers\AuthController';
foreach ([
    ['POST', '/api/auth/login', [$a, 'login']],
    ['POST', '/api/auth/logout', [$a, 'logout']],
    ['GET', '/api/auth/me', [$a, 'me']],
    ['POST', '/api/auth/password', [$a, 'changePassword']],
    ['GET', '/api/admin/dashboard', [$g, 'dashboard']],
    ['GET', '/api/admin/system', [$g, 'systemStatus']],
    ['GET', '/api/admin/users', [$g, 'users']],
    ['POST', '/api/admin/users', [$g, 'userCreate']],
    ['PUT', '/api/admin/users/{id}', [$g, 'userUpdate']],
    ['DELETE', '/api/admin/users/{id}', [$g, 'userDelete']],
    ['GET', '/api/admin/users/{id}/transactions', [$g, 'userTransactions']],
    ['GET', '/api/admin/keys', [$g, 'keys']],
    ['PUT', '/api/admin/keys/{id}', [$g, 'keyUpdate']],
    ['DELETE', '/api/admin/keys/{id}', [$g, 'keyDelete']],
    ['POST', '/api/admin/keys/{id}/rotate', [$g, 'keyRotate']],
    ['GET', '/api/admin/channels', [$g, 'channels']],
    ['POST', '/api/admin/channels', [$g, 'channelCreate']],
    ['POST', '/api/admin/channels/fetch-models', [$g, 'channelFetchModels']],
    ['PUT', '/api/admin/channels/{id}', [$g, 'channelUpdate']],
    ['DELETE', '/api/admin/channels/{id}', [$g, 'channelDelete']],
    ['POST', '/api/admin/channels/{id}/test', [$g, 'channelTest']],
    ['POST', '/api/admin/channels/{id}/model-sync', [$g, 'channelModelSync']],
    ['GET', '/api/admin/stats', [$g, 'stats']],
    ['GET', '/api/admin/groups', [$g, 'groups']],
    ['POST', '/api/admin/groups', [$g, 'groupCreate']],
    ['PUT', '/api/admin/groups/{id}', [$g, 'groupUpdate']],
    ['DELETE', '/api/admin/groups/{id}', [$g, 'groupDelete']],
    ['GET', '/api/admin/model-prices', [$g, 'modelPrices']],
    ['POST', '/api/admin/model-prices', [$g, 'modelPriceSave']],
    ['DELETE', '/api/admin/model-prices/{model}', [$g, 'modelPriceDelete']],
    ['GET', '/api/admin/usage-logs', [$g, 'usageLogs']],
    ['GET', '/api/admin/transactions', [$g, 'transactions']],
    ['GET', '/api/admin/audit-logs', [$g, 'auditLogs']],
    ['GET', '/api/admin/settings', [$g, 'settings']],
    ['POST', '/api/admin/settings', [$g, 'settingSave']],
    ['GET', '/api/admin/redeem-codes', [$g, 'redeemCodes']],
    ['POST', '/api/admin/redeem-codes', [$g, 'redeemCreate']],
    ['DELETE', '/api/admin/redeem-codes/{id}', [$g, 'redeemDelete']],
    ['GET', '/api/admin/tasks', [$g, 'tasks']],
    ['PUT', '/api/admin/tasks/{id}', [$g, 'taskUpdate']],
    ['POST', '/api/admin/tasks/{id}/run', [$g, 'taskRun']],
    ['GET', '/api/admin/tasks/{id}/runs', [$g, 'taskRuns']],
    ['GET', '/api/user/keys', [$u, 'myKeys']],
    ['POST', '/api/user/keys', [$u, 'myKeyCreate']],
    ['PUT', '/api/user/keys/{id}', [$u, 'myKeyUpdate']],
    ['DELETE', '/api/user/keys/{id}', [$u, 'myKeyDelete']],
    ['GET', '/api/user/usage', [$u, 'myUsage']],
    ['POST', '/api/user/redeem', [$u, 'redeem']],
] as $r) {
    [$method, $pattern, $handler] = $r;
    $router->add($method, $pattern, function (array $p = []) use ($handler) {
        [$class, $method] = $handler;
        $class::$method($p);
    });
}

try {
    $method = Http::method();
    if ($method === 'PUT' || $method === 'DELETE' || $method === 'POST' || $method === 'PATCH') {
        // 管理接口统一 CSRF；网关 /v1/* 在控制器内走 Bearer
        if (!str_starts_with($path, '/v1/')) {
            App\Core\Auth::checkCsrf();
        }
    }
    $router->dispatch($method, $path);
} catch (Throwable $e) {
    error_log('[sub2api] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (str_starts_with($path, '/v1/')) {
        App\Core\Http::apiError('服务器内部错误', 'api_error', 500);
    }
    App\Core\Http::error('服务器内部错误', 500);
}

// ---------- 非 API 路径：输出 SPA ----------
if (ob_get_level() > 0) { ob_end_flush(); }
