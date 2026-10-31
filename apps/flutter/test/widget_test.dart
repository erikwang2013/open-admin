// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:get/get.dart';
import 'package:admin_app/app/pages/login/login_page.dart';

void main() {
  testWidgets('登录页渲染用户名和密码输入框', (WidgetTester tester) async {
    await tester.pumpWidget(GetMaterialApp(home: const LoginPage()));
    expect(find.byType(TextField), findsNWidgets(2));
  });
}
