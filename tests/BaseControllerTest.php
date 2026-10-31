<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace tests;

use app\admin\controller\BaseController;
use PHPUnit\Framework\TestCase;

final class ClampPageProbe extends BaseController
{
    public static function clamp(int $page, int $limit): array
    {
        return parent::clampPage($page, $limit);
    }
}

final class BaseControllerTest extends TestCase
{
    public function testClampPageBounds(): void
    {
        self::assertSame([1, 15], ClampPageProbe::clamp(0, 0));
        self::assertSame([1, 15], ClampPageProbe::clamp(-3, -10));
        self::assertSame([1, 100], ClampPageProbe::clamp(0, 500));
        self::assertSame([1, 1], ClampPageProbe::clamp(1, 1));
        self::assertSame([3, 20], ClampPageProbe::clamp(3, 20));
    }
}
