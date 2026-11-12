<?php
declare(strict_types=1);

/**
 * AI Business Assistant - Гостевая техническая поддержка
 * Доступно для всех пользователей БЕЗ регистрации и входа на главном экране.
 */

require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/Security.php';
require_once __DIR__ . '/includes/Auth.php';
require_once __DIR__ . '/includes/AutoMigrate.php';
require_once __DIR__ . '/includes/Mailer.php';

AutoMigrate::run();
$pdo = Database::getConnection();

$currentUser = Auth::user();
$theme = Auth::getActiveTheme();
$particles = Auth::isThemeParticlesEnabled();

$error = null;
$success = null;

// Получаем app_base_url
$baseUrlRow = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'app_base_url' LIMIT 1");
$baseUrl = rtrim((string)($baseUrlRow ? $baseUrlRow->fetchColumn() : 'http://127.0.0.1:8000'), '/');

// Режим просмотра существующего тикета по номеру и токену
$viewTicketNum = trim($_GET['ticket'] ?? '');
$viewToken = trim($_GET['token'] ?? '');
$activeTicket = null;

if (!empty($viewTicketNum)) {
    if (!empty($viewToken)) {
        $tStmt = $pdo->prepare("SELECT * FROM support_tickets WHERE ticket_number = ? AND guest_token = ? LIMIT 1");
        $tStmt->execute([$viewTicketNum, $viewToken]);
        $activeTicket = $tStmt->fetch(PDO::FETCH_ASSOC);
    } elseif ($currentUser) {
        $tStmt = $pdo->prepare("SELECT * FROM support_tickets WHERE ticket_number = ? AND (user_id = ? OR guest_email = ?) LIMIT 1");
        $tStmt->execute([$viewTicketNum, $currentUser['id'], $currentUser['email']]);
        $activeTicket = $tStmt->fetch(PDO::FETCH_ASSOC);
    }
}

// ── AJAX: Получение новых сообщений для чата ──────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'poll_guest_messages' && $activeTicket) {
    header('Content-Type: application/json; charset=utf-8');
    $afterId = (int)($_GET['after_id'] ?? 0);
    $stmt = $pdo->prepare("
        SELECT m.*, u.first_name, u.last_name, r.name as role_name
        FROM support_messages m
        LEFT JOIN users u ON m.sender_id = u.id
        LEFT JOIN roles r ON u.role_id = r.id
        WHERE m.ticket_id = ? AND m.id > ?
        ORDER BY m.id ASC
    ");
    $stmt->execute([(int)$activeTicket['id'], $afterId]);
    $msgs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Отмечаем прочитанными сообщения от поддержки
    if (!empty($msgs)) {
        $pdo->prepare("UPDATE support_messages SET is_read = 1 WHERE ticket_id = ? AND sender_type = 'admin'")
            ->execute([(int)$activeTicket['id']]);
    }

    echo json_encode(['success' => true, 'messages' => $msgs]);
    exit;
}

// ── AJAX / POST: Отправка сообщения в существующий тикет ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_reply' && $activeTicket) {
    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
           || (isset($_POST['ajax']) && $_POST['ajax'] === '1');

    $token = $_POST['csrf_token'] ?? '';
    if (!Security::verifyCsrf($token)) {
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'Истёк токен безопасности. Обновите страницу.']);
            exit;
        }
        $error = "Истёк токен безопасности.";
    } else {
        $replyText = trim($_POST['message'] ?? '');
        if (!empty($replyText)) {
            $senderId = $currentUser ? (int)$currentUser['id'] : 0;
            $ins = $pdo->prepare("
                INSERT INTO support_messages (ticket_id, sender_id, sender_type, message, is_read)
                VALUES (?, ?, 'user', ?, 0)
            ");
            $ins->execute([(int)$activeTicket['id'], $senderId, $replyText]);
            $newId = (int)$pdo->lastInsertId();

            $newStatus = in_array($activeTicket['status'], ['in_review', 'closed', 'archive']) ? 'pending' : $activeTicket['status'];
            $pdo->prepare("UPDATE support_tickets SET last_reply_at = NOW(), status = ? WHERE id = ?")
                ->execute([$newStatus, (int)$activeTicket['id']]);

            // Мгновенное уведомление сотрудников в приложении
            try {
                $staffStmt = $pdo->query("
                    SELECT u.id 
                    FROM users u 
                    JOIN roles r ON u.role_id = r.id 
                    WHERE r.name IN ('support_agent', 'support', 'admin', 'vrio_owner', 'owner', 'supreme_owner', 'developer')
                ");
                $staffIds = $staffStmt->fetchAll(PDO::FETCH_COLUMN);
                $rNotif = $pdo->prepare("
                    INSERT INTO notifications (user_id, type, title, body, link, is_read, created_at)
                    VALUES (?, 'support', ?, ?, ?, 0, NOW())
                ");
                $rTitle = "💬 Сообщение в тикете #{$activeTicket['ticket_number']}";
                $rBody = mb_substr($replyText, 0, 160);
                $rLink = "/support/dashboard.php?ticket_id={$activeTicket['id']}";
                foreach ($staffIds as $sId) {
                    $rNotif->execute([(int)$sId, $rTitle, $rBody, $rLink]);
                }
            } catch (\Throwable $ne) {}

            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => true, 'message_id' => $newId, 'created_at' => date('d.m.Y H:i')]);
                exit;
            }

            header("Location: /support_guest.php?ticket=" . urlencode($activeTicket['ticket_number']) . "&token=" . urlencode((string)$activeTicket['guest_token']));
            exit;
        }
    }
}

