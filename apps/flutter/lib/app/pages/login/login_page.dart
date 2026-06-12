// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
import 'package:flutter/material.dart';
import 'package:dio/dio.dart';
import '../../services/api_service.dart';
import '../../services/auth_service.dart';
import '../../services/captcha_service.dart';

class LoginPage extends StatefulWidget {
  const LoginPage({super.key});
  @override
  State<LoginPage> createState() => _LoginPageState();
}

class _LoginPageState extends State<LoginPage> {
  final _userCtrl = TextEditingController();
  final _passCtrl = TextEditingController();
  static final _h = {'API-Version': 'v1'};
  final _dio = Dio(BaseOptions(baseUrl: ApiService.baseUrl, headers: _h));
  late final CaptchaService _captcha = CaptchaService(Dio(BaseOptions(baseUrl: ApiService.baseUrl, headers: _h)));

  bool _loading = false;
  String? _error;
  CaptchaData? _data;
  final List<Offset> _clicks = [];
  double _sliderVal = 0;

  @override
  void initState() { super.initState(); _refresh(); }

  Future<void> _refresh() async {
    try {
      _data = await _captcha.generate();
      setState(() { _clicks.clear(); _sliderVal = 0; _error = null; });
    } catch (_) { setState(() => _error = '验证码加载失败'); }
  }

  void _onTap(TapUpDetails d, BoxConstraints c) {
    if (_data == null || _clicks.length >= _data!.targets.length) return;
    setState(() {
      _clicks.add(Offset((d.localPosition.dx / c.maxWidth * 400).roundToDouble(),
                         (d.localPosition.dy / c.maxHeight * 250).roundToDouble()));
    });
  }

  Future<void> _doLogin() async {
    final u = _userCtrl.text.trim(), p = _passCtrl.text;
    if (u.isEmpty || p.isEmpty) { setState(() => _error = '请输入用户名和密码'); return; }
    if (_data == null) { setState(() => _error = '请加载验证码'); return; }
    setState(() => _loading = true);
    try {
      final resp = await _dio.post('/api/auth/login', data: {
        'username': u, 'password': p, 'captcha_key': _data!.key,
        'clicks': _data!.type == CaptchaType.click
            ? _clicks.map((c) => {'x': c.dx.round(), 'y': c.dy.round()}).toList()
            : [{'x': _sliderVal.round(), 'y': 0}],
      });
      if (resp.data['code'] == 0) {
        final d = resp.data['data'];
        await AuthService.saveLogin(token: d['access_token'], refreshToken: d['refresh_token'], username: d['user']['username']);
        if (mounted) Navigator.of(context).pushReplacementNamed('/dashboard');
      } else {
        setState(() => _error = resp.data['message'] ?? '登录失败');
        _refresh();
      }
    } catch (_) { setState(() => _error = '网络错误'); _refresh(); }
    finally { setState(() => _loading = false); }
  }

  @override
  void dispose() { _userCtrl.dispose(); _passCtrl.dispose(); super.dispose(); }

