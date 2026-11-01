<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace tests;

use app\common\SessionService;
use PHPUnit\Framework\TestCase;
use support\Redis;

final class SessionServiceTest extends TestCase
{
    /** @var string[] 本测试创建、需在 tearDown 清理的 Redis 键 */
    private array $keys = [];

    private int $userId = 0;

    public static function setUpBeforeClass(): void
    {
        try {
            Redis::ping();
        } catch (\RedisException | \RuntimeException $e) {
            self::markTestSkipped('Redis 不可用: ' . $e->getMessage());
        }
    }

    protected function setUp(): void
    {
        // 随机用户 ID，避免与真实会话键冲突
        $this->userId = random_int(1_000_000_000, 2_000_000_000);
    }

    protected function tearDown(): void
    {
        foreach ($this->keys as $key) {
            try {
                Redis::del($key);
            } catch (\Throwable) {
                // Redis 中途不可用时忽略清理失败
            }
        }
        $this->keys = [];
    }

    private function track(string $key): string
    {
        $this->keys[] = $key;

        return $key;
    }

    private function key(string $prefix): string
    {
        return $this->track($prefix . ':phpunit:' . bin2hex(random_bytes(8)));
    }

    public function testRevokeUserClearsSessionsRotationAndPermissionCache(): void
    {
        $id      = $this->userId;
        $access  = 'access-' . bin2hex(random_bytes(8));
        $refresh = 'refresh-' . bin2hex(random_bytes(8));

        $tokensKey   = $this->track("user_tokens:{$id}");
        $refreshKey  = $this->track("user_refresh_tokens:{$id}");
        $rotationSet = $this->track("user_refresh_rotations:{$id}");
        $permVersion = (int) (Redis::get('perm_version') ?: 0);
        $permKey     = $this->track("perm:{$permVersion}:{$id}");
        $watermark   = $this->track("user_pwd_changed:{$id}");
        $rotationKey = $this->key('refresh_rotation');
        $accessBlacklist  = $this->track('jwt_blacklist:' . md5($access));
        $refreshBlacklist = $this->track('jwt_blacklist:' . md5($refresh));

        Redis::zadd($tokensKey, time() + 3600, md5($access));
        Redis::zadd($refreshKey, time() + 7200, md5($refresh));
        Redis::sadd($rotationSet, $rotationKey);
        // 无法解析的轮换缓存也应被删除
        Redis::setex($rotationKey, 60, 'not-json');
        Redis::setex($permKey, 60, '{}');

        $before = time();
        self::assertTrue(SessionService::revokeUser($id));

        // 改密水位线：未登记过的旧 refresh token 也会因 iat 早于水位线被拒绝
        self::assertSame(1, (int) Redis::exists($watermark));
        self::assertGreaterThanOrEqual($before, (int) Redis::get($watermark));

        // access/refresh 登记成员按剩余 TTL 写入黑名单
        self::assertSame(1, (int) Redis::exists($accessBlacklist));
        self::assertSame(1, (int) Redis::exists($refreshBlacklist));

        // 登记集合、轮换缓存与轮换索引、权限缓存全部删除
        self::assertSame(0, (int) Redis::exists($tokensKey));
        self::assertSame(0, (int) Redis::exists($refreshKey));
        self::assertSame(0, (int) Redis::exists($rotationKey));
        self::assertSame(0, (int) Redis::exists($rotationSet));
        self::assertSame(0, (int) Redis::exists($permKey));
    }

    public function testMillisecondWatermarkRejectsOnlyEarlierTokens(): void
    {
        $id = $this->userId;
        $key = $this->track("user_pwd_changed:{$id}");
        $watermark = (int) floor(microtime(true) * 1000);
        Redis::setex($key, 60, (string) $watermark);

        self::assertTrue(SessionService::isTokenRevoked(['iat_ms' => $watermark], $id));
        self::assertFalse(SessionService::isTokenRevoked(['iat_ms' => $watermark + 1], $id));

        // 兼容升级前写入的秒级水位线与无 iat_ms 的旧令牌
        Redis::setex($key, 60, (string) time());
        self::assertTrue(SessionService::isTokenRevoked(['iat' => time()], $id));
    }
}
