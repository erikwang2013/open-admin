<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

/**
 * 单机生产部署 — 一次性初始化管理员
 *
 * 由 compose 的 init 服务执行（php /app/init-admin.php），也可手动:
 *   docker compose -f deploy/docker-compose.yml run --rm init
 *
 * 行为:
 *   1. 最多等待 MySQL 60 秒；
 *   2. erik_admin_user 表不存在时执行 database/install.sql；
 *   3. 没有任何管理员时创建 ADMIN_USERNAME / ADMIN_PASSWORD 并绑定 super_admin 角色；
 *   4. 已存在管理员时不做任何修改（不会重置密码），只确保 install.lock 存在。
 */

const SUPER_ADMIN_ROLE_ID = '10000000000000001';
const INSTALL_LOCK = '/app/runtime/install.lock';

function envValue(string $key, string $default = ''): string
{
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

function fail(string $message): never
{
    fwrite(STDERR, "[init-admin] {$message}\n");
    exit(1);
}

function writeInstallLock(): void
{
    if (file_put_contents(INSTALL_LOCK, date('Y-m-d H:i:s')) === false) {
        fail('无法写入 ' . INSTALL_LOCK . '，请确认 deploy/data/runtime 可写');
    }
}

$dbHost = envValue('DB_HOST', 'mysql');
$dbPort = envValue('DB_PORT', '3306');
$dbName = envValue('DB_DATABASE', 'open_admin');
$dbUser = envValue('DB_USERNAME', 'root');
$dbPass = envValue('DB_PASSWORD');
$adminUsername = envValue('ADMIN_USERNAME', 'admin');
$adminPassword = envValue('ADMIN_PASSWORD');

if ($adminPassword === '') {
    fail('未配置 ADMIN_PASSWORD');
}

// ---------- 等待 MySQL ----------

$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $dbHost, $dbPort, $dbName);
$pdo = null;
$deadline = time() + 60;
while (true) {
    try {
        $pdo = new PDO($dsn, $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        break;
    } catch (PDOException $e) {
        if (time() >= $deadline) {
            fail('等待 MySQL 超时: ' . $e->getMessage());
        }
        sleep(2);
    }
}

try {
    // ---------- 建表（仅在用户表缺失时，install.sql 自带 DROP，必须避免覆盖已有库） ----------

    $tableExists = $pdo->query("SHOW TABLES LIKE 'erik\\_admin\\_user'")->fetchColumn();
    if ($tableExists === false) {
        // 安装脚本含 DROP TABLE，只允许在完全空库上执行，避免误删已有数据
        $otherTables = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'erik\\_%'")->fetchColumn();
        if ($otherTables > 0) {
            fail('数据库中已有 erik_ 表但缺少用户表，已拒绝执行 install.sql 以避免覆盖数据');
        }
        $sqlFile = __DIR__ . '/database/install.sql';
        $sql = is_file($sqlFile) ? file_get_contents($sqlFile) : false;
        if ($sql === false) {
            fail('找不到 database/install.sql');
        }
        $pdo->exec($sql);
    }

    // ---------- 已存在管理员则跳过 ----------

    $adminCount = (int) $pdo->query('SELECT COUNT(*) FROM erik_admin_user')->fetchColumn();
    if ($adminCount > 0) {
        // 不修改任何用户数据；确保 Web 安装向导被锁住
        if (!is_file(INSTALL_LOCK)) {
            writeInstallLock();
        }
        echo "[init-admin] 管理员已存在（{$adminCount} 个），跳过创建\n";
        exit(0);
    }

    // ---------- 创建首个管理员 ----------

    require __DIR__ . '/vendor/autoload.php';

    $snowflake = new Erikwang2013\Snowflake\Snowflake(
        // 独立 worker id，避免与 app 进程（1/2）同毫秒生成相同主键
        workerId: 31,
        datacenterId: (int) envValue('SNOWFLAKE_DATACENTER_ID', '1'),
        epoch: (int) envValue('SNOWFLAKE_START_TIMESTAMP', '1700000000000'),
    );
    $userId = $snowflake->nextId();

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO `erik_admin_user` (`id`, `username`, `password`, `real_name`, `status`, `created_at`, `updated_at`)
             VALUES (?, ?, ?, ?, 1, NOW(), NOW())'
        )->execute([
            $userId,
            $adminUsername,
            password_hash($adminPassword, PASSWORD_BCRYPT),
            '系统管理员',
        ]);

        $pdo->prepare('INSERT INTO `erik_admin_user_role` (`user_id`, `role_id`) VALUES (?, ?)')
            ->execute([$userId, SUPER_ADMIN_ROLE_ID]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    writeInstallLock();
    echo "[init-admin] 管理员 {$adminUsername} 创建完成\n";
} catch (Throwable $e) {
    fail($e->getMessage());
}
