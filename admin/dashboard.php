<?php
declare(strict_types=1);

/**
 * AI Business Assistant - Admin Dashboard
 * Strictly forbidden for non-admins
 */

require_once dirname(__DIR__) . '/includes/Auth.php';

$admin = Auth::requireAdmin();
$pdo = Database::getConnection();

// Обработка быстрого переключения тех. работ
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_maintenance') {
    $token = $_POST['csrf_token'] ?? '';
    if (Security::verifyCsrf($token)) {
        $newMode = ($_POST['target_mode'] ?? '') === '1' ? '1' : '0';
        $chk = $pdo->prepare("SELECT id FROM settings WHERE setting_key = 'sys_maintenance_mode' LIMIT 1");
        $chk->execute();
        if ($chk->fetchColumn()) {
            $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'sys_maintenance_mode'")->execute([$newMode]);
        } else {
            $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('sys_maintenance_mode', ?)")->execute([$newMode]);
        }
        Security::log($pdo, 'admin_toggle_maintenance', "Режим тех. работ переключен на: {$newMode}", (int)$admin['id']);
        header("Location: /admin/dashboard.php?maint_updated=1");
        exit;
    }
}

$isMaintActive = Auth::isMaintenanceMode();
$csrfToken = Security::csrfToken();

// Ensure last_activity column exists in users table
try {
    $pdo->query("SELECT last_activity FROM users LIMIT 1");
} catch (\Throwable $e) {
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN last_activity DATETIME NULL");
    } catch (\Throwable $e2) {}
}

