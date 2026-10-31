<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace app\api\v1\controller;

use support\Request;
use support\Response;
use Throwable;

/**
 * @Apidoc\Title("验证码")
 */
class CaptchaController
{
    /**
     * @Apidoc\Title("生成验证码")
     * @Apidoc\Group("验证码")
     * @Apidoc\Method("POST")
     * @Apidoc\Url("/api/v1/captcha/generate")
     * @Apidoc\Desc("随机生成 click/slider/rotate 三种验证码之一")
     * @Apidoc\Param("difficulty", type="string", require=false, desc="难度等级", default="medium")
     * @Apidoc\Returned("key", type="string", desc="验证码key")
     * @Apidoc\Returned("type", type="string", desc="验证码类型: click|slider|rotate")
     * @Apidoc\Returned("image", type="string", desc="base64 data URI 图片")
     * @Apidoc\Returned("extra", type="object", desc="类型相关附加数据 targets(xy坐标)/x y puzzle_w puzzle_h puzzle(滑块)/angle(旋转)")
     */
    public function generate(Request $request): Response
    {
        // input 可能是数组（JSON body），is_string 不通过时用默认值，避免 Array to string 异常
        $type = $request->input('type', 'random');
        if (!is_string($type) || !in_array($type, ['click', 'slider', 'rotate', 'random'], true)) {
            $type = 'random';
        }
        $difficulty = $request->input('difficulty', 'medium');
        if (!is_string($difficulty) || !in_array($difficulty, ['easy', 'medium', 'hard'], true)) {
            $difficulty = 'medium';
        }

        // 低概率（1%）清理过期验证码文件；任何异常都不能影响生成
        try {
            if (random_int(1, 100) === 1) {
                $this->cleanupExpiredCaptchaFiles();
            }
        } catch (Throwable) {
        }

        try {
            $result = captcha_create($type, ['difficulty' => $difficulty]);

            // 客户端从 extra.targets 读取点击提示；poster 包返回的键名是 extra.texts
            $extra = is_array($result['extra'] ?? null) ? $result['extra'] : [];
            $extra['targets'] = $extra['texts'] ?? [];

            return json([
                'code' => 0,
                'message' => 'success',
                'data' => [
                    'key'     => $result['key'],
                    'type'    => $result['type'],
                    'image'   => $result['image'], // POSTER 返回 data URI，经 json_encode 后为 base64
                    'extra'   => $extra,
                    'targets' => $extra['targets'], // HarmonyOS 客户端读取顶层 data.targets
                ],
            ]);
        } catch (Throwable) {
            // 不向客户端暴露异常细节
            return json([
                'code' => 500,
                'message' => '验证码生成失败',
                'data' => [],
            ]);
        }
    }

    /**
     * @Apidoc\Title("校验验证码")
     * @Apidoc\Group("验证码")
     * @Apidoc\Method("POST")
     * @Apidoc\Url("/api/v1/captcha/verify")
     * @Apidoc\Desc("校验验证码答案。clicks 格式: click=[{x,y}], slider=int, rotate=int")
     * @Apidoc\Param("key", type="string", require=true, desc="验证码key")
     * @Apidoc\Param("type", type="string", require=true, desc="验证码类型: click|slider|rotate")
     * @Apidoc\Param("clicks", type="mixed", require=true, desc="click=[{x,y}], slider=偏移量, rotate=角度")
     * @Apidoc\Returned("valid", type="bool", desc="是否校验通过")
     */
    public function verify(Request $request): Response
    {
        $key   = $request->input('key', '');
        $type  = $request->input('type', 'click');
        $clicks = $request->input('clicks', []);

        if (empty($key) || empty($clicks)) {
            return json(['code' => 422, 'message' => trans('messages.captcha_missing'), 'data' => []]);
        }

        $valid = captcha_verify($key, $type, $clicks);

        // 验证成功标记 key，登录接口不再重复校验
        if ($valid) {
            try { \support\Redis::setex("captcha_verified:{$key}", 300, '1'); } catch (\Throwable) {}
        }

        return json([
            'code' => $valid ? 0 : 422,
            'message' => $valid ? trans('messages.captcha_verify_pass') : trans('messages.captcha_verify_fail'),
            'data' => ['valid' => $valid],
        ]);
    }

    /**
     * 清理过期验证码文件
     *
     * 文件驱动不会主动回收过期 key，这里按 poster 的 TTL 删除过期 *.json。
     * 路径与 poster FileStorage 保持一致：配置优先，未配置时用系统临时目录。
     */
    private function cleanupExpiredCaptchaFiles(): void
    {
        $dir = config('poster.captcha.file.path') ?: sys_get_temp_dir() . '/poster-captcha';
        if (!is_dir($dir)) {
            return;
        }

        $expireBefore = time() - (int) (config('poster.captcha.ttl') ?: 300);
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime < $expireBefore) {
                @unlink($file);
            }
        }
    }
}
