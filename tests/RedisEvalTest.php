<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace tests;

use PHPUnit\Framework\TestCase;
use support\Redis;

final class RedisEvalTest extends TestCase
{
    public function testEvalPrefixesDeclaredKeys(): void
    {
        $key = 'phpunit:eval:' . bin2hex(random_bytes(6));
        try {
            $result = Redis::eval(
                "redis.call('set', KEYS[1], ARGV[1]); return redis.call('get', KEYS[1])",
                [$key, 'ok'],
                1
            );
        } catch (\RedisException | \RuntimeException $e) {
            self::markTestSkipped('Redis 不可用: ' . $e->getMessage());
        }

        try {
            self::assertSame('ok', $result);
            // 若 eval 未按 num_keys 给 KEYS[1] 加前缀，这里读到 null
            self::assertSame('ok', Redis::get($key));
        } finally {
            Redis::del($key);
        }
    }
}
