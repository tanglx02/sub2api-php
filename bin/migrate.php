<?php
/**
 * Sub2API-PHP 命令行迁移工具
 * 用法: php bin/migrate.php [路径至 data/config.php 所在根目录的绝对/相对路径]
 * 从项目根目录执行: php bin/migrate.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
if ($argc > 1) {
    $root = rtrim($argv[1], '/');
}
define('S2A_ROOT', $root);

// 自动加载
spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        require S2A_ROOT . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    }
});

$configFile = getenv('S2A_CONFIG') ?: (S2A_ROOT . '/data/config.php');
if (!is_file($configFile)) {
    fwrite(STDERR, "错误: 未找到配置文件 {$configFile}\n请先通过 Web 安装向导完成安装。\n");
    exit(1);
}
require $configFile;

try {
    $driver = (string)(\App\Core\Config::get('database.driver') ?? 'sqlite');
    $applied = \App\Core\Migrator::migrate(\App\Core\Database::pdo(), $driver, S2A_ROOT);
    echo "迁移完成，本次应用 " . count($applied) . " 个迁移。\n";
    foreach ($applied as $m) {
        echo "  - {$m}\n";
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '迁移失败: ' . $e->getMessage() . "\n");
    exit(1);
}
