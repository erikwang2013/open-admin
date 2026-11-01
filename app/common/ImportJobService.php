<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace app\common;

use support\Redis;

/**
 * 导入任务队列 — 控制器入队与 worker 消费共用的 Redis 键和原子操作
 */
class ImportJobService
{
    /** 导入锁：同一时间只允许一个导入任务，避免并发重复写库 */
    public const LOCK_KEY = 'import_users_lock';

    /** 导入锁超时（秒），需覆盖 2000 行 bcrypt 的最坏耗时；进程异常退出时由 TTL 兜底释放 */
    public const LOCK_TTL = 1800;

    /** 待处理任务队列 */
    public const QUEUE_KEY = 'import_queue';

    /** 正在处理的任务（worker 重启时回填队列） */
    public const PROCESSING_KEY = 'import_processing';

    /** 任务状态保留时长（秒） */
    public const JOB_TTL = 86400;

    private const JOB_PREFIX = 'import_job:';

    public static function key(string $jobId): string
    {
        return self::JOB_PREFIX . $jobId;
    }

    /** @throws \Throwable Redis 不可用时由调用方决定失败关闭 */
    public static function acquireLock(string $owner): bool
    {
        return (bool) Redis::set(self::LOCK_KEY, $owner, ['nx', 'ex' => self::LOCK_TTL]);
    }

    /** 只删除自己持有的锁，避免锁过期后误删新持有者的锁 */
    public static function releaseLock(?string $owner): void
    {
        if ($owner === null || $owner === '') {
            return;
        }
        try {
            Redis::eval(
                "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end",
                [self::LOCK_KEY, $owner],
                1
            );
        } catch (\Throwable) {
            // 释放失败由 TTL 兜底
        }
    }

    public static function save(array $job): void
    {
        $ok = Redis::setex(
            self::key((string) $job['job_id']),
            self::JOB_TTL,
            json_encode($job, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );
        if (!$ok) {
            throw new \RuntimeException('import job save failed');
        }
    }

    public static function load(string $jobId): ?array
    {
        $raw = Redis::get(self::key($jobId));
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $job = json_decode($raw, true);

        return is_array($job) ? $job : null;
    }

    /**
     * 原子地把任务从一个列表尾部移到另一个列表头部（RPOPLPUSH），
     * support\Redis 只给单键命令加前缀，故用 Lua 保证两个键都被正确加前缀
     *
     * @return string|null 任务 ID，列表为空时返回 null
     */
    public static function handoff(string $from, string $to): ?string
    {
        $jobId = Redis::eval(
            "return redis.call('RPOPLPUSH', KEYS[1], KEYS[2])",
            [$from, $to],
            2
        );

        return is_string($jobId) && $jobId !== '' ? $jobId : null;
    }
}
