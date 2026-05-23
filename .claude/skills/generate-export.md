---
name: generate-export
description: Excel export and dashboard PDF export for both admin and client interfaces
skill_type: implementation
---

# 生成导出功能

为管理端和客户端生成 Excel 导出与 PDF 导出功能。

## 后端导出

### Excel 导出（PhpSpreadsheet）

文件: `app/admin/controller/ExportController.php`

```php
<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

namespace app\admin\controller;

use app\common\HashidsService;
use app\common\SnowflakeService;
use app\common\EncryptionService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use support\Request;
use support\Response;

class ExportController extends BaseController
{
    /**
     * Excel 导出
     * POST /admin/export/excel
     */
    public function excel(Request $request): Response
    {
        $table = $request->input('table', '');
        $columns = $request->input('columns', []);
        $conditions = $request->input('conditions', []);

        $data = $this->fetchExportData($table, $columns, $conditions);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // 设置表头
        $headers = $this->getTableHeaders($table);
        $col = 'A';
        foreach ($headers as $header) {
            $cell = $sheet->getCell($col . '1');
            $cell->setValue($header['label']);
            // 表头样式
            $sheet->getStyle($col . '1')->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1677FF']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
            ]);
            $sheet->getColumnDimension($col)->setAutoSize(true);
            $col++;
        }

        // 填充数据
        $row = 2;
        foreach ($data as $item) {
            $col = 'A';
            foreach ($headers as $field => $header) {
                $value = $item[$field] ?? '';
                // 解密敏感字段
                if (in_array($field, ['phone', 'email', 'id_card'])) {
                    $value = EncryptionService::decrypt($value);
                }
                $sheet->getCell($col . $row)->setValue($value);
                $sheet->getStyle($col . $row)->getBorders()
                    ->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
                $col++;
            }
            $row++;
        }

        // 写入临时文件并返回下载
        $filename = sprintf('export_%s_%s.xlsx', $table, date('YmdHis'));
        $tmpFile = runtime_path() . '/tmp/' . $filename;

        $dir = dirname($tmpFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $writer = new Xlsx($spreadsheet);
        $writer->save($tmpFile);

        return response()->download($tmpFile, $filename);
    }

    /**
     * PDF 导出
     * POST /admin/export/pdf
     */
    public function pdf(Request $request): Response
    {
        $type = $request->input('type', 'table'); // table | dashboard
        $title = $request->input('title', '数据导出');
        $data = $request->input('data', []);

        $html = $this->buildPdfHtml($type, $title, $data);

        // 使用 dompdf 生成 PDF
        $pdf = new \Dompdf\Dompdf();
        $pdf->setPaper('A4', 'landscape');
        $pdf->loadHtml($html);
        $pdf->render();

        $filename = sprintf('export_%s_%s.pdf', $type, date('YmdHis'));
        $tmpFile = runtime_path() . '/tmp/' . $filename;

        $dir = dirname($tmpFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($tmpFile, $pdf->output());

        return response()->download($tmpFile, $filename);
    }

    /**
     * 构建 PDF HTML 模板
     */
    private function buildPdfHtml(string $type, string $title, array $data): string
    {
        $timestamp = date('Y-m-d H:i:s');

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    body { font-family: 'DejaVu Sans', sans-serif; margin: 20px; }
    .header { text-align: center; margin-bottom: 20px; }
    .header h1 { font-size: 20px; color: #1677FF; margin-bottom: 4px; }
    .header .meta { font-size: 11px; color: #999; }
    table { width: 100%; border-collapse: collapse; margin-top: 12px; }
    th { background-color: #1677FF; color: #fff; padding: 8px 10px; text-align: left; font-size: 12px; }
    td { padding: 6px 10px; border-bottom: 1px solid #eee; font-size: 11px; }
    tr:nth-child(even) { background-color: #fafafa; }
    .footer { text-align: center; font-size: 10px; color: #999; margin-top: 20px; border-top: 1px solid #eee; padding-top: 10px; }
</style>
</head>
<body>
<div class="header">
    <h1>{$title}</h1>
    <div class="meta">Copyright (c) 2026 erik &lt;erik@erik.xyz&gt; — https://erik.xyz</div>
    <div class="meta">导出时间: {$timestamp}</div>
</div>
HTML;

        if ($type === 'table') {
            $html .= $this->buildPdfTable($data);
        } else {
            $html .= $this->buildPdfDashboard($data);
        }

        $html .= <<<HTML
<div class="footer">Copyright (c) 2026 erik — https://erik.xyz | 本文件包含不可移除的版权信息</div>
</body>
</html>
HTML;

        return $html;
    }

    /**
     * PDF 表格渲染
     */
    private function buildPdfTable(array $data): string
    {
        if (empty($data['rows'])) return '<p>无数据</p>';

        $html = '<table><thead><tr>';
        foreach ($data['columns'] as $col) {
            $html .= '<th>' . htmlspecialchars($col) . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        foreach ($data['rows'] as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= '<td>' . htmlspecialchars((string)$cell) . '</td>';
            }
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        return $html;
    }

    /**
     * PDF 仪表盘渲染
     */
    private function buildPdfDashboard(array $data): string
    {
        $html = '<div style="display:flex;flex-wrap:wrap;gap:12px;margin-top:12px;">';

        foreach ($data['cards'] ?? [] as $card) {
            $html .= <<<HTML
<div style="flex:1;min-width:140px;padding:16px;background:#f5f5f5;border-radius:8px;text-align:center;">
    <div style="font-size:12px;color:#666;">{$card['label']}</div>
    <div style="font-size:24px;font-weight:bold;color:#1677FF;">{$card['value']}</div>
</div>
HTML;
        }

        $html .= '</div>';
        return $html;
    }
}
```

