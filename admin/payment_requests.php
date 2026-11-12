<?php
declare(strict_types=1);

/**
 * AI Business Assistant - Admin: Заявки на оплату подписки
 * Администратор видит все входящие заявки, может одобрить/отклонить.
 * При одобрении — активирует подписку и выдаёт ключ/письмо пользователю.
 */

require_once dirname(__DIR__) . '/includes/Auth.php';
require_once dirname(__DIR__) . '/includes/Subscription.php';
require_once dirname(__DIR__) . '/includes/Mailer.php';

$admin   = Auth::requireAdmin();
$pdo     = Database::getConnection();
$adminId = (int)$admin['id'];

// Создаём таблицу если нет
$pdo->exec("CREATE TABLE IF NOT EXISTS payment_requests (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT NOT NULL,
    tariff_id     INT NOT NULL,
    custom_days   INT NULL,
    contact_info  VARCHAR(255) NULL,
    status        ENUM('pending','approved','rejected') DEFAULT 'pending',
    admin_note    TEXT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pr_user2   FOREIGN KEY (user_id)   REFERENCES users   (id) ON DELETE CASCADE,
    CONSTRAINT fk_pr_tariff2 FOREIGN KEY (tariff_id) REFERENCES tariffs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$error   = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!Security::verifyCsrf($token)) {
        $error = "Истёк токен безопасности.";
    } else {
        $action    = $_POST['action'] ?? '';
        $requestId = (int)($_POST['request_id'] ?? 0);
        $adminNote = Security::sanitize(trim($_POST['admin_note'] ?? ''));

        // Загружаем заявку
        $reqStmt = $pdo->prepare("
            SELECT pr.*, u.first_name, u.last_name, u.email, t.name AS tariff_name, t.duration_days, t.code AS tariff_code
            FROM payment_requests pr
            JOIN users u ON pr.user_id = u.id
            JOIN tariffs t ON pr.tariff_id = t.id
            WHERE pr.id = ?
        ");
        $reqStmt->execute([$requestId]);
        $req = $reqStmt->fetch();

        if (!$req) {
            $error = "Заявка не найдена.";
        } elseif ($action === 'approve') {
            // Определяем кол-во дней
            $days = ($req['tariff_code'] === 'CUSTOM' && $req['custom_days'] > 0)
                ? (int)$req['custom_days']
                : (int)$req['duration_days'];

            if (Subscription::subscribe((int)$req['user_id'], (int)$req['tariff_id'], $days > 0 ? $days : null)) {
                $pdo->prepare("UPDATE payment_requests SET status = 'approved', admin_note = ? WHERE id = ?")->execute([$adminNote, $requestId]);

                $endDate = date('d.m.Y', strtotime("+{$days} days"));
                Mailer::sendSubscriptionGranted(
                    $req['email'],
                    $req['first_name'] . ' ' . $req['last_name'],
                    $req['tariff_name'],
                    $days,
                    $endDate
                );

                Security::log($pdo, 'admin_approve_payment', "Одобрена заявка #{$requestId} для {$req['email']} (тариф: {$req['tariff_name']})", $adminId);
                $success = "✅ Заявка одобрена. Подписка «{$req['tariff_name']}» активирована для {$req['first_name']} {$req['last_name']}. Уведомление отправлено на {$req['email']}.";
            } else {
                $error = "Ошибка при активации подписки.";
            }
        } elseif ($action === 'reject') {
            $pdo->prepare("UPDATE payment_requests SET status = 'rejected', admin_note = ? WHERE id = ?")->execute([$adminNote, $requestId]);
            Security::log($pdo, 'admin_reject_payment', "Отклонена заявка #{$requestId} для {$req['email']}", $adminId);
            $success = "Заявка #{$requestId} отклонена.";
        }
    }
}

