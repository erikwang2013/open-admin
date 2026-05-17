<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

use Webman\Route;

/**
 * API 路由配置
 *
 * 路由分组说明:
 * - /admin/*  管理端接口，需要 JWT 认证 + 权限校验
 * - /api/*    客户端接口，需要 JWT 认证（部分白名单接口除外）
 * - /common/* 公共服务（文件上传等），无需认证
 */

// ============================================================
// 管理端路由
// ============================================================
Route::group('/admin', function () {
    // 仪表盘
    Route::get('/dashboard', [app\admin\controller\DashboardController::class, 'index']);

    // 用户管理
    Route::resource('/user', app\admin\controller\UserController::class);

    // 角色管理
    Route::resource('/role', app\admin\controller\RoleController::class);

    // 权限管理
    Route::resource('/permission', app\admin\controller\PermissionController::class);

    // 导出
    Route::post('/export/excel', [app\admin\controller\ExportController::class, 'excel']);
    Route::post('/export/pdf', [app\admin\controller\ExportController::class, 'pdf']);
})->middleware([
    app\middleware\AdminAuth::class,
    app\middleware\AdminPermission::class,
]);

// ============================================================
// 公开接口（无需认证）
// ============================================================
Route::group('/api', function () {
    // 点击验证码
    Route::post('/captcha/generate', [app\api\controller\CaptchaController::class, 'generate']);
    Route::post('/captcha/verify', [app\api\controller\CaptchaController::class, 'verify']);

    // 认证
    Route::post('/auth/login', [app\api\controller\AuthController::class, 'login']);
    Route::post('/auth/register', [app\api\controller\AuthController::class, 'register']);
    Route::post('/auth/refresh', [app\api\controller\AuthController::class, 'refresh']);
});

// 关闭默认路由（生产环境建议关闭）
Route::disableDefaultRoute();
