<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace app\common;

use app\model\AdminRole;
use app\model\AdminUser;
use support\Log;
use support\Redis;

/**
 * RBAC 授权边界
 * 用于限制管理员只能授予不超出自身权限集合的角色
 */
class RbacService
{
    /**
     * 全局权限缓存失效（角色/权限变更后调用）
     * 缓存键带版本号，递增后旧键不再被读取，60 秒内自然过期
     */
    public static function invalidatePermissionCaches(): void
    {
        try {
            Redis::incr('perm_version');
        } catch (\Throwable $e) {
            // Redis 故障时鉴权会降级查库，缓存最多再陈旧 60 秒
            Log::error('权限缓存版本递增失败: ' . $e->getMessage());
        }
    }

    /**
     * 用户的有效权限（仅启用的角色）；超级管理员返回 ['*']
     *
     * @return string[]
     */
    public static function permissionsOf(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }
        $roleIds = AdminUser::find($userId)?->roles()
            ->where('admin_role.status', 1)
            ->pluck('admin_role.id')
            ->map(static fn ($id) => (int) $id)
            ->all() ?? [];

        return self::permissionsForRoles($roleIds);
    }

    /**
     * 角色集合的有效权限（仅启用的角色）；包含 super_admin 时返回 ['*']
     *
     * @param int[] $roleIds
     * @return string[]
     */
    public static function permissionsForRoles(array $roleIds): array
    {
        return self::rolePermissions($roleIds, true);
    }

    /**
     * 操作者是否有权授予这些角色（超级管理员不受限）
     * 禁用角色也按权限行校验：角色可能在授予后被重新启用
     */
    public static function canGrant(int $actorId, array $roleIds): bool
    {
        $actorPermissions = self::permissionsOf($actorId);
        if (in_array('*', $actorPermissions, true)) {
            return true;
        }

        return array_diff(self::rolePermissions($roleIds, false), $actorPermissions) === [];
    }

    /**
     * @param int[] $roleIds
     * @param bool $enabledOnly 是否只统计启用角色
     * @return string[]
     */
    private static function rolePermissions(array $roleIds, bool $enabledOnly): array
    {
        if ($roleIds === []) {
            return [];
        }
        $query = AdminRole::whereIn('id', array_map('intval', $roleIds))->with('permissions');
        if ($enabledOnly) {
            $query->where('status', 1);
        }
        $roles = $query->get();

        if ($roles->contains(static fn (AdminRole $role) => $role->slug === 'super_admin')) {
            return ['*'];
        }

        return $roles
            ->flatMap(static fn (AdminRole $role) => $role->permissions->pluck('slug'))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * 操作者能否管理目标账号
     * 目标的所有角色（含停用）都必须可授予；无角色的目标允许管理，本人（角色是自身权限子集）也允许
     */
    public static function canManageUser(int $actorId, int $targetId): bool
    {
        if (in_array('*', self::permissionsOf($actorId), true)) {
            return true;
        }

        // 目标的所有角色都参与判断（含停用角色）：角色可能在之后被重新启用
        $targetRoleIds = AdminUser::find($targetId)?->roles()
            ->pluck('admin_role.id')
            ->map(static fn ($id) => (int) $id)
            ->all() ?? [];

        return $targetRoleIds === [] || self::canGrant($actorId, $targetRoleIds);
    }

    /**
     * 操作者可分配的角色 ID（仅启用角色，且权限是自身权限的子集）
     *
     * @return int[]
     */
    public static function assignableRoleIds(int $actorId): array
    {
        $actorPermissions = self::permissionsOf($actorId);
        $isSuper = in_array('*', $actorPermissions, true);

        $ids = [];
        foreach (AdminRole::where('status', 1)->with('permissions')->orderBy('id', 'asc')->get() as $role) {
            if (!$isSuper) {
                $rolePermissions = $role->permissions->pluck('slug')->unique()->all();
                if (array_diff($rolePermissions, $actorPermissions) !== []) {
                    continue;
                }
            }
            $ids[] = (int) $role->id;
        }

        return $ids;
    }
}
