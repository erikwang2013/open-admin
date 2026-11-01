/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

import router from '@ohos.router';
import promptAction from '@ohos.promptAction';
import { UserService, RoleService } from '../service/DataService';
import { User, RoleOption } from '../model/DataModels';
import { LoadingView, ErrorView } from '../common/Components';

@CustomDialog
struct DeleteUserDialog {
  controller?: CustomDialogController;
  confirm: (password: string) => void = () => {};
  @State password: string = '';

  build() {
    Column() {
      Text('确认删除')
        .fontSize(18)
        .fontWeight(FontWeight.Bold)

      Text('确定要删除该用户吗？删除后不可恢复。')
        .fontSize(14)
        .fontColor('#666666')
        .margin({ top: 8 })

      TextInput({ placeholder: '请输入当前管理员密码', text: this.password })
        .type(InputType.Password)
        .margin({ top: 12 })
        .onChange((v: string) => {
          this.password = v;
        })

      Row() {
        Button('取消')
          .layoutWeight(1)
          .height(40)
          .fontColor('#666666')
          .backgroundColor('#F5F5F5')
          .onClick(() => {
            this.controller?.close();
          })

        Button('删除')
          .layoutWeight(1)
          .height(40)
          .fontColor('#FFFFFF')
          .backgroundColor('#FF4D4F')
          .margin({ left: 12 })
          .enabled(this.password.length > 0)
          .onClick(() => {
            const password = this.password;
            this.controller?.close();
            this.confirm(password);
          })
      }
      .margin({ top: 20 })
    }
    .padding(20)
    .backgroundColor('#FFFFFF')
    .borderRadius(12)
  }
}

@Entry
@Component
struct UserDetailPage {
  @State user: User | null = null;
  @State isLoading: boolean = true;
  @State hasError: boolean = false;
  @State isSubmitting: boolean = false;
  @State mode: string = 'view';
  @State userId: string = '';

  // 表单状态
  @State formUsername: string = '';
  @State formRealName: string = '';
  @State formPhone: string = '';
  @State formEmail: string = '';
  @State formPassword: string = '';
  @State formStatus: number = 1;

  // 新建时可选角色（GET /admin/role/options）
  @State roles: RoleOption[] = [];
  @State selectedRoleIds: string[] = [];
  @State rolesLoading: boolean = false;
  @State roleError: boolean = false;

  deleteDialog: CustomDialogController = new CustomDialogController({
    builder: DeleteUserDialog({
      confirm: (password: string) => {
        this.performDelete(password);
      }
    }),
    autoCancel: true,
    alignment: DialogAlignment.Center
  });

  aboutToAppear(): void {
    const params = (router.getParams() ?? {}) as Record<string, Object>;
    this.mode = (params?.['mode'] as string) ?? 'view';
    this.userId = (params?.['userId'] as string) ?? '';

    if (this.mode !== 'create') {
      this.loadDetail();
    } else {
      this.isLoading = false;
      this.loadRoles();
    }
  }

  async loadRoles(): Promise<void> {
    this.rolesLoading = true;
    try {
      this.roles = await RoleService.list();
    } catch (e) {
      if (e instanceof Error && e.message === 'UNAUTHORIZED') {
        router.replaceUrl({ url: 'pages/LoginPage' });
        return;
      }
      promptAction.showToast({ message: '角色列表加载失败' });
    } finally {
      this.rolesLoading = false;
    }
  }

  toggleRole(id: string, checked: boolean): void {
    if (checked) {
      if (this.selectedRoleIds.indexOf(id) < 0) {
        this.selectedRoleIds = this.selectedRoleIds.concat([id]);
      }
    } else {
      this.selectedRoleIds = this.selectedRoleIds.filter((v: string) => v !== id);
    }
    if (this.selectedRoleIds.length > 0) {
      this.roleError = false;
    }
  }

  async loadDetail(): Promise<void> {
    this.isLoading = true;
    try {
      this.user = await UserService.getDetail(this.userId);
      this.formUsername = this.user.username;
      this.formRealName = this.user.real_name;
      this.formPhone = this.user.phone ?? '';
      this.formEmail = this.user.email ?? '';
      this.formStatus = this.user.status;
    } catch (e) {
      if (e instanceof Error && e.message === 'UNAUTHORIZED') {
        router.replaceUrl({ url: 'pages/LoginPage' });
        return;
      }
      this.hasError = true;
    } finally {
      this.isLoading = false;
    }
  }

