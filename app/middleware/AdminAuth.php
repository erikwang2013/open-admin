<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace app\middleware;

use support\Request;
use support\Response;

use function jwt;

class AdminAuth
{
    public function process(Request $request, callable $next): Response
    {
        $token = $request->header('Authorization', '');
        $token = str_replace('Bearer ', '', $token);

        if (empty($token)) {
            return json(['code' => 401, 'message' => '未登录', 'data' => []]);
        }

        try {
            $payload = jwt()->verify($token);
            $request->adminId = $payload['sub'] ?? 0;
            $request->adminUsername = $payload['username'] ?? '';
        } catch (\Exception $e) {
            return json(['code' => 401, 'message' => 'Token已过期或无效', 'data' => []]);
        }

        return $next($request);
    }
}
