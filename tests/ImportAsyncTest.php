<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace tests;

use app\admin\controller\ImportController;
use app\common\HashidsService;
use app\common\ImportJobService;
use app\process\ImportWorker;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;
use support\Db;
use support\Redis;
use support\Request;

/**
 * 异步导入链路：入队 → worker 消费 → 结果查询 / 失败清理 / 并发锁
 */
final class ImportAsyncTest extends TestCase
{
    private int $actorId;

    /** @var int[] 本测试创建、需在 tearDown 清理的行 */
    private array $userIds = [];
    private array $roleIds = [];

    /** @var string[] 本测试创建、需在 tearDown 清理的 Redis 任务与临时文件 */
    private array $jobIds = [];
    private array $files = [];

    public static function setUpBeforeClass(): void
    {
        try {
            Db::connection()->getPdo();
        } catch (\Throwable $e) {
            self::markTestSkipped('数据库不可用: ' . $e->getMessage());
        }
        try {
            Redis::ping();
        } catch (\Throwable $e) {
            self::markTestSkipped('Redis 不可用: ' . $e->getMessage());
        }

        // HashidsService 依赖插件容器绑定；测试 bootstrap 默认排除了 container 配置
        \Webman\Config::load(dirname(__DIR__) . '/config', ['route']);
        \Erikwang2013\Hashids\Webman\Bootstrap::start(null);
        if (!\support\Container::has('hashids')) {
            self::fail('hashids 容器初始化失败');
        }
    }

    protected function setUp(): void
    {
        $this->actorId = random_int(9_300_000_000_000_000, 9_399_999_999_999_999);
        Db::table('admin_user')->insert([
            'id'       => $this->actorId,
            'username' => 'phpunit_import_' . bin2hex(random_bytes(6)),
            'password' => password_hash('phpunit', PASSWORD_BCRYPT),
            'status'   => 1,
        ]);
        $this->userIds = [$this->actorId];
        $this->roleIds = $this->jobIds = $this->files = [];

        // 测试独占导入队列：清掉上次失败运行可能残留的锁与任务
        Redis::del(ImportJobService::LOCK_KEY);
        Redis::del(ImportJobService::QUEUE_KEY);
        Redis::del(ImportJobService::PROCESSING_KEY);
    }

    protected function tearDown(): void
    {
        try {
            Db::table('admin_user_role')->whereIn('user_id', $this->userIds)->delete();
            Db::table('admin_user')->whereIn('id', $this->userIds)->delete();
            if ($this->roleIds !== []) {
                Db::table('admin_role_permission')->whereIn('role_id', $this->roleIds)->delete();
                Db::table('admin_role')->whereIn('id', $this->roleIds)->delete();
            }
        } catch (\Throwable) {
            // 数据库中途不可用时忽略清理失败
        }
        try {
            foreach ($this->jobIds as $jobId) {
                Redis::del(ImportJobService::key($jobId));
            }
            // support\Redis 只给第一个参数加前缀，多键必须逐个删除
            foreach ([ImportJobService::LOCK_KEY, ImportJobService::QUEUE_KEY, ImportJobService::PROCESSING_KEY] as $key) {
                Redis::del($key);
            }
        } catch (\Throwable) {
            // Redis 中途不可用时忽略清理失败
        }
        foreach ($this->jobIds as $jobId) {
            @unlink(runtime_path('imports') . DIRECTORY_SEPARATOR . $jobId . '.xlsx');
        }
        foreach ($this->files as $file) {
            @unlink($file);
        }
        $this->files = [];
    }

    /**
     * @param string[] $usernames
     */
    private function xlsx(array $usernames): string
    {
        $path = sys_get_temp_dir() . '/phpunit_import_' . bin2hex(random_bytes(6)) . '.xlsx';
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->fromArray(['username', 'password', 'real_name'], null, 'A1');
        $rows = array_map(static fn (string $name): array => [$name, '123456', 'PHPUnit'], $usernames);
        $sheet->fromArray($rows, null, 'A2');
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();
        $this->files[] = $path;

        return $path;
    }

