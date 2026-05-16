// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
import 'package:flutter/material.dart';

class AppTheme {
  static final ThemeData light = ThemeData(
    useMaterial3: true,
    colorSchemeSeed: const Color(0xFF1677FF),
    brightness: Brightness.light,
    dataTableTheme: const DataTableThemeData(
      dataRowMinHeight: 48,
      dataRowMaxHeight: 48,
      headingRowHeight: 40,
      headingTextStyle: TextStyle(fontWeight: FontWeight.w600, fontSize: 13),
      dataTextStyle: TextStyle(fontSize: 13),
    ),
    cardTheme: CardThemeData(
      elevation: 1,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
      margin: EdgeInsets.zero,
    ),
    inputDecorationTheme: InputDecorationTheme(
      border: OutlineInputBorder(borderRadius: BorderRadius.circular(6)),
      contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      isDense: true,
    ),
    dividerTheme: const DividerThemeData(space: 0, thickness: 1),
  );

  static final ThemeData dark = ThemeData(
    useMaterial3: true,
    colorSchemeSeed: const Color(0xFF1677FF),
    brightness: Brightness.dark,
  );
}
