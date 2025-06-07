<?php
/**
 * dispatch_game.php —— 公交调度游戏（整合车型选择逻辑）
 * 依然使用同一个 SQLite 数据库 bus.db，且共享 index.php 登录态
 */

session_start();

// 如果未登录，则给出提示，并不跳转
if (!isset($_SESSION['user_id'])) {
    echo '<!doctype html><html lang="zh"><head><meta charset="utf-8"><title>请先登录</title></head><body>';
    echo '<p style="margin:50px; text-align:center; font-size:18px; color:#555;">请先在 <a href="index.php" style="color:#007acc; text-decoration:underline;">首页</a> 登录，然后再打开本页面。</p>';
    echo '</body></html>';
    exit;
}

try {
    // 打开与 index.php 同一个 SQLite 数据库 bus.db
    $dbFile = __DIR__ . '/bus.db';
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 读取当前用户信息（获取升级等级等）
    $stmt = $pdo->prepare("SELECT id, username, points, speed_level, capacity_level FROM users WHERE id = ?");
    $stmt->execute([ $_SESSION['user_id'] ]);
    $currentUser = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$currentUser) {
        session_destroy();
        echo '<!doctype html><html lang="zh"><head><meta charset="utf-8"><title>用户状态异常</title></head><body>';
        echo '<p style="margin:50px; text-align:center; font-size:18px; color:#555;">用户状态异常，请先 <a href="index.php" style="color:#007acc; text-decoration:underline;">登录</a>。</p>';
        echo '</body></html>';
        exit;
    }

    // 读取 routes 表
    $stmt2 = $pdo->query("SELECT id, name, stations, params FROM routes ORDER BY created_at DESC");
    $routes = $stmt2->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    die("数据库连接失败：" . htmlspecialchars($e->getMessage()));
}

$routesJson      = json_encode($routes, JSON_UNESCAPED_UNICODE);
$currentUserJson = json_encode($currentUser, JSON_UNESCAPED_UNICODE);

