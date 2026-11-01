<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace tests;

use app\admin\controller\BaseController;
use PHPUnit\Framework\TestCase;
use support\Db;
use support\Redis;
use support\Request;

/**
 * 暴露 protected confirmPassword 供测试直接调用
 */
final class ConfirmPasswordProbe extends BaseController
{
    public function confirm(int $adminId, string $password, Request $request): ?string
    {
        return $this->confirmPassword($adminId, $password, $request);
    }
}

/**
 * 二次确认密码失败限流：原子自增，5 次后锁定 5 分钟
 */
final class ConfirmThrottleTest extends TestCase
{
    private const THROTTLED = '密码验证失败次数过多，请 5 分钟后再试';

    private int $adminId;

    public static function setUpBeforeClass(): void
    {
        try {
            Db::connection()->getPdo();
        } catch (\Throwable $e) {
            self::markTestSkipped('数据库不可用: ' . $e->getMessage());
        }
        try {
            Redis::ping();
        } catch (\Throwable $e) {
            self::markTestSkipped('Redis 不可用: ' . $e->getMessage());
        }
    }

    protected function setUp(): void
    {
        $this->adminId = random_int(9_300_000_000_000_000, 9_399_999_999_999_999);
        Db::table('admin_user')->insert([
            'id'       => $this->adminId,
            'username' => 'phpunit_confirm_' . bin2hex(random_bytes(6)),
            'password' => password_hash('secret123', PASSWORD_BCRYPT),
            'status'   => 1,
        ]);
        Redis::del($this->throttleKey());
    }

    protected function tearDown(): void
    {
        try {
            Db::table('admin_user')->where('id', $this->adminId)->delete();
        } catch (\Throwable) {
        }
        try {
            Redis::del($this->throttleKey());
        } catch (\Throwable) {
        }
    }

    private function throttleKey(): string
    {
        return 'pwd_confirm_fail:' . $this->adminId;
    }

    private function request(): Request
    {
        return new Request("GET /admin/profile HTTP/1.1\r\nHost: localhost\r\n\r\n");
    }

    public function testFifthWrongAttemptFailsAndSixthIsThrottledBeforePasswordCheck(): void
    {
        $probe = new ConfirmPasswordProbe();

        for ($i = 1; $i <= 5; $i++) {
            $error = $probe->confirm($this->adminId, 'wrong-' . $i, $this->request());
            self::assertNotNull($error);
            self::assertNotSame(self::THROTTLED, $error);
        }
        self::assertSame(5, (int) Redis::get($this->throttleKey()));

        // 第 6 次即使密码正确也在密码校验前被限流，避免限流窗口内继续验证
        self::assertSame(self::THROTTLED, $probe->confirm($this->adminId, 'wrong-6', $this->request()));
        self::assertSame(self::THROTTLED, $probe->confirm($this->adminId, 'secret123', $this->request()));
        self::assertSame(7, (int) Redis::get($this->throttleKey()));
    }

    public function testCorrectPasswordResetsCounter(): void
    {
        $probe = new ConfirmPasswordProbe();

        for ($i = 1; $i <= 4; $i++) {
            self::assertNotSame(self::THROTTLED, $probe->confirm($this->adminId, 'wrong-' . $i, $this->request()));
        }
        self::assertSame(4, (int) Redis::get($this->throttleKey()));

        self::assertNull($probe->confirm($this->adminId, 'secret123', $this->request()));
        self::assertSame(0, (int) Redis::exists($this->throttleKey()));

        // 计数已清零，下一次失败重新从 1 开始而不是直接限流
        self::assertNotSame(self::THROTTLED, $probe->confirm($this->adminId, 'wrong-again', $this->request()));
        self::assertSame(1, (int) Redis::get($this->throttleKey()));
    }

    public function testEmptyPasswordIsRejectedBeforeThrottleAccounting(): void
    {
        $probe = new ConfirmPasswordProbe();

        self::assertNotNull($probe->confirm($this->adminId, '', $this->request()));
        self::assertSame(0, (int) Redis::exists($this->throttleKey()));
    }
}
