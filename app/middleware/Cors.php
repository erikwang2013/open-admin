<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace app\middleware;

use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

class Cors implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        if ($request->method() === 'OPTIONS') {
            return response('', 204, [
                'Access-Control-Allow-Origin'      => '*',
                'Access-Control-Allow-Methods'     => 'GET,POST,PUT,DELETE,OPTIONS',
                'Access-Control-Allow-Headers'     => 'Authorization,Content-Type,API-Version',
                'Access-Control-Max-Age'           => '86400',
            ]);
        }

        $response = $handler($request);
        $response = $response->withHeaders([
            'Access-Control-Allow-Origin' => '*',
        ]);
        return $response;
    }
}
