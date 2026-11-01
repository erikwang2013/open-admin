<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace app\admin\controller;

use app\model\AdminUser;
use PhpOffice\PhpSpreadsheet\IOFactory;
use support\Request;
use support\Response;
use XMLReader;
use ZipArchive;

/**
 * @Apidoc\Title("数据导入")
 */
class ImportController extends BaseController
{
    /**
     * @Apidoc\Title("导入用户")
     * @Apidoc\Group("数据导入")
     * @Apidoc\Method("POST")
     * @Apidoc\Url("/admin/import/users")
     * @Apidoc\Desc("通过Excel文件批量导入用户")
     * @Apidoc\Param("file", type="file", require=true, desc="Excel文件(.xlsx/.xls)")
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

        $tmpPath = $file->getRealPath();

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

            // 加载前先读工作表元数据拒绝超大表，避免整本工作簿进内存
            $sheetRows = array_column($reader->listWorksheetInfo($tmpPath), 'totalRows');
            if ($sheetRows && max($sheetRows) > 2001) {
                return $this->fail('数据行数不能超过 2000 行（不含表头）', 422);
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

        $rows        = $sheet->toArray();

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

        $total   = 0;
        $success = 0;
        $failed  = 0;
        $errors  = [];

        foreach ($rows as $idx => $row) {
            if ($idx === 0) continue;
            $total++;

            $username = trim((string) ($row[$colMap['username']] ?? ''));
            $password = trim((string) ($row[$colMap['password']] ?? ''));
            $realName = trim((string) ($row[$colMap['real_name']] ?? ''));
            $phone    = isset($colMap['phone']) ? trim((string) ($row[$colMap['phone']] ?? '')) : '';
            $email    = isset($colMap['email']) ? trim((string) ($row[$colMap['email']] ?? '')) : '';
            $status   = isset($colMap['status']) ? (int) ($row[$colMap['status']] ?? 1) : 1;

            if (empty($username)) {
                $failed++;
                $errors[] = ['row' => $idx + 1, 'reason' => '用户名为空'];
                continue;
            }

            if ($password === '' || mb_strlen($password) < 6) {
                $failed++;
                $errors[] = ['row' => $idx + 1, 'reason' => '密码不能为空且至少 6 个字符'];
                continue;
            }

            if (AdminUser::withTrashed()->where('username', $username)->exists()) {
                $failed++;
                $errors[] = ['row' => $idx + 1, 'reason' => "用户名 {$username} 已存在"];
                continue;
            }

            try {
                $user = new AdminUser();
                $user->id        = $this->generateId();
                $user->username  = $username;
                $user->password  = password_hash($password, PASSWORD_BCRYPT);
                $user->real_name = $realName;
                $user->status    = in_array($status, [0, 1], true) ? $status : 1;
                $user->phone     = $phone;
                $user->email     = $email;
                $user->save();
                $success++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = ['row' => $idx + 1, 'reason' => $e->getMessage()];
            }
        }

        return $this->success([
            'total'   => $total,
            'success' => $success,
            'failed'  => $failed,
            'errors'  => $errors,
        ], '导入完成');
    }
}