$requests = $pdo->query("
    SELECT pr.*, u.first_name, u.last_name, u.email, t.name AS tariff_name, t.price, t.duration_days, t.code AS tariff_code
    FROM payment_requests pr
    JOIN users u ON pr.user_id = u.id
    JOIN tariffs t ON pr.tariff_id = t.id
    ORDER BY pr.status ASC, pr.id DESC
")->fetchAll();

$pending = array_filter($requests, fn($r) => $r['status'] === 'pending');

$pageTitle = 'Заявки на подписку | AI Business Assistant';
require dirname(__DIR__) . '/includes/header.php';
?>

<div style="margin-bottom:24px;">
    <h2><i class="fas fa-file-invoice text-warning"></i> Заявки на оплату подписок</h2>
    <p class="text-muted">Пользователи, оставившие заявку на подписку. Свяжитесь с ними, примите оплату и одобрите заявку.</p>
</div>

<?php if ($success): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $success ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if (!empty($pending)): ?>
    <div class="alert" style="background:rgba(245,158,11,0.1);border:1px solid rgba(245,158,11,0.3);border-radius:var(--radius-lg);padding:14px 18px;margin-bottom:20px;">
        <i class="fas fa-bell" style="color:#f59e0b;"></i>
        <strong style="color:#f59e0b;">Ожидают рассмотрения: <?= count($pending) ?> заявок</strong>
    </div>
<?php endif; ?>

<div class="card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:24px;">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Пользователь</th>
                    <th>Тариф / Цена</th>
                    <th>Контакт</th>
                    <th>Статус</th>
                    <th>Дата</th>
                    <th>Действие</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($requests)): ?>
                    <tr><td colspan="7" class="text-center text-muted" style="padding:30px;">Заявок пока нет</td></tr>
                <?php else: ?>
                    <?php foreach ($requests as $r): ?>
                        <tr style="<?= $r['status'] === 'pending' ? 'background:rgba(245,158,11,0.05);' : '' ?>">
                            <td><?= $r['id'] ?></td>
                            <td>
                                <strong><?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) ?></strong><br>
                                <small class="text-muted"><?= htmlspecialchars($r['email']) ?></small>
                            </td>
                            <td>
                                <?= htmlspecialchars($r['tariff_name']) ?>
                                <?php if ($r['custom_days']): ?>
                                    <small class="text-muted">(<?= $r['custom_days'] ?> дн.)</small>
                                <?php else: ?>
                                    <small class="text-muted">(<?= $r['duration_days'] ?> дн.)</small>
                                <?php endif; ?>
                                <br>
                                <?php
                                $sumToDisplay = (!empty($r['amount']) && (float)$r['amount'] > 0)
                                    ? (float)$r['amount']
                                    : (float)$r['price'];
                                ?>
                                <strong class="text-primary" style="font-size:14px;"><?= number_format($sumToDisplay, 0, '.', ' ') ?> ₸</strong>
                            </td>
                            <td><small><?= htmlspecialchars($r['contact_info'] ?? '—') ?></small></td>
                            <td>
                                <?php if ($r['status'] === 'pending'): ?>
                                    <span class="badge badge-warning"><i class="fas fa-clock"></i> Ожидание</span>
                                <?php elseif ($r['status'] === 'approved'): ?>
                                    <span class="badge badge-success"><i class="fas fa-check"></i> Одобрена</span>
                                <?php else: ?>
                                    <span class="badge badge-danger"><i class="fas fa-times"></i> Отклонена</span>
                                <?php endif; ?>
                                <?php if ($r['admin_note']): ?>
                                    <br><small class="text-muted"><?= htmlspecialchars($r['admin_note']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td><small class="text-muted"><?= date('d.m.Y H:i', strtotime($r['created_at'])) ?></small></td>
                            <td>
                                <?php if ($r['status'] === 'pending'): ?>
                                    <!-- Одобрить -->
                                    <form method="POST" style="margin-bottom:8px;" onsubmit="return confirm('Подтвердить оплату и активировать подписку?')">
                                        <?= Security::csrfField() ?>
                                        <input type="hidden" name="action" value="approve">
                                        <input type="hidden" name="request_id" value="<?= $r['id'] ?>">
                                        <input type="text" name="admin_note" class="form-control mb-1" style="font-size:12px;" placeholder="Примечание (необязательно)">
                                        <button type="submit" class="btn btn-success btn-sm btn-block">
                                            <i class="fas fa-check"></i> Одобрить & Активировать
                                        </button>
                                    </form>
                                    <!-- Отклонить -->
                                    <form method="POST" onsubmit="return confirm('Отклонить заявку?')">
                                        <?= Security::csrfField() ?>
                                        <input type="hidden" name="action" value="reject">
                                        <input type="hidden" name="request_id" value="<?= $r['id'] ?>">
                                        <input type="text" name="admin_note" class="form-control mb-1" style="font-size:12px;" placeholder="Причина отказа">
                                        <button type="submit" class="btn btn-danger btn-sm btn-block">
                                            <i class="fas fa-times"></i> Отклонить
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-muted" style="font-size:12px;">Обработана</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
