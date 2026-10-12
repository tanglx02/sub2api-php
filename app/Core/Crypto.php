<?php
declare(strict_types=1);
namespace App\Core;

/** 加解密与密钥工具 */
final class Crypto
{
    private static function key(): string
    {
        $k = (string)Config::get('app_key', '');
        if ($k === '') { throw new \RuntimeException('缺少应用密钥'); }
        return hash('sha256', $k, true);
    }

    /** AES-256-GCM 加密（用于上游密钥） */
    public static function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) { throw new \RuntimeException('加密失败'); }
        return 'enc1:' . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $enc): string
    {
        if (!str_starts_with($enc, 'enc1:')) { return $enc; }
        $raw = base64_decode(substr($enc, 5), true);
        if ($raw === false || strlen($raw) < 29) { throw new \RuntimeException('解密失败'); }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) { throw new \RuntimeException('解密失败'); }
        return $plain;
    }

    /** 生成用户 API Key：sk- 前缀，仅创建时可见 */
    public static function generateApiKey(): string
    {
        return 'sk-s2a-' . bin2hex(random_bytes(24));
    }

    public static function apiKeyHash(string $key): string
    {
        return hash('sha256', $key);
    }

    public static function apiKeyPrefix(string $key): string
    {
        return substr($key, 0, 12);
    }

    public static function maskKey(string $key): string
    {
        if (strlen($key) <= 12) { return substr($key, 0, 4) . '****'; }
        return substr($key, 0, 10) . '****' . substr($key, -4);
    }
}
