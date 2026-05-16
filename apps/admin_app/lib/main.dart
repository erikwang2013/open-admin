// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:responsive_framework/responsive_framework.dart';
import 'app/theme/app_theme.dart';
import 'app/layouts/admin_layout.dart';
import 'app/pages/dashboard/dashboard_page.dart';

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
      getPages: [
        GetPage(name: '/', page: () => AdminLayout(child: const DashboardPage())),
        GetPage(name: '/dashboard', page: () => AdminLayout(child: const DashboardPage())),
      ],
      initialRoute: '/',
    );
  }
}
