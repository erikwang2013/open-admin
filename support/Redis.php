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
    private const DEFAULT_PREFIX = 'open-admin:';

    private static ?string $prefix = null;
    private static ?RedisClient $instance = null;
    private static int $lastPing = 0;

    private static function getPrefix(): string
    {
        if (self::$prefix === null) {
            $env = getenv('REDIS_PREFIX');
            self::$prefix = ($env !== false && $env !== '') ? $env : self::DEFAULT_PREFIX;
        }
        return self::$prefix;
    }

    private static function getInstance(): RedisClient
    {
        if (self::$instance !== null) {
            // 连接存活检查节流：5 秒内直接复用，避免每条命令都 PING 一次
            if (time() - self::$lastPing <= 5) {
                return self::$instance;
            }
            try {
                if (self::$instance->ping()) {
                    self::$lastPing = time();
                    return self::$instance;
                }
            } catch (\Throwable) {
                // 连接已失效，重建
            }
            self::$instance = null;
        }

        $host = getenv('REDIS_HOST') ?: '127.0.0.1';
        $port = (int)(getenv('REDIS_PORT') ?: 6379);
        $pass = getenv('REDIS_PASSWORD') ?: null;
        $db   = (int)(getenv('REDIS_DATABASE') ?: 0);

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
        self::$lastPing = time();
        return self::$instance;
    }

    private static function prefixKey(string $key): string
    {
        if (str_starts_with($key, self::getPrefix())) {
            return $key;
        }
        return self::getPrefix() . $key;
    }

    public static function __callStatic(string $name, array $arguments): mixed
    {
        if ($name === 'eval') {
            // phpredis 原生形式: eval(script, [key1, ..., argN], num_keys)，键在参数数组开头
            if (isset($arguments[1]) && is_array($arguments[1])) {
                $keyCount = (int)($arguments[2] ?? 0);
                for ($i = 0; $i < $keyCount && array_key_exists($i, $arguments[1]); $i++) {
                    $arguments[1][$i] = self::prefixKey((string)$arguments[1][$i]);
                }
            } else {
                // 兼容旧形式 eval(script, keyCount, key1, ..., argN)：转为原生三参数形式
                $keyCount = (int)($arguments[1] ?? 0);
                $evalArgs = array_slice($arguments, 2);
                for ($i = 0; $i < $keyCount && array_key_exists($i, $evalArgs); $i++) {
                    $evalArgs[$i] = self::prefixKey((string)$evalArgs[$i]);
                }
                $arguments = [$arguments[0], $evalArgs, $keyCount];
            }
        } elseif (!empty($arguments) && is_string($arguments[0])) {
            $arguments[0] = self::prefixKey($arguments[0]);
        }

        return self::getInstance()->{$name}(...$arguments);
    }
}
