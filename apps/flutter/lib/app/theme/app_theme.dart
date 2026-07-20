// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
import 'package:flutter/material.dart';

const _blue = Color(0xFF4F6EF7);
const _bg = Color(0xFFF5F6FA);

final _dataTableTheme = DataTableThemeData(
  dataRowMinHeight: 48, dataRowMaxHeight: 48,
  headingRowHeight: 40, dividerThickness: 0,
  headingTextStyle: const TextStyle(fontWeight: FontWeight.w600, fontSize: 12, color: Color(0xFF9CA3AF)),
  dataTextStyle: const TextStyle(fontSize: 13),
);

final _cardTheme = CardThemeData(
  elevation: 0, color: Colors.white, margin: EdgeInsets.zero,
  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12), side: BorderSide.none),
);

const _inputTheme = InputDecorationTheme(
  border: OutlineInputBorder(borderRadius: BorderRadius.all(Radius.circular(10))),
  contentPadding: EdgeInsets.symmetric(horizontal: 14, vertical: 12),
  filled: true, fillColor: _bg,
);

class AppTheme {
  static final ThemeData light = ThemeData(
    useMaterial3: true,
    colorScheme: ColorScheme.fromSeed(seedColor: _blue, primary: _blue, surface: Colors.white, surfaceContainerLowest: _bg, brightness: Brightness.light),
    dataTableTheme: _dataTableTheme, cardTheme: _cardTheme,
    inputDecorationTheme: _inputTheme,
    dividerTheme: const DividerThemeData(space: 0, thickness: 1, color: Color(0xFFF0F0F5)),
    filledButtonTheme: FilledButtonThemeData(style: FilledButton.styleFrom(backgroundColor: _blue, foregroundColor: Colors.white, shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)), minimumSize: const Size(0, 44))),
    elevatedButtonTheme: ElevatedButtonThemeData(style: ElevatedButton.styleFrom(shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)), minimumSize: const Size(0, 44))),
    chipTheme: ChipThemeData(shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)), side: BorderSide.none),
  );

  static final ThemeData dark = ThemeData(
    useMaterial3: true,
    colorScheme: ColorScheme.fromSeed(seedColor: _blue, primary: _blue, brightness: Brightness.dark),
    dataTableTheme: _dataTableTheme, cardTheme: _cardTheme.copyWith(color: const Color(0xFF1C1C2A)),
    inputDecorationTheme: _inputTheme.copyWith(fillColor: const Color(0xFF2A2A3C)),
  );
}
