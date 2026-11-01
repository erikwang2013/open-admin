/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

import 'package:flutter/material.dart';
import 'package:get/get.dart';
import '../../services/api_service.dart';
import '../../services/encryption_service.dart';

class UserFormPage extends StatefulWidget {
  final Map<String, dynamic>? userData;
  const UserFormPage({super.key, this.userData});

  @override
  State<UserFormPage> createState() => _UserFormPageState();
}

class _UserFormPageState extends State<UserFormPage> {
  final _formKey = GlobalKey<FormState>();
  final _usernameCtrl = TextEditingController();
  final _passwordCtrl = TextEditingController();
  final _realNameCtrl = TextEditingController();
  final _phoneCtrl = TextEditingController();
  final _emailCtrl = TextEditingController();
  int _status = 1;
  bool _isLoading = false;
  bool _initLoading = true;
  String? _loadError;
  bool _roleError = false;
  String? _rolesError;
  bool _rolesLoading = true;
  List<Map<String, dynamic>> _roles = [];
  final Set<String> _selectedRoleIds = {};

  bool get isEdit => widget.userData != null;

  // 新增必须基于成功加载的角色列表提交；编辑在加载失败时保留原有角色
  bool get _canSubmit => !_isLoading && !_rolesLoading && (isEdit || (_rolesError == null && _roles.isNotEmpty));

  @override
  void initState() {
    super.initState();
    // 列表行的 phone/email 是脱敏值，编辑时只能使用详情接口的明文
    if (isEdit) _usernameCtrl.text = widget.userData!['username'] ?? '';
    _loadInitial();
  }

  Future<void> _loadInitial() async {
    if (isEdit) {
      try {
        final resp = await ApiService().get('/admin/user/${widget.userData!['id']}');
        final detail = resp['data'] as Map<String, dynamic>;
        _realNameCtrl.text = detail['real_name']?.toString() ?? '';
        _phoneCtrl.text = detail['phone']?.toString() ?? '';
        _emailCtrl.text = detail['email']?.toString() ?? '';
        _status = (detail['status'] as num?)?.toInt() ?? 1;
        _selectedRoleIds
          ..clear()
          ..addAll((detail['role_ids'] as List<dynamic>? ?? const []).map((e) => e.toString()));
      } catch (e) {
        if (!mounted) return;
        setState(() {
          _initLoading = false;
          _loadError = '加载用户信息失败: $e';
        });
        return;
      }
    }
    await _loadRoles();
    if (mounted) setState(() => _initLoading = false);
  }

