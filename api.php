<?php
// api.php —— 处理所有 AJAX 请求（用户登录/注册/升级 + 地标/线路相关）
// 使用 SQLite；如果已有老版本 users 表，会自动为其添加缺失的列（password_hash, points, speed_level, capacity_level）
header('Content-Type: application/json; charset=utf-8');
session_start();

$dbFile = __DIR__ . '/bus.db';
try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // —— 1. 如果 users 表不存在，则创建；如果已存在，则逐列检查并补充缺失列 —— //
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            username        TEXT UNIQUE,
            password_hash   TEXT,
            points          INTEGER DEFAULT 0,
            speed_level     INTEGER DEFAULT 0,
            capacity_level  INTEGER DEFAULT 0,
            created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");

    // 如果表已存在，但缺少下列列，就手动 ALTER 添加（在 catch 中忽略已存在的错误）
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN password_hash TEXT");
    } catch (Exception $ignore) { }
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN points INTEGER DEFAULT 0");
    } catch (Exception $ignore) { }
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN speed_level INTEGER DEFAULT 0");
    } catch (Exception $ignore) { }
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN capacity_level INTEGER DEFAULT 0");
    } catch (Exception $ignore) { }

    // —— 2. 如果 routes 表不存在，则创建 —— //
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS routes (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            name        TEXT,
            stations    TEXT,
            params      TEXT,
            votes       INTEGER DEFAULT 0,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");

    // —— 3. 如果 landmarks 表不存在，则创建 —— //
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS landmarks (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            name        TEXT,
            lng         REAL,
            lat         REAL,
            tag         TEXT,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
} catch (PDOException $e) {
    echo json_encode(['ok' => false, 'msg' => '数据库连接失败: ' . $e->getMessage()]);
    exit;
}

$act = $_GET['act'] ?? '';

// —— 用户注册 —— //
if ($act === 'register') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'msg' => '仅支持 POST']);
        exit;
    }
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    if ($username === '' || $password === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'msg' => '用户名和密码不能为空']);
        exit;
    }
    // 检查用户名是否已存在
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
        echo json_encode(['ok' => false, 'msg' => '用户名已存在']);
        exit;
    }
    // 插入新用户
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $pdo->prepare("INSERT INTO users (username, password_hash) VALUES (?, ?)");
    $res = $stmt->execute([$username, $hash]);
    if ($res) {
        $_SESSION['user_id'] = $pdo->lastInsertId();
        echo json_encode(['ok' => true]);
    } else {
        echo json_encode(['ok' => false, 'msg' => '注册失败']);
    }
    exit;
}

// —— 用户登录 —— //
if ($act === 'login') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'msg' => '仅支持 POST']);
        exit;
    }
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    if ($username === '' || $password === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'msg' => '用户名和密码不能为空']);
        exit;
    }
    $stmt = $pdo->prepare("SELECT id, password_hash FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && password_verify($password, $row['password_hash'])) {
        $_SESSION['user_id'] = $row['id'];
        echo json_encode(['ok' => true]);
    } else {
        echo json_encode(['ok' => false, 'msg' => '用户名或密码错误']);
    }
    exit;
}

// —— 用户登出 —— //
if ($act === 'logout') {
    session_destroy();
    echo json_encode(['ok' => true]);
    exit;
}

// —— 更新用户信息（升级/积分） —— //
if ($act === 'update_user') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'msg' => '仅支持 POST']);
        exit;
    }
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['ok' => false, 'msg' => '未登录']);
        exit;
    }
    $fields = [];
    $params = [ ':id' => $_SESSION['user_id'] ];
    if (isset($_POST['speed_level'])) {
        $fields[] = 'speed_level = :speed_level';
        $params[':speed_level'] = intval($_POST['speed_level']);
    }
    if (isset($_POST['capacity_level'])) {
        $fields[] = 'capacity_level = :capacity_level';
        $params[':capacity_level'] = intval($_POST['capacity_level']);
    }
    if (isset($_POST['points'])) {
        $fields[] = 'points = :points';
        $params[':points'] = intval($_POST['points']);
    }
    if (empty($fields)) {
        echo json_encode(['ok' => false, 'msg' => '没有可更新的字段']);
        exit;
    }
    $sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $res = $stmt->execute($params);
    echo json_encode(['ok' => (bool)$res]);
    exit;
}

