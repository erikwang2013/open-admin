<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace app\api\v1\controller;

use app\model\AdminUser;
use app\common\SnowflakeService;
use app\common\EncryptionService;
use app\common\SessionService;
use support\Container;
use support\Redis;
use support\Request;
use support\Response;
use Erikwang2013\Jwt\JWT;
use Erikwang2013\Jwt\JWTFactory;
use Throwable;

/**
 * @Apidoc\Title("认证")
 */
class AuthController
{
    private static ?JWT $jwt = null;

    /**
     * 固定 cost-10 占位哈希 — 用户不存在时仍执行一次 password_verify，
     * 使失败路径耗时与真实用户一致，避免响应时间泄漏用户名是否存在
     */
    private const DUMMY_PASSWORD_HASH = '$2y$10$vbWyLxn4TqPtlgtAeRJ7IuwxCFqtocDRECtrpqhlCXG8JKWHWtBlG';

    private static function getJWT(): JWT
    {
        if (self::$jwt === null) {
            $config = config('plugin.erikwang2013.jwt.jwt', []);
            self::$jwt = JWTFactory::createFromConfig($config);
        }
        return self::$jwt;
    }

    /**
     * 账号锁定键 — 已知用户按 ID 记键（与用户名大小写无关），未知用户按规范化用户名记键
     *
     * @return array{0: string, 1: string} [锁定键, 失败计数键]
     */
    public static function lockKeys(string $username, ?int $userId = null): array
    {
        if ($userId !== null && $userId > 0) {
            return ["account_lock:u:{$userId}", "login_fail:u:{$userId}"];
        }

        $normalized = md5(mb_strtolower(trim($username), 'UTF-8'));
        return ["account_lock:n:{$normalized}", "login_fail:n:{$normalized}"];
    }