  async save(): Promise<void> {
    if (!this.formUsername.trim()) {
      promptAction.showToast({ message: '请输入用户名' });
      return;
    }
    if (this.mode === 'create' && !this.formPassword) {
      promptAction.showToast({ message: '请输入密码' });
      return;
    }
    if (this.mode === 'create' && this.selectedRoleIds.length === 0) {
      this.roleError = true;
      promptAction.showToast({ message: '请至少选择一个角色' });
      return;
    }

    this.isSubmitting = true;
    try {
      if (this.mode === 'create') {
        await UserService.create({
          username: this.formUsername,
          real_name: this.formRealName,
          password: this.formPassword,
          phone: this.formPhone,
          email: this.formEmail,
          status: this.formStatus,
          role_ids: this.selectedRoleIds
        });
        promptAction.showToast({ message: '创建成功' });
      } else {
        await UserService.update(this.userId, {
          real_name: this.formRealName,
          phone: this.formPhone,
          email: this.formEmail,
          status: this.formStatus
        });
        promptAction.showToast({ message: '更新成功' });
      }
      router.back();
    } catch (e) {
      if (e instanceof Error && e.message === 'UNAUTHORIZED') {
        router.replaceUrl({ url: 'pages/LoginPage' });
        return;
      }
      promptAction.showToast({ message: '保存失败' });
    } finally {
      this.isSubmitting = false;
    }
  }

  deleteUser(): void {
    this.deleteDialog.open();
  }

  async performDelete(password: string): Promise<void> {
    if (!password) {
      promptAction.showToast({ message: '请输入密码' });
      return;
    }
    try {
      await UserService.delete(this.userId, password);
      promptAction.showToast({ message: '删除成功' });
      router.back();
    } catch (e) {
      if (e instanceof Error && e.message === 'UNAUTHORIZED') {
        router.replaceUrl({ url: 'pages/LoginPage' });
        return;
      }
      // 422（密码错误/业务校验）等失败展示服务端消息，不能提示成功
      const message = e instanceof Error && e.message ? e.message : '删除失败';
      promptAction.showToast({ message });
    }
  }