// **模拟车型数据**（暂时不保存到数据库，重启后会重置）
$vehicleTypes = [
    ['id' => 'standard', 'name' => '普通公交',      'baseSpeedMul' => 1.0, 'baseCapacity' => 30],
    ['id' => 'double',   'name' => '双层公交',      'baseSpeedMul' => 0.8, 'baseCapacity' => 50],
    ['id' => 'electric', 'name' => '电动公交（慢）','baseSpeedMul' => 0.7, 'baseCapacity' => 25]
];
$vehicleJson = json_encode($vehicleTypes, JSON_UNESCAPED_UNICODE);
?>
<!doctype html>
<html lang="zh">
<head>
  <meta charset="utf-8">
  <title>公交调度游戏</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">

  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    html, body { margin:0; padding:0; height:100%; background: #f3f4f6; }
    #map { width:100%; height:50vh; }
    .btn { @apply bg-blue-500 hover:bg-blue-600 text-white font-semibold py-2 px-4 rounded; }
    .btn-disabled { @apply bg-gray-400 text-white font-semibold py-2 px-4 rounded cursor-not-allowed; }
  </style>

  <!-- 高德地图 JSAPI & 安全码 -->
  <script>
    window._AMapSecurityConfig = {
      securityJsCode: 'be4a83fa67123622c932485c2228ebae'
    };
  </script>
  <script src="https://webapi.amap.com/maps?v=2.0&key=f6c37aecfaa9f4ff8717a771280284a0"></script>
</head>
<body class="flex flex-col items-center">

  <!-- ===== 头部：显示用户名与升级按钮 ===== -->
  <div class="w-full max-w-4xl bg-white p-4 flex justify-between items-center shadow">
    <div>
      <span class="font-semibold">用户：</span><span id="userNameDisplay"><?= htmlspecialchars($currentUser['username']) ?></span>
      <span class="ml-4 font-semibold">积分：</span><span id="userPoints"><?= intval($currentUser['points']) ?></span>
    </div>
    <div class="flex space-x-4">
      <div>
        <button id="btnUpgradeSpeed" class="btn text-sm px-3">升级 速度 (10 分)</button>
        <div class="text-xs text-gray-500">当前等级：<span id="speedLevel"><?= intval($currentUser['speed_level']) ?></span></div>
      </div>
      <div>
        <button id="btnUpgradeCapacity" class="btn text-sm px-3">升级 座位 (8 分)</button>
        <div class="text-xs text-gray-500">当前等级：<span id="capacityLevel"><?= intval($currentUser['capacity_level']) ?></span></div>
      </div>
      <a href="logout.php" class="btn text-sm px-3">退出登录</a>
    </div>
  </div>

  <!-- ===== 地图 ===== -->
  <div id="map"></div>

  <!-- ===== 下方：游戏控制区 ===== -->
  <div class="w-full max-w-4xl bg-white p-4 mt-4 rounded-lg shadow">
    <div class="grid grid-cols-3 gap-6">

      <!-- 线路选择 -->
      <div>
        <label class="block mb-1 font-medium">选择线路：</label>
        <select id="routeSelect" class="w-full border rounded px-2 py-1">
          <option value="">正在加载线路…</option>
        </select>
      </div>

      <!-- 车型选择 -->
      <div>
        <label class="block mb-1 font-medium">选择车型：</label>
        <select id="vehicleType" class="w-full border rounded px-2 py-1">
          <!-- JS 会填充 -->
        </select>
      </div>

      <!-- 发车间隔 -->
      <div>
        <label class="block mb-1 font-medium">发车间隔（秒）<span id="intervalDisplay">30</span></label>
        <input id="dispatchInterval" type="range" min="5" max="60" value="30" step="1" class="w-full">
      </div>
    </div>

    <div class="mt-6 flex space-x-4">
      <button id="loadRouteBtn" class="btn flex-1">加载并绘制线路</button>
      <button id="startGameBtn" class="btn flex-1" disabled>开始游戏</button>
      <button id="resetGameBtn" class="btn flex-1" disabled>重置游戏</button>
    </div>
  </div>

  <!-- ===== 记分板 ===== -->
  <div class="w-full max-w-4xl bg-white p-4 mt-4 rounded-lg shadow">
    <div class="grid grid-cols-2 gap-4">
      <div>已完成行程：<span id="completedTrips">0</span></div>
      <div>当前在途公交数：<span id="activeBuses">0</span></div>
      <div>平均等待时间（秒）：<span id="avgWait">0</span></div>
      <div>当前满意度：<span id="satisfaction">100%</span></div>
      <div>座位/负载：<span id="loadStatus">0 / 0</span></div>
    </div>
  </div>

  <script>
    // —— 把 PHP 注入的 JSON 先拿过来 ——   
    const routes       = <?= $routesJson ?>;
    const currentUser  = <?= $currentUserJson ?>;
    const vehicleTypes = <?= $vehicleJson ?>;

    let map, driving;
    let currentRoute = null;
    let routeCoords = [], routeDists = [], totalRouteLength = 0;
    let stationIndices = [], stationDists = [];
    let buses = [], completedCount = 0;
    let passengerQueues = [], allPassengerWaits = [];
    let gameStarted = false, dispatchTimer = null, passengerGenTimer = null;

    document.addEventListener('DOMContentLoaded', () => {
      initMapAndDriving();
      bindGameControls();
      populateRouteSelect();
      populateVehicleSelect();
      requestAnimationFrame(updateBuses);
    });

    // 初始化高德地图与驾车服务
    function initMapAndDriving() {
      map = new AMap.Map('map', { zoom:12, center:[121.4737,31.2304] });
      AMap.plugin('AMap.Driving', () => {
        driving = new AMap.Driving({
          map: map,
          policy: AMap.DrivingPolicy.LEAST_TIME,
          hideMarkers: true
        });
      });
    }

    // 填充线路下拉
    function populateRouteSelect() {
      const sel = document.getElementById('routeSelect');
      sel.innerHTML = '<option value="">-- 请选择线路 --</option>';
      routes.forEach(r => {
        const opt = document.createElement('option');
        opt.value = r.id;
        opt.textContent = r.name;
        sel.appendChild(opt);
      });
      sel.onchange = onRouteChange;
    }

    // 填充车型下拉
    function populateVehicleSelect() {
      const sel = document.getElementById('vehicleType');
      sel.innerHTML = '<option value="">-- 请选择车型 --</option>';
      vehicleTypes.forEach(v => {
        const opt = document.createElement('option');
        opt.value = v.id;
        opt.textContent = v.name;
        sel.appendChild(opt);
      });
      // 默认选中“普通公交”
      sel.value = vehicleTypes[0].id;
    }

    function onRouteChange(e) {
      document.getElementById('loadRouteBtn').disabled = (e.target.value === '');
      document.getElementById('startGameBtn').disabled = true;
      document.getElementById('resetGameBtn').disabled = true;
      clearRoute();
      resetMetrics();
    }

    // 绑定各控件动作
    function bindGameControls() {
      // 发车间隔滑条
      const intervalSlider = document.getElementById('dispatchInterval');
      const intervalDisplay = document.getElementById('intervalDisplay');
      intervalSlider.oninput = () => {
        intervalDisplay.textContent = intervalSlider.value;
      };

      // 加载并绘制线路
      document.getElementById('loadRouteBtn').onclick = loadAndDrawRoute;
      // 开始游戏
      document.getElementById('startGameBtn').onclick = startGame;
      // 重置游戏
      document.getElementById('resetGameBtn').onclick = () => {
        clearRoute();
        resetMetrics();
        document.getElementById('startGameBtn').disabled = false;
        document.getElementById('resetGameBtn').disabled = true;
      };

      // 升级速度
      document.getElementById('btnUpgradeSpeed').onclick = () => {
        const cost = 10;
        if (currentUser.points < cost) {
          alert('积分不足，无法升级');
          return;
        }
        const newLevel = currentUser.speed_level + 1;
        const newPoints = currentUser.points - cost;
        fetch('api.php?act=update_user', {
          method:'POST',
          headers:{ 'Content-Type':'application/x-www-form-urlencoded' },
          body: `speed_level=${newLevel}&points=${newPoints}`
        })
        .then(res => res.json())
        .then(data => {
          if (data.ok) {
            currentUser.speed_level = newLevel;
            currentUser.points = newPoints;
            document.getElementById('speedLevel').textContent = newLevel;
            document.getElementById('userPoints').textContent = newPoints;
            alert('速度升级成功，当前等级：' + newLevel);
          } else {
            alert('升级失败：' + data.msg);
          }
        });
      };

      // 升级座位
      document.getElementById('btnUpgradeCapacity').onclick = () => {
        const cost = 8;
        if (currentUser.points < cost) {
          alert('积分不足，无法升级');
          return;
        }
        const newLevel = currentUser.capacity_level + 1;
        const newPoints = currentUser.points - cost;
        fetch('api.php?act=update_user', {
          method:'POST',
          headers:{ 'Content-Type':'application/x-www-form-urlencoded' },
          body: `capacity_level=${newLevel}&points=${newPoints}`
        })
        .then(res => res.json())
        .then(data => {
          if (data.ok) {
            currentUser.capacity_level = newLevel;
            currentUser.points = newPoints;
            document.getElementById('capacityLevel').textContent = newLevel;
            document.getElementById('userPoints').textContent = newPoints;
            alert('座位升级成功，当前等级：' + newLevel);
          } else {
            alert('升级失败：' + data.msg);
          }
        });
      };
    }

    // 清空地图、车辆、队列
    function clearRoute() {
      map.clearMap();
      buses.forEach(b => b.marker.setMap(null));
      buses = [];
      routeCoords = [];
      routeDists = [];
      stationIndices = [];
      stationDists = [];
      totalRouteLength = 0;
      passengerQueues = [];
      if (dispatchTimer) { clearInterval(dispatchTimer); dispatchTimer = null; }
      if (passengerGenTimer) { clearInterval(passengerGenTimer); passengerGenTimer = null; }
      gameStarted = false;
    }

    // 重置计分板
    function resetMetrics() {
      completedCount = 0;
      allPassengerWaits = [];
      updateScoreboard();
    }

    // 分段累加距离的 loadAndDrawRoute
    async function loadAndDrawRoute() {
      const routeId = document.getElementById('routeSelect').value;
      const vehicleId = document.getElementById('vehicleType').value;
      if (!routeId) return;
      if (!vehicleId) {
        alert('请选择车型');
        return;
      }

      clearRoute();

      currentRoute = routes.find(r => r.id == routeId);
      if (!currentRoute) {
        alert('找不到线路数据');
        return;
      }

      let stations, params;
      try {
        stations = JSON.parse(currentRoute.stations);
        params   = JSON.parse(currentRoute.params);
      } catch (err) {
        alert('解析线路数据失败：' + err.message);
        return;
      }
      if (!Array.isArray(stations) || stations.length < 2) {
        alert('该线路至少要有 2 个站点');
        return;
      }

      passengerQueues = stations.map(_ => []);

      routeCoords = [];
      routeDists  = [];
      let lastCumDist = 0;

      for (let i = 0; i < stations.length - 1; i++) {
        const from = stations[i].ll;
        const to   = stations[i + 1].ll;
        let segmentPath, segmentDistArray;
        try {
          const { path, distArray } = await planSegmentGetDistance(from, to);
          segmentPath = path;
          segmentDistArray = distArray;
        } catch (err) {
          console.warn(`[警告] 第 ${i} 段 Driving.search 失败，退化为直线：`, err);
          segmentPath = [from, to];
          const d = AMap.GeometryUtil.distance(
            new AMap.LngLat(from[0], from[1]),
            new AMap.LngLat(to[0], to[1])
          );
          segmentDistArray = [0, d];
        }

        if (i === 0) {
          for (let k = 0; k < segmentPath.length; k++) {
            routeCoords.push(segmentPath[k]);
            routeDists.push(lastCumDist + segmentDistArray[k]);
          }
          lastCumDist += segmentDistArray[segmentDistArray.length - 1];
        } else {
          if (segmentPath.length <= 1) {
            routeCoords.push(segmentPath[0]);
            routeDists.push(lastCumDist);
          } else {
            for (let k = 1; k < segmentPath.length; k++) {
              routeCoords.push(segmentPath[k]);
              routeDists.push(lastCumDist + segmentDistArray[k]);
            }
          }
          lastCumDist += segmentDistArray[segmentDistArray.length - 1];
        }
      }

      totalRouteLength = routeDists[routeDists.length - 1];

      // 计算每个站对应的索引与累积距离
      stationIndices = [];
      stationDists   = [];
      stations.forEach(st => {
        const [slng, slat] = st.ll;
        let bestIdx = 0, bestDistSq = Infinity;
        for (let idx = 0; idx < routeCoords.length; idx++) {
          const pt = routeCoords[idx];
          const dx = pt[0] - slng, dy = pt[1] - slat;
          const d2 = dx*dx + dy*dy;
          if (d2 < bestDistSq) {
            bestDistSq = d2;
            bestIdx = idx;
          }
        }
        stationIndices.push(bestIdx);
        stationDists.push(routeDists[bestIdx]);
      });
      {
        const zipped = stationIndices.map((idx,i) => ({ idx, dist: stationDists[i] }));
        zipped.sort((a,b) => a.idx - b.idx);
        stationIndices = zipped.map(o => o.idx);
        stationDists   = zipped.map(o => o.dist);
      }

      map.setFitView(null, false);
      document.getElementById('startGameBtn').disabled = false;
      document.getElementById('resetGameBtn').disabled = false;
      resetMetrics();
    }

    function planSegmentGetDistance(from, to) {
      return new Promise((resolve, reject) => {
        if (!driving) {
          setTimeout(() => planSegmentGetDistance(from, to).then(resolve).catch(reject), 200);
          return;
        }
        driving.search(
          new AMap.LngLat(from[0], from[1]),
          new AMap.LngLat(to[0], to[1]),
          (status, result) => {
            if (status === 'complete' && result.routes && result.routes.length > 0) {
              const r = result.routes[0];
              const fullPath = [];
              const fullDistArray = [];
              let offset = 0;

              for (let si = 0; si < r.steps.length; si++) {
                const step = r.steps[si];
                const stepPathArray = step.path.map(pt => [pt.lng, pt.lat]);

                const distArrayStep = [];
                let cumStep = 0;
                for (let pi = 0; pi < stepPathArray.length; pi++) {
                  if (pi === 0) {
                    cumStep = 0;
                    distArrayStep.push(0);
                  } else {
                    const p0 = stepPathArray[pi - 1];
                    const p1 = stepPathArray[pi];
                    const d01 = AMap.GeometryUtil.distance(
                      new AMap.LngLat(p0[0], p0[1]),
                      new AMap.LngLat(p1[0], p1[1])
                    );
                    cumStep += d01;
                    distArrayStep.push(cumStep);
                  }
                }

                if (si === 0) {
                  for (let k = 0; k < stepPathArray.length; k++) {
                    fullPath.push(stepPathArray[k]);
                    fullDistArray.push(offset + distArrayStep[k]);
                  }
                } else {
                  for (let k = 1; k < stepPathArray.length; k++) {
                    fullPath.push(stepPathArray[k]);
                    fullDistArray.push(offset + distArrayStep[k]);
                  }
                }

                offset += distArrayStep[distArrayStep.length - 1];
              }

              resolve({
                path: fullPath.slice(),
                distArray: fullDistArray.slice()
              });
            } else {
              reject(new Error(result.info || status));
            }
          }
        );
      });
    }

    function startGame() {
      if (gameStarted) return;
      gameStarted = true;

      const intervalSec = Math.max(1, parseInt(document.getElementById('dispatchInterval').value, 10));
      const vehicleId   = document.getElementById('vehicleType').value;
      const selType     = vehicleTypes.find(v => v.id === vehicleId);
      if (!selType) {
        alert('请先选择有效的车型');
        return;
      }

      // 从车型参数 + 用户升级 获取实际速度倍率 & 实际容量
      const speedMul = selType.baseSpeedMul * (1 + 0.1 * currentUser.speed_level);  // 每升级 +10% 速度
      const capacity = selType.baseCapacity * (1 + 0.1 * currentUser.capacity_level); // 每升级 +10% 容量

      // 每 10 秒生成一次乘客队列
      passengerGenTimer = setInterval(() => {
        // 假设 固定速率：10 人/分钟/站
        const defaultRate = 10;
        const genCount = Math.round(defaultRate / 6);
        const now = Date.now();
        passengerQueues.forEach(queue => {
          for (let i = 0; i < genCount; i++) queue.push(now);
        });
      }, 10000);

      let toggleDir = true;
      dispatchTimer = setInterval(() => {
        dispatchBus(toggleDir ? 'start' : 'end', speedMul, capacity);
        toggleDir = !toggleDir;
      }, intervalSec * 1000);

      // 先来一辆“去程”
      dispatchBus('start', speedMul, capacity);
      toggleDir = false;

      document.getElementById('startGameBtn').disabled = true;
      document.getElementById('resetGameBtn').disabled = false;
    }

    function dispatchBus(dir, speedMul, capacity) {
      if (!routeCoords.length || !stationIndices.length || !gameStarted) return;
      const params = JSON.parse(currentRoute.params);
      const rawSpeed = (params.speed || 20) * 1000 / 3600; // m/s
      const baseSpeed = rawSpeed * speedMul;               // 考虑车型与升级后的真实速度
      const dwellMs = (params.dwellTime || 20) * 1000;     // 停站毫秒数

      let traveledDist = 0;
      let forward = (dir === 'start');
      let nextStationIdx = forward ? 1 : stationDists.length - 2;

      // 尝试加载 1.png 作为图标
      let markerIconCfg = {};
      fetch('1.png', { method:'HEAD' })
        .then(res => {
          if (res.ok) {
            markerIconCfg.icon = new AMap.Icon({
              size: new AMap.Size(32,32),
              image: '1.png'
            });
          }
        })
        .catch(()=>{})
        .finally(() => {
          const initPt = forward ? routeCoords[0] : routeCoords[routeCoords.length - 1];
          const marker = new AMap.Marker(Object.assign({
            position: new AMap.LngLat(initPt[0], initPt[1]),
            map: map
          }, markerIconCfg));

          buses.push({
            marker,
            traveledDist,
            forward,
            baseSpeed,
            dwellTimeMs: dwellMs,
            state: 'moving',
            nextStationIdx,
            lastFrameTime: performance.now(),
            capacity: Math.floor(capacity),
            passengersOnboard: []
          });
          updateScoreboard();
        });
    }

    function updateBuses(timestamp) {
      buses.forEach((bus, idx) => {
        if (bus.state === 'moving') {
          const deltaTime = (timestamp - bus.lastFrameTime) / 1000;
          bus.lastFrameTime = timestamp;
          const moveDist = bus.baseSpeed * deltaTime;
          bus.traveledDist += moveDist;
          if (bus.traveledDist > totalRouteLength) {
            bus.traveledDist = totalRouteLength;
          }

          if (bus.traveledDist >= totalRouteLength) {
            bus.marker.setMap(null);
            buses.splice(idx, 1);
            completedCount++;
            awardPointsForTrip();
            return;
          }

          // 找当前 segIdx
          let segIdx = 0;
          while (segIdx < routeDists.length - 1 && routeDists[segIdx + 1] <= bus.traveledDist) {
            segIdx++;
          }
          if (segIdx >= routeDists.length - 1) segIdx = routeDists.length - 2;

          // 线性插值
          let distA = routeDists[segIdx];
          let distB = routeDists[segIdx + 1];
          let pA = routeCoords[segIdx];
          let pB = routeCoords[segIdx + 1];
          let t = (bus.traveledDist - distA) / (distB - distA);
          let lng = pA[0] + t * (pB[0] - pA[0]);
          let lat = pA[1] + t * (pB[1] - pA[1]);

          if (!bus.forward) {
            const dPrime = totalRouteLength - bus.traveledDist;
            let idxR = 0;
            while (idxR < routeDists.length - 1 && routeDists[idxR + 1] <= dPrime) {
              idxR++;
            }
            if (idxR >= routeDists.length - 1) idxR = routeDists.length - 2;
            const dA = routeDists[idxR];
            const dB = routeDists[idxR + 1];
            const rA = routeCoords[idxR];
            const rB = routeCoords[idxR + 1];
            const tt = (dPrime - dA) / (dB - dA);
            lng = rA[0] + tt * (rB[0] - rA[0]);
            lat = rA[1] + tt * (rB[1] - rA[1]);
          }

          bus.marker.setPosition(new AMap.LngLat(lng, lat));

          // 检测到站
          const targetDist = stationDists[bus.nextStationIdx];
          if (bus.traveledDist >= targetDist - 1e-6 && (bus.traveledDist - moveDist) < targetDist) {
            bus.state = 'dwelling';
            bus.dwellStart = timestamp;
            handlePassengerExchange(bus);
          }
        }
        else if (bus.state === 'dwelling') {
          if (timestamp - bus.dwellStart >= bus.dwellTimeMs) {
            const currentDist = bus.traveledDist;
            let idx0 = stationDists.findIndex(d => Math.abs(d - currentDist) < 1e-3);
            if (idx0 < 0) {
              for (let i = 0; i < stationDists.length; i++) {
                if (Math.abs(stationDists[i] - currentDist) < 1e-2) {
                  idx0 = i; break;
                }
              }
            }
            if (idx0 < 0) idx0 = 0;
            bus.nextStationIdx = Math.min(idx0 + 1, stationDists.length - 1);
            bus.state = 'moving';
            bus.lastFrameTime = timestamp;
          }
        }
      });

      updateScoreboard();
      requestAnimationFrame(updateBuses);
    }

    function handlePassengerExchange(bus) {
      const currentDist = bus.traveledDist;
      let stationIdxGlobal = stationDists.findIndex(d => Math.abs(d - currentDist) < 1e-3);
      if (stationIdxGlobal < 0) {
        for (let i = 0; i < stationDists.length; i++) {
          if (Math.abs(stationDists[i] - currentDist) < 1e-2) {
            stationIdxGlobal = i; break;
          }
        }
      }
      if (stationIdxGlobal < 0) return;

      const queue = passengerQueues[stationIdxGlobal];
      const now = Date.now();
      queue.forEach(arrivalTime => {
        allPassengerWaits.push(now - arrivalTime);
      });
      const onboard = Math.min(queue.length, bus.capacity);
      const overload = Math.max(queue.length - bus.capacity, 0);
      bus.passengersOnboard = [];
      queue.length = 0;
      document.getElementById('loadStatus').textContent =
        `${onboard} / ${bus.capacity}` + (overload > 0 ? ` (超载 ${overload})` : '');
    }

    function awardPointsForTrip() {
      let avgWaitSec = 0;
      if (allPassengerWaits.length > 0) {
        const sum = allPassengerWaits.reduce((a,b)=>a+b,0);
        avgWaitSec = (sum / allPassengerWaits.length) / 1000;
      }
      const bonus = avgWaitSec > 0 ? Math.max(1, Math.floor(10 / avgWaitSec)) : 1;
      const pointsGained = 5 + bonus;
      currentUser.points += pointsGained;
      document.getElementById('userPoints').textContent = currentUser.points;
      fetch('api.php?act=update_user', {
        method:'POST',
        headers:{ 'Content-Type':'application/x-www-form-urlencoded' },
        body: `points=${currentUser.points}`
      });
    }

    function updateScoreboard() {
      document.getElementById('completedTrips').textContent = completedCount;
      document.getElementById('activeBuses').textContent   = buses.length;
      if (allPassengerWaits.length > 0) {
        const sum = allPassengerWaits.reduce((a,b)=>a+b,0);
        const avgMs = sum / allPassengerWaits.length;
        document.getElementById('avgWait').textContent = (avgMs/1000).toFixed(1);
        const avgSec = avgMs/1000;
        const sat = Math.max(0, 100 - Math.floor(avgSec * 5));
        document.getElementById('satisfaction').textContent = sat + '%';
      } else {
        document.getElementById('avgWait').textContent = '0';
        document.getElementById('satisfaction').textContent = '100%';
      }
    }
  </script>
</body>
</html>