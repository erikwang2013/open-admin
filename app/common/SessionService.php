<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace app\common;

use Erikwang2013\Jwt\JWT;
use Erikwang2013\Jwt\JWTFactory;
use support\Log;
use support\Redis;

/**
 * 会话吊销服务
 * 集中处理 access/refresh token 黑名单、改密水位线与权限缓存清理
 */
class SessionService
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
     * 吊销用户全部在线会话（改密/禁用/删除时调用）
     *
     * 先写改密水位线，未登记过的旧 refresh token 也会因 iat 早于水位线被拒绝；
     * 再按剩余 TTL 将已登记的 access/refresh token 加入黑名单并删除登记集合；
     * 最后吊销轮换缓存并清理权限缓存。
     */
    public static function revokeUser(int $userId): void
    {
        try {
            $refreshExpire = (int) (config('plugin.erikwang2013.jwt.jwt.refresh_expire') ?: 1209600);
            Redis::setex("user_pwd_changed:{$userId}", $refreshExpire + 60, (string) time());

            foreach (['user_tokens', 'user_refresh_tokens'] as $set) {
                $key = "{$set}:{$userId}";
                foreach (Redis::zrange($key, 0, -1, true) ?: [] as $member => $exp) {
                    Redis::setex('jwt_blacklist:' . $member, max((int) $exp - time(), 1), '1');
                }
                Redis::del($key);
            }

            // 轮换缓存中的令牌一并吊销，防止旧 refresh token 经 60 秒宽限换回已吊销的新令牌
            self::revokeRotationCaches($userId);
        } catch (\Throwable $e) {
            // Redis 故障不影响调用方已完成的数据库写入
            Log::error("会话吊销失败 user={$userId}: " . $e->getMessage());
        }

        // 即使上面的批量吊销中途失败，也要让权限缓存失效
        self::clearPermissionCache($userId);
    }

    /**
     * 吊销用户所有轮换缓存中的令牌并删除缓存（登出/改密时调用）
     *
     * @param int $userId 用户 ID；0 表示仅处理 $extraKey
     * @param string|null $extraKey 额外的轮换缓存键（本次登出的 refresh token）
     */
    public static function revokeRotationCaches(int $userId, ?string $extraKey = null): void
    {
        try {
            $jwt    = self::getJWT();
            $setKey = "user_refresh_rotations:{$userId}";
            $keys   = $userId > 0 ? (Redis::smembers($setKey) ?: []) : [];
            if ($extraKey !== null) {
                $keys[] = $extraKey;
            }

            foreach (array_unique($keys) as $rotationKey) {
                $cached = Redis::get($rotationKey);
                if ($cached) {
                    $pair = json_decode((string) $cached, true);
                    if (is_array($pair)) {
                        foreach (['access_token' => false, 'refresh_token' => true] as $field => $allowRefresh) {
                            if (empty($pair[$field]) || !is_string($pair[$field])) {
                                continue;
                            }
                            try {
                                $pairPayload = $jwt->decode($pair[$field], $allowRefresh);
                                $pairTtl     = max((int) ($pairPayload['exp'] ?? 0) - time(), 1);
                            } catch (\Throwable $e) {
                                $pairTtl = 60; // 无法解析时按最短窗口吊销，避免漏网
                            }
                            Redis::setex('jwt_blacklist:' . md5($pair[$field]), $pairTtl, '1');
                        }
                    }
                }
                Redis::del($rotationKey);
            }

            if ($userId > 0) {
                Redis::del($setKey);
            }
        } catch (\Throwable $e) {
            // 登出/改密不应因 Redis 故障失败
            Log::error("轮换缓存吊销失败 user={$userId}: " . $e->getMessage());
        }
    }

    /**
     * 清理权限缓存（角色变更后调用，避免 60 秒内仍按旧角色鉴权）
     */
    public static function clearPermissionCache(int $id): void
    {
        try {
            Redis::del("perm:{$id}");
        } catch (\Throwable $e) {
            // 权限缓存读取本身也会降级查库，Redis 故障时无需中断请求
            Log::error("权限缓存清理失败 user={$id}: " . $e->getMessage());
        }
    }
}
