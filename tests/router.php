<?php
// 测试路由器：让 PHP 内置服务器把所有请求交给 index.php
// php -S 127.0.0.1:8765 tests/router.php
if (PHP_SAPI !== 'cli-server') { exit('CLI server only'); }
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
// 静态资源直接返回
if (preg_match('#^/assets/#', $path) && is_file(__DIR__ . '/../public' . $path)) {
    return false;
}
if ($path === '/cron.php') { require __DIR__ . '/../cron.php'; return true; }
require __DIR__ . '/../index.php';
