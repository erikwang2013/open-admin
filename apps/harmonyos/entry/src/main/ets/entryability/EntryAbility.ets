/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

import UIAbility from '@ohos.app.ability.UIAbility';
import window from '@ohos.window';
import hilog from '@ohos.hilog';
import { TokenManager } from '../utils/TokenManager';

const TAG = 'EntryAbility';

export default class EntryAbility extends UIAbility {
  onCreate(want, launchParam): void {
    hilog.info(0x0000, TAG, 'Ability onCreate');
  }

  onWindowStageCreate(windowStage: window.WindowStage): void {
    hilog.info(0x0000, TAG, 'Ability onWindowStageCreate');

    // 等待首选项加载完成，避免重启后误判为未登录
    TokenManager.init(this.context).then(() => {
      // 已登录则跳过登录页直接进入仪表盘
      const accessToken = AppStorage.get('access_token') as string ?? '';
      const startPage = accessToken.length > 0 ? 'pages/DashboardPage' : 'pages/LoginPage';

      windowStage.loadContent(startPage, (err) => {
        if (err.code) {
          hilog.error(0x0000, TAG, `Failed to load content: ${err.code}`);
          return;
        }
        hilog.info(0x0000, TAG, 'Succeeded in loading content');
      });
    });
  }

  onWindowStageDestroy(): void {
    hilog.info(0x0000, TAG, 'Ability onWindowStageDestroy');
  }

  onForeground(): void {
    hilog.info(0x0000, TAG, 'Ability onForeground');
  }

  onBackground(): void {
    hilog.info(0x0000, TAG, 'Ability onBackground');
  }

  onDestroy(): void {
    hilog.info(0x0000, TAG, 'Ability onDestroy');
  }
}
