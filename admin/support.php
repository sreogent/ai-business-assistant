<?php
declare(strict_types=1);

/**
 * AI Business Assistant - Admin Support & Tickets Dashboard
 * Панель управления службой технической поддержки и тикетами пользователей
 */

require_once dirname(__DIR__) . '/includes/Auth.php';
require_once dirname(__DIR__) . '/includes/Mailer.php';

$admin = Auth::requireRole(['support_agent', 'admin', 'vrio_owner']);
$adminId = (int)$admin['id'];
$pdo = Database::getConnection();

// Получаем список всех администраторов и специалистов поддержки для назначения
$adminStmt = $pdo->query("
    SELECT u.id, u.first_name, u.last_name, u.email, r.name AS role_name 
    FROM users u 
    INNER JOIN roles r ON u.role_id = r.id 
    WHERE r.name IN ('admin', 'vrio_owner', 'support_agent')
    ORDER BY u.first_name ASC
");
$adminList = $adminStmt->fetchAll();

$cfg = $pdo->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$baseUrl = rtrim($cfg['app_base_url'] ?? 'http://127.0.0.1:8000', '/');

$error = null;
$success = null;

// Обработка действий (быстрая смена статуса / назначение админа / закрытие)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!Security::verifyCsrf($token)) {
        $error = "Истёк токен безопасности. Повторите попытку.";
    } else {
        $action = $_POST['action'] ?? '';
        $ticketId = (int)($_POST['ticket_id'] ?? 0);

        $tCheck = $pdo->prepare("SELECT * FROM support_tickets WHERE id = ? LIMIT 1");
        $tCheck->execute([$ticketId]);
        $ticket = $tCheck->fetch();

        if (!$ticket) {
            $error = "Тикет #{$ticketId} не найден.";
        } else {
            if ($action === 'change_status') {
                $newStatus = trim($_POST['status'] ?? '');
                $validStatuses = ['pending', 'in_review', 'transferred_admin', 'transferred_management', 'ignored', 'archive', 'closed'];
                if (in_array($newStatus, $validStatuses, true)) {
                    $assignedTo = $ticket['assigned_to'];
                    if ($newStatus === 'transferred_admin' && !empty($_POST['assigned_to'])) {
                        $assignedTo = (int)$_POST['assigned_to'];
                    } elseif ($newStatus === 'in_review' && empty($assignedTo)) {
                        $assignedTo = $adminId; // Назначаем текущего админа при взятии в работу
                    }

                    $pdo->prepare("
                        UPDATE support_tickets 
                        SET status = ?, assigned_to = ? 
                        WHERE id = ?
                    ")->execute([$newStatus, $assignedTo, $ticketId]);

                    $success = "Статус тикета #{$ticket['ticket_number']} изменён.";
                } else {
                    $error = "Некорректный статус.";
                }
            } elseif ($action === 'delete_ticket') {
                $pdo->prepare("DELETE FROM support_tickets WHERE id = ?")->execute([$ticketId]);
                $success = "Тикет #{$ticket['ticket_number']} успешно удален.";
            }
        }
    }
}

// Фильтры
$filterStatus = trim($_GET['status'] ?? '');
$search = trim($_GET['q'] ?? '');

$where = ["1=1"];
$params = [];

if (!empty($filterStatus)) {
    $where[] = "t.status = ?";
    $params[] = $filterStatus;
}

