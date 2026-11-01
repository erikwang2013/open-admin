<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace tests;

use app\middleware\OperationLog;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class OperationLogFilterTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function filter(array $input): array
    {
        // PHP >= 8.1（项目最低版本）反射可直接调用私有方法，setAccessible() 已是废弃的空操作
        $method = new ReflectionMethod(OperationLog::class, 'filterSensitive');

        return $method->invoke(new OperationLog(), $input);
    }

    public function testMasksSensitiveFields(): void
    {
        self::assertSame(
            [
                'password' => '***',
                'phone'    => '***',
                'email'    => '***',
                'id_card'  => '***',
            ],
            self::filter([
                'password' => 'secret-password',
                'phone'    => '13812345678',
                'email'    => 'alice@example.com',
                'id_card'  => '110101199001011234',
            ])
        );
    }

    public function testRecursesIntoNestedArrays(): void
    {
        $input = [
            'user' => [
                'name'     => 'Alice',
                'password' => 'secret-password',
                'profile'  => [
                    'phone'   => '13812345678',
                    'email'   => 'alice@example.com',
                    'id_card' => '110101199001011234',
                ],
            ],
            'items' => [
                ['password' => 'one'],
                ['password' => 'two'],
            ],
        ];

        self::assertSame(
            [
                'user' => [
                    'name'     => 'Alice',
                    'password' => '***',
                    'profile'  => [
                        'phone'   => '***',
                        'email'   => '***',
                        'id_card' => '***',
                    ],
                ],
                'items' => [
                    ['password' => '***'],
                    ['password' => '***'],
                ],
            ],
            self::filter($input)
        );
    }

    public function testLeavesUnrelatedValuesUntouched(): void
    {
        $input = [
            'username'  => 'alice',
            'real_name' => '<b>Alice</b>',
            'status'    => 1,
            'tags'      => ['admin', 'staff'],
            'nested'    => ['count' => 2, 'enabled' => true],
        ];

        self::assertSame($input, self::filter($input));
    }
}
