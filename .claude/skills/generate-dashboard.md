---
name: generate-dashboard
description: Dashboard visualization panels with chart widgets and data cards
skill_type: implementation
---

# 生成仪表盘

为管理端和客户端生成可视化仪表盘面板。

## 后端仪表盘 API

文件: `app/admin/controller/DashboardController.php`

```php
<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

namespace app\admin\controller;

use app\model\AdminUser;
use app\model\OperationLog;
use support\Request;
use support\Response;

class DashboardController extends BaseController
{
    /**
     * 仪表盘首页数据
     * GET /admin/dashboard
     */
    public function index(Request $request): Response
    {
        return $this->success([
            // 统计卡片
            'stats' => $this->getStats(),
            // 趋势图数据
            'trends' => $this->getTrends(),
            // 分布图数据
            'distribution' => $this->getDistribution(),
            // 最近操作日志
            'recent_logs' => $this->getRecentLogs(),
        ]);
    }

    /**
     * 获取统计卡片数据
     */
    private function getStats(): array
    {
        $today = date('Y-m-d');

        return [
            [
                'label' => '用户总数',
                'value' => AdminUser::count(),
                'icon' => 'people',
                'color' => '#1677FF',
                'trend' => $this->calcTrend('AdminUser'),
            ],
            [
                'label' => '今日新增',
                'value' => AdminUser::whereDate('created_at', $today)->count(),
                'icon' => 'person_add',
                'color' => '#52C41A',
            ],
            [
                'label' => '活跃用户',
                'value' => AdminUser::whereDate('last_login_at', $today)->count(),
                'icon' => 'bolt',
                'color' => '#FA8C16',
            ],
            [
                'label' => '操作日志',
                'value' => OperationLog::whereDate('created_at', $today)->count(),
                'icon' => 'description',
                'color' => '#722ED1',
            ],
        ];
    }

    /**
     * 获取趋势图数据（近30天）
     */
    private function getTrends(): array
    {
        $dates = [];
        $userGrowth = [];
        $logCounts = [];

        for ($i = 29; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
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

    /**
     * 获取分布数据
     */
    private function getDistribution(): array
    {
        return [
            'user_status' => [
                ['name' => '启用', 'value' => AdminUser::where('status', 1)->count()],
                ['name' => '禁用', 'value' => AdminUser::where('status', 0)->count()],
            ],
            'login_source' => [
                ['name' => 'Web端', 'value' => 280],
                ['name' => '移动端', 'value' => 65],
                ['name' => 'API', 'value' => 42],
            ],
        ];
    }

    /**
     * 最近操作日志
     */
    private function getRecentLogs(): array
    {
        return OperationLog::with('user')
            ->orderBy('id', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($log) {
                return [
                    'id' => $this->encodeId($log->id),
                    'user' => $log->user->username ?? '系统',
                    'action' => $log->action,
                    'ip' => $log->ip,
                    'created_at' => $log->created_at,
                ];
            })
            ->toArray();
    }

    /**
     * 计算趋势百分比
     */
    private function calcTrend(string $model): float
    {
        $today = AdminUser::whereDate('created_at', date('Y-m-d'))->count();
        $yesterday = AdminUser::whereDate('created_at', date('Y-m-d', strtotime('-1 day')))->count();

        if ($yesterday == 0) return $today > 0 ? 100.0 : 0.0;
        return round(($today - $yesterday) / $yesterday * 100, 1);
    }
}
```

## Flutter 仪表盘页面

文件: `apps/admin_app/lib/app/pages/dashboard/dashboard_page.dart`

