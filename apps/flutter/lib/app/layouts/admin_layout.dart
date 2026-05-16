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
  static const double sidebarWidth = 240;
  static const double sidebarCollapsedWidth = 64;
  static const double headerHeight = 56;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Row(
        children: [
          _buildSidebar(),
          Expanded(
            child: Column(
              children: [
                _buildHeader(),
                Expanded(
                  child: Container(
                    color: Theme.of(context).colorScheme.surfaceContainerLowest,
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
    final width = _sidebarCollapsed ? sidebarCollapsedWidth : sidebarWidth;
    return AnimatedContainer(
      duration: const Duration(milliseconds: 200),
      width: width,
      child: NavigationDrawer(
        selectedIndex: 0,
        onDestinationSelected: (i) {},
        children: [
          Container(
            height: headerHeight,
            padding: const EdgeInsets.symmetric(horizontal: 16),
            alignment: Alignment.centerLeft,
            child: _sidebarCollapsed
                ? const Icon(Icons.admin_panel_settings, size: 28)
                : const Row(
                    children: [
                      Icon(Icons.admin_panel_settings, size: 24),
                      SizedBox(width: 8),
                      Text('管理后台', style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
                    ],
                  ),
          ),
          const Divider(),
          _buildNavItem(Icons.dashboard, '仪表盘', '/dashboard', 0),
          _buildNavItem(Icons.people, '用户管理', '/users', 1),
          _buildNavItem(Icons.security, '角色权限', '/roles', 2),
          _buildNavItem(Icons.settings, '系统配置', '/settings', 3),
          _buildNavItem(Icons.description, '操作日志', '/logs', 4),
        ],
      ),
    );
  }

  Widget _buildNavItem(IconData icon, String title, String route, int index) {
    return NavigationDrawerDestination(
      icon: Icon(icon, size: 20),
      label: Text(title),
      selectedIcon: Icon(icon, size: 20, color: Theme.of(context).colorScheme.primary),
    );
  }

  Widget _buildHeader() {
    return Container(
      height: headerHeight,
      padding: const EdgeInsets.symmetric(horizontal: 16),
      decoration: BoxDecoration(
        color: Theme.of(context).colorScheme.surface,
        border: Border(
          bottom: BorderSide(color: Theme.of(context).dividerColor),
        ),
      ),
      child: Row(
        children: [
          IconButton(
            icon: Icon(_sidebarCollapsed ? Icons.menu_open : Icons.menu),
            tooltip: _sidebarCollapsed ? '展开菜单' : '收起菜单',
            onPressed: () => setState(() => _sidebarCollapsed = !_sidebarCollapsed),
          ),
          const Spacer(),
          PopupMenuButton<String>(
            offset: const Offset(0, headerHeight),
            child: const Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                CircleAvatar(radius: 14, child: Icon(Icons.person, size: 16)),
                SizedBox(width: 8),
                Text('管理员', style: TextStyle(fontSize: 14)),
                Icon(Icons.arrow_drop_down, size: 20),
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
