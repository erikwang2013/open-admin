<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace tests;

use app\admin\controller\UserController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class UserMaskHelperTest extends TestCase
{
    private static function call(string $method, string $value): string
    {
        // PHP >= 8.1（项目最低版本）反射可直接调用私有方法，setAccessible() 已是废弃的空操作
        $reflection = new ReflectionMethod(UserController::class, $method);

        return $reflection->invoke(null, $value);
    }

    public function testMaskPhoneKeepsPrefixAndSuffix(): void
    {
        self::assertSame('138****5678', self::call('maskPhone', '13812345678'));
    }

    public function testMaskEmailKeepsFirstCharacterAndDomain(): void
    {
        self::assertSame('a***@example.com', self::call('maskEmail', 'alice@example.com'));
    }

    public function testEmptyValuesAreHandled(): void
    {
        self::assertSame('', self::call('maskPhone', ''));
        self::assertSame('***@', self::call('maskEmail', ''));
    }

    public function testNonMatchingPhoneIsUnchanged(): void
    {
        foreach (['12345', 'abc'] as $phone) {
            self::assertSame($phone, self::call('maskPhone', $phone));
        }
        // 国家码/格式化号码也必须脱敏，避免列表泄露完整手机号
        self::assertSame('+86****5678', self::call('maskPhone', '+8613812345678'));
        self::assertSame('+86****5678', self::call('maskPhone', '+86 13812345678'));
        // 7-10 位座机也不能被完整还原
        self::assertSame('1****67', self::call('maskPhone', '1234567'));
        self::assertSame('1*****78', self::call('maskPhone', '12345678'));
    }
}
