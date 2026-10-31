/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

import preferences from '@ohos.data.preferences';
import common from '@ohos.app.ability.common';

/**
 * JWT Token 管理
 *
 * 内存中通过 AppStorage 读写，同时用首选项持久化，保证应用重启后仍是登录态。
 */
export class TokenManager {
  private static ACCESS_TOKEN_KEY = 'access_token';
  private static REFRESH_TOKEN_KEY = 'refresh_token';
  private static STORE_NAME = 'auth';
  private static store: preferences.Preferences | null = null;
  private static initPromise: Promise<void> | null = null;

  /** 应用启动时调用：加载持久化的 token 到 AppStorage（重复调用复用同一次加载） */
  static init(context: common.Context): Promise<void> {
    if (!TokenManager.initPromise) {
      TokenManager.initPromise = TokenManager.load(context);
    }
    return TokenManager.initPromise;
  }

  private static async load(context: common.Context): Promise<void> {
    try {
      const store = await preferences.getPreferences(context, TokenManager.STORE_NAME);
      TokenManager.store = store;
      const accessToken = await store.get(TokenManager.ACCESS_TOKEN_KEY, '') as string;
      const refreshToken = await store.get(TokenManager.REFRESH_TOKEN_KEY, '') as string;
      AppStorage.setOrCreate(TokenManager.ACCESS_TOKEN_KEY, accessToken);
      AppStorage.setOrCreate(TokenManager.REFRESH_TOKEN_KEY, refreshToken);
    } catch (_) {
      // 读取失败时保持未登录，不影响应用启动
    }
  }

  static async saveTokens(accessToken: string, refreshToken: string): Promise<void> {
    AppStorage.setOrCreate(TokenManager.ACCESS_TOKEN_KEY, accessToken);
    AppStorage.setOrCreate(TokenManager.REFRESH_TOKEN_KEY, refreshToken);
    if (TokenManager.store) {
      TokenManager.store.putSync(TokenManager.ACCESS_TOKEN_KEY, accessToken);
      TokenManager.store.putSync(TokenManager.REFRESH_TOKEN_KEY, refreshToken);
      await TokenManager.store.flush();
    }
  }

  static getAccessToken(): string {
    return AppStorage.get(TokenManager.ACCESS_TOKEN_KEY) as string ?? '';
  }

  static getRefreshToken(): string {
    return AppStorage.get(TokenManager.REFRESH_TOKEN_KEY) as string ?? '';
  }

  static clearTokens(): void {
    AppStorage.setOrCreate(TokenManager.ACCESS_TOKEN_KEY, '');
    AppStorage.setOrCreate(TokenManager.REFRESH_TOKEN_KEY, '');
    if (TokenManager.store) {
      TokenManager.store.deleteSync(TokenManager.ACCESS_TOKEN_KEY);
      TokenManager.store.deleteSync(TokenManager.REFRESH_TOKEN_KEY);
      TokenManager.store.flush().catch(() => {});
    }
  }

  static isLoggedIn(): boolean {
    return (TokenManager.getAccessToken() ?? '').length > 0;
  }
}
