<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace tests;

use Erikwang2013\Jwt\JWT;
use Erikwang2013\Jwt\JWTException;
use Erikwang2013\Jwt\JWTFactory;
use PHPUnit\Framework\TestCase;

final class AuthTokenTest extends TestCase
{
    private static ?JWT $jwt = null;

    private static function jwt(): JWT
    {
        return self::$jwt ??= JWTFactory::createFromConfig(config('plugin.erikwang2013.jwt.jwt', []));
    }

    public function testAccessTokenCarriesAccessType(): void
    {
        $token = self::jwt()->encode(['sub' => '1', 'token_type' => 'access']);
        $payload = self::jwt()->decode($token);

        self::assertSame('access', $payload['token_type']);
        self::assertSame('1', $payload['sub']);
    }

    public function testRefreshTokenCarriesRefreshType(): void
    {
        $token = self::jwt()->encode(['sub' => '1', 'token_type' => 'refresh']);
        $payload = self::jwt()->decode($token, true);

        self::assertSame('refresh', $payload['token_type']);
    }

    public function testRefreshTokenRejectedAsAccessToken(): void
    {
        $token = self::jwt()->encode(['sub' => '1', 'token_type' => 'refresh']);

        $this->expectException(JWTException::class);
        self::jwt()->decode($token);
    }

    public function testLegacyTokenWithoutTypeIsTreatedAsAccess(): void
    {
        $token = self::jwt()->encode(['sub' => '1']);
        $payload = self::jwt()->decode($token);

        self::assertArrayNotHasKey('token_type', $payload);
        // 与 AdminAuth 一致：无 token_type 的历史令牌按 access 处理
        self::assertSame('access', $payload['token_type'] ?? 'access');
    }
}