```dart
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
import 'package:flutter/material.dart';
import 'package:fl_chart/fl_chart.dart';
import 'package:get/get.dart';
import 'dashboard_controller.dart';

class DashboardPage extends GetView<DashboardController> {
  const DashboardPage({super.key});

  @override
  Widget build(BuildContext context) {
    return Obx(() {
      if (controller.isLoading.value) {
        return const Center(child: CircularProgressIndicator());
      }

      return SingleChildScrollView(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // 页面标题
            Row(
              children: [
                const Text('仪表盘', style: TextStyle(fontSize: 24, fontWeight: FontWeight.bold)),
                const Spacer(),
                // 导出按钮
                PopupMenuButton<String>(
                  icon: const Icon(Icons.download),
                  tooltip: '导出',
                  onSelected: (type) {
                    if (type == 'pdf') controller.exportPdf();
                    if (type == 'excel') controller.exportExcel();
                  },
                  itemBuilder: (_) => const [
                    PopupMenuItem(value: 'pdf', child: ListTile(leading: Icon(Icons.picture_as_pdf), title: Text('导出PDF'))),
                    PopupMenuItem(value: 'excel', child: ListTile(leading: Icon(Icons.table_chart), title: Text('导出Excel'))),
                  ],
                ),
              ],
            ),
            const SizedBox(height: 16),

            // 统计卡片
            _buildStatsGrid(),
            const SizedBox(height: 16),

            // 趋势图
            _buildTrendChart(),
            const SizedBox(height: 16),

            // 分布图和最近日志
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Expanded(flex: 2, child: _buildDistributionChart()),
                const SizedBox(width: 16),
                Expanded(flex: 3, child: _buildRecentLogs()),
              ],
            ),
          ],
        ),
      );
    });
  }

  Widget _buildStatsGrid() {
    return GridView.builder(
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
        crossAxisCount: 4,
        mainAxisExtent: 120,
        crossAxisSpacing: 16,
      ),
      itemCount: controller.stats.length,
      itemBuilder: (context, index) {
        final stat = controller.stats[index];
        return Card(
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Icon(_getIcon(stat['icon']), color: Color(int.parse('0xFF${stat['color'].replaceFirst('#', '')}')), size: 20),
                    const Spacer(),
                    _buildTrendBadge(stat['trend']),
                  ],
                ),
                const Spacer(),
                Text(stat['label'], style: TextStyle(fontSize: 13, color: Colors.grey[600])),
                const SizedBox(height: 4),
                Text('${stat['value']}', style: const TextStyle(fontSize: 28, fontWeight: FontWeight.bold)),
              ],
            ),
          ),
        );
      },
    );
  }

  Widget _buildTrendChart() {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text('数据趋势（近30天）', style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
            const SizedBox(height: 16),
            SizedBox(
              height: 300,
              child: LineChart(
                LineChartData(
                  gridData: FlGridData(show: true, drawVerticalLine: false),
                  titlesData: FlTitlesData(
                    bottomTitles: AxisTitles(sideTitles: SideTitles(showTitles: false)),
                    leftTitles: AxisTitles(sideTitles: SideTitles(showTitles: true, reservedSize: 40)),
                    topTitles: const AxisTitles(sideTitles: SideTitles(showTitles: false)),
                    rightTitles: const AxisTitles(sideTitles: SideTitles(showTitles: false)),
                  ),
                  borderData: FlBorderData(show: false),
                  lineBarsData: [
                    LineChartBarData(
                      spots: controller.trendSpots[0],
                      color: const Color(0xFF1677FF),
                      barWidth: 2,
                      dotData: const FlDotData(show: false),
                    ),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildDistributionChart() {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text('用户状态分布', style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
            const SizedBox(height: 16),
            SizedBox(
              height: 200,
              child: PieChart(
                PieChartData(
                  sections: controller.pieSections,
                  centerSpaceRadius: 40,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildRecentLogs() {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text('最近操作', style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
            const SizedBox(height: 12),
            ...controller.recentLogs.map((log) => ListTile(
              dense: true,
              leading: CircleAvatar(radius: 14, child: Text(log['user'][0].toUpperCase())),
              title: Text(log['action'], style: const TextStyle(fontSize: 13)),
              subtitle: Text(log['created_at'], style: const TextStyle(fontSize: 11)),
              trailing: Text(log['ip'], style: TextStyle(fontSize: 11, color: Colors.grey[500])),
            )),
          ],
        ),
      ),
    );
  }

  Widget _buildTrendBadge(double? trend) {
    if (trend == null) return const SizedBox.shrink();
    final isUp = trend >= 0;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
      decoration: BoxDecoration(
        color: isUp ? Colors.green[50] : Colors.red[50],
        borderRadius: BorderRadius.circular(4),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(isUp ? Icons.arrow_upward : Icons.arrow_downward, size: 12, color: isUp ? Colors.green : Colors.red),
          Text('${trend.abs()}%', style: TextStyle(fontSize: 11, color: isUp ? Colors.green : Colors.red)),
        ],
      ),
    );
  }

  IconData _getIcon(String name) {
    switch (name) {
      case 'people': return Icons.people;
      case 'person_add': return Icons.person_add;
      case 'bolt': return Icons.bolt;
      default: return Icons.description;
    }
  }
}
```

## 仪表盘规范

1. 仪表盘数据通过独立 API 接口获取，不分散在各业务控制器中
2. 统计卡片使用 GridView 网格布局，保持 4 列对齐
3. 图表使用 `fl_chart` 库，支持折线图、饼图、柱状图
4. 数据支持按时间范围筛选（今天/本周/本月/自定义）
5. 导出 PDF 时保留图表渲染和版权信息
6. 仪表盘数据缓存 5 分钟，避免频繁查询
