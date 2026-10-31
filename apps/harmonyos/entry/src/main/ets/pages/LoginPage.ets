/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

import router from '@ohos.router';
import promptAction from '@ohos.promptAction';
import { TokenManager } from '../utils/TokenManager';
import { apiService } from '../service/ApiService';
import { LoginResponse } from '../model/DataModels';

interface CaptchaTarget {
  order: number;
  text: string;
  x: number;
  y: number;
}

interface CaptchaData {
  key: string;
  type: string;
  image: string; // base64
  targets: CaptchaTarget[];
}

interface ClickPoint {
  x: number;
  y: number;
}

interface CaptchaRequest {
  difficulty: string;
  type: string;
}

interface CaptchaVerifyRequest {
  key: string;
  type: string;
  clicks: number[][];
}

interface CaptchaVerifyResult {
  valid: boolean;
}

interface LoginRequest {
  username: string;
  password: string;
  captcha_key: string;
}

@Entry
@Component
struct LoginPage {
  @State username: string = '';
  @State password: string = '';
  @State isLoading: boolean = false;
  @State showPassword: boolean = false;

  // 验证码状态
  @State captchaData: CaptchaData | null = null;
  @State captchaLoading: boolean = true;
  @State clicks: ClickPoint[] = [];
  @State clickMarkers: {x: number, y: number, order: number}[] = [];
  @State captchaError: string = '';
  @State loginError: string = '';
  // 验证码图片显示区域的实际尺寸（用于点击坐标换算）
  @State imageBoxW: number = 0;
  @State imageBoxH: number = 0;

  aboutToAppear(): void {
    this.loadCaptcha();
  }

  async loadCaptcha(): Promise<void> {
    this.captchaLoading = true;
    this.clicks = [];
    this.clickMarkers = [];
    this.captchaError = '';

    try {
      const body: CaptchaRequest = { difficulty: 'medium', type: 'click' };
      const result = await apiService.post<CaptchaData>('/api/v1/captcha/generate', body);
      if (result.code === 0) {
        this.captchaData = result.data;
        this.captchaData!.targets.sort((a, b) => a.order - b.order);
      } else {
        this.captchaError = '验证码加载失败';
      }
    } catch (e) {
      this.captchaError = '网络错误，无法加载验证码';
    } finally {
      this.captchaLoading = false;
    }
  }

  onCaptchaClick(event: ClickEvent): void {
    if (!this.captchaData || this.clicks.length >= this.captchaData.targets.length) return;

    // 图片以 ImageFit.Contain 居中显示在组件内，先定位实际绘制区域再换算回 300x200 原图坐标
    const boxW = this.imageBoxW;
    const boxH = this.imageBoxH;
    if (boxW <= 0 || boxH <= 0) return;
    const scale = Math.min(boxW / 300, boxH / 200);
    const dispW = 300 * scale;
    const dispH = 200 * scale;
    const offsetX = (boxW - dispW) / 2;
    const offsetY = (boxH - dispH) / 2;
    // 点在留白区域不记录
    if (event.x < offsetX || event.x > offsetX + dispW
      || event.y < offsetY || event.y > offsetY + dispH) return;

    const imgX = Math.round((event.x - offsetX) / scale);
    const imgY = Math.round((event.y - offsetY) / scale);

    const order = this.clicks.length;
    this.clicks.push({ x: imgX, y: imgY });
    this.clickMarkers.push({ x: event.x, y: event.y, order: order + 1 });
    this.captchaError = '';
    this.loginError = '';
  }

