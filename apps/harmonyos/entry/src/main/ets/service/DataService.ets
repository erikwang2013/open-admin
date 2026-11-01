/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

import { apiService } from './ApiService';
import {
  LoginRequest,
  LoginResponse,
  DashboardData,
  User,
  UserListResult,
  PageParams,
  RoleOption,
  RefreshTokenRequest,
  PasswordConfirmRequest,
  UserCreatePayload
} from '../model/DataModels';

/**
 * 认证服务
 */
export class AuthService {
  static async login(username: string, password: string): Promise<LoginResponse> {
    const body: LoginRequest = { username, password };
    const response = await apiService.post<LoginResponse>('/api/v1/auth/login', body);
    if (response.code !== 0) {
      throw new Error(response.message);
    }
    return response.data;
  }

  static async refreshToken(refreshToken: string): Promise<LoginResponse> {
    const body: RefreshTokenRequest = { refresh_token: refreshToken };
    const response = await apiService.post<LoginResponse>('/api/v1/auth/refresh', body);
    if (response.code !== 0) {
      throw new Error(response.message);
    }
    return response.data;
  }

  /** 服务端登出：吊销 access/refresh token（access 过期时由 ApiService 自动刷新后重试） */
  static async logout(refreshToken: string): Promise<void> {
    const body: RefreshTokenRequest = { refresh_token: refreshToken };
    const response = await apiService.post<void>('/admin/profile/logout', body);
    if (response.code !== 0) {
      throw new Error(response.message);
    }
  }
}

/**
 * 仪表盘服务
 */
export class DashboardService {
  static async getDashboard(): Promise<DashboardData> {
    const response = await apiService.get<DashboardData>('/admin/dashboard');
    if (response.code !== 0) {
      throw new Error(response.message);
    }
    return response.data;
  }
}

/**
 * 角色服务
 */
export class RoleService {
  /** 当前管理员可分配的角色（GET /admin/role/options） */
  static async list(): Promise<RoleOption[]> {
    const response = await apiService.get<RoleOption[]>('/admin/role/options');
    if (response.code !== 0) {
      throw new Error(response.message);
    }
    return response.data;
  }
}

/**
 * 用户管理服务
 */
export class UserService {
  static async getList(params: PageParams & { keyword?: string; status?: number }): Promise<UserListResult> {
    const query = Object.entries(params)
      .filter(([_, v]) => v !== undefined && v !== '')
      .map(([k, v]) => `${k}=${encodeURIComponent(String(v))}`)
      .join('&');
    const response = await apiService.get<UserListResult>(`/admin/user?${query}`);
    if (response.code !== 0) {
      throw new Error(response.message);
    }
    return response.data;
  }

  static async getDetail(id: string): Promise<User> {
    const response = await apiService.get<User>(`/admin/user/${id}`);
    if (response.code !== 0) {
      throw new Error(response.message);
    }
    return response.data;
  }

  static async create(data: UserCreatePayload): Promise<void> {
    const response = await apiService.post<User>('/admin/user', data);
    if (response.code !== 0) {
      throw new Error(response.message);
    }
  }

  static async update(id: string, data: Partial<User>): Promise<void> {
    const response = await apiService.put<User>(`/admin/user/${id}`, data);
    if (response.code !== 0) {
      throw new Error(response.message);
    }
  }

  /** 删除用户需当前管理员密码二次确认，密码放在 DELETE 请求体中 */
  static async delete(id: string, password: string): Promise<void> {
    const body: PasswordConfirmRequest = { password };
    const response = await apiService.delete<void>(`/admin/user/${id}`, body);
    if (response.code !== 0) {
      throw new Error(response.message);
    }
  }
}
