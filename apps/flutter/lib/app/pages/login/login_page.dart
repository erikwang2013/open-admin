// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
import 'dart:math';
import 'package:flutter/material.dart';
import 'package:dio/dio.dart';
import '../../services/api_service.dart';
import '../../services/auth_service.dart';
import '../../services/captcha_service.dart';

const _kSrcW = 400.0, _kSrcH = 250.0;
const _kAspect = _kSrcW / _kSrcH; // 8:5

class LoginPage extends StatefulWidget {
  const LoginPage({super.key});
  @override
  State<LoginPage> createState() => _LoginPageState();
}

class _LoginPageState extends State<LoginPage> {
  final _userCtrl = TextEditingController();
  final _passCtrl = TextEditingController();
  final _dio = Dio(BaseOptions(baseUrl: ApiService.baseUrl, headers: {'API-Version': 'v1'}));
  late final _svc = CaptchaService(Dio(BaseOptions(baseUrl: ApiService.baseUrl, headers: {'API-Version': 'v1'})));

  bool _loading = false;
  String? _error;
  CaptchaData? _data;
  final List<Offset> _clicks = [];
  double _val = 0; // slider or rotate value

  @override
  void initState() { super.initState(); _reload(); }

  Future<void> _reload() async {
    try {
      _data = await _svc.generate();
      if (mounted) setState(() { _clicks.clear(); _val = 0; _error = null; });
    } catch (_) { if (mounted) setState(() => _error = '验证码加载失败'); }
  }

  Future<void> _login() async {
    final u = _userCtrl.text.trim(), p = _passCtrl.text;
    if (u.isEmpty || p.isEmpty) { setState(() => _error = '请输入用户名和密码'); return; }
    if (_data == null) { setState(() => _error = '请加载验证码'); return; }
    setState(() => _loading = true);
    try {
      // 1. 先校验验证码
      final answer = _data!.type == CaptchaType.click ? _clicks : _val;
      final ok = await _svc.verify(_data!, answer);
      if (!ok) {
        if (mounted) { setState(() => _error = '验证码校验失败，请重试'); _reload(); _loading = false; }
        return;
      }
      // 2. 验证通过后登录（仅提交 captcha_key）
      final resp = await _dio.post('/api/auth/login', data: {
        'username': u, 'password': p, 'captcha_key': _data!.key,
      });
      if (resp.data['code'] == 0) {
        await AuthService.saveLogin(
          token: resp.data['data']['access_token'],
          refreshToken: resp.data['data']['refresh_token'],
          username: resp.data['data']['user']['username'],
        );
        if (mounted) Navigator.of(context).pushReplacementNamed('/dashboard');
      } else {
        if (mounted) { setState(() => _error = resp.data['message'] ?? '登录失败'); _reload(); }
      }
    } catch (_) { if (mounted) { setState(() => _error = '网络错误'); _reload(); } }
    finally { if (mounted) setState(() => _loading = false); }
  }

  @override
  void dispose() { _userCtrl.dispose(); _passCtrl.dispose(); super.dispose(); }

  // ============ 自适应容器 ============
  // 所有验证码共享同一容器，内部坐标以 400x250 为基准，自动适配实际尺寸
  Widget _wrapCaptcha(Widget Function(double w, double h) builder) =>
    LayoutBuilder(builder: (_, c) {
      final w = c.maxWidth;
      final h = w / _kAspect;
      return ClipRRect(
        borderRadius: BorderRadius.circular(10),
        child: Container(
          width: w, height: h,
          decoration: BoxDecoration(color: Colors.grey.shade100, border: Border.all(color: Colors.grey.shade300)),
          child: builder(w, h),
        ),
      );
    });

  static double _s(double v, double real, double src) => v / src * real;

