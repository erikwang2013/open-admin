<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace app\admin\controller;

use app\model\AdminRole;
use app\model\AdminUser;
use app\common\EncryptionService;
use app\common\RbacService;
use app\common\SessionService;
use Illuminate\Database\QueryException;
use support\Db;
use support\Request;
use support\Response;
use Webman\Validation\Validator;

/**
 * @Apidoc\Title("用户管理")
 */
class UserController extends BaseController
{
    /**
     * @Apidoc\Title("用户列表")
     * @Apidoc\Group("用户管理")
     * @Apidoc\Url("/admin/user")
     * @Apidoc\Desc("分页获取用户列表，支持关键词搜索和状态筛选")
     * @Apidoc\Param("page", type="int", require=false, desc="页码", default="1")
     * @Apidoc\Param("limit", type="int", require=false, desc="每页条数", default="15")
     * @Apidoc\Param("keyword", type="string", require=false, desc="搜索关键词")
     * @Apidoc\Param("status", type="int", require=false, desc="状态筛选(0禁用1启用)")
     * @Apidoc\Returned("list", type="array", desc="用户列表")
     * @Apidoc\Returned("total", type="int", desc="总数")
     * @Apidoc\Returned("page", type="int", desc="当前页码")
     * @Apidoc\Returned("limit", type="int", desc="每页条数")
     */
    public function index(Request $request): Response
    {
        [$page, $limit] = self::clampPage((int) $request->input('page', 1), (int) $request->input('limit', 15));
        $keyword = $request->input('keyword', '');
        $status = $request->input('status');

        $query = AdminUser::query();
        if ($keyword) {
            $query->where(function ($q) use ($keyword) {
                $q->where('username', 'like', "%{$keyword}%")
                  ->orWhere('real_name', 'like', "%{$keyword}%");
            });
        }
        if ($status !== null && $status !== '') {
            $query->where('status', (int) $status);
        }

        $total = $query->count();
        $list = $query->offset(($page - 1) * $limit)
                      ->limit($limit)
                      ->orderBy('id', 'desc')
                      ->get()
                      ->map(function ($user) {
                          $data = $user->toArray();
                          unset($data['password'], $data['id_card']);
                          // 脱敏处理（Encryptable cast 已自动解密，直接对明文脱敏）
                          if (!empty($data['phone'])) {
                              $data['phone'] = self::maskPhone((string) $data['phone']);
                          }
                          if (!empty($data['email'])) {
                              $data['email'] = self::maskEmail((string) $data['email']);
                          }
                          return $this->encodeIds($data);
                      });

        return $this->success([
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    /**
     * @Apidoc\Title("创建用户")
     * @Apidoc\Group("用户管理")
     * @Apidoc\Method("POST")
     * @Apidoc\Url("/admin/user")
     * @Apidoc\Desc("创建新用户")
     * @Apidoc\Param("username", type="string", require=true, desc="用户名")
     * @Apidoc\Param("password", type="string", require=true, desc="密码")
     * @Apidoc\Param("real_name", type="string", require=true, desc="真实姓名")
     * @Apidoc\Param("status", type="int", require=false, desc="状态(0禁用1启用)", default="1")
     * @Apidoc\Param("phone", type="string", require=false, desc="手机号")
     * @Apidoc\Param("email", type="string", require=false, desc="邮箱")
     * @Apidoc\Param("role_ids", type="array", require=false, desc="角色hashid数组；非超管只能分配自身权限范围内的角色")
     */
    public function store(Request $request): Response
    {
        $passwordInput = $request->input('password');
        $password = is_string($passwordInput) ? EncryptionService::decryptTransmission($passwordInput) : $passwordInput;
        $phoneInput = $request->input('phone', '');
        $phone = is_string($phoneInput) ? EncryptionService::decryptTransmission($phoneInput) : $phoneInput;
        $emailInput = $request->input('email', '');
        $email = is_string($emailInput) ? EncryptionService::decryptTransmission($emailInput) : $emailInput;

        $data = array_merge($request->all(), [
            'password' => $password,
            'phone' => $phone,
            'email' => $email,
        ]);

        $validator = Validator::make($data, [
            'username' => 'required|string|min:3|max:50',
            'password' => 'required|string|min:6|max:32',
            'real_name' => 'required|string|max:50',
            'status' => 'in:0,1',
            'phone' => 'string|max:20',
            'email' => 'string|max:254',
        ]);

        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422);
        }

        $roleIds = $this->resolveRoleIds($request);
        if ($roleIds instanceof Response) {
            return $roleIds;
        }
        $adminId = (int) ($request->adminId ?? 0);
        if ($roleIds !== null && $this->containsSuperAdminRole($roleIds) && !$this->isSuperAdmin($adminId)) {
            return $this->fail('仅超级管理员可分配超级管理员角色', 403);
        }
        // 非超级管理员不能授予超出自身权限集合的角色
        if ($roleIds !== null && !RbacService::canGrant($adminId, $roleIds)) {
            return $this->fail('无权授予超出自身权限的角色', 403);
        }

        $exists = AdminUser::withTrashed()->where('username', $request->input('username'))->exists();
        if ($exists) {
            return $this->fail(trans('messages.username_exists'), 422);
        }

        $user = new AdminUser();
        $user->id = $this->generateId();
        $user->username = $request->input('username');
        $user->password = password_hash($password, PASSWORD_BCRYPT);
        $user->real_name = $request->input('real_name');
        $user->status = (int) $request->input('status', 1);
        $user->phone = $phone;
        $user->email = $email;
        try {
            // 用户与角色关联同一事务：角色写入失败时回滚用户，避免留下无角色账号
            Db::connection()->transaction(function () use ($user, $roleIds): void {
                $user->save();
                if ($roleIds !== null) {
                    $user->roles()->sync($roleIds);
                }
            });
        } catch (QueryException $e) {
            // 并发创建同名用户时唯一索引兜底；其他写入错误继续抛出
            if (AdminUser::withTrashed()->where('username', $request->input('username'))->exists()) {
                return $this->fail(trans('messages.username_exists'), 422);
            }
            throw $e;
        }

        $udata = $user->toArray();
        unset($udata['password'], $udata['id_card']);
        return $this->success($this->encodeIds($udata), trans('messages.create_success'));
    }

    /**
     * @Apidoc\Title("用户详情")
     * @Apidoc\Group("用户管理")
     * @Apidoc\Url("/admin/user/{id}")
     * @Apidoc\Desc("获取指定用户的详细信息")
     * @Apidoc\Param("id", type="string", require=true, desc="用户hashid")
     */
    public function show(Request $request, string $id): Response
    {
        $id = $this->decodeId($id);
        $user = AdminUser::find($id);
        if (!$user) {
            return $this->fail(trans('messages.user_not_found'), 404);
        }

        $data = $user->toArray();
        unset($data['password'], $data['id_card']);
        // Encryptable cast 已自动解密，phone/email 直接为明文
        $data['role_ids'] = $user->roles
            ->map(fn ($role) => $this->encodeId((int) $role->id))
            ->values()
            ->all();
        return $this->success($this->encodeIds($data));
    }

    /**
     * @Apidoc\Title("更新用户")
     * @Apidoc\Group("用户管理")
     * @Apidoc\Method("PUT")
     * @Apidoc\Url("/admin/user/{id}")
     * @Apidoc\Desc("更新指定用户的信息")
     * @Apidoc\Param("id", type="string", require=true, desc="用户hashid")
     * @Apidoc\Param("real_name", type="string", require=false, desc="真实姓名")
     * @Apidoc\Param("status", type="int", require=false, desc="状态")
     * @Apidoc\Param("password", type="string", require=false, desc="新密码")
     * @Apidoc\Param("phone", type="string", require=false, desc="手机号")
     * @Apidoc\Param("email", type="string", require=false, desc="邮箱")
     * @Apidoc\Param("role_ids", type="array", require=false, desc="角色hashid数组；仅校验本次新增角色，非超管受权限边界限制")
     */
    public function update(Request $request, string $id): Response
    {
        $id = $this->decodeId($id);
        $user = AdminUser::find($id);
        if (!$user) {
            return $this->fail(trans('messages.user_not_found'), 404);
        }

        $adminId = (int) ($request->adminId ?? 0);

        // 密码只解密一次，校验通过后才哈希；未提供或空密码视为不修改
        $data = $request->all();
        $passwordInput = $request->input('password');
        $plainPassword = null;
        if ($passwordInput !== null && $passwordInput !== '') {
            if (is_string($passwordInput)) {
                $plainPassword = EncryptionService::decryptTransmission($passwordInput);
                $data['password'] = $plainPassword;
            }
        } else {
            unset($data['password']);
        }

        // phone/email 解密后参与校验；null 视为不修改
        foreach (['phone', 'email'] as $field) {
            $raw = $request->input($field);
            if ($raw === null) {
                unset($data[$field]);
                continue;
            }
            $data[$field] = is_string($raw) ? EncryptionService::decryptTransmission($raw) : $raw;
        }

        $validator = Validator::make($data, [
            'password'  => 'string|min:6|max:32',
            'real_name' => 'string|max:50',
            'phone'     => 'string|max:20',
            'email'     => 'string|max:254',
            'status'    => 'in:0,1',
        ]);
        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422);
        }