    /**
     * @Apidoc\Title("登录")
     * @Apidoc\Group("认证")
     * @Apidoc\Method("POST")
     * @Apidoc\Url("/api/v1/auth/login")
     * @Apidoc\Desc("验证码校验通过后登录。密码使用 RSA-2048 公钥加密后 Base64 传输，私钥仅服务端持有")
     * @Apidoc\Param("username", type="string", require=true, desc="用户名")
     * @Apidoc\Param("password", type="string", require=true, desc="RSA-2048 PKCS1v1.5 加密后 Base64（兼容 AES/明文回退）")
     * @Apidoc\Param("captcha_key", type="string", require=true, desc="验证码key（需先通过 /api/v1/captcha/verify）")
     * @Apidoc\Returned("access_token", type="string", desc="访问令牌")
     * @Apidoc\Returned("refresh_token", type="string", desc="刷新令牌")
     * @Apidoc\Returned("expires_in", type="int", desc="过期时间(秒)")
     */
    public function login(Request $request): Response
    {
        $username = trim((string) $request->input('username', ''));
        $password = (string) $request->input('password', '');
        $captchaKey = (string) $request->input('captcha_key', '');

        if ($username === '' || strlen($username) < 3 || strlen($username) > 50) {
            return json(['code' => 422, 'message' => '用户名格式不正确', 'data' => []]);
        }
        if ($captchaKey === '') {
            return json(['code' => 422, 'message' => '验证码参数缺失', 'data' => []]);
        }

        // 原子消费验证码标记：DEL 返回 1 才视为首次通过，并发复用同一标记时只有一个请求成功
        // （Redis 故障时拒绝登录，不降级放行）
        try {
            if ((int) Redis::del("captcha_verified:{$captchaKey}") !== 1) {
                return json(['code' => 422, 'message' => '请先完成验证码校验', 'data' => []]);
            }
        } catch (Throwable) {
            return json(['code' => 503, 'message' => '服务暂时不可用，请稍后再试', 'data' => []])->withStatus(503);
        }

        // 校验用户凭证
        $user = AdminUser::where('username', $username)->first();

        // 账号锁定检查（5次失败/15分钟）— 已存在用户按 ID 记键，大小写变体无法绕开锁定
        [$lockKey, $failKey] = self::lockKeys($username, $user ? (int) $user->id : null);
        try {
            if (Redis::get($lockKey)) {
                return json(['code' => 429, 'message' => trans('messages.account_locked'), 'data' => []]);
            }
        } catch (\Throwable) {}

        // 密码解密：RSA 非对称 > AES 对称 > 明文，逐级回退保证兼容
        $rawPassword = (string) $request->input('password', '');
        $password = EncryptionService::decryptTransmission($rawPassword);

        // 长度校验通过后始终执行 password_verify：用户不存在时用固定占位哈希，抹平时间差
        // （先算 $passwordOk，避免 !$user 短路掉校验）
        $lengthOk = $password !== '' && strlen($password) >= 6 && strlen($password) <= 32;
        $hash = ($user && (string) $user->password !== '') ? (string) $user->password : self::DUMMY_PASSWORD_HASH;
        $passwordOk = $lengthOk && password_verify($password, $hash);

        if (!$lengthOk || !$user || !$passwordOk) {
            // 登录失败：计数 + 锁定
            try {
                $fails = Redis::incr($failKey);
                if ($fails === 1) Redis::expire($failKey, 900);
                if ($fails >= 5) {
                    Redis::setex($lockKey, 900, '1');
                    Redis::del($failKey);
                    return json(['code' => 429, 'message' => trans('messages.account_locked'), 'data' => []]);
                }
            } catch (\Throwable) {}
            return json(['code' => 401, 'message' => trans('messages.invalid_credentials'), 'data' => []]);
        }

        // 登录成功：清除失败计数
        try { Redis::del($failKey); Redis::del($lockKey); } catch (\Throwable) {}

        if ($user->status === 0) {
            return json(['code' => 403, 'message' => trans('messages.account_disabled'), 'data' => []]);
        }

        // 签发 JWT
        $jwt = self::getJWT();
        $tokenExpire   = (int)(config('plugin.erikwang2013.jwt.jwt.default_expire') ?: 7200);
        $refreshExpire = (int)(config('plugin.erikwang2013.jwt.jwt.refresh_expire') ?: 1209600);
        $issuedAt = (int) floor(microtime(true) * 1000);
        $token = $jwt->encode(['sub' => $user->id, 'username' => $user->username, 'token_type' => 'access', 'iat_ms' => $issuedAt]);
        $refreshToken = $jwt->encode(['sub' => $user->id, 'token_type' => 'refresh', 'iat_ms' => $issuedAt], $refreshExpire);

        // 并发会话限制
        $this->trackSession($user->id, $token, $tokenExpire);
        $this->trackRefreshToken($user->id, $refreshToken, $refreshExpire);

        // 更新登录信息
        $user->last_login_at = date('Y-m-d H:i:s');
        $user->last_login_ip = $request->getRealIp();
        $user->save();

        return json([
            'code'    => 0,
            'message' => trans('messages.login_success'),
            'data'    => [
                'access_token'  => $token,
                'refresh_token' => $refreshToken,
                'expires_in'    => (int)(config('plugin.erikwang2013.jwt.jwt.default_expire') ?: 7200),
                'user'          => [
                    'id'        => Container::get('hashids')->encode($user->id),
                    'username'  => $user->username,
                    'real_name' => $user->real_name,
                ],
            ],
        ]);
    }