  async login(): Promise<void> {
    const trimmedUsername = this.username.trim();
    if (!trimmedUsername) {
      this.loginError = '请输入用户名';
      return;
    }
    if (!this.password) {
      this.loginError = '请输入密码';
      return;
    }
    if (!this.captchaData || this.clicks.length < this.captchaData.targets.length) {
      const next = this.captchaData?.targets[this.clicks.length];
      this.loginError = next ? `请点击图中文字『${next.text}』` : '请完成验证码验证';
      return;
    }

    this.isLoading = true;
    this.loginError = '';

    try {
      const captchaKey = this.captchaData!.key;
      const verifyBody: CaptchaVerifyRequest = {
        key: captchaKey,
        type: 'click',
        clicks: this.clicks.map((p: ClickPoint): number[] => [p.x, p.y])
      };
      const verifyResult = await apiService.post<CaptchaVerifyResult>('/api/v1/captcha/verify', verifyBody);
      if (verifyResult.code !== 0) {
        this.loginError = '验证码校验失败';
        this.loadCaptcha();
        return;
      }

      const body: LoginRequest = {
        username: trimmedUsername,
        password: this.password,
        captcha_key: captchaKey
      };
      const result = await apiService.post<LoginResponse>('/api/v1/auth/login', body);
      if (result.code === 0) {
        await TokenManager.saveTokens(result.data.access_token, result.data.refresh_token);
        AppStorage.setOrCreate('adminUsername', trimmedUsername);
        router.replaceUrl({ url: 'pages/DashboardPage' });
      } else {
        this.loginError = result.message ?? '登录失败';
        this.loadCaptcha();
      }
    } catch (e) {
      // 凭证错误以 HTTP 401 返回时统一由 ApiService 抛出 UNAUTHORIZED
      this.loginError = (e as Error).message === 'UNAUTHORIZED' ? '用户名或密码错误' : '网络错误，请检查连接';
      this.loadCaptcha();
    } finally {
      this.isLoading = false;
    }
  }

