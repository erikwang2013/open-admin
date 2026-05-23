---
name: generate-flutter
description: Initialize Flutter app with PC-style admin panel web layout under apps/ directory
skill_type: implementation
---

# 生成 Flutter 管理后台

在 `apps/` 目录下创建 Flutter 项目，Web 端按 PC 管理后台风格设计。

## 设计原则

### Web 端布局（PC 风格）
- **不是**移动端 App 风格的响应式布局
- 经典管理后台三栏布局: 侧边栏 + 顶栏 + 内容区
- 数据密集型页面使用表格展示
- 弹窗使用 Dialog 而非 BottomSheet
- 表单使用桌面端对齐方式（label 在左，input 在右）
- 鼠标悬停交互，支持右键菜单

### 与移动端 App 的区别
- Web: 侧边栏可折叠，表格密度高，支持多选批量操作
- App: 底部导航栏，卡片列表，滑动操作

## 创建步骤

### 1. 创建 Flutter 项目

```bash
cd apps
flutter create --org com.erik admin_app
```

### 2. 添加依赖

修改 `apps/admin_app/pubspec.yaml`:

```yaml
dependencies:
  flutter:
    sdk: flutter
  # 状态管理
  get: ^4.6.6
  # 网络请求
  dio: ^5.4.0
  # 数据表格
  data_table_2: ^2.5.0
  # 图表
  fl_chart: ^0.68.0
  # PDF 导出
  pdf: ^3.10.0
  printing: ^5.12.0
  # Excel 导出
  excel: ^4.0.0
  # 文件下载
  file_saver: ^0.2.0
  # 本地存储
  shared_preferences: ^2.2.0
  # 路由
  go_router: ^13.0.0
  # 响应式
  responsive_framework: ^1.4.0
  # 国家旗帜
  country_icons: ^3.0.0
```

### 3. 入口文件结构

文件: `apps/admin_app/lib/main.dart`

```dart
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:responsive_framework/responsive_framework.dart';
import 'app/routes/app_pages.dart';
import 'app/theme/app_theme.dart';

void main() {
  runApp(const AdminApp());
}

class AdminApp extends StatelessWidget {
  const AdminApp({super.key});

  @override
  Widget build(BuildContext context) {
    return GetMaterialApp(
      title: '开放管理后台',
      debugShowCheckedModeBanner: false,
      theme: AppTheme.light,
      darkTheme: AppTheme.dark,
      builder: (context, child) => ResponsiveBreakpoints.builder(
        child: child!,
        breakpoints: [
          const Breakpoint(start: 0, end: 768, name: MOBILE),
          const Breakpoint(start: 769, end: 1920, name: DESKTOP),
        ],
      ),
      getPages: AppPages.routes,
      initialRoute: AppPages.initial,
    );
  }
}
```

### 4. 主布局组件（PC 风格）

文件: `apps/admin_app/lib/app/layouts/admin_layout.dart`

