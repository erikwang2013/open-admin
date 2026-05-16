/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

import http from '@ohos.net.http';
import { ApiResponse } from '../model/DataModels';
import { TokenManager } from '../utils/TokenManager';

const BASE_URL = 'http://10.0.2.2:8787'; // 模拟器访问宿主机

/**
 * HTTP API 服务
 */
export class ApiService {
  private httpRequest: http.HttpRequest;

  constructor() {
    this.httpRequest = http.createHttp();
  }

  /**
   * 发送请求
   */
  private async request<T>(method: http.RequestMethod, path: string, body?: object): Promise<ApiResponse<T>> {
    const token = TokenManager.getAccessToken();

    const options: http.HttpRequestOptions = {
      method: method,
      header: {
        'Content-Type': 'application/json',
        'Authorization': token ? `Bearer ${token}` : '',
      },
      connectTimeout: 15000,
      readTimeout: 15000,
    };

    if (body) {
      options.extraData = JSON.stringify(body);
    }

    const url = `${BASE_URL}${path}`;
    const response = await this.httpRequest.request(url, options);

    if (response.responseCode === 401) {
      TokenManager.clearTokens();
      // 跳转到登录页
    }

    const result: ApiResponse<T> = JSON.parse(response.result as string);
    return result;
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
