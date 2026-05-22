<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace app\api\v1\controller;

use support\Request;
use support\Response;
use Throwable;

class CaptchaController
{
    /**
     * @Apidoc\Title("生成验证码")
     * @Apidoc\Group("captcha")
     * @Apidoc\Method("POST")
     * @Apidoc\Url("/api/captcha/generate")
     * @Apidoc\Desc("生成点击验证码，返回base64图片和点击目标")
     * @Apidoc\Param("difficulty", type="string", require=false, desc="难度等级", default="medium")
     * @Apidoc\Returned("key", type="string", desc="验证码key")
     * @Apidoc\Returned("image", type="string", desc="base64图片")
     * @Apidoc\Returned("extra", type="object", desc="额外信息(含targets)")
     */
    public function generate(Request $request): Response
    {
        $difficulty = $request->input('difficulty', 'medium');

        try {
            $result = captcha_create('click', ['difficulty' => $difficulty]);

            return json([
                'code' => 0,
                'message' => 'success',
                'data' => [
                    'key' => $result['key'],
                    'image' => base64_encode($result['image']), // base64 PNG
                    'extra' => [
                        'targets' => $result['extra']['targets'],
                    ],
                ],
            ]);
        } catch (Throwable $e) {
            return json([
                'code' => 500,
                'message' => trans('messages.captcha_generate_failed'),
                'data' => [],
            ]);
        }
    }

    /**
     * @Apidoc\Title("校验验证码")
     * @Apidoc\Group("captcha")
     * @Apidoc\Method("POST")
     * @Apidoc\Url("/api/captcha/verify")
     * @Apidoc\Desc("校验点击验证码是否有效")
     * @Apidoc\Param("key", type="string", require=true, desc="验证码key")
     * @Apidoc\Param("clicks", type="array", require=true, desc="点击坐标数组[{x,y},...]")
     * @Apidoc\Returned("valid", type="bool", desc="是否校验通过")
     */
    public function verify(Request $request): Response
    {
        $key = $request->input('key', '');
        $clicks = $request->input('clicks', []);

        if (empty($key) || empty($clicks)) {
            return json(['code' => 422, 'message' => trans('messages.captcha_missing'), 'data' => []]);
        }

        $valid = captcha_verify($key, 'click', $clicks);

        return json([
            'code' => $valid ? 0 : 422,
            'message' => $valid ? trans('messages.captcha_verify_pass') : trans('messages.captcha_verify_fail'),
            'data' => ['valid' => $valid],
        ]);
    }
}