        $statusInput = $request->input('status');
        $status = $statusInput === null ? (int) $user->status : (int) $statusInput;
        if (!in_array($status, [0, 1], true)) {
            return $this->fail(trans('messages.invalid_status'), 422);
        }
        if ($status !== 1 && $id === $adminId) {
            return $this->fail('不能禁用当前登录账号', 422);
        }

        $roleIds = $this->resolveRoleIds($request);
        if ($roleIds instanceof Response) {
            return $roleIds;
        }

        // 低权限管理员不得操作超级管理员账号，也不得分配超级管理员角色（锁内会重新校验）
        $actorIsSuper = $this->isSuperAdmin($adminId);
        $currentRoleIds = $this->userRoleIds($user);
        $currentHasSuper = $this->containsSuperAdminRole($currentRoleIds);
        $newHasSuper = $roleIds === null ? $currentHasSuper : $this->containsSuperAdminRole($roleIds);
        $authError = $this->superAdminGuard($actorIsSuper, $currentHasSuper, $newHasSuper);
        if ($authError !== null) {
            return $authError;
        }
        // 非超级管理员不能操作持有更高权限角色的账号（改密/禁用/删除等）
        if (!$actorIsSuper && !RbacService::canManageUser($adminId, $id)) {
            return $this->fail('无权管理该用户', 403);
        }
        // 只校验本次新增的角色：保留用户原有更高角色或移除角色不受限制
        if ($roleIds !== null && !RbacService::canGrant($adminId, array_diff($roleIds, $currentRoleIds))) {
            return $this->fail('无权授予超出自身权限的角色', 403);
        }

