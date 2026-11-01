<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace tests;

use app\admin\controller\ImportController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ImportSizeGuardTest extends TestCase
{
    /**
     * @param array<int, array{totalRows?: int, totalColumns?: int}> $sheets
     */
    private static function sizeError(array $sheets): ?string
    {
        // PHP >= 8.1（项目最低版本）反射可直接调用私有方法，setAccessible() 已是废弃的空操作
        $method = new ReflectionMethod(ImportController::class, 'workbookSizeError');

        return $method->invoke(null, $sheets);
    }

    public function testSheetWithinRowAndCellLimitsPasses(): void
    {
        // 2000 行 × 15 列 = 30000 个单元格
        self::assertNull(self::sizeError([['totalRows' => 2000, 'totalColumns' => 15]]));
        // 边界：2001 行元数据 = 2000 行数据 + 表头
        self::assertNull(self::sizeError([['totalRows' => 2001, 'totalColumns' => 15]]));
    }

    public function testTooManyRowsFails(): void
    {
        // 2001 行数据 + 表头 = 2002 行元数据，超过 2000 行数据上限
        self::assertNotNull(self::sizeError([['totalRows' => 2002, 'totalColumns' => 15]]));
    }

    public function testTooManyCellsFails(): void
    {
        self::assertNotNull(self::sizeError([['totalRows' => 1000, 'totalColumns' => 33]]));
    }

    public function testEmptyMetadataPasses(): void
    {
        self::assertNull(self::sizeError([]));
        self::assertNull(self::sizeError([[]]));
    }

    public function testAnyOversizedSheetFails(): void
    {
        self::assertNotNull(self::sizeError([
            ['totalRows' => 10, 'totalColumns' => 5],
            ['totalRows' => 2002, 'totalColumns' => 5],
        ]));
    }
}
