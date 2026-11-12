<?php
declare(strict_types=1);

/**
 * AI Business Assistant - User Support Chat
 * Чат диалога пользователя со службой технической поддержки
 */

require_once dirname(__DIR__) . '/includes/Auth.php';

$user   = Auth::requireAuth('/login.php');
$userId = (int)$user['id'];
$pdo    = Database::getConnection();

$ticketId = (int)($_GET['ticket_id'] ?? 0);
$ticketNum = trim($_GET['ticket'] ?? '');

if ($ticketId <= 0 && !empty($ticketNum)) {
    $tLookup = $pdo->prepare("SELECT id FROM support_tickets WHERE ticket_number = ? AND user_id = ? LIMIT 1");
    $tLookup->execute([$ticketNum, $userId]);
    $ticketId = (int)$tLookup->fetchColumn();
}

$stmt = $pdo->prepare("SELECT * FROM support_tickets WHERE id = ? AND user_id = ? LIMIT 1");
$stmt->execute([$ticketId, $userId]);
$ticket = $stmt->fetch();

if (!$ticket) {
    header("Location: /user/support.php");
    exit;
}

$error = null;

// AJAX: Получение новых сообщений тикета в реальном времени
if (isset($_GET['action']) && $_GET['action'] === 'poll_messages') {
    header('Content-Type: application/json; charset=utf-8');
    $afterId = (int)($_GET['after_id'] ?? 0);
    $pollStmt = $pdo->prepare("
        SELECT m.*, u.first_name, u.last_name, r.name AS role_name
        FROM support_messages m
        LEFT JOIN users u ON m.sender_id = u.id
        LEFT JOIN roles r ON u.role_id = r.id
        WHERE m.ticket_id = ? AND m.id > ?
        ORDER BY m.id ASC
    ");
    $pollStmt->execute([$ticketId, $afterId]);
    $newMsgs = $pollStmt->fetchAll(PDO::FETCH_ASSOC);

    // Отмечаем прочитанными сообщения поддержки
    if (!empty($newMsgs)) {
        $pdo->prepare("UPDATE support_messages SET is_read = 1 WHERE ticket_id = ? AND sender_type = 'admin'")->execute([$ticketId]);
    }

    echo json_encode(['success' => true, 'messages' => $newMsgs]);
    exit;
}

// Обработка отправки нового сообщения пользователем (обычная форма или AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') 
           || (isset($_POST['ajax']) && $_POST['ajax'] === '1');

    $token = $_POST['csrf_token'] ?? '';
    if (!Security::verifyCsrf($token)) {
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'Истёк токен безопасности']);
            exit;
        }
        $error = "Истёк токен безопасности. Повторите попытку.";
    } else {
        $message = trim($_POST['message'] ?? '');
        if (!empty($message)) {
            $ins = $pdo->prepare("
                INSERT INTO support_messages (ticket_id, sender_id, sender_type, message, is_read)
                VALUES (?, ?, 'user', ?, 0)
            ");
            $ins->execute([$ticketId, $userId, $message]);
            $newMsgId = (int)$pdo->lastInsertId();

            // Обновляем время ответа и статус тикета
            $newStatus = in_array($ticket['status'], ['in_review', 'closed', 'archive']) ? 'pending' : $ticket['status'];
            $pdo->prepare("
                UPDATE support_tickets 
                SET last_reply_at = NOW(), status = ? 
                WHERE id = ?
            ")->execute([$newStatus, $ticketId]);

            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => true, 
                    'message_id' => $newMsgId, 
                    'created_at' => date('d.m.Y H:i')
                ]);
                exit;
            }

            header("Location: /user/support_chat.php?ticket_id={$ticketId}");
            exit;
        }
    }
}

// Отмечаем сообщения службы поддержки как прочитанные
$pdo->prepare("UPDATE support_messages SET is_read = 1 WHERE ticket_id = ? AND sender_type = 'admin'")->execute([$ticketId]);

