<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace tests;

use Erikwang2013\Poster\PosterConfig;
use Erikwang2013\Poster\Storage\StorageFactory;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

class CaptchaTest extends TestCase
{
    protected function setUp(): void
    {
        if (file_exists(__DIR__ . '/../.env')) {
            $dotenv = \Dotenv\Dotenv::createUnsafeImmutable(__DIR__ . '/..');
            $dotenv->safeLoad();
        }
    }

    /**
     * 客户端可见载荷：key/image/extra.texts（仅提示文字与顺序），
     * 目标坐标绝不返回客户端 — 见 captcha_correct_clicks_needs_server_answer
     */
    #[Test]
    public function captcha_generate_returns_valid_structure(): void
    {
        $result = captcha_create('click', ['difficulty' => 'medium']);

        $this->assertArrayHasKey('key', $result, '应包含 key');
        $this->assertArrayHasKey('image', $result, '应包含 image');
        $this->assertArrayHasKey('extra', $result, '应包含 extra');
        $this->assertArrayHasKey('texts', $result['extra'], 'extra 应包含 texts');
        $this->assertArrayNotHasKey('targets', $result['extra'], '坐标 targets 不应返回客户端');

        $this->assertNotEmpty($result['key']);
        $this->assertNotEmpty($result['image']);
        $this->assertSame('click', $result['type']);
        $this->assertCount(3, $result['extra']['texts'], 'medium 难度应有 3 个目标');
    }

    #[Test]
    public function captcha_texts_have_required_fields(): void
    {
        $result = captcha_create('click', ['difficulty' => 'easy']);

        $this->assertNotEmpty($result['extra']['texts']);
        foreach ($result['extra']['texts'] as $i => $target) {
            $this->assertArrayHasKey('text', $target);
            $this->assertArrayHasKey('order', $target);
            $this->assertIsString($target['text']);
            $this->assertNotEmpty($target['text']);
            $this->assertIsInt($target['order']);
            $this->assertSame($i + 1, $target['order'], 'order 应从 1 递增');
        }
    }

    #[Test]
    public function captcha_difficulty_controls_target_count(): void
    {
        $easy = captcha_create('click', ['difficulty' => 'easy']);
        $medium = captcha_create('click', ['difficulty' => 'medium']);
        $hard = captcha_create('click', ['difficulty' => 'hard']);

        $this->assertCount(2, $easy['extra']['texts'], 'easy 应为 2 个目标');
        $this->assertCount(3, $medium['extra']['texts'], 'medium 应为 3 个目标');
        $this->assertCount(4, $hard['extra']['texts'], 'hard 应为 4 个目标');
    }

    /**
     * 正确点击须通过：坐标取自服务端存储（客户端拿不到坐标），
     * 存储实例与 captcha_create 使用同一自动解析，key 即验证码 key。
     */
    #[Test]
    public function captcha_verify_correct_clicks_passes(): void
    {
        $result = captcha_create('click', ['difficulty' => 'easy']);
        $stored = StorageFactory::create()->get($result['key']);
        $this->assertIsArray($stored, '服务端应存有目标坐标');
        $this->assertCount(2, $stored['targets']);

        // 客户端点击格式为 [[x, y], ...] 数字对
        $clicks = array_map(fn($t) => [$t['x'], $t['y']], $stored['targets']);
        $valid = captcha_verify($result['key'], 'click', $clicks);

        $this->assertTrue($valid, '点击正确坐标应验证通过');
    }

    #[Test]
    public function captcha_verify_wrong_clicks_fails(): void
    {
        $result = captcha_create('click', ['difficulty' => 'easy']);

        // 使用完全错误的坐标（数字对格式）
        $clicks = [[0, 0], [999, 999]];
        $valid = captcha_verify($result['key'], 'click', $clicks);

        $this->assertFalse($valid, '错误坐标应验证失败');
    }

    #[Test]
    public function captcha_key_has_limited_attempts(): void
    {
        $max = PosterConfig::get('captcha.max_attempts', 3);
        $result = captcha_create('click', ['difficulty' => 'easy']);
        $storage = StorageFactory::create();
        $clicks = [[0, 0], [999, 999]];

        // 连续错误达到上限后，key 被销毁，验证必然失败
        for ($i = 0; $i < $max + 1; $i++) {
            $this->assertFalse(captcha_verify($result['key'], 'click', $clicks), "第 {$i} 次错误验证应失败");
        }
        $this->assertNull($storage->get($result['key']), '达到次数上限后 key 应被删除');
    }

    #[Test]
    public function captcha_generates_unique_keys(): void
    {
        $r1 = captcha_create('click');
        $r2 = captcha_create('click');

        $this->assertNotEquals($r1['key'], $r2['key'], '每次生成的 key 应不同');
    }
}
