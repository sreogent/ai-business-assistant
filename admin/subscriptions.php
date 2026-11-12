<?php
declare(strict_types=1);

/**
 * AI Business Assistant - Admin Subscriptions Management
 * Управление всеми подписками: просмотр, ручная деактивация/отзыв с отправкой письма, продление
 */

require_once dirname(__DIR__) . '/includes/Auth.php';
require_once dirname(__DIR__) . '/includes/Subscription.php';
require_once dirname(__DIR__) . '/includes/Mailer.php';

$admin = Auth::requireAdmin();
$pdo = Database::getConnection();
$adminId = (int)$admin['id'];

$error = null;
$success = null;

$cfg = $pdo->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$baseUrl = rtrim($cfg['app_base_url'] ?? 'http://127.0.0.1:8000', '/');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!Security::verifyCsrf($token)) {
        $error = "Истёк токен безопасности. Повторите попытку.";
    } else {
        $action = $_POST['action'] ?? '';

        // 1. Отзыв / деактивация подписки
        if ($action === 'revoke') {
            $subId = (int)($_POST['sub_id'] ?? 0);
            $reason = trim($_POST['reason'] ?? 'Подписка отозвана администратором');

            $stmt = $pdo->prepare("
                SELECT s.*, u.id AS user_id, u.first_name, u.last_name, u.email, t.name AS tariff_name
                FROM subscriptions s
                JOIN users u ON s.user_id = u.id
                JOIN tariffs t ON s.tariff_id = t.id
                WHERE s.id = ?
            ");
            $stmt->execute([$subId]);
            $sub = $stmt->fetch();

            if (!$sub) {
                $error = "Подписка не найдена.";
            } else {
                // Меняем статус на cancelled и сбрасываем дату окончания на текущее время
                $upd = $pdo->prepare("UPDATE subscriptions SET status = 'cancelled' WHERE id = ?");
                $upd->execute([$subId]);

                // Логируем действие
                Security::log(
                    $pdo,
                    'admin_subscription_revoked',
                    "Подписка ID: {$subId} пользователя #{$sub['user_id']} ({$sub['email']}) отозвана. Причина: {$reason}",
                    $adminId
                );

                // Отправляем письмо пользователю
                $fullName = trim($sub['first_name'] . ' ' . $sub['last_name']);
                $mailSent = Mailer::sendSubscriptionRevoked(
                    $sub['email'],
                    $fullName,
                    $sub['tariff_name'],
                    $reason,
                    $baseUrl
                );

                $success = "✅ Подписка пользователя <strong>" . htmlspecialchars($fullName) . "</strong> деактивирована."
                    . ($mailSent ? " Уведомление успешно отправлено на <strong>" . htmlspecialchars($sub['email']) . "</strong>." : " (Письмо сохранено в лог)");
            }
        }

        // 2. Повторная активация подписки
        elseif ($action === 'reactivate') {
            $subId = (int)($_POST['sub_id'] ?? 0);
            $addDays = max(1, (int)($_POST['add_days'] ?? 30));

            $stmt = $pdo->prepare("
                SELECT s.*, u.first_name, u.last_name, u.email, t.name AS tariff_name
                FROM subscriptions s
                JOIN users u ON s.user_id = u.id
                JOIN tariffs t ON s.tariff_id = t.id
                WHERE s.id = ?
            ");
            $stmt->execute([$subId]);
            $sub = $stmt->fetch();

            if (!$sub) {
                $error = "Подписка не найдена.";
            } else {
                $newEndDate = date('Y-m-d H:i:s', strtotime("+{$addDays} days"));
                $upd = $pdo->prepare("UPDATE subscriptions SET status = 'active', end_date = ? WHERE id = ?");
                $upd->execute([$newEndDate, $subId]);

                Security::log(
                    $pdo,
                    'admin_subscription_reactivated',
                    "Подписка ID: {$subId} пользователя {$sub['email']} продлена до {$newEndDate}",
                    $adminId
                );

                $fullName = trim($sub['first_name'] . ' ' . $sub['last_name']);
                Mailer::sendSubscriptionGranted(
                    $sub['email'],
                    $fullName,
                    $sub['tariff_name'],
                    $addDays,
                    date('d.m.Y H:i', strtotime($newEndDate))
                );

                $success = "✅ Подписка активирована до " . date('d.m.Y H:i', strtotime($newEndDate)) . ". Уведомление отправлено.";
            }
        }
    }
}

// Загружаем список всех подписок
$subscriptions = $pdo->query("
    SELECT s.*, 
           u.first_name, u.last_name, u.email, u.phone,
           t.name AS tariff_name, t.ai_requests_limit,
           (SELECT COUNT(*) FROM ai_history WHERE user_id = u.id AND DATE(created_at) = CURDATE()) AS ai_used_today,
           (SELECT COUNT(*) FROM ai_history WHERE user_id = u.id AND created_at >= s.start_date) AS ai_used_total
    FROM subscriptions s
    JOIN users u ON s.user_id = u.id
    JOIN tariffs t ON s.tariff_id = t.id
    ORDER BY s.id DESC
")->fetchAll();

$pageTitle = 'Управление подписками | AI Business Assistant';
require dirname(__DIR__) . '/includes/header.php';
?>

<div style="margin-bottom:24px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
    <div>
        <h2><i class="fas fa-id-card-alt text-primary"></i> Все подписки пользователей</h2>
        <p class="text-muted">Мониторинг, деактивация, отзыв подписок с автоматическим email-уведомлением клиенту</p>
    </div>
    <div style="display:flex; gap:10px;">
        <a href="/admin/subscription_keys.php" class="btn btn-primary">
            <i class="fas fa-key"></i> Выдать ключ / подписку
        </a>
        <a href="/admin/payment_requests.php" class="btn btn-secondary">
            <i class="fas fa-file-invoice"></i> Заявки
        </a>
    </div>
</div>

<?php if ($success): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $success ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:24px;">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Пользователь</th>
                    <th>Тариф</th>
                    <th>Срок действия</th>
                    <th>AI Запросы</th>
                    <th>Статус</th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($subscriptions)): ?>
                    <tr><td colspan="7" class="text-center text-muted" style="padding:32px;">Подписок пока нет</td></tr>
                <?php else: ?>
                    <?php foreach ($subscriptions as $s): ?>
                        <?php 
                            $isExpired = strtotime($s['end_date']) < time();
                            $status = $s['status'];
                            if ($status === 'active' && $isExpired) {
                                $status = 'expired';
                            }
                        ?>
                        <tr>
                            <td><?= $s['id'] ?></td>
                            <td>
                                <strong><?= htmlspecialchars($s['first_name'] . ' ' . $s['last_name']) ?></strong><br>
                                <small class="text-muted"><?= htmlspecialchars($s['email']) ?></small>
                                <?php if (!empty($s['phone'])): ?>
                                    <br><small class="text-muted"><i class="fas fa-phone-alt" style="font-size:10px;"></i> <?= htmlspecialchars($s['phone']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($s['tariff_name']) ?></strong>
                            </td>
                            <td>
                                <small>С: <?= date('d.m.Y', strtotime($s['start_date'])) ?></small><br>
                                <small>По: <?= date('d.m.Y H:i', strtotime($s['end_date'])) ?></small>
                            </td>
                            <td>
                                <span class="badge badge-primary" title="Ежедневный лимит (восстанавливается в 00:00)"><?= (int)$s['ai_used_today'] ?> / <?= $s['ai_requests_limit'] > 0 ? $s['ai_requests_limit'] : '∞' ?> / день</span><br>
                                <small class="text-muted" style="font-size:11px;">Всего за подписку: <?= (int)$s['ai_used_total'] ?></small>
                            </td>
                            <td>
                                <?php if ($status === 'active'): ?>
                                    <span class="badge badge-success"><i class="fas fa-check"></i> Активна</span>
                                <?php elseif ($status === 'cancelled'): ?>
                                    <span class="badge badge-danger"><i class="fas fa-ban"></i> Отозвана</span>
                                <?php else: ?>
                                    <span class="badge badge-secondary"><i class="fas fa-clock"></i> Истекла</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($status === 'active'): ?>
                                    <!-- Кнопка отозвать подписку с модальным окном причины -->
                                    <button type="button" class="btn btn-danger btn-sm" onclick="openRevokeModal(<?= $s['id'] ?>, '<?= htmlspecialchars(addslashes($s['first_name'] . ' ' . $s['last_name'])) ?>', '<?= htmlspecialchars(addslashes($s['tariff_name'])) ?>')">
                                        <i class="fas fa-times-circle"></i> Отобрать подписку
                                    </button>
                                <?php else: ?>
                                    <!-- Кнопка возобновить -->
                                    <form method="POST" style="display:inline-block;" onsubmit="return confirm('Возобновить подписку на 30 дней?')">
                                        <?= Security::csrfField() ?>
                                        <input type="hidden" name="action" value="reactivate">
                                        <input type="hidden" name="sub_id" value="<?= $s['id'] ?>">
                                        <input type="hidden" name="add_days" value="30">
                                        <button type="submit" class="btn btn-success btn-sm">
                                            <i class="fas fa-redo"></i> Возобновить (+30 дн.)
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Модальное окно отзыва подписки -->
<div id="revokeModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7); z-index:9999; align-items:center; justify-content:center; padding:20px;">
    <div style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); max-width:500px; width:100%; padding:28px; box-shadow:0 20px 40px rgba(0,0,0,0.5);">
        <h3 class="mb-2" style="color:#ef4444;"><i class="fas fa-exclamation-triangle"></i> Отозвать подписку</h3>
        <p class="text-muted mb-3" style="font-size:14px;">
            Вы собираетесь деактивировать подписку пользователя <strong id="modalUserName" class="text-primary"></strong> (тариф: <span id="modalTariffName"></span>).
            <br>Пользователю будет немедленно отправлено <strong>email-уведомление</strong> с причиной деактивации.
        </p>

        <form method="POST">
            <?= Security::csrfField() ?>
            <input type="hidden" name="action" value="revoke">
            <input type="hidden" name="sub_id" id="modalSubId" value="">

            <div class="form-group mb-3">
                <label class="form-label">Причина отзыва (будет указана в письме клиенту):</label>
                <textarea name="reason" class="form-control" rows="3" required placeholder="Например: Возврат средств / Нарушение условий / Запрос клиента...">Подписка отозвана администратором сервиса</textarea>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:12px;">
                <button type="button" class="btn btn-secondary" onclick="closeRevokeModal()">Отмена</button>
                <button type="submit" class="btn btn-danger">
                    <i class="fas fa-ban"></i> Подтвердить и отправить письмо
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openRevokeModal(subId, userName, tariffName) {
    document.getElementById('modalSubId').value = subId;
    document.getElementById('modalUserName').innerText = userName;
    document.getElementById('modalTariffName').innerText = tariffName;
    const modal = document.getElementById('revokeModal');
    modal.style.display = 'flex';
}
function closeRevokeModal() {
    document.getElementById('revokeModal').style.display = 'none';
}
</script>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
