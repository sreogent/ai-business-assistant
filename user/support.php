<?php
declare(strict_types=1);

/**
 * AI Business Assistant - User Support Tickets & Chat
 * Создание обращений в техподдержку и список диалогов пользователя
 */

require_once dirname(__DIR__) . '/includes/Auth.php';
require_once dirname(__DIR__) . '/includes/Mailer.php';

$user   = Auth::requireAuth('/login.php');
$userId = (int)$user['id'];
$pdo    = Database::getConnection();

$cfg = $pdo->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$baseUrl = rtrim($cfg['app_base_url'] ?? 'http://127.0.0.1:8000', '/');

$error = null;
$success = null;

// Обработка создания нового тикета
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!Security::verifyCsrf($token)) {
        $error = "Истёк токен безопасности. Повторите попытку.";
    } else {
        $subject  = trim($_POST['subject'] ?? '');
        $category = trim($_POST['category'] ?? 'Общий вопрос');
        $priority = trim($_POST['priority'] ?? 'medium');
        $message  = trim($_POST['message'] ?? '');

        if (empty($subject) || empty($message)) {
            $error = "Пожалуйста, укажите тему обращения и опишите вашу ситуацию.";
        } else {
            $ticketNumber = 'TCK-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));

            $pdo->beginTransaction();
            try {
                $insTicket = $pdo->prepare("
                    INSERT INTO support_tickets (ticket_number, user_id, subject, category, priority, status)
                    VALUES (?, ?, ?, ?, ?, 'pending')
                ");
                $insTicket->execute([$ticketNumber, $userId, $subject, $category, $priority]);
                $ticketId = (int)$pdo->lastInsertId();

                $insMsg = $pdo->prepare("
                    INSERT INTO support_messages (ticket_id, sender_id, sender_type, message, is_read)
                    VALUES (?, ?, 'user', ?, 0)
                ");
                $insMsg->execute([$ticketId, $userId, $message]);

                $pdo->commit();

                Security::log($pdo, 'user_create_support_ticket', "Создан тикет поддержки #{$ticketNumber} («{$subject}»)", $userId);

                // Отправка подтверждения на почту
                Mailer::sendSupportTicketCreated($user['email'], $user['first_name'], $ticketNumber, $subject, $category, $baseUrl);

                header("Location: /user/support_chat.php?ticket_id={$ticketId}&created=1");
                exit;
            } catch (\Throwable $e) {
                $pdo->rollBack();
                $error = "Не удалось создать тикет: " . $e->getMessage();
            }
        }
    }
}

// Статусы обращений
$statusConfig = [
    'pending'                => ['label' => 'Ожидание',                        'badge' => 'badge-warning',   'icon' => 'fa-clock'],
    'in_review'              => ['label' => 'Рассмотрено',                      'badge' => 'badge-success',   'icon' => 'fa-check-circle'],
    'transferred_admin'      => ['label' => 'Передано администратору',          'badge' => 'badge-info',      'icon' => 'fa-user-shield'],
    'transferred_management' => ['label' => 'Передано руководству',             'badge' => 'badge-primary',   'icon' => 'fa-crown'],
    'ignored'                => ['label' => 'Игнор',                            'badge' => 'badge-danger',    'icon' => 'fa-ban'],
    'archive'                => ['label' => 'Архив',                            'badge' => 'badge-secondary', 'icon' => 'fa-archive'],
    'closed'                 => ['label' => 'Закрыто',                          'badge' => 'badge-secondary', 'icon' => 'fa-lock'],
];

// Загрузка тикетов пользователя
$tStmt = $pdo->prepare("
    SELECT t.*, 
           (SELECT COUNT(*) FROM support_messages m WHERE m.ticket_id = t.id) AS msg_count,
           (SELECT message FROM support_messages m WHERE m.ticket_id = t.id ORDER BY m.id DESC LIMIT 1) AS last_message,
           (SELECT sender_type FROM support_messages m WHERE m.ticket_id = t.id ORDER BY m.id DESC LIMIT 1) AS last_sender
    FROM support_tickets t
    WHERE t.user_id = ?
    ORDER BY t.last_reply_at DESC, t.id DESC
");
$tStmt->execute([$userId]);
$tickets = $tStmt->fetchAll();

$pageTitle = 'Техподдержка & Чат | AI Business Assistant';
require dirname(__DIR__) . '/includes/header.php';
?>

<div style="margin-bottom:24px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
    <div>
        <h2><i class="fas fa-headset text-primary"></i> Служба технической поддержки</h2>
        <p class="text-muted">Оперативная помощь дежурных специалистов, консультации по платформе и решение вопросов</p>
    </div>
    <div>
        <button type="button" class="btn btn-primary" onclick="openNewTicketModal()">
            <i class="fas fa-plus-circle"></i> Создать обращение
        </button>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger" style="margin-bottom:24px;"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Список тикетов -->
<div class="card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:24px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
        <h3 style="font-size:17px; margin:0;"><i class="fas fa-comments text-secondary"></i> Мои обращения (<?= count($tickets) ?>)</h3>
        <button type="button" class="btn btn-secondary btn-sm" onclick="location.reload()"><i class="fas fa-sync"></i> Обновить</button>
    </div>

    <?php if (empty($tickets)): ?>
        <div style="text-align:center; padding:48px 20px;">
            <div style="width:64px; height:64px; border-radius:50%; background:rgba(99,102,241,0.1); color:#818cf8; font-size:28px; display:inline-flex; align-items:center; justify-content:center; margin-bottom:16px;">
                <i class="fas fa-headset"></i>
            </div>
            <h4 style="margin-bottom:6px;">У вас пока нет активных обращений</h4>
            <p class="text-muted" style="font-size:13px; max-width:420px; margin:0 auto 20px;">
                Если у вас возник вопрос по работе кассы, подключению организации, выписке документов или тарифам — создайте тикет, и наша команда ответит вам в чате.
            </p>
            <button type="button" class="btn btn-primary" onclick="openNewTicketModal()">
                <i class="fas fa-plus"></i> Создать первое обращение
            </button>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table" style="font-size:13px;">
                <thead>
                    <tr>
                        <th>Номер тикета</th>
                        <th>Тема вопроса</th>
                        <th>Категория</th>
                        <th>Приоритет</th>
                        <th>Последний ответ</th>
                        <th>Статус</th>
                        <th>Действие</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tickets as $t): 
                        $st = $statusConfig[$t['status']] ?? ['label' => $t['status'], 'badge' => 'badge-secondary', 'icon' => 'fa-info-circle'];
                    ?>
                        <tr>
                            <td>
                                <strong style="font-family:monospace; color:#818cf8; font-size:14px;">#<?= htmlspecialchars($t['ticket_number']) ?></strong>
                                <br><small class="text-muted"><?= date('d.m.Y H:i', strtotime($t['created_at'])) ?></small>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($t['subject']) ?></strong>
                                <?php if (!empty($t['last_message'])): ?>
                                    <div style="color:var(--text-muted); font-size:12px; margin-top:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:320px;">
                                        <?= $t['last_sender'] === 'admin' ? '<span style="color:#818cf8;">Поддержка:</span> ' : 'Вы: ' ?>
                                        <?= htmlspecialchars(mb_substr($t['last_message'], 0, 80)) ?>...
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge" style="background:#1f2937; color:#9ca3af; font-size:11px;">
                                    <?= htmlspecialchars($t['category']) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($t['priority'] === 'urgent'): ?>
                                    <span class="badge badge-danger">Срочный</span>
                                <?php elseif ($t['priority'] === 'high'): ?>
                                    <span class="badge badge-warning">Высокий</span>
                                <?php elseif ($t['priority'] === 'low'): ?>
                                    <span class="badge badge-secondary">Низкий</span>
                                <?php else: ?>
                                    <span class="badge badge-info">Средний</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <small class="text-muted"><?= date('d.m.Y H:i', strtotime($t['last_reply_at'] ?: $t['created_at'])) ?></small>
                            </td>
                            <td>
                                <span class="badge <?= $st['badge'] ?>">
                                    <i class="fas <?= $st['icon'] ?>"></i> <?= $st['label'] ?>
                                </span>
                            </td>
                            <td>
                                <a href="/user/support_chat.php?ticket_id=<?= $t['id'] ?>" class="btn btn-secondary btn-sm">
                                    <i class="fas fa-comment-dots"></i> Открыть чат
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Модальное окно создания обращения -->
<div id="newTicketModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7); z-index:9999; align-items:center; justify-content:center; padding:20px;">
    <div class="card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:32px; max-width:540px; width:100%; box-shadow:0 20px 40px rgba(0,0,0,0.5);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
            <h3 style="margin:0; font-size:18px;"><i class="fas fa-plus-circle text-primary"></i> Новое обращение в техподдержку</h3>
            <button type="button" onclick="closeNewTicketModal()" style="background:none; border:none; color:var(--text-muted); font-size:20px; cursor:pointer;">&times;</button>
        </div>

        <form method="POST">
            <?= Security::csrfField() ?>

            <div class="form-group mb-3">
                <label class="form-label">Тема обращения *</label>
                <input type="text" name="subject" class="form-control" required placeholder="Кратко опишите проблему (например: Вопрос по онлайн-кассе)">
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:14px;" class="mb-3">
                <div class="form-group">
                    <label class="form-label">Категория вопроса</label>
                    <select name="category" class="form-control">
                        <option value="Общий вопрос">Общий вопрос</option>
                        <option value="Финансы и Касса">Финансы и Касса (POS)</option>
                        <option value="Организация и Реквизиты">Организация и Реквизиты</option>
                        <option value="Меню и Номенклатура">Меню и Каталог товаров</option>
                        <option value="Тарифы и Подписка">Тарифы и Подписка</option>
                        <option value="Технические ошибки">Технические неполадки</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Приоритет</label>
                    <select name="priority" class="form-control">
                        <option value="medium" selected>Средний</option>
                        <option value="low">Низкий</option>
                        <option value="high">Высокий</option>
                        <option value="urgent">Срочный (Критично)</option>
                    </select>
                </div>
            </div>

            <div class="form-group mb-4">
                <label class="form-label">Подробное описание ситуации *</label>
                <textarea name="message" class="form-control" rows="5" required placeholder="Опишите подробно, с чем возникла сложность или какой у вас вопрос..."></textarea>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="btn btn-secondary" onclick="closeNewTicketModal()">Отмена</button>
                <button type="submit" class="btn btn-primary" style="font-weight:700;">
                    <i class="fas fa-paper-plane"></i> Отправить в поддержку
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openNewTicketModal() {
    document.getElementById('newTicketModal').style.display = 'flex';
}
function closeNewTicketModal() {
    document.getElementById('newTicketModal').style.display = 'none';
}
</script>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
