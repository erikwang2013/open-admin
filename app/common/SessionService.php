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
     * 先写改密水位线，未登记过的旧 token 也会因 iat 早于水位线被拒绝；
     * 再按剩余 TTL 将已登记的 access/refresh token 加入黑名单并删除登记集合；
     * 最后吊销轮换缓存并清理权限缓存。
     *
     * 任一核心 Redis 操作失败即返回 false：调用方必须在写库前中止，不能带着未吊销的会话完成敏感变更。
     */
    public static function revokeUser(int $userId): bool
    {
        try {
            $refreshExpire = (int) (config('plugin.erikwang2013.jwt.jwt.refresh_expire') ?: 1209600);
            // 毫秒水位线避免同一秒内签发/吊销的顺序歧义
            Redis::setex("user_pwd_changed:{$userId}", $refreshExpire + 60, (string) self::nowMs());

            foreach (['user_tokens', 'user_refresh_tokens'] as $set) {
                $key = "{$set}:{$userId}";
                foreach (Redis::zrange($key, 0, -1, true) ?: [] as $member => $exp) {
                    Redis::setex('jwt_blacklist:' . $member, max((int) $exp - time(), 1), '1');
                }
                Redis::del($key);
            }

            // 轮换缓存中的令牌一并吊销，防止旧 refresh token 经 60 秒宽限换回已吊销的新令牌
            self::deleteRotationCaches($userId, null);
            self::deletePermissionCache($userId);
        } catch (\Throwable $e) {
            Log::error("会话吊销失败 user={$userId}: " . $e->getMessage());
            return false;
        }

        return true;
    }

    /**
     * 吊销用户所有轮换缓存中的令牌并删除缓存（登出/改密时调用）
     *
     * 登出路径的最佳努力版本：Redis 故障只记日志，不影响登出响应。
     *
     * @param int $userId 用户 ID；0 表示仅处理 $extraKey
     * @param string|null $extraKey 额外的轮换缓存键（本次登出的 refresh token）
     */
    public static function revokeRotationCaches(int $userId, ?string $extraKey = null): void
    {
        try {
            self::deleteRotationCaches($userId, $extraKey);
        } catch (\Throwable $e) {
            Log::error("轮换缓存吊销失败 user={$userId}: " . $e->getMessage());
        }
    }

    /**
     * 清理权限缓存（角色变更后调用，避免 60 秒内仍按旧角色鉴权）
     *
     * 权限缓存本身读取失败时会降级查库，故此处最佳努力即可。
     */
    public static function clearPermissionCache(int $id): void
    {
        try {
            self::deletePermissionCache($id);
        } catch (\Throwable $e) {
            Log::error("权限缓存清理失败 user={$id}: " . $e->getMessage());
        }
    }

    /**
     * 轮换缓存吊销的严格版本（异常向上抛出，供 revokeUser 判定失败）
     */
    private static function deleteRotationCaches(int $userId, ?string $extraKey): void
    {
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
    }

    /**
     * 判断 JWT 签发时间是否早于该用户的改密/强制下线水位线
     * 兼容旧版秒级水位线与缺少 iat_ms 的旧令牌；Redis 异常向上抛出由调用方 fail-closed。
     *
     * @param array<string, mixed> $payload
     */
    public static function isTokenRevoked(array $payload, int $userId): bool
    {
        $changedAt = Redis::get("user_pwd_changed:{$userId}");
        if ($changedAt === null || $changedAt === false || $changedAt === '') {
            return false;
        }

        $changedAt = (int) $changedAt;
        // 1e11 秒约等于公元 5138 年；小于该值视为旧版秒级水位线
        $changedAtMs = $changedAt < 100_000_000_000 ? $changedAt * 1000 : $changedAt;
        $issuedAtMs  = isset($payload['iat_ms'])
            ? (int) $payload['iat_ms']
            : (int) ($payload['iat'] ?? 0) * 1000;

        return $issuedAtMs <= $changedAtMs;
    }

    private static function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    /**
     * 删除当前权限缓存版本下的用户缓存（未设置 perm_version 时按版本 0）
     */
    private static function deletePermissionCache(int $id): void
    {
        $version = (int) (Redis::get('perm_version') ?: 0);
        Redis::del("perm:{$version}:{$id}");
    }
}