  Future<void> _loadRoles() async {
    try {
      final resp = await ApiService().get('/admin/role/options');
      final list = (resp['data'] as List<dynamic>).map((e) => e as Map<String, dynamic>).toList();
      if (!mounted) return;
      setState(() {
        _roles = list;
        _rolesError = null;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() => _rolesError = '加载角色列表失败: $e');
    } finally {
      if (mounted) setState(() => _rolesLoading = false);
    }
  }

  void _toggleRole(String id, bool selected) {
    setState(() {
      if (selected) {
        _selectedRoleIds.add(id);
        _roleError = false;
      } else {
        _selectedRoleIds.remove(id);
      }
    });
  }

  Future<void> _submit() async {
    if (!(_formKey.currentState?.validate() ?? false)) return;
    // 仅新增强制选角色；编辑允许清空（兼容无角色的存量用户）
    if (!isEdit && _roles.isNotEmpty && _selectedRoleIds.isEmpty) {
      setState(() => _roleError = true);
      return;
    }
    setState(() => _isLoading = true);

    final data = <String, dynamic>{
      'real_name': _realNameCtrl.text.trim(),
      'status': _status,
      'phone': EncryptionService.encrypt(_phoneCtrl.text.trim()),
      'email': EncryptionService.encrypt(_emailCtrl.text.trim()),
    };
    // 角色列表加载失败时不提交 role_ids，避免更新时误清空已有角色
    if (_roles.isNotEmpty) data['role_ids'] = _selectedRoleIds.toList();
    if (!isEdit) {
      data['username'] = _usernameCtrl.text.trim();
      data['password'] = EncryptionService.encrypt(_passwordCtrl.text);
    } else if (_passwordCtrl.text.isNotEmpty) {
      data['password'] = EncryptionService.encrypt(_passwordCtrl.text);
    }

    try {
      final api = ApiService();
      if (isEdit) {
        await api.put('/admin/user/${widget.userData!['id']}', data: data);
      } else {
        await api.post('/admin/user', data: data);
      }
      Get.snackbar('成功', isEdit ? '用户更新成功' : '用户创建成功');
      Get.back(result: true);
    } catch (e) {
      Get.snackbar('错误', '操作失败: $e');
    } finally {
      setState(() => _isLoading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(isEdit ? '编辑用户' : '新增用户')),
      body: _initLoading
          ? const Center(child: CircularProgressIndicator())
          : _loadError != null
              ? Center(
                  child: Column(mainAxisSize: MainAxisSize.min, children: [
                    Text(_loadError!, style: TextStyle(color: Theme.of(context).colorScheme.error)),
                    const SizedBox(height: 12),
                    ElevatedButton(
                      onPressed: () {
                        setState(() {
                          _initLoading = true;
                          _loadError = null;
                        });
                        _loadInitial();
                      },
                      child: const Text('重试'),
                    ),
                  ]),
                )
              : Center(
                  child: SizedBox(
                    width: 500,
                    child: Form(
                      key: _formKey,
                      child: ListView(
                        padding: const EdgeInsets.all(24),
                        children: [
                          TextFormField(controller: _usernameCtrl, enabled: !isEdit, decoration: const InputDecoration(labelText: '用户名'), validator: (v) => (v == null || v.isEmpty) ? '请输入用户名' : null),
                          const SizedBox(height: 16),
                          TextFormField(controller: _passwordCtrl, obscureText: true, decoration: InputDecoration(labelText: isEdit ? '新密码（留空不修改）' : '密码'), validator: (v) => !isEdit && (v == null || v.isEmpty) ? '请输入密码' : null),
                          const SizedBox(height: 16),
                          TextFormField(controller: _realNameCtrl, decoration: const InputDecoration(labelText: '真实姓名'), validator: (v) => (v == null || v.isEmpty) ? '请输入真实姓名' : null),
                          const SizedBox(height: 16),
                          TextFormField(controller: _phoneCtrl, decoration: const InputDecoration(labelText: '手机号')),
                          const SizedBox(height: 16),
                          TextFormField(controller: _emailCtrl, decoration: const InputDecoration(labelText: '邮箱')),
                          const SizedBox(height: 16),
                          Align(alignment: Alignment.centerLeft, child: Text('角色', style: Theme.of(context).textTheme.labelLarge)),
                          const SizedBox(height: 8),
                          if (_rolesLoading)
                            const Align(alignment: Alignment.centerLeft, child: SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)))
                          else if (_rolesError != null) ...[
                            Text(_rolesError!, style: TextStyle(color: Theme.of(context).colorScheme.error, fontSize: 12)),
                            Align(alignment: Alignment.centerLeft, child: TextButton(onPressed: () { setState(() { _rolesLoading = true; _rolesError = null; }); _loadRoles(); }, child: const Text('重试'))),
                          ] else if (_roles.isEmpty)
                            Text(isEdit ? '暂无可用角色' : '暂无可用角色，请先创建至少一个启用角色', style: const TextStyle(color: Colors.grey))
                          else
                            Wrap(
                              spacing: 8,
                              runSpacing: 4,
                              children: _roles.map((role) {
                                final id = role['id'].toString();
                                return FilterChip(
                                  label: Text(role['name']?.toString() ?? ''),
                                  selected: _selectedRoleIds.contains(id),
                                  onSelected: _isLoading ? null : (selected) => _toggleRole(id, selected),
                                );
                              }).toList(),
                            ),
                          if (_roleError) ...[
                            const SizedBox(height: 4),
                            Text('请至少选择一个角色', style: TextStyle(color: Theme.of(context).colorScheme.error, fontSize: 12)),
                          ],
                          const SizedBox(height: 16),
                          DropdownButtonFormField<int>(initialValue: _status, decoration: const InputDecoration(labelText: '状态'), items: const [
                            DropdownMenuItem(value: 1, child: Text('启用')),
                            DropdownMenuItem(value: 0, child: Text('禁用')),
                          ], onChanged: (v) => setState(() => _status = v ?? 1)),
                          const SizedBox(height: 24),
                          ElevatedButton(onPressed: _canSubmit ? _submit : null, child: Text(_isLoading ? '提交中...' : '提交')),
                        ],
                      ),
                    ),
                  ),
                ),
    );
  }
}
