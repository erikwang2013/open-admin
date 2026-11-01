<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace app\admin\controller;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use Dompdf\Dompdf;
use app\common\EncryptionService;
use app\model\AdminUser;
use app\model\OperationLog;
use app\model\AdminRole;
use app\model\SystemConfig;
use support\Request;
use support\Response;

/**
 * @Apidoc\Title("数据导出")
 */
class ExportController extends BaseController
{
    /**
     * @Apidoc\Title("导出Excel")
     * @Apidoc\Group("数据导出")
     * @Apidoc\Method("POST")
     * @Apidoc\Url("/admin/export/excel")
     * @Apidoc\Desc("导出数据为Excel文件，支持多表多字段")
     * @Apidoc\Param("table", type="string", require=true, desc="表名(admin_user/operation_log/admin_role/system_config)")
     * @Apidoc\Param("columns", type="array", require=false, desc="导出字段列表")
     * @Apidoc\Param("conditions", type="object", require=false, desc="筛选条件")
     * @Apidoc\Param("title", type="string", require=false, desc="导出标题", default="数据导出")
     */
    public function excel(Request $request): Response
    {
        $this->cleanupTempFiles();

        $table = $request->input('table', 'admin_user');
        $columns = $request->input('columns', []);
        $conditions = $request->input('conditions', []);
        $title = $request->input('title', '数据导出');

        // 获取导出字段映射，仅保留已知字段
        $exportColumns = $this->getExportColumns($table);
        if (!is_array($conditions)) {
            return $this->fail('筛选条件必须是对象', 422)->withStatus(422);
        }
        $unknownConditions = array_diff(array_keys($conditions), array_keys($exportColumns));
        if (!empty($unknownConditions)) {
            return $this->fail('不支持的筛选字段: ' . implode(', ', $unknownConditions), 422)->withStatus(422);
        }
        foreach ($conditions as $conditionValue) {
            if ($conditionValue !== null && !is_scalar($conditionValue)) {
                return $this->fail('筛选条件值必须是标量', 422)->withStatus(422);
            }
        }
        $columns = is_array($columns) ? $columns : [];
        $columns = array_values(array_intersect($columns, array_keys($exportColumns)));
        if (empty($columns)) {
            $columns = array_keys($exportColumns);
        }

        // 查询数据（多取 1 行用于判断是否超限）
        $data = $this->fetchExportData($table, $columns, $conditions);
        if (count($data) > 5000) {
            return $this->fail('导出数据超过 5000 行，请缩小筛选范围', 422)->withStatus(422);
        }
        $sensitiveFields = $this->getSensitiveFields($table);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheetTitle = mb_substr(str_replace(['\\', '/', '?', '*', '[', ']', ':'], '', (string) $title), 0, 31);
        $sheet->setTitle($sheetTitle !== '' ? $sheetTitle : '数据导出');

        // 表头样式
        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1677FF']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ];

        // 数据行样式
        $dataStyle = [
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ];

        $colIndex = 'A';
        foreach ($columns as $col) {
            $label = $exportColumns[$col] ?? $col;
            $cell = $sheet->getCell($colIndex . '1');
            $cell->setValue($label);
            $sheet->getStyle($colIndex . '1')->applyFromArray($headerStyle);
            $sheet->getColumnDimension($colIndex)->setAutoSize(true);
            $colIndex++;
        }

        // 填充数据
        $row = 2;
        foreach ($data as $item) {
            $colIndex = 'A';
            foreach ($columns as $col) {
                $value = $item[$col] ?? '';
                if (in_array($col, $sensitiveFields) && !empty($value)) {
                    // Encryptable cast 已自动解密，直接对明文脱敏
                    if ($col === 'phone') {
                        $value = EncryptionService::maskPhone((string) $value);
                    } elseif ($col === 'email') {
                        $value = EncryptionService::maskEmail((string) $value);
                    } else {
                        $value = str_repeat('*', 8); // id_card等彻底隐藏
                    }
                }
                // 强制字符串写入，防止以 = + - @ 开头的值被解析为公式
                $sheet->getCell($colIndex . $row)->setValueExplicit((string) $value, DataType::TYPE_STRING);
                $colIndex++;
            }
            $row++;
        }

        // 边框一次性应用到整个数据区域，避免逐格 applyFromArray 造成内存/耗时膨胀
        if ($row > 2) {
            $lastCol = Coordinate::stringFromColumnIndex(count($columns));
            $sheet->getStyle('A2:' . $lastCol . ($row - 1))->applyFromArray($dataStyle);
        }

        // 冻结首行
        $sheet->freezePane('A2');
        // 自动筛选
        $sheet->setAutoFilter($sheet->calculateWorksheetDimension());

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
     * @Apidoc\Title("导出PDF")
     * @Apidoc\Group("数据导出")
     * @Apidoc\Method("POST")
     * @Apidoc\Url("/admin/export/pdf")
     * @Apidoc\Desc("导出数据为PDF文件；rows 最多 5000 行、表头+单元格总数最多 30000、stats 最多 200 项，超出返回 422")
     * @Apidoc\Param("type", type="string", require=true, desc="导出类型(table/dashboard)")
     * @Apidoc\Param("title", type="string", require=false, desc="导出标题", default="数据导出")
     * @Apidoc\Param("data", type="object", require=false, desc="导出数据（data/rows/stats/columns 必须是对象或数组，rows 每个元素必须是数组，columns 与单元格必须是标量，超出行数、单元格或卡片上限返回 422）")
     */
    public function pdf(Request $request): Response
    {
        $this->cleanupTempFiles();

        $type = $request->input('type', 'table');
        $title = $request->input('title', '数据导出');
        $data = $request->input('data', []);
        if (!is_array($data)) {
            return $this->fail('导出数据必须是对象', 422)->withStatus(422);
        }
        $error = $this->pdfDataError($data);
        if ($error !== null) {
            return $this->fail($error, 422)->withStatus(422);
        }

        $html = $this->buildPdfHtml($type, $title, $data);

        $dompdf = new Dompdf();
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->loadHtml($html);
        $dompdf->render();

        $filename = sprintf('export_%s_%s.pdf', $type, date('YmdHis'));
        $tmpFile = runtime_path() . '/tmp/' . $filename;

        $dir = dirname($tmpFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($tmpFile, $dompdf->output());

        return response()->download($tmpFile, $filename);
    }

    /**
     * PDF 导出负载校验：data 内 columns/rows/stats 的形状、标量元素与行数、表头+单元格、卡片上限，返回错误消息，null 表示通过
     */
    private function pdfDataError(array $data): ?string
    {
        $cellCount = 0;
        if (isset($data['columns'])) {
            if (!is_array($data['columns'])) {
                return '导出数据的 columns 必须是数组';
            }
            if (count($data['columns']) > 30000) {
                return '导出数据单元格超过 30000 个，请减少行数或列数';
            }
            foreach ($data['columns'] as $col) {
                if (!is_scalar($col)) {
                    return '导出数据的 columns 中每个元素必须是标量';
                }
            }
            $cellCount = count($data['columns']);
        }

        if (isset($data['rows'])) {
            if (!is_array($data['rows'])) {
                return '导出数据的 rows 必须是数组';
            }
            if (count($data['rows']) > 5000) {
                return '导出数据超过 5000 行，请缩小筛选范围';
            }
            foreach ($data['rows'] as $row) {
                if (!is_array($row)) {
                    return '导出数据的 rows 中每个元素必须是数组';
                }
                foreach ($row as $cell) {
                    // null 视为空单元格，保持既有导出行为
                    if ($cell !== null && !is_scalar($cell)) {
                        return '导出数据的单元格必须是标量';
                    }
                }
                $cellCount += count($row);
            }
            if ($cellCount > 30000) {
                return '导出数据单元格超过 30000 个，请减少行数或列数';
            }
        }

        if (isset($data['stats'])) {
            if (!is_array($data['stats'])) {
                return '导出数据的 stats 必须是数组';
            }
            if (count($data['stats']) > 200) {
                return '导出统计卡片超过 200 项';
            }
        }

        return null;
    }

    /**
     * 构建 PDF HTML 模板
     */
    private function buildPdfHtml(string $type, string $title, array $data): string
    {
        $timestamp = date('Y-m-d H:i:s');

        $html = '<!DOCTYPE html><html><head><meta charset="utf-8">';
        $html .= '<style>
            body { font-family: "DejaVu Sans", sans-serif; margin: 20px; }
            .header { text-align: center; margin-bottom: 20px; }
            .header h1 { font-size: 20px; color: #1677FF; margin-bottom: 4px; }
            .header .meta { font-size: 11px; color: #999; }
            table { width: 100%; border-collapse: collapse; margin-top: 12px; }
            th { background-color: #1677FF; color: #fff; padding: 8px 10px; text-align: left; font-size: 12px; }
            td { padding: 6px 10px; border-bottom: 1px solid #eee; font-size: 11px; }
            tr:nth-child(even) { background-color: #fafafa; }
            .footer { text-align: center; font-size: 10px; color: #999; margin-top: 20px; border-top: 1px solid #eee; padding-top: 10px; }
            .cards { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 12px; }
            .card { flex: 1; min-width: 140px; padding: 16px; background: #f5f5f5; border-radius: 8px; text-align: center; }
            .card-label { font-size: 12px; color: #666; }
            .card-value { font-size: 24px; font-weight: bold; color: #1677FF; }
        </style></head><body>';

        $html .= '<div class="header">';
        $html .= '<h1>' . htmlspecialchars($title) . '</h1>';
        $html .= '<div class="meta">Copyright (c) 2026 erik &lt;erik@erik.xyz&gt; — https://erik.xyz</div>';
        $html .= '<div class="meta">导出时间: ' . $timestamp . '</div>';
        $html .= '</div>';

        if ($type === 'dashboard') {
            $html .= '<div class="cards">';
            foreach (is_array($data['stats'] ?? null) ? $data['stats'] : [] as $card) {
                if (!is_array($card)) {
                    continue;
                }
                $label = is_scalar($card['label'] ?? null) ? (string) $card['label'] : '';
                $value = is_scalar($card['value'] ?? null) ? (string) $card['value'] : '';
                $html .= '<div class="card"><div class="card-label">' . htmlspecialchars($label) . '</div>';
                $html .= '<div class="card-value">' . htmlspecialchars($value) . '</div></div>';
            }
            $html .= '</div>';
        } elseif (is_array($data['columns'] ?? null) && is_array($data['rows'] ?? null) && !empty($data['rows'])) {
            $html .= '<table><thead><tr>';
            foreach ($data['columns'] as $col) {
                $html .= '<th>' . htmlspecialchars((string) $col) . '</th>';
            }
            $html .= '</tr></thead><tbody>';
            foreach ($data['rows'] as $row) {
                $html .= '<tr>';
                foreach ($row as $cell) {
                    $html .= '<td>' . htmlspecialchars((string) $cell) . '</td>';
                }
                $html .= '</tr>';
            }
            $html .= '</tbody></table>';
        }

        $html .= '<div class="footer">Copyright (c) 2026 erik — https://erik.xyz | 本文件包含不可移除的版权信息</div>';
        $html .= '</body></html>';

        return $html;
    }

    /**
     * 清理超过 1 小时的导出临时文件
     */
    private function cleanupTempFiles(): void
    {
        foreach (glob(runtime_path() . '/tmp/export_*') ?: [] as $file) {
            if (is_file($file) && @filemtime($file) < time() - 3600) {
                @unlink($file);
            }
        }
    }

    private function fetchExportData(string $table, array $columns, array $conditions): array
    {
        $modelMap = [
            'admin_user' => AdminUser::class,
            'operation_log' => OperationLog::class,
            'admin_role' => AdminRole::class,
            'system_config' => SystemConfig::class,
        ];

        if (!isset($modelMap[$table])) {
            return [];
        }

        $model = new $modelMap[$table]();
        $query = $model->newQuery();

        foreach ($conditions as $field => $value) {
            if (!empty($value) || $value === '0') {
                $query->where($field, $value);
            }
        }

        return $query->limit(5001)->get()->toArray();
    }

    private function getExportColumns(string $table): array
    {
        $maps = [
            'admin_user' => [
                'id' => '用户ID', 'username' => '用户名', 'real_name' => '真实姓名',
                'phone' => '手机号', 'email' => '邮箱', 'status' => '状态',
                'last_login_at' => '最后登录时间', 'last_login_ip' => '最后登录IP',
                'created_at' => '创建时间',
            ],
            'operation_log' => [
                'id' => 'ID', 'user_id' => '用户ID', 'action' => '操作动作',
                'method' => '请求方法', 'path' => '请求路径', 'ip' => 'IP地址',
                'created_at' => '操作时间',
            ],
            'admin_role' => [
                'id' => 'ID', 'name' => '角色名称', 'slug' => '角色标识',
                'description' => '描述', 'status' => '状态', 'created_at' => '创建时间',
            ],
            'system_config' => [
                'id' => 'ID', 'group' => '分组', 'key' => '配置键',
                'value' => '配置值', 'type' => '类型', 'description' => '说明',
                'created_at' => '创建时间',
            ],
        ];

        return $maps[$table] ?? [];
    }

    private function getSensitiveFields(string $table): array
    {
        $maps = [
            'admin_user' => ['phone', 'email', 'id_card'],
        ];
        return $maps[$table] ?? [];
    }
}
