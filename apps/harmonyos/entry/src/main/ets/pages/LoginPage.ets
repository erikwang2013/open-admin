/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

import router from '@ohos.router';
import promptAction from '@ohos.promptAction';
import { AuthService } from '../service/DataService';
import { TokenManager } from '../utils/TokenManager';

@Entry
@Component
struct LoginPage {
  @State username: string = '';
  @State password: string = '';
  @State isLoading: boolean = false;
  @State showPassword: boolean = false;

  async login(): Promise<void> {
    if (!this.username.trim()) {
      promptAction.showToast({ message: '请输入用户名' });
      return;
    }
    if (!this.password) {
      promptAction.showToast({ message: '请输入密码' });
      return;
    }

    this.isLoading = true;
    try {
      const result = await AuthService.login(this.username, this.password);
      await TokenManager.saveTokens(result.access_token, result.refresh_token);
      router.replaceUrl({ url: 'pages/DashboardPage' });
    } catch (e) {
      promptAction.showToast({ message: '登录失败，请检查账号密码' });
    } finally {
      this.isLoading = false;
    }
  }

  build() {
    Column() {
      // 顶部品牌区
      Column() {
        Image($rawfile('logo.png'))
          .width(72)
          .height(72)
          .margin({ bottom: 12 })

        Text('开放管理后台')
          .fontSize(24)
          .fontWeight(FontWeight.Bold)
          .fontColor('#1677FF')

        Text('HarmonyOS 客户端')
          .fontSize(13)
          .fontColor('#999999')
          .margin({ top: 4 })
      }
      .width('100%')
      .height('35%')
      .justifyContent(FlexAlign.Center)

      // 表单区
      Column() {
        // 用户名
        TextInput({ placeholder: '请输入用户名', text: this.username })
          .height(48)
          .fontSize(16)
          .backgroundColor('#F5F5F5')
          .borderRadius(8)
          .padding({ left: 16, right: 16 })
          .onChange((value: string) => {
            this.username = value;
          })

        // 密码
        Row() {
          TextInput({ placeholder: '请输入密码', text: this.password })
            .height(48)
            .fontSize(16)
            .type(this.showPassword ? InputType.Normal : InputType.Password)
            .backgroundColor('#F5F5F5')
            .borderRadius(8)
            .padding({ left: 16, right: 16 })
            .layoutWeight(1)
            .onChange((value: string) => {
              this.password = value;
            })

          Button(this.showPassword ? '隐藏' : '显示')
            .fontSize(13)
            .fontColor('#1677FF')
            .backgroundColor(Color.Transparent)
            .width(60)
            .onClick(() => {
              this.showPassword = !this.showPassword;
            })
        }
        .width('100%')
        .margin({ top: 16 })

        // 登录按钮
        Button('登 录')
          .width('100%')
          .height(48)
          .fontSize(16)
          .fontColor('#FFFFFF')
          .backgroundColor(this.isLoading ? '#99BBFF' : '#1677FF')
          .borderRadius(8)
          .margin({ top: 24 })
          .enabled(!this.isLoading)
          .onClick(() => {
            this.login();
          })
      }
      .width('100%')
      .padding({ left: 32, right: 32 })

      // 底部版权
      Blank()
      Text('Copyright (c) 2026 erik — https://erik.xyz')
        .fontSize(11)
        .fontColor('#CCCCCC')
        .textAlign(TextAlign.Center)
        .width('100%')
        .padding({ bottom: 20 })
    }
    .width('100%')
    .height('100%')
    .backgroundColor('#FFFFFF')
  }
}
