/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

/**
 * 统一响应格式
 */
export interface ApiResponse<T> {
  code: number;
  message: string;
  data: T;
}

/**
 * 分页参数
 */
export interface PageParams {
  page: number;
  limit: number;
}

/**
 * 分页响应
 */
export interface PageResult<T> {
  list: T[];
  total: number;
  page: number;
  limit: number;
}

/**
 * 仪表盘统计数据
 */
export interface StatCard {
  label: string;
  value: string;
  icon: string;
  color: string;
  trend?: number;
}

/**
 * 趋势数据
 */
export interface TrendData {
  dates: string[];
  series: TrendSeries[];
}

export interface TrendSeries {
  name: string;
  data: number[];
  color: string;
}

/**
 * 仪表盘完整数据
 */
export interface DashboardData {
  stats: StatCard[];
  trends: TrendData;
  distribution: Record<string, DistributionItem[]>;
  recent_logs: OperationLogItem[];
}

export interface DistributionItem {
  name: string;
  value: number;
}

/**
 * 用户模型
 */
export interface User {
  id: string; // hashid 字符串
  username: string;
  real_name: string;
  avatar: string;
  phone: string;
  email: string;
  status: number;
  last_login_at: string;
  last_login_ip: string;
  created_at: string;
}

export interface UserListResult extends PageResult<User> {}

/**
 * 创建用户请求体（role_ids 为角色 hashid 数组）
 */
export interface UserCreatePayload {
  username: string;
  real_name: string;
  password: string;
  phone?: string;
  email?: string;
  status?: number;
  role_ids: string[];
}

/**
 * 角色模型
 */
export interface Role {
  id: string;
  name: string;
  slug: string;
  description: string;
  status: number;
}

/**
 * 角色选项（GET /admin/role/options，仅可分配角色）
 */
export interface RoleOption {
  id: string;
  name: string;
  slug: string;
}

/**
 * 操作日志
 */
export interface OperationLogItem {
  id: string;
  user_name: string;
  action: string;
  method: string;
  path: string;
  ip: string;
  created_at: string;
}

/**
 * 登录请求/响应
 */
export interface LoginRequest {
  username: string;
  password: string;
}

/**
 * refresh_token 请求体（刷新令牌 / 登出）
 */
export interface RefreshTokenRequest {
  refresh_token: string;
}

/**
 * 密码二次确认请求体（删除用户等敏感操作）
 */
export interface PasswordConfirmRequest {
  password: string;
}

export interface LoginResponse {
  access_token: string;
  refresh_token: string;
  expires_in: number;
}
