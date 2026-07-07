// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
import 'package:flutter/material.dart';
import 'package:dio/dio.dart';
import 'package:get/get.dart';
import '../../services/api_service.dart';
import '../../services/auth_service.dart';
import '../../services/captcha_service.dart';
import '../../services/encryption_service.dart';
import 'captcha/click.dart';
import 'captcha/slider.dart';
import 'captcha/rotate.dart';

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
  final _clickKey = GlobalKey<ClickCaptchaState>();
  final _sliderKey = GlobalKey<SliderCaptchaState>();
  final _rotateKey = GlobalKey<RotateCaptchaState>();

  @override
  void initState() { super.initState(); _reload(); }

  Future<void> _reload() async {
    try {
      _data = await _svc.generate();
      if (mounted) setState(() => _error = null);
    } catch (_) { if (mounted) setState(() => _error = '验证码加载失败'); }
  }

  Future<void> _login() async {
    final u = _userCtrl.text.trim(), p = _passCtrl.text;
    if (u.isEmpty || p.isEmpty) { setState(() => _error = '请输入用户名和密码'); return; }
    if (_data == null) { setState(() => _error = '请加载验证码'); return; }
    setState(() => _loading = true);
    try {
      dynamic answer;
      switch (_data!.type) {
        case CaptchaType.click: answer = _clickKey.currentState!.answer; break;
        case CaptchaType.slider: answer = _sliderKey.currentState!.answer; break;
        case CaptchaType.rotate: answer = _rotateKey.currentState!.answer; break;
      }
      final ok = await _svc.verify(_data!, answer);
      if (!ok) {
        if (mounted) { setState(() => _error = '验证码校验失败'); _reload(); _loading = false; }
        return;
      }
      final resp = await _dio.post('/api/auth/login', data: {
        'username': u,
        'password': EncryptionService.encrypt(p),
        'captcha_key': _data!.key
      });
      if (resp.data['code'] == 0) {
        final d = resp.data['data'];
        await AuthService.saveLogin(token: d['access_token'], refreshToken: d['refresh_token'], username: d['user']['username']);
        if (mounted) Get.offAllNamed('/dashboard');
      } else {
        if (mounted) { setState(() => _error = resp.data['message'] ?? '登录失败'); _reload(); }
      }
    } catch (_) { if (mounted) { setState(() => _error = '网络错误'); _reload(); } }
    finally { if (mounted) setState(() => _loading = false); }
  }

  @override
  void dispose() { _userCtrl.dispose(); _passCtrl.dispose(); super.dispose(); }

  Widget _captchaUI() {
    if (_data == null) return const Center(child: CircularProgressIndicator());
    switch (_data!.type) {
      case CaptchaType.slider: return SliderCaptcha(key: _sliderKey, data: _data!);
      case CaptchaType.rotate: return RotateCaptcha(key: _rotateKey, data: _data!);
      default: return ClickCaptcha(key: _clickKey, data: _data!);
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
          const SizedBox(height: 8),
          TextButton.icon(onPressed: _reload, icon: const Icon(Icons.refresh, size: 16), label: const Text('换一张')),
          if (_error != null) Padding(padding: const EdgeInsets.only(top: 8),
            child: Container(padding: const EdgeInsets.all(10), decoration: BoxDecoration(color: Colors.red.shade50, borderRadius: BorderRadius.circular(6)),
              child: Row(children: [Icon(Icons.error_outline, color: Colors.red.shade400, size: 18), const SizedBox(width: 8), Expanded(child: Text(_error!, style: const TextStyle(color: Colors.red, fontSize: 13)))])),
          ),
          const SizedBox(height: 8),
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