if (!empty($search)) {
    $where[] = "(t.ticket_number LIKE ? OR t.subject LIKE ? OR u.email LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)";
    $like = "%{$search}%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$whereSql = implode(' AND ', $where);

// Подсчет счетчиков по статусам
$countsStmt = $pdo->query("
    SELECT 
        status, 
        COUNT(*) as cnt 
    FROM support_tickets 
    GROUP BY status
");
$rawCounts = $countsStmt->fetchAll(PDO::FETCH_KEY_PAIR);

$totalTickets = (int)array_sum($rawCounts);
$cntPending = (int)($rawCounts['pending'] ?? 0);
$cntReview = (int)($rawCounts['in_review'] ?? 0);
$cntTransAdmin = (int)($rawCounts['transferred_admin'] ?? 0);
$cntTransMgmt = (int)($rawCounts['transferred_management'] ?? 0);
$cntIgnored = (int)($rawCounts['ignored'] ?? 0);
$cntArchive = (int)($rawCounts['archive'] ?? 0);
$cntClosed = (int)($rawCounts['closed'] ?? 0);

// Запрос тикетов с авторами, организациями и последними сообщениями
$sql = "
    SELECT 
        t.*,
        u.first_name, u.last_name, u.email, u.phone,
        org.name AS org_name,
        adm.first_name AS admin_first_name, adm.last_name AS admin_last_name,
        (SELECT COUNT(*) FROM support_messages WHERE ticket_id = t.id) AS messages_count,
        (SELECT COUNT(*) FROM support_messages WHERE ticket_id = t.id AND sender_type = 'user' AND is_read = 0) AS unread_count
    FROM support_tickets t
    LEFT JOIN users u ON t.user_id = u.id
    LEFT JOIN organizations org ON (org.user_id = u.id OR org.director_email = u.email)
    LEFT JOIN users adm ON t.assigned_to = adm.id
    WHERE {$whereSql}
    ORDER BY 
        CASE 
            WHEN t.status = 'pending' THEN 1
            WHEN t.status = 'transferred_management' THEN 2
            WHEN t.status = 'transferred_admin' THEN 3
            WHEN t.status = 'in_review' THEN 4
            WHEN t.status = 'ignored' THEN 5
            WHEN t.status = 'archive' THEN 6
            ELSE 7
        END ASC,
        t.last_reply_at DESC, 
        t.created_at DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tickets = $stmt->fetchAll();

$statusLabels = [
    'pending'                => ['title' => 'Ожидание',                 'badge' => 'badge-warning',   'color' => '#f59e0b', 'icon' => 'fa-clock'],
    'in_review'              => ['title' => 'Рассмотрено / В работе',   'badge' => 'badge-success',   'color' => '#10b981', 'icon' => 'fa-check-circle'],
    'transferred_admin'      => ['title' => 'Передано администратору',   'badge' => 'badge-info',      'color' => '#3b82f6', 'icon' => 'fa-user-shield'],
    'transferred_management' => ['title' => 'Передано руководству',      'badge' => 'badge-primary',   'color' => '#ec4899', 'icon' => 'fa-crown'],
    'ignored'                => ['title' => 'Игнор',                     'badge' => 'badge-danger',    'color' => '#ef4444', 'icon' => 'fa-ban'],
    'archive'                => ['title' => 'Архив',                     'badge' => 'badge-secondary', 'color' => '#6b7280', 'icon' => 'fa-archive'],
    'closed'                 => ['title' => 'Закрыто',                   'badge' => 'badge-secondary', 'color' => '#4b5563', 'icon' => 'fa-lock'],
];

$priorityLabels = [
    'low'    => ['label' => 'Низкий',      'badge' => 'badge-secondary'],
    'normal' => ['label' => 'Обычный',     'badge' => 'badge-info'],
    'high'   => ['label' => 'Высокий',     'badge' => 'badge-warning'],
    'urgent' => ['label' => 'Критический', 'badge' => 'badge-danger'],
];

$csrfToken = Security::csrfToken();
$pageTitle = "Техподдержка и Тикеты | Панель администратора";
require dirname(__DIR__) . '/includes/header.php';
?>

<div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px; margin-bottom:24px;">
    <div>
        <h1 style="margin:0; font-size:24px; font-weight:700;">
            <i class="fas fa-headset" style="color:var(--primary); margin-right:8px;"></i> Техническая поддержка & Тикеты
        </h1>
        <p style="margin:4px 0 0; color:var(--text-muted); font-size:14px;">
            Обработка обращений пользователей, назначение специалистов, история диалогов и управление статусами
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

<!-- Сводные карточки со статусами -->
<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(140px, 1fr)); gap:12px; margin-bottom:24px;">
    <a href="/admin/support.php" style="text-decoration:none;">
        <div class="card" style="padding:14px; text-align:center; border-top:3px solid var(--primary); background:<?= empty($filterStatus) ? 'rgba(99,102,241,0.1)' : 'var(--bg-card)' ?>;">
            <div style="font-size:11px; text-transform:uppercase; color:var(--text-muted); font-weight:600;">Все</div>
            <div style="font-size:22px; font-weight:700; color:var(--text-primary); margin-top:4px;"><?= $totalTickets ?></div>
        </div>
    </a>
    <a href="/admin/support.php?status=pending" style="text-decoration:none;">
        <div class="card" style="padding:14px; text-align:center; border-top:3px solid #f59e0b; background:<?= $filterStatus === 'pending' ? 'rgba(245,158,11,0.1)' : 'var(--bg-card)' ?>;">
            <div style="font-size:11px; text-transform:uppercase; color:#f59e0b; font-weight:600;">Ожидание</div>
            <div style="font-size:22px; font-weight:700; color:#f59e0b; margin-top:4px;"><?= $cntPending ?></div>
        </div>
    </a>
    <a href="/admin/support.php?status=in_review" style="text-decoration:none;">
        <div class="card" style="padding:14px; text-align:center; border-top:3px solid #10b981; background:<?= $filterStatus === 'in_review' ? 'rgba(16,185,129,0.1)' : 'var(--bg-card)' ?>;">
            <div style="font-size:11px; text-transform:uppercase; color:#10b981; font-weight:600;">Рассмотрено</div>
            <div style="font-size:22px; font-weight:700; color:#10b981; margin-top:4px;"><?= $cntReview ?></div>
        </div>
    </a>
    <a href="/admin/support.php?status=transferred_admin" style="text-decoration:none;">
        <div class="card" style="padding:14px; text-align:center; border-top:3px solid #3b82f6; background:<?= $filterStatus === 'transferred_admin' ? 'rgba(59,130,246,0.1)' : 'var(--bg-card)' ?>;">
            <div style="font-size:11px; text-transform:uppercase; color:#3b82f6; font-weight:600;">Передано админу</div>
            <div style="font-size:22px; font-weight:700; color:#3b82f6; margin-top:4px;"><?= $cntTransAdmin ?></div>
        </div>
    </a>
    <a href="/admin/support.php?status=transferred_management" style="text-decoration:none;">
        <div class="card" style="padding:14px; text-align:center; border-top:3px solid #ec4899; background:<?= $filterStatus === 'transferred_management' ? 'rgba(236,72,153,0.1)' : 'var(--bg-card)' ?>;">
            <div style="font-size:11px; text-transform:uppercase; color:#ec4899; font-weight:600;">Руководству</div>
            <div style="font-size:22px; font-weight:700; color:#ec4899; margin-top:4px;"><?= $cntTransMgmt ?></div>
        </div>
    </a>
    <a href="/admin/support.php?status=ignored" style="text-decoration:none;">
        <div class="card" style="padding:14px; text-align:center; border-top:3px solid #ef4444; background:<?= $filterStatus === 'ignored' ? 'rgba(239,68,68,0.1)' : 'var(--bg-card)' ?>;">
            <div style="font-size:11px; text-transform:uppercase; color:#ef4444; font-weight:600;">Игнор</div>
            <div style="font-size:22px; font-weight:700; color:#ef4444; margin-top:4px;"><?= $cntIgnored ?></div>
        </div>
    </a>
    <a href="/admin/support.php?status=archive" style="text-decoration:none;">
        <div class="card" style="padding:14px; text-align:center; border-top:3px solid #6b7280; background:<?= $filterStatus === 'archive' ? 'rgba(107,114,128,0.1)' : 'var(--bg-card)' ?>;">
            <div style="font-size:11px; text-transform:uppercase; color:#9ca3af; font-weight:600;">Архив</div>
            <div style="font-size:22px; font-weight:700; color:#9ca3af; margin-top:4px;"><?= $cntArchive ?></div>
        </div>
    </a>
    <a href="/admin/support.php?status=closed" style="text-decoration:none;">
        <div class="card" style="padding:14px; text-align:center; border-top:3px solid #4b5563; background:<?= $filterStatus === 'closed' ? 'rgba(75,85,99,0.1)' : 'var(--bg-card)' ?>;">
            <div style="font-size:11px; text-transform:uppercase; color:#9ca3af; font-weight:600;">Закрыто</div>
            <div style="font-size:22px; font-weight:700; color:#9ca3af; margin-top:4px;"><?= $cntClosed ?></div>
        </div>
    </a>
</div>

<!-- Фильтр и Поиск -->
<div class="card" style="padding:16px; margin-bottom:20px;">
    <form method="GET" action="/admin/support.php" style="display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
        <div style="flex:1; min-width:240px; position:relative;">
            <i class="fas fa-search" style="position:absolute; left:12px; top:12px; color:var(--text-muted);"></i>
            <input type="text" name="q" class="form-control" style="padding-left:36px;" 
                   placeholder="Поиск по #тикету, теме, имени или email..." 
                   value="<?= htmlspecialchars($search) ?>">
        </div>

        <div style="min-width:180px;">
            <select name="status" class="form-control">
                <option value="">Все статусы</option>
                <option value="pending" <?= $filterStatus === 'pending' ? 'selected' : '' ?>>Ожидание</option>
                <option value="in_review" <?= $filterStatus === 'in_review' ? 'selected' : '' ?>>Рассмотрено</option>
                <option value="transferred_admin" <?= $filterStatus === 'transferred_admin' ? 'selected' : '' ?>>Передано администратору</option>
                <option value="transferred_management" <?= $filterStatus === 'transferred_management' ? 'selected' : '' ?>>Передано руководству</option>
                <option value="ignored" <?= $filterStatus === 'ignored' ? 'selected' : '' ?>>Игнор</option>
                <option value="archive" <?= $filterStatus === 'archive' ? 'selected' : '' ?>>Архив</option>
                <option value="closed" <?= $filterStatus === 'closed' ? 'selected' : '' ?>>Закрыто</option>
            </select>
        </div>

        <button type="submit" class="btn btn-primary">
            <i class="fas fa-filter"></i> Применить
        </button>

        <?php if (!empty($search) || !empty($filterStatus)): ?>
            <a href="/admin/support.php" class="btn btn-secondary">
                <i class="fas fa-times"></i> Сбросить
            </a>
        <?php endif; ?>
    </form>
</div>

<!-- Список тикетов -->
<div class="card" style="padding:0; overflow:hidden;">
    <div style="padding:16px 20px; border-bottom:1px solid var(--border-color); display:flex; justify-content:space-between; align-items:center;">
        <h3 style="margin:0; font-size:16px; font-weight:600;">
            Найдено обращений: <?= count($tickets) ?>
        </h3>
    </div>

    <?php if (empty($tickets)): ?>
        <div style="padding:40px; text-align:center; color:var(--text-muted);">
            <i class="fas fa-inbox" style="font-size:40px; opacity:0.4; margin-bottom:12px;"></i>
            <p style="margin:0; font-size:15px;">Обращений по заданным критериям не найдено.</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table" style="margin:0;">
                <thead>
                    <tr>
                        <th style="width:110px;">Тикет</th>
                        <th>Пользователь / Компания</th>
                        <th>Тема & Категория</th>
                        <th style="width:120px;">Приоритет</th>
                        <th style="width:180px;">Статус</th>
                        <th style="width:150px;">Ответственный</th>
                        <th style="width:100px; text-align:center;">Диалог</th>
                        <th style="width:140px;">Обновлено</th>
                        <th style="width:120px; text-align:right;">Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tickets as $t): 
                        $st = $statusLabels[$t['status']] ?? ['title' => $t['status'], 'badge' => 'badge-secondary', 'color' => '#6b7280', 'icon' => 'fa-info-circle'];
                        $pr = $priorityLabels[$t['priority']] ?? ['label' => $t['priority'], 'badge' => 'badge-secondary'];
                    ?>
                        <tr style="<?= $t['unread_count'] > 0 ? 'background:rgba(99,102,241,0.05);' : '' ?>">
                            <td>
                                <a href="/admin/support_chat.php?ticket_id=<?= $t['id'] ?>" style="font-family:monospace; font-weight:700; color:var(--primary); text-decoration:none;">
                                    #<?= htmlspecialchars($t['ticket_number']) ?>
                                </a>
                                <?php if ($t['unread_count'] > 0): ?>
                                    <div style="margin-top:2px;">
                                        <span class="badge badge-danger" style="font-size:10px; padding:2px 5px;">+<?= $t['unread_count'] ?> нов.</span>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="font-weight:600; color:var(--text-primary);">
                                    <?= htmlspecialchars(trim(($t['first_name'] ?? '') . ' ' . ($t['last_name'] ?? '')) ?: 'Пользователь #' . $t['user_id']) ?>
                                </div>
                                <div style="font-size:12px; color:var(--text-muted);">
                                    <i class="fas fa-envelope"></i> <?= htmlspecialchars($t['email'] ?? '—') ?>
                                </div>
                                <?php if (!empty($t['org_name'])): ?>
                                    <div style="font-size:11px; color:#818cf8; margin-top:2px;">
                                        <i class="fas fa-building"></i> <?= htmlspecialchars($t['org_name']) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="/admin/support_chat.php?ticket_id=<?= $t['id'] ?>" style="font-weight:600; color:var(--text-primary); text-decoration:none; display:block;">
                                    <?= htmlspecialchars($t['subject']) ?>
                                </a>
                                <span style="font-size:12px; color:var(--text-muted);">
                                    <i class="fas fa-folder"></i> <?= htmlspecialchars($t['category'] ?? 'Общее') ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?= $pr['badge'] ?>">
                                    <?= $pr['label'] ?>
                                </span>
                            </td>
                            <td>
                                <div style="display:inline-flex; align-items:center; gap:6px; font-weight:600; font-size:12px; color:<?= $st['color'] ?>;">
                                    <i class="fas <?= $st['icon'] ?>"></i> <?= $st['title'] ?>
                                </div>
                            </td>
                            <td>
                                <?php if (!empty($t['admin_first_name'])): ?>
                                    <span style="font-size:13px; font-weight:500;">
                                        <i class="fas fa-user-tie" style="color:var(--primary); font-size:11px;"></i>
                                        <?= htmlspecialchars($t['admin_first_name'] . ' ' . $t['admin_last_name']) ?>
                                    </span>
                                <?php else: ?>
                                    <span style="font-size:12px; color:var(--text-muted); font-style:italic;">Не назначен</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:center;">
                                <a href="/admin/support_chat.php?ticket_id=<?= $t['id'] ?>" class="btn btn-secondary btn-sm" style="padding:4px 8px; font-size:12px;" title="Сообщений: <?= $t['messages_count'] ?>">
                                    <i class="fas fa-comments"></i> <?= $t['messages_count'] ?>
                                </a>
                            </td>
                            <td>
                                <div style="font-size:12px; color:var(--text-primary);">
                                    <?= date('d.m.Y H:i', strtotime($t['last_reply_at'] ?? $t['created_at'])) ?>
                                </div>
                                <div style="font-size:11px; color:var(--text-muted);">
                                    созд: <?= date('d.m.Y', strtotime($t['created_at'])) ?>
                                </div>
                            </td>
                            <td style="text-align:right;">
                                <div style="display:flex; justify-content:flex-end; gap:6px;">
                                    <a href="/admin/support_chat.php?ticket_id=<?= $t['id'] ?>" class="btn btn-primary btn-sm" title="Открыть диалог">
                                        <i class="fas fa-comment-dots"></i>
                                    </a>
                                    <!-- Кнопка модалки быстрой смены статуса -->
                                    <button type="button" class="btn btn-secondary btn-sm" onclick="openStatusModal(<?= htmlspecialchars(json_encode($t)) ?>)" title="Изменить статус">
                                        <i class="fas fa-exchange-alt"></i>
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

