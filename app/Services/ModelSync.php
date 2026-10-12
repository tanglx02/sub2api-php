<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\Database as DB;
use App\Gateway\UpstreamClient;

/**
 * 渠道模型同步：从上游 /v1/models 拉取模型并与现有列表"合并"（只增不删，避免旧模型被清理导致调用冲突）
 */
final class ModelSync
{
    /** 同步单个渠道。返回 ['ok'=>bool,'added'=>int,'removed'=>0,'total'=>int,'error'=>?string] */
    public static function syncChannel(int $channelId): array
    {
        $ch = DB::one('SELECT * FROM channels WHERE id = ?', [$channelId]);
        if (!$ch) { return ['ok' => false, 'error' => '渠道不存在']; }
        $res = UpstreamClient::fetchModels($ch['base_url'], Channels::decryptedKey($ch), $ch['type'], (int)$ch['connect_timeout']);
        $existing = json_decode((string)($ch['models'] ?? '[]'), true) ?: [];
        $now = gmdate('Y-m-d H:i:s');
        if (!$res['ok']) {
            // 失败也记录同步时间，避免失败渠道被高频重试；旧模型保持不变
            DB::run('UPDATE channels SET last_model_sync_at = ?, last_error = ? WHERE id = ?', [$now, $res['error'], $channelId]);
            return ['ok' => false, 'added' => 0, 'removed' => 0, 'total' => count($existing), 'error' => $res['error']];
        }
        // 只增不删：旧模型全部保留，新增上游返回的模型
        $merged = array_values(array_unique(array_merge($existing, $res['models'])));
        sort($merged);
        $added = count(array_diff($merged, $existing));
        DB::run('UPDATE channels SET models = ?, last_model_sync_at = ?, last_error = NULL WHERE id = ?',
            [json_encode($merged, JSON_UNESCAPED_SLASHES), $now, $channelId]);
        return ['ok' => true, 'added' => $added, 'removed' => 0, 'total' => count($merged), 'error' => null];
    }

    /** 定时任务入口：同步所有开启自动同步且到期的渠道 */
    public static function runDue(): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $rows = DB::all("SELECT id, name, model_sync_interval, last_model_sync_at FROM channels
                         WHERE model_sync_interval > 0 AND status = 'active'
                         AND (last_model_sync_at IS NULL OR last_model_sync_at = ''
                              OR last_model_sync_at <= ?)", [gmdate('Y-m-d H:i:s', time() - 60)]); // 粗筛，精确判断在循环内
        $results = [];
        foreach ($rows as $ch) {
            $interval = max(1, (int)$ch['model_sync_interval']);
            $last = $ch['last_model_sync_at'] ?? null;
            if ($last && strtotime((string)$last . ' UTC') > time() - $interval * 60) {
                continue; // 未到期
            }
            $r = self::syncChannel((int)$ch['id']);
            $results[] = ['channel' => $ch['name'], 'ok' => $r['ok'], 'added' => $r['added'] ?? 0, 'error' => $r['error'] ?? null];
        }
        $added = array_sum(array_column($results, 'added'));
        $failed = count(array_filter($results, fn($r) => !$r['ok']));
        return ['ok' => $failed === 0, 'message' => sprintf('同步 %d 个渠道，新增模型 %d 个，失败 %d 个', count($results), $added, $failed), 'detail' => $results];
    }
}
