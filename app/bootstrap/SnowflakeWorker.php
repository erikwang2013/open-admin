<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace app\bootstrap;

use app\common\SnowflakeService;
use Webman\Bootstrap;
use Workerman\Worker;

/**
 * 按 worker 进程编号偏移 Snowflake 工作节点 ID
 * 多进程共用同一 worker_id 会生成重复主键，启动时按 $worker->id 隔离
 */
class SnowflakeWorker implements Bootstrap
{
    public static function start(?Worker $worker): void
    {
        SnowflakeService::setWorkerOffset((int)($worker->id ?? 0));
    }
}
