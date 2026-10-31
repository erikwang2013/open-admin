<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace tests;

use PHPUnit\Framework\TestCase;

final class ConfigWiringTest extends TestCase
{
    public function testLoadedHashidsSaltMatchesEnvironment(): void
    {
        self::assertSame(getenv('HASHIDS_SALT') ?: '', config('hashids.connections.main.salt'));
    }

    public function testHashidsConfigReadsSaltFromEnvironment(): void
    {
        $original = getenv('HASHIDS_SALT');
        putenv('HASHIDS_SALT=phpunit-salt');
        try {
            $config = require dirname(__DIR__) . '/config/hashids.php';
        } finally {
            putenv($original === false ? 'HASHIDS_SALT' : 'HASHIDS_SALT=' . $original);
        }

        self::assertSame('phpunit-salt', $config['connections']['main']['salt']);
    }
}
