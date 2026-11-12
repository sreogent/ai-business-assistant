<?php
declare(strict_types=1);

/**
 * AI Business Assistant - Консоль Тестировщика (QA Tester Console)
 * Автоматизированный Smoke-тест, Песочница генерации данных,
 * Аудио-диагностика телефонии и микрофона, Баг-трекинг.
 */

require_once dirname(__DIR__) . '/includes/Auth.php';
require_once dirname(__DIR__) . '/includes/OrganizationGenerator.php';
require_once dirname(__DIR__) . '/includes/AIService.php';

$user = Auth::requireRole(['tester', 'vrio_owner', 'admin']);
$userId = (int)$user['id'];
$pdo = Database::getConnection();

$error = null;
$success = null;
$smokeResults = null;
$createdTestUser = null;

// Обработка действий тестировщика
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!Security::verifyCsrf($token)) {
        $error = "Истёк токен безопасности. Повторите попытку.";
    } else {
        $action = $_POST['action'] ?? '';

        // 1. Автоматический Smoke-тест системы
        if ($action === 'run_smoke_test') {
            $smokeResults = [];

            // Тест 1: База данных
            $t0 = microtime(true);
            try {
                $ver = $pdo->query("SELECT 1")->fetchColumn();
                $smokeResults[] = [
                    'name' => 'Подключение к MySQL и выполнение запросов',
                    'category' => 'База данных',
                    'ok' => ($ver == 1),
                    'latency_ms' => round((microtime(true) - $t0) * 1000, 2),
                    'details' => 'Соединение стабильно, SELECT 1 выполнен успешно.'
                ];
            } catch (\Throwable $e) {
                $smokeResults[] = [
                    'name' => 'Подключение к MySQL',
                    'category' => 'База данных',
                    'ok' => false,
                    'latency_ms' => round((microtime(true) - $t0) * 1000, 2),
                    'details' => $e->getMessage()
                ];
            }

            // Тест 2: Проверка целостности ключевых таблиц
            $t0 = microtime(true);
            $requiredTables = ['users', 'organizations', 'direct_messages', 'dm_calls', 'section_locks', 'cash_orders', 'subscription_keys', 'bug_reports'];
            $missing = [];
            foreach ($requiredTables as $rt) {
                $chk = $pdo->query("SHOW TABLES LIKE '{$rt}'")->fetchColumn();
                if (!$chk) $missing[] = $rt;
            }
            $smokeResults[] = [
                'name' => 'Целостность схемы базы данных (' . count($requiredTables) . ' таблиц)',
                'category' => 'Схема БД',
                'ok' => empty($missing),
                'latency_ms' => round((microtime(true) - $t0) * 1000, 2),
                'details' => empty($missing) ? 'Все ключевые таблицы присутствуют в БД.' : 'Отсутствуют таблицы: ' . implode(', ', $missing)
            ];

            // Тест 3: Права на запись в каталог аудиофайлов и голосовых
            $t0 = microtime(true);
            $uploadDir = dirname(__DIR__) . '/uploads/voice_messages';
            $dirOk = false;
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0777, true);
            }
            $testFile = $uploadDir . '/test_' . time() . '.tmp';
            if (@file_put_contents($testFile, 'test') !== false) {
                @unlink($testFile);
                $dirOk = true;
            }
            $smokeResults[] = [
                'name' => 'Права на запись голосовых сообщений (uploads/voice_messages)',
                'category' => 'Файловая система',
                'ok' => $dirOk,
                'latency_ms' => round((microtime(true) - $t0) * 1000, 2),
                'details' => $dirOk ? 'Директория доступна для записи и чтения аудиофайлов.' : 'Нет прав на запись в uploads/voice_messages'
            ];

            // Тест 4: Генератор и валидатор CSRF токенов
            $t0 = microtime(true);
            $tokenSample = Security::csrfToken();
            $tokenValid = Security::verifyCsrf($tokenSample);
            $smokeResults[] = [
                'name' => 'Генерация и валидация CSRF токенов безопасности',
                'category' => 'Безопасность',
                'ok' => $tokenValid,
                'latency_ms' => round((microtime(true) - $t0) * 1000, 2),
                'details' => $tokenValid ? 'Токены безопасности корректно генерируются и проверяются.' : 'Ошибка сверки CSRF токена.'
            ];

            // Тест 5: Локальный детерминированный движок AI
            $t0 = microtime(true);
            try {
                $aiResp = AIService::deterministicLocalEngine('бизнес-план и окупаемость', '', ['first_name' => 'QA_Tester', 'role_name' => 'tester']);
                $aiOk = !empty($aiResp) && mb_strlen($aiResp) > 100;
                $smokeResults[] = [
                    'name' => 'Детерминированный бизнес-движок AI (расчёты, бизнес-планы, налоги)',
                    'category' => 'AI Сервис',
                    'ok' => $aiOk,
                    'latency_ms' => round((microtime(true) - $t0) * 1000, 2),
                    'details' => $aiOk ? 'AI-движок ответил структурированным расчётом (' . mb_strlen($aiResp) . ' симв.)' : 'AI вернул пустой ответ.'
                ];
            } catch (\Throwable $e) {
                $smokeResults[] = [
                    'name' => 'Детерминированный бизнес-движок AI',
                    'category' => 'AI Сервис',
                    'ok' => false,
                    'latency_ms' => round((microtime(true) - $t0) * 1000, 2),
                    'details' => $e->getMessage()
                ];
            }

            // Тест 6: Механизм блокировки разделов
            $t0 = microtime(true);
            $locks = Auth::getSectionLocks();
            $smokeResults[] = [
                'name' => 'Система ручной блокировки разделов (section_locks)',
                'category' => 'Модули доступа',
                'ok' => is_array($locks),
                'latency_ms' => round((microtime(true) - $t0) * 1000, 2),
                'details' => 'Таблица блокировок прочитана, активных записей: ' . count($locks)
            ];

            // Тест 7: Таблица телефонии и статусы DM-звонков
            $t0 = microtime(true);
            $dmCallCols = $pdo->query("DESCRIBE dm_calls")->fetchAll(PDO::FETCH_COLUMN);
            $colsOk = in_array('caller_id', $dmCallCols) && in_array('receiver_id', $dmCallCols) && in_array('status', $dmCallCols);
            $smokeResults[] = [
                'name' => 'Схема телефонии и личных звонков (dm_calls)',
                'category' => 'Телефония',
                'ok' => $colsOk,
                'latency_ms' => round((microtime(true) - $t0) * 1000, 2),
                'details' => $colsOk ? 'Колонки caller_id, receiver_id, status на месте.' : 'Отсутствуют необходимые поля в dm_calls.'
            ];

            $allOk = !in_array(false, array_column($smokeResults, 'ok'), true);
            Security::log($pdo, 'tester_smoke_test', "Тестировщик выполнил Smoke-тест системы (Всего тестов: " . count($smokeResults) . ", Успешно: " . ($allOk ? 'ДА' : 'ЕСТЬ ОШИБКИ') . ")", $userId);
            $success = $allOk
                ? "✅ Автоматический Smoke-тест завершён: все " . count($smokeResults) . " проверок успешно пройдены!"
                : "⚠️ Smoke-тест завершён с замечаниями. Ознакомьтесь с отчётом ниже.";
        }

        // 2. Создание нового баг-репорта
        if ($action === 'submit_bug') {
            $title = trim($_POST['title'] ?? '');
            $severity = trim($_POST['severity'] ?? 'medium');
            $category = trim($_POST['category'] ?? 'UI');
            $description = trim($_POST['description'] ?? '');
            $steps = trim($_POST['steps_to_reproduce'] ?? '');

            if (empty($title) || empty($description)) {
                $error = "Пожалуйста, укажите заголовок и подробное описание ошибки.";
            } else {
                $fullDesc = "[Категория: {$category}]\n" . $description;
                $insBug = $pdo->prepare("
                    INSERT INTO bug_reports (tester_id, title, severity, status, description, steps_to_reproduce)
                    VALUES (?, ?, ?, 'new', ?, ?)
                ");
                $insBug->execute([$userId, $title, $severity, $fullDesc, $steps]);
                $bugId = (int)$pdo->lastInsertId();

                // Отправляем уведомление в чат разработчиков и руководства
                $hqChanStmt = $pdo->query("SELECT id FROM team_chat_channels WHERE channel_key = 'site_hq' LIMIT 1");
                $hqChanId = $hqChanStmt->fetchColumn();
                if ($hqChanId) {
                    $sevIcons = ['low' => '🟢', 'medium' => '🟡', 'high' => '🟠', 'critical' => '🔴'];
                    $sevIcon = $sevIcons[$severity] ?? '⚠️';
                    $chatMsg = "{$sevIcon} [БАГ-РЕПОРТ #{$bugId}] {$title}\nКатегория: {$category} | Критичность: {$severity}\nТестировщик: " . ($user['first_name'] . ' ' . $user['last_name']) . "\nШаги: " . mb_substr($steps, 0, 100);
                    $pdo->prepare("INSERT INTO team_chat_messages (channel_id, sender_id, message) VALUES (?, ?, ?)")
                        ->execute([$hqChanId, $userId, $chatMsg]);
                }

                Security::log($pdo, 'tester_submit_bug', "Тестировщик создал баг-репорт #{$bugId}: {$title}", $userId);
                $success = "🐞 Баг-репорт #{$bugId} зарегистрирован и отправлен в командный чат разработчиков!";
            }
        }

        // 3. Песочница: Создание тестового пользователя
        if ($action === 'generate_test_user') {
            $roleName = trim($_POST['user_role'] ?? 'user');
            $roleId = (int)$pdo->query("SELECT id FROM roles WHERE name = " . $pdo->quote($roleName))->fetchColumn();
            if ($roleId <= 0) $roleId = 4; // user fallback

            $rndNum = random_int(1000, 9999);
            $testEmail = "qa_{$roleName}_{$rndNum}@qa.local";
            $rawPass = "QaTest" . random_int(100, 999) . "!";
            $hashPass = password_hash($rawPass, PASSWORD_BCRYPT);
            $names = ['Александр', 'Максим', 'Дмитрий', 'Сергей', 'Андрей', 'Елена', 'Анастасия', 'Ольга'];
            $surnames = ['Тестеров', 'Проверкин', 'Контроллер', 'Экспертов', 'Аудитор', 'Инженеров'];
            $fName = $names[array_rand($names)];
            $lName = $surnames[array_rand($surnames)];

            $ins = $pdo->prepare("
                INSERT INTO users (role_id, email, password_hash, first_name, last_name, status, created_at)
                VALUES (?, ?, ?, ?, ?, 'active', NOW())
            ");
            $ins->execute([$roleId, $testEmail, $hashPass, $fName, $lName]);
            $newUid = (int)$pdo->lastInsertId();

            $createdTestUser = [
                'id' => $newUid,
                'email' => $testEmail,
                'password' => $rawPass,
                'name' => "{$fName} {$lName}",
                'role' => $roleName
            ];
            Security::log($pdo, 'tester_generate_user', "QA создал тестового пользователя {$testEmail} (ID #{$newUid})", $userId);
            $success = "👤 Тестовый пользователь создан! Email: <strong>{$testEmail}</strong> | Пароль: <strong>{$rawPass}</strong> (Роль: {$roleName})";
        }

        // 4. Песочница: Генерация тестовой организации
        if ($action === 'generate_test_org') {
            $bType = trim($_POST['business_type'] ?? 'coffee');
            $types = OrganizationGenerator::getBusinessTypes();
            $tInfo = $types[$bType] ?? $types['coffee'];

            $orgName = "QA Тест: " . $tInfo['title'] . " #" . random_int(100, 999);
            $insOrg = $pdo->prepare("
                INSERT INTO organizations 
                (user_id, business_type, name, legal_name, director_name, director_email, director_phone, inn, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
            ");
            $insOrg->execute([
                $userId,
                $bType,
                $orgName,
                'ТОО ' . $orgName,
                $user['first_name'] . ' ' . $user['last_name'],
                $user['email'],
                '+7 777 000 00 00',
                (string)random_int(100000000000, 999999999999)
            ]);
            $newOrgId = (int)$pdo->lastInsertId();

            $genStats = OrganizationGenerator::setupOrganization($newOrgId, $bType, $userId);

            Security::log($pdo, 'tester_generate_org', "QA сгенерировал организацию #{$newOrgId} ({$bType})", $userId);
            $success = "🏢 Тестовая организация «{$orgName}» создана! Категорий: {$genStats['categories']}, товаров: {$genStats['items']}, должностей: {$genStats['positions']}.";
        }

        // 5. Песочница: Эмуляция кассовых продаж (POS)
        if ($action === 'emulate_pos_sales') {
            $orgId = (int)$pdo->query("SELECT id FROM organizations ORDER BY id DESC LIMIT 1")->fetchColumn();
            if ($orgId) {
                $methods = ['cash', 'card', 'transfer'];
                $count = 5;
                for ($i = 0; $i < $count; $i++) {
                    $total = random_int(1500, 18500);
                    $method = $methods[array_rand($methods)];
                    $insOrder = $pdo->prepare("
                        INSERT INTO cash_orders (organization_id, cashier_id, total, payment_method, status, paid_at)
                        VALUES (?, ?, ?, ?, 'paid', NOW())
                    ");
                    $insOrder->execute([$orgId, $userId, $total, $method]);
                }
                $success = "💳 Успешно эмулировано {$count} кассовых чеков с различными типами оплаты!";
            } else {
                $error = "Сначала создайте организацию для привязки кассы.";
            }
        }

        // 6. Песочница: Генерация ключа активации тарифа
        if ($action === 'generate_test_key') {
            $key = strtoupper(bin2hex(random_bytes(2))) . '-' . strtoupper(bin2hex(random_bytes(2))) . '-' . strtoupper(bin2hex(random_bytes(2))) . '-' . strtoupper(bin2hex(random_bytes(2)));
            $tariffId = (int)$pdo->query("SELECT id FROM tariffs WHERE is_active = 1 LIMIT 1")->fetchColumn();
            if ($tariffId) {
                $pdo->prepare("
                    INSERT INTO subscription_keys (key_code, tariff_id, created_by, recipient_email, status)
                    VALUES (?, ?, ?, 'qa_test@localhost', 'active')
                ")->execute([$key, $tariffId, $userId]);
                $success = "🔑 Сгенерирован тестовый ключ: <strong style='font-family:monospace; color:#10b981;'>{$key}</strong>";
            } else {
                $error = "Не найден активный тариф в системе.";
            }
        }

        // 7. Очистка тестовых данных QA
        if ($action === 'cleanup_qa_data') {
            try {
                $delUsers = $pdo->exec("DELETE FROM users WHERE email LIKE 'qa_%@qa.local'");
                $delOrgs = $pdo->exec("DELETE FROM organizations WHERE name LIKE 'QA Тест:%'");
                Security::log($pdo, 'tester_cleanup_qa', "QA очистил тестовые данные: {$delUsers} пользователей, {$delOrgs} организаций", $userId);
                $success = "🧹 Очистка завершена: удалено тестовых пользователей: {$delUsers}, тестовых организаций: {$delOrgs}.";
            } catch (\Throwable $e) {
                $error = "Ошибка очистки: " . $e->getMessage();
            }
        }
    }
}

// Загружаем список баг-репортов
$bugsStmt = $pdo->prepare("
    SELECT b.*, u.first_name, u.last_name 
    FROM bug_reports b
    LEFT JOIN users u ON b.tester_id = u.id 
    ORDER BY b.id DESC
");
$bugsStmt->execute();
$myBugs = $bugsStmt->fetchAll();

$businessTypes = OrganizationGenerator::getBusinessTypes();

$csrfToken = Security::csrfToken();
$pageTitle = "Консоль Тестировщика (QA) | AI Business Assistant";
require dirname(__DIR__) . '/includes/header.php';
?>

<div style="margin-bottom:24px; display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px;">
    <div>
        <div style="display:flex; align-items:center; gap:10px; margin-bottom:6px;">
            <span class="badge" style="background:linear-gradient(135deg,#f59e0b,#d97706); color:#fff; font-size:12px; font-weight:700; padding:4px 10px;">
                <i class="fas fa-vial"></i> Консоль Тестировщика (QA)
            </span>
            <span style="font-size:13px; color:var(--text-muted);">Автоматизированные тесты &bull; Песочница данных &bull; Аудио-тесты &bull; Баг-трекинг</span>
        </div>
        <h1 style="margin:0; font-size:26px; font-weight:800;">Центр контроля качества и тестирования</h1>
    </div>

    <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
        <!-- Кнопка запуска полного Smoke-теста -->
        <form method="POST" action="/tester/dashboard.php" style="margin:0;">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" value="run_smoke_test">
            <button type="submit" class="btn btn-primary btn-sm" style="font-weight:700; background:linear-gradient(135deg,#10b981,#059669); border:none;">
                <i class="fas fa-play-circle"></i> Запустить полный Smoke-тест
            </button>
        </form>

        <a href="/chat.php?channel=site_hq" class="btn btn-secondary btn-sm">
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

<!-- РЕЗУЛЬТАТЫ SMOKE-ТЕСТА (ЕСЛИ ЗАПУЩЕН) -->
<?php if (!empty($smokeResults)): ?>
<div class="card" style="padding:20px; margin-bottom:24px; border:1px solid rgba(16,185,129,0.3); background:rgba(16,185,129,0.03);">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
        <h3 style="margin:0; font-size:16px; font-weight:800; display:flex; align-items:center; gap:8px;">
            <i class="fas fa-clipboard-check" style="color:#10b981;"></i> Отчёт автоматического Smoke-тестирования
        </h3>
        <span style="font-size:12px; color:var(--text-muted); font-family:monospace;">
            Дата проверки: <?= date('d.m.Y H:i:s') ?>
        </span>
    </div>

    <div style="overflow-x:auto;">
        <table class="table table-sm" style="font-size:12px; margin:0;">
            <thead>
                <tr style="border-bottom:2px solid var(--border-color); color:var(--text-muted);">
                    <th style="width:140px;">Категория</th>
                    <th>Тест</th>
                    <th style="width:120px;">Статус</th>
                    <th style="width:100px;">Время</th>
                    <th>Результат / Детали</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($smokeResults as $sr): ?>
                <tr style="border-bottom:1px solid var(--border-color);">
                    <td style="color:var(--text-muted); font-weight:600;"><?= htmlspecialchars($sr['category']) ?></td>
                    <td style="font-weight:700; color:var(--text-primary);"><?= htmlspecialchars($sr['name']) ?></td>
                    <td>
                        <span class="badge <?= $sr['ok'] ? 'badge-success' : 'badge-danger' ?>" style="font-size:11px;">
                            <i class="fas <?= $sr['ok'] ? 'fa-check' : 'fa-times' ?>"></i>
                            <?= $sr['ok'] ? 'PASS' : 'FAIL' ?>
                        </span>
                    </td>
                    <td style="font-family:monospace; color:var(--text-muted);"><?= $sr['latency_ms'] ?> ms</td>
                    <td style="font-size:11px; color:<?= $sr['ok'] ? 'var(--text-muted)' : '#ef4444' ?>;">
                        <?= htmlspecialchars($sr['details']) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- СЕТКА: АУДИО ТЕЛЕФОНИЯ И ПЕСОЧНИЦА ДАННЫХ -->
<div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:24px;">

    <!-- 1. АУДИО ДИАГНОСТИКА ТЕЛЕФОНИИ И МИКРОФОНА -->
    <div class="card" style="padding:20px;">
        <h3 style="margin:0 0 8px; font-size:16px; font-weight:800; display:flex; align-items:center; gap:8px;">
            <i class="fas fa-volume-up" style="color:#38bdf8;"></i> Аудио-диагностика телефонии
        </h3>
        <p style="font-size:12px; color:var(--text-muted); margin-bottom:16px;">
            Проверка сигналов Web Audio API и аппаратной записи микрофона без необходимости второго устройства.
        </p>

        <div style="display:flex; flex-direction:column; gap:10px;">
            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <button type="button" class="btn btn-secondary btn-sm" onclick="window.playDialTone()" style="flex:1; min-width:130px;">
                    <i class="fas fa-phone-volume"></i> Гудок вызова (425Hz)
                </button>
                <button type="button" class="btn btn-secondary btn-sm" onclick="window.playIncomingRingtone()" style="flex:1; min-width:130px;">
                    <i class="fas fa-bell"></i> Рингтон звонка
                </button>
                <button type="button" class="btn btn-secondary btn-sm" onclick="window.playBusyTone()" style="flex:1; min-width:130px;">
                    <i class="fas fa-phone-slash"></i> Сигнал «Занято»
                </button>
            </div>

            <button type="button" class="btn btn-danger btn-sm" onclick="stopAllAudioTests()" style="font-weight:600;">
                <i class="fas fa-volume-mute"></i> Заглушить все сигналы
            </button>
        </div>

        <!-- Тест аппаратного микрофона Loopback -->
        <div style="margin-top:16px; border-top:1px solid var(--border-color); padding-top:14px;">
            <div style="font-size:13px; font-weight:700; margin-bottom:6px;">
                <i class="fas fa-microphone" style="color:#ef4444;"></i> Тест микрофона (Loopback Запись 3 сек)
            </div>
            <div style="font-size:11px; color:var(--text-muted); margin-bottom:10px;">
                Запишет 3 секунды вашего голоса и сразу воспроизведет для проверки микрофона и динамиков.
            </div>
            <div style="display:flex; align-items:center; gap:10px;">
                <button type="button" id="btnMicTest" class="btn btn-secondary btn-sm" onclick="runMicLoopbackTest()">
                    <i class="fas fa-circle" style="color:#ef4444; font-size:10px;"></i> Начать запись теста (3с)
                </button>
                <span id="micTestStatus" style="font-size:12px; color:var(--text-muted); font-weight:600;"></span>
            </div>
            <div id="micPlaybackWrap" style="margin-top:10px; display:none;">
                <audio id="micAudioEl" controls style="width:100%; height:32px;"></audio>
            </div>
        </div>
    </div>

    <!-- 2. ПЕСОЧНИЦА ДАННЫХ (SANDBOX GENERATOR) -->
    <div class="card" style="padding:20px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
            <h3 style="margin:0; font-size:16px; font-weight:800; display:flex; align-items:center; gap:8px;">
                <i class="fas fa-magic" style="color:#f59e0b;"></i> Песочница данных (Генератор QA)
            </h3>
            <form method="POST" action="/tester/dashboard.php" style="margin:0;" onsubmit="return confirm('Удалить сгенерированных пользователей и организации QA?');">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="action" value="cleanup_qa_data">
                <button type="submit" class="btn btn-secondary btn-sm" style="font-size:11px; padding:2px 8px; color:#ef4444;">
                    <i class="fas fa-trash-alt"></i> Очистить QA данные
                </button>
            </form>
        </div>
        <p style="font-size:12px; color:var(--text-muted); margin-bottom:14px;">
            Мгновенное создание изолированных сущностей для тестирования различных сценариев.
        </p>

        <!-- Генерация пользователя с ролью -->
        <form method="POST" action="/tester/dashboard.php" style="margin-bottom:12px; display:flex; gap:8px; align-items:center;">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" value="generate_test_user">

            <select name="user_role" class="form-control form-control-sm" style="width:140px; font-size:12px;">
                <option value="user">Клиент (User)</option>
                <option value="staff">Сотрудник</option>
                <option value="support_agent">Поддержка</option>
                <option value="developer">Разработчик</option>
                <option value="tester">Тестировщик</option>
            </select>

            <button type="submit" class="btn btn-secondary btn-sm" style="font-weight:600; flex:1;">
                <i class="fas fa-user-plus"></i> Создать тест-пользователя
            </button>
        </form>

        <!-- Генерация организации -->
        <form method="POST" action="/tester/dashboard.php" style="margin-bottom:12px; display:flex; gap:8px; align-items:center;">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" value="generate_test_org">

            <select name="business_type" class="form-control form-control-sm" style="width:140px; font-size:12px;">
                <?php foreach ($businessTypes as $k => $v): ?>
                    <option value="<?= $k ?>"><?= htmlspecialchars($v['title']) ?></option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="btn btn-secondary btn-sm" style="font-weight:600; flex:1;">
                <i class="fas fa-building"></i> Сгенерировать бизнес
            </button>
        </form>

        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <!-- Эмуляция кассовых продаж -->
            <form method="POST" action="/tester/dashboard.php" style="flex:1; margin:0;">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="action" value="emulate_pos_sales">
                <button type="submit" class="btn btn-secondary btn-sm w-100" style="font-weight:600;">
                    <i class="fas fa-cash-register"></i> Эмуляция 5 чеков POS
                </button>
            </form>

            <!-- Генерация ключа подписки -->
            <form method="POST" action="/tester/dashboard.php" style="flex:1; margin:0;">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="action" value="generate_test_key">
                <button type="submit" class="btn btn-secondary btn-sm w-100" style="font-weight:600; color:#10b981;">
                    <i class="fas fa-key"></i> Создать промокод
                </button>
            </form>
        </div>
    </div>
</div>

<!-- СЕТКА: ФОРМА БАГ-РЕПОРТА И ТАБЛИЦА БАГОВ -->
<div style="display:grid; grid-template-columns:1fr 1.5fr; gap:20px; margin-bottom:24px;">

    <!-- ФОРМА РЕГИСТРАЦИИ ДЕФЕКТА -->
    <div class="card" style="padding:20px;">
        <h3 style="margin:0 0 14px; font-size:16px; font-weight:800; display:flex; align-items:center; gap:8px;">
            <i class="fas fa-plus-circle" style="color:#ef4444;"></i> Зарегистрировать баг-репорт
        </h3>

        <form method="POST" action="/tester/dashboard.php">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" value="submit_bug">

            <div class="form-group mb-2">
                <label style="font-size:12px; font-weight:600;">Краткий заголовок дефекта:</label>
                <input type="text" name="title" class="form-control form-control-sm" required placeholder="Например: Ошибка передачи токена при звонке...">
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:10px;">
                <div>
                    <label style="font-size:12px; font-weight:600;">Критичность:</label>
                    <select name="severity" class="form-control form-control-sm">
                        <option value="low">Низкая (Косметика)</option>
                        <option value="medium" selected>Средняя (Обычный баг)</option>
                        <option value="high">Высокая (Часть не работает)</option>
                        <option value="critical">Блокирующий (Critical/Blocker)</option>
                    </select>
                </div>
                <div>
                    <label style="font-size:12px; font-weight:600;">Категория:</label>
                    <select name="category" class="form-control form-control-sm">
                        <option value="Телефония & Звонки">Телефония &amp; Звонки</option>
                        <option value="Личные сообщения">Личные сообщения</option>
                        <option value="AI-Ассистент">AI-Ассистент</option>
                        <option value="Касса POS">Касса POS</option>
                        <option value="Авторизация & Сессии">Авторизация &amp; Сессии</option>
                        <option value="Интерфейс UI">Интерфейс UI</option>
                    </select>
                </div>
            </div>

            <div class="form-group mb-2">
                <label style="font-size:12px; font-weight:600;">Шаги воспроизведения:</label>
                <textarea name="steps_to_reproduce" rows="2" class="form-control form-control-sm" placeholder="1. Открыть раздел...&#10;2. Нажать кнопку...&#10;3. Получили ошибку..."></textarea>
            </div>

            <div class="form-group mb-3">
                <label style="font-size:12px; font-weight:600;">Подробное описание и фактический результат:</label>
                <textarea name="description" rows="3" class="form-control form-control-sm" required placeholder="Опишите, что пошло не так и что отобразилось на экране..."></textarea>
            </div>

            <button type="submit" class="btn btn-danger btn-sm w-100" style="font-weight:700; padding:8px;">
                <i class="fas fa-paper-plane"></i> Направить разработчикам в HQ
            </button>
        </form>
    </div>

    <!-- ТАБЛИЦА ВСЕХ ЗАРЕГИСТРИРОВАННЫХ БАГОВ -->
    <div class="card" style="padding:20px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
            <h3 style="margin:0; font-size:16px; font-weight:800; display:flex; align-items:center; gap:8px;">
                <i class="fas fa-list-alt" style="color:#06b6d4;"></i> Реестр дефектов QA
            </h3>
            <span class="badge" style="background:rgba(255,255,255,0.08); font-size:12px;">
                Всего: <?= count($myBugs) ?>
            </span>
        </div>

        <?php if (empty($myBugs)): ?>
            <div style="padding:40px; text-align:center; color:var(--text-muted); font-size:13px;">
                <i class="fas fa-check-double" style="font-size:36px; color:#10b981; margin-bottom:8px; display:block;"></i>
                Багов пока не зарегистрировано.
            </div>
        <?php else: ?>
            <div style="overflow-x:auto; max-height:460px; overflow-y:auto;">
                <table class="table table-sm" style="font-size:12px; margin:0;">
                    <thead>
                        <tr style="border-bottom:2px solid var(--border-color); color:var(--text-muted);">
                            <th style="width:60px;">ID</th>
                            <th>Заголовок</th>
                            <th style="width:90px;">Важность</th>
                            <th style="width:100px;">Статус</th>
                            <th>Ответ разработчика</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($myBugs as $b): 
                            $sevColors = ['low' => '#10b981', 'medium' => '#f59e0b', 'high' => '#f97316', 'critical' => '#ef4444'];
                            $stLabels = ['new' => 'Новый', 'in_progress' => 'В работе', 'fixed' => 'Исправлен', 'rejected' => 'Отклонён'];
                            $stBadges = ['new' => 'badge-danger', 'in_progress' => 'badge-warning', 'fixed' => 'badge-success', 'rejected' => 'badge-secondary'];
                        ?>
                        <tr style="border-bottom:1px solid var(--border-color);">
                            <td style="font-family:monospace; color:var(--text-muted);">#<?= $b['id'] ?></td>
                            <td>
                                <div style="font-weight:700; color:var(--text-primary);"><?= htmlspecialchars($b['title']) ?></div>
                                <div style="font-size:11px; color:var(--text-muted); margin-top:2px;">
                                    <?= date('d.m.Y H:i', strtotime($b['created_at'])) ?>
                                </div>
                            </td>
                            <td>
                                <span style="font-weight:700; font-size:11px; color:<?= $sevColors[$b['severity']] ?? '#f59e0b' ?>;">
                                    <?= htmlspecialchars($b['severity']) ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?= $stBadges[$b['status']] ?? 'badge-secondary' ?>" style="font-size:11px;">
                                    <?= $stLabels[$b['status']] ?? $b['status'] ?>
                                </span>
                            </td>
                            <td style="font-size:11px; color:var(--text-muted);">
                                <?= htmlspecialchars($b['developer_comment'] ?: 'Ожидает рассмотрения') ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function stopAllAudioTests() {
    if (typeof window.stopDialTone === 'function') window.stopDialTone();
    if (typeof window.stopIncomingRingtone === 'function') window.stopIncomingRingtone();
}

let micMediaRecorder = null;
let micChunks = [];

async function runMicLoopbackTest() {
    const statusEl = document.getElementById('micTestStatus');
    const btn = document.getElementById('btnMicTest');
    const wrap = document.getElementById('micPlaybackWrap');
    const audioEl = document.getElementById('micAudioEl');

    try {
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        micChunks = [];
        micMediaRecorder = new MediaRecorder(stream);
        micMediaRecorder.ondataavailable = e => {
            if (e.data && e.data.size > 0) micChunks.push(e.data);
        };

        micMediaRecorder.onstop = () => {
            stream.getTracks().forEach(t => t.stop());
            const blob = new Blob(micChunks, { type: 'audio/webm' });
            const url = URL.createObjectURL(blob);
            audioEl.src = url;
            wrap.style.display = 'block';
            audioEl.play().catch(() => {});
            statusEl.textContent = '✅ Запись завершена! Идёт воспроизведение...';
            statusEl.style.color = '#10b981';
            btn.disabled = false;
        };

        micMediaRecorder.start();
        btn.disabled = true;
        let secLeft = 3;
        statusEl.textContent = `🎙️ Говорите в микрофон: ${secLeft} сек...`;
        statusEl.style.color = '#ef4444';

        const timer = setInterval(() => {
            secLeft--;
            if (secLeft > 0) {
                statusEl.textContent = `🎙️ Говорите в микрофон: ${secLeft} сек...`;
            } else {
                clearInterval(timer);
                if (micMediaRecorder && micMediaRecorder.state !== 'inactive') {
                    micMediaRecorder.stop();
                }
            }
        }, 1000);

    } catch (err) {
        statusEl.textContent = '❌ Ошибка микрофона: ' + err.message;
        statusEl.style.color = '#ef4444';
        btn.disabled = false;
    }
}
</script>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
