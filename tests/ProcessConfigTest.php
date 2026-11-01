<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace tests;

use app\process\LogCleanup;
use PHPUnit\Framework\TestCase;

final class ProcessConfigTest extends TestCase
{
    public function testLogCleanupProcessIsRegisteredWithSingleWorker(): void
    {
        $processes = config('process');

        self::assertIsArray($processes);
        self::assertArrayHasKey('log-cleanup', $processes);
        self::assertSame(LogCleanup::class, $processes['log-cleanup']['handler'] ?? null);
        self::assertSame(1, $processes['log-cleanup']['count'] ?? null);
    }

    public function testLogCleanupClassFileExists(): void
    {
        self::assertFileExists(dirname(__DIR__) . '/app/process/LogCleanup.php');
    }
}