<!-- Модальное окно быстрой смены статуса тикета -->
<div id="statusModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7); z-index:9999; justify-content:center; align-items:center; padding:16px;">
    <div class="card" style="max-width:480px; width:100%; border:1px solid var(--border-color); box-shadow:0 12px 30px rgba(0,0,0,0.5);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
            <h3 style="margin:0; font-size:18px; font-weight:700;">
                <i class="fas fa-tags" style="color:var(--primary); margin-right:8px;"></i> Смена статуса тикета
            </h3>
            <button type="button" onclick="closeStatusModal()" style="background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:18px;">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <form method="POST" action="/admin/support.php">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" value="change_status">
            <input type="hidden" name="ticket_id" id="modalTicketId" value="">

            <div style="background:rgba(255,255,255,0.03); border-radius:8px; padding:12px; margin-bottom:16px; font-size:13px;">
                <div><strong>Номер:</strong> <span id="modalTicketNumber" style="color:var(--primary); font-family:monospace; font-weight:700;"></span></div>
                <div><strong>Тема:</strong> <span id="modalTicketSubject"></span></div>
            </div>

            <div class="form-group" style="margin-bottom:16px;">
                <label style="display:block; margin-bottom:6px; font-weight:600; font-size:13px;">Новый статус обращения:</label>
                <select name="status" id="modalStatusSelect" class="form-control" onchange="toggleAdminSelect(this.value)" required>
                    <option value="pending">⏳ Ожидание</option>
                    <option value="in_review">✅ Рассмотрено / В работе</option>
                    <option value="transferred_admin">👤 Передано другому администратору</option>
                    <option value="transferred_management">👑 Передано руководству</option>
                    <option value="ignored">🚫 Игнор</option>
                    <option value="archive">📦 Архив</option>
                    <option value="closed">🔒 Закрыто</option>
                </select>
            </div>

            <div class="form-group" id="adminSelectContainer" style="display:none; margin-bottom:20px;">
                <label style="display:block; margin-bottom:6px; font-weight:600; font-size:13px;">Выберите администратора:</label>
                <select name="assigned_to" id="modalAdminSelect" class="form-control">
                    <option value="">-- Не выбрано --</option>
                    <?php foreach ($adminList as $adm): ?>
                        <option value="<?= $adm['id'] ?>">
                            <?= htmlspecialchars($adm['first_name'] . ' ' . $adm['last_name'] . ' (' . $adm['email'] . ')') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="btn btn-secondary" onclick="closeStatusModal()">Отмена</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Сохранить статус
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openStatusModal(t) {
    document.getElementById('modalTicketId').value = t.id;
    document.getElementById('modalTicketNumber').innerText = '#' + t.ticket_number;
    document.getElementById('modalTicketSubject').innerText = t.subject;
    document.getElementById('modalStatusSelect').value = t.status;

    if (t.assigned_to) {
        document.getElementById('modalAdminSelect').value = t.assigned_to;
    }

    toggleAdminSelect(t.status);
    document.getElementById('statusModal').style.display = 'flex';
}

function closeStatusModal() {
    document.getElementById('statusModal').style.display = 'none';
}

function toggleAdminSelect(status) {
    const container = document.getElementById('adminSelectContainer');
    if (status === 'transferred_admin') {
        container.style.display = 'block';
    } else {
        container.style.display = 'none';
    }
}
</script>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
