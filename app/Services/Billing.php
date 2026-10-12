<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\Database as DB;

/**
 * 计费核心（P0）
 * - usage_logs.request_id 唯一，防重复记账
 * - billing_status: pending -> completed | failed | insufficient
 * - 扣费在事务内以条件更新防负余额
 */
final class Billing
{
    /** 兼容 OpenAI/DeepSeek/自定义 usage 字段名 */
    public static function normalizeUsage(array $u): array
    {
        return [
            'input_tokens' => (int)($u['input_tokens'] ?? $u['prompt_tokens'] ?? 0),
            'output_tokens' => (int)($u['output_tokens'] ?? $u['completion_tokens'] ?? 0),
        ];
    }

    /** 渠道重试：复用同一请求记录，重置账务状态 */
    public static function reattach(string $requestId, int $channelId, string $upstreamModel): bool
    {
        $stmt = DB::run(
            "UPDATE usage_logs SET channel_id = ?, upstream_model = ?, status = 'pending', billing_status = 'pending', error_message = NULL
             WHERE request_id = ? AND billing_status IN ('pending','failed')",
            [$channelId, $upstreamModel, $requestId]
        );
        return $stmt->rowCount() > 0;
    }

    /** 请求开始：登记请求记录（若 request_id 已存在则返回 false，防重放） */
    public static function startRequest(string $requestId, int $userId, int $apiKeyId, ?int $channelId, string $model, string $upstreamModel, bool $stream, float $multiplier): bool
    {
        try {
            DB::run(
                'INSERT INTO usage_logs (request_id, user_id, api_key_id, channel_id, model, upstream_model, stream, group_multiplier, status, billing_status, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                [$requestId, $userId, $apiKeyId, $channelId, $model, $upstreamModel, $stream ? 1 : 0, $multiplier, 'pending', 'pending', gmdate('Y-m-d H:i:s')]
            );
            return true;
        } catch (\PDOException $e) {
            if (self::isDuplicate($e)) { return false; }
            throw $e;
        }
    }

    /** 计算费用（USD）。价格为 每 1M token 的 USD */
    public static function computeCost(string $model, int $inTok, int $outTok, float $multiplier): array
    {
        $p = DB::one('SELECT input_price, output_price FROM model_prices WHERE model = ?', [$model]);
        $inPrice = $p !== null ? (float)$p['input_price'] : 0.0;
        $outPrice = $p !== null ? (float)$p['output_price'] : 0.0;
        $inCost = $inTok / 1_000_000 * $inPrice;
        $outCost = $outTok / 1_000_000 * $outPrice;
        return ['input_cost' => $inCost, 'output_cost' => $outCost, 'total_cost' => $inCost + $outCost, 'actual_cost' => ($inCost + $outCost) * $multiplier];
    }

    /**
     * 请求完成并结算。幂等：仅当 billing_status='pending' 时执行一次。
     * $usage 为 null 表示无真实 usage（由调用方传入估算值并标记 usage_source）。
     */
    public static function settle(string $requestId, ?array $usage, string $usageSource, ?int $durationMs, ?string $error = null): array
    {
        $log = DB::one('SELECT * FROM usage_logs WHERE request_id = ?', [$requestId]);
        if ($log === null) { return ['ok' => false, 'reason' => 'request_not_found']; }
        if ($log['billing_status'] !== 'pending') { return ['ok' => true, 'reason' => 'already_settled', 'billing_status' => $log['billing_status']]; }

        $userId = (int)$log['user_id'];
        $usage = self::normalizeUsage(is_array($usage) ? $usage : []);
        $inTok = (int)$usage['input_tokens'];
        $outTok = (int)$usage['output_tokens'];
        $cost = self::computeCost($log['model'], $inTok, $outTok, (float)$log['group_multiplier']);
        $actual = $cost['actual_cost'];

        DB::begin();
        try {
            // 1) 标记本请求已结算（幂等点）
            $stmt = DB::run(
                'UPDATE usage_logs SET input_tokens=?, output_tokens=?, total_tokens=?, input_cost=?, output_cost=?, total_cost=?, actual_cost=?, usage_source=?, duration_ms=?, status=?, billing_status=?
                 WHERE request_id=? AND billing_status=?',
                [$inTok, $outTok, $inTok + $outTok, $cost['input_cost'], $cost['output_cost'], $cost['total_cost'], $actual, $usageSource, $durationMs, $error === null ? 'completed' : 'failed', $error === null ? 'completed' : 'failed', $requestId, 'pending']
            );
            if ($stmt->rowCount() === 0) {
                DB::rollback();
                return ['ok' => true, 'reason' => 'already_settled'];
            }

            // 2) 失败请求不扣费
            if ($error !== null) {
                DB::run('UPDATE usage_logs SET error_message = ? WHERE request_id = ?', [mb_substr($error, 0, 1000), $requestId]);
                DB::commit();
                return ['ok' => true, 'billing_status' => 'failed', 'actual_cost' => 0.0];
            }

            // 3) 条件扣费，防负余额；条件失败则记 insufficient
            $cond = self::$hasQuota
                ? 'UPDATE users SET balance = balance - ?, total_spent = total_spent + ?, request_count = request_count + 1 WHERE id = ? AND balance >= ?'
                : 'UPDATE users SET balance = balance - ?, total_spent = total_spent + ?, request_count = request_count + 1 WHERE id = ?';
            $args = self::$hasQuota ? [$actual, $actual, $userId, $actual] : [$actual, $actual, $userId];
            $stmt = DB::run($cond, $args);
            if ($stmt->rowCount() === 0) {
                DB::run("UPDATE usage_logs SET billing_status='insufficient' WHERE request_id=?", [$requestId]);
                DB::commit();
                return ['ok' => false, 'billing_status' => 'insufficient', 'actual_cost' => $actual];
            }

            $bal = (float)DB::value('SELECT balance FROM users WHERE id = ?', [$userId]);
            DB::run(
                'INSERT INTO balance_transactions (user_id, type, amount, balance_after, ref_request_id, note, created_at) VALUES (?,?,?,?,?,?,?)',
                [$userId, 'consume', -$actual, $bal, $requestId, $log['model'], gmdate('Y-m-d H:i:s')]
            );
            DB::run('UPDATE api_keys SET quota_used = quota_used + ?, request_count = request_count + 1, last_used_at = ? WHERE id = ?',
                [$actual, gmdate('Y-m-d H:i:s'), (int)$log['api_key_id']]);
            DB::commit();
            return ['ok' => true, 'billing_status' => 'completed', 'actual_cost' => $actual, 'balance_after' => $bal];
        } catch (\Throwable $e) {
            DB::rollback();
            throw $e;
        }
    }

    /** 记录请求失败（无 usage） */
    public static function fail(string $requestId, string $error, ?int $durationMs = null): array
    {
        return self::settle($requestId, ['input_tokens' => 0, 'output_tokens' => 0], 'none', $durationMs, $error);
    }

    private static bool $hasQuota = true;
    public static function enforceNonNegative(bool $v): void { self::$hasQuota = $v; }

    private static function isDuplicate(\PDOException $e): bool
    {
        $msg = $e->getMessage();
        return str_contains($msg, 'UNIQUE') || str_contains($msg, 'Duplicate entry') || ($e->errorInfo[1] ?? 0) == 1062;
    }

    /** 请求前余额检查 */
    public static function hasBalance(int $userId, float $minReserve = 0.0): bool
    {
        $bal = (float)DB::value('SELECT balance FROM users WHERE id = ?', [$userId]);
        return $bal > $minReserve;
    }
}
