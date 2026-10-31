/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

import http from '@ohos.net.http';
import { ApiResponse, LoginResponse } from '../model/DataModels';
import { TokenManager } from '../utils/TokenManager';
import AppConfig from '../config/AppConfig';

/**
 * HTTP API 服务 — 支持 JWT 无感刷新
 */
export class ApiService {
  private httpRequest: http.HttpRequest;
  /** 并发 401 时共享同一次刷新，避免同时轮换 refresh token 互相踢下线 */
  private refreshPromise: Promise<boolean> | null = null;

  constructor() {
    this.httpRequest = http.createHttp();
  }

  /**
   * 发送请求（自动处理 401 和 token 刷新）
   */
  private async request<T>(method: http.RequestMethod, path: string, body?: object, retryCount: number = 0): Promise<ApiResponse<T>> {
    const token = TokenManager.getAccessToken();

    const options: http.HttpRequestOptions = {
      method: method,
      header: {
        'Content-Type': 'application/json',
        'X-Client-Platform': 'harmonyos',
        'Authorization': (token && token.length > 0) ? `Bearer ${token}` : '',
      },
      connectTimeout: 15000,
      readTimeout: 15000,
    };

    if (body) {
      options.extraData = JSON.stringify(body);
    }

    const response = await this.httpRequest.request(`${AppConfig.baseUrl}${path}`, options);

    let result: ApiResponse<T> | null = null;
    try {
      result = JSON.parse(response.result as string) as ApiResponse<T>;
    } catch (_) {
      // 非 JSON 响应（如网关错误页）按业务失败处理
    }

    // 后端历史上以 HTTP 200 + body.code=401 表示未授权，两种形态都要触发刷新；
    // 认证接口的 401 是凭证错误，交给调用方展示服务端消息，不刷新也不清会话
    const unauthorized = response.responseCode === 401 || (result !== null && result.code === 401);
    const isAuthPath = path.indexOf('/api/v1/auth/') === 0;
    if (unauthorized && !isAuthPath) {
      if (retryCount === 0 && await this.tryRefreshToken()) {
        // 刷新成功，用新 token 重试原始请求
        return this.request<T>(method, path, body, retryCount + 1);
      }
      TokenManager.clearTokens();
      throw new Error('UNAUTHORIZED');
    }

    if (result === null) {
      throw new Error(`HTTP_${response.responseCode}`);
    }
    return result;
  }

  /**
   * 并发 401 只发起一次刷新，其余请求等待同一结果
   * @returns 刷新成功返回 true
   */
  private async tryRefreshToken(): Promise<boolean> {
    if (this.refreshPromise === null) {
      this.refreshPromise = this.doRefreshToken();
    }
    const pending = this.refreshPromise;
    try {
      return await pending;
    } finally {
      // 只清理自己等待的那次刷新，避免覆盖稍后新建的刷新
      if (this.refreshPromise === pending) {
        this.refreshPromise = null;
      }
    }
  }

  /**
   * 尝试用 refresh_token 刷新 access_token
   * @returns 刷新成功返回 true
   */
  private async doRefreshToken(): Promise<boolean> {
    const refreshToken = TokenManager.getRefreshToken();
    if (!refreshToken) {
      return false;
    }

    try {
      const refreshResponse = await this.httpRequest.request(
        `${AppConfig.baseUrl}/api/v1/auth/refresh`,
        {
          method: http.RequestMethod.POST,
          header: {
            'Content-Type': 'application/json',
          },
          extraData: JSON.stringify({ refresh_token: refreshToken }),
          connectTimeout: 10000,
          readTimeout: 10000,
        }
      );

      if (refreshResponse.responseCode === 200) {
        const data: ApiResponse<LoginResponse> =
          JSON.parse(refreshResponse.result as string) as ApiResponse<LoginResponse>;
        if (data.code === 0 && data.data) {
          await TokenManager.saveTokens(
            data.data.access_token,
            data.data.refresh_token
          );
          return true;
        }
      }
    } catch (_) {
      // 刷新请求本身失败（网络错误等）
    }

    return false;
  }

  async get<T>(path: string): Promise<ApiResponse<T>> {
    return this.request<T>(http.RequestMethod.GET, path);
  }

  async post<T>(path: string, body?: object): Promise<ApiResponse<T>> {
    return this.request<T>(http.RequestMethod.POST, path, body);
  }

  async put<T>(path: string, body?: object): Promise<ApiResponse<T>> {
    return this.request<T>(http.RequestMethod.PUT, path, body);
  }

  async delete<T>(path: string): Promise<ApiResponse<T>> {
    return this.request<T>(http.RequestMethod.DELETE, path);
  }
}

export const apiService = new ApiService();