    /**
     * @Apidoc\Title("刷新令牌")
     * @Apidoc\Group("认证")
     * @Apidoc\Method("POST")
     * @Apidoc\Url("/api/v1/auth/refresh")
     * @Apidoc\Desc("使用refresh_token刷新access_token")
     * @Apidoc\Param("refresh_token", type="string", require=true, desc="刷新令牌")
     * @Apidoc\Returned("access_token", type="string", desc="访问令牌")
     * @Apidoc\Returned("refresh_token", type="string", desc="新刷新令牌")
     * @Apidoc\Returned("expires_in", type="int", desc="过期时间(秒)")
     */
    public function refresh(Request $request): Response
    {
        $refreshToken = $request->input('refresh_token', '');

        if (empty($refreshToken) || !is_string($refreshToken)) {
            return json(['code' => 422, 'message' => trans('messages.refresh_missing'), 'data' => []]);
        }

        try {
            $jwt = self::getJWT();
            // JWT 库默认拒绝 refresh 类型，显式允许后由下方 token_type 校验把关
            $payload = $jwt->decode($refreshToken, true);

            // 只接受 refresh 类型令牌，防止 access_token 无限续期
            if (($payload['token_type'] ?? '') !== 'refresh') {
                return json(['code' => 401, 'message' => trans('messages.refresh_invalid'), 'data' => []]);
            }

            $userId = (int) ($payload['sub'] ?? 0);
            $user   = AdminUser::find($userId);
            if (!$user || $user->status === 0) {
                return json(['code' => 401, 'message' => trans('messages.refresh_invalid'), 'data' => []]);
            }

            // 密码修改后签发的旧 refresh token 一律失效（覆盖未登记的旧令牌；毫秒级水位）
            try {
                if (SessionService::isTokenRevoked($payload, $userId)) {
                    return json(['code' => 401, 'message' => trans('messages.refresh_invalid'), 'data' => []]);
                }
            } catch (Throwable) {
                return json(['code' => 503, 'message' => '服务暂时不可用，请稍后再试', 'data' => []])->withStatus(503);
            }
        } catch (Throwable $e) {
            return json(['code' => 401, 'message' => trans('messages.refresh_invalid'), 'data' => []]);
        }

        $tokenHash   = md5($refreshToken);
        $rotationKey = 'refresh_rotation:' . $tokenHash;
        $lockKey     = 'refresh_lock:' . $tokenHash;

        // 登记集合是登出/改密的吊销标记：先记下源令牌是否仍在册，轮换后复查以关闭并发竞态
        try {
            $sourceTracked = Redis::zscore("user_refresh_tokens:{$userId}", $tokenHash) !== false;
        } catch (Throwable) {
            $sourceTracked = false;
        }
        $trackedUser = $sourceTracked ? $userId : null;

        // 轮换重放宽限：60 秒内同一旧 refresh token 的重试/并发刷新返回同一对新令牌，
        // 必须放在黑名单拒绝之前，否则旧令牌轮换后立即重试会被判为已登出；
        // 缓存令牌任何一枚已吊销时删除缓存并拒绝，防止登出/改密后经缓存换回有效令牌
        $cached = self::cachedRotationPair($rotationKey, $trackedUser, $tokenHash);
        if ($cached === false) {
            return json(['code' => 401, 'message' => trans('messages.refresh_invalid'), 'data' => []]);
        }
        if ($cached !== null) {
            return $this->tokenResponse($cached, $user);
        }

        // SET NX 锁串行化轮换，避免同一旧令牌并发换出多对令牌
        $lockValue = bin2hex(random_bytes(8));
        try {
            $locked = (bool) Redis::set($lockKey, $lockValue, ['nx', 'ex' => 5]);
        } catch (Throwable) {
            // Redis 故障时降级为无锁轮换，黑名单检查同现有逻辑 fail-open
            $locked = true;
        }

        if (!$locked) {
            // 短暂等待持锁请求写入轮换结果，命中则复用同一对令牌
            for ($i = 0; $i < 5; $i++) {
                usleep(100000);
                $cached = self::cachedRotationPair($rotationKey, $trackedUser, $tokenHash);
                if ($cached === false) {
                    return json(['code' => 401, 'message' => trans('messages.refresh_invalid'), 'data' => []]);
                }
                if ($cached !== null) {
                    return $this->tokenResponse($cached, $user);
                }
            }
            return json(['code' => 409, 'message' => '刷新请求处理中，请稍后重试', 'data' => []])->withStatus(409);
        }

        try {
            // 持锁后复查：并发请求可能已完成轮换
            $cached = self::cachedRotationPair($rotationKey, $trackedUser, $tokenHash);
            if ($cached === false) {
                return json(['code' => 401, 'message' => trans('messages.refresh_invalid'), 'data' => []]);
            }
            if ($cached !== null) {
                return $this->tokenResponse($cached, $user);
            }

            // 已登出的 refresh token 不可再使用
            try {
                if (Redis::get('jwt_blacklist:' . $tokenHash)) {
                    return json(['code' => 401, 'message' => trans('messages.refresh_invalid'), 'data' => []]);
                }
            } catch (Throwable) {
                // Redis 故障时跳过黑名单检查
            }

            // 刷新时更新最后登录时间和IP
            $user->last_login_at = date('Y-m-d H:i:s');
            $user->last_login_ip = $request->getRealIp();
            $user->save();

            $tokenExpire   = (int)(config('plugin.erikwang2013.jwt.jwt.default_expire') ?: 7200);
            $refreshExpire = (int)(config('plugin.erikwang2013.jwt.jwt.refresh_expire') ?: 1209600);
            $issuedAt = (int) floor(microtime(true) * 1000);
            $token = $jwt->encode(['sub' => $user->id, 'username' => $user->username, 'token_type' => 'access', 'iat_ms' => $issuedAt]);
            $newRefresh = $jwt->encode(['sub' => $user->id, 'token_type' => 'refresh', 'iat_ms' => $issuedAt], $refreshExpire);

            // 并发会话限制
            $this->trackSession($userId, $token, $tokenExpire);
            $this->trackRefreshToken($userId, $newRefresh, $refreshExpire);

            // 先写轮换缓存并登记到用户集合（登出/改密时统一吊销）；再吊销旧 refresh token
            try {
                Redis::setex($rotationKey, 60, json_encode([
                    'access_token'  => $token,
                    'refresh_token' => $newRefresh,
                    'expires_in'    => $tokenExpire,
                ]));
                Redis::sadd("user_refresh_rotations:{$userId}", $rotationKey);
                Redis::expire("user_refresh_rotations:{$userId}", 120);
            } catch (Throwable) {
                // Redis 故障不影响本次刷新
            }
            try {
                Redis::setex(
                    'jwt_blacklist:' . $tokenHash,
                    max((int) ($payload['exp'] ?? 0) - time(), 60),
                    '1'
                );
            } catch (Throwable) {
                // Redis 故障不影响本次刷新
            }

            // 登出/改密的清理可能发生在本次轮换检查之后：源令牌已被移除则撤销刚签发的令牌对
            if ($sourceTracked) {
                try {
                    if (Redis::zscore("user_refresh_tokens:{$userId}", $tokenHash) === false) {
                        foreach ([[$token, $tokenExpire], [$newRefresh, $refreshExpire]] as [$issued, $ttl]) {
                            $issuedHash = md5($issued);
                            Redis::setex('jwt_blacklist:' . $issuedHash, $ttl, '1');
                            Redis::zrem("user_tokens:{$userId}", $issuedHash);
                            Redis::zrem("user_refresh_tokens:{$userId}", $issuedHash);
                        }
                        Redis::del($rotationKey);
                        Redis::srem("user_refresh_rotations:{$userId}", $rotationKey);
                        return json(['code' => 401, 'message' => trans('messages.refresh_invalid'), 'data' => []]);
                    }
                } catch (Throwable) {
                    // Redis 故障时保留本次刷新结果
                }
            }

            return json([
                'code'    => 0,
                'message' => 'success',
                'data'    => [
                    'access_token'  => $token,
                    'refresh_token' => $newRefresh,
                    'expires_in'    => $tokenExpire,
                    'user'          => [
                        'id'        => Container::get('hashids')->encode($user->id),
                        'username'  => $user->username,
                        'real_name' => $user->real_name,
                    ],
                ],
            ]);
        } catch (Throwable $e) {
            return json(['code' => 401, 'message' => trans('messages.refresh_invalid'), 'data' => []]);
        } finally {
            // 只删除自己持有的锁，避免锁过期后误删新持有者的锁
            try {
                Redis::eval(
                    "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end",
                    [$lockKey, $lockValue],
                    1
                );
            } catch (Throwable) {
                // 释放失败由 5 秒 TTL 兜底
            }
        }
    }

