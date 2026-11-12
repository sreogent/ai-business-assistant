<?php
declare(strict_types=1);

/**
 * AI Business Assistant - Рабочее место Агента Техподдержки (Support Agent Console)
 * Обработка обращений пользователей, экспресс-поиск клиента, звонки в 1 клик,
 * сброс паролей, шаблоны ответов и эскалация обращений руководству.
 */

require_once dirname(__DIR__) . '/includes/Auth.php';
require_once dirname(__DIR__) . '/includes/Mailer.php';

// Доступ строго для агентов поддержки, администраторов и владельца
$user = Auth::requireRole(['support_agent', 'admin', 'vrio_owner']);
$agentId = (int)$user['id'];
$pdo = Database::getConnection();

// AJAX: Получение истории сообщений по тикету для агента поддержки
if (isset($_GET['action']) && $_GET['action'] === 'get_messages') {
    header('Content-Type: application/json; charset=utf-8');
    $tId = (int)($_GET['ticket_id'] ?? 0);
    $tMeta = $pdo->prepare("
        SELECT t.*, 
               COALESCE(u.first_name, t.guest_name, 'Гость') as first_name, 
               COALESCE(u.last_name, '') as last_name, 
               COALESCE(u.email, t.guest_email, 'Гость без email') as client_email, 
               COALESCE(u.phone, 'Не указан') as client_phone
        FROM support_tickets t
        LEFT JOIN users u ON t.user_id = u.id
        WHERE t.id = ? LIMIT 1
    ");
    $tMeta->execute([$tId]);
    $ticketRow = $tMeta->fetch(PDO::FETCH_ASSOC);

    if (!$ticketRow) {
        echo json_encode(['success' => false, 'message' => 'Тикет не найден']);
        exit;
    }

    $msgStmt = $pdo->prepare("
        SELECT m.*, u.first_name, u.last_name, u.email, r.name as role_name
        FROM support_messages m
        LEFT JOIN users u ON m.sender_id = u.id
        LEFT JOIN roles r ON u.role_id = r.id
        WHERE m.ticket_id = ?
        ORDER BY m.id ASC
    ");
    $msgStmt->execute([$tId]);
    $msgs = $msgStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'ticket' => $ticketRow,
        'messages' => $msgs
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$error = null;
$success = null;
$foundUser = null;

// Обработка действий агента поддержки
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!Security::verifyCsrf($token)) {
        $error = "Истёк токен безопасности. Повторите попытку.";
    } else {
        $action = $_POST['action'] ?? '';
        $ticketId = (int)($_POST['ticket_id'] ?? 0);

        $tCheck = $pdo->prepare("
            SELECT t.*, 
                   COALESCE(u.email, t.guest_email) as client_email, 
                   COALESCE(u.first_name, t.guest_name, 'Гость') as first_name, 
                   COALESCE(u.last_name, '') as last_name 
            FROM support_tickets t 
            LEFT JOIN users u ON t.user_id = u.id 
            WHERE t.id = ? 
            LIMIT 1
        ");
        $tCheck->execute([$ticketId]);
        $ticket = $tCheck->fetch();

        if ($ticketId > 0 && !$ticket) {
            $error = "Тикет #{$ticketId} не найден.";
        } elseif ($ticket) {
            // 1. Быстрая смена статуса
            if ($action === 'change_status') {
                $newStatus = trim($_POST['status'] ?? '');
                $validStatuses = ['pending', 'in_review', 'transferred_admin', 'transferred_management', 'ignored', 'archive', 'closed'];

                if (in_array($newStatus, $validStatuses, true)) {
                    $assignedTo = $ticket['assigned_to'];
                    if ($newStatus === 'in_review' && empty($assignedTo)) {
                        $assignedTo = $agentId;
                    } elseif ($newStatus === 'transferred_management') {
                        $hqChanId = (int)$pdo->query("SELECT id FROM team_chat_channels WHERE channel_key = 'site_hq' LIMIT 1")->fetchColumn();
                        if ($hqChanId) {
                            $notice = "⚠️ [ТИКЕТ ПЕРЕДАН РУКОВОДСТВУ] #{$ticket['ticket_number']}: {$ticket['subject']}\nКлиент: {$ticket['client_email']}\nПередал агент: {$user['first_name']} {$user['last_name']}";
                            $pdo->prepare("INSERT INTO team_chat_messages (channel_id, sender_id, message) VALUES (?, ?, ?)")
                                ->execute([$hqChanId, $agentId, $notice]);
                        }
                    }

                    $pdo->prepare("
                        UPDATE support_tickets 
                        SET status = ?, assigned_to = ?, updated_at = NOW() 
                        WHERE id = ?
                    ")->execute([$newStatus, $assignedTo, $ticketId]);

                    Security::log($pdo, 'support_status_change', "Агент поддержки изменил статус тикета #{$ticket['ticket_number']} на: {$newStatus}", $agentId);
                    $success = "Статус тикета #{$ticket['ticket_number']} обновлен на «{$newStatus}».";
                } else {
                    $error = "Недопустимый статус обращения.";
                }
            }

            // 2. Взять обращение в работу агенту
            elseif ($action === 'take_ticket') {
                $pdo->prepare("
                    UPDATE support_tickets 
                    SET status = 'in_review', assigned_to = ?, updated_at = NOW() 
                    WHERE id = ?
                ")->execute([$agentId, $ticketId]);

                Security::log($pdo, 'support_take_ticket', "Агент #{$agentId} взял в работу тикет #{$ticket['ticket_number']}", $agentId);
                $success = "Обращение #{$ticket['ticket_number']} взято в вашу работу.";
            }

            // 3. Отправка ответа клиенту в тикет
            elseif ($action === 'send_reply') {
                $replyText = trim($_POST['reply_message'] ?? '');
                if (empty($replyText)) {
                    $error = "Текст ответа не может быть пустым.";
                } else {
                    $pdo->prepare("
                        INSERT INTO support_messages (ticket_id, sender_id, sender_type, message, is_read, created_at)
                        VALUES (?, ?, 'admin', ?, 0, NOW())
                    ")->execute([$ticketId, $agentId, $replyText]);

                    $pdo->prepare("
                        UPDATE support_tickets 
                        SET last_reply_at = NOW(), updated_at = NOW(), assigned_to = ? 
                        WHERE id = ?
                    ")->execute([$agentId, $ticketId]);

                    if (!empty($ticket['client_email'])) {
                        $subj = "Ответ службы поддержки по обращению #{$ticket['ticket_number']}";
                        $html = "
                            <div style='font-family:sans-serif; color:#1f2937; line-height:1.6;'>
                                <h3 style='color:#4f46e5;'>Ответ по обращению #{$ticket['ticket_number']}</h3>
                                <p>Здравствуйте, <strong>" . htmlspecialchars($ticket['first_name'] ?? 'Пользователь') . "</strong>!</p>
                                <p>Специалист службы поддержки ответил на ваш вопрос:</p>
                                <div style='background:#f3f4f6; border-left:4px solid #4f46e5; padding:12px 16px; margin:16px 0; border-radius:4px;'>
                                    " . nl2br(htmlspecialchars($replyText)) . "
                                </div>
                                <p style='font-size:13px; color:#6b7280;'>Вы можете продолжить переписку в личном кабинете на сайте.</p>
                            </div>
                        ";
                        Mailer::sendEmail($ticket['client_email'], $ticket['first_name'] ?? 'Клиент', $subj, $html);
                    }

                    Security::log($pdo, 'support_reply_sent', "Агент ответил на тикет #{$ticket['ticket_number']}", $agentId);
                    $success = "Ответ клиенту по тикету #{$ticket['ticket_number']} успешно отправлен.";
                }
            }

            // 4. Сохранение служебной заметки агента
            elseif ($action === 'save_notes') {
                $notes = trim($_POST['admin_notes'] ?? '');
                $pdo->prepare("UPDATE support_tickets SET admin_notes = ? WHERE id = ?")->execute([$notes, $ticketId]);
                $success = "Служебная заметка к тикету сохранена.";
            }
        }

        // 5. Поиск клиента (User Lookup)
        if ($action === 'lookup_client') {
            $queryUser = trim($_POST['user_query'] ?? '');
            if (!empty($queryUser)) {
                $uStmt = $pdo->prepare("
                    SELECT u.*, r.name as role_name, o.name as org_name, s.tariff_id, t.name as tariff_name
                    FROM users u
                    LEFT JOIN roles r ON u.role_id = r.id
                    LEFT JOIN organizations o ON o.user_id = u.id AND o.is_active = 1
                    LEFT JOIN subscriptions s ON s.user_id = u.id AND s.status = 'active'
                    LEFT JOIN tariffs t ON s.tariff_id = t.id
                    WHERE u.email = ? OR u.phone = ? OR u.id = ?
                    LIMIT 1
                ");
                $uStmt->execute([$queryUser, $queryUser, (int)$queryUser]);
                $foundUser = $uStmt->fetch(PDO::FETCH_ASSOC);
                if (!$foundUser) {
                    $error = "Пользователь с запросом «{$queryUser}» не найден.";
                }
            }
        }

        // 6. Сброс пароля клиента агентом поддержки
        if ($action === 'support_reset_pass') {
            $targetUid = (int)($_POST['target_user_id'] ?? 0);
            if ($targetUid > 0) {
                $newPass = "TempPass" . random_int(100, 999) . "!";
                $newHash = password_hash($newPass, PASSWORD_BCRYPT);
                $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$newHash, $targetUid]);
                Security::log($pdo, 'support_reset_password', "Агент поддержки сбросил пароль пользователю #{$targetUid}", $agentId);
                $success = "🔑 Пароль пользователя #{$targetUid} успешно сброшен на временный: <strong style='font-family:monospace; color:#10b981;'>{$newPass}</strong> (передайте клиенту).";
            }
        }

        // 7. Блокировка / разблокировка аккаунта клиента
        if ($action === 'support_toggle_status') {
            $targetUid = (int)($_POST['target_user_id'] ?? 0);
            $newSt = trim($_POST['new_status'] ?? 'active');
            if ($targetUid > 0 && in_array($newSt, ['active', 'blocked'], true)) {
                $pdo->prepare("UPDATE users SET status = ? WHERE id = ?")->execute([$newSt, $targetUid]);
                Security::log($pdo, 'support_toggle_user_status', "Агент поддержки изменил статус пользователя #{$targetUid} на {$newSt}", $agentId);
                $success = "Статус пользователя #{$targetUid} изменен на «{$newSt}».";
            }
        }
    }
}

// Фильтры списка тикетов
$statusFilter = trim($_GET['status'] ?? '');
$categoryFilter = trim($_GET['category'] ?? '');
$search = trim($_GET['q'] ?? '');

$where = ["1=1"];
$params = [];

if (!empty($statusFilter)) {
    if ($statusFilter === 'my_in_progress') {
        $where[] = "t.assigned_to = ? AND t.status = 'in_review'";
        $params[] = $agentId;
    } else {
        $where[] = "t.status = ?";
        $params[] = $statusFilter;
    }
}

if (!empty($categoryFilter)) {
    if ($categoryFilter === 'complaint') {
        $where[] = "(t.category LIKE '%жалоб%' OR t.category LIKE '%бар%' OR t.category LIKE '%касс%' OR t.category LIKE '%баг%')";
    } else {
        $where[] = "t.category = ?";
        $params[] = $categoryFilter;
    }
}

if (!empty($search)) {
    $where[] = "(t.ticket_number LIKE ? OR t.subject LIKE ? OR u.email LIKE ? OR u.first_name LIKE ? OR t.guest_name LIKE ? OR t.guest_email LIKE ?)";
    $like = "%{$search}%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$whereSql = implode(' AND ', $where);

// Список тикетов
$ticketsStmt = $pdo->prepare("
    SELECT t.*, 
           COALESCE(u.email, t.guest_email, 'Гость без email') as client_email, 
           COALESCE(u.first_name, t.guest_name, 'Гость') as first_name, 
           COALESCE(u.last_name, '') as last_name, 
           COALESCE(u.phone, 'Не указан') as client_phone,
           agent.first_name as agent_first_name, agent.last_name as agent_last_name
    FROM support_tickets t
    LEFT JOIN users u ON t.user_id = u.id
    LEFT JOIN users agent ON t.assigned_to = agent.id
    WHERE {$whereSql}
    ORDER BY 
        CASE 
            WHEN t.priority = 'urgent' AND t.status = 'pending' THEN 1
            WHEN t.status = 'pending' THEN 2
            WHEN t.status = 'in_review' THEN 3
            ELSE 4
        END ASC, t.id DESC
");
$ticketsStmt->execute($params);
$tickets = $ticketsStmt->fetchAll();

// Статистика для шапки
$cntPending = (int)$pdo->query("SELECT COUNT(*) FROM support_tickets WHERE status = 'pending'")->fetchColumn();
$cntMy = (int)$pdo->query("SELECT COUNT(*) FROM support_tickets WHERE assigned_to = {$agentId} AND status = 'in_review'")->fetchColumn();
$cntComplaints = (int)$pdo->query("SELECT COUNT(*) FROM support_tickets WHERE category LIKE '%жалоб%' OR category LIKE '%бар%' OR category LIKE '%касс%' OR category LIKE '%баг%'")->fetchColumn();
$cntClosed = (int)$pdo->query("SELECT COUNT(*) FROM support_tickets WHERE status IN ('closed', 'archive')")->fetchColumn();

// Статусные бейджи
$statusMeta = [
    'pending'                => ['label' => 'Ожидание',           'badge' => 'badge-danger',    'color' => '#ef4444', 'icon' => 'fa-clock'],
    'in_review'              => ['label' => 'В работе',           'badge' => 'badge-warning',   'color' => '#f59e0b', 'icon' => 'fa-spinner'],
    'transferred_admin'      => ['label' => 'Передано коллеге',   'badge' => 'badge-primary',   'color' => '#6366f1', 'icon' => 'fa-user-friends'],
    'transferred_management' => ['label' => 'Передано руководству','badge' => 'badge-warning',  'color' => '#d97706', 'icon' => 'fa-crown'],
    'ignored'                => ['label' => 'Игнор',              'badge' => 'badge-secondary', 'color' => '#9ca3af', 'icon' => 'fa-ban'],
    'closed'                 => ['label' => 'Рассмотрено / Решено','badge' => 'badge-success',   'color' => '#10b981', 'icon' => 'fa-check-circle'],
    'archive'                => ['label' => 'В архиве',           'badge' => 'badge-secondary', 'color' => '#6b7280', 'icon' => 'fa-archive'],
];

$priorityMeta = [
    'urgent' => ['label' => 'Срочный',  'color' => '#ef4444', 'icon' => 'fa-fire'],
    'high'   => ['label' => 'Высокий',  'color' => '#f97316', 'icon' => 'fa-exclamation-triangle'],
    'medium' => ['label' => 'Обычный',  'color' => '#3b82f6', 'icon' => 'fa-info-circle'],
    'low'    => ['label' => 'Низкий',   'color' => '#9ca3af', 'icon' => 'fa-arrow-down'],
];

$csrfToken = Security::csrfToken();
$pageTitle = "Консоль Агента Поддержки | AI Business Assistant";
require dirname(__DIR__) . '/includes/header.php';
?>

<!-- Верхний баннер и статус агента -->
<div style="margin-bottom:24px; display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px;">
    <div>
        <div style="display:flex; align-items:center; gap:10px; margin-bottom:6px;">
            <span class="badge" style="background:linear-gradient(135deg,#3b82f6,#2563eb); color:#fff; font-size:12px; font-weight:700; padding:4px 10px;">
                <i class="fas fa-headset"></i> Консоль Агента Поддержки
            </span>
            <span class="badge badge-success" style="font-size:11px; padding:3px 8px;">
                <i class="fas fa-circle" style="font-size:8px;"></i> На линии
            </span>
        </div>
        <h1 style="margin:0; font-size:24px; font-weight:800; color:var(--text-primary);">
            Рабочее место специалиста поддержки
        </h1>
        <p style="margin:4px 0 0; font-size:13px; color:var(--text-muted);">
            Оперативная помощь клиентам, экспресс-управление доступом, разбор обращений и звонки в 1 клик
        </p>
    </div>

    <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <a href="/chat.php?channel=support_team" class="btn btn-secondary btn-sm">
            <i class="fas fa-comments"></i> Чат поддержки
        </a>
        <a href="/chat.php?channel=site_hq" class="btn btn-secondary btn-sm">
            <i class="fas fa-crown"></i> Связь с HQ
        </a>
        <a href="/direct_messages.php" class="btn btn-primary btn-sm">
            <i class="fas fa-phone-alt"></i> ЛС &amp; Звонки
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

<!-- Метрики поддержки -->
<div class="stats-grid" style="margin-bottom:24px;">
    <div class="stat-card" style="border-left:4px solid #ef4444;">
        <div class="stat-icon" style="background:rgba(239,68,68,0.15); color:#ef4444;">
            <i class="fas fa-clock"></i>
        </div>
        <div>
            <div class="stat-title">Ожидают ответа</div>
            <div class="stat-value" style="color:#ef4444;"><?= $cntPending ?></div>
            <small class="text-muted"><a href="?status=pending">Показать новые &rarr;</a></small>
        </div>
    </div>

    <div class="stat-card" style="border-left:4px solid #f59e0b;">
        <div class="stat-icon" style="background:rgba(245,158,11,0.15); color:#f59e0b;">
            <i class="fas fa-user-check"></i>
        </div>
        <div>
            <div class="stat-title">В моей работе</div>
            <div class="stat-value" style="color:#f59e0b;"><?= $cntMy ?></div>
            <small class="text-muted"><a href="?status=my_in_progress">Мои тикеты &rarr;</a></small>
        </div>
    </div>

    <div class="stat-card" style="border-left:4px solid #f97316;">
        <div class="stat-icon" style="background:rgba(249,115,22,0.15); color:#f97316;">
            <i class="fas fa-exclamation-triangle"></i>
        </div>
        <div>
            <div class="stat-title">Жалобы / Инциденты</div>
            <div class="stat-value" style="color:#f97316;"><?= $cntComplaints ?></div>
            <small class="text-muted"><a href="?category=complaint">Бар, касса, сервис &rarr;</a></small>
        </div>
    </div>

    <div class="stat-card" style="border-left:4px solid #10b981;">
        <div class="stat-icon" style="background:rgba(16,185,129,0.15); color:#10b981;">
            <i class="fas fa-check-double"></i>
        </div>
        <div>
            <div class="stat-title">Рассмотрено / Архив</div>
            <div class="stat-value" style="color:#10b981;"><?= $cntClosed ?></div>
            <small class="text-muted"><a href="?status=closed">Решенные обращения &rarr;</a></small>
        </div>
    </div>
</div>

<!-- ИНСТРУМЕНТ ПОИСКА И ПОМОЩИ КЛИЕНТУ (CLIENT LOOKUP & FAST ACTIONS) -->
<div class="card" style="padding:16px 20px; margin-bottom:24px;">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:<?= !empty($foundUser) ? '14px' : '0' ?>;">
        <div>
            <h3 style="margin:0; font-size:15px; font-weight:800; display:flex; align-items:center; gap:8px;">
                <i class="fas fa-search-plus" style="color:#3b82f6;"></i> Экспресс-поиск клиента и управление доступом
            </h3>
            <span style="font-size:12px; color:var(--text-muted);">
                Мгновенный поиск по Email, телефону или User ID для звонка или сброса пароля
            </span>
        </div>
        <form method="POST" action="/support/dashboard.php" style="margin:0; display:flex; gap:8px; align-items:center;">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" value="lookup_client">
            <input type="text" name="user_query" class="form-control form-control-sm" placeholder="Email, телефон или ID..." style="width:240px; font-size:12px;" required value="<?= htmlspecialchars($_POST['user_query'] ?? '') ?>">
            <button type="submit" class="btn btn-secondary btn-sm" style="font-weight:700;">
                <i class="fas fa-search"></i> Найти
            </button>
        </form>
    </div>

    <?php if (!empty($foundUser)): ?>
    <div style="background:var(--bg-input); border:1px solid var(--border-color); border-radius:10px; padding:14px;">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
            <div>
                <div style="font-size:15px; font-weight:800; color:var(--text-primary);">
                    <?= htmlspecialchars(($foundUser['first_name'] ?? '') . ' ' . ($foundUser['last_name'] ?? '')) ?>
                    <span style="font-size:12px; font-weight:400; color:var(--text-muted);">(ID #<?= $foundUser['id'] ?>)</span>
                    <span class="badge <?= $foundUser['status'] === 'active' ? 'badge-success' : 'badge-danger' ?>" style="font-size:11px; margin-left:6px;">
                        <?= $foundUser['status'] === 'active' ? 'Активен' : 'Заблокирован' ?>
                    </span>
                </div>
                <div style="font-size:12px; color:var(--text-muted); margin-top:4px;">
                    Email: <strong style="color:var(--text-primary);"><?= htmlspecialchars($foundUser['email']) ?></strong> &bull;
                    Организация: <strong><?= htmlspecialchars($foundUser['org_name'] ?? 'Не привязана') ?></strong> &bull;
                    Тариф: <strong style="color:#10b981;"><?= htmlspecialchars($foundUser['tariff_name'] ?? 'Базовый') ?></strong>
                </div>
            </div>

            <!-- Функциональные кнопки по клиенту -->
            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <a href="/direct_messages.php?call=<?= (int)$foundUser['id'] ?>" class="btn btn-success btn-sm" style="font-weight:700;">
                    <i class="fas fa-phone-alt"></i> Позвонить клиенту
                </a>
                <a href="/direct_messages.php?user=<?= (int)$foundUser['id'] ?>" class="btn btn-primary btn-sm" style="font-weight:700;">
                    <i class="fas fa-comment-dots"></i> Написать в ЛС
                </a>
                
                <!-- Сброс пароля -->
                <form method="POST" action="/support/dashboard.php" style="margin:0;" onsubmit="return confirm('Сбросить пароль пользователя на временный?');">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="action" value="support_reset_pass">
                    <input type="hidden" name="target_user_id" value="<?= (int)$foundUser['id'] ?>">
                    <button type="submit" class="btn btn-secondary btn-sm" style="color:#f59e0b; font-weight:600;">
                        <i class="fas fa-key"></i> Сбросить пароль
                    </button>
                </form>

                <!-- Блокировка/разблокировка -->
                <form method="POST" action="/support/dashboard.php" style="margin:0;">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="action" value="support_toggle_status">
                    <input type="hidden" name="target_user_id" value="<?= (int)$foundUser['id'] ?>">
                    <input type="hidden" name="new_status" value="<?= $foundUser['status'] === 'active' ? 'blocked' : 'active' ?>">
                    <button type="submit" class="btn btn-secondary btn-sm" style="color:<?= $foundUser['status'] === 'active' ? '#ef4444' : '#10b981' ?>; font-weight:600;">
                        <i class="fas fa-<?= $foundUser['status'] === 'active' ? 'user-slash' : 'user-check' ?>"></i>
                        <?= $foundUser['status'] === 'active' ? 'Заблокировать' : 'Разблокировать' ?>
                    </button>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Панель фильтров и поиска -->
<div class="card" style="padding:16px 20px; margin-bottom:24px; background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg);">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:14px;">
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <a href="/support/dashboard.php" class="btn btn-sm <?= empty($statusFilter) && empty($categoryFilter) ? 'btn-primary' : 'btn-secondary' ?>">
                Все тикеты
            </a>
            <a href="?status=pending" class="btn btn-sm <?= $statusFilter === 'pending' ? 'btn-primary' : 'btn-secondary' ?>">
                <i class="fas fa-clock"></i> Ожидают (<?= $cntPending ?>)
            </a>
            <a href="?status=my_in_progress" class="btn btn-sm <?= $statusFilter === 'my_in_progress' ? 'btn-primary' : 'btn-secondary' ?>">
                <i class="fas fa-user-check"></i> Мои в работе (<?= $cntMy ?>)
            </a>
            <a href="?category=complaint" class="btn btn-sm <?= $categoryFilter === 'complaint' ? 'btn-primary' : 'btn-secondary' ?>" style="<?= $categoryFilter === 'complaint' ? '' : 'color:#f97316;' ?>">
                <i class="fas fa-exclamation-triangle"></i> Жалобы на бар/кассу (<?= $cntComplaints ?>)
            </a>
            <a href="?status=transferred_management" class="btn btn-sm <?= $statusFilter === 'transferred_management' ? 'btn-primary' : 'btn-secondary' ?>">
                <i class="fas fa-crown"></i> Передано руководству
            </a>
            <a href="?status=closed" class="btn btn-sm <?= $statusFilter === 'closed' ? 'btn-primary' : 'btn-secondary' ?>">
                <i class="fas fa-check-circle"></i> Рассмотренные
            </a>
        </div>

        <form method="GET" action="/support/dashboard.php" style="margin:0; display:flex; gap:8px;">
            <input type="text" name="q" class="form-control form-control-sm" placeholder="Номер тикета, тема, email..." value="<?= htmlspecialchars($search) ?>" style="width:220px; font-size:12px;">
            <button type="submit" class="btn btn-secondary btn-sm">
                <i class="fas fa-search"></i>
            </button>
            <?php if (!empty($search) || !empty($statusFilter) || !empty($categoryFilter)): ?>
                <a href="/support/dashboard.php" class="btn btn-secondary btn-sm" title="Сбросить фильтры">
                    <i class="fas fa-times"></i>
                </a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Список обращений -->
<div class="card" style="padding:0; overflow:hidden; border:1px solid var(--border-color); border-radius:var(--radius-lg);">
    <div style="padding:16px 20px; border-bottom:1px solid var(--border-color); background:var(--bg-input); display:flex; justify-content:space-between; align-items:center;">
        <h3 style="margin:0; font-size:16px; font-weight:700;">
            Очередь обращений клиентов (<?= count($tickets) ?>)
        </h3>
        <span style="font-size:12px; color:var(--text-muted);">
            Приоритетные и новые запросы отображаются в начале списка
        </span>
    </div>

    <?php if (empty($tickets)): ?>
        <div style="padding:50px 20px; text-align:center; color:var(--text-muted);">
            <i class="fas fa-inbox" style="font-size:48px; opacity:0.3; margin-bottom:12px;"></i>
            <p style="margin:0; font-size:14px;">Обращений по выбранному фильтру не найдено.</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table" style="margin:0; font-size:13px;">
                <thead>
                    <tr style="background:var(--bg-card); color:var(--text-muted); font-size:11px; text-transform:uppercase; letter-spacing:0.5px;">
                        <th style="padding:12px 16px;">Тикет</th>
                        <th style="padding:12px 16px;">Клиент / Контакты</th>
                        <th style="padding:12px 16px;">Тема и категория</th>
                        <th style="padding:12px 16px;">Приоритет</th>
                        <th style="padding:12px 16px;">Статус</th>
                        <th style="padding:12px 16px;">Ответственный</th>
                        <th style="padding:12px 16px; text-align:right;">Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tickets as $t): 
                        $st = $statusMeta[$t['status']] ?? ['label' => $t['status'], 'badge' => 'badge-secondary', 'color' => '#6b7280', 'icon' => 'fa-info-circle'];
                        $pr = $priorityMeta[$t['priority']] ?? ['label' => $t['priority'], 'color' => '#3b82f6', 'icon' => 'fa-info-circle'];
                        $isComplaint = (stripos($t['category'], 'жалоб') !== false || stripos($t['category'], 'бар') !== false || stripos($t['category'], 'касс') !== false);
                    ?>
                    <tr style="border-bottom:1px solid var(--border-color); vertical-align:middle; background:<?= $isComplaint ? 'rgba(239,68,68,0.03)' : 'transparent' ?>;">
                        <td style="padding:14px 16px;">
                            <span style="font-family:monospace; font-weight:700; color:var(--text-primary);">
                                #<?= htmlspecialchars($t['ticket_number']) ?>
                            </span>
                            <div style="font-size:11px; color:var(--text-muted); margin-top:2px;">
                                <?= date('d.m H:i', strtotime($t['created_at'])) ?>
                            </div>
                        </td>

                        <td style="padding:14px 16px;">
                            <div style="font-weight:700; color:var(--text-primary);">
                                <?= htmlspecialchars(($t['first_name'] ?? '') . ' ' . ($t['last_name'] ?? '')) ?: 'Аноним' ?>
                            </div>
                            <div style="font-size:11px; color:var(--text-muted); margin-top:2px;">
                                <?= htmlspecialchars($t['client_email'] ?? '') ?>
                                <?php if (!empty($t['client_phone'])): ?>
                                    &bull; <?= htmlspecialchars($t['client_phone']) ?>
                                <?php endif; ?>
                            </div>
                        </td>

                        <td style="padding:14px 16px; max-width:280px;">
                            <div style="font-weight:600; color:var(--text-primary); text-overflow:ellipsis; overflow:hidden; white-space:nowrap;" title="<?= htmlspecialchars($t['subject']) ?>">
                                <?= htmlspecialchars($t['subject']) ?>
                            </div>
                            <div style="margin-top:4px;">
                                <span class="badge" style="background:rgba(255,255,255,0.08); font-size:11px; font-weight:500;">
                                    <?= htmlspecialchars($t['category'] ?? 'Общий вопрос') ?>
                                </span>
                                <?php if ($isComplaint): ?>
                                    <span class="badge badge-danger" style="font-size:10px; margin-left:4px;">
                                        <i class="fas fa-exclamation-circle"></i> Жалоба
                                    </span>
                                <?php endif; ?>
                            </div>
                        </td>

                        <td style="padding:14px 16px;">
                            <span style="color:<?= $pr['color'] ?>; font-weight:700; font-size:12px; display:inline-flex; align-items:center; gap:5px;">
                                <i class="fas <?= $pr['icon'] ?>"></i> <?= $pr['label'] ?>
                            </span>
                        </td>

                        <td style="padding:14px 16px;">
                            <span class="badge <?= $st['badge'] ?>" style="font-size:11px; display:inline-flex; align-items:center; gap:5px; padding:4px 8px;">
                                <i class="fas <?= $st['icon'] ?>"></i> <?= $st['label'] ?>
                            </span>
                        </td>

                        <td style="padding:14px 16px;">
                            <?php if ($t['assigned_to']): ?>
                                <span style="font-size:12px; font-weight:600; color:var(--text-primary);">
                                    <?= htmlspecialchars($t['agent_first_name'] . ' ' . $t['agent_last_name']) ?>
                                </span>
                            <?php else: ?>
                                <span style="font-size:12px; color:var(--text-muted); font-style:italic;">Не назначен</span>
                            <?php endif; ?>
                        </td>

                        <td style="padding:14px 16px; text-align:right;">
                            <div style="display:inline-flex; gap:6px; align-items:center;">
                                <?php if (!empty($t['user_id'])): ?>
                                    <a href="/direct_messages.php?call=<?= (int)$t['user_id'] ?>" class="btn btn-secondary btn-sm" title="Позвонить клиенту в 1 клик" style="padding:5px 9px; font-size:12px; color:#10b981;">
                                        <i class="fas fa-phone-alt"></i>
                                    </a>
                                    <a href="/direct_messages.php?user=<?= (int)$t['user_id'] ?>" class="btn btn-secondary btn-sm" title="Написать в личные сообщения" style="padding:5px 9px; font-size:12px; color:#38bdf8;">
                                        <i class="fas fa-comment-dots"></i>
                                    </a>
                                <?php endif; ?>

                                <?php if (!$t['assigned_to'] || $t['status'] === 'pending'): ?>
                                    <form method="POST" action="/support/dashboard.php" style="margin:0;">
                                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                        <input type="hidden" name="action" value="take_ticket">
                                        <input type="hidden" name="ticket_id" value="<?= $t['id'] ?>">
                                        <button type="submit" class="btn btn-primary btn-sm" style="padding:5px 10px; font-size:12px;">
                                            В работу
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <button type="button" class="btn btn-secondary btn-sm" style="padding:5px 12px; font-size:12px;" onclick="openTicketModal(<?= $t['id'] ?>)">
                                    <i class="fas fa-envelope-open-text"></i> Открыть
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- МОДАЛЬНОЕ ОКНО РАБОТЫ С ТИКЕТОМ -->
<div id="ticketModal" style="display:none; position:fixed; inset:0; z-index:9999; background:rgba(0,0,0,0.65); backdrop-filter:blur(4px); align-items:center; justify-content:center; padding:20px;">
    <div style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:14px; width:100%; max-width:850px; max-height:90vh; display:flex; flex-direction:column; overflow:hidden; box-shadow:0 20px 40px rgba(0,0,0,0.5);">
        
        <!-- Заголовок модалки -->
        <div style="padding:16px 20px; border-bottom:1px solid var(--border-color); background:var(--bg-input); display:flex; justify-content:space-between; align-items:center; flex-shrink:0;">
            <div>
                <div style="display:flex; align-items:center; gap:8px;">
                    <span id="modalTicketNum" style="font-family:monospace; font-weight:800; font-size:15px; color:#6366f1;">#</span>
                    <span id="modalStatusBadge" class="badge"></span>
                </div>
                <h3 id="modalSubject" style="margin:4px 0 0; font-size:16px; font-weight:800; color:var(--text-primary);">Тема тикета</h3>
            </div>
            <button type="button" class="btn btn-secondary btn-sm" onclick="closeTicketModal()" style="border-radius:50%; width:32px; height:32px; padding:0; display:flex; align-items:center; justify-content:center;">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <!-- История диалога -->
        <div id="modalChatBox" style="flex:1; overflow-y:auto; padding:20px; display:flex; flex-direction:column; gap:12px; background:var(--bg-card);">
            <div style="text-align:center; color:var(--text-muted); padding:30px;">
                <i class="fas fa-spinner fa-spin" style="font-size:24px;"></i>
                <div style="margin-top:8px;">Загрузка сообщений...</div>
            </div>
        </div>

        <!-- Быстрые шаблоны ответов (Canned Responses) -->
        <div style="padding:10px 20px; background:var(--bg-input); border-top:1px solid var(--border-color); flex-shrink:0;">
            <div style="font-size:11px; font-weight:700; color:var(--text-muted); margin-bottom:6px; text-transform:uppercase;">
                Быстрые шаблоны ответов:
            </div>
            <div style="display:flex; gap:6px; flex-wrap:wrap;">
                <button type="button" class="btn btn-secondary btn-sm" style="font-size:11px; padding:2px 7px;" onclick="insertCanned('Здравствуйте! Ваше обращение принято в работу специалистом службы поддержки. Мы уже разбираемся с вопросом.')">
                    👋 Взяли в работу
                </button>
                <button type="button" class="btn btn-secondary btn-sm" style="font-size:11px; padding:2px 7px;" onclick="insertCanned('Здравствуйте! Вы можете активировать бесплатный тестовый период на 7 дней в один клик на главной странице дашборда.')">
                    🎁 Тестовый период
                </button>
                <button type="button" class="btn btn-secondary btn-sm" style="font-size:11px; padding:2px 7px;" onclick="insertCanned('Ваша жалоба по работе бара/кассы передана управляющему и техническому отделу. Проблема будет решена.')">
                    ⚠️ Жалоба на кассу
                </button>
                <button type="button" class="btn btn-secondary btn-sm" style="font-size:11px; padding:2px 7px;" onclick="insertCanned('Вопрос успешно решен. Если у вас возникнут дополнительные вопросы, мы всегда рады помочь!')">
                    ✅ Вопрос решен
                </button>
                <button type="button" class="btn btn-secondary btn-sm" style="font-size:11px; padding:2px 7px;" onclick="insertCanned('Здравствуйте! Для подбора программ господдержки и грантов перейдите в раздел «AI-Ассистент» и выберите «Гранты & Фонд Даму». Система подготовит полный пакет расчётов.')">
                    💰 Гранты &amp; Субсидии
                </button>
                <button type="button" class="btn btn-secondary btn-sm" style="font-size:11px; padding:2px 7px;" onclick="insertCanned('Здравствуйте! Для настройки онлайн-кассы и подключения к ОФД перейдите в раздел «Онлайн-касса (POS)». При необходимости — передадим заявку техническому специалисту.')">
                    🧾 Настройка кассы
                </button>
                <button type="button" class="btn btn-secondary btn-sm" style="font-size:11px; padding:2px 7px;" onclick="insertCanned('Уточните, пожалуйста, вашу проблему подробнее. При необходимости — передадим обращение руководству для оперативного решения.')">
                    🔄 Уточнить детали
                </button>
            </div>
        </div>

        <!-- Форма отправки ответа -->
        <form method="POST" action="/support/dashboard.php" style="margin:0; flex-shrink:0;">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" value="send_reply">
            <input type="hidden" name="ticket_id" id="modalTicketId" value="0">

            <div style="padding:14px 20px; background:var(--bg-card); border-top:1px solid var(--border-color);">
                <div class="form-group mb-2">
                    <textarea name="reply_message" id="modalReplyText" class="form-control" rows="3" required placeholder="Введите текст ответа клиенту (будет сохранен в истории и отправлен на email)..." style="font-size:13px;"></textarea>
                </div>

                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                    <div style="display:flex; gap:8px;">
                        <button type="button" class="btn btn-secondary btn-sm" onclick="modalChangeStatus('closed')" style="color:#10b981; font-weight:600;">
                            <i class="fas fa-check-circle"></i> Закрыть тикет
                        </button>
                        <button type="button" class="btn btn-secondary btn-sm" onclick="modalChangeStatus('transferred_management')" style="color:#f59e0b; font-weight:600;">
                            <i class="fas fa-crown"></i> Передать в HQ
                        </button>
                    </div>

                    <button type="submit" class="btn btn-primary btn-sm" style="font-weight:700; padding:6px 20px;">
                        <i class="fas fa-paper-plane"></i> Отправить ответ
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
function insertCanned(text) {
    const el = document.getElementById('modalReplyText');
    if (el) {
        el.value = (el.value ? el.value + "\n\n" : "") + text;
        el.focus();
    }
}

async function openTicketModal(ticketId) {
    const modal = document.getElementById('ticketModal');
    const box = document.getElementById('modalChatBox');
    const numEl = document.getElementById('modalTicketNum');
    const subjEl = document.getElementById('modalSubject');
    const idInput = document.getElementById('modalTicketId');

    idInput.value = ticketId;
    modal.style.display = 'flex';
    box.innerHTML = '<div style="text-align:center; padding:30px; color:var(--text-muted);"><i class="fas fa-spinner fa-spin"></i> Загрузка...</div>';

    try {
        const resp = await fetch(`/support/dashboard.php?action=get_messages&ticket_id=${ticketId}`);
        const data = await resp.json();

        if (!data.success) {
            box.innerHTML = `<div class="alert alert-danger">${data.message || 'Ошибка загрузки'}</div>`;
            return;
        }

        numEl.textContent = '#' + data.ticket.ticket_number;
        subjEl.textContent = data.ticket.subject;

        if (data.messages.length === 0) {
            box.innerHTML = '<div style="text-align:center; color:var(--text-muted); padding:20px;">История сообщений пуста</div>';
        } else {
            box.innerHTML = data.messages.map(m => {
                const isAdmin = (m.sender_type === 'admin' || m.sender_type === 'support');
                const senderName = (m.first_name ? m.first_name + ' ' + (m.last_name || '') : 'Пользователь');
                const timeStr = new Date(m.created_at).toLocaleString('ru-RU');
                return `
                    <div style="display:flex; flex-direction:column; align-items:${isAdmin ? 'flex-end' : 'flex-start'};">
                        <div style="font-size:11px; color:var(--text-muted); margin-bottom:4px;">
                            ${isAdmin ? '👨‍💻 Служба поддержки' : '👤 ' + senderName} &bull; ${timeStr}
                        </div>
                        <div style="max-width:80%; padding:10px 14px; border-radius:10px; font-size:13px; line-height:1.5; ${isAdmin ? 'background:#4f46e5; color:#fff;' : 'background:var(--bg-input); border:1px solid var(--border-color); color:var(--text-primary);'}">
                            ${m.message.replace(/\n/g, '<br>')}
                        </div>
                    </div>
                `;
            }).join('');
            box.scrollTop = box.scrollHeight;
        }
    } catch(err) {
        box.innerHTML = `<div class="alert alert-danger">Ошибка сети: ${err.message}</div>`;
    }
}

function closeTicketModal() {
    document.getElementById('ticketModal').style.display = 'none';
}

function modalChangeStatus(status) {
    const tId = document.getElementById('modalTicketId').value;
    if (!tId || tId === '0') return;

    if (confirm(`Изменить статус тикета на «${status}»?`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '/support/dashboard.php';

        const csrf = document.createElement('input');
        csrf.type = 'hidden';
        csrf.name = 'csrf_token';
        csrf.value = <?= json_encode($csrfToken) ?>;
        form.appendChild(csrf);

        const act = document.createElement('input');
        act.type = 'hidden';
        act.name = 'action';
        act.value = 'change_status';
        form.appendChild(act);

        const tid = document.createElement('input');
        tid.type = 'hidden';
        tid.name = 'ticket_id';
        tid.value = tId;
        form.appendChild(tid);

        const st = document.createElement('input');
        st.type = 'hidden';
        st.name = 'status';
        st.value = status;
        form.appendChild(st);

        document.body.appendChild(form);
        form.submit();
    }
}
</script>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
