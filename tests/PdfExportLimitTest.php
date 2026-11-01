<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace tests;

use app\admin\controller\ExportController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use support\Request;
use support\Response;

/**
 * PDF 导出上限：行数 / 单元格 / 卡片数量超限返回 422
 */
final class PdfExportLimitTest extends TestCase
{
    /** @var string[] 本测试生成的临时 PDF */
    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $file) {
            @unlink($file);
        }
        $this->tmpFiles = [];
    }

    private function pdf(array $payload): Response
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $request = new Request(
            "POST /admin/export/pdf HTTP/1.1\r\nHost: localhost\r\n"
            . "Content-Type: application/json\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body
        );

        return (new ExportController())->pdf($request);
    }

    private function json(Response $response): array
    {
        return json_decode($response->rawBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testMoreThan5000RowsIsRejected(): void
    {
        $response = $this->pdf(['data' => ['rows' => array_fill(0, 5001, [])]]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(422, $this->json($response)['code']);
        self::assertStringContainsString('5000', $this->json($response)['message']);
    }

    public function testMoreThan30000CellsIncludingEmptyColumnsIsRejected(): void
    {
        // 2000 行 × 16 列（全部为空）也计入单元格总数，防止用空列撑爆 PDF 渲染
        $rows = array_fill(0, 2000, array_fill(0, 16, ''));
        $response = $this->pdf(['data' => ['rows' => $rows]]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('30000', $this->json($response)['message']);
    }

    public function testNonArrayRowsAreRejected(): void
    {
        $response = $this->pdf(['data' => ['rows' => [['ok'], 'not-an-array']]]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(422, $this->json($response)['code']);
    }

    public function testNonArrayDataIsRejected(): void
    {
        $response = $this->pdf(['data' => 'not-an-array']);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(422, $this->json($response)['code']);
    }

    public function testMoreThan200StatsAreRejected(): void
    {
        $stats = array_fill(0, 201, ['label' => 'card', 'value' => 1]);
        $response = $this->pdf(['type' => 'dashboard', 'data' => ['stats' => $stats]]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('200', $this->json($response)['message']);
    }

    public function testNonArrayColumnsAreRejected(): void
    {
        $response = $this->pdf(['data' => ['columns' => 'not-an-array', 'rows' => [['x']]]]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(422, $this->json($response)['code']);
    }

    public function testMoreThan30000ColumnsAreRejected(): void
    {
        $response = $this->pdf(['data' => ['columns' => array_fill(0, 30001, 'c')]]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('30000', $this->json($response)['message']);
    }

    public function test100000CellsAreRejected(): void
    {
        // 5000 行 × 20 列 = 100000 个单元格
        $rows = array_fill(0, 5000, array_fill(0, 20, 'x'));
        $response = $this->pdf(['data' => ['columns' => ['A'], 'rows' => $rows]]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('30000', $this->json($response)['message']);
    }

    public function testNonScalarColumnIsRejected(): void
    {
        $response = $this->pdf(['data' => ['columns' => [['nested']], 'rows' => [['x']]]]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(422, $this->json($response)['code']);
    }

    public function testNonScalarCellIsRejected(): void
    {
        $response = $this->pdf(['data' => ['columns' => ['A'], 'rows' => [['ok', ['nested']]]]]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(422, $this->json($response)['code']);
    }

    public function testHeaderAndCellBoundary(): void
    {
        $guard = new ReflectionMethod(ExportController::class, 'pdfDataError');
        $controller = new ExportController();
        $columns = array_fill(0, 10000, 'c');
        // 10000 个表头 + 5000 行 × 4 列 = 30000
        $rows = array_fill(0, 5000, array_fill(0, 4, 'x'));

        self::assertNull($guard->invoke($controller, ['columns' => $columns, 'rows' => $rows]));

        $rows[0][] = 'x'; // 30001
        self::assertSame(
            '导出数据单元格超过 30000 个，请减少行数或列数',
            $guard->invoke($controller, ['columns' => $columns, 'rows' => $rows])
        );
    }

    public function testValidTableAndDashboardReturnPdf(): void
    {
        $cases = [
            ['type' => 'table', 'data' => ['columns' => ['A', 'B'], 'rows' => [['1', '2'], ['3', '4']]]],
            ['type' => 'dashboard', 'data' => ['stats' => [['label' => '用户', 'value' => 3]]]],
        ];

        foreach ($cases as $payload) {
            $response = $this->pdf($payload);
            self::assertSame(200, $response->getStatusCode());
            self::assertNotNull($response->file);

            $file = $response->file['file'];
            $this->tmpFiles[] = $file;
            self::assertFileExists($file);
            self::assertStringStartsWith('%PDF', (string) file_get_contents($file));
        }
    }
}