// Загружаем всю историю сообщений
$msgStmt = $pdo->prepare("
    SELECT m.*, u.first_name, u.last_name, r.name AS role_name
    FROM support_messages m
    LEFT JOIN users u ON m.sender_id = u.id
    LEFT JOIN roles r ON u.role_id = r.id
    WHERE m.ticket_id = ?
    ORDER BY m.id ASC
");
$msgStmt->execute([$ticketId]);
$messages = $msgStmt->fetchAll();

// Статусы
$statusConfig = [
    'pending'                => ['label' => 'Ожидание ответа',                 'badge' => 'badge-warning',   'icon' => 'fa-clock'],
    'in_review'              => ['label' => 'Рассмотрено / В работе',          'badge' => 'badge-success',   'icon' => 'fa-check-circle'],
    'transferred_admin'      => ['label' => 'Передано администратору',          'badge' => 'badge-info',      'icon' => 'fa-user-shield'],
    'transferred_management' => ['label' => 'Передано руководству',             'badge' => 'badge-primary',   'icon' => 'fa-crown'],
    'ignored'                => ['label' => 'Игнор',                            'badge' => 'badge-danger',    'icon' => 'fa-ban'],
    'archive'                => ['label' => 'Архив',                            'badge' => 'badge-secondary', 'icon' => 'fa-archive'],
    'closed'                 => ['label' => 'Закрыто',                          'badge' => 'badge-secondary', 'icon' => 'fa-lock'],
];
$st = $statusConfig[$ticket['status']] ?? ['label' => $ticket['status'], 'badge' => 'badge-secondary', 'icon' => 'fa-info-circle'];

$pageTitle = "Тикет #{$ticket['ticket_number']} | AI Business Assistant";
require dirname(__DIR__) . '/includes/header.php';
?>

<div style="max-width:960px; margin:0 auto;">
    <!-- Верхняя панель с навигацией и статусом тикета -->
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:20px;">
        <div>
            <a href="/user/support.php" class="btn btn-secondary btn-sm" style="margin-bottom:8px;">
                <i class="fas fa-arrow-left"></i> Назад ко всем обращениям
            </a>
            <h2 style="margin:0; font-size:20px;">
                <span style="font-family:monospace; color:#818cf8;">#<?= htmlspecialchars($ticket['ticket_number']) ?></span>: 
                <?= htmlspecialchars($ticket['subject']) ?>
            </h2>
        </div>
        <div style="display:flex; align-items:center; gap:8px;">
            <span class="badge" style="background:#1f2937; color:#9ca3af; font-size:12px; padding:6px 12px;">
                <i class="fas fa-folder"></i> <?= htmlspecialchars($ticket['category']) ?>
            </span>
            <span class="badge <?= $st['badge'] ?>" style="font-size:12px; padding:6px 12px;">
                <i class="fas <?= $st['icon'] ?>"></i> <?= $st['label'] ?>
            </span>
        </div>
    </div>

    <?php if (isset($_GET['created'])): ?>
        <div class="alert alert-success" style="font-size:14px; margin-bottom:20px;">
            <i class="fas fa-check-circle"></i> Ваше обращение успешно зарегистрировано! Специалист службы поддержки уже подключился.
        </div>
    <?php endif; ?>

    <!-- Область чата -->
    <div class="card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:0; overflow:hidden; box-shadow:0 10px 30px rgba(0,0,0,0.3);">
        
        <!-- Лента сообщений -->
        <div id="chatMessages" style="height:520px; overflow-y:auto; padding:24px; display:flex; flex-direction:column; gap:16px; background:rgba(10,14,23,0.5);">
            <?php foreach ($messages as $m): 
                $isMe = ($m['sender_type'] === 'user');
                $senderName = $isMe 
                    ? ($m['first_name'] ? htmlspecialchars($m['first_name'] . ' ' . $m['last_name']) : 'Вы')
                    : 'Служба поддержки';
            ?>
                <div style="display:flex; justify-content:<?= $isMe ? 'flex-end' : 'flex-start' ?>; align-items:flex-end; gap:10px;">
                    <?php if (!$isMe): ?>
                        <div style="width:36px; height:36px; border-radius:50%; background:linear-gradient(135deg,#3b82f6,#8b5cf6); display:flex; align-items:center; justify-content:center; color:#fff; font-size:14px; flex-shrink:0;">
                            <i class="fas fa-headset"></i>
                        </div>
                    <?php endif; ?>

                    <div style="max-width:72%;">
                        <div style="font-size:11px; color:var(--text-muted); margin-bottom:4px; text-align:<?= $isMe ? 'right' : 'left' ?>;">
                            <strong><?= $senderName ?></strong>
                            <?php if (!$isMe): ?>
                                <span class="badge badge-info" style="font-size:10px; padding:2px 6px; margin-left:4px;">Специалист</span>
                            <?php endif; ?>
                            • <?= date('d.m.Y H:i', strtotime($m['created_at'])) ?>
                        </div>

                        <div style="
                            padding:14px 18px; 
                            border-radius:16px; 
                            font-size:14px; 
                            line-height:1.6;
                            <?= $isMe 
                                ? 'background:linear-gradient(135deg,#4f46e5,#6366f1); color:#fff; border-bottom-right-radius:4px;' 
                                : 'background:#1f2937; border:1px solid #374151; color:#f3f4f6; border-bottom-left-radius:4px;' 
                            ?>
                        ">
                            <?= nl2br(htmlspecialchars($m['message'])) ?>
                        </div>
                    </div>

                    <?php if ($isMe): ?>
                        <div style="width:36px; height:36px; border-radius:50%; background:#374151; display:flex; align-items:center; justify-content:center; color:#9ca3af; font-size:14px; flex-shrink:0;">
                            <i class="fas fa-user"></i>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Поле ввода ответа -->
        <?php if ($ticket['status'] === 'archive' || $ticket['status'] === 'closed'): ?>
            <div style="padding:18px 24px; background:#111827; border-top:1px solid var(--border-color); text-align:center; color:var(--text-muted); font-size:13px;">
                <i class="fas fa-lock"></i> Данное обращение закрыто или находится в архиве. Если у вас возник новый вопрос, пожалуйста, <a href="/user/support.php" style="color:#818cf8;">создайте новый тикет</a>.
            </div>
        <?php else: ?>
            <form id="supportReplyForm" onsubmit="sendSupportMessage(event); return false;" style="margin:0; padding:18px 24px; background:var(--bg-card); border-top:1px solid var(--border-color);">
                <?= Security::csrfField() ?>
                <input type="hidden" name="ajax" value="1">
                <div style="display:flex; gap:12px; align-items:flex-end;">
                    <textarea 
                        name="message" 
                        id="chatInput" 
                        class="form-control" 
                        rows="2" 
                        placeholder="Напишите ответ или уточнение... (Enter или Ctrl+Enter — мгновенная отправка)" 
                        required 
                        style="resize:none; font-size:14px; padding:12px;"
                    ></textarea>
                    <button type="submit" id="supportSendBtn" class="btn btn-primary" style="padding:14px 24px; height:100%; display:flex; align-items:center; gap:8px; font-weight:700;">
                        <i class="fas fa-paper-plane"></i> <span>Отправить</span>
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<script>
let lastSupportMsgId = <?= !empty($messages) ? (int)end($messages)['id'] : 0 ?>;
const ticketId = <?= $ticketId ?>;

function escapeHtmlSupport(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

async function sendSupportMessage(e) {
    if (e) {
        e.preventDefault();
        e.stopPropagation();
    }
    const input = document.getElementById('chatInput');
    const msg = (input?.value || '').trim();
    if (!msg) return;

    input.value = '';
    input.style.height = '';

    const box = document.getElementById('chatMessages');
    const tempId = 'supp-temp-' + Date.now();
    const nowTime = new Date().toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });

    // Оптимистичное добавление пузыря
    const div = document.createElement('div');
    div.id = tempId;
    div.style.cssText = 'display:flex; justify-content:flex-end; align-items:flex-end; gap:10px; opacity:0.92;';
    div.innerHTML = `
        <div style="max-width:72%;">
            <div style="font-size:11px; color:var(--text-muted); margin-bottom:4px; text-align:right;">
                <strong>Вы</strong> • ${nowTime} <i class="fas fa-check" style="font-size:9px; opacity:0.7;"></i>
            </div>
            <div style="padding:14px 18px; border-radius:16px; font-size:14px; line-height:1.6; background:linear-gradient(135deg,#4f46e5,#6366f1); color:#fff; border-bottom-right-radius:4px;">
                ${escapeHtmlSupport(msg).replace(/\\n/g, '<br>')}
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
        const form = document.getElementById('supportReplyForm');
        const fd = new FormData(form);
        fd.append('message', msg);
        fd.append('ajax', '1');

        const r = await fetch(window.location.href, {
            method: 'POST',
            body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await r.json();
        if (data.success && data.message_id) {
            div.id = 'supp-msg-' + data.message_id;
            div.style.opacity = '1';
            lastSupportMsgId = Math.max(lastSupportMsgId, data.message_id);
        } else if (!data.success) {
            div.style.border = '1px solid #ef4444';
            alert(data.error || 'Ошибка отправки');
        }
    } catch(err) {
        console.error('Support send error:', err);
    }
}

async function pollSupportMessages() {
    try {
        const r = await fetch(`/user/support_chat.php?ticket_id=${ticketId}&action=poll_messages&after_id=${lastSupportMsgId}`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await r.json();
        if (data.success && data.messages && data.messages.length > 0) {
            const box = document.getElementById('chatMessages');
            data.messages.forEach(m => {
                if (m.id > lastSupportMsgId) lastSupportMsgId = m.id;
                if (document.getElementById('supp-msg-' + m.id)) return;

                const isMe = (m.sender_type === 'user');
                const senderName = isMe ? 'Вы' : 'Служба поддержки';
                const createdTime = new Date(m.created_at).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });

                const msgDiv = document.createElement('div');
                msgDiv.id = 'supp-msg-' + m.id;
                msgDiv.style.cssText = `display:flex; justify-content:${isMe ? 'flex-end' : 'flex-start'}; align-items:flex-end; gap:10px;`;
                
                let avatar = isMe
                    ? `<div style="width:36px; height:36px; border-radius:50%; background:#374151; display:flex; align-items:center; justify-content:center; color:#9ca3af; font-size:14px; flex-shrink:0;"><i class="fas fa-user"></i></div>`
                    : `<div style="width:36px; height:36px; border-radius:50%; background:linear-gradient(135deg,#3b82f6,#8b5cf6); display:flex; align-items:center; justify-content:center; color:#fff; font-size:14px; flex-shrink:0;"><i class="fas fa-headset"></i></div>`;

                let badge = !isMe ? `<span class="badge badge-info" style="font-size:10px; padding:2px 6px; margin-left:4px;">Специалист</span>` : '';

                msgDiv.innerHTML = `
                    ${!isMe ? avatar : ''}
                    <div style="max-width:72%;">
                        <div style="font-size:11px; color:var(--text-muted); margin-bottom:4px; text-align:${isMe ? 'right' : 'left'};">
                            <strong>${senderName}</strong> ${badge} • ${createdTime}
                        </div>
                        <div style="padding:14px 18px; border-radius:16px; font-size:14px; line-height:1.6; ${isMe ? 'background:linear-gradient(135deg,#4f46e5,#6366f1); color:#fff; border-bottom-right-radius:4px;' : 'background:#1f2937; border:1px solid #374151; color:#f3f4f6; border-bottom-left-radius:4px;'}">
                            ${escapeHtmlSupport(m.message).replace(/\\n/g, '<br>')}
                        </div>
                    </div>
                    ${isMe ? avatar : ''}
                `;
                box.appendChild(msgDiv);
            });
            box.scrollTop = box.scrollHeight;
        }
    } catch(e) {}
}

window.addEventListener('DOMContentLoaded', function() {
    var chat = document.getElementById('chatMessages');
    if (chat) chat.scrollTop = chat.scrollHeight;

    var input = document.getElementById('chatInput');
    if (input) {
        input.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey || !e.shiftKey) && e.key === 'Enter') {
                e.preventDefault();
                sendSupportMessage(e);
            }
        });
    }

    // Фоновый опрос ответов службы поддержки каждые 3 секунды
    setInterval(pollSupportMessages, 3000);
});
</script>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
