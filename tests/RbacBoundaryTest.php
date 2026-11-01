<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace tests;

use app\common\RbacService;
use PHPUnit\Framework\TestCase;
use support\Db;

/**
 * RBAC 授权边界：只能授予/管理权限集合不超过自身权限的角色
 */
final class RbacBoundaryTest extends TestCase
{
    private int $base;

    /** @var int[] 本测试创建、需在 tearDown 清理的行 */
    private array $userIds = [];
    private array $roleIds = [];
    private array $permissionIds = [];

    public static function setUpBeforeClass(): void
    {
        try {
            Db::connection()->getPdo();
        } catch (\Throwable $e) {
            self::markTestSkipped('数据库不可用: ' . $e->getMessage());
        }
    }

    protected function setUp(): void
    {
        // 高位随机 ID 段，避免与雪花 ID 或并发测试相互覆盖
        $this->base = random_int(9_300_000_000_000_000, 9_399_999_999_999_999);
        $this->userIds = $this->roleIds = $this->permissionIds = [];
    }

    protected function tearDown(): void
    {
        try {
            if ($this->userIds !== []) {
                Db::table('admin_user_role')->whereIn('user_id', $this->userIds)->delete();
                Db::table('admin_user')->whereIn('id', $this->userIds)->delete();
            }
            if ($this->roleIds !== []) {
                Db::table('admin_role_permission')->whereIn('role_id', $this->roleIds)->delete();
                Db::table('admin_role')->whereIn('id', $this->roleIds)->delete();
            }
            if ($this->permissionIds !== []) {
                Db::table('admin_permission')->whereIn('id', $this->permissionIds)->delete();
            }
        } catch (\Throwable) {
            // 数据库中途不可用时忽略清理失败，不改写测试结果
        }
        $this->userIds = $this->roleIds = $this->permissionIds = [];
    }

    private function permission(int $offset): int
    {
        $id = $this->base + $offset;
        Db::table('admin_permission')->insert([
            'id'        => $id,
            'parent_id' => 0,
            'name'      => 'phpunit',
            'slug'      => 'phpunit.rbac.' . bin2hex(random_bytes(4)),
            'type'      => 3,
        ]);
        $this->permissionIds[] = $id;

        return $id;
    }

    /**
     * @param int[] $permissionIds
     */
    private function role(int $offset, array $permissionIds, int $status = 1): int
    {
        $id = $this->base + $offset;
        Db::table('admin_role')->insert([
            'id'     => $id,
            'name'   => 'phpunit',
            'slug'   => 'phpunit_rbac_' . bin2hex(random_bytes(6)),
            'status' => $status,
        ]);
        $this->roleIds[] = $id;

        foreach ($permissionIds as $permissionId) {
            Db::table('admin_role_permission')->insert(['role_id' => $id, 'permission_id' => $permissionId]);
        }

        return $id;
    }

    /**
     * @param int[] $roleIds
     */
    private function user(int $offset, array $roleIds): int
    {
        $id = $this->base + $offset;
        Db::table('admin_user')->insert([
            'id'       => $id,
            'username' => 'phpunit_rbac_' . bin2hex(random_bytes(6)),
            'password' => password_hash('phpunit', PASSWORD_BCRYPT),
            'status'   => 1,
        ]);
        $this->userIds[] = $id;

        foreach ($roleIds as $roleId) {
            Db::table('admin_user_role')->insert(['user_id' => $id, 'role_id' => $roleId]);
        }

        return $id;
    }

    private function superRoleId(): int
    {
        $id = Db::table('admin_role')->where('slug', 'super_admin')->value('id');
        if ($id === null) {
            // 权限边界的超级管理员语义依赖内置角色，缺失时跳过而不是伪造一个
            self::markTestSkipped('数据库缺少内置 super_admin 角色');
        }

        return (int) $id;
    }

    public function testCanGrantAllowsSubsetAndZeroPermissionsButRejectsHigher(): void
    {
        $p1 = $this->permission(101);
        $p2 = $this->permission(102);
        $p3 = $this->permission(103);
        $actorRole      = $this->role(201, [$p1, $p2]);
        $higherRole     = $this->role(202, [$p3]);
        $zeroRole       = $this->role(203, []);
        $disabledHigher = $this->role(204, [$p3], 0);
        $actor          = $this->user(301, [$actorRole]);

        self::assertTrue(RbacService::canGrant($actor, []));
        self::assertTrue(RbacService::canGrant($actor, [$actorRole]));
        self::assertTrue(RbacService::canGrant($actor, [$zeroRole]));
        self::assertFalse(RbacService::canGrant($actor, [$higherRole]));
        self::assertFalse(RbacService::canGrant($actor, [$actorRole, $higherRole]));
        // 停用角色同样按权限行校验：重新启用后不能成为提权通道
        self::assertFalse(RbacService::canGrant($actor, [$disabledHigher]));
    }

    public function testSuperAdminMayGrantAndManageAnyone(): void
    {
        $higherRole = $this->role(202, [$this->permission(103)]);
        $higherUser = $this->user(302, [$higherRole]);
        $superUser  = $this->user(303, [$this->superRoleId()]);

        self::assertTrue(RbacService::canGrant($superUser, [$higherRole]));
        self::assertTrue(RbacService::canManageUser($superUser, $higherUser));
        self::assertContains($higherRole, RbacService::assignableRoleIds($superUser));
    }

    public function testCanManageUserChecksAllTargetRolesIncludingDisabled(): void
    {
        $p1 = $this->permission(101);
        $p2 = $this->permission(102);
        $actorRole      = $this->role(201, [$p1]);
        $higherRole     = $this->role(202, [$p2]);
        $actor          = $this->user(301, [$actorRole]);
        $zeroTarget     = $this->user(302, []);
        $higherTarget   = $this->user(303, [$higherRole]);
        $disabledTarget = $this->user(304, [$this->role(204, [$p2], 0)]);

        self::assertTrue(RbacService::canManageUser($actor, $zeroTarget));
        self::assertTrue(RbacService::canManageUser($actor, $actor));
        self::assertFalse(RbacService::canManageUser($actor, $higherTarget));
        // 目标角色已停用也必须拒绝，避免重新启用后越权
        self::assertFalse(RbacService::canManageUser($actor, $disabledTarget));
    }

    public function testAssignableRoleIdsOnlyReturnsEnabledSubset(): void
    {
        $p1 = $this->permission(101);
        $p2 = $this->permission(102);
        $actorRole    = $this->role(201, [$p1]);
        $higherRole   = $this->role(202, [$p2]);
        $zeroRole     = $this->role(203, []);
        $disabledRole = $this->role(204, [$p1], 0);
        $actor        = $this->user(301, [$actorRole]);

        $assignable = RbacService::assignableRoleIds($actor);

        self::assertContains($actorRole, $assignable);
        self::assertContains($zeroRole, $assignable);
        self::assertNotContains($higherRole, $assignable);
        self::assertNotContains($disabledRole, $assignable);
        // 非超级管理员不能把超级管理员角色分配出去
        self::assertNotContains($this->superRoleId(), $assignable);
    }
}
