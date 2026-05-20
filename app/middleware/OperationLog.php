<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace app\middleware;

use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;
use app\common\SnowflakeService;

class OperationLog implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $method = $request->method();

        if (!in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
            return $handler($request);
        }

        $response = $handler($request);

        try {
            // 过滤敏感字段
            $input = $request->all();
            unset($input['password'], $input['old_password'], $input['new_password'], $input['new_password_confirmation']);

            $log = new \app\model\OperationLog();
            $log->id        = SnowflakeService::generate();
            $log->user_id    = $request->adminId ?? 0;
            $log->action     = $method;
            $log->method     = $method;
            $log->path       = $request->path();
            $log->ip         = $request->getRealIp();
            $log->input      = json_encode($input, JSON_UNESCAPED_UNICODE);
            $log->created_at = date('Y-m-d H:i:s');
            $log->save();
        } catch (\Throwable $e) {
            // 日志记录失败不应影响业务请求
        }

        return $response;
    }
}