```dart
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
import 'package:flutter/material.dart';

class AdminLayout extends StatefulWidget {
  final Widget child;
  const AdminLayout({super.key, required this.child});

  @override
  State<AdminLayout> createState() => _AdminLayoutState();
}

class _AdminLayoutState extends State<AdminLayout> {
  bool _sidebarCollapsed = false;
  static const double _sidebarWidth = 240;
  static const double _sidebarCollapsedWidth = 64;
  static const double _headerHeight = 56;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Row(
        children: [
          // 侧边栏
          _buildSidebar(),
          // 主体区域
          Expanded(
            child: Column(
              children: [
                // 顶部栏
                _buildHeader(),
                // 内容区
                Expanded(
                  child: Container(
                    color: Colors.grey[50],
                    padding: const EdgeInsets.all(16),
                    child: widget.child,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildSidebar() {
    return AnimatedContainer(
      duration: const Duration(milliseconds: 200),
      width: _sidebarCollapsed ? _sidebarCollapsedWidth : _sidebarWidth,
      child: Drawer(
        elevation: 2,
        child: Column(
          children: [
            // Logo
            Container(
              height: _headerHeight,
              padding: const EdgeInsets.symmetric(horizontal: 16),
              alignment: Alignment.centerLeft,
              decoration: BoxDecoration(
                border: Border(bottom: BorderSide(color: Colors.grey[200]!)),
              ),
              child: _sidebarCollapsed
                  ? const Icon(Icons.admin_panel_settings)
                  : const Text('管理后台', style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
            ),
            // 菜单项
            Expanded(
              child: ListView(
                padding: const EdgeInsets.symmetric(vertical: 8),
                children: [
                  _buildMenuItem(Icons.dashboard, '仪表盘', '/dashboard'),
                  _buildMenuItem(Icons.people, '用户管理', '/users'),
                  _buildMenuItem(Icons.security, '角色权限', '/roles'),
                  _buildMenuItem(Icons.settings, '系统配置', '/settings'),
                  _buildMenuItem(Icons.description, '操作日志', '/logs'),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildMenuItem(IconData icon, String title, String route) {
    return ListTile(
      leading: Icon(icon, size: _sidebarCollapsed ? 24 : 20),
      title: _sidebarCollapsed ? null : Text(title),
      horizontalTitleGap: 8,
      dense: true,
      onTap: () {},
    );
  }

  Widget _buildHeader() {
    return Container(
      height: _headerHeight,
      padding: const EdgeInsets.symmetric(horizontal: 16),
      decoration: BoxDecoration(
        color: Colors.white,
        boxShadow: [BoxShadow(color: Colors.black.withOpacity(0.05), blurRadius: 2)],
      ),
      child: Row(
        children: [
          IconButton(
            icon: Icon(_sidebarCollapsed ? Icons.menu_open : Icons.menu),
            onPressed: () => setState(() => _sidebarCollapsed = !_sidebarCollapsed),
          ),
          const Spacer(),
          // 用户信息下拉
          PopupMenuButton(
            icon: const Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                CircleAvatar(radius: 14, child: Icon(Icons.person, size: 16)),
                SizedBox(width: 8),
                Text('管理员'),
                Icon(Icons.arrow_drop_down),
              ],
            ),
            itemBuilder: (_) => [
              const PopupMenuItem(value: 'profile', child: Text('个人中心')),
              const PopupMenuItem(value: 'logout', child: Text('退出登录')),
            ],
          ),
        ],
      ),
    );
  }
}
```

### 5. 主题配置

文件: `apps/admin_app/lib/app/theme/app_theme.dart`

```dart
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
import 'package:flutter/material.dart';

class AppTheme {
  static final ThemeData light = ThemeData(
    useMaterial3: true,
    colorSchemeSeed: const Color(0xFF1677FF),
    brightness: Brightness.light,
    // 表格
    dataTableTheme: const DataTableThemeData(
      dataRowMinHeight: 48,
      dataRowMaxHeight: 48,
      headingRowHeight: 40,
    ),
    // 卡片
    cardTheme: CardTheme(
      elevation: 1,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
    ),
    // 输入框
    inputDecorationTheme: InputDecorationTheme(
      border: OutlineInputBorder(borderRadius: BorderRadius.circular(6)),
      contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      isDense: true,
    ),
  );

  static final ThemeData dark = ThemeData(
    useMaterial3: true,
    colorSchemeSeed: const Color(0xFF1677FF),
    brightness: Brightness.dark,
  );
}
```

## Web 端特定配置

### 鼠标交互（非触屏）
- 表格行悬停高亮
- Tooltip 提示
- 右键菜单使用 `ContextMenu` 组件
- 双击行展开详情
- 快捷键支持（Ctrl+S 保存，Delete 删除）

### 页面模板

数据列表页（PC 风格）:
```dart
class DataListPage extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        // 搜索区域
        _buildSearchBar(),
        const SizedBox(height: 16),
        // 表格卡片
        Expanded(
          child: Card(
            child: Column(
              children: [
                // 表格工具栏
                _buildTableToolbar(),
                // 数据表格
                Expanded(child: _buildDataTable()),
                // 分页栏
                _buildPagination(),
              ],
            ),
          ),
        ),
      ],
    );
  }
}
```

## 关键规范

1. 所有 Dart 文件头部必须包含版权声明
2. Web 端使用 `admin_layout.dart` 布局，不使用移动端的 `Scaffold(bottomNavigationBar: ...)`
3. 表格使用 `DataTable` 或 `data_table_2` 的 `PaginatedDataTable2`
4. 响应式仅处理 Web 端不同分辨率，不降级为移动端布局
5. 弹窗全部使用 `Dialog`，不使用 `BottomSheet`
