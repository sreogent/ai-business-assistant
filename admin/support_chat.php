<?php
declare(strict_types=1);

/**
 * AI Business Assistant - Admin Support Chat & Ticket Management
 * Детальный чат с пользователем, отправка ответов с email-уведомлением,
 * управление статусами («Рассмотрено», «Архив», «Игнор», «Ожидание», «Передано другому администратору», «Передано руководству» и т.д.)
 */

require_once dirname(__DIR__) . '/includes/Auth.php';
require_once dirname(__DIR__) . '/includes/Mailer.php';

$admin = Auth::requireRole(['support_agent', 'admin', 'vrio_owner']);
$adminId = (int)$admin['id'];
$pdo = Database::getConnection();

$ticketId = (int)($_GET['ticket_id'] ?? 0);
$ticketNum = trim($_GET['ticket'] ?? '');

if ($ticketId <= 0 && !empty($ticketNum)) {
    $tLookup = $pdo->prepare("SELECT id FROM support_tickets WHERE ticket_number = ? LIMIT 1");
    $tLookup->execute([$ticketNum]);
    $ticketId = (int)$tLookup->fetchColumn();
}

$stmt = $pdo->prepare("
    SELECT t.*, 
           u.first_name, u.last_name, u.email, u.phone, u.created_at AS user_registered_at,
           adm.first_name AS admin_first_name, adm.last_name AS admin_last_name,
           org.name AS org_name, org.inn AS org_inn, org.business_type AS org_type
    FROM support_tickets t
    LEFT JOIN users u ON t.user_id = u.id
    LEFT JOIN users adm ON t.assigned_to = adm.id
    LEFT JOIN organizations org ON (org.user_id = u.id OR org.director_email = u.email)
    WHERE t.id = ? 
    LIMIT 1
");
$stmt->execute([$ticketId]);
$ticket = $stmt->fetch();

if (!$ticket) {
    header("Location: /admin/support.php");
    exit;
}

// Список администраторов и специалистов поддержки для передачи тикета
$adminList = $pdo->query("
    SELECT u.id, u.first_name, u.last_name, u.email 
    FROM users u 
    INNER JOIN roles r ON u.role_id = r.id 
    WHERE r.name IN ('admin', 'vrio_owner', 'support_agent')
    ORDER BY u.first_name ASC
")->fetchAll();

$cfg = $pdo->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$baseUrl = rtrim($cfg['app_base_url'] ?? 'http://127.0.0.1:8000', '/');

$error = null;
$success = null;

// Обработка действий
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!Security::verifyCsrf($token)) {
        $error = "Истёк токен безопасности. Повторите попытку.";
    } else {
        $action = $_POST['action'] ?? '';

        // 1. Отправка ответа в чат поддержки
        if ($action === 'send_reply') {
            $replyMessage = trim($_POST['message'] ?? '');
            $newStatus = trim($_POST['change_status'] ?? '');

            if (empty($replyMessage)) {
                $error = "Пожалуйста, введите текст сообщения.";
            } else {
                // Добавляем сообщение в базу
                $pdo->prepare("
                    INSERT INTO support_messages (ticket_id, sender_id, sender_type, message, is_read)
                    VALUES (?, ?, 'admin', ?, 0)
                ")->execute([$ticketId, $adminId, $replyMessage]);

                // Обновляем статус и ответственного если указан
                $updateStatus = !empty($newStatus) ? $newStatus : 'in_review';
                $assigned = $ticket['assigned_to'] ?: $adminId;

                $pdo->prepare("
                    UPDATE support_tickets 
                    SET last_reply_at = NOW(), status = ?, assigned_to = ?
                    WHERE id = ?
                ")->execute([$updateStatus, $assigned, $ticketId]);

                // Отправляем email-уведомление пользователю
                $statusTitles = [
                    'pending'                => 'Ожидание ответа',
                    'in_review'              => 'Рассмотрено / В работе',
                    'transferred_admin'      => 'Передано другому администратору',
                    'transferred_management' => 'Передано руководству',
                    'ignored'                => 'Игнор',
                    'archive'                => 'Архив',
                    'closed'                 => 'Закрыто',
                ];
                $statusTitle = $statusTitles[$updateStatus] ?? $updateStatus;
                $userName = trim(($ticket['first_name'] ?? '') . ' ' . ($ticket['last_name'] ?? '')) ?: 'Пользователь';

                Mailer::sendSupportReplyNotification(
                    $ticket['email'],
                    $userName,
                    $ticket['ticket_number'],
                    $ticket['subject'],
                    $replyMessage,
                    $statusTitle,
                    $baseUrl
                );

                header("Location: /admin/support_chat.php?ticket_id={$ticketId}&replied=1");
                exit;
            }
        }

        // 2. Обновление метаданных тикета (статус, ответственный, заметки, приоритет)
        if ($action === 'update_ticket_meta') {
            $newStatus = trim($_POST['status'] ?? $ticket['status']);
            $newPriority = trim($_POST['priority'] ?? $ticket['priority']);
            $newAssigned = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : null;
            $adminNotes = trim($_POST['admin_notes'] ?? '');

            $pdo->prepare("
                UPDATE support_tickets 
                SET status = ?, priority = ?, assigned_to = ?, admin_notes = ? 
                WHERE id = ?
            ")->execute([$newStatus, $newPriority, $newAssigned, $adminNotes, $ticketId]);

            $success = "Параметры тикета успешно сохранены.";
            
            // Перезагружаем данные тикета
            $stmt->execute([$ticketId]);
            $ticket = $stmt->fetch();
        }
    }
}

if (isset($_GET['replied'])) {
    $success = "Ответ успешно отправлен, пользователю направлено email-уведомление!";
}

// Отмечаем сообщения пользователя как прочитанные администратором
$pdo->prepare("UPDATE support_messages SET is_read = 1 WHERE ticket_id = ? AND sender_type = 'user'")->execute([$ticketId]);

// Загружаем сообщения
$msgStmt = $pdo->prepare("
    SELECT m.*, u.first_name, u.last_name, u.email, r.name AS role_name
    FROM support_messages m
    LEFT JOIN users u ON m.sender_id = u.id
    LEFT JOIN roles r ON u.role_id = r.id
    WHERE m.ticket_id = ?
    ORDER BY m.id ASC
");
$msgStmt->execute([$ticketId]);
$messages = $msgStmt->fetchAll();

// Статистика обращений данного пользователя
$uStatsStmt = $pdo->prepare("SELECT COUNT(*) FROM support_tickets WHERE user_id = ?");
$uStatsStmt->execute([(int)$ticket['user_id']]);
$userTotalTickets = (int)$uStatsStmt->fetchColumn();

// Статусы
$statusConfig = [
    'pending'                => ['label' => 'Ожидание',                 'badge' => 'badge-warning',   'color' => '#f59e0b', 'icon' => 'fa-clock'],
    'in_review'              => ['label' => 'Рассмотрено / В работе',   'badge' => 'badge-success',   'color' => '#10b981', 'icon' => 'fa-check-circle'],
    'transferred_admin'      => ['label' => 'Передано администратору',   'badge' => 'badge-info',      'color' => '#3b82f6', 'icon' => 'fa-user-shield'],
    'transferred_management' => ['label' => 'Передано руководству',      'badge' => 'badge-primary',   'color' => '#ec4899', 'icon' => 'fa-crown'],
    'ignored'                => ['label' => 'Игнор',                     'badge' => 'badge-danger',    'color' => '#ef4444', 'icon' => 'fa-ban'],
    'archive'                => ['label' => 'Архив',                     'badge' => 'badge-secondary', 'color' => '#6b7280', 'icon' => 'fa-archive'],
    'closed'                 => ['label' => 'Закрыто',                   'badge' => 'badge-secondary', 'color' => '#4b5563', 'icon' => 'fa-lock'],
];
$st = $statusConfig[$ticket['status']] ?? ['label' => $ticket['status'], 'badge' => 'badge-secondary', 'color' => '#6b7280', 'icon' => 'fa-info-circle'];

$csrfToken = Security::csrfToken();
$pageTitle = "Тикет #{$ticket['ticket_number']} | Техподдержка";
require dirname(__DIR__) . '/includes/header.php';
?>

<div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:20px;">
    <div>
        <a href="/admin/support.php" class="btn btn-secondary btn-sm" style="margin-bottom:8px;">
            <i class="fas fa-arrow-left"></i> Вернуться к списку тикетов
        </a>
        <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
            <h1 style="margin:0; font-size:22px; font-weight:700;">
                Тикет <span style="color:var(--primary); font-family:monospace;">#<?= htmlspecialchars($ticket['ticket_number']) ?></span>
            </h1>
            <span class="badge" style="background:<?= $st['color'] ?>20; color:<?= $st['color'] ?>; border:1px solid <?= $st['color'] ?>40; font-size:12px;">
                <i class="fas <?= $st['icon'] ?>"></i> <?= $st['label'] ?>
            </span>
        </div>
        <p style="margin:4px 0 0; color:var(--text-muted); font-size:14px;">
            Тема: <strong style="color:var(--text-primary);"><?= htmlspecialchars($ticket['subject']) ?></strong> | Категория: <?= htmlspecialchars($ticket['category']) ?>
        </p>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger" style="margin-bottom:20px;">
        <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success" style="margin-bottom:20px;">
        <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?>
    </div>
<?php endif; ?>

<div style="display:grid; grid-template-columns: 1fr 340px; gap:20px; align-items:start;">
    
    <!-- ЛЕВАЯ КОЛОНКА: ЧАТ И СООБЩЕНИЯ -->
    <div>
        <div class="card" style="padding:0; overflow:hidden; display:flex; flex-direction:column; height:680px;">
            
            <!-- Шапка чата -->
            <div style="padding:14px 20px; border-bottom:1px solid var(--border-color); background:rgba(0,0,0,0.15); display:flex; justify-content:space-between; align-items:center;">
                <div style="display:flex; align-items:center; gap:10px;">
                    <div style="width:36px; height:36px; border-radius:50%; background:linear-gradient(135deg, #6366f1, #8b5cf6); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:14px;">
                        <?= mb_strtoupper(mb_substr($ticket['first_name'] ?: 'U', 0, 1)) ?>
                    </div>
                    <div>
                        <div style="font-weight:600; font-size:14px;">
                            <?= htmlspecialchars(trim(($ticket['first_name'] ?? '') . ' ' . ($ticket['last_name'] ?? '')) ?: 'Пользователь #' . $ticket['user_id']) ?>
                        </div>
                        <div style="font-size:11px; color:var(--text-muted);">
                            Создан: <?= date('d.m.Y H:i', strtotime($ticket['created_at'])) ?>
                        </div>
                    </div>
                </div>
                <div style="font-size:12px; color:var(--text-muted);">
                    Сообщений: <strong><?= count($messages) ?></strong>
                </div>
            </div>

            <!-- Область сообщений со скроллом -->
            <div id="messagesContainer" style="flex:1; overflow-y:auto; padding:20px; display:flex; flex-direction:column; gap:16px;">
                <?php foreach ($messages as $msg): 
                    $isAdmin = ($msg['sender_type'] === 'admin');
                    $senderName = $isAdmin 
                        ? (trim(($msg['first_name'] ?? '') . ' ' . ($msg['last_name'] ?? '')) ?: 'Служба поддержки')
                        : (trim(($ticket['first_name'] ?? '') . ' ' . ($ticket['last_name'] ?? '')) ?: 'Пользователь');
                ?>
                    <div style="display:flex; flex-direction:column; align-items: <?= $isAdmin ? 'flex-end' : 'flex-start' ?>;">
                        <div style="display:flex; align-items:center; gap:8px; margin-bottom:4px; font-size:12px; color:var(--text-muted);">
                            <?php if ($isAdmin): ?>
                                <span style="color:#818cf8; font-weight:600;"><i class="fas fa-shield-alt"></i> <?= htmlspecialchars($senderName) ?> (Поддержка)</span>
                                <span>•</span>
                                <span><?= date('H:i, d.m.Y', strtotime($msg['created_at'])) ?></span>
                            <?php else: ?>
                                <span style="color:var(--text-primary); font-weight:600;"><i class="fas fa-user"></i> <?= htmlspecialchars($senderName) ?></span>
                                <span>•</span>
                                <span><?= date('H:i, d.m.Y', strtotime($msg['created_at'])) ?></span>
                            <?php endif; ?>
                        </div>

                        <div style="max-width:80%; padding:14px 18px; border-radius:14px; font-size:14px; line-height:1.6; word-break:break-word;
                            <?= $isAdmin 
                                ? 'background:linear-gradient(135deg, #4f46e5, #6366f1); color:#ffffff; border-bottom-right-radius:2px;' 
                                : 'background:var(--bg-input); color:var(--text-primary); border:1px solid var(--border-color); border-bottom-left-radius:2px;' 
                            ?>">
                            <?= nl2br(htmlspecialchars($msg['message'])) ?>
                        </div>

                        <?php if ($isAdmin): ?>
                            <div style="font-size:11px; color:var(--text-muted); margin-top:3px;">
                                <?= $msg['is_read'] ? '<i class="fas fa-check-double" style="color:#10b981;"></i> Прочитано пользователем' : '<i class="fas fa-check"></i> Отправлено' ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Быстрые шаблоны ответов -->
            <div style="padding:8px 16px; border-top:1px solid var(--border-color); background:rgba(0,0,0,0.1); display:flex; gap:8px; overflow-x:auto; white-space:nowrap;">
                <span style="font-size:11px; color:var(--text-muted); align-self:center;">Шаблоны:</span>
                <button type="button" class="btn btn-secondary btn-sm" style="font-size:11px; padding:3px 8px;" onclick="applyTemplate('greeting')">
                    👋 Принято в работу
                </button>
                <button type="button" class="btn btn-secondary btn-sm" style="font-size:11px; padding:3px 8px;" onclick="applyTemplate('clarify')">
                    ❓ Запрос деталей
                </button>
                <button type="button" class="btn btn-secondary btn-sm" style="font-size:11px; padding:3px 8px;" onclick="applyTemplate('mgmt')">
                    👑 Передано руководству
                </button>
                <button type="button" class="btn btn-secondary btn-sm" style="font-size:11px; padding:3px 8px;" onclick="applyTemplate('resolved')">
                    ✅ Вопрос решен
                </button>
            </div>

            <!-- Форма отправки ответа -->
            <div style="padding:16px 20px; border-top:1px solid var(--border-color); background:var(--bg-card);">
                <form method="POST" action="/admin/support_chat.php?ticket_id=<?= $ticketId ?>">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="action" value="send_reply">

                    <div style="display:flex; gap:10px; margin-bottom:10px;">
                        <textarea name="message" id="replyTextarea" class="form-control" rows="3" style="resize:none;" 
                                  placeholder="Напишите ответ пользователю... (будет отправлено email-уведомление)" required></textarea>
                    </div>

                    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                        <div style="display:flex; align-items:center; gap:8px;">
                            <label style="margin:0; font-size:12px; color:var(--text-muted);">При отправке сменить статус на:</label>
                            <select name="change_status" class="form-control" style="font-size:12px; padding:4px 8px; width:auto;">
                                <option value="in_review" <?= $ticket['status'] === 'in_review' ? 'selected' : '' ?>>Рассмотрено / В работе</option>
                                <option value="pending">Ожидание</option>
                                <option value="transferred_management">Передано руководству</option>
                                <option value="archive">Архив</option>
                                <option value="closed">Закрыто</option>
                            </select>
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-paper-plane"></i> Отправить ответ
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ПРАВАЯ КОЛОНКА: УПРАВЛЕНИЕ СТАТУСАМИ И ИНФО О КЛИЕНТЕ -->
    <div style="display:flex; flex-direction:column; gap:16px;">
        
        <!-- Карточка управления тикетом -->
        <div class="card" style="padding:20px;">
            <h3 style="margin:0 0 16px; font-size:16px; font-weight:700;">
                <i class="fas fa-sliders-h" style="color:var(--primary); margin-right:6px;"></i> Управление тикетом
            </h3>

            <form method="POST" action="/admin/support_chat.php?ticket_id=<?= $ticketId ?>">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="action" value="update_ticket_meta">

                <!-- Статус обращения (по ТЗ: Рассмотрено, архив, Игнор, Ожидание, Передано другому администратору, Передано руководству и т.д) -->
                <div class="form-group" style="margin-bottom:14px;">
                    <label style="display:block; font-size:12px; font-weight:600; margin-bottom:6px; color:var(--text-secondary);">
                        Статус обращения:
                    </label>
                    <select name="status" id="sidebarStatusSelect" class="form-control" onchange="checkAdminAssignment(this.value)">
                        <option value="pending" <?= $ticket['status'] === 'pending' ? 'selected' : '' ?>>⏳ Ожидание</option>
                        <option value="in_review" <?= $ticket['status'] === 'in_review' ? 'selected' : '' ?>>✅ Рассмотрено / В работе</option>
                        <option value="transferred_admin" <?= $ticket['status'] === 'transferred_admin' ? 'selected' : '' ?>>👤 Передано другому администратору</option>
                        <option value="transferred_management" <?= $ticket['status'] === 'transferred_management' ? 'selected' : '' ?>>👑 Передано руководству</option>
                        <option value="ignored" <?= $ticket['status'] === 'ignored' ? 'selected' : '' ?>>🚫 Игнор</option>
                        <option value="archive" <?= $ticket['status'] === 'archive' ? 'selected' : '' ?>>📦 Архив</option>
                        <option value="closed" <?= $ticket['status'] === 'closed' ? 'selected' : '' ?>>🔒 Закрыто</option>
                    </select>
                </div>

                <!-- Ответственный администратор -->
                <div class="form-group" id="sidebarAdminGroup" style="margin-bottom:14px; <?= $ticket['status'] === 'transferred_admin' ? '' : 'display:none;' ?>">
                    <label style="display:block; font-size:12px; font-weight:600; margin-bottom:6px; color:var(--text-secondary);">
                        Назначенный администратор:
                    </label>
                    <select name="assigned_to" class="form-control">
                        <option value="">-- Выберите сотрудника --</option>
                        <?php foreach ($adminList as $adm): ?>
                            <option value="<?= $adm['id'] ?>" <?= ((int)$ticket['assigned_to'] === (int)$adm['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($adm['first_name'] . ' ' . $adm['last_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Приоритет -->
                <div class="form-group" style="margin-bottom:14px;">
                    <label style="display:block; font-size:12px; font-weight:600; margin-bottom:6px; color:var(--text-secondary);">
                        Приоритет:
                    </label>
                    <select name="priority" class="form-control">
                        <option value="low" <?= $ticket['priority'] === 'low' ? 'selected' : '' ?>>Низкий</option>
                        <option value="normal" <?= $ticket['priority'] === 'normal' ? 'selected' : '' ?>>Обычный</option>
                        <option value="high" <?= $ticket['priority'] === 'high' ? 'selected' : '' ?>>Высокий</option>
                        <option value="urgent" <?= $ticket['priority'] === 'urgent' ? 'selected' : '' ?>>Критический</option>
                    </select>
                </div>

                <!-- Внутренние служебные заметки -->
                <div class="form-group" style="margin-bottom:16px;">
                    <label style="display:block; font-size:12px; font-weight:600; margin-bottom:6px; color:var(--text-secondary);">
                        Внутренние заметки (видят только админы):
                    </label>
                    <textarea name="admin_notes" class="form-control" rows="3" placeholder="Заметки по клиенту или сути проблемы..."><?= htmlspecialchars($ticket['admin_notes'] ?? '') ?></textarea>
                </div>

                <button type="submit" class="btn btn-primary" style="width:100%;">
                    <i class="fas fa-save"></i> Сохранить параметры
                </button>
            </form>
        </div>

        <!-- Данные о пользователе и организации -->
        <div class="card" style="padding:20px;">
            <h3 style="margin:0 0 14px; font-size:15px; font-weight:700;">
                <i class="fas fa-user-circle" style="color:var(--primary); margin-right:6px;"></i> Клиент
            </h3>

            <div style="display:flex; flex-direction:column; gap:10px; font-size:13px;">
                <div>
                    <div style="color:var(--text-muted); font-size:11px; text-transform:uppercase;">ФИО:</div>
                    <div style="font-weight:600; color:var(--text-primary);">
                        <?= htmlspecialchars(trim(($ticket['first_name'] ?? '') . ' ' . ($ticket['last_name'] ?? '')) ?: 'Не указано') ?>
                    </div>
                </div>

                <div>
                    <div style="color:var(--text-muted); font-size:11px; text-transform:uppercase;">Email:</div>
                    <div style="color:var(--text-primary);">
                        <a href="mailto:<?= htmlspecialchars($ticket['email']) ?>" style="color:#818cf8; text-decoration:none;">
                            <?= htmlspecialchars($ticket['email'] ?? '—') ?>
                        </a>
                    </div>
                </div>

                <?php if (!empty($ticket['phone'])): ?>
                    <div>
                        <div style="color:var(--text-muted); font-size:11px; text-transform:uppercase;">Телефон:</div>
                        <div style="color:var(--text-primary);"><?= htmlspecialchars($ticket['phone']) ?></div>
                    </div>
                <?php endif; ?>

                <div>
                    <div style="color:var(--text-muted); font-size:11px; text-transform:uppercase;">Организация:</div>
                    <div style="color:var(--text-primary); font-weight:600;">
                        <?php if (!empty($ticket['org_name'])): ?>
                            <span style="color:#10b981;"><i class="fas fa-building"></i> <?= htmlspecialchars($ticket['org_name']) ?></span>
                            <?php if (!empty($ticket['org_inn'])): ?>
                                <span style="font-size:11px; color:var(--text-muted);"> (ИНН: <?= htmlspecialchars($ticket['org_inn']) ?>)</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color:var(--text-muted); font-style:italic;">Организация отсутствует</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div>
                    <div style="color:var(--text-muted); font-size:11px; text-transform:uppercase;">Регистрация:</div>
                    <div style="color:var(--text-primary);">
                        <?= !empty($ticket['user_registered_at']) ? date('d.m.Y', strtotime($ticket['user_registered_at'])) : '—' ?>
                    </div>
                </div>

                <div>
                    <div style="color:var(--text-muted); font-size:11px; text-transform:uppercase;">Всего тикетов клиента:</div>
                    <div style="color:var(--text-primary); font-weight:600;">
                        <a href="/admin/support.php?q=<?= urlencode($ticket['email']) ?>" style="color:var(--primary); text-decoration:none;">
                            <?= $userTotalTickets ?> обр. →
                        </a>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
// Автоскролл к последнему сообщению при открытии
window.addEventListener('DOMContentLoaded', () => {
    const container = document.getElementById('messagesContainer');
    if (container) {
        container.scrollTop = container.scrollHeight;
    }
});

function checkAdminAssignment(status) {
    const group = document.getElementById('sidebarAdminGroup');
    if (status === 'transferred_admin') {
        group.style.display = 'block';
    } else {
        group.style.display = 'none';
    }
}

function applyTemplate(type) {
    const textarea = document.getElementById('replyTextarea');
    const userName = <?= json_encode(trim(($ticket['first_name'] ?? '') . ' ' . ($ticket['last_name'] ?? '')) ?: 'клиент') ?>;
    
    let text = '';
    if (type === 'greeting') {
        text = `Здравствуйте, ${userName}!\n\nВаше обращение принято в работу специалистами службы поддержки. Мы уже анализируем вопрос и вернемся с решением в ближайшее время.`;
    } else if (type === 'clarify') {
        text = `Здравствуйте, ${userName}!\n\nДля оперативного решения вопроса, пожалуйста, уточните следующие детали:\n1. На каком этапе возникает вопрос/сложность?\n2. Приложите, пожалуйста, снимок экрана или текст ошибки.\n\nСпасибо!`;
    } else if (type === 'mgmt') {
        text = `Здравствуйте, ${userName}!\n\nВаш запрос был передан руководству компании для рассмотрения в индивидуальном порядке. Мы сообщим вам итог сразу после принятия решения.`;
    } else if (type === 'resolved') {
        text = `Здравствуйте, ${userName}!\n\nСообщаем, что необходимые действия успешно выполнены, и вопрос решен. Если у вас возникнут дополнительные вопросы, мы всегда на связи!`;
    }

    textarea.value = text;
    textarea.focus();
}
</script>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