        $apply = function () use ($request, $user, $id, $adminId, $status, $statusInput, $plainPassword, $roleIds, $data): Response {
            // 锁内重新读取角色，防止并发分配使目标在上面的检查之后才成为超级管理员
            $liveCurrentRoleIds = $this->userRoleIds($user);
            $liveCurrentHasSuper = $this->containsSuperAdminRole($liveCurrentRoleIds);
            $liveNewHasSuper = $roleIds === null
                ? $liveCurrentHasSuper
                : $this->containsSuperAdminRole($roleIds);
            $liveActorIsSuper = $this->isSuperAdmin($adminId);
            $authError = $this->superAdminGuard($liveActorIsSuper, $liveCurrentHasSuper, $liveNewHasSuper);
            if ($authError !== null) {
                return $authError;
            }
            if (!$liveActorIsSuper && !RbacService::canManageUser($adminId, $id)) {
                return $this->fail('无权管理该用户', 403);
            }
            if ($roleIds !== null && !RbacService::canGrant($adminId, array_diff($roleIds, $liveCurrentRoleIds))) {
                return $this->fail('无权授予超出自身权限的角色', 403);
            }

            // 最后一名启用超级管理员校验与写操作在同一把锁内，避免并发绕过
            if ($status !== 1 && $this->leavesNoSuperAdmin([$id])) {
                return $this->fail('系统必须保留至少一名启用的超级管理员', 422);
            }
            if ($liveCurrentHasSuper && !$liveNewHasSuper && $this->leavesNoSuperAdmin([$id])) {
                return $this->fail('系统必须保留至少一名启用的超级管理员', 422);
            }

            $user->real_name = $request->input('real_name', $user->real_name);
            $user->status = $status;
            if ($plainPassword !== null) {
                $user->password = password_hash($plainPassword, PASSWORD_BCRYPT);
            }
            // 客户端可能回传列表中的脱敏值：与当前值一致或本身就是掩码形状时视为未修改
            if (array_key_exists('phone', $data)
                && !self::looksMaskedPhone((string) $data['phone'])
                && $data['phone'] !== self::maskPhone((string) $user->phone)) {
                $user->phone = (string) $data['phone'];
            }
            if (array_key_exists('email', $data)
                && !self::looksMaskedEmail((string) $data['email'])
                && $data['email'] !== self::maskEmail((string) $user->email)) {
                $user->email = (string) $data['email'];
            }

            // 字段与角色关联同一事务：角色写入失败时回滚字段修改
            Db::connection()->transaction(function () use ($user, $roleIds): void {
                $user->save();
                if ($roleIds !== null) {
                    $user->roles()->sync($roleIds);
                }
            });

            // 密码/禁用吊销会话；仅角色变更时清理权限缓存即可
            if ($plainPassword !== null || ($statusInput !== null && $status === 0)) {
                SessionService::revokeUser($id);
            } elseif ($roleIds !== null) {
                SessionService::clearPermissionCache($id);
            }

            $udata = $user->toArray();
            // roles 关系在校验时已加载，避免以其原始 ID 混入响应
            unset($udata['password'], $udata['id_card'], $udata['roles']);
            return $this->success($this->encodeIds($udata), trans('messages.update_success'));
        };

