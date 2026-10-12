<?php
declare(strict_types=1);
namespace App\Core;

/** 管理员操作审计日志与脱敏日志 */
final class Audit
{
    public static function log(int $actorId, string $action, string $targetType = '', ?int $targetId = null, array $detail = []): void
    {
        try {
            Database::run(
                'INSERT INTO audit_logs (user_id, action, target_type, target_id, detail, ip, created_at) VALUES (?,?,?,?,?,?,?)',
                [$actorId, $action, $targetType, $targetId, json_encode(self::sanitize($detail), JSON_UNESCAPED_UNICODE), Http::clientIp(), gmdate('Y-m-d H:i:s')]
            );
        } catch (\Throwable) { /* 审计失败不阻断业务 */ }
    }

    /** 移除敏感字段，密钥脱敏 */
    public static function sanitize(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_array($v)) { $data[$k] = self::sanitize($v); continue; }
            if (preg_match('/key|secret|password|token|credential/i', (string)$k)) {
                $data[$k] = is_string($v) && $v !== '' ? Crypto::maskKey($v) : '***';
            }
        }
        return $data;
    }
}
