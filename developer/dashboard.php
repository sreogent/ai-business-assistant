<?php
declare(strict_types=1);

/**
 * AI Business Assistant - Консоль Разработчика (Developer Console)
 * Инженерная панель: SQL Runner, Оптимизация БД, Диагностика PHP/сервера,
 * Мониторинг API-эндпоинтов, Баг-трекинг и системный аудит.
 */

require_once dirname(__DIR__) . '/includes/Auth.php';

$user = Auth::requireRole(['developer', 'vrio_owner', 'admin']);
$userId = (int)$user['id'];
$pdo = Database::getConnection();

$error = null;
$success = null;
$sqlResults = null;
$sqlExecTime = null;
$sqlError = null;
$pingResults = null;

// Обработка действий разработчика
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!Security::verifyCsrf($token)) {
        $error = "Истёк токен безопасности. Повторите попытку.";
    } else {
        $action = $_POST['action'] ?? '';

        // 1. Выполнение безопасного SQL-запроса
        if ($action === 'run_sql') {
            $query = trim($_POST['sql_query'] ?? '');
            $cleanQuery = rtrim(trim($query), "; \t\n\r");
            if (empty($cleanQuery)) {
                $sqlError = "Введите SQL-запрос.";
            } else {
                // Разрешаем SELECT, SHOW, DESCRIBE, EXPLAIN
                $firstWord = strtoupper(strtok($cleanQuery, " \t\n\r"));
                if (!in_array($firstWord, ['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN'], true)) {
                    $sqlError = "В целях безопасности через консоль разрешены только запросы на чтение (SELECT, SHOW, DESCRIBE, EXPLAIN).";
                } else {
                    $t0 = microtime(true);
                    try {
                        $stmt = $pdo->query($cleanQuery);
                        $sqlResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        $sqlExecTime = round((microtime(true) - $t0) * 1000, 2);
                        Security::log($pdo, 'developer_sql_query', "Разработчик выполнил SQL: " . mb_substr($cleanQuery, 0, 100), $userId);
                    } catch (\Throwable $e) {
                        $sqlError = $e->getMessage();
                    }
                }
            }
        }

        // 2. Оптимизация и проверка таблиц MySQL
        if ($action === 'db_optimize_tables') {
            try {
                $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
                $optCount = 0;
                foreach ($tables as $t) {
                    $pdo->query("OPTIMIZE TABLE `{$t}`");
                    $optCount++;
                }
                Security::log($pdo, 'developer_db_optimize', "Разработчик выполнил оптимизацию {$optCount} таблиц", $userId);
                $success = "⚡ Успешно оптимизировано {$optCount} таблиц базы данных! Таблицы дефрагментированы, индексы обновлены.";
            } catch (\Throwable $e) {
                $error = "Ошибка оптимизации: " . $e->getMessage();
            }
        }

        // 3. Очистка устаревших системных логов (>30 дней)
        if ($action === 'db_clean_old_logs') {
            try {
                $stmt = $pdo->prepare("DELETE FROM logs WHERE created_at < NOW() - INTERVAL 30 DAY");
                $stmt->execute();
                $deleted = $stmt->rowCount();
                Security::log($pdo, 'developer_db_clean_logs', "Разработчик очистил {$deleted} старых логов", $userId);
                $success = "🧹 Очищено устаревших записей журнала аудита: {$deleted}.";
            } catch (\Throwable $e) {
                $error = "Ошибка очистки: " . $e->getMessage();
            }
        }

        // 4. Пинг системных эндпоинтов (Health Check)
        if ($action === 'ping_endpoints') {
            $endpoints = [
                ['name' => 'Личные сообщения', 'url' => '/direct_messages.php?action=get_conversations'],
                ['name' => 'AI-сервис', 'url' => '/user/ai_assistant.php'],
                ['name' => 'Чат-система', 'url' => '/chat.php?action=get_channels'],
                ['name' => 'Голосовые заметки API', 'url' => '/api/voice_message.php'],
                ['name' => 'Голосовые звонки API', 'url' => '/api/chat_call.php?action=check_incoming'],
            ];

            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
            $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
            $pingResults = [];

            foreach ($endpoints as $ep) {
                $fullUrl = $protocol . $host . $ep['url'];
                $t0 = microtime(true);
                $status = 0;
                $errStr = null;

                $ch = curl_init($fullUrl);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 4,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_COOKIE => session_name() . '=' . session_id(),
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => false,
                ]);
                $body = curl_exec($ch);
                if (curl_errno($ch)) {
                    $errStr = curl_error($ch);
                } else {
                    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                }
                curl_close($ch);
                $ms = round((microtime(true) - $t0) * 1000, 1);

                $pingResults[] = [
                    'name' => $ep['name'],
                    'path' => $ep['url'],
                    'status' => $status,
                    'latency_ms' => $ms,
                    'error' => $errStr,
                    'ok' => ($status >= 200 && $status < 400)
                ];
            }
            $success = "📡 Диагностика эндпоинтов завершена (" . count($pingResults) . " проверено).";
        }

        // 5. Смена статуса бага тестировщика
        if ($action === 'update_bug_status') {
            $bugId = (int)($_POST['bug_id'] ?? 0);
            $newStatus = trim($_POST['status'] ?? 'in_progress');
            $devComment = trim($_POST['developer_comment'] ?? '');

            $valid = ['new', 'in_progress', 'fixed', 'rejected'];
            if ($bugId > 0 && in_array($newStatus, $valid, true)) {
                $pdo->prepare("
                    UPDATE bug_reports 
                    SET status = ?, developer_comment = ? 
                    WHERE id = ?
                ")->execute([$newStatus, $devComment, $bugId]);

                Security::log($pdo, 'developer_update_bug', "Разработчик обновил баг #{$bugId} на статус: {$newStatus}", $userId);
                $success = "Статус баг-репорта #{$bugId} обновлен на «{$newStatus}».";
            } else {
                $error = "Некорректный идентификатор или статус отчета.";
            }
        }
    }
}

