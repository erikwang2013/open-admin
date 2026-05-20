<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace app\middleware;

use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;
use support\Redis;

class RateLimit implements MiddlewareInterface
{
    private int $defaultLimit = 60;
    private int $defaultWindow = 60;

    private array $sensitive = [
        '/api/auth/login'    => ['limit' => 10, 'window' => 60],
        '/api/auth/register' => ['limit' => 5,  'window' => 60],
    ];

    public function process(Request $request, callable $handler): Response
    {
        $path = $request->path();
        $ip   = $request->getRealIp();

        $limit  = $this->defaultLimit;
        $window = $this->defaultWindow;

        foreach ($this->sensitive as $pattern => $cfg) {
            if (str_starts_with($path, $pattern)) {
                $limit  = $cfg['limit'];
                $window = $cfg['window'];
                break;
            }
        }

        $key         = "rate_limit:{$ip}:" . md5($path);
        $now         = (int) (microtime(true) * 1000);
        $windowStart = $now - $window * 1000;

        Redis::zremrangebyscore($key, 0, $windowStart);
        $count = Redis::zcard($key);

        if ($count >= $limit) {
            return json([
                'code'    => 429,
                'message' => '请求过于频繁，请稍后再试',
                'data'    => [],
            ])->withStatus(429);
        }

        Redis::zadd($key, $now, $now . '.' . mt_rand());
        Redis::expire($key, $window + 10);

        return $handler($request);
    }
}