    private function multipart(string $filePath, array $fields = []): Request
    {
        $boundary = '----phpunit' . bin2hex(random_bytes(8));
        $body = '';
        foreach ($fields as $name => $values) {
            foreach ((array) $values as $value) {
                $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
            }
        }
        $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"import.xlsx\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\n" . file_get_contents($filePath) . "\r\n"
            . "--{$boundary}--\r\n";

        return new Request(
            "POST /admin/import/users HTTP/1.1\r\nHost: localhost\r\n"
            . "Content-Type: multipart/form-data; boundary={$boundary}\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body
        );
    }

    private function blankRequest(): Request
    {
        return new Request("GET /admin/import/status HTTP/1.1\r\nHost: localhost\r\n\r\n");
    }

    /**
     * @return array{0: int, 1: array}
     */
    private function callImport(Request $request): array
    {
        $response = (new ImportController())->users($request);
        // 上传临时文件在入队成功时已被移动到 runtime/imports；失败路径（如 409）需自行清理
        $upload = $request->file('file');
        if ($upload !== null) {
            $this->files[] = $upload->getPathname();
        }

        return [$response->getStatusCode(), json_decode($response->rawBody(), true, 512, JSON_THROW_ON_ERROR)];
    }

    public function testAsyncImportCompletesAndCleansUp(): void
    {
        $roleId = random_int(9_300_000_000_000_000, 9_399_999_999_999_999);
        Db::table('admin_role')->insert([
            'id'     => $roleId,
            'name'   => 'phpunit_import',
            'slug'   => 'phpunit_import_' . bin2hex(random_bytes(6)),
            'status' => 1,
        ]);
        $this->roleIds[] = $roleId;

        $usernames = ['phpunit_one_' . bin2hex(random_bytes(4)), 'phpunit_two_' . bin2hex(random_bytes(4))];
        $request = $this->multipart($this->xlsx($usernames), ['role_ids[]' => [HashidsService::encode($roleId)]]);
        $request->adminId = $this->actorId;

        [$status, $body] = $this->callImport($request);
        self::assertSame(202, $status);
        self::assertSame(0, $body['code']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $body['data']['job_id']);
        self::assertSame('queued', $body['data']['status']);

        $jobId = $body['data']['job_id'];
        $this->jobIds[] = $jobId;
        self::assertFileExists(runtime_path('imports') . DIRECTORY_SEPARATOR . $jobId . '.xlsx');
        self::assertContains($jobId, Redis::lrange(ImportJobService::QUEUE_KEY, 0, -1));

        (new ImportWorker())->tick();

        $job = ImportJobService::load($jobId);
        self::assertIsArray($job);
        self::assertSame('completed', $job['status']);
        self::assertSame(2, $job['result']['total']);
        self::assertSame(2, $job['result']['success']);
        self::assertSame(0, $job['result']['failed']);

        $userIds = Db::table('admin_user')->whereIn('username', $usernames)->pluck('id')->all();
        self::assertCount(2, $userIds);
        $this->userIds = array_merge($this->userIds, array_map('intval', $userIds));
        self::assertSame(2, Db::table('admin_user_role')
            ->whereIn('user_id', $userIds)
            ->where('role_id', $roleId)
            ->count());

        // worker 处理完成后删除文件、释放锁、移出 processing
        self::assertFileDoesNotExist(runtime_path('imports') . DIRECTORY_SEPARATOR . $jobId . '.xlsx');
        self::assertFalse(Redis::get(ImportJobService::LOCK_KEY));
        self::assertNotContains($jobId, Redis::lrange(ImportJobService::PROCESSING_KEY, 0, -1));
    }