// ── POST: Создание нового обращения гостем ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!isset($_POST['action']) || $_POST['action'] === 'create_ticket')) {
    $token = $_POST['csrf_token'] ?? '';
    if (!Security::verifyCsrf($token)) {
        $error = "Истёк токен безопасности. Повторите отправку.";
    } else {
        $guestName  = trim($_POST['guest_name'] ?? ($currentUser ? ($currentUser['first_name'] . ' ' . $currentUser['last_name']) : ''));
        $guestEmail = trim($_POST['guest_email'] ?? ($currentUser['email'] ?? ''));
        $category   = trim($_POST['category'] ?? 'Общий вопрос');
        $subject    = trim($_POST['subject'] ?? '');
        $message    = trim($_POST['message'] ?? '');

        if (empty($guestName)) {
            $error = "Пожалуйста, представьтесь (укажите ваше имя).";
        } elseif (!filter_var($guestEmail, FILTER_VALIDATE_EMAIL)) {
            $error = "Введите корректный email для получения ответа поддержки.";
        } elseif (empty($subject) || empty($message)) {
            $error = "Укажите тему обращения и опишите вашу проблему или вопрос.";
        } else {
            $ticketNumber = 'TCK-G-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
            $guestToken = bin2hex(random_bytes(16));
            $userId = $currentUser ? (int)$currentUser['id'] : null;

            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("
                    INSERT INTO support_tickets (ticket_number, user_id, guest_name, guest_email, guest_token, subject, category, priority, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'medium', 'pending')
                ");
                $stmt->execute([$ticketNumber, $userId, $guestName, $guestEmail, $guestToken, $subject, $category]);
                $newTicketId = (int)$pdo->lastInsertId();

                $msgStmt = $pdo->prepare("
                    INSERT INTO support_messages (ticket_id, sender_id, sender_type, message, is_read)
                    VALUES (?, ?, 'user', ?, 0)
                ");
                $msgStmt->execute([$newTicketId, $userId ?: 0, $message]);

                $pdo->commit();

                // Мгновенное уведомление в приложение для администраторов и техподдержки
                try {
                    $staffStmt = $pdo->query("
                        SELECT u.id 
                        FROM users u 
                        JOIN roles r ON u.role_id = r.id 
                        WHERE r.name IN ('support_agent', 'support', 'admin', 'vrio_owner', 'owner', 'supreme_owner', 'developer')
                    ");
                    $staffIds = $staffStmt->fetchAll(PDO::FETCH_COLUMN);
                    $notifStmt = $pdo->prepare("
                        INSERT INTO notifications (user_id, type, title, body, link, is_read, created_at)
                        VALUES (?, 'support', ?, ?, ?, 0, NOW())
                    ");
                    $notifTitle = "⚡ Новое обращение в техподдержку #{$ticketNumber}";
                    $notifBody = "От: {$guestName} ({$guestEmail})\nТема: {$subject}\nКатегория: {$category}";
                    $notifLink = "/support/dashboard.php?ticket_id={$newTicketId}";
                    foreach ($staffIds as $sId) {
                        $notifStmt->execute([(int)$sId, $notifTitle, $notifBody, $notifLink]);
                    }

                    // Отправляем уведомление в корпоративный чат штаба, если канал существует
                    $hqChanRow = $pdo->query("SELECT id FROM team_chat_channels WHERE channel_key = 'site_hq' LIMIT 1");
                    $hqChanId = $hqChanRow ? (int)$hqChanRow->fetchColumn() : 0;
                    if ($hqChanId) {
                        $chatNotice = "📩 [ОБРАЩЕНИЕ С САЙТА]\n#{$ticketNumber}: {$subject}\nОт: {$guestName} ({$guestEmail})\nКатегория: {$category}\nСообщение: {$message}";
                        $pdo->prepare("INSERT INTO team_chat_messages (channel_id, sender_id, message, created_at) VALUES (?, 0, ?, NOW())")
                            ->execute([$hqChanId, $chatNotice]);
                    }
                } catch (\Throwable $ne) {}

                // Отправляем уведомление на почту гостю
                $trackingUrl = "{$baseUrl}/support_guest.php?ticket=" . urlencode($ticketNumber) . "&token=" . urlencode($guestToken);
                try {
                    Mailer::sendSupportTicketCreated($guestEmail, $guestName, $ticketNumber, $subject, $trackingUrl);
                } catch (\Throwable $e) {}

                header("Location: /support_guest.php?ticket=" . urlencode($ticketNumber) . "&token=" . urlencode($guestToken) . "&created=1");
                exit;
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = "Не удалось отправить обращение: " . $e->getMessage();
            }
        }
    }
}

// ── POST: Поиск существующего обращения ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'lookup_ticket') {
    $searchNum = strtoupper(trim($_POST['ticket_number'] ?? ''));
    $searchEmail = trim($_POST['email'] ?? '');

    if (empty($searchNum) || empty($searchEmail)) {
        $error = "Введите номер обращения и email, указанный при создании.";
    } else {
        $chk = $pdo->prepare("
            SELECT t.* 
            FROM support_tickets t 
            LEFT JOIN users u ON t.user_id = u.id 
            WHERE t.ticket_number = ? AND (t.guest_email = ? OR u.email = ?) 
            LIMIT 1
        ");
        $chk->execute([$searchNum, $searchEmail, $searchEmail]);
        $found = $chk->fetch(PDO::FETCH_ASSOC);

        if ($found) {
            header("Location: /support_guest.php?ticket=" . urlencode($found['ticket_number']) . "&token=" . urlencode((string)$found['guest_token']));
            exit;
        } else {
            $error = "Обращение с указанным номером и email не найдено.";
        }
    }
}

// Загрузка сообщений для активного тикета
$messages = [];
if ($activeTicket) {
    $msgStmt = $pdo->prepare("
        SELECT m.*, u.first_name, u.last_name, r.name as role_name
        FROM support_messages m
        LEFT JOIN users u ON m.sender_id = u.id
        LEFT JOIN roles r ON u.role_id = r.id
        WHERE m.ticket_id = ?
        ORDER BY m.id ASC
    ");
    $msgStmt->execute([(int)$activeTicket['id']]);
    $messages = $msgStmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="ru" data-theme="<?= htmlspecialchars($theme) ?>" data-theme-particles="<?= $particles ? '1' : '0' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $activeTicket ? 'Обращение #' . htmlspecialchars($activeTicket['ticket_number']) : 'Техническая поддержка' ?> | AI Business Assistant</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/themes.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="/assets/js/theme-particles.js" defer></script>
</head>
<body style="min-height: 100vh; background: var(--bg-primary); color: var(--text-primary); display: flex; flex-direction: column;">

    <!-- Navigation Header -->
    <header class="landing-header" style="position: sticky; top: 0; z-index: 100; backdrop-filter: blur(12px); background: rgba(10, 14, 23, 0.85); border-bottom: 1px solid var(--border-color); padding: 14px 28px; display: flex; align-items: center; justify-content: space-between;">
        <div style="display:flex; align-items:center; gap: 24px;">
            <a href="/" class="logo" style="text-decoration: none; font-size: 18px; font-weight: 800; color: #fff; display: flex; align-items: center; gap: 8px;">
                <span class="logo-badge" style="background: linear-gradient(135deg, var(--primary), var(--accent)); padding: 4px 8px; border-radius: 6px; font-size: 12px; font-weight: 900;">AI</span> Business Assistant
            </a>
            <nav style="display: flex; gap: 16px; align-items: center;">
                <a href="/news.php" style="color: var(--text-muted); text-decoration: none; font-size: 14px; font-weight: 600; display: flex; align-items: center; gap: 6px;">
                    <i class="fas fa-newspaper"></i> Новости
                </a>
                <a href="/support_guest.php" style="color: var(--primary); text-decoration: none; font-size: 14px; font-weight: 700; display: flex; align-items: center; gap: 6px;">
                    <i class="fas fa-headset"></i> Поддержка
                </a>
            </nav>
        </div>

        <div style="display: flex; align-items: center; gap: 12px;">
            <button class="theme-toggle" id="theme-toggle" title="Сменить тему" style="background: rgba(255,255,255,0.06); border: 1px solid var(--border-color); color: var(--text-primary); width: 36px; height: 36px; border-radius: 8px; cursor: pointer;">
                <i class="fas fa-moon"></i>
            </button>
            <?php if ($currentUser): ?>
                <a href="<?= Auth::getRedirectForRole($currentUser['role_name'] ?? 'user') ?>" class="btn btn-primary btn-sm">
                    <i class="fas fa-columns"></i> В кабинет
                </a>
            <?php else: ?>
                <a href="/login.php" class="btn btn-secondary btn-sm">Вход</a>
                <a href="/register.php" class="btn btn-primary btn-sm">Регистрация</a>
            <?php endif; ?>
        </div>
    </header>

    <!-- Main Container -->
    <main style="flex: 1; max-width: 960px; width: 100%; margin: 32px auto; padding: 0 20px;">

        <?php if ($error): ?>
            <div class="alert alert-danger" style="margin-bottom: 24px;">
                <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($activeTicket): ?>
            <!-- ══════════════════════════════════════════════════════════════════ -->
            <!-- ЭКРАН ЖИВОГО ДИАЛОГА С ПОДДЕРЖКОЙ                              -->
            <!-- ══════════════════════════════════════════════════════════════════ -->
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px; margin-bottom: 20px;">
                <div>
                    <a href="/support_guest.php" class="btn btn-secondary btn-sm" style="margin-bottom: 8px;">
                        <i class="fas fa-arrow-left"></i> Новое обращение / Найти другое
                    </a>
                    <h2 style="margin: 0; font-size: 22px;">
                        <span style="font-family: monospace; color: #818cf8;">#<?= htmlspecialchars($activeTicket['ticket_number']) ?></span>: 
                        <?= htmlspecialchars($activeTicket['subject']) ?>
                    </h2>
                    <div style="font-size: 13px; color: var(--text-muted); margin-top: 4px;">
                        От: <strong><?= htmlspecialchars($activeTicket['guest_name'] ?? 'Гость') ?></strong> (<?= htmlspecialchars($activeTicket['guest_email'] ?? '') ?>)
                    </div>
                </div>

                <div style="display: flex; gap: 8px; align-items: center;">
                    <span class="badge" style="background: rgba(255,255,255,0.08); font-size: 12px; padding: 6px 12px;">
                        <i class="fas fa-folder"></i> <?= htmlspecialchars($activeTicket['category']) ?>
                    </span>
                    <span class="badge <?= $activeTicket['status'] === 'closed' ? 'badge-secondary' : 'badge-success' ?>" style="font-size: 12px; padding: 6px 12px;">
                        <i class="fas <?= $activeTicket['status'] === 'closed' ? 'fa-lock' : 'fa-headset' ?>"></i> 
                        <?= $activeTicket['status'] === 'closed' ? 'Закрыто' : 'Открыто' ?>
                    </span>
                </div>
            </div>

            <?php if (isset($_GET['created'])): ?>
                <div class="alert alert-success" style="margin-bottom: 20px; font-size: 14px;">
                    <i class="fas fa-check-circle"></i> 
                    Ваше обращение принято! Сохраните эту страницу в закладки или перейдите по ссылке, отправленной на ваш email, чтобы проверить ответ.
                </div>
            <?php endif; ?>

            <div class="card" style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius-lg); padding: 0; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.3);">
                <!-- Сообщения диалога -->
                <div id="guestChatBox" style="height: 480px; overflow-y: auto; padding: 24px; display: flex; flex-direction: column; gap: 16px; background: rgba(10,14,23,0.5);">
                    <?php foreach ($messages as $m): 
                        $isMe = ($m['sender_type'] === 'user');
                        $senderName = $isMe ? 'Вы' : 'Служба поддержки';
                    ?>
                        <div id="guest-msg-<?= (int)$m['id'] ?>" style="display: flex; justify-content: <?= $isMe ? 'flex-end' : 'flex-start' ?>; align-items: flex-end; gap: 10px;">
                            <?php if (!$isMe): ?>
                                <div style="width: 36px; height: 36px; border-radius: 50%; background: linear-gradient(135deg,#3b82f6,#8b5cf6); display: flex; align-items: center; justify-content: center; color: #fff; font-size: 14px; flex-shrink: 0;">
                                    <i class="fas fa-headset"></i>
                                </div>
                            <?php endif; ?>

                            <div style="max-width: 72%;">
                                <div style="font-size: 11px; color: var(--text-muted); margin-bottom: 4px; text-align: <?= $isMe ? 'right' : 'left' ?>;">
                                    <strong><?= htmlspecialchars($senderName) ?></strong>
                                    <?php if (!$isMe): ?>
                                        <span class="badge badge-info" style="font-size: 10px; padding: 2px 6px; margin-left: 4px;">Специалист</span>
                                    <?php endif; ?>
                                    • <?= date('d.m.Y H:i', strtotime($m['created_at'])) ?>
                                </div>
                                <div style="padding: 14px 18px; border-radius: 16px; font-size: 14px; line-height: 1.6; <?= $isMe ? 'background:linear-gradient(135deg,#4f46e5,#6366f1); color:#fff; border-bottom-right-radius:4px;' : 'background:#1f2937; border:1px solid #374151; color:#f3f4f6; border-bottom-left-radius:4px;' ?>">
                                    <?= nl2br(htmlspecialchars($m['message'])) ?>
                                </div>
                            </div>

                            <?php if ($isMe): ?>
                                <div style="width: 36px; height: 36px; border-radius: 50%; background: #374151; display: flex; align-items: center; justify-content: center; color: #9ca3af; font-size: 14px; flex-shrink: 0;">
                                    <i class="fas fa-user"></i>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Форма отправки сообщения (без перезагрузки страницы) -->
                <?php if ($activeTicket['status'] === 'closed' || $activeTicket['status'] === 'archive'): ?>
                    <div style="padding: 18px 24px; background: #111827; text-align: center; color: var(--text-muted); font-size: 13px;">
                        <i class="fas fa-lock"></i> Обращение закрыто. Если у вас возник вопрос, <a href="/support_guest.php" style="color: #818cf8;">создайте новое обращение</a>.
                    </div>
                <?php else: ?>
                    <form id="guestReplyForm" onsubmit="submitGuestReply(event); return false;" style="margin: 0; padding: 18px 24px; background: var(--bg-card); border-top: 1px solid var(--border-color);">
                        <?= Security::csrfField() ?>
                        <input type="hidden" name="action" value="send_reply">
                        <input type="hidden" name="ajax" value="1">
                        <div style="display: flex; gap: 12px; align-items: flex-end;">
                            <textarea 
                                name="message" 
                                id="guestChatInput" 
                                class="form-control" 
                                rows="2" 
                                placeholder="Напишите ответ в службу поддержки... (Enter или кнопка для отправки)" 
                                required 
                                style="resize: none; font-size: 14px; padding: 12px;"
                            ></textarea>
                            <button type="submit" id="guestSendBtn" class="btn btn-primary" style="padding: 14px 24px; height: 100%; display: flex; align-items: center; gap: 8px; font-weight: 700;">
                                <i class="fas fa-paper-plane"></i> <span>Отправить</span>
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>

            <script>
            let lastGuestMsgId = <?= !empty($messages) ? (int)end($messages)['id'] : 0 ?>;
            const guestTicketNum = <?= json_encode($activeTicket['ticket_number']) ?>;
            const guestToken = <?= json_encode((string)$activeTicket['guest_token']) ?>;

            function escapeHtmlG(str) {
                if (!str) return '';
                return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
            }

            async function submitGuestReply(e) {
                if (e) { e.preventDefault(); e.stopPropagation(); }
                const input = document.getElementById('guestChatInput');
                const text = (input?.value || '').trim();
                if (!text) return;

                input.value = '';
                input.style.height = '';

                const box = document.getElementById('guestChatBox');
                const tempId = 'gtemp-' + Date.now();
                const nowTime = new Date().toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });

                // Мгновенная отрисовка
                const div = document.createElement('div');
                div.id = tempId;
                div.style.cssText = 'display:flex; justify-content:flex-end; align-items:flex-end; gap:10px; opacity:0.92;';
                div.innerHTML = `
                    <div style="max-width:72%;">
                        <div style="font-size:11px; color:var(--text-muted); margin-bottom:4px; text-align:right;">
                            <strong>Вы</strong> • ${nowTime} <i class="fas fa-check" style="font-size:9px; opacity:0.7;"></i>
                        </div>
                        <div style="padding:14px 18px; border-radius:16px; font-size:14px; line-height:1.6; background:linear-gradient(135deg,#4f46e5,#6366f1); color:#fff; border-bottom-right-radius:4px;">
                            ${escapeHtmlG(text).replace(/\\n/g, '<br>')}
                        </div>
                    </div>
                    <div style="width:36px; height:36px; border-radius:50%; background:#374151; display:flex; align-items:center; justify-content:center; color:#9ca3af; font-size:14px; flex-shrink:0;">
                        <i class="fas fa-user"></i>
                    </div>
                `;
                if (box) {
                    box.appendChild(div);
                    box.scrollTop = box.scrollHeight;
                }

                try {
                    const form = document.getElementById('guestReplyForm');
                    const fd = new FormData(form);
                    fd.append('message', text);
                    fd.append('action', 'send_reply');
                    fd.append('ajax', '1');

                    const r = await fetch(window.location.href, {
                        method: 'POST',
                        body: fd,
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    });
                    const d = await r.json();
                    if (d.success && d.message_id) {
                        div.id = 'guest-msg-' + d.message_id;
                        div.style.opacity = '1';
                        lastGuestMsgId = Math.max(lastGuestMsgId, d.message_id);
                    } else if (!d.success) {
                        div.style.border = '1px solid #ef4444';
                        alert(d.error || 'Ошибка отправки');
                    }
                } catch(err) {
                    console.error('Guest reply error:', err);
                }
            }

            async function pollGuestChat() {
                try {
                    const url = `/support_guest.php?ticket=${encodeURIComponent(guestTicketNum)}&token=${encodeURIComponent(guestToken)}&action=poll_guest_messages&after_id=${lastGuestMsgId}`;
                    const r = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                    const d = await r.json();
                    if (d.success && d.messages && d.messages.length > 0) {
                        const box = document.getElementById('guestChatBox');
                        d.messages.forEach(m => {
                            if (m.id > lastGuestMsgId) lastGuestMsgId = m.id;
                            if (document.getElementById('guest-msg-' + m.id)) return;

                            const isMe = (m.sender_type === 'user');
                            const senderName = isMe ? 'Вы' : 'Служба поддержки';
                            const createdTime = new Date(m.created_at).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });

                            const mDiv = document.createElement('div');
                            mDiv.id = 'guest-msg-' + m.id;
                            mDiv.style.cssText = `display:flex; justify-content:${isMe ? 'flex-end' : 'flex-start'}; align-items:flex-end; gap:10px;`;
                            
                            let avatar = isMe
                                ? `<div style="width:36px; height:36px; border-radius:50%; background:#374151; display:flex; align-items:center; justify-content:center; color:#9ca3af; font-size:14px; flex-shrink:0;"><i class="fas fa-user"></i></div>`
                                : `<div style="width:36px; height:36px; border-radius:50%; background:linear-gradient(135deg,#3b82f6,#8b5cf6); display:flex; align-items:center; justify-content:center; color:#fff; font-size:14px; flex-shrink:0;"><i class="fas fa-headset"></i></div>`;

                            let badge = !isMe ? `<span class="badge badge-info" style="font-size:10px; padding:2px 6px; margin-left:4px;">Специалист</span>` : '';

                            mDiv.innerHTML = `
                                ${!isMe ? avatar : ''}
                                <div style="max-width:72%;">
                                    <div style="font-size:11px; color:var(--text-muted); margin-bottom:4px; text-align:${isMe ? 'right' : 'left'};">
                                        <strong>${senderName}</strong> ${badge} • ${createdTime}
                                    </div>
                                    <div style="padding:14px 18px; border-radius:16px; font-size:14px; line-height:1.6; ${isMe ? 'background:linear-gradient(135deg,#4f46e5,#6366f1); color:#fff; border-bottom-right-radius:4px;' : 'background:#1f2937; border:1px solid #374151; color:#f3f4f6; border-bottom-left-radius:4px;'}">
                                        ${escapeHtmlG(m.message).replace(/\\n/g, '<br>')}
                                    </div>
                                </div>
                                ${isMe ? avatar : ''}
                            `;
                            box.appendChild(mDiv);
                        });
                        box.scrollTop = box.scrollHeight;
                    }
                } catch(e) {}
            }

            window.addEventListener('DOMContentLoaded', () => {
                const b = document.getElementById('guestChatBox');
                if (b) b.scrollTop = b.scrollHeight;

                const inp = document.getElementById('guestChatInput');
                if (inp) {
                    inp.addEventListener('keydown', (e) => {
                        if ((e.ctrlKey || e.metaKey || !e.shiftKey) && e.key === 'Enter') {
                            e.preventDefault();
                            submitGuestReply(e);
                        }
                    });
                }
                setInterval(pollGuestChat, 3000);
            });
            </script>

        <?php else: ?>
            <!-- ══════════════════════════════════════════════════════════════════ -->
            <!-- ЭКРАН СОЗДАНИЯ ОБРАЩЕНИЯ И ПОИСКА                              -->
            <!-- ══════════════════════════════════════════════════════════════════ -->
            <div style="text-align: center; margin-bottom: 36px;">
                <div style="width: 72px; height: 72px; border-radius: 20px; background: linear-gradient(135deg, #3b82f6, #2563eb); display: inline-flex; align-items: center; justify-content: center; font-size: 32px; color: #fff; margin-bottom: 16px; box-shadow: 0 10px 25px rgba(59,130,246,0.35);">
                    <i class="fas fa-headset"></i>
                </div>
                <h1 style="font-size: 28px; margin: 0 0 10px; font-weight: 800;">Техническая поддержка онлайн</h1>
                <p style="color: var(--text-muted); font-size: 15px; max-width: 600px; margin: 0 auto; line-height: 1.5;">
                    Помощь доступна без регистрации. Задайте вопрос специалистам, сообщите об ошибке или проконсультируйтесь по тарифам и возможностям платформы.
                </p>
            </div>

            <!-- Вкладки: Создать обращение / Проверить статус -->
            <div style="display: flex; gap: 8px; justify-content: center; margin-bottom: 24px;">
                <button type="button" id="tabBtnCreate" onclick="switchSupportTab('create')" class="btn btn-primary" style="font-weight: 700; border-radius: 10px; padding: 10px 24px;">
                    <i class="fas fa-paper-plane"></i> Написать в поддержку
                </button>
                <button type="button" id="tabBtnLookup" onclick="switchSupportTab('lookup')" class="btn btn-secondary" style="font-weight: 700; border-radius: 10px; padding: 10px 24px;">
                    <i class="fas fa-search"></i> Проверить моё обращение
                </button>
            </div>

            <!-- Таб 1: Создание обращения -->
            <div id="tabContentCreate" class="card" style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius-lg); padding: 32px; box-shadow: 0 15px 35px rgba(0,0,0,0.25);">
                <form method="POST" action="/support_guest.php">
                    <?= Security::csrfField() ?>
                    <input type="hidden" name="action" value="create_ticket">

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                        <div class="form-group" style="margin: 0;">
                            <label class="form-label" style="font-weight: 600;">Ваше имя *</label>
                            <input 
                                type="text" 
                                name="guest_name" 
                                class="form-control" 
                                required 
                                placeholder="Иван Петров" 
                                value="<?= htmlspecialchars($currentUser ? ($currentUser['first_name'] . ' ' . $currentUser['last_name']) : ($_POST['guest_name'] ?? '')) ?>"
                            >
                        </div>
                        <div class="form-group" style="margin: 0;">
                            <label class="form-label" style="font-weight: 600;">Email для связи и ответа *</label>
                            <input 
                                type="email" 
                                name="guest_email" 
                                class="form-control" 
                                required 
                                placeholder="name@company.com" 
                                value="<?= htmlspecialchars($currentUser['email'] ?? ($_POST['guest_email'] ?? '')) ?>"
                            >
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 16px; margin-bottom: 16px;">
                        <div class="form-group" style="margin: 0;">
                            <label class="form-label" style="font-weight: 600;">Категория</label>
                            <select name="category" class="form-control">
                                <option value="Общий вопрос">Общий вопрос</option>
                                <option value="Вопрос по тарифам и оплате">Тарифы и оплата</option>
                                <option value="Активация ключа">Активация ключа</option>
                                <option value="Техническая ошибка / Баг">Техническая ошибка / Баг</option>
                                <option value="AI и интеграции">AI и интеграции</option>
                                <option value="Другое">Другое</option>
                            </select>
                        </div>
                        <div class="form-group" style="margin: 0;">
                            <label class="form-label" style="font-weight: 600;">Тема обращения *</label>
                            <input 
                                type="text" 
                                name="subject" 
                                class="form-control" 
                                required 
                                placeholder="Краткая суть вопроса" 
                                value="<?= htmlspecialchars($_POST['subject'] ?? '') ?>"
                            >
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: 24px;">
                        <label class="form-label" style="font-weight: 600;">Текст вопроса или описание проблемы *</label>
                        <textarea 
                            name="message" 
                            class="form-control" 
                            rows="5" 
                            required 
                            placeholder="Подробно опишите, с чем вам нужна помощь..."
                            style="resize: vertical;"
                        ><?= htmlspecialchars($_POST['message'] ?? '') ?></textarea>
                    </div>

                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px;">
                        <div style="font-size: 13px; color: var(--text-muted); display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-shield-alt text-primary"></i> 
                            Ответ поступит прямо в этот чат и будет продублирован на ваш email.
                        </div>
                        <button type="submit" class="btn btn-primary" style="font-size: 15px; font-weight: 700; padding: 12px 32px;">
                            <i class="fas fa-paper-plane"></i> Отправить обращение
                        </button>
                    </div>
                </form>
            </div>

            <!-- Таб 2: Поиск существующего обращения -->
            <div id="tabContentLookup" class="card" style="display: none; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius-lg); padding: 32px; box-shadow: 0 15px 35px rgba(0,0,0,0.25);">
                <form method="POST" action="/support_guest.php">
                    <?= Security::csrfField() ?>
                    <input type="hidden" name="action" value="lookup_ticket">

                    <p style="color: var(--text-muted); font-size: 14px; margin-bottom: 20px;">
                        Введите номер вашего обращения (вида <code>TCK-G-261102-XXXX</code>) и email, с которого оно было отправлено:
                    </p>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 24px;">
                        <div class="form-group" style="margin: 0;">
                            <label class="form-label" style="font-weight: 600;">Номер обращения *</label>
                            <input type="text" name="ticket_number" class="form-control" required placeholder="TCK-G-..." style="font-family: monospace; font-weight: 700;">
                        </div>
                        <div class="form-group" style="margin: 0;">
                            <label class="form-label" style="font-weight: 600;">Ваш Email *</label>
                            <input type="email" name="email" class="form-control" required placeholder="name@company.com">
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary" style="font-weight: 700; padding: 12px 28px;">
                        <i class="fas fa-search"></i> Открыть диалог с поддержкой
                    </button>
                </form>
            </div>

            <script>
            function switchSupportTab(tab) {
                const cTab = document.getElementById('tabContentCreate');
                const lTab = document.getElementById('tabContentLookup');
                const cBtn = document.getElementById('tabBtnCreate');
                const lBtn = document.getElementById('tabBtnLookup');

                if (tab === 'create') {
                    cTab.style.display = 'block';
                    lTab.style.display = 'none';
                    cBtn.className = 'btn btn-primary';
                    lBtn.className = 'btn btn-secondary';
                } else {
                    cTab.style.display = 'none';
                    lTab.style.display = 'block';
                    cBtn.className = 'btn btn-secondary';
                    lBtn.className = 'btn btn-primary';
                }
            }
            </script>
        <?php endif; ?>

    </main>

    <!-- Footer -->
    <footer style="padding: 24px 20px; text-align: center; border-top: 1px solid var(--border-color); color: var(--text-muted); font-size: 13px; margin-top: auto;">
        <div style="display: flex; justify-content: center; gap: 18px; margin-bottom: 8px;">
            <a href="/" style="color: var(--text-muted); text-decoration: none;">Главная</a>
            <span>•</span>
            <a href="/news.php" style="color: var(--text-muted); text-decoration: none;">Новости</a>
            <span>•</span>
            <a href="/user/activate_key.php" style="color: var(--text-muted); text-decoration: none;">Активировать ключ</a>
            <span>•</span>
            <a href="/login.php" style="color: var(--text-muted); text-decoration: none;">Вход</a>
        </div>
        <p style="margin: 0;">&copy; <?= date('Y') ?> AI Business Assistant. Круглосуточная техническая поддержка.</p>
    </footer>

    <script src="/assets/js/main.js"></script>
</body>
</html>
