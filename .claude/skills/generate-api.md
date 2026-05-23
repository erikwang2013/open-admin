---
name: generate-api
description: Generate API controllers with hashids ID encryption, JWT auth, API encryption, snowflake ID generation
skill_type: implementation
---

# 生成 API 接口

生成符合项目规范的 API 控制器和路由。

## API 规范

### ID 处理
- **请求/响应中的 ID**: 使用 `erikwang2013/hashids` 加解密，对外暴露 hash 字符串
- **数据库存储的 ID**: BIGINT 原值，由 `erikwang2013/snowflake-php` 生成
- 不要在 API 层暴露真实数据库 ID

### 认证
- 使用 `erikwang2013/jwt-webman` 进行 JWT 认证
- 管理端中间件验证角色权限
- 客户端中间件验证用户身份

### 加密
- API 请求/响应中的敏感字段使用 `erikwang2013/encryption` 加解密
- 数据库敏感字段使用 `erikwang2013/encryptable` trait

### 统一响应格式

```json
{
    "code": 0,
    "message": "success",
    "data": {}
}
```

业务错误码:
- `0`: 成功
- `400`: 请求参数错误
- `401`: 未授权（未登录）
- `403`: 无权限
- `404`: 资源不存在
- `422`: 验证失败
- `500`: 服务器错误

## 控制器模板

### 管理端基础控制器

文件: `app/admin/controller/BaseController.php`

```php
<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

namespace app\admin\controller;

use app\common\HashidsService;
use app\common\SnowflakeService;
use app\common\EncryptionService;
use support\Request;
use support\Response;

class BaseController
{
    /**
     * 成功响应
     */
    protected function success($data = [], string $message = 'success', int $code = 0): Response
    {
        return json([
            'code' => $code,
            'message' => $message,
            'data' => $data,
        ]);
    }

    /**
     * 失败响应
     */
    protected function fail(string $message = 'fail', int $code = 500, $data = []): Response
    {
        return json([
            'code' => $code,
            'message' => $message,
            'data' => $data,
        ]);
    }

    /**
     * 将模型 ID 编码为 hashid
     */
    protected function encodeId(int $id): string
    {
        return HashidsService::encode($id);
    }

    /**
     * 将 hashid 解码为模型 ID
     */
    protected function decodeId(string $hashid): int
    {
        return HashidsService::decode($hashid);
    }

    /**
     * 将模型中的 id 字段批量编码
     */
    protected function encodeIds(array $data, array $idFields = ['id']): array
    {
        foreach ($idFields as $field) {
            if (isset($data[$field])) {
                $data[$field] = HashidsService::encode($data[$field]);
            }
        }
        return $data;
    }

    /**
     * 生成新的 snowflake ID
     */
    protected function generateId(): int
    {
        return SnowflakeService::generate();
    }
}
```

### CRUD 控制器模板

文件: `app/admin/controller/UserController.php`

```php
<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

namespace app\admin\controller;

use app\model\AdminUser;
use support\Request;

class UserController extends BaseController
{
    /**
     * 用户列表
     * GET /admin/user
     */
    public function index(Request $request): Response
    {
        $page = (int) $request->input('page', 1);
        $limit = (int) $request->input('limit', 15);
        $keyword = $request->input('keyword', '');

        $query = AdminUser::query();

        if ($keyword) {
            $query->where('username', 'like', "%{$keyword}%")
                  ->orWhere('real_name', 'like', "%{$keyword}%");
        }

        $total = $query->count();
        $list = $query->offset(($page - 1) * $limit)
                      ->limit($limit)
                      ->orderBy('id', 'desc')
                      ->get()
                      ->map(function ($user) {
                          // 加密敏感字段
                          $user->phone = EncryptionService::maskPhone($user->phone);
                          $user->email = EncryptionService::maskEmail($user->email);
                          return $this->encodeIds($user->toArray());
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
        ]);

        if ($validator->fails()) {
            return $this->fail($validator->errors()->first(), 422);
        }

        $user = new AdminUser();
        $user->id = $this->generateId();
        $user->username = $request->input('username');
        $user->password = password_hash($request->input('password'), PASSWORD_BCRYPT);
        $user->real_name = $request->input('real_name');
        // 加密存储敏感字段
        $user->phone = EncryptionService::encrypt($request->input('phone', ''));
        $user->email = EncryptionService::encrypt($request->input('email', ''));
        $user->save();

        return $this->success($this->encodeIds($user->toArray()), '创建成功');
    }

    /**
     * 用户详情
     * GET /admin/user/{id}
     * 注意: {id} 为 hashid，需解码
     */
    public function show(Request $request, string $hashid): Response
    {
        $id = $this->decodeId($hashid);
        $user = AdminUser::find($id);

        if (!$user) {
            return $this->fail('用户不存在', 404);
        }

        $data = $user->toArray();
        // 批量编码 ID 字段
        $data = $this->encodeIds($data);
        // 解密敏感字段返回
        $data['phone'] = EncryptionService::decrypt($data['phone']);
        $data['email'] = EncryptionService::decrypt($data['email']);

        return $this->success($data);
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

        if ($request->input('phone') !== null) {
            $user->phone = EncryptionService::encrypt($request->input('phone'));
        }
        if ($request->input('email') !== null) {
            $user->email = EncryptionService::encrypt($request->input('email'));
        }

        $user->save();

        return $this->success($this->encodeIds($user->toArray()), '更新成功');
    }

    /**
     * 删除用户
     * DELETE /admin/user/{id}
     */
    public function destroy(Request $request, string $hashid): Response
    {
        $id = $this->decodeId($hashid);
        $user = AdminUser::find($id);

        if (!$user) {
            return $this->fail('用户不存在', 404);
        }

        $user->delete(); // 软删除

        return $this->success([], '删除成功');
    }
}
```

## 路由配置

文件: `config/route.php`

```php
<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */
use Webman\Route;

// 管理端路由，需要 JWT 认证 + 权限检查
Route::group('/admin', function () {
    Route::resource('/user', app\admin\controller\UserController::class);
    Route::resource('/role', app\admin\controller\RoleController::class);
    Route::resource('/permission', app\admin\controller\PermissionController::class);
    Route::get('/dashboard', [app\admin\controller\DashboardController::class, 'index']);
    Route::post('/export/excel', [app\admin\controller\ExportController::class, 'excel']);
    Route::post('/export/pdf', [app\admin\controller\ExportController::class, 'pdf']);
})->middleware([
    app\middleware\AdminAuth::class,
    app\middleware\AdminPermission::class,
]);

// 客户端 API 路由，需要 JWT 认证
Route::group('/api', function () {
    Route::post('/auth/login', [app\api\controller\AuthController::class, 'login']);
    Route::post('/auth/refresh', [app\api\controller\AuthController::class, 'refresh']);
})->middleware([
    app\middleware\ApiAuth::class,
]);
```

## 关键规范

1. 所有控制器文件必须包含版权声明
2. API 层所有 ID 必须通过 hashids 加解密
3. 数据库 ID 使用 snowflake 生成，禁止自增
4. 敏感字段存入数据库前加密，返回客户端前解密
5. 全局函数/类使用 `use` 导入，不使用前置 `\`
6. 路由中 ID 参数接收 hashid 字符串，控制器内部解码