### 导出服务层

文件: `app/service/ExportService.php`

```php
<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

namespace app\service;

use app\common\EncryptionService;

class ExportService
{
    /**
     * 导出数据并脱敏处理
     */
    public static function sanitizeForExport(array $data, array $sensitiveFields = []): array
    {
        foreach ($data as &$row) {
            foreach ($sensitiveFields as $field) {
                if (isset($row[$field])) {
                    $decrypted = EncryptionService::decrypt($row[$field]);
                    // 导出时脱敏显示
                    $row[$field] = EncryptionService::mask($decrypted);
                }
            }
        }
        return $data;
    }

    /**
     * 获取导出字段映射
     */
    public static function getExportColumns(string $table): array
    {
        $maps = [
            'admin_user' => [
                'id' => '用户ID',
                'username' => '用户名',
                'real_name' => '真实姓名',
                'phone' => '手机号',
                'email' => '邮箱',
                'status' => '状态',
                'last_login_at' => '最后登录时间',
                'created_at' => '创建时间',
            ],
            'system_config' => [
                'id' => 'ID',
                'group' => '配置分组',
                'key' => '配置键',
                'value' => '配置值',
                'type' => '值类型',
                'description' => '说明',
                'created_at' => '创建时间',
            ],
        ];

        return $maps[$table] ?? [];
    }
}
```

## Flutter 端

### Excel 导出调用

```dart
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz

import 'package:dio/dio.dart';
import 'package:file_saver/file_saver.dart';

class ExportService {
  final Dio _dio;

  ExportService(this._dio);

  Future<void> exportExcel({
    required String table,
    required List<String> columns,
    Map<String, dynamic>? conditions,
  }) async {
    final response = await _dio.post(
      '/admin/export/excel',
      data: {
        'table': table,
        'columns': columns,
        'conditions': conditions,
      },
      options: Options(responseType: ResponseType.bytes),
    );

    final bytes = response.data;
    final filename = 'export_${table}_${DateTime.now().millisecondsSinceEpoch}.xlsx';
    await FileSaver.instance.saveFile(filename, bytes, 'xlsx');
  }

  Future<void> exportPdf({
    required String type,
    required String title,
    required Map<String, dynamic> data,
  }) async {
    final response = await _dio.post(
      '/admin/export/pdf',
      data: {
        'type': type,
        'title': title,
        'data': data,
      },
      options: Options(responseType: ResponseType.bytes),
    );

    final bytes = response.data;
    final filename = 'export_${type}_${DateTime.now().millisecondsSinceEpoch}.pdf';
    await FileSaver.instance.saveFile(filename, bytes, 'pdf');
  }
}
```

## 导出规范

1. Excel/PDF 文件必须包含版权信息（页脚或元数据）
2. 敏感数据在导出时脱敏处理（手机号中间4位星号，邮箱部分隐藏）
3. 大数量导出使用队列异步生成，完成后通知下载
4. 导出文件名包含时间戳，格式: `{类型}_{描述}_{YmdHis}.{扩展名}`