        // 状态变化、角色变更、目标是超级管理员，或操作者不是超级管理员时，
        // 都与角色分配/最后一名超级管理员守卫串行化（锁内会重新校验授权）
        $statusChanged = $statusInput !== null && $status !== (int) $user->status;
        if ($statusChanged || $roleIds !== null || $currentHasSuper || !$actorIsSuper) {
            return $this->withSuperAdminLock($apply) ?? $this->serviceBusyResponse();
        }
        return $apply();
    }

    /**
     * @Apidoc\Title("删除用户")
     * @Apidoc\Group("用户管理")
     * @Apidoc\Method("DELETE")
     * @Apidoc\Url("/admin/user/{id}")
     * @Apidoc\Desc("软删除指定用户，需密码二次确认")
     * @Apidoc\Param("id", type="string", require=true, desc="用户hashid")
     * @Apidoc\Param("password", type="string", require=true, desc="当前管理员密码")
     */
    public function destroy(Request $request, string $id): Response
    {
        $id = $this->decodeId($id);
        $user = AdminUser::find($id);
        if (!$user) {
            return $this->fail(trans('messages.user_not_found'), 404);
        }

        $adminId = (int) ($request->adminId ?? 0);
        if ($id === $adminId) {
            return $this->fail('不能删除当前登录账号', 422);
        }
        $actorIsSuper = $this->isSuperAdmin($adminId);
        if (!$actorIsSuper && $this->hasSuperAdminRoleOnUsers([$id])) {
            return $this->fail('仅超级管理员可删除超级管理员账号', 403);
        }
        if (!$actorIsSuper && !RbacService::canManageUser($adminId, $id)) {
            return $this->fail('无权管理该用户', 403);
        }
        $error = $this->confirmPassword($adminId, $request->input('password', ''), $request);
        if ($error !== null) {
            return $this->fail($error, 422);
        }

        return $this->withSuperAdminLock(function () use ($id, $user, $adminId): Response {
            // 锁内复核：并发角色分配可能刚把目标提升为超级管理员
            $liveActorIsSuper = $this->isSuperAdmin($adminId);
            if (!$liveActorIsSuper && $this->hasSuperAdminRoleOnUsers([$id])) {
                return $this->fail('仅超级管理员可删除超级管理员账号', 403);
            }
            if (!$liveActorIsSuper && !RbacService::canManageUser($adminId, $id)) {
                return $this->fail('无权管理该用户', 403);
            }
            if ($this->leavesNoSuperAdmin([$id])) {
                return $this->fail('系统必须保留至少一名启用的超级管理员', 422);
            }

            // 软删除前腾出用户名，释放唯一索引；截断以适配 username varchar(50)
            $suffix = '#deleted' . $user->id;
            $user->username = mb_substr($user->username, 0, max(0, 50 - mb_strlen($suffix))) . $suffix;
            $user->save();
            $user->delete();
            SessionService::revokeUser($id);
            return $this->success([], trans('messages.delete_success'));
        }) ?? $this->serviceBusyResponse();
    }

    /**
     * @Apidoc\Title("批量删除")
     * @Apidoc\Group("用户管理")
     * @Apidoc\Method("POST")
     * @Apidoc\Url("/admin/user/batch/destroy")
     * @Apidoc\Desc("批量删除用户，需密码二次确认")
     * @Apidoc\Param("ids", type="array", require=true, desc="用户hashid数组")
     * @Apidoc\Param("password", type="string", require=true, desc="当前管理员密码")
     */
    public function batchDestroy(Request $request): Response
    {
        $ids      = $request->input('ids', []);
        $password = $request->input('password', '');

        if (empty($ids) || !is_array($ids)) {
            return $this->fail(trans('messages.no_user_selection'), 422);
        }

        $adminId = $request->adminId ?? 0;
        $error   = $this->confirmPassword($adminId, $password, $request);
        if ($error !== null) {
            return $this->fail($error, 422);
        }

        $decodedIds = [];
        $invalidIds = [];
        foreach ($ids as $id) {
            try {
                $decodedIds[] = $this->decodeId($id);
            } catch (\InvalidArgumentException $e) {
                $invalidIds[] = $id;
            }
        }
        if (!empty($invalidIds)) {
            return $this->fail(trans('messages.invalid_ids') . ': ' . implode(', ', $invalidIds), 422);
        }

        $actorId = (int) $adminId;
        if (in_array($actorId, $decodedIds, true)) {
            return $this->fail('不能删除当前登录账号', 422);
        }
        $actorIsSuper = $this->isSuperAdmin($actorId);
        if (!$actorIsSuper && $this->hasSuperAdminRoleOnUsers($decodedIds)) {
            return $this->fail('仅超级管理员可删除超级管理员账号', 403);
        }
        if (!$actorIsSuper && !$this->canManageUsers($actorId, $decodedIds)) {
            return $this->fail('无权管理该用户', 403);
        }

        return $this->withSuperAdminLock(function () use ($decodedIds, $actorId): Response {
            // 锁内复核：并发角色分配可能刚把目标提升为超级管理员
            $liveActorIsSuper = $this->isSuperAdmin($actorId);
            if (!$liveActorIsSuper && $this->hasSuperAdminRoleOnUsers($decodedIds)) {
                return $this->fail('仅超级管理员可删除超级管理员账号', 403);
            }
            if (!$liveActorIsSuper && !$this->canManageUsers($actorId, $decodedIds)) {
                return $this->fail('无权管理该用户', 403);
            }
            if ($this->leavesNoSuperAdmin($decodedIds)) {
                return $this->fail('系统必须保留至少一名启用的超级管理员', 422);
            }

            foreach (AdminUser::whereIn('id', $decodedIds)->get() as $user) {
                $suffix = '#deleted' . $user->id;
                $user->username = mb_substr($user->username, 0, max(0, 50 - mb_strlen($suffix))) . $suffix;
                $user->save();
                $user->delete();
                SessionService::revokeUser((int) $user->id);
            }

            return $this->success(['count' => count($decodedIds)], '删除成功');
        }) ?? $this->serviceBusyResponse();
    }

    /**
     * @Apidoc\Title("批量启用/禁用")
     * @Apidoc\Group("用户管理")
     * @Apidoc\Method("POST")
     * @Apidoc\Url("/admin/user/batch/status")
     * @Apidoc\Desc("批量修改用户启用/禁用状态")
     * @Apidoc\Param("ids", type="array", require=true, desc="用户hashid数组")
     * @Apidoc\Param("status", type="int", require=true, desc="目标状态(0禁用1启用)")
     */
    public function batchStatus(Request $request): Response
    {
        $ids    = $request->input('ids', []);
        $status = (int) $request->input('status', 0);

        if (empty($ids) || !is_array($ids)) {
            return $this->fail(trans('messages.no_user_selection_status'), 422);
        }

        if (!in_array($status, [0, 1], true)) {
            return $this->fail(trans('messages.invalid_status'), 422);
        }

        $decodedIds = [];
        $invalidIds = [];
        foreach ($ids as $id) {
            try {
                $decodedIds[] = $this->decodeId($id);
            } catch (\InvalidArgumentException $e) {
                $invalidIds[] = $id;
            }
        }
        if (!empty($invalidIds)) {
            return $this->fail(trans('messages.invalid_ids') . ': ' . implode(', ', $invalidIds), 422);
        }

        $adminId = (int) ($request->adminId ?? 0);
        if (in_array($adminId, $decodedIds, true)) {
            return $this->fail('不能修改当前登录账号的状态', 422);
        }
        $actorIsSuper = $this->isSuperAdmin($adminId);
        if (!$actorIsSuper && $this->hasSuperAdminRoleOnUsers($decodedIds)) {
            return $this->fail('仅超级管理员可修改超级管理员账号', 403);
        }
        if (!$actorIsSuper && !$this->canManageUsers($adminId, $decodedIds)) {
            return $this->fail('无权管理该用户', 403);
        }

        $message = $status === 1 ? trans('messages.batch_enable_success') : trans('messages.batch_disable_success');

        // 启用/禁用都与角色分配与最后一名超级管理员守卫串行化
        return $this->withSuperAdminLock(function () use ($decodedIds, $status, $message, $adminId): Response {
            // 锁内复核：并发角色分配可能刚把目标提升为超级管理员
            $liveActorIsSuper = $this->isSuperAdmin($adminId);
            if (!$liveActorIsSuper && $this->hasSuperAdminRoleOnUsers($decodedIds)) {
                return $this->fail('仅超级管理员可修改超级管理员账号', 403);
            }
            if (!$liveActorIsSuper && !$this->canManageUsers($adminId, $decodedIds)) {
                return $this->fail('无权管理该用户', 403);
            }
            if ($status === 0) {
                if ($this->leavesNoSuperAdmin($decodedIds)) {
                    return $this->fail('系统必须保留至少一名启用的超级管理员', 422);
                }
                AdminUser::whereIn('id', $decodedIds)->update(['status' => 0]);
                foreach ($decodedIds as $uid) {
                    SessionService::revokeUser((int) $uid);
                }
            } else {
                AdminUser::whereIn('id', $decodedIds)->update(['status' => 1]);
            }
            return $this->success(['count' => count($decodedIds)], $message);
        }) ?? $this->serviceBusyResponse();
    }

    /**
     * 解码 role_ids（hashid 数组）；无效或不存在的角色返回 422 响应，未提交返回 null
     *
     * @return int[]|Response|null
     */
    private function resolveRoleIds(Request $request): array|Response|null
    {
        $input = $request->input('role_ids');
        if ($input === null) {
            return null;
        }
        if (!is_array($input)) {
            return $this->fail(trans('messages.invalid_ids'), 422);
        }

        $ids = [];
        foreach ($input as $hashid) {
            if (!is_string($hashid)) {
                return $this->fail(trans('messages.invalid_ids'), 422);
            }
            try {
                $ids[] = $this->decodeId($hashid);
            } catch (\InvalidArgumentException $e) {
                return $this->fail(trans('messages.invalid_ids'), 422);
            }
        }

        $ids = array_values(array_unique($ids));
        if ($ids !== [] && AdminRole::whereIn('id', $ids)->count() !== count($ids)) {
            return $this->fail(trans('messages.invalid_ids'), 422);
        }
        return $ids;
    }

    /**
     * 读取用户当前角色 ID（直接查库，不使用可能过期的关系缓存）
     *
     * @return int[]
     */
    private function userRoleIds(AdminUser $user): array
    {
        return $user->roles()->get()->map(static fn ($role) => (int) $role->id)->all();
    }

    /**
     * 角色 ID 中是否包含超级管理员角色
     */
    private function containsSuperAdminRole(array $roleIds): bool
    {
        return $roleIds !== []
            && AdminRole::whereIn('id', $roleIds)->where('slug', 'super_admin')->exists();
    }

    /**
     * 超级管理员操作授权：低权限管理员不得操作超级管理员账号或分配该角色
     */
    private function superAdminGuard(bool $actorIsSuper, bool $currentHasSuper, bool $newHasSuper): ?Response
    {
        if (($currentHasSuper || $newHasSuper) && !$actorIsSuper) {
            return $this->fail(
                $currentHasSuper ? '仅超级管理员可修改超级管理员账号' : '仅超级管理员可分配超级管理员角色',
                403
            );
        }
        return null;
    }

    /**
     * 给定用户中是否有人拥有超级管理员角色
     */
    private function hasSuperAdminRoleOnUsers(array $userIds): bool
    {
        return AdminUser::whereIn('id', $userIds)
            ->whereHas('roles', static fn ($query) => $query->where('slug', 'super_admin'))
            ->exists();
    }

    /**
     * 操作者能否管理全部目标账号（任一目标权限高于操作者即拒绝）
     *
     * @param int[] $targetIds
     */
    private function canManageUsers(int $adminId, array $targetIds): bool
    {
        foreach ($targetIds as $targetId) {
            if (!RbacService::canManageUser($adminId, (int) $targetId)) {
                return false;
            }
        }
        return true;
    }

    /**
     * 当前登录管理员是否为启用的超级管理员
     */
    private function isSuperAdmin(int $adminId): bool
    {
        return $adminId > 0 && AdminUser::where('id', $adminId)
            ->whereHas('roles', static fn ($query) => $query->where('slug', 'super_admin')->where('status', 1))
            ->exists();
    }

    /**
     * 手机号脱敏（与列表接口保持一致，用于识别客户端回传的脱敏值）
     */
    private static function maskPhone(string $phone): string
    {
        return preg_replace('/^(\d{3})\d+(\d{4})$/', '$1****$2', $phone) ?? $phone;
    }

    /**
     * 邮箱脱敏（与列表接口保持一致，用于识别客户端回传的脱敏值）
     */
    private static function maskEmail(string $email): string
    {
        $parts = explode('@', $email);
        return mb_substr($parts[0], 0, 1) . '***@' . ($parts[1] ?? '');
    }

    /**
     * 是否为手机号掩码形状（客户端列表脱敏值，可能已过期）
     */
    private static function looksMaskedPhone(string $phone): bool
    {
        return preg_match('/^\d{3}\*{4}\d{4}$/', $phone) === 1;
    }

    /**
     * 是否为邮箱掩码形状（客户端列表脱敏值，可能已过期）
     */
    private static function looksMaskedEmail(string $email): bool
    {
        return preg_match('/^[^@\s]?\*{3}@[^@\s]*$/', $email) === 1;
    }

    /**
     * 在 MySQL 命名锁内执行「最后一名启用超级管理员」校验与写操作，跨进程串行化
     * 未获取到锁时返回 null，由调用方返回 503
     */
    private function withSuperAdminLock(callable $callback): ?Response
    {
        $name = 'open_admin:last_super_admin';
        try {
            $acquired = (int) (Db::selectOne('SELECT GET_LOCK(?, 3) AS acquired', [$name])->acquired ?? 0);
        } catch (\Throwable) {
            return null;
        }
        if ($acquired !== 1) {
            return null;
        }

        try {
            return $callback();
        } finally {
            try {
                Db::selectOne('SELECT RELEASE_LOCK(?) AS released', [$name]);
            } catch (\Throwable) {
                // 连接断开时 MySQL 会自动释放命名锁
            }
        }
    }

    /**
     * 命名锁等待超时时的响应
     */
    private function serviceBusyResponse(): Response
    {
        return $this->fail('系统繁忙，请稍后重试', 503)->withStatus(503);
    }

    /**
     * 判断本次删除/禁用是否会移除最后一名启用的超级管理员
     *
     * @param int[] $ids 将被删除或禁用的用户 ID
     */
    private function leavesNoSuperAdmin(array $ids): bool
    {
        $isSuperAdmin = static fn ($query) => $query->where('admin_role.slug', 'super_admin');
        $affected = AdminUser::whereIn('id', $ids)
            ->where('status', 1)
            ->whereHas('roles', $isSuperAdmin)
            ->count();
        if ($affected === 0) {
            return false;
        }

        $remaining = AdminUser::where('status', 1)->whereHas('roles', $isSuperAdmin)->count() - $affected;
        return $remaining < 1;
    }
}
