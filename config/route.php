<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

use Webman\Route;
use support\Request;

/**
 * API 路由配置
 *
 * 路由分组说明:
 * - /admin/*  管理端接口，需要 JWT 认证 + 权限校验
 * - /api/*    客户端接口（部分白名单，部分需认证）
 * - /common/* 公共服务（文件上传等），无需认证
 *
 * API 版本策略:
 * - 版本号通过请求头 API-Version 携带（如 "v1"、"v2"），不在 URL 中体现
 * - 缺失时默认使用 v1
 * - 由 ApiVersion 中间件校验，路由闭包按版本解析对应控制器
 */

/**
 * 创建版本化 API 路由闭包
 *
 * 根据请求头 API-Version 动态解析控制器类。
 * 控制器目录结构: app/api/{version}/controller/{Controller}.php
 */
function v(string $controller, string $action): \Closure
{
    return function (Request $request) use ($controller, $action) {
        $version = $request->apiVersion ?? 'v1';
        $class = "\\app\\api\\{$version}\\controller\\{$controller}";
        return (new $class)->{$action}($request);
    };
}

// ============================================================
// 管理端路由（保持不变，不参与版本化）
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
// 公开接口（通过 API-Version 头路由到版本化控制器）
// ============================================================
Route::group('/api', function () {
    // 点击验证码
    Route::post('/captcha/generate', v('CaptchaController', 'generate'));
    Route::post('/captcha/verify', v('CaptchaController', 'verify'));

    // 认证
    Route::post('/auth/login', v('AuthController', 'login'));
    Route::post('/auth/register', v('AuthController', 'register'));
    Route::post('/auth/refresh', v('AuthController', 'refresh'));
})->middleware([
    app\middleware\ApiVersion::class,
]);

// 关闭默认路由（生产环境建议关闭）
Route::disableDefaultRoute();