// Технические показатели системы
$phpVersion = PHP_VERSION;
$serverSoftware = $_SERVER['SERVER_SOFTWARE'] ?? 'PHP Server';
$memoryUsage = round(memory_get_usage(true) / 1024 / 1024, 2) . ' MB';
$memoryLimit = ini_get('memory_limit') ?: 'N/A';
$maxExecTime = ini_get('max_execution_time') ?: 'N/A';
$uploadMax = ini_get('upload_max_filesize') ?: 'N/A';
$postMax = ini_get('post_max_size') ?: 'N/A';

// MySQL версия и статистика
$mysqlVersion = $pdo->query("SELECT VERSION()")->fetchColumn() ?: 'Unknown';
$dbStats = $pdo->query("
    SELECT 
        COUNT(table_name) AS tables_count,
        ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS db_size_mb 
    FROM information_schema.TABLES 
    WHERE table_schema = DATABASE()
")->fetch();

// Список таблиц с размером и количеством строк
$tablesInfoStmt = $pdo->query("
    SELECT 
        table_name,
        engine,
        table_rows,
        ROUND((data_length + index_length) / 1024, 1) AS size_kb
    FROM information_schema.TABLES 
    WHERE table_schema = DATABASE()
    ORDER BY (data_length + index_length) DESC
    LIMIT 12
");
$tablesInfo = $tablesInfoStmt->fetchAll();

// Баг-репорты от тестировщиков
$bugsStmt = $pdo->query("
    SELECT b.*, u.first_name, u.last_name, u.email 
    FROM bug_reports b
    LEFT JOIN users u ON b.tester_id = u.id
    ORDER BY 
        CASE 
            WHEN b.status = 'new' THEN 1
            WHEN b.status = 'in_progress' THEN 2
            WHEN b.status = 'fixed' THEN 3
            ELSE 4
        END ASC, b.id DESC
");
$bugReports = $bugsStmt->fetchAll();

// Системные логи аудита
$logsStmt = $pdo->query("
    SELECT l.*, u.email 
    FROM logs l 
    LEFT JOIN users u ON l.user_id = u.id 
    ORDER BY l.id DESC 
    LIMIT 25
");
$systemLogs = $logsStmt->fetchAll();

// Статистика сообщений и звонков
$dmCount  = (int)($pdo->query("SELECT COUNT(*) FROM direct_messages")->fetchColumn() ?? 0);
$dmCallCount = (int)($pdo->query("SELECT COUNT(*) FROM dm_calls")->fetchColumn() ?? 0);
$activeUsers24h = (int)($pdo->query("SELECT COUNT(DISTINCT user_id) FROM logs WHERE created_at >= NOW() - INTERVAL 24 HOUR")->fetchColumn() ?? 0);

$bugStatusBadges = [
    'new'         => ['label' => 'Новый',       'badge' => 'badge-danger',   'color' => '#ef4444'],
    'in_progress' => ['label' => 'В работе',    'badge' => 'badge-warning',  'color' => '#f59e0b'],
    'fixed'       => ['label' => 'Исправлен',   'badge' => 'badge-success',  'color' => '#10b981'],
    'rejected'    => ['label' => 'Отклонён',    'badge' => 'badge-secondary','color' => '#6b7280'],
];

$csrfToken = Security::csrfToken();
$pageTitle = "Консоль Разработчика | AI Business Assistant";
require dirname(__DIR__) . '/includes/header.php';
?>

<div style="margin-bottom:24px; display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px;">
    <div>
        <div style="display:flex; align-items:center; gap:10px; margin-bottom:6px;">
            <span class="badge" style="background:linear-gradient(135deg,#06b6d4,#0891b2); color:#fff; font-size:12px; font-weight:700; padding:4px 10px;">
                <i class="fas fa-terminal"></i> Консоль Разработчика
            </span>
            <span style="font-size:13px; color:var(--text-muted);">SQL Console &bull; Диагностика &bull; Оптимизация БД &bull; Health Check</span>
        </div>
        <h1 style="margin:0; font-size:26px; font-weight:800;">Центр управления инфраструктурой</h1>
    </div>

    <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
        <!-- Запуск пинга эндпоинтов -->
        <form method="POST" action="/developer/dashboard.php" style="margin:0;">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" value="ping_endpoints">
            <button type="submit" class="btn btn-secondary btn-sm" style="font-weight:600;">
                <i class="fas fa-network-wired"></i> Health Check API
            </button>
        </form>

        <!-- Оптимизация таблиц БД -->
        <form method="POST" action="/developer/dashboard.php" style="margin:0;" onsubmit="return confirm('Запустить дефрагментацию и оптимизацию всех таблиц БД?');">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" value="db_optimize_tables">
            <button type="submit" class="btn btn-secondary btn-sm" style="font-weight:600; color:#10b981;">
                <i class="fas fa-bolt"></i> Оптимизировать БД
            </button>
        </form>

        <a href="/chat.php?channel=site_hq" class="btn btn-primary btn-sm">
            <i class="fas fa-crown"></i> Чат Dev &amp; HQ
        </a>
        <a href="/direct_messages.php" class="btn btn-secondary btn-sm">
            <i class="fas fa-comment-dots"></i> ЛС &amp; Звонки
        </a>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger" style="margin-bottom:20px;">
        <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success" style="margin-bottom:20px;">
        <i class="fas fa-check-circle"></i> <?= $success ?>
    </div>
<?php endif; ?>

<!-- СИСТЕМНЫЕ МЕТРИКИ (ИНФРАСТРУКТУРА) -->
<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(210px, 1fr)); gap:14px; margin-bottom:24px;">
    <div class="card" style="padding:16px;">
        <div style="font-size:11px; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-muted); margin-bottom:6px;">
            <i class="fab fa-php" style="color:#818cf8;"></i> Среда PHP
        </div>
        <div style="font-size:20px; font-weight:800; color:var(--text-primary);"><?= htmlspecialchars($phpVersion) ?></div>
        <div style="font-size:11px; color:var(--text-muted); margin-top:4px;">RAM: <?= $memoryUsage ?> / <?= htmlspecialchars($memoryLimit) ?></div>
    </div>

    <div class="card" style="padding:16px;">
        <div style="font-size:11px; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-muted); margin-bottom:6px;">
            <i class="fas fa-database" style="color:#06b6d4;"></i> База Данных MySQL
        </div>
        <div style="font-size:20px; font-weight:800; color:var(--text-primary);"><?= $dbStats['tables_count'] ?? 0 ?> таблиц</div>
        <div style="font-size:11px; color:var(--text-muted); margin-top:4px;">Размер: <?= $dbStats['db_size_mb'] ?? 0 ?> MB &bull; v<?= htmlspecialchars(mb_substr($mysqlVersion, 0, 10)) ?></div>
    </div>

    <div class="card" style="padding:16px;">
        <div style="font-size:11px; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-muted); margin-bottom:6px;">
            <i class="fas fa-bug" style="color:#f59e0b;"></i> Баги от QA-отдела
        </div>
        <div style="font-size:20px; font-weight:800; color:<?= count($bugReports) > 0 ? '#f59e0b' : '#10b981' ?>;">
            <?= count($bugReports) ?> отчетов
        </div>
        <div style="font-size:11px; color:var(--text-muted); margin-top:4px;">
            <?= count(array_filter($bugReports, fn($b) => $b['status'] === 'new')) ?> новых требует внимания
        </div>
    </div>

    <div class="card" style="padding:16px;">
        <div style="font-size:11px; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-muted); margin-bottom:6px;">
            <i class="fas fa-phone-volume" style="color:#38bdf8;"></i> DM &amp; Телефония
        </div>
        <div style="font-size:20px; font-weight:800; color:var(--text-primary);"><?= $dmCallCount ?> звонков</div>
        <div style="font-size:11px; color:var(--text-muted); margin-top:4px;">Сообщений в личке: <?= $dmCount ?></div>
    </div>
</div>

<!-- РЕЗУЛЬТАТЫ HEALTH CHECK ПРИ НАЖАТИИ -->
<?php if (!empty($pingResults)): ?>
<div class="card" style="padding:18px; margin-bottom:24px; border:1px solid rgba(56,189,248,0.3); background:rgba(56,189,248,0.03);">
    <div style="font-size:15px; font-weight:800; margin-bottom:12px; display:flex; align-items:center; gap:8px;">
        <i class="fas fa-heartbeat" style="color:#38bdf8;"></i> Результаты проверки эндпоинтов (Health Check)
    </div>
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:10px;">
        <?php foreach ($pingResults as $pr): ?>
        <div style="display:flex; justify-content:space-between; align-items:center; padding:10px 14px; background:var(--bg-input); border-radius:8px; border:1px solid var(--border-color);">
            <div>
                <div style="font-weight:700; font-size:13px; color:var(--text-primary);"><?= htmlspecialchars($pr['name']) ?></div>
                <div style="font-size:11px; font-family:monospace; color:var(--text-muted);"><?= htmlspecialchars($pr['path']) ?></div>
            </div>
            <div style="text-align:right;">
                <span class="badge <?= $pr['ok'] ? 'badge-success' : 'badge-danger' ?>" style="font-size:12px;">
                    HTTP <?= $pr['status'] ?>
                </span>
                <div style="font-size:11px; color:var(--text-muted); margin-top:3px;"><?= $pr['latency_ms'] ?> ms</div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- ИНТЕРАКТИВНЫЙ SQL RUNNER (КОНСОЛЬ ЗАПРОСОВ) -->
<div class="card" style="padding:20px; margin-bottom:24px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; flex-wrap:wrap; gap:10px;">
        <div>
            <h3 style="margin:0 0 4px; font-size:16px; font-weight:800; display:flex; align-items:center; gap:8px;">
                <i class="fas fa-terminal" style="color:#06b6d4;"></i> Интерактивная SQL Консоль (Только чтение)
            </h3>
            <div style="font-size:12px; color:var(--text-muted);">
                Безопасное выполнение запросов к текущей базе данных (SELECT, SHOW, DESCRIBE, EXPLAIN)
            </div>
        </div>
        <!-- Быстрые шаблоны запросов -->
        <div style="display:flex; gap:6px; flex-wrap:wrap;">
            <button type="button" class="btn btn-secondary btn-sm" style="font-size:11px; padding:3px 8px;" onclick="setSql('SELECT id, first_name, last_name, email, role_id, status FROM users ORDER BY id DESC LIMIT 15')">
                👤 Пользователи
            </button>
            <button type="button" class="btn btn-secondary btn-sm" style="font-size:11px; padding:3px 8px;" onclick="setSql('SELECT id, caller_id, receiver_id, status, started_at, ended_at FROM dm_calls ORDER BY id DESC LIMIT 10')">
                📞 Звонки DM
            </button>
            <button type="button" class="btn btn-secondary btn-sm" style="font-size:11px; padding:3px 8px;" onclick="setSql('SELECT section_key, section_name, is_locked, lock_reason, locked_until FROM section_locks')">
                🔒 Блокировки
            </button>
            <button type="button" class="btn btn-secondary btn-sm" style="font-size:11px; padding:3px 8px;" onclick="setSql('SHOW TABLE STATUS')">
                📊 Статус таблиц
            </button>
        </div>
    </div>

    <form method="POST" action="/developer/dashboard.php" style="margin:0;">
        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
        <input type="hidden" name="action" value="run_sql">

        <div style="position:relative; margin-bottom:12px;">
            <textarea name="sql_query" id="sqlQueryInput" rows="3" class="form-control"
                style="font-family:Consolas, monospace; font-size:13px; background:#0b1120; color:#38bdf8; border:1px solid #1e293b; padding:12px;"
                placeholder="SELECT * FROM users WHERE status = 'active' ORDER BY id DESC LIMIT 10;"><?= htmlspecialchars($_POST['sql_query'] ?? '') ?></textarea>
        </div>

        <div style="display:flex; justify-content:space-between; align-items:center;">
            <span style="font-size:12px; color:var(--text-muted);">
                <i class="fas fa-shield-alt"></i> Запросы на изменение (DROP, DELETE, UPDATE) заблокированы в консоли
            </span>
            <button type="submit" class="btn btn-primary btn-sm" style="font-weight:700; padding:6px 18px;">
                <i class="fas fa-play"></i> Выполнить запрос
            </button>
        </div>
    </form>

    <!-- Вывод ошибки SQL -->
    <?php if ($sqlError): ?>
        <div style="margin-top:14px; padding:10px 14px; background:rgba(239,68,68,0.15); border:1px solid rgba(239,68,68,0.3); border-radius:8px; color:#ef4444; font-family:monospace; font-size:12px;">
            <i class="fas fa-exclamation-triangle"></i> Ошибка SQL: <?= htmlspecialchars($sqlError) ?>
        </div>
    <?php endif; ?>

    <!-- Таблица результатов SQL -->
    <?php if ($sqlResults !== null): ?>
        <div style="margin-top:16px; border-top:1px solid var(--border-color); padding-top:14px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                <span style="font-size:13px; font-weight:700; color:#10b981;">
                    <i class="fas fa-check"></i> Найдено строк: <?= count($sqlResults) ?>
                </span>
                <span style="font-size:12px; color:var(--text-muted); font-family:monospace;">
                    Время выполнения: <?= $sqlExecTime ?> ms
                </span>
            </div>

            <?php if (empty($sqlResults)): ?>
                <div style="padding:16px; text-align:center; color:var(--text-muted); font-size:13px; background:var(--bg-input); border-radius:8px;">
                    Запрос вернул пустой набор данных (0 строк).
                </div>
            <?php else: ?>
                <div style="overflow-x:auto; max-height:360px; border:1px solid var(--border-color); border-radius:8px;">
                    <table class="table table-sm" style="margin:0; font-size:12px; font-family:Consolas, monospace;">
                        <thead style="position:sticky; top:0; background:var(--bg-card); z-index:2;">
                            <tr>
                                <?php foreach (array_keys($sqlResults[0]) as $col): ?>
                                    <th style="padding:8px 12px; border-bottom:2px solid var(--border-color); color:#38bdf8;"><?= htmlspecialchars((string)$col) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sqlResults as $row): ?>
                                <tr>
                                    <?php foreach ($row as $val): ?>
                                        <td style="padding:6px 12px; border-bottom:1px solid var(--border-color); white-space:nowrap;">
                                            <?php if ($val === null): ?>
                                                <span style="color:var(--text-muted); font-style:italic;">NULL</span>
                                            <?php else: ?>
                                                <?= htmlspecialchars(mb_substr((string)$val, 0, 100)) ?>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<!-- СЕТКА: БАГ-ТРЕКЕР И СОСТОЯНИЕ ТАБЛИЦ -->
<div style="display:grid; grid-template-columns:1.4fr 1fr; gap:20px; margin-bottom:24px;">
    <!-- БАГ-ТРЕКИНГ ОТ QA -->
    <div class="card" style="padding:20px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
            <h3 style="margin:0; font-size:16px; font-weight:800; display:flex; align-items:center; gap:8px;">
                <i class="fas fa-bug" style="color:#f59e0b;"></i> Баг-репорты от тестировщиков
            </h3>
            <span class="badge" style="background:rgba(245,158,11,0.15); color:#f59e0b; font-size:12px; font-weight:700;">
                Всего: <?= count($bugReports) ?>
            </span>
        </div>

        <?php if (empty($bugReports)): ?>
            <div style="padding:30px; text-align:center; color:var(--text-muted); font-size:13px;">
                <i class="fas fa-check-circle" style="font-size:36px; color:#10b981; margin-bottom:8px; display:block;"></i>
                Открытых баг-репортов нет. Все найденные дефекты устранены.
            </div>
        <?php else: ?>
            <div style="display:flex; flex-direction:column; gap:12px; max-height:420px; overflow-y:auto; padding-right:4px;">
                <?php foreach ($bugReports as $b): 
                    $bInfo = $bugStatusBadges[$b['status']] ?? $bugStatusBadges['new'];
                ?>
                <div style="border:1px solid var(--border-color); border-radius:10px; padding:14px; background:var(--bg-input);">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:8px; gap:8px;">
                        <div>
                            <span class="badge <?= $bInfo['badge'] ?>" style="font-size:11px; margin-right:6px;">
                                <?= $bInfo['label'] ?>
                            </span>
                            <span style="font-size:11px; color:var(--text-muted); font-family:monospace;">
                                #<?= $b['id'] ?> &bull; <?= htmlspecialchars($b['severity']) ?>
                            </span>
                            <div style="font-weight:700; font-size:14px; margin-top:4px; color:var(--text-primary);">
                                <?= htmlspecialchars($b['title']) ?>
                            </div>
                        </div>
                        <span style="font-size:11px; color:var(--text-muted); white-space:nowrap;">
                            <?= date('d.m H:i', strtotime($b['created_at'])) ?>
                        </span>
                    </div>

                    <div style="font-size:12px; color:var(--text-muted); margin-bottom:10px; line-height:1.4;">
                        <?= nl2br(htmlspecialchars(mb_substr($b['description'], 0, 180))) ?>
                    </div>

                    <!-- Форма обновления статуса бага -->
                    <form method="POST" action="/developer/dashboard.php" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin:0; border-top:1px dashed var(--border-color); padding-top:10px;">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        <input type="hidden" name="action" value="update_bug_status">
                        <input type="hidden" name="bug_id" value="<?= $b['id'] ?>">

                        <select name="status" class="form-control form-control-sm" style="width:130px; font-size:11px; height:30px;">
                            <option value="new" <?= $b['status'] === 'new' ? 'selected' : '' ?>>Новый</option>
                            <option value="in_progress" <?= $b['status'] === 'in_progress' ? 'selected' : '' ?>>В работе</option>
                            <option value="fixed" <?= $b['status'] === 'fixed' ? 'selected' : '' ?>>Исправлен</option>
                            <option value="rejected" <?= $b['status'] === 'rejected' ? 'selected' : '' ?>>Отклонён</option>
                        </select>

                        <input type="text" name="developer_comment" class="form-control form-control-sm" style="flex:1; min-width:140px; font-size:11px; height:30px;" placeholder="Комментарий разработчика..." value="<?= htmlspecialchars($b['developer_comment'] ?? '') ?>">

                        <button type="submit" class="btn btn-secondary btn-sm" style="font-size:11px; height:30px; padding:0 10px;">
                            Сохранить
                        </button>
                    </form>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- ТАБЛИЦЫ БД И ПАРАМЕТРЫ СЕРВЕРА -->
    <div class="card" style="padding:20px;">
        <h3 style="margin:0 0 14px; font-size:16px; font-weight:800; display:flex; align-items:center; gap:8px;">
            <i class="fas fa-layer-group" style="color:#818cf8;"></i> Состояние таблиц БД
        </h3>

        <div style="overflow-x:auto;">
            <table class="table table-sm" style="font-size:12px; margin:0;">
                <thead>
                    <tr style="border-bottom:1px solid var(--border-color); color:var(--text-muted);">
                        <th>Таблица</th>
                        <th>Строк</th>
                        <th>Размер</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tablesInfo as $ti): ?>
                    <tr style="border-bottom:1px solid var(--border-color);">
                        <td style="font-family:Consolas, monospace; font-weight:600; color:var(--text-primary);">
                            <?= htmlspecialchars($ti['table_name']) ?>
                        </td>
                        <td style="color:var(--text-muted);"><?= number_format((int)($ti['table_rows'] ?? 0)) ?></td>
                        <td style="color:#06b6d4; font-family:monospace;"><?= $ti['size_kb'] ?> KB</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div style="margin-top:16px; border-top:1px solid var(--border-color); padding-top:12px;">
            <div style="font-size:12px; font-weight:700; margin-bottom:6px; color:var(--text-muted);">Системные лимиты PHP</div>
            <div style="display:flex; justify-content:space-between; font-size:11px; color:var(--text-muted); margin-bottom:4px;">
                <span>upload_max_filesize:</span>
                <span style="font-family:monospace; color:var(--text-primary);"><?= htmlspecialchars($uploadMax) ?></span>
            </div>
            <div style="display:flex; justify-content:space-between; font-size:11px; color:var(--text-muted); margin-bottom:4px;">
                <span>post_max_size:</span>
                <span style="font-family:monospace; color:var(--text-primary);"><?= htmlspecialchars($postMax) ?></span>
            </div>
            <div style="display:flex; justify-content:space-between; font-size:11px; color:var(--text-muted);">
                <span>max_execution_time:</span>
                <span style="font-family:monospace; color:var(--text-primary);"><?= htmlspecialchars($maxExecTime) ?> сек</span>
            </div>
        </div>
    </div>
</div>

<!-- ЖУРНАЛ АУДИТА СИСТЕМЫ (LOGS) -->
<div class="card" style="padding:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; flex-wrap:wrap; gap:10px;">
        <div>
            <h3 style="margin:0 0 4px; font-size:16px; font-weight:800; display:flex; align-items:center; gap:8px;">
                <i class="fas fa-clipboard-list" style="color:#10b981;"></i> Системный журнал аудита (Последние события)
            </h3>
            <div style="font-size:12px; color:var(--text-muted);">
                Фиксация действий разработчиков, тестировщиков и критических событий безопасности
            </div>
        </div>

        <form method="POST" action="/developer/dashboard.php" style="margin:0;" onsubmit="return confirm('Удалить записи логов старше 30 дней?');">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" value="db_clean_old_logs">
            <button type="submit" class="btn btn-secondary btn-sm" style="font-size:11px; padding:3px 10px;">
                <i class="fas fa-trash-alt"></i> Очистить старые (>30 дней)
            </button>
        </form>
    </div>

    <div style="overflow-x:auto;">
        <table class="table table-sm" style="font-size:12px; margin:0;">
            <thead>
                <tr style="border-bottom:2px solid var(--border-color); color:var(--text-muted);">
                    <th style="width:70px;">ID</th>
                    <th style="width:140px;">Дата</th>
                    <th style="width:160px;">Событие</th>
                    <th>Описание действия</th>
                    <th style="width:160px;">Пользователь</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($systemLogs as $log): ?>
                <tr style="border-bottom:1px solid var(--border-color);">
                    <td style="color:var(--text-muted); font-family:monospace;">#<?= (int)($log['id'] ?? 0) ?></td>
                    <td style="white-space:nowrap; color:var(--text-muted); font-size:11px;">
                        <?= !empty($log['created_at']) ? date('d.m.Y H:i:s', strtotime($log['created_at'])) : '-' ?>
                    </td>
                    <td>
                        <span class="badge" style="background:rgba(255,255,255,0.08); font-family:monospace; font-size:11px;">
                            <?= htmlspecialchars((string)($log['event_type'] ?? $log['action'] ?? 'system')) ?>
                        </span>
                    </td>
                    <td style="color:var(--text-primary); font-size:12px;">
                        <?= htmlspecialchars((string)($log['details'] ?? '')) ?>
                    </td>
                    <td style="color:var(--text-muted); font-size:11px;">
                        <?= htmlspecialchars((string)($log['email'] ?? $log['user_email'] ?? 'Система')) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function setSql(q) {
    const el = document.getElementById('sqlQueryInput');
    if (el) {
        el.value = q;
        el.focus();
    }
}
</script>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
