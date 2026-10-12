<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\Database as DB;
use App\Gateway\UpstreamClient;

/** 上游渠道选择：按分组过滤 → 启用状态 → 优先级轮询 */
final class Channels
{
    /** 返回模型可用渠道列表（按优先级排序，逐个尝试做故障切换） */
    public static function pick(string $model, ?int $groupId, int $limit = 5): array
    {
        $rows = DB::all("SELECT * FROM channels WHERE status = 'active' ORDER BY priority ASC, last_used_at IS NULL DESC, last_used_at ASC");
        $out = [];
        foreach ($rows as $ch) {
            $models = self::channelModels($ch);
            if (!in_array($model, $models, true)) { continue; }
            if (!self::servesGroup((int)$ch['id'], $groupId)) { continue; }
            $out[] = $ch;
            if (count($out) >= $limit) { break; }
        }
        return $out;
    }

    /** 渠道可服务的本地模型列表（models JSON：列表或映射的键） */
    public static function channelModels(array $ch): array
    {
        $m = json_decode((string)($ch['models'] ?? '[]'), true);
        if (!is_array($m)) { return []; }
        $keys = [];
        foreach ($m as $k => $v) { $keys[] = is_string($k) ? $k : $v; }
        return $keys;
    }

    /** 本地模型 → 上游模型名 */
    public static function upstreamModel(array $ch, string $model): string
    {
        $m = json_decode((string)($ch['model_mapping'] ?? '{}'), true);
        if (is_array($m) && isset($m[$model]) && is_string($m[$model]) && $m[$model] !== '') { return $m[$model]; }
        return $model;
    }

    /** 渠道是否服务某分组：未绑定任何分组的渠道服务全部分组 */
    public static function servesGroup(int $channelId, ?int $groupId): bool
    {
        $n = (int)DB::value('SELECT COUNT(*) FROM channel_groups WHERE channel_id = ?', [$channelId]);
        if ($n === 0) { return true; }
        if ($groupId === null) { return false; }
        return (bool)DB::value('SELECT COUNT(*) FROM channel_groups WHERE channel_id = ? AND group_id = ?', [$channelId, $groupId]);
    }

    public static function decryptedKey(array $ch): string
    {
        return \App\Core\Crypto::decrypt($ch['api_key_enc']);
    }

    public static function recordError(int $channelId, string $error): void
    {
        try {
            DB::run('UPDATE channels SET last_error = ?, last_used_at = ? WHERE id = ?',
                [mb_substr($error, 0, 500), gmdate('Y-m-d H:i:s'), $channelId]);
        } catch (\Throwable) {}
    }

    public static function recordSuccess(int $channelId): void
    {
        try {
            DB::run('UPDATE channels SET last_error = NULL, last_used_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s'), $channelId]);
        } catch (\Throwable) {}
    }
}
