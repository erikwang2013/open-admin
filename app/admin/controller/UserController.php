<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace app\admin\controller;

use app\model\AdminUser;
use app\common\EncryptionService;
use support\Request;

class UserController extends BaseController
{
    /**
     * 用户列表（分页）
     * GET /admin/user
     */
    public function index(Request $request): Response
    {
        $page = (int) $request->input('page', 1);
        $limit = (int) $request->input('limit', 15);
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
                          // 脱敏处理
                          if (!empty($data['phone'])) {
                              $data['phone'] = EncryptionService::maskPhone(
                                  EncryptionService::decrypt($data['phone'])
                              );
                          }
                          if (!empty($data['email'])) {
                              $data['email'] = EncryptionService::maskEmail(
                                  EncryptionService::decrypt($data['email'])
                              );
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
     * 创建用户
     * POST /admin/user
     */
    public function store(Request $request): Response
    {
        $validator = validator($request->all(), [
            'username' => 'required|string|min:3|max:50',
            'password' => 'required|string|min:6|max:32',
            'real_name' => 'required|string|max:50',
            'status' => 'in:0,1',
        ]);

        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422);
        }

        $exists = AdminUser::where('username', $request->input('username'))->exists();
        if ($exists) {
            return $this->fail('用户名已存在', 422);
        }

        $user = new AdminUser();
        $user->id = $this->generateId();
        $user->username = $request->input('username');
        $user->password = password_hash($request->input('password'), PASSWORD_BCRYPT);
        $user->real_name = $request->input('real_name');
        $user->status = (int) $request->input('status', 1);
        $user->phone = EncryptionService::encrypt($request->input('phone', ''));
        $user->email = EncryptionService::encrypt($request->input('email', ''));
        $user->save();

        $data = $user->toArray();
        unset($data['password'], $data['id_card']);
        return $this->success($this->encodeIds($data), '创建成功');
    }

    /**
     * 用户详情
     * GET /admin/user/{id}
     */
    public function show(Request $request, string $hashid): Response
    {
        $id = $this->decodeId($hashid);
        $user = AdminUser::find($id);
        if (!$user) {
            return $this->fail('用户不存在', 404);
        }

        $data = $user->toArray();
        unset($data['password'], $data['id_card']);
        if (!empty($data['phone'])) {
            $data['phone'] = EncryptionService::decrypt($data['phone']);
        }
        if (!empty($data['email'])) {
            $data['email'] = EncryptionService::decrypt($data['email']);
        }
        return $this->success($this->encodeIds($data));
    }

    /**
     * 更新用户
     * PUT /admin/user/{id}
     */
    public function update(Request $request, string $hashid): Response
    {
        $id = $this->decodeId($hashid);
        $user = AdminUser::find($id);
        if (!$user) {
            return $this->fail('用户不存在', 404);
        }

        $user->real_name = $request->input('real_name', $user->real_name);
        $user->status = (int) $request->input('status', $user->status);

        if ($request->has('password') && !empty($request->input('password'))) {
            $user->password = password_hash($request->input('password'), PASSWORD_BCRYPT);
        }
        if ($request->has('phone')) {
            $user->phone = EncryptionService::encrypt($request->input('phone', ''));
        }
        if ($request->has('email')) {
            $user->email = EncryptionService::encrypt($request->input('email', ''));
        }

        $user->save();

        $data = $user->toArray();
        unset($data['password'], $data['id_card']);
        return $this->success($this->encodeIds($data), '更新成功');
    }

    /**
     * 删除用户（软删除，需密码二次确认）
     * DELETE /admin/user/{id}
     */
    public function destroy(Request $request, string $hashid): Response
    {
        $id = $this->decodeId($hashid);
        $user = AdminUser::find($id);
        if (!$user) {
            return $this->fail('用户不存在', 404);
        }

        $adminId = $request->adminId ?? 0;
        $error = $this->confirmPassword($adminId, $request->input('password', ''), $request);
        if ($error !== null) {
            return $this->fail($error, 422);
        }

        $user->delete();
        return $this->success([], '删除成功');
    }
}
