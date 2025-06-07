<?php
// index.php — 登录/注册 + 正常首页内容
session_start();

/**
 * 如果收到了登录请求，就尝试登录：
 * - 前端通过 AJAX 或者表单 POST ?act=login 提交 username/password
 * - 这里示例用表单提交，如果想用 AJAX，可以改成 fetch('api.php?act=login', ...)
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    // 采用 API 接口登录
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    if ($username === '' || $password === '') {
        $errorMsg = '用户名和密码不能为空';
    } else {
        // 直接调用 api.php?act=login 接口
        // 也可以把 login 逻辑直接写在这里，但为了保持与前面代码一致，直接调用 api.php
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'http://'.$_SERVER['HTTP_HOST'].dirname($_SERVER['PHP_SELF']).'/api.php?act=login');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'username' => $username,
            'password' => $password
        ]));
        $resp = curl_exec($ch);
        curl_close($ch);

        $json = json_decode($resp, true);
        if ($json && isset($json['ok']) && $json['ok'] === true) {
            // 登录成功：api.php 已经在 session 里写入 user_id
            header('Location: index.php');
            exit;
        } else {
            $errorMsg = $json['msg'] ?? '登录失败';
        }
    }
}

// 如果访问本页面、且已经登录，就显示正常的首页内容；否则显示登录表单
if (!isset($_SESSION['user_id'])):
    // —— 未登录：显示登录表单 —— ?>
    <!doctype html>
    <html lang="zh">
    <head>
      <meta charset="utf-8">
      <title>登录</title>
      <meta name="viewport" content="width=device-width,initial-scale=1">
      <script src="https://cdn.tailwindcss.com"></script>
      <style>
        body { background: #f3f4f6; }
        .login-box { width: 350px; margin: 100px auto; padding: 20px; background: #fff; border-radius: 6px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        input, button { width: 100%; padding: 8px; margin-bottom: 12px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 1rem; }
        button { background: #3b82f6; color: #fff; border: none; cursor: pointer; }
        button:hover { background: #2563eb; }
        .error { color: #dc2626; font-size: 0.9rem; margin-bottom: 8px; }
      </style>
    </head>
    <body>
      <div class="login-box">
        <h2 class="text-center text-2xl font-bold mb-4">用户登录</h2>
        <?php if (!empty($errorMsg)): ?>
          <p class="error"><?= htmlspecialchars($errorMsg) ?></p>
        <?php endif; ?>
        <form method="POST" action="index.php">
          <input type="hidden" name="action" value="login">
          <input type="text" name="username" placeholder="用户名" required>
          <input type="password" name="password" placeholder="密码" required>
          <button type="submit">登录</button>
        </form>
        <p class="text-center text-sm">还没有账号？<a href="index.php?register=1" class="text-blue-600 underline">注册新用户</a></p>
      </div>
    </body>
    </html>
<?php
    exit;  // 未登录时，不继续往下执行
endif;

// —— 到这里说明已登录 —— //
// 你可以从 session 里拿到 user_id，然后查询用户名或者其他信息：
try {
    $userStmt = $pdo->prepare("SELECT username, points, speed_level, capacity_level FROM users WHERE id = ?");
    $userStmt->execute([ $_SESSION['user_id'] ]);
    $currentUser = $userStmt->fetch(PDO::FETCH_ASSOC);
    if (!$currentUser) {
        // 如果 session 里的 user_id 在表里找不到，则强制登出
        session_destroy();
        header('Location: index.php');
        exit;
    }
} catch (Exception $_e) {
    // 数据库异常时，也强制登出
    session_destroy();
    header('Location: index.php');
    exit;
}

// —— 到这里，登录成功，可以渲染首页 —— //
?>
<!doctype html>
<html lang="zh">
<head>
  <meta charset="utf-8">
  <title>公交线路模拟器首页</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    body { background: #f3f4f6; }
    header { background: #fff; padding: 12px 24px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
    main  { max-width: 900px; margin: 20px auto; background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
    a.button { background: #3b82f6; color: #fff; padding: 8px 16px; border-radius: 4px; text-decoration: none; }
    a.button:hover { background: #2563eb; }
  </style>
</head>
<body>
  <header class="flex justify-between items-center">
    <div>
      <span class="font-semibold">用户：</span><span><?= htmlspecialchars($currentUser['username']) ?></span>
      <span class="ml-4">积分：<span><?= intval($currentUser['points']) ?></span></span>
    </div>
    <div class="space-x-4">
      <a href="game.php" class="button">进入调度游戏</a>
      <a href="logout.php" class="button bg-red-500 hover:bg-red-600">退出登录</a>
    </div>
  </header>

  <main>
    <h1 class="text-2xl font-bold mb-4">欢迎来到上海公交线路模拟器</h1>
    <p>这里是首页内容，可以放置：<br>
      - 快速进入参与线路的按钮<br>
      - 当前热门线路列表<br>
      - 你的积分、升级进度、成就等等
    </p>
    <div class="mt-6">
      <a href="dispatch_game.php" class="button">立即进入调度游戏（可视化版）</a>
    </div>
  </main>
</body>
</html>