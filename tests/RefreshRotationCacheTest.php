<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace tests;

use app\api\v1\controller\AuthController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use support\Redis;

final class RefreshRotationCacheTest extends TestCase
{
    /** @var string[] 本测试创建、需在 tearDown 清理的 Redis 键 */
    private array $keys = [];

    public static function setUpBeforeClass(): void
    {
        try {
            Redis::ping();
        } catch (\RedisException | \RuntimeException $e) {
            self::markTestSkipped('Redis 不可用: ' . $e->getMessage());
        }
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

    /**
     * @return array|false|null
     */
    private static function cachedRotationPair(string $rotationKey): array|false|null
    {
        // PHP >= 8.1（项目最低版本）反射可直接调用私有方法，setAccessible() 已是废弃的空操作
        $method = new ReflectionMethod(AuthController::class, 'cachedRotationPair');

        return $method->invoke(null, $rotationKey);
    }

    private function newRotationKey(): string
    {
        $key = 'refresh_rotation:phpunit:' . bin2hex(random_bytes(8));
        $this->keys[] = $key;

        return $key;
    }

    private function storePair(string $rotationKey, string $accessToken, string $refreshToken): void
    {
        Redis::setex($rotationKey, 60, json_encode([
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in'    => 7200,
        ]));
    }

    private function blacklist(string $token): void
    {
        $key = 'jwt_blacklist:' . md5($token);
        $this->keys[] = $key;
        Redis::setex($key, 60, '1');
    }

    public function testAbsentCacheReturnsNull(): void
    {
        self::assertNull(self::cachedRotationPair($this->newRotationKey()));
    }

    public function testValidCachedPairIsReturned(): void
    {
        $rotationKey = $this->newRotationKey();
        $accessToken = 'access-' . bin2hex(random_bytes(8));
        $refreshToken = 'refresh-' . bin2hex(random_bytes(8));
        $this->storePair($rotationKey, $accessToken, $refreshToken);

        self::assertSame(
            [
                'access_token'  => $accessToken,
                'refresh_token' => $refreshToken,
                'expires_in'    => 7200,
            ],
            self::cachedRotationPair($rotationKey)
        );
    }

    public function testBlacklistedAccessTokenInvalidatesAndDeletesCache(): void
    {
        $rotationKey = $this->newRotationKey();
        $accessToken = 'access-' . bin2hex(random_bytes(8));
        $this->storePair($rotationKey, $accessToken, 'refresh-' . bin2hex(random_bytes(8)));
        $this->blacklist($accessToken);

        self::assertFalse(self::cachedRotationPair($rotationKey));
        self::assertSame(0, (int) Redis::exists($rotationKey));
    }

    public function testBlacklistedRefreshTokenInvalidatesCache(): void
    {
        $rotationKey = $this->newRotationKey();
        $refreshToken = 'refresh-' . bin2hex(random_bytes(8));
        $this->storePair($rotationKey, 'access-' . bin2hex(random_bytes(8)), $refreshToken);
        $this->blacklist($refreshToken);

        self::assertFalse(self::cachedRotationPair($rotationKey));
        self::assertSame(0, (int) Redis::exists($rotationKey));
    }
}
