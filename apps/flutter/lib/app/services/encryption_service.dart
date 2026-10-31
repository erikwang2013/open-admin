/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

import 'package:encrypt/encrypt.dart';
import 'package:pointycastle/asymmetric/api.dart';

import 'api_service.dart';

/// 密码传输加密 — RSA 非对称加密（公钥加密，私钥仅服务端持有）
///
/// 公钥可安全存放于前端代码中，不会导致密钥泄露。
/// 加密算法: RSA-2048 / PKCS1v1.5 / Base64
///
/// 服务端密钥轮换后，登录前通过 GET /api/v1/auth/public-key 动态获取当前公钥；
/// 获取失败时保留下方内置公钥作为回退。
class EncryptionService {
  static const _fallbackPublicKeyPem = '''-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA0+QqkXVytnm32HjL5HVV
G1p6E5oRnQ/WKAaKLisjH0D+tffvVTQ0zIdJS92ohbfI+90SpmsKfgLdEah1gFMT
AyynXbwj9JHIhGjmjYSTC3EPnzmYD9I+K4gjf8E/BE0BdJfQaEb/1d8XduqeUxu2
8LL4q7tmcdH95mhyklUDPy87BADMZpGqGne054wQQDguvhflBYK+6EGJnAkRvj4S
Y9J5x1UIn5f00xR9ZxMVPRHBud12jr+x/udQB5MsFOXoFz007zubefjZauIVWPuV
5IR9KmMYzfqiRbTSOHW+jDuxtDSPgEvCvZlEaB/EhYp1FVbazjuCys4VH6R/dyCy
PwIDAQAB
-----END PUBLIC KEY-----''';

  static RSAPublicKey? _cachedKey;
  static Future<void>? _pendingFetch;

  /// 获取服务端当前公钥；同一时刻只发一次请求，成功后缓存到本次会话。
  /// 失败时静默回退到内置公钥，不阻断登录。
  static Future<void> ensurePublicKey() {
    if (_cachedKey != null) return Future.value();
    return _pendingFetch ??= _fetchPublicKey().whenComplete(() => _pendingFetch = null);
  }

  static Future<void> _fetchPublicKey() async {
    try {
      final resp = await ApiService().get('/api/v1/auth/public-key');
      final data = resp['data'];
      final pem = data is Map ? data['public_key'] : null;
      if (pem is String && pem.contains('BEGIN PUBLIC KEY')) {
        _cachedKey = RSAKeyParser().parse(pem) as RSAPublicKey;
      }
    } catch (_) {
      // 网络异常或服务端未配置 RSA 密钥：继续使用内置公钥
    }
  }

  static RSAPublicKey get _key {
    return _cachedKey ??= RSAKeyParser().parse(_fallbackPublicKeyPem) as RSAPublicKey;
  }

  /// RSA-2048 PKCS1v1.5 加密 → Base64 编码
  static String encrypt(String plaintext) {
    if (plaintext.isEmpty) return '';
    final encrypted = Encrypter(RSA(publicKey: _key)).encrypt(plaintext);
    return encrypted.base64;
  }
}
