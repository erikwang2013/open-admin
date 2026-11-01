<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace app\process;

use app\admin\controller\ImportController;
use app\common\ImportJobService;
use support\Log;
use support\Redis;
use Workerman\Timer;
use Workerman\Worker;

/**
 * 用户导入 worker：单进程串行消费 Redis 队列，避免阻塞 HTTP worker
 *
 * 单 worker 假设：config/process.php 中 count=1，因此 recover() 只会在本进程启动时
 * 面对自己上一次运行留下的 processing 条目；processing_at 宽限期用于滚动重启/多实例
 * 场景下防止另一个仍在执行的进程被重复入队。
 */
class ImportWorker
{
    /** 队列轮询间隔（秒） */
    private const TICK_INTERVAL = 1;

    /** processing_at 距现在不足该秒数时可能仍在执行，恢复时先跳过并稍后重试 */
    private const RECOVER_GRACE = 60;

    public function onWorkerStart(Worker $worker): void
    {
        $this->recover();
        $this->sweepStaleFiles();
        Timer::add(self::TICK_INTERVAL, [$this, 'tick']);
    }

    /**
     * 进程重启时回收上次未处理完的任务：
     * - completed/failed：只清理 processing 条目和残留文件，不再入队
     * - processing/queued 且仍持有锁（或锁已过期可重新抢占）：放回队列
     * - 锁已被其他任务接管：标记失败并清理，避免与当前持锁任务并发写库
     * - processing_at 很新：保留在 processing，宽限期后再检查
     */
    public function recover(): void
    {
        try {
            $jobIds = Redis::lrange(ImportJobService::PROCESSING_KEY, 0, -1);
        } catch (\Throwable $e) {
            Log::error('import worker recovery failed: ' . $e->getMessage());
            return;
        }

        $retryIn = 0;
        foreach ($jobIds as $jobId) {
            $jobId = (string) $jobId;
            try {
                $job = ImportJobService::load($jobId);
                if ($job === null) {
                    // 元数据已过期，无文件信息可清理；只移除 processing 条目
                    Log::warning("import job {$jobId} metadata missing during recovery");
                    $this->detach($jobId);
                    continue;
                }

                $status = (string) ($job['status'] ?? '');
                if (in_array($status, ['completed', 'failed'], true)) {
                    $this->removeFile($job);
                    $this->detach($jobId);
                    continue;
                }

                $owner = (string) ($job['lock'] ?? '');
                $lock  = Redis::get(ImportJobService::LOCK_KEY);
                if ($owner === '') {
                    $this->failJob($jobId, $job, '导入任务缺少锁信息，已取消');
                    continue;
                }
                if ($lock !== false && $lock !== null && $lock !== $owner) {
                    $this->failJob($jobId, $job, '导入锁已被其他任务接管，任务已取消');
                    continue;
                }

                $age = time() - (int) ($job['processing_at'] ?? 0);
                if ($status === 'processing' && $age >= 0 && $age < self::RECOVER_GRACE) {
                    $retryIn = max($retryIn, self::RECOVER_GRACE - $age);
                    continue;
                }

                // 锁已过期且无人持有：用原 owner 值重新抢占，任务仍受锁保护
                if (($lock === false || $lock === null) && !ImportJobService::acquireLock($owner)) {
                    $retryIn = max($retryIn, self::RECOVER_GRACE);
                    continue;
                }

                // 先入队成功再移除 processing，避免 Redis 中断时任务从两处都消失
                if (Redis::rpush(ImportJobService::QUEUE_KEY, $jobId) !== false) {
                    $this->detach($jobId);
                } else {
                    Log::error("import job {$jobId} requeue failed");
                }
            } catch (\Throwable $e) {
                Log::error("import job {$jobId} recovery failed: " . $e->getMessage());
            }
        }

        if ($retryIn > 0) {
            try {
                Timer::add($retryIn, [$this, 'recover'], [], false);
            } catch (\Throwable) {
                // 非 workerman 运行环境（如单元测试）无法注册 Timer，下次启动时再恢复
            }
        }
    }

