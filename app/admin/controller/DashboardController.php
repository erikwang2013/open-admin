<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace app\admin\controller;

use app\model\AdminUser;
use app\common\EncryptionService;
use app\model\OperationLog;
use support\Request;

class DashboardController extends BaseController
{
    /**
     * 仪表盘数据
     * GET /admin/dashboard
     */
    public function index(Request $request): Response
    {
        $today = date('Y-m-d');
        $startOfRange = date('Y-m-d', strtotime('-29 days'));

        return $this->success([
            'stats' => $this->getStats($today),
            'trends' => $this->getTrends($startOfRange),
            'distribution' => $this->getDistribution(),
            'recent_logs' => $this->getRecentLogs(),
        ]);
    }

    private function getStats(string $today): array
    {
        $totalUsers = AdminUser::count();
        $todayNew = AdminUser::whereDate('created_at', $today)->count();
        $todayActive = AdminUser::whereDate('last_login_at', $today)->count();
        $todayLogs = OperationLog::whereDate('created_at', $today)->count();

        return [
            [
                'label' => '用户总数',
                'value' => (string) $totalUsers,
                'icon' => 'people',
                'color' => '#1677FF',
                'trend' => $this->calcTrend(AdminUser::class),
            ],
            [
                'label' => '今日新增',
                'value' => (string) $todayNew,
                'icon' => 'person_add',
                'color' => '#52C41A',
            ],
            [
                'label' => '活跃用户',
                'value' => (string) $todayActive,
                'icon' => 'bolt',
                'color' => '#FA8C16',
            ],
            [
                'label' => '操作日志',
                'value' => (string) $todayLogs,
                'icon' => 'description',
                'color' => '#722ED1',
            ],
        ];
    }

    private function getTrends(string $startOfRange): array
    {
        $dates = [];
        $userGrowth = [];
        $logCounts = [];

        for ($i = 29; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("+{$i} days", strtotime($startOfRange)));
            $dates[] = $date;
            $userGrowth[] = AdminUser::whereDate('created_at', '<=', $date)->count();
            $logCounts[] = OperationLog::whereDate('created_at', $date)->count();
        }

        return [
            'dates' => $dates,
            'series' => [
                ['name' => '累计用户', 'data' => $userGrowth, 'color' => '#1677FF'],
                ['name' => '操作日志', 'data' => $logCounts, 'color' => '#52C41A'],
            ],
        ];
    }

    private function getDistribution(): array
    {
        return [
            'user_status' => [
                ['name' => '启用', 'value' => AdminUser::where('status', 1)->count()],
                ['name' => '禁用', 'value' => AdminUser::where('status', 0)->count()],
            ],
        ];
    }

    private function getRecentLogs(): array
    {
        return OperationLog::with('user')
            ->orderBy('id', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($log) {
                $data = $log->toArray();
                $data['id'] = $this->encodeId($data['id']);
                $data['user_name'] = $log->user->username ?? '系统';
                unset($data['user'], $data['user_id']);
                return $data;
            })
            ->toArray();
    }

    private function calcTrend(string $model): ?float
    {
        $today = AdminUser::whereDate('created_at', date('Y-m-d'))->count();
        $yesterday = AdminUser::whereDate('created_at', date('Y-m-d', strtotime('-1 day')))->count();

        if ($yesterday === 0) {
            return $today > 0 ? 100.0 : 0.0;
        }
        return round(($today - $yesterday) / $yesterday * 100, 1);
    }
}
