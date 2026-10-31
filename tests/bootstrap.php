<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

use Dotenv\Dotenv;
use Webman\Config;

require dirname(__DIR__) . '/vendor/autoload.php';

// 与 webman 启动流程一致：加载 .env（存在时），不输出任何值
if (class_exists(Dotenv::class)) {
    Dotenv::createUnsafeImmutable(dirname(__DIR__))->safeLoad();
}

// 加载 config/，排除运行时才需要的 route/container（与 App::run 一致）
Config::load(dirname(__DIR__) . '/config', ['route', 'container']);