  // ============ Click Captcha ============
  Widget _clickWidget() {
    final d = _data!;
    return Column(children: [
      Text('请按文字顺序点击图中对应位置: ${d.targets.map((t) => '"${t.text}"').join(' → ')}',
          style: const TextStyle(fontSize: 13, color: Colors.black87)),
      const SizedBox(height: 6),
      ClipRRect(borderRadius: BorderRadius.circular(6), child: GestureDetector(
        onTapUp: (e) => _onTap(e, const BoxConstraints.tightFor(width: 400, height: 250)),
        child: Stack(children: [
          Image.memory(d.imageBytes, width: 400, height: 250, fit: BoxFit.fill),
          for (final e in _clicks.asMap().entries)
            Positioned(
              left: e.value.dx / 400 * 400 - 13, top: e.value.dy / 250 * 250 - 13,
              child: _marker(e.key + 1),
            ),
        ]),
      )),
      const SizedBox(height: 4),
      Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
        Text('已点击 ${_clicks.length}/${d.targets.length}', style: const TextStyle(fontSize: 12, color: Colors.grey)),
        TextButton.icon(icon: const Icon(Icons.refresh, size: 16), label: const Text('换一张'), onPressed: _refresh),
      ]),
    ]);
  }

  Widget _marker(int n) => Container(
    width: 26, height: 26,
    decoration: BoxDecoration(color: const Color(0xFF1677FF).withAlpha(210), shape: BoxShape.circle),
    child: Center(child: Text('$n', style: const TextStyle(color: Colors.white, fontSize: 13, fontWeight: FontWeight.bold))),
  );

  // ============ Slider Captcha ============
  Widget _sliderWidget() {
    final d = _data!;
    final puzzle = d.puzzleBytes;
    return Column(children: [
      const Text('将拼图碎片滑动到缺口位置', style: TextStyle(fontSize: 13, color: Colors.black87)),
      const SizedBox(height: 6),
      // Background image
      ClipRRect(borderRadius: BorderRadius.circular(6),
        child: Image.memory(d.imageBytes, width: 400, height: 200, fit: BoxFit.fill)),
      const SizedBox(height: 8),
      // Puzzle piece + slider
      if (puzzle != null)
        Padding(padding: const EdgeInsets.symmetric(horizontal: 20), child: Stack(children: [
          // Track
          Container(height: 40, decoration: BoxDecoration(color: Colors.grey[200], borderRadius: BorderRadius.circular(4))),
          // Puzzle piece at slider position
          Positioned(
            left: (_sliderVal / 300 * 360).clamp(0, 360),
            top: -45,
            child: Image.memory(puzzle, width: d.sliderW.toDouble(), height: d.sliderH.toDouble()),
          ),
        ])),
      const SizedBox(height: 4),
      Slider(value: _sliderVal, min: 0, max: 300, onChanged: (v) => setState(() => _sliderVal = v)),
      TextButton.icon(icon: const Icon(Icons.refresh, size: 16), label: const Text('换一张'), onPressed: _refresh),
    ]);
  }

  // ============ Rotate Captcha ============
  Widget _rotateWidget() {
    final d = _data!;
    return Column(children: [
      const Text('将旋转的图片调整回正确角度', style: TextStyle(fontSize: 13, color: Colors.black87)),
      const SizedBox(height: 6),
      RotationTransition(
        turns: AlwaysStoppedAnimation(_sliderVal / 360),
        child: ClipRRect(borderRadius: BorderRadius.circular(6),
          child: Image.memory(d.imageBytes, width: 260, height: 260, fit: BoxFit.contain)),
      ),
      const SizedBox(height: 12),
      Slider(value: _sliderVal, min: 0, max: 360, divisions: 360, label: '${_sliderVal.round()}°',
          onChanged: (v) => setState(() => _sliderVal = v)),
      TextButton.icon(icon: const Icon(Icons.refresh, size: 16), label: const Text('换一张'), onPressed: _refresh),
    ]);
  }

  Widget _captchaArea() {
    if (_data == null) return const Padding(padding: EdgeInsets.all(20), child: CircularProgressIndicator());
    switch (_data!.type) {
      case CaptchaType.slider: return _sliderWidget();
      case CaptchaType.rotate: return _rotateWidget();
      default: return _clickWidget();
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    backgroundColor: Colors.white,
    body: Center(child: SingleChildScrollView(padding: const EdgeInsets.all(32), child: ConstrainedBox(
      constraints: const BoxConstraints(maxWidth: 420),
      child: Column(mainAxisSize: MainAxisSize.min, children: [
        const Icon(Icons.admin_panel_settings, size: 56, color: Color(0xFF1677FF)),
        const SizedBox(height: 8),
        const Text('开放管理后台', style: TextStyle(fontSize: 20, fontWeight: FontWeight.bold, color: Color(0xFF1677FF))),
        const SizedBox(height: 28),
        TextField(controller: _userCtrl, decoration: const InputDecoration(labelText: '用户名', prefixIcon: Icon(Icons.person_outline), border: OutlineInputBorder())),
        const SizedBox(height: 14),
        TextField(controller: _passCtrl, obscureText: true, decoration: const InputDecoration(labelText: '密码', prefixIcon: Icon(Icons.lock_outline), border: OutlineInputBorder())),
        const SizedBox(height: 20),
        _captchaArea(),
        const SizedBox(height: 14),
        if (_error != null)
          Padding(padding: const EdgeInsets.only(bottom: 10), child: Container(
            padding: const EdgeInsets.all(10), decoration: BoxDecoration(color: Colors.red[50], borderRadius: BorderRadius.circular(6)),
            child: Row(children: [const Icon(Icons.error_outline, color: Colors.red, size: 18), const SizedBox(width: 8), Expanded(child: Text(_error!, style: const TextStyle(color: Colors.red, fontSize: 13)))])),
          ),
        SizedBox(width: double.infinity, height: 44, child: FilledButton(
          onPressed: _loading ? null : _doLogin,
          child: _loading ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white)) : const Text('登 录', style: TextStyle(fontSize: 16)),
        )),
        const SizedBox(height: 16),
        Text('Copyright (c) 2026 erik — https://erik.xyz', style: TextStyle(fontSize: 11, color: Colors.grey[400])),
      ]),
    ))),
  );
}
