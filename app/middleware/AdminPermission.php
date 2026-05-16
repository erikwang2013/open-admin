<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace app\middleware;

use support\Request;
use support\Response;

class AdminPermission
{
    public function process(Request $request, callable $next): Response
    {
        $adminId = $request->adminId ?? 0;
        if (!$adminId) {
            return $next($request);
        }

        $path = $request->path();
        $method = $request->method();

        // 获取用户权限标识列表
        $permissions = $this->getUserPermissions($adminId);

        // 超级管理员跳过检查
        if (in_array('*', $permissions)) {
            return $next($request);
        }

        // 构造权限标识: method.path
        $requiredPermission = strtolower($method) . '.' . trim($path, '/');

        if (!in_array($requiredPermission, $permissions)) {
            return json(['code' => 403, 'message' => '无权限访问', 'data' => []]);
        }

        return $next($request);
    }

    private function getUserPermissions(int $adminId): array
    {
        $user = \app\model\AdminUser::find($adminId);
        if (!$user) return [];

        $permissions = [];
        foreach ($user->roles as $role) {
            if ($role->status === 0) continue;
            foreach ($role->permissions as $perm) {
                $permissions[] = $perm->slug;
            }
        }
        return array_unique($permissions);
    }
}
