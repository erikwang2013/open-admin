<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace support;

use Redis as RedisClient;
use RuntimeException;

/**
 * Redis 工具类 — 单例连接池
 */
class Redis
{
    private static ?RedisClient $instance = null;

    private static function getInstance(): RedisClient
    {
        if (self::$instance !== null) {
            try {
                self::$instance->ping();
                return self::$instance;
            } catch (\Throwable) {
                self::$instance = null;
            }
        }

        $host = getenv('REDIS_HOST') ?: '127.0.0.1';
        $port = (int)(getenv('REDIS_PORT') ?: 6379);
        $pass = getenv('REDIS_PASSWORD') ?: null;
        $db   = (int)(getenv('REDIS_DB') ?: 0);

        $redis = new RedisClient();
        if (!$redis->connect($host, $port)) {
            throw new RuntimeException("Redis connect failed: {$host}:{$port}");
        }
        if ($pass !== null && $pass !== '') {
            $redis->auth($pass);
        }
        if ($db !== 0) {
            $redis->select($db);
        }

        self::$instance = $redis;
        return self::$instance;
    }

    public static function __callStatic(string $name, array $arguments): mixed
    {
        return self::getInstance()->{$name}(...$arguments);
    }
}
