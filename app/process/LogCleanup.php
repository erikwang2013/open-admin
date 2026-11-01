<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace app\process;

use app\model\OperationLog;
use support\Log;
use Workerman\Timer;
use Workerman\Worker;

/**
 * 操作日志清理进程：保留 180 天，每轮最多分 10 批删除 100000 行
 */
class LogCleanup
{
    /** 日志保留天数 */
    private const RETENTION_DAYS = 180;

    /** 单次删除上限，避免长事务锁表 */
    private const BATCH_SIZE = 10000;

    /** 每轮最多批次数，避免积压时一次删除过久 */
    private const MAX_BATCHES = 10;

    public function onWorkerStart(Worker $worker): void
    {
        // 启动时先清一次，部署后无需等到次日定时器
        $this->cleanup();
        Timer::add(86400, [$this, 'cleanup']);
    }

    public function cleanup(): void
    {
        try {
            $model  = new OperationLog();
            $table  = $model->getConnection()->getTablePrefix() . $model->getTable();
            $cutoff = date('Y-m-d H:i:s', time() - self::RETENTION_DAYS * 86400);

            $total = 0;
            for ($batch = 0; $batch < self::MAX_BATCHES; $batch++) {
                // 原生 DELETE 才能带 LIMIT，查询构造器的 delete 会忽略 limit
                $deleted = $model->getConnection()->delete(
                    "DELETE FROM {$table} WHERE created_at < ? LIMIT " . self::BATCH_SIZE,
                    [$cutoff]
                );
                $total += $deleted;
                // 不足一批说明已清完，提前结束
                if ($deleted < self::BATCH_SIZE) {
                    break;
                }
            }

            if ($total > 0) {
                Log::info("operation_log cleanup: deleted {$total} rows older than " . self::RETENTION_DAYS . ' days');
            }
        } catch (\Throwable $e) {
            // 清理失败不影响进程，下一轮定时器继续尝试
            Log::warning('operation_log cleanup failed: ' . $e->getMessage());
        }
    }
}
