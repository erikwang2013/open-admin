<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace tests;

use app\common\EncryptionService;
use PHPUnit\Framework\TestCase;

final class EncryptionServiceTest extends TestCase
{
    public function testMaskPhone(): void
    {
        self::assertSame('138****8000', EncryptionService::maskPhone('13800138000'));
        self::assertSame('123456', EncryptionService::maskPhone('123456'));
        self::assertSame('', EncryptionService::maskPhone(''));
    }

    public function testMaskEmail(): void
    {
        self::assertSame('a***@example.com', EncryptionService::maskEmail('alice@example.com'));
        self::assertSame('a**@example.com', EncryptionService::maskEmail('ab@example.com'));
        self::assertSame('a**@example.com', EncryptionService::maskEmail('a@example.com'));
        self::assertSame('not-an-email', EncryptionService::maskEmail('not-an-email'));
        self::assertSame('a@b@example.com', EncryptionService::maskEmail('a@b@example.com'));
        self::assertSame('', EncryptionService::maskEmail(''));
    }
}
