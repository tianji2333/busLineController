<?php
// config.php —— 全局配置：包含管理员账号、数据库连接、权限检查

session_start();

// 管理员账户（已修改为 admin / 123456）
define('ADMIN_USER', 'admin');
define('ADMIN_PASS', '123456');

// 打开 SQLite 数据库 bus.db
$dbFile = __DIR__ . '/bus.db';
try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("数据库连接失败: " . htmlspecialchars($e->getMessage()));
}

// 如果需要检查管理员权限，调用此函数即可
function require_admin() {
    if (empty($_SESSION['is_admin'])) {
        header('Location: admin.php?action=login');
        exit;
    }
}

// 让全局 $db 代表数据库连接
$db = $pdo;