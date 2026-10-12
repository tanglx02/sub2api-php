<?php
declare(strict_types=1);
namespace App\Core;

/** 应用配置：安装后写入 data/config.php */
final class Config
{
    private static ?array $data = null;

    public static function load(): bool
    {
        if (self::$data !== null) { return true; }
        $file = self::path();
        if (!is_file($file)) { return false; }
        self::$data = require $file;
        return is_array(self::$data);
    }

    public static function path(): string
    {
        // 测试等场景可通过环境变量覆盖配置路径
        $env = getenv('S2A_CONFIG');
        return $env !== false && $env !== '' ? $env : (dirname(__DIR__, 2) . '/data/config.php');
    }

    public static function installed(): bool
    {
        return self::load() && !empty(self::$data['installed']);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::load();
        return self::$data[$key] ?? $default;
    }

    public static function all(): array
    {
        return self::$data ?? [];
    }
}
