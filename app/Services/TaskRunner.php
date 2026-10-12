<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\Database as DB;
use App\Services\Channels;

/** 定时任务执行器（cron.php 或管理后台手动触发） */
final class TaskRunner
{
    /** @var array<string, callable(): array{ok:bool,message:string}> */
    public static function handlers(): array
    {
        return [
            'channel_health_check' => function (): array {                $chs = DB::all("SELECT * FROM channels WHERE status = 'active'");
                $okCount = 0;
                foreach ($chs as $ch) {
                    $res = \App\Gateway\UpstreamClient::testChannel($ch['base_url'], Channels::decryptedKey($ch), $ch['type'], (int)$ch['connect_timeout']);
                    DB::run('UPDATE channels SET last_test_at = ?, last_test_ok = ?, last_error = ? WHERE id = ?',
                        [gmdate('Y-m-d H:i:s'), $res['ok'] ? 1 : 0, $res['ok'] ? null : $res['error'], $ch['id']]);
                    if ($res['ok']) { $okCount++; }
                }
                return ['ok' => true, 'message' => "检测完成：{$okCount}/" . count($chs) . " 个渠道正常"];
            },
            'usage_stats_maintain' => function (): array {
                $cut = gmdate('Y-m-d H:i:s', time() - 3600);
                $n = (int)DB::value("SELECT COUNT(*) FROM usage_logs WHERE billing_status = 'pending' AND created_at < ?", [$cut]);
                if ($n > 0) {
                    DB::run("UPDATE usage_logs SET billing_status = 'failed', status = 'failed', error_message = '请求未完成（孤儿记录清理）' WHERE billing_status = 'pending' AND created_at < ?", [$cut]);
                }
                return ['ok' => true, 'message' => "清理孤儿账务记录 {$n} 条"];
            },
            'log_cleanup' => function (): array {
                $days = max(7, (int)(DB::value("SELECT svalue FROM settings WHERE skey = 'log_retention_days'") ?? 90));
                $cut = gmdate('Y-m-d H:i:s', time() - $days * 86400);
                $a = DB::run('DELETE FROM usage_logs WHERE created_at < ?', [$cut])->rowCount();
                $b = DB::run('DELETE FROM login_attempts WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - 30 * 86400)])->rowCount();
                $c = DB::run('DELETE FROM rate_counters WHERE window_key < ?', [gmdate('YmdHi', time() - 86400)])->rowCount();
                return ['ok' => true, 'message' => "清理 usage_logs {$a} 条、登录尝试 {$b} 条、限流计数 {$c} 条"];
            },
            'channel_model_sync' => function (): array {
                return ModelSync::runDue();
            },
        ];
    }

    /** cron 入口：执行所有到期任务 */
    public static function runDue(): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $tasks = DB::all("SELECT * FROM tasks WHERE enabled = 1 AND (next_run_at IS NULL OR next_run_at <= ?)", [$now]);
        $results = [];
        foreach ($tasks as $t) {
            $results[] = ['task' => $t['name'], 'result' => self::execute($t)];
        }
        return $results;
    }

    public static function runById(int $id, int $operatorId): array
    {
        $t = DB::one('SELECT * FROM tasks WHERE id = ?', [$id]);
        if (!$t) { return ['ok' => false, 'message' => '任务不存在']; }
        $r = self::execute($t);
        AuditSafe($operatorId, 'task_run_' . $t['name'], $r);
        return $r;
    }

    private static function execute(array $t): array
    {
        $handlers = self::handlers();
        $started = gmdate('Y-m-d H:i:s');
        DB::run('INSERT INTO task_runs (task_id, status, started_at) VALUES (?,?,?)', [(int)$t['id'], 'running', $started]);
        $runId = DB::lastId();
        try {
            $h = $handlers[$t['handler']] ?? null;
            if ($h === null) { throw new \RuntimeException('未知任务类型: ' . $t['handler']); }
            $r = $h();
            DB::run('UPDATE task_runs SET status = ?, message = ?, finished_at = ? WHERE id = ?',
                [$r['ok'] ? 'success' : 'failed', $r['message'], gmdate('Y-m-d H:i:s'), $runId]);
            DB::run('UPDATE tasks SET last_run_at = ?, next_run_at = ?, last_status = ?, last_error = NULL, run_count = run_count + 1 WHERE id = ?',
                [$started, gmdate('Y-m-d H:i:s', time() + (int)$t['interval_minutes'] * 60), $r['ok'] ? 'success' : 'failed', $t['id']]);
            return $r;
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            DB::run('UPDATE task_runs SET status = ?, message = ?, finished_at = ? WHERE id = ?', ['failed', $msg, gmdate('Y-m-d H:i:s'), $runId]);
            DB::run('UPDATE tasks SET last_run_at = ?, next_run_at = ?, last_status = ?, last_error = ?, fail_count = fail_count + 1 WHERE id = ?',
                [$started, gmdate('Y-m-d H:i:s', time() + (int)$t['interval_minutes'] * 60), 'failed', $msg, $t['id']]);
            return ['ok' => false, 'message' => $msg];
        }
    }
}

function AuditSafe(int $uid, string $action, array $detail): void
{
    try { \App\Core\Audit::log($uid, $action, detail: $detail); } catch (\Throwable) {}
}