    /**
     * 任务元数据 1 天后过期，超过该时间的残留文件不可能再被消费
     */
    public function sweepStaleFiles(): void
    {
        $cutoff = time() - ImportJobService::JOB_TTL;
        foreach (glob(runtime_path('imports') . '/*') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }

    /**
     * 每个 tick 最多处理一个任务
     */
    public function tick(): void
    {
        try {
            $jobId = ImportJobService::handoff(ImportJobService::QUEUE_KEY, ImportJobService::PROCESSING_KEY);
        } catch (\Throwable $e) {
            Log::error('import queue poll failed: ' . $e->getMessage());
            return;
        }

        if ($jobId !== null) {
            $this->process($jobId);
        }
    }

    private function process(string $jobId): void
    {
        $job      = null;
        $file     = null;
        $requeued = false;

        try {
            try {
                $job = ImportJobService::load($jobId);
            } catch (\Throwable $e) {
                // Redis 瞬时故障：放回队列下个 tick 重试，避免任务永久卡在 processing
                Log::error("import job {$jobId} load failed: " . $e->getMessage());
                $this->requeue($jobId);
                $requeued = true;
                return;
            }
            if ($job === null) {
                Log::warning("import job {$jobId} metadata missing");
                return;
            }
            // 恢复队列时可能重复入队，已完成/失败的任务不再执行，避免重复跑批
            if (in_array($job['status'] ?? '', ['completed', 'failed'], true)) {
                return;
            }

            $file = $this->jobFile($job);

            // 锁归属校验：锁过期时用同一 owner 值重新抢占一次；已被其他任务接管则拒绝执行
            try {
                $ownsLock = $this->ensureLock((string) ($job['lock'] ?? ''));
            } catch (\Throwable $e) {
                // Redis 瞬时故障：无法判断锁归属，放回队列稍后重试
                Log::error("import job {$jobId} lock check failed: " . $e->getMessage());
                $this->requeue($jobId);
                $requeued = true;
                return;
            }
            if (!$ownsLock) {
                $this->failJob($jobId, $job, '导入锁已被其他任务接管，任务已取消');
                return;
            }

            $job['status']        = 'processing';
            $job['processing_at'] = time();
            ImportJobService::save($job);

            if ($file === null || !is_file($file)) {
                throw new \RuntimeException('import file missing');
            }

            $response = (new ImportController())->processImport($file, (string) ($job['ext'] ?? ''), $job['role_ids'] ?? null);
            $body     = json_decode($response->rawBody(), true);
            if (!is_array($body)) {
                throw new \RuntimeException('invalid import response');
            }

            $job['status'] = ((int) ($body['code'] ?? -1) === 0) ? 'completed' : 'failed';
            $job['result'] = $body['data'] ?? null;
            if ($job['status'] === 'failed') {
                $job['error'] = (string) ($body['message'] ?? '导入失败');
            }
            ImportJobService::save($job);
        } catch (\Throwable $e) {
            Log::error("import job {$jobId} failed: " . $e->getMessage());
            if ($job !== null) {
                $job['status'] = 'failed';
                $job['error']  = '导入执行失败，请稍后重试';
                try {
                    ImportJobService::save($job);
                } catch (\Throwable) {
                    // 状态写不进去时仍继续清理文件和锁
                }
            }
        } finally {
            // 已放回队列的任务保留文件、锁和 processing 条目，交给下一次 tick 或重启恢复
            if (!$requeued) {
                if ($file !== null) {
                    @unlink($file);
                }
                ImportJobService::releaseLock($job['lock'] ?? null);
                $this->detach($jobId);
            }
        }
    }

    /**
     * 确认任务仍持有导入锁；锁已过期则用同一 owner 值重新抢占并续上 TTL
     */
    private function ensureLock(string $owner): bool
    {
        if ($owner === '') {
            return false;
        }

        $current = Redis::get(ImportJobService::LOCK_KEY);
        if ($current === $owner) {
            return true;
        }
        if ($current === false || $current === null) {
            return ImportJobService::acquireLock($owner);
        }

        return false;
    }

    /**
     * 标记任务失败并清理文件/processing 条目（锁由 finally 或锁归属决定）
     */
    private function failJob(string $jobId, array $job, string $error): void
    {
        $job['status'] = 'failed';
        $job['error']  = $error;
        try {
            ImportJobService::save($job);
        } catch (\Throwable $e) {
            Log::error("import job {$jobId} failure state save failed: " . $e->getMessage());
        }
        $this->removeFile($job);
        $this->detach($jobId);
    }

    private function requeue(string $jobId): void
    {
        try {
            // 先入队成功再移除 processing，避免 Redis 中断时任务从两处都消失
            if (Redis::rpush(ImportJobService::QUEUE_KEY, $jobId) !== false) {
                $this->detach($jobId);
            }
        } catch (\Throwable) {
            // 入队失败时条目仍在 processing，进程启动时由 recover 回收
        }
    }

    private function detach(string $jobId): void
    {
        try {
            // phpredis 的参数顺序是 key, value, count
            Redis::lrem(ImportJobService::PROCESSING_KEY, $jobId, 0);
        } catch (\Throwable) {
            // 进程重启后由 recover 回填队列；此处失败不影响任务结果
        }
    }

    private function removeFile(array $job): void
    {
        $file = $this->jobFile($job);
        if ($file !== null) {
            @unlink($file);
        }
    }

    private function jobFile(array $job): ?string
    {
        $name = basename((string) ($job['file'] ?? ''));

        return $name === '' ? null : runtime_path('imports') . DIRECTORY_SEPARATOR . $name;
    }
}
