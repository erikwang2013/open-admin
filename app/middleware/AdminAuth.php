<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace app\middleware;

use app\model\AdminUser;
use app\common\SessionService;
use support\Request;
use Webman\Http\Response;
use support\Redis;
use Erikwang2013\Jwt\JWT;
use Erikwang2013\Jwt\JWTFactory;
use Erikwang2013\Jwt\JWTException;

class AdminAuth
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

    public function process(Request $request, callable $next): Response
    {
        $header = $request->header('Authorization', '');
        $token  = preg_replace('/^Bearer\s+/i', '', trim($header));

        if (empty($token)) {
            return json(['code' => 401, 'message' => '未登录', 'data' => []])->withStatus(401);
        }

        // 检查 JWT 黑名单
        $blacklistKey = 'jwt_blacklist:' . md5($token);
        try {
            if (Redis::get($blacklistKey)) {
                return json(['code' => 401, 'message' => 'Token已失效，请重新登录', 'data' => []])->withStatus(401);
            }
        } catch (\Throwable $e) {
            // Redis 故障时无法确认令牌是否已吊销，失败关闭
            return json(['code' => 503, 'message' => '服务暂时不可用，请稍后再试', 'data' => []])->withStatus(503);
        }

        try {
            $payload = self::getJWT()->decode($token);
            // 仅接受 access token（旧令牌无 token_type 字段，按 access 处理）
            if (($payload['token_type'] ?? 'access') !== 'access') {
                return json(['code' => 401, 'message' => 'Token已过期或无效', 'data' => []])->withStatus(401);
            }
        } catch (JWTException | \Exception $e) {
            return json(['code' => 401, 'message' => 'Token已过期或无效', 'data' => []])->withStatus(401);
        }

        $adminId = (int) ($payload['sub'] ?? 0);

        // 账号被删除或禁用后，已签发但未过期的 access token 必须立即失效
        try {
            $user = AdminUser::find($adminId);
        } catch (\Throwable $e) {
            return json(['code' => 503, 'message' => '服务暂时不可用，请稍后再试', 'data' => []])->withStatus(503);
        }
        if (!$user || (int) $user->status !== 1) {
            return json(['code' => 401, 'message' => '账号不存在或已被禁用', 'data' => []])->withStatus(401);
        }

        // 改密/强制下线水位线（毫秒级）：签发早于水位线的 access token 一律拒绝
        try {
            $revoked = SessionService::isTokenRevoked($payload, $adminId);
        } catch (\Throwable $e) {
            return json(['code' => 503, 'message' => '服务暂时不可用，请稍后再试', 'data' => []])->withStatus(503);
        }
        if ($revoked) {
            return json(['code' => 401, 'message' => 'Token已失效，请重新登录', 'data' => []])->withStatus(401);
        }

        $request->adminId = $adminId;
        $request->adminUsername = $payload['username'] ?? '';

        return $next($request);
    }
}
