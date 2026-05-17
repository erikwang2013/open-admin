<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace app\api\controller;

use app\model\AdminUser;
use app\common\SnowflakeService;
use app\common\EncryptionService;
use support\Request;
use support\Response;
use Throwable;

use function captcha_verify;
use function jwt;
use function hashids;

class AuthController
{
    /**
     * 登录（需先通过点击验证码）
     * POST /api/auth/login
     *
     * 请求: { username, password, captcha_key, clicks: [{x,y}, ...] }
     */
    public function login(Request $request): Response
    {
        $validator = validator($request->all(), [
            'username' => 'required|string|min:3|max:50',
            'password' => 'required|string|min:6|max:32',
            'captcha_key' => 'required|string',
            'clicks' => 'required|array|min:2',
        ]);

        if ($validator->fails()) {
            return json(['code' => 422, 'message' => $validator->errors()->first(), 'data' => []]);
        }

        // 第一步: 验证点击验证码
        $captchaKey = $request->input('captcha_key');
        $clicks = $request->input('clicks');

        if (!captcha_verify($captchaKey, 'click', $clicks)) {
            return json(['code' => 422, 'message' => '验证码错误，请重试', 'data' => []]);
        }

        // 第二步: 校验用户凭证
        $username = $request->input('username');
        $user = AdminUser::where('username', $username)->first();

        if (!$user || !password_verify($request->input('password'), $user->password)) {
            return json(['code' => 401, 'message' => '用户名或密码错误', 'data' => []]);
        }

        if ($user->status === 0) {
            return json(['code' => 403, 'message' => '账号已被禁用', 'data' => []]);
        }

        // 第三步: 签发 JWT
        $token = jwt()->create([
            'sub' => $user->id,
            'username' => $user->username,
        ]);

        $refreshToken = jwt()->refresh();

        // 更新登录信息
        $user->last_login_at = date('Y-m-d H:i:s');
        $user->last_login_ip = $request->getRealIp();
        $user->save();

        return json([
            'code' => 0,
            'message' => '登录成功',
            'data' => [
                'access_token' => $token,
                'refresh_token' => $refreshToken,
                'expires_in' => 7200,
                'user' => [
                    'id' => hashids()->encode($user->id),
                    'username' => $user->username,
                    'real_name' => $user->real_name,
                ],
            ],
        ]);
    }

    /**
     * 注册（需先通过点击验证码）
     * POST /api/auth/register
     *
     * 请求: { username, password, real_name, captcha_key, clicks: [{x,y}, ...] }
     */
    public function register(Request $request): Response
    {
        $validator = validator($request->all(), [
            'username' => 'required|string|min:3|max:50',
            'password' => 'required|string|min:6|max:32',
            'real_name' => 'required|string|max:50',
            'captcha_key' => 'required|string',
            'clicks' => 'required|array|min:2',
        ]);

        if ($validator->fails()) {
            return json(['code' => 422, 'message' => $validator->errors()->first(), 'data' => []]);
        }

        // 第一步: 验证点击验证码
        $captchaKey = $request->input('captcha_key');
        $clicks = $request->input('clicks');

        if (!captcha_verify($captchaKey, 'click', $clicks)) {
            return json(['code' => 422, 'message' => '验证码错误，请重试', 'data' => []]);
        }

        // 第二步: 检查用户名唯一性
        $username = $request->input('username');
        if (AdminUser::where('username', $username)->exists()) {
            return json(['code' => 422, 'message' => '用户名已存在', 'data' => []]);
        }

        // 第三步: 创建用户
        $user = new AdminUser();
        $user->id = SnowflakeService::generate();
        $user->username = $username;
        $user->password = password_hash($request->input('password'), PASSWORD_BCRYPT);
        $user->real_name = $request->input('real_name');
        $user->phone = EncryptionService::encrypt($request->input('phone', ''));
        $user->email = EncryptionService::encrypt($request->input('email', ''));
        $user->status = 1;
        $user->save();

        // 第四步: 签发 JWT
        $token = jwt()->create([
            'sub' => $user->id,
            'username' => $user->username,
        ]);
        $refreshToken = jwt()->refresh();

        return json([
            'code' => 0,
            'message' => '注册成功',
            'data' => [
                'access_token' => $token,
                'refresh_token' => $refreshToken,
                'expires_in' => 7200,
                'user' => [
                    'id' => hashids()->encode($user->id),
                    'username' => $user->username,
                    'real_name' => $user->real_name,
                ],
            ],
        ]);
    }

    /**
     * 刷新令牌
     * POST /api/auth/refresh
     */
    public function refresh(Request $request): Response
    {
        $refreshToken = $request->input('refresh_token', '');

        if (empty($refreshToken)) {
            return json(['code' => 422, 'message' => '缺少刷新令牌', 'data' => []]);
        }

        try {
            $token = jwt()->refresh($refreshToken);
            $newRefreshToken = jwt()->refresh();

            return json([
                'code' => 0,
                'message' => 'success',
                'data' => [
                    'access_token' => $token,
                    'refresh_token' => $newRefreshToken,
                    'expires_in' => 7200,
                ],
            ]);
        } catch (Throwable $e) {
            return json(['code' => 401, 'message' => '刷新令牌无效或已过期', 'data' => []]);
        }
    }
}
