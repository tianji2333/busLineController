<?php
/**
 * admin.php —— 后台管理（登录/登出 + 线路 & 车型 增删改查）
 */
include 'config.php';

// 1. 处理 action 参数（login / logout / list / edit / delete / vehicles / edit_vehicle / delete_vehicle）
$action = $_GET['action'] ?? 'list';

/******************** 登录 / 登出 ********************/
if ($action === 'login') {
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $user = trim($_POST['username'] ?? '');
        $pass = trim($_POST['password'] ?? '');
        if ($user === ADMIN_USER && $pass === ADMIN_PASS) {
            $_SESSION['is_admin'] = true;
            header('Location: admin.php');
            exit;
        } else {
            $error = '用户名或密码错误';
        }
    }
    // 显示登录表单
    ?>
    <!doctype html>
    <html lang="zh">
    <head>
      <meta charset="utf-8">
      <title>后台登录</title>
      <script src="https://cdn.tailwindcss.com"></script>
      <style>
        body { background: #f3f4f6; }
        .login-box { width: 300px; margin: 100px auto; padding: 20px; background: #fff; border-radius: 6px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .login-box input { width: 100%; padding: 8px; margin-bottom: 12px; border: 1px solid #d1d5db; border-radius: 4px; }
        .login-box button { width: 100%; padding: 8px; background: #3b82f6; color: #fff; border: none; border-radius: 4px; }
        .error { color: #dc2626; font-size: 0.9rem; margin-bottom: 10px; }
      </style>
    </head>
    <body>
      <div class="login-box">
        <h2 class="text-center text-2xl font-bold mb-4">管理员登录</h2>
        <?php if ($error !== ''): ?>
          <p class="error"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>
        <form method="POST" action="admin.php?action=login">
          <input type="text" name="username" placeholder="用户名" required>
          <input type="password" name="password" placeholder="密码" required>
          <button type="submit">登录</button>
        </form>
      </div>
    </body>
    </html>
    <?php
    exit;
}

if ($action === 'logout') {
    unset($_SESSION['is_admin']);
    header('Location: admin.php?action=login');
    exit;
}

// 剩余所有操作，都需要管理员先登录
require_admin();

/******************** “线路管理” 模块 ********************/
/* 列表：admin.php?action=list */
if ($action === 'list') {
    // 从 routes 表里取所有线路
    $stmt   = $db->query("SELECT * FROM routes ORDER BY id DESC");
    $routes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <!doctype html>
    <html lang="zh">
    <head>
      <meta charset="utf-8">
      <title>线路管理</title>
      <script src="https://cdn.tailwindcss.com"></script>
      <style>
        body { background: #f9fafb; }
        .container { max-width: 900px; margin: 40px auto; padding: 20px; background: #fff; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #e5e7eb; padding: 8px; text-align: left; }
        th { background: #f3f4f6; }
        a.btn { padding: 4px 8px; border-radius: 4px; text-decoration: none; color: #fff; font-size: 0.9rem; }
        a.edit { background: #3b82f6; }
        a.delete { background: #ef4444; margin-left: 4px; }
        .tabs { display: flex; gap: 16px; }
        .tab { padding: 8px 16px; cursor: pointer; border-bottom: 2px solid transparent; }
        .tab-active { border-bottom-color: #3b82f6; font-weight: 600; }
      </style>
    </head>
    <body>
      <div class="container">
        <div class="flex justify-between items-center">
          <h1 class="text-2xl font-bold">公交线路 & 车型 管理</h1>
          <div>
            <a href="admin.php?action=edit" class="btn edit">＋ 新增线路</a>
            <a href="admin.php?action=vehicles" class="btn edit ml-2">车型管理</a>
            <a href="admin.php?action=logout" class="btn delete ml-2">退出登录</a>
          </div>
        </div>

        <table>
          <thead>
            <tr>
              <th>ID</th>
              <th>名称</th>
              <th>票价基价</th>
              <th>每公里单价</th>
              <th>创建时间</th>
              <th>操作</th>
            </tr>
          </thead>
          <tbody>
            <?php if (count($routes) > 0): ?>
              <?php foreach ($routes as $r): ?>
                <tr>
                  <td><?= $r['id'] ?></td>
                  <td><?= htmlspecialchars($r['name']) ?></td>
                  <?php
                    $pr = json_decode($r['params'], true);
                    $baseFare = isset($pr['baseFare']) ? $pr['baseFare'] : '-';
                    $perKm     = isset($pr['perKm']) ? $pr['perKm'] : '-';
                  ?>
                  <td>¥<?= $baseFare ?></td>
                  <td>¥<?= $perKm ?>/km</td>
                  <td><?= $r['created_at'] ?></td>
                  <td>
                    <a href="admin.php?action=edit&id=<?= $r['id'] ?>" class="btn edit">编辑</a>
                    <a href="admin.php?action=delete&id=<?= $r['id'] ?>" class="btn delete" onclick="return confirm('确定删除此线路吗？')">删除</a>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="6" class="text-center py-4">暂无线路数据。</td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </body>
    </html>
    <?php
    exit;
}

/* 新增/编辑线路：admin.php?action=edit  或  admin.php?action=edit&id=xxx */
if ($action === 'edit') {
    $isEdit = false;
    $route  = null;

    if (isset($_GET['id'])) {
        // 编辑模式：读取已存在记录
        $isEdit = true;
        $rid    = intval($_GET['id']);
        $stmt   = $db->prepare("SELECT * FROM routes WHERE id = :id");
        $stmt->execute([':id' => $rid]);
        $route = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$route) {
            die('指定线路不存在');
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $name     = trim($_POST['name'] ?? '');
        $stations = trim($_POST['stations'] ?? '');
        $params   = trim($_POST['params'] ?? '');

        if ($name === '')     die('线路名称不能为空');
        if ($stations === '') die('stations JSON 不能为空');
        if ($params === '')   die('params JSON 不能为空');

        if ($isEdit) {
            // 更新
            $stmt = $db->prepare("
                UPDATE routes
                SET name = :n, stations = :s, params = :p
                WHERE id = :id
            ");
            $stmt->execute([
                ':n'   => $name,
                ':s'   => $stations,
                ':p'   => $params,
                ':id'  => $route['id']
            ]);
        } else {
            // 新增
            $stmt = $db->prepare("
                INSERT INTO routes (name, stations, params)
                VALUES (:n, :s, :p)
            ");
            $stmt->execute([
                ':n' => $name,
                ':s' => $stations,
                ':p' => $params
            ]);
        }

        header('Location: admin.php?action=list');
        exit;
    }

    // 渲染 新增 / 编辑 表单
    ?>
    <!doctype html>
    <html lang="zh">
    <head>
      <meta charset="utf-8">
      <title><?= $isEdit ? '编辑线路' : '新增线路' ?></title>
      <script src="https://cdn.tailwindcss.com"></script>
      <style>
        body { background: #f9fafb; }
        .container { max-width: 700px; margin: 40px auto; padding: 20px; background: #fff; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        input, textarea, button { width: 100%; padding: 8px; margin-bottom: 12px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 1rem; }
        button { background: #3b82f6; color: #fff; border: none; cursor: pointer; }
        button:hover { background: #2563eb; }
        label { font-weight: 500; margin-bottom: 4px; display: block; }
      </style>
    </head>
    <body>
      <div class="container">
        <h1 class="text-2xl font-bold mb-4"><?= $isEdit ? '编辑公交线路' : '新增公交线路' ?></h1>
        <form method="POST" action="admin.php?action=edit<?= $isEdit ? '&id=' . $route['id'] : '' ?>">
          <label>线路名称：</label>
          <input type="text" name="name" required value="<?= $isEdit ? htmlspecialchars($route['name']) : '' ?>">

          <label>stations（JSON 格式）：</label>
          <textarea name="stations" rows="6" required><?= $isEdit ? htmlspecialchars($route['stations']) : '[{"name":"站A","ll":[121.47,31.23],"address":"示例地址"},{"name":"站B","ll":[121.48,31.235],"address":"示例地址"}]' ?></textarea>
          <p class="text-sm text-gray-500 mb-4">示例：<code>[{"name":"站A","ll":[121.47,31.23],"address":"xx"},{"name":"站B","ll":[121.48,31.235],"address":"yy"}]</code></p>

          <label>params（JSON 格式）：</label>
          <textarea name="params" rows="4" required><?= $isEdit ? htmlspecialchars($route['params']) : '{"speed":20,"dwellTime":20,"baseFare":2,"perKm":0.8,"vehicle":"标准公交"}' ?></textarea>
          <p class="text-sm text-gray-500 mb-4">示例：<code>{"speed":20,"dwellTime":20,"baseFare":2,"perKm":0.8,"vehicle":"标准公交"}</code></p>

          <button type="submit"><?= $isEdit ? '保存修改' : '创建线路' ?></button>
          <a href="admin.php?action=list" style="margin-left:12px; display:inline-block; margin-top:4px; color:#2563eb;">取消</a>
        </form>
      </div>
    </body>
    </html>
    <?php
    exit;
}

/* 删除线路：admin.php?action=delete&id=xxx */
if ($action === 'delete') {
    $rid = intval($_GET['id'] ?? 0);
    if ($rid > 0) {
        $stmt = $db->prepare("DELETE FROM routes WHERE id = :id");
        $stmt->execute([':id' => $rid]);
    }
    header('Location: admin.php?action=list');
    exit;
}

/******************** “车型管理” 模块（不使用数据库，读/写 vehicle_types.json） ********************/
/* 读取 JSON 文件，若不存在则使用默认列表 */
function load_vehicle_types() {
    $fn = __DIR__ . '/vehicle_types.json';
    if (file_exists($fn)) {
        $str = file_get_contents($fn);
        $arr = json_decode($str, true);
        if (is_array($arr)) return $arr;
    }
    // 默认示例车型
    return [
        ["name" => "标准公交", "speedMul" => 1.0, "capacityAdd" => 0],
        ["name" => "快速型",   "speedMul" => 1.2, "capacityAdd" => -5],
        ["name" => "大型车",   "speedMul" => 0.9, "capacityAdd" => 10]
    ];
}
/* 将数组写回 JSON 文件 */
function save_vehicle_types($arr) {
    $fn = __DIR__ . '/vehicle_types.json';
    file_put_contents($fn, json_encode($arr, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
}

/* 列表：admin.php?action=vehicles */
if ($action === 'vehicles') {
    $types = load_vehicle_types();
    ?>
    <!doctype html>
    <html lang="zh">
    <head>
      <meta charset="utf-8">
      <title>车型管理</title>
      <script src="https://cdn.tailwindcss.com"></script>
      <style>
        body { background: #f9fafb; }
        .container { max-width: 600px; margin: 40px auto; padding: 20px; background: #fff; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #e5e7eb; padding: 8px; text-align: left; }
        th { background: #f3f4f6; }
        a.btn { padding: 4px 8px; border-radius: 4px; text-decoration: none; color: #fff; font-size: 0.9rem; }
        a.edit { background: #3b82f6; }
        a.delete { background: #ef4444; margin-left: 4px; }
      </style>
    </head>
    <body>
      <div class="container">
        <div class="flex justify-between items-center">
          <h1 class="text-2xl font-bold">车型管理</h1>
          <div>
            <a href="admin.php?action=edit_vehicle" class="btn edit">＋ 新增车型</a>
            <a href="admin.php?action=list" class="btn delete ml-2">返回线路管理</a>
          </div>
        </div>
        <table>
          <thead>
            <tr>
              <th>序号</th>
              <th>车型名称</th>
              <th>速度倍率</th>
              <th>座位增减</th>
              <th>操作</th>
            </tr>
          </thead>
          <tbody>
            <?php if (count($types) > 0): ?>
              <?php foreach ($types as $idx => $t): ?>
                <tr>
                  <td><?= $idx + 1 ?></td>
                  <td><?= htmlspecialchars($t['name']) ?></td>
                  <td><?= $t['speedMul'] ?>×</td>
                  <td><?= $t['capacityAdd'] >= 0 ? '+' . $t['capacityAdd'] : $t['capacityAdd'] ?></td>
                  <td>
                    <a href="admin.php?action=edit_vehicle&idx=<?= $idx ?>" class="btn edit">编辑</a>
                    <a href="admin.php?action=delete_vehicle&idx=<?= $idx ?>" class="btn delete" onclick="return confirm('确定删除此车型吗？')">删除</a>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="5" class="text-center py-4">暂无车型数据。</td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </body>
    </html>
    <?php
    exit;
}

/* 新增／编辑单个车型：admin.php?action=edit_vehicle 或 admin.php?action=edit_vehicle&idx=xxx */
if ($action === 'edit_vehicle') {
    $isEdit    = false;
    $vehicle   = ["name" => "", "speedMul" => 1.0, "capacityAdd" => 0];
    $types     = load_vehicle_types();
    $editIdx   = null;

    if (isset($_GET['idx'])) {
        $isEdit  = true;
        $editIdx = intval($_GET['idx']);
        if (!isset($types[$editIdx])) {
            die('指定车型不存在');
        }
        $vehicle = $types[$editIdx];
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $nm   = trim($_POST['name'] ?? '');
        $sm   = floatval($_POST['speedMul'] ?? 1.0);
        $ca   = intval($_POST['capacityAdd'] ?? 0);
        if ($nm === '') die('车型名称不能为空');

        $newOne = ["name" => $nm, "speedMul" => $sm, "capacityAdd" => $ca];
        if ($isEdit) {
            $types[$editIdx] = $newOne;
        } else {
            $types[] = $newOne;
        }
        save_vehicle_types($types);
        header('Location: admin.php?action=vehicles');
        exit;
    }

    // 渲染 新增／编辑 车型 表单
    ?>
    <!doctype html>
    <html lang="zh">
    <head>
      <meta charset="utf-8">
      <title><?= $isEdit ? '编辑车型' : '新增车型' ?></title>
      <script src="https://cdn.tailwindcss.com"></script>
      <style>
        body { background: #f9fafb; }
        .container { max-width: 500px; margin: 40px auto; padding: 20px; background: #fff; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        input, button { width: 100%; padding: 8px; margin-bottom: 12px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 1rem; }
        button { background: #3b82f6; color: #fff; border: none; cursor: pointer; }
        button:hover { background: #2563eb; }
        label { font-weight: 500; margin-bottom: 4px; display: block; }
      </style>
    </head>
    <body>
      <div class="container">
        <h1 class="text-2xl font-bold mb-4"><?= $isEdit ? '编辑车型' : '新增车型' ?></h1>
        <form method="POST" action="admin.php?action=edit_vehicle<?= $isEdit ? '&idx=' . $editIdx : '' ?>">
          <label>车型名称：</label>
          <input type="text" name="name" required value="<?= htmlspecialchars($vehicle['name']) ?>">

          <label>速度倍率（如 1.2 表示原始速度的 120%）：</label>
          <input type="number" name="speedMul" step="0.1" min="0.1" value="<?= $vehicle['speedMul'] ?>">

          <label>座位增减（负数表示少，正数表示多）：</label>
          <input type="number" name="capacityAdd" value="<?= $vehicle['capacityAdd'] ?>">

          <button type="submit"><?= $isEdit ? '保存修改' : '创建车型' ?></button>
          <a href="admin.php?action=vehicles" style="margin-left:12px; display:inline-block; margin-top:4px; color:#2563eb;">取消</a>
        </form>
      </div>
    </body>
    </html>
    <?php
    exit;
}

/* 删除单个车型：admin.php?action=delete_vehicle&idx=xxx */
if ($action === 'delete_vehicle') {
    $idx = intval($_GET['idx'] ?? -1);
    $types = load_vehicle_types();
    if ($idx >= 0 && isset($types[$idx])) {
        array_splice($types, $idx, 1);
        save_vehicle_types($types);
    }
    header('Location: admin.php?action=vehicles');
    exit;
}

/* 如果 action 未匹配，默认跳回线路列表 */
header('Location: admin.php?action=list');
exit;