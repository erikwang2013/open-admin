/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

import 'package:dio/dio.dart';
import 'auth_service.dart';

class ApiService {
  static final ApiService _instance = ApiService._();
  factory ApiService() => _instance;

  late final Dio dio;
  static const String baseUrl = 'http://localhost:8787';

  ApiService._() {
    dio = Dio(BaseOptions(
      baseUrl: baseUrl,
      connectTimeout: const Duration(seconds: 10),
      receiveTimeout: const Duration(seconds: 10),
      headers: {
        'Content-Type': 'application/json',
        'API-Version': 'v1',
        'X-Client-Platform': 'web',
      },
    ));

    dio.interceptors.add(InterceptorsWrapper(
      onRequest: (options, handler) async {
        final token = await AuthService.getToken();
        if (token != null) {
          options.headers['Authorization'] = 'Bearer $token';
        }
        handler.next(options);
      },
      onError: (error, handler) {
        if (error.response?.statusCode == 401) {
          AuthService.clearToken();
        }
        handler.next(error);
      },
    ));
  }

  Future<Map<String, dynamic>> get(String path, {Map<String, dynamic>? params}) async {
    final resp = await dio.get(path, queryParameters: params);
    return _handleResponse(resp);
  }

  Future<Map<String, dynamic>> post(String path, {dynamic data}) async {
    final resp = await dio.post(path, data: data);
    return _handleResponse(resp);
  }

  Future<Map<String, dynamic>> put(String path, {dynamic data}) async {
    final resp = await dio.put(path, data: data);
    return _handleResponse(resp);
  }

  Future<Map<String, dynamic>> delete(String path, {dynamic data}) async {
    final resp = await dio.delete(path, data: data);
    return _handleResponse(resp);
  }

  Map<String, dynamic> _handleResponse(Response resp) {
    final body = resp.data as Map<String, dynamic>;
    if (body['code'] != 0) {
      throw ApiException(body['code'] as int, body['message'] as String? ?? '请求失败');
    }
    return body;
  }
}

class ApiException implements Exception {
  final int code;
  final String message;
  ApiException(this.code, this.message);

  @override
  String toString() => 'ApiException($code): $message';
}
