<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

/**
 * 数据库敏感字段加解密插件配置
 *
 * Webman—plugin 统一布局: 顶层 key/cipher/previous_keys
 * 模型中使用 cast: '字段名' => \Maize\Encryptable\Encryptable::class
 *
 * @see https://github.com/erikwang2013/encryptable
 */
return [
    // 数据库加密密钥，生产环境请使用 32 字节随机字符串并通过环境变量 ENCRYPTION_KEY 注入
    'key' => env('ENCRYPTION_KEY', 'open-admin-db-encryption-key-32b'),

    // 加密算法，默认 aes-128-ecb。也支持 aes-256-cbc, sm4-ecb
    'cipher' => env('ENCRYPTION_CIPHER', 'aes-128-ecb'),

    // 历史密钥列表（用于密钥轮换时的数据迁移），逗号分隔
    'previous_keys' => Maize\Encryptable\Support\PreviousKeysParser::parse(env('ENCRYPTION_PREVIOUS_KEYS')),
];