// AJAX: Database Connection Check
if (isset($_GET['action']) && $_GET['action'] === 'check_db') {
    header('Content-Type: application/json; charset=utf-8');
    $start = microtime(true);
    try {
        // Ping test query
        $pdo->query("SELECT 1")->fetch();
        $pingMs = round((microtime(true) - $start) * 1000, 2);

        $dbName = $pdo->query("SELECT DATABASE()")->fetchColumn() ?: 'не определена';
        $dbUser = $pdo->query("SELECT CURRENT_USER()")->fetchColumn() ?: 'не определен';
        $dbVersion = $pdo->query("SELECT VERSION()")->fetchColumn() ?: 'не определена';
        $dbCharset = $pdo->query("SELECT @@character_set_database")->fetchColumn() ?: 'utf8mb4';
        $dbCollation = $pdo->query("SELECT @@collation_database")->fetchColumn() ?: 'utf8mb4_unicode_ci';

        // Size & tables count
        $sizeStmt = $pdo->prepare("
            SELECT COUNT(*) AS table_count, 
                   COALESCE(SUM(data_length + index_length), 0) AS size_bytes
            FROM information_schema.TABLES
            WHERE table_schema = ?
        ");
        $sizeStmt->execute([$dbName]);
        $sizeRow = $sizeStmt->fetch(PDO::FETCH_ASSOC);
        $tableCount = (int)($sizeRow['table_count'] ?? 0);
        $sizeMb = round(((int)($sizeRow['size_bytes'] ?? 0)) / (1024 * 1024), 2);

        // Uptime & threads
        $uptime = 'N/A';
        $threads = '1';
        try {
            $status = $pdo->query("SHOW STATUS WHERE Variable_name IN ('Uptime', 'Threads_connected')")->fetchAll(PDO::FETCH_KEY_PAIR);
            if (!empty($status['Uptime'])) {
                $secs = (int)$status['Uptime'];
                $days = floor($secs / 86400);
                $hours = floor(($secs % 86400) / 3600);
                $mins = floor(($secs % 3600) / 60);
                $uptime = "{$days}д {$hours}ч {$mins}м";
            }
            if (!empty($status['Threads_connected'])) {
                $threads = $status['Threads_connected'];
            }
        } catch (\Throwable $e) {}

        echo json_encode([
            'success' => true,
            'ping_ms' => $pingMs,
            'database' => $dbName,
            'user' => $dbUser,
            'version' => $dbVersion,
            'charset' => $dbCharset,
            'collation' => $dbCollation,
            'tables_count' => $tableCount,
            'size_mb' => $sizeMb,
            'uptime' => $uptime,
            'threads' => $threads,
            'message' => 'Соединение с базой данных стабильно, транзакции и запросы выполняются штатно!'
        ], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Moscow Timezone boundary for today
$tzMsk = new DateTimeZone('Europe/Moscow');
$todayMskStart = (new DateTime('today', $tzMsk))->format('Y-m-d H:i:s');

// 1. Metrics: Total users, active subscriptions, total revenue, AI queries count
$totalUsers = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$activeSubs = (int)$pdo->query("SELECT COUNT(*) FROM subscriptions WHERE status = 'active' AND end_date >= NOW()")->fetchColumn();
$totalSubscriptions = (int)$pdo->query("SELECT COUNT(*) FROM subscriptions")->fetchColumn();

$totalRevenue = (float)$pdo->query("
    SELECT COALESCE(SUM(t.price), 0) 
    FROM subscriptions s 
    JOIN tariffs t ON s.tariff_id = t.id
")->fetchColumn() ?: 0.0;
$totalAiQueries = (int)$pdo->query("SELECT COUNT(*) FROM ai_history")->fetchColumn();

// 2. People Online (active in last 15 minutes)
$onlineUsers = $pdo->query("
    SELECT u.id, u.first_name, u.last_name, u.email, u.last_activity, r.name AS role_name, r.description AS role_desc
    FROM users u
    JOIN roles r ON u.role_id = r.id
    WHERE u.last_activity >= NOW() - INTERVAL 15 MINUTE
    ORDER BY u.last_activity DESC
    LIMIT 15
")->fetchAll();
$onlineCount = count($onlineUsers);
// Fallback: admin is online right now
if ($onlineCount === 0 && !empty($admin)) {
    $onlineCount = 1;
    $onlineUsers = [[
        'id' => $admin['id'],
        'first_name' => $admin['first_name'],
        'last_name' => $admin['last_name'],
        'email' => $admin['email'],
        'last_activity' => date('Y-m-d H:i:s'),
        'role_name' => $admin['role_name'] ?? 'admin',
        'role_desc' => 'Администратор'
    ]];
}

// 3. Activity Today (MSK)
// AI Queries today
$aiTodayStmt = $pdo->prepare("SELECT COUNT(*) FROM ai_history WHERE created_at >= ?");
$aiTodayStmt->execute([$todayMskStart]);
$aiToday = (int)$aiTodayStmt->fetchColumn();

// Chat messages today
$chatToday = 0;
try {
    $chatTodayStmt = $pdo->prepare("SELECT COUNT(*) FROM team_chat_messages WHERE created_at >= ?");
    $chatTodayStmt->execute([$todayMskStart]);
    $chatToday = (int)$chatTodayStmt->fetchColumn();
} catch (\Throwable $e) {}

// Logins today
$loginsToday = 0;
try {
    $loginsTodayStmt = $pdo->prepare("SELECT COUNT(*) FROM logs WHERE event_type LIKE '%login%' AND created_at >= ?");
    $loginsTodayStmt->execute([$todayMskStart]);
    $loginsToday = (int)$loginsTodayStmt->fetchColumn();
} catch (\Throwable $e) {}

// Subscriptions today
$subsTodayStmt = $pdo->prepare("SELECT COUNT(*) FROM subscriptions WHERE created_at >= ?");
$subsTodayStmt->execute([$todayMskStart]);
$subsToday = (int)$subsTodayStmt->fetchColumn();

// Total platform activities today
$totalActivityToday = $aiToday + $chatToday + $loginsToday + $subsToday;

// 4. Packages / Tariffs Breakdown
$tariffStats = $pdo->query("
    SELECT t.id, t.name, t.code, t.price, t.duration_days,
           COUNT(s.id) AS total_count,
           SUM(CASE WHEN s.status = 'active' AND s.end_date >= NOW() THEN 1 ELSE 0 END) AS active_count,
           COALESCE(SUM(t.price), 0) AS total_revenue
    FROM tariffs t
    LEFT JOIN subscriptions s ON t.id = s.tariff_id
    GROUP BY t.id, t.name, t.code, t.price, t.duration_days
    ORDER BY total_count DESC, t.price DESC
")->fetchAll();

// Keys generated & used
$totalKeys = 0;
$usedKeys = 0;
try {
    $totalKeys = (int)$pdo->query("SELECT COUNT(*) FROM subscription_keys")->fetchColumn();
    $usedKeys = (int)$pdo->query("SELECT COUNT(*) FROM subscription_keys WHERE status = 'used'")->fetchColumn();
} catch (\Throwable $e) {}

// 5. Recent subscriptions
$recentSubs = $pdo->query("
    SELECT s.*, u.email, u.first_name, u.last_name, t.name as tariff_name, t.price
    FROM subscriptions s
    JOIN users u ON s.user_id = u.id
    JOIN tariffs t ON s.tariff_id = t.id
    ORDER BY s.id DESC
    LIMIT 6
")->fetchAll();

$pageTitle = 'Админ Панель — Дашборд | AI Business Assistant';
require dirname(__DIR__) . '/includes/header.php';
?>

<div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px; margin-bottom:24px;">
    <div>
        <h2 style="display:flex; align-items:center; gap:12px;">
            <i class="fas fa-chart-line text-primary"></i> Панель администратора
        </h2>
        <p class="text-muted" style="margin:0;">
            Мониторинг сервера, статистика активности пользователей, подписок и управление AI-платформой
        </p>
    </div>
    <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <button id="btnCheckDb" class="btn btn-primary" onclick="checkDbConnection()" style="box-shadow:0 4px 14px rgba(59,130,246,0.35);">
            <i class="fas fa-database"></i> Проверить подключение к БД
        </button>
        <button class="btn btn-secondary" onclick="window.location.reload();">
            <i class="fas fa-sync-alt"></i> Обновить данные
        </button>
    </div>
</div>

<!-- Панель быстрого управления Тех. Работами -->
<div class="card" style="margin-bottom:24px; padding:18px 24px; background:<?= $isMaintActive ? 'linear-gradient(135deg, rgba(239,68,68,0.15), rgba(185,28,28,0.08))' : 'var(--bg-card)' ?>; border:1px solid <?= $isMaintActive ? 'rgba(239,68,68,0.5)' : 'var(--border-color)' ?>; border-radius:var(--radius-lg); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
    <div style="display:flex; align-items:center; gap:14px;">
        <div style="width:42px; height:42px; border-radius:12px; background:<?= $isMaintActive ? 'rgba(239,68,68,0.2)' : 'rgba(245,158,11,0.15)' ?>; display:flex; align-items:center; justify-content:center; font-size:20px;">
            <i class="fas fa-tools" style="color:<?= $isMaintActive ? '#ef4444' : '#f59e0b' ?>;"></i>
        </div>
        <div>
            <div style="display:flex; align-items:center; gap:8px;">
                <strong style="font-size:15px; color:var(--text-primary);">Режим технического обслуживания (ТО)</strong>
                <?php if ($isMaintActive): ?>
                    <span class="badge" style="background:#ef4444; color:#fff; font-size:10px; font-weight:800; padding:2px 8px; text-transform:uppercase;">Включен</span>
                <?php else: ?>
                    <span class="badge" style="background:rgba(16,185,129,0.2); color:#10b981; font-size:10px; font-weight:800; padding:2px 8px; text-transform:uppercase;">Выключен (Штатно)</span>
                <?php endif; ?>
            </div>
            <div style="font-size:12px; color:var(--text-muted); margin-top:2px;">
                <?= $isMaintActive 
                    ? 'Доступ обычным клиентам ограничен. Открыты только тикеты поддержки и вручную разрешенные разделы.' 
                    : 'Сайт открыт для всех пользователей без ограничений.' ?>
            </div>
        </div>
    </div>

    <div style="display:flex; align-items:center; gap:10px;">
        <a href="/owner/dashboard.php" class="btn btn-secondary btn-sm" title="Настроить доступ к разделам вручную">
            <i class="fas fa-sliders-h"></i> Ручные разделы
        </a>
        <form method="POST" action="/admin/dashboard.php" style="margin:0;" onsubmit="return confirm('<?= $isMaintActive ? 'Вы уверены, что хотите ВЫКЛЮЧИТЬ тех. работы и открыть сайт всем клиентам?' : 'Вы уверены, что хотите ВКЛЮЧИТЬ режим тех. работ?' ?>');">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" value="toggle_maintenance">
            <input type="hidden" name="target_mode" value="<?= $isMaintActive ? '0' : '1' ?>">
            <?php if ($isMaintActive): ?>
                <button type="submit" class="btn" style="background:#10b981; color:#fff; font-weight:700; border:none; padding:8px 18px; border-radius:10px; cursor:pointer;">
                    <i class="fas fa-power-off"></i> Выключить ТО
                </button>
            <?php else: ?>
                <button type="submit" class="btn btn-warning" style="font-weight:700; padding:8px 18px; border-radius:10px; cursor:pointer;">
                    <i class="fas fa-tools"></i> Включить ТО
                </button>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- DB Status Alert Card (Dynamic) -->
<div id="dbStatusCard" style="display:none; margin-bottom:24px; background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:20px; box-shadow:0 8px 24px rgba(0,0,0,0.3); transition:all 0.3s ease;">
    <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid var(--border-color); padding-bottom:12px; margin-bottom:16px;">
        <h4 style="margin:0; display:flex; align-items:center; gap:10px;" id="dbStatusTitle">
            <i class="fas fa-spinner fa-spin text-primary"></i> Тестирование подключения к MySQL...
        </h4>
        <button type="button" class="btn btn-sm btn-secondary" onclick="document.getElementById('dbStatusCard').style.display='none';">
            <i class="fas fa-times"></i>
        </button>
    </div>
    <div id="dbStatusBody">
        <p class="text-muted">Отправка проверочного пакета к базе данных...</p>
    </div>
</div>

<!-- Primary Stats Grid -->
<div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); margin-bottom:24px;">
    <!-- Total Users -->
    <div class="stat-card">
        <div class="stat-icon"><i class="fas fa-users"></i></div>
        <div>
            <div class="stat-title">Всего пользователей</div>
            <div class="stat-value"><?= number_format($totalUsers, 0, '.', ' ') ?></div>
            <small class="text-muted"><a href="/admin/users.php" style="color:var(--primary);">Управление &rarr;</a></small>
        </div>
    </div>

    <!-- Online Users -->
    <div class="stat-card" style="border-left:3px solid #10b981;">
        <div class="stat-icon" style="background:rgba(16,185,129,0.15); color:#10b981;">
            <i class="fas fa-signal"></i>
        </div>
        <div>
            <div class="stat-title" style="display:flex; align-items:center; gap:6px;">
                <span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:#10b981; box-shadow:0 0 8px #10b981;"></span>
                Людей на сайте сейчас
            </div>
            <div class="stat-value text-success"><?= $onlineCount ?></div>
            <small class="text-muted">Активность за 15 минут</small>
        </div>
    </div>

    <!-- Site Activity Today -->
    <div class="stat-card" style="border-left:3px solid #f59e0b;">
        <div class="stat-icon" style="background:rgba(245,158,11,0.15); color:#f59e0b;">
            <i class="fas fa-bolt"></i>
        </div>
        <div>
            <div class="stat-title">Активность за сегодня</div>
            <div class="stat-value" style="color:#f59e0b;"><?= number_format($totalActivityToday, 0, '.', ' ') ?></div>
            <small class="text-muted">Событий с 00:00 МСК</small>
        </div>
    </div>

    <!-- Total Packages / Subscriptions -->
    <div class="stat-card" style="border-left:3px solid #8b5cf6;">
        <div class="stat-icon" style="background:rgba(139,92,246,0.15); color:#8b5cf6;">
            <i class="fas fa-box-open"></i>
        </div>
        <div>
            <div class="stat-title">Оформлено пакетов</div>
            <div class="stat-value" style="color:#a78bfa;"><?= number_format($totalSubscriptions, 0, '.', ' ') ?></div>
            <small class="text-muted">Активных: <strong><?= $activeSubs ?></strong></small>
        </div>
    </div>

    <!-- Revenue -->
    <div class="stat-card">
        <div class="stat-icon"><i class="fas fa-coins"></i></div>
        <div>
            <div class="stat-title">Общий оборот / Доход</div>
            <div class="stat-value"><?= number_format($totalRevenue, 0, '.', ' ') ?> ₸</div>
            <small class="text-muted"><a href="/admin/tariffs.php" style="color:var(--primary);">Тарифы &rarr;</a></small>
        </div>
    </div>

    <!-- AI Generations -->
    <div class="stat-card">
        <div class="stat-icon"><i class="fas fa-robot"></i></div>
        <div>
            <div class="stat-title">AI генераций всего</div>
            <div class="stat-value text-primary"><?= number_format($totalAiQueries, 0, '.', ' ') ?></div>
            <small class="text-muted">Сегодня: +<?= $aiToday ?></small>
        </div>
    </div>
</div>

<!-- Main 2-Column Content Layout -->
<div style="display:grid; grid-template-columns: 2fr 1fr; gap:24px; margin-bottom:28px;">
    <!-- LEFT COLUMN -->
    <div style="display:flex; flex-direction:column; gap:24px;">
        <!-- Packages / Tariffs Breakdown -->
        <div class="card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:24px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
                <h3 style="margin:0; display:flex; align-items:center; gap:10px;">
                    <i class="fas fa-tags text-primary"></i> Сколько пакетов было оформлено (по тарифам)
                </h3>
                <a href="/admin/subscriptions.php" class="btn btn-sm btn-secondary">Все подписки &rarr;</a>
            </div>

            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Пакет / Тариф</th>
                            <th>Стоимость</th>
                            <th>Срок</th>
                            <th class="text-center">Всего оформлено</th>
                            <th class="text-center">Активно сейчас</th>
                            <th class="text-right">Сумма оборота</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($tariffStats)): ?>
                            <tr><td colspan="6" class="text-center text-muted">Тарифы не найдены</td></tr>
                        <?php else: ?>
                            <?php foreach ($tariffStats as $ts): 
                                $percent = $totalSubscriptions > 0 ? round(($ts['total_count'] / $totalSubscriptions) * 100, 1) : 0;
                            ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($ts['name']) ?></strong>
                                        <br><small class="text-muted">Код: <code><?= htmlspecialchars($ts['code']) ?></code></small>
                                    </td>
                                    <td><?= number_format((float)$ts['price'], 0, '.', ' ') ?> ₸</td>
                                    <td><?= (int)$ts['duration_days'] ?> дн.</td>
                                    <td class="text-center">
                                        <span class="badge" style="background:rgba(59,130,246,0.2); color:#60a5fa; font-size:13px; font-weight:600;">
                                            <?= $ts['total_count'] ?> шт. (<?= $percent ?>%)
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge badge-success">
                                            <?= $ts['active_count'] ?>
                                        </span>
                                    </td>
                                    <td class="text-right" style="font-weight:600;">
                                        <?= number_format((float)$ts['total_revenue'], 0, '.', ' ') ?> ₸
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div style="display:flex; justify-content:space-between; align-items:center; margin-top:16px; padding-top:16px; border-top:1px solid var(--border-color); flex-wrap:wrap; gap:12px;">
                <div style="display:flex; gap:20px; flex-wrap:wrap;">
                    <div><span class="text-muted">Всего покупок пакетов:</span> <strong><?= $totalSubscriptions ?></strong></div>
                    <div><span class="text-muted">Куплено за сегодня:</span> <strong class="text-success">+<?= $subsToday ?></strong></div>
                    <div><span class="text-muted">Активировано промокодов/ключей:</span> <strong><?= $usedKeys ?> из <?= $totalKeys ?></strong></div>
                </div>
                <a href="/admin/subscription_keys.php" class="btn btn-sm btn-secondary">
                    <i class="fas fa-key"></i> Ключи подписок
                </a>
            </div>
        </div>

        <!-- Recent Subscriptions -->
        <div class="card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:24px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
                <h3 style="margin:0; display:flex; align-items:center; gap:10px;">
                    <i class="fas fa-receipt text-primary"></i> Последние оформленные пакеты
                </h3>
                <a href="/admin/subscriptions.php" class="btn btn-sm btn-secondary">Подробнее &rarr;</a>
            </div>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Пользователь</th>
                            <th>Тариф</th>
                            <th>Сумма</th>
                            <th>Окончание</th>
                            <th>Статус</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentSubs)): ?>
                            <tr><td colspan="5" class="text-center text-muted">Пока нет покупок подписок</td></tr>
                        <?php else: ?>
                            <?php foreach ($recentSubs as $rs): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($rs['first_name'] . ' ' . $rs['last_name']) ?></strong><br>
                                        <small class="text-muted"><?= htmlspecialchars($rs['email']) ?></small>
                                    </td>
                                    <td><span class="badge" style="background:rgba(255,255,255,0.08);"><?= htmlspecialchars($rs['tariff_name']) ?></span></td>
                                    <td><strong><?= number_format((float)$rs['price'], 0, '.', ' ') ?> ₸</strong></td>
                                    <td><small><?= date('d.m.Y', strtotime($rs['end_date'])) ?></small></td>
                                    <td>
                                        <span class="badge <?= $rs['status'] === 'active' ? 'badge-success' : 'badge-danger' ?>">
                                            <?= $rs['status'] ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- RIGHT COLUMN -->
    <div style="display:flex; flex-direction:column; gap:24px;">
        <!-- Online Users Card -->
        <div class="card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:24px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                <h3 style="margin:0; font-size:16px; display:flex; align-items:center; gap:8px;">
                    <span style="display:inline-block; width:10px; height:10px; border-radius:50%; background:#10b981; box-shadow:0 0 10px #10b981;"></span>
                    Люди на сайте онлайн
                </h3>
                <span class="badge badge-success"><?= $onlineCount ?> онлайн</span>
            </div>
            
            <div style="display:flex; flex-direction:column; gap:10px; max-height:280px; overflow-y:auto;">
                <?php if (empty($onlineUsers)): ?>
                    <p class="text-muted text-center" style="margin:12px 0;">Сейчас активных пользователей нет</p>
                <?php else: ?>
                    <?php foreach ($onlineUsers as $ou): 
                        $initials = mb_substr($ou['first_name'], 0, 1) . mb_substr($ou['last_name'], 0, 1);
                        $timeAgo = 'только что';
                        if (!empty($ou['last_activity'])) {
                            $diffSec = time() - strtotime($ou['last_activity']);
                            if ($diffSec > 60) {
                                $diffMin = floor($diffSec / 60);
                                $timeAgo = "{$diffMin} мин. назад";
                            }
                        }
                    ?>
                        <div style="display:flex; align-items:center; justify-content:space-between; padding:8px 12px; background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.05); border-radius:8px;">
                            <div style="display:flex; align-items:center; gap:10px;">
                                <div style="width:34px; height:34px; border-radius:50%; background:linear-gradient(135deg, #3b82f6, #8b5cf6); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:bold; font-size:12px;">
                                    <?= htmlspecialchars($initials ?: 'U') ?>
                                </div>
                                <div>
                                    <div style="font-weight:600; font-size:13px;"><?= htmlspecialchars($ou['first_name'] . ' ' . $ou['last_name']) ?></div>
                                    <div style="font-size:11px;" class="text-muted"><?= htmlspecialchars($ou['email']) ?></div>
                                </div>
                            </div>
                            <div style="text-align:right;">
                                <span class="badge" style="font-size:10px; background:rgba(59,130,246,0.15); color:#60a5fa;"><?= htmlspecialchars($ou['role_name']) ?></span>
                                <div style="font-size:10px; color:#10b981; margin-top:2px;"><?= $timeAgo ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Site Activity Breakdown Today -->
        <div class="card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:24px;">
            <h3 style="margin:0 0 16px 0; font-size:16px; display:flex; align-items:center; gap:8px;">
                <i class="fas fa-chart-pie text-warning"></i> Активность сайта сегодня (МСК)
            </h3>

            <div style="display:flex; flex-direction:column; gap:12px;">
                <div style="display:flex; justify-content:space-between; align-items:center; padding:8px 12px; background:rgba(255,255,255,0.03); border-radius:8px;">
                    <div style="display:flex; align-items:center; gap:10px;">
                        <i class="fas fa-robot text-primary" style="width:16px;"></i>
                        <span>AI генераций:</span>
                    </div>
                    <strong style="color:var(--primary); font-size:15px;"><?= $aiToday ?></strong>
                </div>

                <div style="display:flex; justify-content:space-between; align-items:center; padding:8px 12px; background:rgba(255,255,255,0.03); border-radius:8px;">
                    <div style="display:flex; align-items:center; gap:10px;">
                        <i class="fas fa-comments text-info" style="width:16px;"></i>
                        <span>Сообщений в чатах:</span>
                    </div>
                    <strong style="color:#38bdf8; font-size:15px;"><?= $chatToday ?></strong>
                </div>

                <div style="display:flex; justify-content:space-between; align-items:center; padding:8px 12px; background:rgba(255,255,255,0.03); border-radius:8px;">
                    <div style="display:flex; align-items:center; gap:10px;">
                        <i class="fas fa-sign-in-alt text-success" style="width:16px;"></i>
                        <span>Входов в систему:</span>
                    </div>
                    <strong style="color:#10b981; font-size:15px;"><?= $loginsToday ?></strong>
                </div>

                <div style="display:flex; justify-content:space-between; align-items:center; padding:8px 12px; background:rgba(255,255,255,0.03); border-radius:8px;">
                    <div style="display:flex; align-items:center; gap:10px;">
                        <i class="fas fa-shopping-bag text-warning" style="width:16px;"></i>
                        <span>Оформлено пакетов:</span>
                    </div>
                    <strong style="color:#f59e0b; font-size:15px;"><?= $subsToday ?></strong>
                </div>
            </div>
        </div>

        <!-- Quick Admin Links -->
        <div class="card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:24px;">
            <h3 style="margin:0 0 16px 0; font-size:16px;"><i class="fas fa-cogs text-warning"></i> Разделы управления</h3>
            <div style="display:flex; flex-direction:column; gap:10px;">
                <a href="/admin/users.php" class="btn btn-secondary" style="justify-content:flex-start;">
                    <i class="fas fa-users-cog"></i> Пользователи & Блокировки
                </a>
                <a href="/admin/tariffs.php" class="btn btn-secondary" style="justify-content:flex-start;">
                    <i class="fas fa-tags"></i> Тарифные планы & Лимиты
                </a>
                <a href="/admin/subscriptions.php" class="btn btn-secondary" style="justify-content:flex-start;">
                    <i class="fas fa-receipt"></i> Управление подписками
                </a>
                <a href="/admin/subscription_keys.php" class="btn btn-secondary" style="justify-content:flex-start;">
                    <i class="fas fa-key"></i> Генератор ключей тарифов
                </a>
                <a href="/admin/ai_settings.php" class="btn btn-secondary" style="justify-content:flex-start;">
                    <i class="fas fa-brain"></i> Подключение API нейросети
                </a>
                <a href="/admin/test_smtp.php" class="btn btn-secondary" style="justify-content:flex-start;">
                    <i class="fas fa-envelope"></i> Диагностика почты (SMTP)
                </a>
                <a href="/admin/logs.php" class="btn btn-secondary" style="justify-content:flex-start;">
                    <i class="fas fa-shield-alt"></i> Журнал аудита & Входы
                </a>
            </div>
        </div>
    </div>
</div>

<script>
function checkDbConnection() {
    const card = document.getElementById('dbStatusCard');
    const title = document.getElementById('dbStatusTitle');
    const body = document.getElementById('dbStatusBody');
    const btn = document.getElementById('btnCheckDb');

    card.style.display = 'block';
    card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    btn.disabled = true;
    title.innerHTML = '<i class="fas fa-spinner fa-spin text-primary"></i> Проверка подключения к MySQL/БД...';
    body.innerHTML = '<div style="padding:12px; color:var(--text-muted);"><i class="fas fa-spinner fa-spin"></i> Выполняется тестовый запрос к базе данных...</div>';

    fetch('/admin/dashboard.php?action=check_db')
        .then(response => response.json())
        .then(data => {
            btn.disabled = false;
            if (data.success) {
                title.innerHTML = '<span style="color:#10b981;"><i class="fas fa-check-circle"></i> Подключение к базе данных успешно!</span>';
                body.innerHTML = `
                    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:16px; margin-top:8px;">
                        <div style="background:rgba(255,255,255,0.03); padding:12px; border-radius:8px; border:1px solid rgba(255,255,255,0.06);">
                            <div class="text-muted" style="font-size:12px;"><i class="fas fa-tachometer-alt"></i> Пинг (отклик):</div>
                            <div style="font-size:18px; font-weight:700; color:#10b981;">${data.ping_ms} мс</div>
                            <small class="text-muted">Очень быстрый отклик</small>
                        </div>
                        <div style="background:rgba(255,255,255,0.03); padding:12px; border-radius:8px; border:1px solid rgba(255,255,255,0.06);">
                            <div class="text-muted" style="font-size:12px;"><i class="fas fa-database"></i> База данных:</div>
                            <div style="font-size:15px; font-weight:700; color:#60a5fa;">${data.database}</div>
                            <small class="text-muted">Пользователь: ${data.user}</small>
                        </div>
                        <div style="background:rgba(255,255,255,0.03); padding:12px; border-radius:8px; border:1px solid rgba(255,255,255,0.06);">
                            <div class="text-muted" style="font-size:12px;"><i class="fas fa-server"></i> Версия СУБД:</div>
                            <div style="font-size:14px; font-weight:600;">${data.version}</div>
                            <small class="text-muted">Кодировка: ${data.charset}</small>
                        </div>
                        <div style="background:rgba(255,255,255,0.03); padding:12px; border-radius:8px; border:1px solid rgba(255,255,255,0.06);">
                            <div class="text-muted" style="font-size:12px;"><i class="fas fa-table"></i> Таблицы и объем:</div>
                            <div style="font-size:16px; font-weight:700; color:#a78bfa;">${data.tables_count} таблиц</div>
                            <small class="text-muted">Размер базы: ${data.size_mb} МБ</small>
                        </div>
                        <div style="background:rgba(255,255,255,0.03); padding:12px; border-radius:8px; border:1px solid rgba(255,255,255,0.06);">
                            <div class="text-muted" style="font-size:12px;"><i class="fas fa-clock"></i> Аптайм сервера:</div>
                            <div style="font-size:14px; font-weight:600;">${data.uptime}</div>
                            <small class="text-muted">Активных потоков: ${data.threads}</small>
                        </div>
                    </div>
                    <div style="margin-top:14px; padding:10px 14px; background:rgba(16,185,129,0.1); border-left:3px solid #10b981; border-radius:6px; font-size:13px; color:#6ee7b7;">
                        <i class="fas fa-check"></i> ${data.message}
                    </div>
                `;
            } else {
                title.innerHTML = '<span style="color:#ef4444;"><i class="fas fa-exclamation-triangle"></i> Ошибка подключения к базе данных!</span>';
                body.innerHTML = `
                    <div style="padding:14px; background:rgba(239,68,68,0.1); border-left:3px solid #ef4444; border-radius:6px; color:#fca5a5;">
                        <strong>Детали ошибки:</strong><br>
                        <code>${data.error || 'Неизвестная ошибка связи с MySQL'}</code>
                    </div>
                `;
            }
        })
        .catch(err => {
            btn.disabled = false;
            title.innerHTML = '<span style="color:#ef4444;"><i class="fas fa-exclamation-triangle"></i> Сбой сети при запросе</span>';
            body.innerHTML = `<div style="padding:12px; color:#fca5a5;">Не удалось связаться с сервером: ${err.message}</div>`;
        });
}
</script>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