    /**
     * 读取轮换缓存 — 命中且两枚令牌均未吊销时返回令牌对；缓存令牌已吊销时清缓存并返回 false；无缓存返回 null
     *
     * @param int|null $userId 源 refresh token 所属用户，与 $tokenHash 同时提供时校验令牌仍在登记集合中
     * @return array|false|null
     */
    private static function cachedRotationPair(string $rotationKey, ?int $userId = null, ?string $tokenHash = null): array|false|null
    {
        try {
            $cachedPair = Redis::get($rotationKey);
            if (!$cachedPair) {
                return null;
            }
            // 登出/改密已把源令牌移出登记集合：缓存对应作废，防止宽限期换回已吊销的令牌对
            if ($userId !== null && $tokenHash !== null
                && Redis::zscore("user_refresh_tokens:{$userId}", $tokenHash) === false) {
                Redis::del($rotationKey);
                return false;
            }
            $pair = json_decode((string) $cachedPair, true);
            if (!is_array($pair) || empty($pair['access_token']) || empty($pair['refresh_token'])) {
                return null;
            }
            foreach (['access_token', 'refresh_token'] as $field) {
                if (Redis::get('jwt_blacklist:' . md5((string) $pair[$field]))) {
                    Redis::del($rotationKey);
                    return false;
                }
            }
            return $pair;
        } catch (Throwable) {
            // Redis 故障时退回正常轮换流程
            return null;
        }
    }

