<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace app\admin\controller;

use app\common\HashidsService;
use app\common\SnowflakeService;
use app\common\EncryptionService;
use app\model\AdminUser;
use support\Redis;
use support\Request;
use support\Response;

/**
 * 管理端基础控制器
 * 提供统一响应格式、ID编解码、snowflake ID 生成
 */
class BaseController
{
    /**
     * 成功响应
     */
    protected function success($data = [], string $message = 'success', int $code = 0): Response
    {
        return json(['code' => $code, 'message' => $message, 'data' => $data]);
    }

    /**
     * 失败响应
     */
    protected function fail(string $message = 'fail', int $code = 500, $data = []): Response
    {
        return json(['code' => $code, 'message' => $message, 'data' => $data]);
    }

    /**
     * 将模型 ID 编码为 hashid 字符串
     */
    protected function encodeId(int $id): string
    {
        return HashidsService::encode($id);
    }

    /**
     * 将 hashid 字符串解码为原始 ID
     */
    protected function decodeId(string $hashid): int
    {
        return HashidsService::decode($hashid);
    }

    /**
     * 批量编码数组中的 ID 字段
     */
    protected function encodeIds(array $data, array $idFields = ['id']): array
    {
        return HashidsService::encodeIds($data, $idFields);
    }

    /**
     * 生成新的 snowflake ID
     */
    protected function generateId(): int
    {
        return SnowflakeService::generate();
    }

    /**
     * 校正分页参数 — 页码至少为 1，每页条数限制在 1~100
     */
    protected static function clampPage(int $page, int $limit): array
    {
        return [max(1, $page), $limit < 1 ? 15 : min($limit, 100)];
    }

    /**
     * 二次确认 — 验证当前登录用户密码
     * 敏感操作（删除、导出等）调用此方法确认身份
     *
     * @param int $adminId 当前登录用户 ID
     * @param string $password 用户输入的密码
     * @return string|null 错误消息，null 表示验证通过
     */
    protected function confirmPassword(int $adminId, string $password, Request $request): ?string
    {
        if (empty($password)) {
            return trans('messages.password_confirm_required');
        }

        // 失败计数 5 次 / 300 秒，防止已登录会话被用于反复猜测密码；Redis 故障时不阻断本次确认
        $throttleKey = "pwd_confirm_fail:{$adminId}";
        try {
            // 先原子自增再判断，避免并发请求在 GET 与 INCR 之间同时通过
            $attempts = (int) Redis::eval(
                "local n = redis.call('incr', KEYS[1]) if n == 1 then redis.call('expire', KEYS[1], 300) end return n",
                [$throttleKey],
                1
            );
            if ($attempts > 5) {
                return '密码验证失败次数过多，请 5 分钟后再试';
            }
        } catch (\Throwable) {}

        $decrypted = EncryptionService::decryptTransmission($password);
        $admin = AdminUser::find($adminId);
        if (!$admin || !password_verify($decrypted, $admin->password)) {
            return trans('messages.password_confirm_failed');
        }

        try { Redis::del($throttleKey); } catch (\Throwable) {}

        return null; // 验证通过
    }

    /**
     * 会话吊销失败（Redis 不可用）时的 503 响应
     * 调用方必须在写库前中止，避免数据库已变更但旧会话仍然有效
     */
    protected function sessionRevokeFailure(): Response
    {
        return $this->fail('会话吊销失败，请稍后重试', 503)->withStatus(503);
    }
}
