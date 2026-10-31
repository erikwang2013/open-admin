<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

return [
    support\bootstrap\Session::class,
    \Erikwang2013\Poster\Adapters\Webman\CaptchaPlugin::class,
    // 按 worker 进程隔离 Snowflake 工作节点 ID，避免多进程生成重复主键
    \app\bootstrap\SnowflakeWorker::class,
];