    public function testBusyLockReturns409WithoutQueueing(): void
    {
        $owner = bin2hex(random_bytes(8));
        self::assertTrue(ImportJobService::acquireLock($owner));

        $request = $this->multipart($this->xlsx(['phpunit_busy_' . bin2hex(random_bytes(4))]));
        $request->adminId = $this->actorId;

        try {
            [$status, $body] = $this->callImport($request);
            self::assertSame(409, $status);
            self::assertSame(409, $body['code']);
            self::assertStringContainsString('正在执行', $body['message']);
            self::assertSame(0, (int) Redis::llen(ImportJobService::QUEUE_KEY));
            self::assertSame($owner, Redis::get(ImportJobService::LOCK_KEY));
        } finally {
            ImportJobService::releaseLock($owner);
        }
    }

    public function testStatusIsHiddenFromOtherAdmins(): void
    {
        $controller = new ImportController();
        $request = $this->blankRequest();
        $request->adminId = $this->actorId;

        self::assertSame(404, $controller->status($request, bin2hex(random_bytes(16)))->getStatusCode());
        self::assertSame(404, $controller->status($request, 'not-a-job-id')->getStatusCode());

        $foreignJob = bin2hex(random_bytes(16));
        $this->jobIds[] = $foreignJob;
        ImportJobService::save([
            'job_id'     => $foreignJob,
            'status'     => 'queued',
            'admin_id'   => $this->actorId + 1,
            'file'       => $foreignJob . '.xlsx',
            'ext'        => 'xlsx',
            'role_ids'   => null,
            'lock'       => null,
            'created_at' => time(),
        ]);
        self::assertSame(404, $controller->status($request, $foreignJob)->getStatusCode());

        $ownJob = bin2hex(random_bytes(16));
        $this->jobIds[] = $ownJob;
        ImportJobService::save([
            'job_id'     => $ownJob,
            'status'     => 'queued',
            'admin_id'   => $this->actorId,
            'file'       => $ownJob . '.xlsx',
            'ext'        => 'xlsx',
            'role_ids'   => null,
            'lock'       => null,
            'created_at' => time(),
        ]);
        $response = $controller->status($request, $ownJob);
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode($response->rawBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($ownJob, $body['data']['job_id']);
        self::assertSame('queued', $body['data']['status']);
    }

    public function testWorkerRefusesJobWhoseLockWasReassigned(): void
    {
        $jobId = bin2hex(random_bytes(16));
        $this->jobIds[] = $jobId;
        $owner   = bin2hex(random_bytes(8));
        $foreign = bin2hex(random_bytes(8));
        $username = 'phpunit_lock_' . bin2hex(random_bytes(4));
        $dir = runtime_path('imports');
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        copy($this->xlsx([$username]), $dir . DIRECTORY_SEPARATOR . $jobId . '.xlsx');

        ImportJobService::save([
            'job_id'     => $jobId,
            'status'     => 'queued',
            'admin_id'   => $this->actorId,
            'file'       => $jobId . '.xlsx',
            'ext'        => 'xlsx',
            'role_ids'   => null,
            'lock'       => $owner,
            'created_at' => time(),
        ]);
        // 锁已过期并被另一个导入任务接管
        Redis::set(ImportJobService::LOCK_KEY, $foreign, ['ex' => 300]);
        Redis::rpush(ImportJobService::QUEUE_KEY, $jobId);

        (new ImportWorker())->tick();

        $job = ImportJobService::load($jobId);
        self::assertIsArray($job);
        self::assertSame('failed', $job['status']);
        self::assertStringContainsString('锁已被其他任务接管', $job['error']);
        self::assertFileDoesNotExist($dir . DIRECTORY_SEPARATOR . $jobId . '.xlsx');
        self::assertNotContains($jobId, Redis::lrange(ImportJobService::PROCESSING_KEY, 0, -1));
        // 不执行导入，也不误删他人的锁
        self::assertSame(0, Db::table('admin_user')->where('username', $username)->count());
        self::assertSame($foreign, Redis::get(ImportJobService::LOCK_KEY));
    }

    public function testRecoverRequeuesOnlyOwnedJobsAndCleansTerminalEntries(): void
    {
        $dir = runtime_path('imports');
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $owned     = bin2hex(random_bytes(16));
        $completed = bin2hex(random_bytes(16));
        $foreign   = bin2hex(random_bytes(16));
        $this->jobIds = array_merge($this->jobIds, [$owned, $completed, $foreign]);
        $owner = bin2hex(random_bytes(8));
        $other = bin2hex(random_bytes(8));
        self::assertTrue(ImportJobService::acquireLock($owner));

        $username = 'phpunit_recover_' . bin2hex(random_bytes(4));
        copy($this->xlsx([$username]), $dir . DIRECTORY_SEPARATOR . $owned . '.xlsx');
        ImportJobService::save([
            'job_id' => $owned, 'status' => 'processing', 'admin_id' => $this->actorId,
            'file' => $owned . '.xlsx', 'ext' => 'xlsx', 'role_ids' => null,
            'lock' => $owner, 'created_at' => time(), 'processing_at' => time() - 3600,
        ]);
        // 已完成的任务只清理，不再入队
        ImportJobService::save([
            'job_id' => $completed, 'status' => 'completed', 'admin_id' => $this->actorId,
            'file' => $completed . '.xlsx', 'ext' => 'xlsx', 'role_ids' => null,
            'lock' => $owner, 'created_at' => time(), 'processing_at' => time() - 3600,
        ]);
        // 锁已归属他人：失败清理，不能入队
        file_put_contents($dir . DIRECTORY_SEPARATOR . $foreign . '.xlsx', 'orphan');
        ImportJobService::save([
            'job_id' => $foreign, 'status' => 'processing', 'admin_id' => $this->actorId,
            'file' => $foreign . '.xlsx', 'ext' => 'xlsx', 'role_ids' => null,
            'lock' => $other, 'created_at' => time(), 'processing_at' => time() - 3600,
        ]);

        Redis::rpush(ImportJobService::PROCESSING_KEY, $owned);
        Redis::rpush(ImportJobService::PROCESSING_KEY, $completed);
        Redis::rpush(ImportJobService::PROCESSING_KEY, $foreign);

        (new ImportWorker())->recover();

        self::assertSame([$owned], Redis::lrange(ImportJobService::QUEUE_KEY, 0, -1));
        self::assertSame([], Redis::lrange(ImportJobService::PROCESSING_KEY, 0, -1));
        self::assertSame('completed', ImportJobService::load($completed)['status']);
        $foreignJob = ImportJobService::load($foreign);
        self::assertSame('failed', $foreignJob['status']);
        self::assertStringContainsString('锁已被其他任务接管', $foreignJob['error']);
        self::assertFileDoesNotExist($dir . DIRECTORY_SEPARATOR . $foreign . '.xlsx');
        self::assertSame($owner, Redis::get(ImportJobService::LOCK_KEY));

        // 放回队列的任务继续执行
        (new ImportWorker())->tick();
        $ownedJob = ImportJobService::load($owned);
        self::assertSame('completed', $ownedJob['status']);
        self::assertSame(1, $ownedJob['result']['success']);
        $userIds = Db::table('admin_user')->where('username', $username)->pluck('id')->all();
        self::assertCount(1, $userIds);
        $this->userIds = array_merge($this->userIds, array_map('intval', $userIds));
        self::assertFalse(Redis::get(ImportJobService::LOCK_KEY));
    }

    public function testRecoverDefersFreshProcessingEntry(): void
    {
        $jobId = bin2hex(random_bytes(16));
        $this->jobIds[] = $jobId;
        $owner = bin2hex(random_bytes(8));
        $dir = runtime_path('imports');
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        file_put_contents($dir . DIRECTORY_SEPARATOR . $jobId . '.xlsx', 'placeholder');
        self::assertTrue(ImportJobService::acquireLock($owner));
        ImportJobService::save([
            'job_id' => $jobId, 'status' => 'processing', 'admin_id' => $this->actorId,
            'file' => $jobId . '.xlsx', 'ext' => 'xlsx', 'role_ids' => null,
            'lock' => $owner, 'created_at' => time(), 'processing_at' => time(),
        ]);
        Redis::rpush(ImportJobService::PROCESSING_KEY, $jobId);

        // 刚启动的任务可能仍在其他进程执行：宽限期内保持 processing，不入队
        (new ImportWorker())->recover();
        self::assertSame([], Redis::lrange(ImportJobService::QUEUE_KEY, 0, -1));
        self::assertSame([$jobId], Redis::lrange(ImportJobService::PROCESSING_KEY, 0, -1));
        self::assertSame('processing', ImportJobService::load($jobId)['status']);

        // 宽限期过后重新检查：任务回到队列
        $job = ImportJobService::load($jobId);
        $job['processing_at'] = time() - 3600;
        ImportJobService::save($job);
        (new ImportWorker())->recover();
        self::assertSame([$jobId], Redis::lrange(ImportJobService::QUEUE_KEY, 0, -1));
        self::assertSame([], Redis::lrange(ImportJobService::PROCESSING_KEY, 0, -1));
    }

    public function testRecoverReacquiresExpiredLock(): void
    {
        $jobId = bin2hex(random_bytes(16));
        $this->jobIds[] = $jobId;
        $owner = bin2hex(random_bytes(8));
        $dir = runtime_path('imports');
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        file_put_contents($dir . DIRECTORY_SEPARATOR . $jobId . '.xlsx', 'placeholder');
        // 锁已过期（键不存在）
        self::assertFalse(Redis::get(ImportJobService::LOCK_KEY));
        ImportJobService::save([
            'job_id' => $jobId, 'status' => 'processing', 'admin_id' => $this->actorId,
            'file' => $jobId . '.xlsx', 'ext' => 'xlsx', 'role_ids' => null,
            'lock' => $owner, 'created_at' => time(), 'processing_at' => time() - 3600,
        ]);
        Redis::rpush(ImportJobService::PROCESSING_KEY, $jobId);

        (new ImportWorker())->recover();

        // 用原 owner 值重新抢占锁并放回队列，任务仍受锁保护
        self::assertSame($owner, Redis::get(ImportJobService::LOCK_KEY));
        self::assertSame([$jobId], Redis::lrange(ImportJobService::QUEUE_KEY, 0, -1));
        self::assertSame([], Redis::lrange(ImportJobService::PROCESSING_KEY, 0, -1));
    }

    public function testWorkerMarksFailedAndCleansUp(): void
    {
        $jobId = bin2hex(random_bytes(16));
        $this->jobIds[] = $jobId;
        $owner = bin2hex(random_bytes(8));
        $dir = runtime_path('imports');
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        file_put_contents($dir . DIRECTORY_SEPARATOR . $jobId . '.xlsx', 'not an excel file');

        self::assertTrue(ImportJobService::acquireLock($owner));
        ImportJobService::save([
            'job_id'     => $jobId,
            'status'     => 'queued',
            'admin_id'   => $this->actorId,
            'file'       => $jobId . '.xlsx',
            'ext'        => 'xlsx',
            'role_ids'   => null,
            'lock'       => $owner,
            'created_at' => time(),
        ]);
        Redis::rpush(ImportJobService::QUEUE_KEY, $jobId);

        (new ImportWorker())->tick();

        $job = ImportJobService::load($jobId);
        self::assertIsArray($job);
        self::assertSame('failed', $job['status']);
        self::assertNotEmpty($job['error']);
        self::assertFileDoesNotExist($dir . DIRECTORY_SEPARATOR . $jobId . '.xlsx');
        self::assertFalse(Redis::get(ImportJobService::LOCK_KEY));
        self::assertNotContains($jobId, Redis::lrange(ImportJobService::PROCESSING_KEY, 0, -1));
    }
}
