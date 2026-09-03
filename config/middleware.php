<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

/**
 * 全局中间件配置
 *
 * 以下中间件对所有请求生效，按注册顺序依次执行。
 * 执行顺序: Cors → SecurityMiddleware (erikwang2013/security-php) → RateLimit → {路由组中间件} → Controller
 *
 * 说明: API 版本体现在路由前缀（/api/v1/...）中，由路由直接分发，无需版本中间件。
 */

return [
    '@' => [
        app\middleware\Cors::class,
        app\middleware\Locale::class,
        app\middleware\SecurityFilter::class,  // 基于 erikwang2013/security-php
        app\middleware\RateLimit::class,
    ],
];