  build() {
    Column() {
      Scroll() {
        Column() {
          // 顶部品牌区
          Column() {
            Image($rawfile('logo.png'))
              .width(64)
              .height(64)
              .margin({ bottom: 8 })

            Text('开放管理后台')
              .fontSize(22)
              .fontWeight(FontWeight.Bold)
              .fontColor('#1677FF')

            Text('HarmonyOS 客户端')
              .fontSize(12)
              .fontColor('#999999')
              .margin({ top: 2 })
          }
          .width('100%')
          .padding({ top: 40, bottom: 24 })
          .justifyContent(FlexAlign.Center)

          // 表单区
          Column() {
            // 用户名
            TextInput({ placeholder: '请输入用户名', text: this.username })
              .height(48)
              .fontSize(15)
              .backgroundColor('#F5F5F5')
              .borderRadius(8)
              .padding({ left: 16, right: 16 })
              .onChange((v: string) => { this.username = v; })

            // 密码
            Row() {
              TextInput({ placeholder: '请输入密码', text: this.password })
                .height(48)
                .fontSize(15)
                .type(this.showPassword ? InputType.Normal : InputType.Password)
                .backgroundColor('#F5F5F5')
                .borderRadius(8)
                .padding({ left: 16, right: 16 })
                .layoutWeight(1)
                .onChange((v: string) => { this.password = v; })

              Button(this.showPassword ? '隐藏' : '显示')
                .fontSize(12)
                .fontColor('#1677FF')
                .backgroundColor(Color.Transparent)
                .width(56)
                .onClick(() => { this.showPassword = !this.showPassword; })
            }
            .width('100%')
            .margin({ top: 14 })

            // 点击验证码
            if (this.captchaLoading) {
              LoadingProgress()
                .width(36)
                .height(36)
                .color('#1677FF')
                .margin({ top: 16 })
            } else if (this.captchaError && !this.captchaData) {
              Row() {
                Text(this.captchaError)
                  .fontSize(13)
                  .fontColor('#FF4D4F')
                  .layoutWeight(1)
                Button('重试')
                  .fontSize(13)
                  .fontColor('#1677FF')
                  .backgroundColor(Color.Transparent)
                  .onClick(() => { this.loadCaptcha(); })
              }
              .width('100%')
              .margin({ top: 14 })
            } else if (this.captchaData) {
              Column() {
                // 提示文字
                Row() {
                  Text('请按顺序点击图中文字: ')
                    .fontSize(12)
                    .fontColor('#666666')
                  ForEach(this.captchaData!.targets, (t: CaptchaTarget, i: number) => {
                    Text(i > 0 ? ' → ' : '')
                      .fontSize(12)
                      .fontColor('#999999')
                    Text(`"${t.text}"`)
                      .fontSize(13)
                      .fontWeight(this.clicks.length === i ? FontWeight.Bold : FontWeight.Normal)
                      .fontColor(this.clicks.length > i ? '#52C41A' : (this.clicks.length === i ? '#1677FF' : '#333333'))
                  })
                }
                .width('100%')
                .margin({ top: 14, bottom: 6 })

                // 验证码图片
                Stack({ alignContent: Alignment.TopStart }) {
                  Image((this.captchaData!.image.startsWith('data:') ?? false)
                    ? this.captchaData!.image
                    : `data:image/png;base64,${this.captchaData!.image}`)
                    .width('100%')
                    .height(200)
                    .objectFit(ImageFit.Contain)
                    .borderRadius(8)
                    .border({ width: 1, color: '#EEEEEE' })
                    .onClick((event: ClickEvent) => { this.onCaptchaClick(event); })

                  // 点击标记
                  ForEach(this.clickMarkers, (m: {x: number, y: number, order: number}) => {
                    Circle({ width: 26, height: 26 })
                      .fill('#1677FFCC')
                      .position({ x: m.x - 13, y: m.y - 13 })
                    Text(`${m.order}`)
                      .fontSize(13)
                      .fontColor('#FFFFFF')
                      .fontWeight(FontWeight.Bold)
                      .position({ x: m.x - 3, y: m.y - 8 })
                  })
                }
                .width('100%')
                .height(200)
                .onAreaChange((oldValue: Area, newValue: Area) => {
                  this.imageBoxW = Number(newValue.width);
                  this.imageBoxH = Number(newValue.height);
                })
                .margin({ top: 4 })

                // 操作栏
                Row() {
                  Text(`已点击 ${this.clicks.length}/${this.captchaData!.targets.length}`)
                    .fontSize(12)
                    .fontColor('#999999')

                  Blank()

                  Button('换一张')
                    .fontSize(12)
                    .fontColor('#1677FF')
                    .backgroundColor(Color.Transparent)
                    .onClick(() => { this.loadCaptcha(); })
                }
                .width('100%')
                .margin({ top: 4 })
              }
              .width('100%')
            }

            // 错误提示
            if (this.loginError) {
              Text(this.loginError)
                .fontSize(13)
                .fontColor('#FF4D4F')
                .width('100%')
                .textAlign(TextAlign.Center)
                .padding({ top: 10, bottom: 2 })
            }

            // 登录按钮
            Button('登 录')
              .width('100%')
              .height(48)
              .fontSize(16)
              .fontColor('#FFFFFF')
              .backgroundColor(this.isLoading ? '#99BBFF' : '#1677FF')
              .borderRadius(8)
              .margin({ top: 20 })
              .enabled(!this.isLoading)
              .onClick(() => { this.login(); })
          }
          .width('100%')
          .padding({ left: 28, right: 28 })

          // 底部操作
          Row() {
            Text('还没有账号？')
              .fontSize(13)
              .fontColor('#999999')
            Button('立即注册')
              .fontSize(13)
              .fontColor('#1677FF')
              .backgroundColor(Color.Transparent)
              .onClick(() => {
                promptAction.showToast({ message: '注册功能即将上线' });
              })
          }
          .justifyContent(FlexAlign.Center)
          .margin({ top: 16 })

          // 版权
          Text('Copyright (c) 2026 erik — https://erik.xyz')
            .fontSize(10)
            .fontColor('#CCCCCC')
            .textAlign(TextAlign.Center)
            .width('100%')
            .padding({ top: 20, bottom: 24 })
        }
        .width('100%')
      }
      .width('100%')
      .layoutWeight(1)
      .scrollable(ScrollDirection.Vertical)
    }
    .width('100%')
    .height('100%')
    .backgroundColor('#FFFFFF')
  }
}