    /**
     * 轮换缓存命中时的统一响应
     */
    private function tokenResponse(array $pair, AdminUser $user): Response
    {
        return json([
            'code'    => 0,
            'message' => 'success',
            'data'    => $pair + ['user' => [
                'id'        => Container::get('hashids')->encode($user->id),
                'username'  => $user->username,
                'real_name' => $user->real_name,
            ]],
        ]);
    }

    /**
     * @Apidoc\Title("获取传输加密公钥")
     * @Apidoc\Group("认证")
     * @Apidoc\Method("GET")
     * @Apidoc\Url("/api/v1/auth/public-key")
     * @Apidoc\Desc("由服务端 RSA 私钥导出公钥 PEM，供客户端在密钥轮换后动态获取；未配置私钥时返回 code 404")
     * @Apidoc\Returned("public_key", type="string", desc="RSA 公钥 PEM")
     */
    public function publicKey(): Response
    {
        $privateKey = (string) config('encryption.rsa_private_key', '');
        if ($privateKey === '') {
            return json(['code' => 404, 'message' => '未配置 RSA 密钥', 'data' => []]);
        }

        try {
            // 配置项为 Base64 编码的 PEM；只导出公钥，私钥不出进程
            $pem = base64_decode($privateKey, true);
            if ($pem === false) {
                throw new \RuntimeException('invalid private key');
            }
            $key = openssl_pkey_get_private($pem);
            if ($key === false) {
                throw new \RuntimeException('invalid private key');
            }
            $details = openssl_pkey_get_details($key);
            $publicKey = is_array($details) ? (string) ($details['key'] ?? '') : '';
            if ($publicKey === '') {
                throw new \RuntimeException('public key derivation failed');
            }

            return json(['code' => 0, 'message' => 'success', 'data' => ['public_key' => $publicKey]]);
        } catch (Throwable) {
            return json(['code' => 404, 'message' => '未配置 RSA 密钥', 'data' => []]);
        }
    }

    /**
     * 并发会话限制 — 同一用户最多 3 个有效 token
     * @param int $userId 用户 ID
     * @param string $token JWT access_token
     * @param int $expiresIn token 有效期（秒）
     */
    private function trackSession(int $userId, string $token, int $expiresIn): void
    {
        try {
            $key = "user_tokens:{$userId}";
            $exp = time() + $expiresIn;
            $member = md5($token);

            // 清理已过期的 token
            // phpredis 6 的 zRemRangeByScore 要求 start/stop 为 string
            Redis::zremrangebyscore($key, '0', (string) time());
            // 添加新 token
            Redis::zadd($key, $exp, $member);
            // 超过 3 个 → 踢出最旧的
            $count = Redis::zcard($key);
            if ($count > 3) {
                $oldest = Redis::zrange($key, 0, 0, true);
                if ($oldest) {
                    $oldMember = array_key_first($oldest);
                    $oldExp = (int) $oldest[$oldMember];
                    $ttl = max($oldExp - time(), 0);
                    Redis::zrem($key, $oldMember);
                    if ($ttl > 0) {
                        Redis::setex("jwt_blacklist:{$oldMember}", $ttl, '1');
                    }
                }
            }
            Redis::expire($key, $expiresIn + 3600);
        } catch (\Throwable) {
            // Redis 故障不影响登录
        }
    }

    /**
     * 登记 refresh token，便于改密/登出时统一吊销（未登记的旧令牌仍可用）
     * @param int $userId 用户 ID
     * @param string $token JWT refresh_token
     * @param int $expiresIn token 有效期（秒）
     */
    private function trackRefreshToken(int $userId, string $token, int $expiresIn): void
    {
        try {
            $key = "user_refresh_tokens:{$userId}";
            // phpredis 6 的 zRemRangeByScore 要求 start/stop 为 string
            Redis::zremrangebyscore($key, '0', (string) time());
            Redis::zadd($key, time() + $expiresIn, md5($token));
            Redis::expire($key, $expiresIn + 3600);
        } catch (\Throwable) {
            // Redis 故障不影响令牌签发
        }
    }
}
