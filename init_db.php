<?php
/**
 * 运行本脚本可以初始化 SQLite 数据库，创建两张表：
 *   - lines：存放线路基本信息
 *   - stops：存放每条线路对应的站点序列
 */

$dbFile = __DIR__ . '/data/bus_sim.db';
if (!is_dir(__DIR__.'/data')) {
    mkdir(__DIR__.'/data', 0755, true);
}

$db = new PDO('sqlite:' . $dbFile);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 创建 lines 表：存储线路基本参数
$db->exec("
CREATE TABLE IF NOT EXISTS lines (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    speed REAL NOT NULL,          -- km/h
    interval_min INTEGER NOT NULL, -- 分钟
    first_bus TEXT NOT NULL,      -- e.g. '06:00'
    last_bus TEXT NOT NULL,       -- e.g. '22:00'
    dwell_time INTEGER NOT NULL,  -- 停站时长（秒）
    base_fare REAL NOT NULL,      -- 基价
    per_km REAL NOT NULL,         -- 每公里单价
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
");

// 创建 stops 表：存储站点序列，按 order_index 排序
$db->exec("
CREATE TABLE IF NOT EXISTS stops (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    line_id INTEGER NOT NULL,
    stop_name TEXT NOT NULL,
    lat REAL NOT NULL,
    lng REAL NOT NULL,
    order_index INTEGER NOT NULL,
    FOREIGN KEY(line_id) REFERENCES lines(id) ON DELETE CASCADE
);
");

echo "数据库初始化完成\n";