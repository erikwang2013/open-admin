<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

/**
 * JWT 认证配置
 * 管理端与客户端共用一个密钥体系，通过 guard 区分 token 类型
 * @link https://github.com/erikwang2013/jwt-webman
 */
return [
    // JWT 签名密钥，生产环境请使用 64 位以上随机字符串并通过环境变量注入
    'secret' => getenv('JWT_SECRET') ?: 'open-admin-jwt-secret-change-in-production',

    // 签名算法，支持 HS256/HS384/HS512/RS256
    'algorithm' => getenv('JWT_ALGORITHM') ?: 'HS256',

    // 访问令牌有效期（秒），默认 2 小时。到期后需要使用刷新令牌续期
    'ttl' => (int)(getenv('JWT_TTL') ?: 7200),

    // 刷新令牌有效期（秒），默认 14 天。过期后需重新登录
    'refresh_ttl' => (int)(getenv('JWT_REFRESH_TTL') ?: 1209600),

    // 签发者标识，用于多应用场景区分 token 来源
    'issuer' => getenv('JWT_ISSUER') ?: 'open-admin',

    // 受众标识，用于限制 token 使用范围
    'audience' => getenv('JWT_AUDIENCE') ?: 'open-admin',
];
