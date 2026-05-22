<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace app\admin\controller;

use app\model\AdminUser;
use support\Request;
use support\Response;
use support\Redis;
use Erikwang2013\Jwt\JWT;
use Erikwang2013\Jwt\JWTFactory;

class ProfileController extends BaseController
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

    public function updateProfile(Request $request): Response
    {
        $adminId = $request->adminId ?? 0;
        $user    = AdminUser::find($adminId);
        if (!$user) {
            return $this->fail(trans('messages.user_not_found'), 404);
        }

        if ($request->has('real_name')) {
            $user->real_name = $request->input('real_name');
        }
        if ($request->has('phone')) {
            $user->phone = $request->input('phone', '');
        }
        if ($request->has('email')) {
            $user->email = $request->input('email', '');
        }

        $user->save();

        $data = $user->toArray();
        unset($data['password'], $data['id_card']);
        // phone/email 由 Encryptable cast 自动加解密，无需额外处理

        return $this->success($this->encodeIds($data), trans('messages.update_success'));
    }

    public function updatePassword(Request $request): Response
    {
        $adminId = $request->adminId ?? 0;
        $user    = AdminUser::find($adminId);
        if (!$user) {
            return $this->fail(trans('messages.user_not_found'), 404);
        }

        $oldPassword = $request->input('old_password', '');
        $newPassword = $request->input('new_password', '');

        if (empty($oldPassword) || empty($newPassword)) {
            return $this->fail(trans('messages.password_required'), 422);
        }

        if (!password_verify($oldPassword, $user->password)) {
            return $this->fail(trans('messages.old_password_wrong'), 422);
        }

        if (strlen($newPassword) < 6 || strlen($newPassword) > 32) {
            return $this->fail(trans('messages.password_too_short'), 422);
        }

        $user->password = password_hash($newPassword, PASSWORD_BCRYPT);
        $user->save();

        return $this->success([], trans('messages.password_changed'));
    }

    public function logout(Request $request): Response
    {
        $token = $request->header('Authorization', '');
        $token = str_replace('Bearer ', '', $token);

        if (empty($token)) {
            return $this->fail(trans('messages.not_logged_in'), 401);
        }

        try {
            $payload = self::getJWT()->decode($token);
            $ttl     = max((int)($payload['exp'] ?? 0) - time(), 0);
            Redis::setex('jwt_blacklist:' . md5($token), $ttl, '1');
        } catch (\Throwable $e) {
            // token 无效也视为登出成功
        }

        return $this->success([], trans('messages.logout_success'));
    }
}