  build() {
    Column() {
      // 顶栏
      Row() {
        Button({ type: ButtonType.Circle }) {
          Image($rawfile('back.png')).width(24).height(24)
        }
        .width(36).height(36)
        .backgroundColor('#FFFFFF')
        .onClick(() => router.back())

        Text(this.mode === 'create' ? '新增用户' : '用户详情')
          .fontSize(18)
          .fontWeight(FontWeight.Bold)
          .margin({ left: 8 })

        Blank()

        if (this.mode !== 'create') {
          Button('删除')
            .fontSize(13)
            .fontColor('#FF4D4F')
            .backgroundColor('#FFE6E6')
            .borderRadius(4)
            .height(32)
            .onClick(() => this.deleteUser())
        }
      }
      .width('100%')
      .height(56)
      .padding({ left: 16, right: 16 })
      .backgroundColor('#FFFFFF')

      if (this.isLoading) {
        LoadingView();
      } else if (this.hasError) {
        ErrorView({ onRetry: () => this.loadDetail() });
      } else {
        Scroll() {
          Column() {
            // 头像
            Circle({ width: 72, height: 72 })
              .fill('#E6F0FF')
              .margin({ top: 24, bottom: 24 })

            // 表单
            this.buildFormItem('用户名', () => {
              TextInput({ placeholder: '请输入用户名', text: this.formUsername })
                .fontSize(15)
                .enabled(this.mode === 'create')
                .onChange((v: string) => { this.formUsername = v; })
            });

            if (this.mode === 'create') {
              this.buildFormItem('密码', () => {
                TextInput({ placeholder: '请输入密码', text: this.formPassword })
                  .fontSize(15)
                  .type(InputType.Password)
                  .onChange((v: string) => { this.formPassword = v; })
              });

              // 角色多选：至少选择一个，role_ids 随创建请求提交
              Column() {
                Text('角色')
                  .fontSize(15)
                  .fontColor('#333333')

                if (this.rolesLoading) {
                  Text('加载中...').fontSize(13).fontColor('#999999').margin({ top: 8 })
                } else if (this.roles.length === 0) {
                  Text('暂无可分配角色').fontSize(13).fontColor('#FF4D4F').margin({ top: 8 })
                } else {
                  Flex({ wrap: FlexWrap.Wrap }) {
                    ForEach(this.roles, (role: RoleOption) => {
                      Row() {
                        Checkbox({ name: role.id })
                          .select(this.selectedRoleIds.indexOf(role.id) >= 0)
                          .selectedColor('#1677FF')
                          .onChange((checked: boolean) => { this.toggleRole(role.id, checked); })

                        Text(role.name)
                          .fontSize(14)
                          .fontColor('#333333')
                      }
                      .margin({ right: 16, top: 8 })
                    }, (role: RoleOption) => role.id)
                  }
                  .width('100%')
                }

                if (this.roleError) {
                  Text('请至少选择一个角色').fontSize(12).fontColor('#FF4D4F').margin({ top: 8 })
                }
              }
              .width('100%')
              .padding({ left: 16, right: 16, top: 12, bottom: 12 })
              .backgroundColor('#FFFFFF')
              .alignItems(HorizontalAlign.Start)
              .border({ width: { bottom: 0.5 }, color: '#EEEEEE' })
            }

            this.buildFormItem('姓名', () => {
              TextInput({ placeholder: '请输入真实姓名', text: this.formRealName })
                .fontSize(15)
                .onChange((v: string) => { this.formRealName = v; })
            });

            this.buildFormItem('手机号', () => {
              TextInput({ placeholder: '请输入手机号', text: this.formPhone })
                .fontSize(15)
                .type(InputType.PhoneNumber)
                .onChange((v: string) => { this.formPhone = v; })
            });

            this.buildFormItem('邮箱', () => {
              TextInput({ placeholder: '请输入邮箱', text: this.formEmail })
                .fontSize(15)
                .type(InputType.Email)
                .onChange((v: string) => { this.formEmail = v; })
            });

            this.buildFormItem('状态', () => {
              Row() {
                Button('启用')
                  .fontSize(13)
                  .fontColor(this.formStatus === 1 ? '#FFFFFF' : '#666666')
                  .backgroundColor(this.formStatus === 1 ? '#52C41A' : '#F5F5F5')
                  .borderRadius(4)
                  .height(32)
                  .onClick(() => { this.formStatus = 1; })

                Button('禁用')
                  .fontSize(13)
                  .fontColor(this.formStatus === 0 ? '#FFFFFF' : '#666666')
                  .backgroundColor(this.formStatus === 0 ? '#FF4D4F' : '#F5F5F5')
                  .borderRadius(4)
                  .height(32)
                  .margin({ left: 12 })
                  .onClick(() => { this.formStatus = 0; })
              }
            });

            // 提交按钮
            Button('保 存')
              .width('100%')
              .height(48)
              .fontSize(16)
              .fontColor('#FFFFFF')
              .backgroundColor(this.isSubmitting ? '#99BBFF' : '#1677FF')
              .borderRadius(8)
              .margin({ top: 32, left: 16, right: 16, bottom: 32 })
              .enabled(!this.isSubmitting)
              .onClick(() => { this.save(); })
          }
          .width('100%')
        }
        .width('100%')
        .layoutWeight(1)
        .backgroundColor('#F5F5F5')
      }
    }
    .width('100%')
    .height('100%')
  }

  @Builder
  buildFormItem(label: string, content: () => void) {
    Row() {
      Text(label)
        .fontSize(15)
        .fontColor('#333333')
        .width(80)

      content()
        .layoutWeight(1)
    }
    .width('100%')
    .height(56)
    .padding({ left: 16, right: 16 })
    .backgroundColor('#FFFFFF')
    .border({ width: { bottom: 0.5 }, color: '#EEEEEE' })
    .alignItems(VerticalAlign.Center)
  }
}
