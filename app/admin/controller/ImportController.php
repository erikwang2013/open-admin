<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace app\admin\controller;

use app\common\RbacService;
use app\model\AdminRole;
use app\model\AdminUser;
use PhpOffice\PhpSpreadsheet\IOFactory;
use support\Db;
use support\Log;
use support\Redis;
use support\Request;
use support\Response;
use Webman\Validation\Validator;
use XMLReader;
use ZipArchive;

/**
 * @Apidoc\Title("数据导入")
 */
class ImportController extends BaseController
{
    /** 导入锁：同一时间只允许一个导入任务，避免并发重复写库 */
    private const IMPORT_LOCK_KEY = 'import_users_lock';

    /** 导入锁超时（秒），需覆盖 2000 行 bcrypt 的最坏耗时；进程异常退出时由 TTL 兜底释放 */
    private const IMPORT_LOCK_TTL = 1800;

    /**
     * @Apidoc\Title("导入用户")
     * @Apidoc\Group("数据导入")
     * @Apidoc\Method("POST")
     * @Apidoc\Url("/admin/import/users")
     * @Apidoc\Desc("通过Excel文件批量导入用户")
     * @Apidoc\Param("file", type="file", require=true, desc="Excel文件(.xlsx/.xls)")
     * @Apidoc\Param("role_ids", type="array", require=false, desc="角色hashid数组，导入用户统一分配这些角色")
     * @Apidoc\Returned("total", type="int", desc="总行数")
     * @Apidoc\Returned("success", type="int", desc="成功数")
     * @Apidoc\Returned("failed", type="int", desc="失败数")
     * @Apidoc\Returned("errors", type="array", desc="失败详情")
     */
    public function users(Request $request): Response
    {
        $file = $request->file('file');
        if (!$file || !$file->isValid()) {
            return $this->fail('请上传 Excel 文件', 422);
        }

        $ext = strtolower($file->getUploadExtension() ?: '');
        if (!in_array($ext, ['xlsx', 'xls'], true)) {
            return $this->fail('仅支持 .xlsx 或 .xls 文件', 422);
        }

        if ($file->getSize() > 5 * 1024 * 1024) {
            return $this->fail('文件大小不能超过 5MB', 422);
        }

        $roleIds = null;
        if ($request->input('role_ids') !== null) {
            $rawRoleIds = $request->input('role_ids', []);
            if (!is_array($rawRoleIds)) {
                return $this->fail('角色参数格式错误', 422);
            }

            $roleIds = [];
            foreach ($rawRoleIds as $hashid) {
                if (!is_scalar($hashid)) {
                    return $this->fail('角色参数格式错误', 422);
                }
                try {
                    $roleIds[] = $this->decodeId((string) $hashid);
                } catch (\InvalidArgumentException) {
                    return $this->fail('角色不存在', 422);
                }
            }
            $roleIds = array_values(array_unique($roleIds));

            $roles = AdminRole::whereIn('id', $roleIds)->get();
            if ($roles->count() !== count($roleIds)) {
                return $this->fail('角色不存在', 422);
            }
            // 只有超级管理员才能通过导入把 super_admin 分配给其他账号
            if ($roles->contains(static fn ($role) => $role->slug === 'super_admin')) {
                $isSuperAdmin = AdminUser::whereKey((int) ($request->adminId ?? 0))
                    ->whereHas('roles', static fn ($query) => $query->where('admin_role.slug', 'super_admin'))
                    ->exists();
                if (!$isSuperAdmin) {
                    return $this->fail('无权分配超级管理员角色', 403);
                }
            }
            // 非超级管理员只能分配自己权限集合内的角色，防止导入绕过 RBAC 提权
            if (!RbacService::canGrant((int) ($request->adminId ?? 0), $roleIds)) {
                return $this->fail('无权分配所选角色', 403);
            }
        }

        // SET NX 锁串行化整个导入流程，避免并发导入绕过重名检查并重复写库
        $lockValue = bin2hex(random_bytes(8));
        try {
            $locked = (bool) Redis::set(self::IMPORT_LOCK_KEY, $lockValue, ['nx', 'ex' => self::IMPORT_LOCK_TTL]);
        } catch (\Throwable) {
            // Redis 故障时降级为无锁导入（fail-open）
            $locked = true;
            $lockValue = null;
        }

        if (!$locked) {
            return $this->fail('已有导入任务正在执行，请稍后重试', 409)->withStatus(409);
        }

        try {
            return $this->processImport((string) $file->getRealPath(), $ext, $roleIds);
        } finally {
            // 只删除自己持有的锁，避免锁过期后误删新持有者的锁
            if ($lockValue !== null) {
                try {
                    Redis::eval(
                        "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end",
                        [self::IMPORT_LOCK_KEY, $lockValue],
                        1
                    );
                } catch (\Throwable) {
                    // 释放失败由 TTL 兜底
                }
            }
        }
    }

