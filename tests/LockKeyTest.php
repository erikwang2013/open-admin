<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace tests;

use app\api\v1\controller\AuthController;
use PHPUnit\Framework\TestCase;

final class LockKeyTest extends TestCase
{
    public function testKnownUserKeysIncludeUserId(): void
    {
        self::assertSame(
            ['account_lock:u:42', 'login_fail:u:42'],
            AuthController::lockKeys('x', 42)
        );
    }

    public function testUserIdPathIgnoresUsernameCaseAndWhitespace(): void
    {
        $expected = ['account_lock:u:42', 'login_fail:u:42'];

        self::assertSame($expected, AuthController::lockKeys('  Alice ', 42));
        self::assertSame($expected, AuthController::lockKeys('ALICE', 42));
        self::assertSame($expected, AuthController::lockKeys('alice', 42));
    }

    public function testUnknownUsernameIsNormalized(): void
    {
        $hash = md5('alice');

        self::assertSame(
            ["account_lock:n:{$hash}", "login_fail:n:{$hash}"],
            AuthController::lockKeys('  Alice ')
        );
    }

    public function testUnknownUsernameCaseVariantsCollide(): void
    {
        $keys = AuthController::lockKeys('alice');

        self::assertSame($keys, AuthController::lockKeys('ALICE'));
        self::assertSame($keys, AuthController::lockKeys('  Alice  '));
    }

    public function testKnownAndUnknownUsersUseDifferentNamespaces(): void
    {
        $known   = AuthController::lockKeys('alice', 42);
        $unknown = AuthController::lockKeys('alice');

        self::assertStringStartsWith('account_lock:u:', $known[0]);
        self::assertStringStartsWith('account_lock:n:', $unknown[0]);

        foreach (['account_lock', 'login_fail'] as $index => $namespace) {
            self::assertStringStartsWith($namespace . ':u:', $known[$index]);
            self::assertStringStartsWith($namespace . ':n:', $unknown[$index]);
        }

        self::assertEmpty(array_intersect($known, $unknown));
    }
}
