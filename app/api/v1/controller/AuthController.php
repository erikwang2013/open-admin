<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace app\api\v1\controller;

use app\model\AdminUser;
use app\common\SnowflakeService;
use app\common\EncryptionService;
use support\Container;
use support\Request;
use support\Response;
use Erikwang2013\Jwt\JWT;
use Erikwang2013\Jwt\JWTFactory;
use Throwable;

class AuthController
{
    private static ?JWT $jwt = null;

    private static function getJWT(): JWT
    {
        if (self::$jwt === null) {
            $config = config('plugin.erikwang2013.jwt.jwt', []);
            self::$jwt = JWTFactory::createFromConfig($config);
        }
        return self::$jwt;
    }

    /**
     * 登录（需先通过点击验证码）
     * POST /api/auth/login
     */
    public function login(Request $request): Response
    {
        $validator = validator($request->all(), [
            'username'    => 'required|string|min:3|max:50',
            'password'    => 'required|string|min:6|max:32',
            'captcha_key' => 'required|string',
            'clicks'      => 'required|array|min:2',
        ]);

        if ($validator->fails()) {
            return json(['code' => 422, 'message' => $validator->errors()->first(), 'data' => []]);
        }

        // 验证点击验证码
        if (!captcha_verify($request->input('captcha_key'), 'click', $request->input('clicks'))) {
            return json(['code' => 422, 'message' => '验证码错误，请重试', 'data' => []]);
        }

        // 校验用户凭证
        $username = $request->input('username');
        $user = AdminUser::where('username', $username)->first();

        if (!$user || !password_verify($request->input('password'), $user->password)) {
            return json(['code' => 401, 'message' => '用户名或密码错误', 'data' => []]);
        }

        if ($user->status === 0) {
            return json(['code' => 403, 'message' => '账号已被禁用', 'data' => []]);
        }

        // 签发 JWT
        $jwt = self::getJWT();
        $token = $jwt->encode(['sub' => $user->id, 'username' => $user->username]);
        $refreshToken = $jwt->encode(['sub' => $user->id, 'token_type' => 'refresh'],
            (int)(config('plugin.erikwang2013.jwt.jwt.refresh_expire') ?: 1209600)
        );

        // 更新登录信息
        $user->last_login_at = date('Y-m-d H:i:s');
        $user->last_login_ip = $request->getRealIp();
        $user->save();

        return json([
            'code'    => 0,
            'message' => '登录成功',
            'data'    => [
                'access_token'  => $token,
                'refresh_token' => $refreshToken,
                'expires_in'    => (int)(config('plugin.erikwang2013.jwt.jwt.default_expire') ?: 7200),
                'user'          => [
                    'id'        => Container::get('hashids')->encode($user->id),
                    'username'  => $user->username,
                    'real_name' => $user->real_name,
                ],
            ],
        ]);
    }

    /**
     * 注册（需先通过点击验证码）
     * POST /api/auth/register
     */
    public function register(Request $request): Response
    {
        $validator = validator($request->all(), [
            'username'    => 'required|string|min:3|max:50',
            'password'    => 'required|string|min:6|max:32',
            'real_name'   => 'required|string|max:50',
            'captcha_key' => 'required|string',
            'clicks'      => 'required|array|min:2',
        ]);

        if ($validator->fails()) {
            return json(['code' => 422, 'message' => $validator->errors()->first(), 'data' => []]);
        }

        if (!captcha_verify($request->input('captcha_key'), 'click', $request->input('clicks'))) {
            return json(['code' => 422, 'message' => '验证码错误，请重试', 'data' => []]);
        }

        $username = $request->input('username');
        if (AdminUser::where('username', $username)->exists()) {
            return json(['code' => 422, 'message' => '用户名已存在', 'data' => []]);
        }

        $user = new AdminUser();
        $user->id = SnowflakeService::generate();
        $user->username = $username;
        $user->password = password_hash($request->input('password'), PASSWORD_BCRYPT);
        $user->real_name = $request->input('real_name');
        $user->phone = EncryptionService::encrypt($request->input('phone', ''));
        $user->email = EncryptionService::encrypt($request->input('email', ''));
        $user->status = 1;
        $user->save();

        $jwt = self::getJWT();
        $token = $jwt->encode(['sub' => $user->id, 'username' => $user->username]);
        $refreshToken = $jwt->encode(['sub' => $user->id, 'token_type' => 'refresh'],
            (int)(config('plugin.erikwang2013.jwt.jwt.refresh_expire') ?: 1209600)
        );

        return json([
            'code'    => 0,
            'message' => '注册成功',
            'data'    => [
                'access_token'  => $token,
                'refresh_token' => $refreshToken,
                'expires_in'    => (int)(config('plugin.erikwang2013.jwt.jwt.default_expire') ?: 7200),
                'user'          => [
                    'id'        => Container::get('hashids')->encode($user->id),
                    'username'  => $user->username,
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
            $jwt = self::getJWT();
            $payload = $jwt->decode($refreshToken);

            $token = $jwt->encode(['sub' => $payload['sub'], 'username' => $payload['username'] ?? '']);
            $newRefresh = $jwt->encode(['sub' => $payload['sub'], 'token_type' => 'refresh'],
                (int)(config('plugin.erikwang2013.jwt.jwt.refresh_expire') ?: 1209600)
            );

            return json([
                'code'    => 0,
                'message' => 'success',
                'data'    => [
                    'access_token'  => $token,
                    'refresh_token' => $newRefresh,
                    'expires_in'    => (int)(config('plugin.erikwang2013.jwt.jwt.default_expire') ?: 7200),
                ],
            ]);
        } catch (Throwable $e) {
            return json(['code' => 401, 'message' => '刷新令牌无效或已过期', 'data' => []]);
        }
    }
}