    /**
     * 解析 Excel 并逐个导入用户
     *
     * @param int[]|null $roleIds 统一分配的角色 ID，null 表示未指定
     */
    private function processImport(string $tmpPath, string $ext, ?array $roleIds): Response
    {
        try {
            // xlsx 本质是 ZIP：限制 xl/**.xml 解压后总量与单元格数，防止压缩炸弹或宽表在解析时撑爆 128M 内存
            if ($ext === 'xlsx' && class_exists(ZipArchive::class)) {
                $zip = new ZipArchive();
                if ($zip->open($tmpPath) !== true) {
                    return $this->fail('Excel 文件无法读取，请确认文件未损坏', 422);
                }
                try {
                    $xmlBytes = 0;
                    $cells = 0;
                    for ($i = 0, $total = $zip->numFiles; $i < $total; $i++) {
                        $name = $zip->getNameIndex($i);
                        if (!is_string($name) || !preg_match('#^xl/.*\.xml$#i', $name)) {
                            continue;
                        }
                        $xmlBytes += (int) ($zip->statIndex($i)['size'] ?? 0);
                        if ($xmlBytes > 8 * 1024 * 1024) {
                            return $this->fail('Excel 文件内容过大，无法导入', 422);
                        }
                        // 流式统计单元格，行数上限管不住列数/稀疏宽表
                        if (preg_match('#^xl/worksheets/[^/]+\.xml$#i', $name)) {
                            $xmlReader = new XMLReader();
                            try {
                                if (!$xmlReader->open("zip://{$tmpPath}#{$name}")) {
                                    throw new \RuntimeException('worksheet xml open failed');
                                }
                                while ($xmlReader->read()) {
                                    if ($xmlReader->nodeType === XMLReader::ELEMENT && $xmlReader->localName === 'c') {
                                        if (++$cells > 32000) {
                                            return $this->fail('Excel 文件单元格过多，无法导入', 422);
                                        }
                                    }
                                }
                            } finally {
                                $xmlReader->close();
                            }
                        }
                    }
                } finally {
                    $zip->close();
                }
            }

            $reader = IOFactory::createReaderForFile($tmpPath);
            $reader->setReadDataOnly(true);
            $reader->setReadEmptyCells(false);

            // 加载前先读工作表元数据拒绝超大表（xls/xlsx 都适用），避免整本工作簿进内存
            $error = self::workbookSizeError($reader->listWorksheetInfo($tmpPath));
            if ($error !== null) {
                return $this->fail($error, 422);
            }

            $spreadsheet = $reader->load($tmpPath);
        } catch (\Throwable $e) {
            return $this->fail('Excel 文件无法解析，请确认文件未损坏且格式正确', 422);
        }

        $sheet = $spreadsheet->getActiveSheet();

        // 再加一道实际数据边界校验，防止元数据与实际行数不一致
        if ($sheet->getHighestDataRow() > 2001) {
            return $this->fail('数据行数不能超过 2000 行（不含表头）', 422);
        }

        $rows = $sheet->toArray();

        if (count($rows) < 2) {
            return $this->fail('Excel 文件无数据', 422);
        }

        $headers = array_map('strtolower', array_map('trim', $rows[0]));
        $colMap  = array_flip($headers);

        $required = ['username', 'password', 'real_name'];
        foreach ($required as $col) {
            if (!isset($colMap[$col])) {
                return $this->fail("缺少必填列: {$col}", 422);
            }
        }

        // 一次性取回已存在用户名（含软删除），避免逐行查询
        $usernames = [];
        foreach (array_slice($rows, 1) as $row) {
            $name = trim((string) ($row[$colMap['username']] ?? ''));
            if ($name !== '') {
                $usernames[$name] = true;
            }
        }
        $existing = [];
        if ($usernames !== []) {
            foreach (AdminUser::withTrashed()->whereIn('username', array_keys($usernames))->pluck('username') as $name) {
                $existing[mb_strtolower((string) $name)] = true;
            }
        }

        $total   = 0;
        $success = 0;
        $failed  = 0;
        $errors  = [];
        $seen    = [];

        foreach ($rows as $idx => $row) {
            if ($idx === 0) continue;
            $total++;

            $username = trim((string) ($row[$colMap['username']] ?? ''));
            $password = trim((string) ($row[$colMap['password']] ?? ''));
            $realName = trim((string) ($row[$colMap['real_name']] ?? ''));
            $phone    = isset($colMap['phone']) ? trim((string) ($row[$colMap['phone']] ?? '')) : '';
            $email    = isset($colMap['email']) ? trim((string) ($row[$colMap['email']] ?? '')) : '';
            $status   = isset($colMap['status']) ? trim((string) ($row[$colMap['status']] ?? '')) : '';
            if ($status === '') {
                $status = '1';
            }

            $validator = Validator::make([
                'username'  => $username,
                'password'  => $password,
                'real_name' => $realName,
                'phone'     => $phone,
                'email'     => $email,
                'status'    => $status,
            ], [
                'username'  => 'required|string|min:3|max:50',
                'password'  => 'required|string|min:6|max:32',
                'real_name' => 'required|string|max:50',
                'phone'     => 'string|max:20',
                'email'     => 'string|max:254',
                'status'    => 'in:0,1',
            ]);

            if ($validator->fails()) {
                $failed++;
                $errors[] = ['row' => $idx + 1, 'reason' => $validator->errors()->first()];
                continue;
            }

            // 同文件内重复（大小写不敏感）在内存中去重，不再逐行查库
            $key = mb_strtolower($username);
            if (isset($existing[$key]) || isset($seen[$key])) {
                $failed++;
                $errors[] = ['row' => $idx + 1, 'reason' => "用户名 {$username} 已存在"];
                continue;
            }
            $seen[$key] = true;

            try {
                $user = new AdminUser();
                $user->id        = $this->generateId();
                $user->username  = $username;
                $user->password  = password_hash($password, PASSWORD_BCRYPT);
                $user->real_name = $realName;
                $user->status    = (int) $status;
                $user->phone     = $phone;
                $user->email     = $email;
                // 用户与角色关联同事务：角色写入失败时回滚用户行，避免出现无角色账号
                Db::connection()->transaction(static function () use ($user, $roleIds): void {
                    $user->save();
                    if ($roleIds !== null) {
                        $user->roles()->sync($roleIds);
                    }
                });
                $success++;
            } catch (\Throwable $e) {
                // 异常细节只写服务端日志，响应里不暴露数据库错误
                Log::warning("import users failed at row {$idx} (username {$username}): " . $e->getMessage());
                $failed++;
                $errors[] = ['row' => $idx + 1, 'reason' => '用户创建失败，请稍后重试'];
            }
        }

        return $this->success([
            'total'   => $total,
            'success' => $success,
            'failed'  => $failed,
            'errors'  => $errors,
        ], '导入完成');
    }

    /**
     * 校验工作表元数据（行数 / 单元格总数），超限返回错误消息
     *
     * @param array<int, array{totalRows?: int, totalColumns?: int}> $sheets
     */
    private static function workbookSizeError(array $sheets): ?string
    {
        foreach ($sheets as $sheet) {
            $rows    = (int) ($sheet['totalRows'] ?? 0);
            $columns = (int) ($sheet['totalColumns'] ?? 0);
            if ($rows > 2001 || $rows * max(1, $columns) > 32000) {
                return 'Excel 文件数据量过大，无法导入（最多 2000 行、32000 个单元格）';
            }
        }

        return null;
    }
}
