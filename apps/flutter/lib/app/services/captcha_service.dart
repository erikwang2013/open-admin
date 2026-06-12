// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
import 'dart:convert';
import 'dart:typed_data';
import 'package:dio/dio.dart';

enum CaptchaType { click, rotate, slider }

class CaptchaService {
  final Dio _dio;
  CaptchaService(this._dio);

  Future<CaptchaData> generate({String difficulty = 'medium'}) async {
    final resp = await _dio.post('/api/captcha/generate', data: {'difficulty': difficulty});
    if (resp.data['code'] != 0) throw Exception(resp.data['message']);
    return CaptchaData.fromJson(resp.data['data']);
  }

  Future<bool> verify(CaptchaData captcha, dynamic answer) async {
    final data = <String, dynamic>{'key': captcha.key};
    if (answer is List) {
      data['clicks'] = answer.map((c) => {'x': c.dx.round(), 'y': c.dy.round()}).toList();
    } else {
      data['clicks'] = [{'x': (answer as num).round(), 'y': 0}];
    }
    final resp = await _dio.post('/api/captcha/verify', data: data);
    return resp.data['data']?['valid'] == true;
  }
}

class CaptchaData {
  final String key;
  final CaptchaType type;
  final Uint8List imageBytes;
  final Map<String, dynamic> extra;

  CaptchaData({required this.key, required this.type, required this.imageBytes, required this.extra});

  static Uint8List _decodeImage(String raw) {
    // API 返回 "data:image/png;base64,XXXX"
    final inner = raw.replaceFirst(RegExp(r'^data:image/\w+;base64,'), '');
    return base64Decode(inner);
  }

  factory CaptchaData.fromJson(Map<String, dynamic> json) {
    final typeStr = json['type'] as String? ?? 'click';
    CaptchaType type;
    switch (typeStr) {
      case 'rotate': type = CaptchaType.rotate;
      case 'slider': type = CaptchaType.slider;
      default: type = CaptchaType.click;
    }
    return CaptchaData(
      key: json['key'],
      type: type,
      imageBytes: _decodeImage(json['image']),
      extra: json['extra'] is Map ? json['extra'] : {},
    );
  }

  // --- click ---
  List<CaptchaTarget> get targets =>
      (extra['targets'] as List?)?.map((t) => CaptchaTarget.fromJson(t)).toList() ?? [];

  // --- slider ---
  int get sliderX => (extra['x'] as num?)?.toInt() ?? 0;
  int get sliderW => (extra['puzzle_w'] as num?)?.toInt() ?? 50;
  int get sliderH => (extra['puzzle_h'] as num?)?.toInt() ?? 50;
  Uint8List? get puzzleBytes {
    final p = extra['puzzle'];
    if (p is String) return _decodeImage(p);
    return null;
  }
}

class CaptchaTarget {
  final int order, x, y;
  final String text;
  CaptchaTarget({required this.order, required this.text, required this.x, required this.y});
  factory CaptchaTarget.fromJson(Map<String, dynamic> json) =>
      CaptchaTarget(order: json['order'], text: json['text'] ?? '', x: json['x'], y: json['y']);
}