// —— 获取当前用户信息 —— //
if ($act === 'get_user') {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['ok' => false, 'msg' => '未登录']);
        exit;
    }
    $stmt = $pdo->prepare("SELECT id, username, points, speed_level, capacity_level FROM users WHERE id = ?");
    $stmt->execute([ $_SESSION['user_id'] ]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user) {
        echo json_encode(['ok' => true, 'user' => $user], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['ok' => false, 'msg' => '用户不存在']);
    }
    exit;
}

// ——— 以下是原本的 地标/线路/点赞/榜单 相关接口 —— //

// -------------- 地标相关 -------------- //
if ($act === 'landmarks') {
    $stmt = $pdo->query("SELECT id,name,lng,lat,tag FROM landmarks ORDER BY created_at DESC");
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($list, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($act === 'add_landmark') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'msg' => '仅支持 POST']);
        exit;
    }
    $name = trim($_POST['name'] ?? '');
    $lng  = floatval($_POST['lng'] ?? 0);
    $lat  = floatval($_POST['lat'] ?? 0);
    $tag  = trim($_POST['tag'] ?? '');
    if (!$name || !$lng || !$lat) {
        echo json_encode(['ok'=>false,'msg'=>'名称/坐标不能为空']);
        exit;
    }
    $stmt = $pdo->prepare("INSERT INTO landmarks (name,lng,lat,tag) VALUES (?,?,?,?)");
    $res = $stmt->execute([$name, $lng, $lat, $tag]);
    if ($res) {
        echo json_encode(['ok'=>true]);
    } else {
        echo json_encode(['ok'=>false,'msg'=>'插入地标失败']);
    }
    exit;
}

if ($act === 'del_landmark') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'msg' => '仅支持 POST']);
        exit;
    }
    $id = intval($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['ok'=>false,'msg'=>'ID 无效']);
        exit;
    }
    $stmt = $pdo->prepare("DELETE FROM landmarks WHERE id = ?");
    $res = $stmt->execute([$id]);
    echo json_encode(['ok'=> (bool)$res]);
    exit;
}

// -------------- 保存/加载线路 -------------- //
if ($act === 'save_route') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'msg' => '仅支持 POST']);
        exit;
    }
    $name     = trim($_POST['name'] ?? '');
    $stations = $_POST['stations'] ?? '[]';
    $params   = $_POST['params'] ?? '[]';
    if (!$name) {
        echo json_encode(['ok'=>false,'msg'=>'线路名称不能为空']);
        exit;
    }
    $stmt = $pdo->prepare("INSERT INTO routes (name,stations,params) VALUES (?,?,?)");
    $res = $stmt->execute([$name, $stations, $params]);
    if ($res) {
        echo json_encode(['ok'=>true, 'id'=>$pdo->lastInsertId()], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['ok'=>false,'msg'=>'保存失败']);
    }
    exit;
}

if ($act === 'vote_route') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'msg' => '仅支持 POST']);
        exit;
    }
    $id = intval($_POST['route_id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['ok'=>false,'msg'=>'ID 无效']);
        exit;
    }
    $stmt = $pdo->prepare("UPDATE routes SET votes = votes + 1 WHERE id = ?");
    $res = $stmt->execute([$id]);
    echo json_encode(['ok'=> (bool)$res]);
    exit;
}

if ($act === 'routes') {
    $order = $_GET['order'] === 'hot' ? 'votes DESC' : 'created_at DESC';
    $stmt = $pdo->query("SELECT id,name,votes FROM routes ORDER BY {$order} LIMIT 100");
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($list, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($act === 'route_detail') {
    $id = intval($_GET['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['ok'=>false,'msg'=>'ID 无效']);
        exit;
    }
    $stmt = $pdo->prepare("SELECT id,name,stations,params,votes FROM routes WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        echo json_encode(['ok'=>false,'msg'=>'未找到该线路']);
    } else {
        echo json_encode([
          'id'       => $row['id'],
          'name'     => $row['name'],
          'stations' => $row['stations'],
          'params'   => $row['params'],
          'votes'    => $row['votes']
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// 其他未知操作
echo json_encode(['ok'=>false,'msg'=>'未知操作']);
exit;