  // ============ Click ============
  // ============ Click ============
  Widget _clickUI() {
    final d = _data!;
    final ts = d.targets;
    return Column(children: [
      Text('按顺序点击: ${ts.map((t) => '"${t['text']}"').join(' → ')}', style: const TextStyle(fontSize: 13)),
      const SizedBox(height: 8),
      _wrapCaptcha((w, h) => GestureDetector(
        onTapUp: (e) {
          final tap = Offset(e.localPosition.dx / w * _kSrcW, e.localPosition.dy / h * _kSrcH);
          final near = _clicks.indexWhere((c) => (c - tap).distance < 18);
          setState(() => near >= 0 ? _clicks.removeAt(near) : _clicks.length < ts.length ? _clicks.add(tap) : null);
        },
        child: Stack(children: [
          Image.memory(d.imageBytes, width: w, height: h, fit: BoxFit.fill),
          for (int i = 0; i < _clicks.length; i++)
            Positioned(
              left: _s(_clicks[i].dx, w, _kSrcW) - 12, top: _s(_clicks[i].dy, h, _kSrcH) - 12,
              child: Container(width: 24, height: 24, decoration: const BoxDecoration(shape: BoxShape.circle, color: Colors.redAccent),
                child: const Icon(Icons.close, color: Colors.white, size: 14)),
            ),
        ]),
      )),
      const SizedBox(height: 4),
      Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
        Text('${_clicks.length}/${ts.length} 已标记', style: const TextStyle(fontSize: 12, color: Colors.grey)),
        TextButton.icon(onPressed: _reload, icon: const Icon(Icons.refresh, size: 16), label: const Text('换一张')),
      ]),
    ]);
  }

  // ============ Slider ============
  // ============ Slider ============
  Widget _sliderUI() {
    final d = _data!;
    final puzzle = d.puzzleBytes;
    return Column(children: [
      const Text('拖动拼图片对齐缺口', style: TextStyle(fontSize: 13)),
      const SizedBox(height: 8),
      _wrapCaptcha((w, h) => Stack(children: [
        Image.memory(d.imageBytes, width: w, height: h, fit: BoxFit.fill),
        if (puzzle != null)
          Positioned(
            left: _s(_val, w, _kSrcW).clamp(0, w - _s(50, w, _kSrcW)),
            top: (h - _s(40, w, _kSrcW)) / 2,
            child: Image.memory(puzzle, width: _s(50, w, _kSrcW), height: _s(40, w, _kSrcW)),
          ),
      ])),
      const SizedBox(height: 8),
      Slider.adaptive(value: _val, min: 0, max: _kSrcW, onChanged: (v) => setState(() => _val = v)),
      Center(child: TextButton.icon(onPressed: _reload, icon: const Icon(Icons.refresh, size: 16), label: const Text('换一张'))),
    ]);
  }

  // ============ Rotate ============
  // ============ Rotate ============
  Widget _rotateUI() {
    final d = _data!;
    return Column(children: [
      const Text('旋转图片使标记回到正上方', style: TextStyle(fontSize: 13)),
      const SizedBox(height: 10),
      _wrapCaptcha((w, h) => Stack(children: [
        Positioned.fill(child: Container(color: Colors.grey.shade200)),
        Center(child: Transform.rotate(
          angle: _val * pi / 180,
          child: Image.memory(d.imageBytes, width: h * 0.9, height: h * 0.9, fit: BoxFit.contain),
        )),
        Positioned(top: 4, left: 0, right: 0,
          child: Icon(Icons.arrow_drop_up, size: w * 0.08, color: Colors.red)),
      ])),
      const SizedBox(height: 8),
      Row(children: [
        const Icon(Icons.rotate_left, size: 18, color: Colors.grey),
        Expanded(child: Slider(value: _val, min: 0, max: 359, divisions: 359, onChanged: (v) => setState(() => _val = v))),
        const Icon(Icons.rotate_right, size: 18, color: Colors.grey),
      ]),
      Center(child: TextButton.icon(onPressed: _reload, icon: const Icon(Icons.refresh, size: 16), label: const Text('换一张'))),
    ]);
  }

  Widget _captchaUI() {
    if (_data == null) return _wrapCaptcha((w, h) => const Center(child: CircularProgressIndicator()));
    switch (_data!.type) {
      case CaptchaType.slider: return _sliderUI();
      case CaptchaType.rotate: return _rotateUI();
      default: return _clickUI();
    }
  }

  @override
  Widget build(BuildContext c) => Scaffold(
    backgroundColor: Colors.white,
    body: Center(child: SingleChildScrollView(
      padding: const EdgeInsets.all(32),
      child: ConstrainedBox(constraints: const BoxConstraints(maxWidth: 440), child: Column(
        mainAxisSize: MainAxisSize.min, children: [
          const Icon(Icons.admin_panel_settings, size: 56, color: Color(0xFF1677FF)),
          const SizedBox(height: 8),
          const Text('开放管理后台', style: TextStyle(fontSize: 20, fontWeight: FontWeight.bold)),
          const SizedBox(height: 28),
          TextField(controller: _userCtrl, decoration: const InputDecoration(labelText: '用户名', prefixIcon: Icon(Icons.person_outline), border: OutlineInputBorder())),
          const SizedBox(height: 14),
          TextField(controller: _passCtrl, obscureText: true, decoration: const InputDecoration(labelText: '密码', prefixIcon: Icon(Icons.lock_outline), border: OutlineInputBorder())),
          const SizedBox(height: 20),
          _captchaUI(),
          const SizedBox(height: 14),
          if (_error != null) Padding(padding: const EdgeInsets.only(bottom: 10),
            child: Container(padding: const EdgeInsets.all(10), decoration: BoxDecoration(color: Colors.red.shade50, borderRadius: BorderRadius.circular(6)),
              child: Row(children: [Icon(Icons.error_outline, color: Colors.red.shade400, size: 18), const SizedBox(width: 8), Expanded(child: Text(_error!, style: const TextStyle(color: Colors.red, fontSize: 13)))])),
          ),
          SizedBox(width: double.infinity, height: 44, child: FilledButton(
            onPressed: _loading ? null : _login,
            child: _loading ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white)) : const Text('登 录', style: TextStyle(fontSize: 16)),
          )),
          const SizedBox(height: 16),
          Text('Copyright (c) 2026 erik — https://erik.xyz', style: TextStyle(fontSize: 11, color: Colors.grey.shade400)),
        ],
      )),
    )),
  );
}
