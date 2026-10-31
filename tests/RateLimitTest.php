<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace tests;

use app\middleware\RateLimit;
use PHPUnit\Framework\TestCase;

final class RateLimitTest extends TestCase
{
    public function testLoginPathGetsSensitiveLimit(): void
    {
        self::assertSame(
            ['limit' => 10, 'window' => 60],
            (new RateLimit())->limitForPath('/api/v1/auth/login')
        );
    }

    public function testUnknownPathGetsDefaultLimit(): void
    {
        self::assertSame(
            ['limit' => 60, 'window' => 60],
            (new RateLimit())->limitForPath('/api/v1/auth/refresh')
        );
    }

    public function testSensitivePrefixMatchesSubPathsOnly(): void
    {
        $rateLimit = new RateLimit();

        self::assertSame(['limit' => 10, 'window' => 60], $rateLimit->limitForPath('/api/v1/auth/login/'));
        self::assertSame(['limit' => 10, 'window' => 60], $rateLimit->limitForPath('/api/v1/auth/login/extra'));
        // 同前缀但非子路径，不应命中敏感限流
        self::assertSame(['limit' => 60, 'window' => 60], $rateLimit->limitForPath('/api/v1/auth/login-other'));
    }
}
