<?php
declare(strict_types=1);

/**
 * AI Business Assistant - Admin: Subscription Keys & Direct Grant Manager
 *
 * Admin can:
 * 1. Generate a unique one-time activation key for any tariff and email it to any address
 * 2. Directly grant a subscription to an existing user and notify them by email
 * 3. View all issued keys with statuses and revoke them
 */

require_once dirname(__DIR__) . '/includes/Auth.php';
require_once dirname(__DIR__) . '/includes/Subscription.php';
require_once dirname(__DIR__) . '/includes/Mailer.php';

$admin = Auth::requireAdmin();
$pdo = Database::getConnection();
$adminId = (int)$admin['id'];

// --- Create subscription_keys table if it was just added ---
$pdo->exec("CREATE TABLE IF NOT EXISTS subscription_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    key_code VARCHAR(64) NOT NULL UNIQUE,
    tariff_id INT NOT NULL,
    created_by INT NULL,
    recipient_email VARCHAR(191) NULL,
    status ENUM('active', 'used', 'revoked') NOT NULL DEFAULT 'active',
    used_by INT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_key_tariff2 FOREIGN KEY (tariff_id) REFERENCES tariffs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$error = null;
$success = null;

$appBaseUrlRow = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'app_base_url' LIMIT 1");
$appBaseUrlRow->execute();
$appBaseUrl = rtrim((string)($appBaseUrlRow->fetchColumn() ?: 'http://127.0.0.1:8000'), '/');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!Security::verifyCsrf($token)) {
        $error = "Истек токен безопасности. Повторите попытку.";
    } else {
        $action = $_POST['action'] ?? '';

        // ── Action 1: Generate & Email Key ────────────────────────────────────
        if ($action === 'generate_key') {
            $tariffId    = (int)($_POST['tariff_id'] ?? 0);
            $recipientEmail = trim($_POST['recipient_email'] ?? '');
            $recipientName  = trim($_POST['recipient_name']  ?? 'Клиент');

            if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
                $error = "Введите корректный email получателя.";
            } elseif ($tariffId <= 0) {
                $error = "Выберите тарифный план.";
            } else {
                $tariffStmt = $pdo->prepare("SELECT * FROM tariffs WHERE id = ? AND is_active = 1");
                $tariffStmt->execute([$tariffId]);
                $tariff = $tariffStmt->fetch();

                if (!$tariff) {
                    $error = "Тариф не найден или неактивен.";
                } else {
                    // Generate cryptographically secure key: XXXX-XXXX-XXXX-XXXX
                    $rawKey  = strtoupper(bin2hex(random_bytes(8)));
                    $keyCode = implode('-', str_split($rawKey, 4));

                    $insertKey = $pdo->prepare("
                        INSERT INTO subscription_keys (key_code, tariff_id, created_by, recipient_email, status)
                        VALUES (?, ?, ?, ?, 'active')
                    ");
                    $insertKey->execute([$keyCode, $tariffId, $adminId, $recipientEmail]);

                    $activateUrl = "{$appBaseUrl}/user/activate_key.php?key=" . urlencode($keyCode);
                    $days = (int)$tariff['duration_days'];

                    $sent = Mailer::sendSubscriptionKey(
                        $recipientEmail,
                        $recipientName,
                        $keyCode,
                        $tariff['name'],
                        $days,
                        $activateUrl
                    );

                    Security::log($pdo, 'admin_issued_key', "Ключ {$keyCode} выдан на {$recipientEmail} (тариф: {$tariff['name']})", $adminId);

                    $success = "✅ Ключ <strong>{$keyCode}</strong> успешно создан"
                        . ($sent ? " и отправлен на <strong>{$recipientEmail}</strong>." : ". Email не отправлен (SMTP не настроен) — ключ сохранён в журнале писем.");
                }
            }
        }

        // ── Action 2: Direct Subscription Grant ───────────────────────────────
        elseif ($action === 'grant_direct') {
            $userId   = (int)($_POST['user_id'] ?? 0);
            $tariffId = (int)($_POST['tariff_id_grant'] ?? 0);
            $customDays = !empty($_POST['custom_days_grant']) ? (int)$_POST['custom_days_grant'] : null;

            if ($userId <= 0 || $tariffId <= 0) {
                $error = "Выберите пользователя и тарифный план.";
            } else {
                $userStmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                $userStmt->execute([$userId]);
                $targetUser = $userStmt->fetch();

                $tariffStmt = $pdo->prepare("SELECT * FROM tariffs WHERE id = ?");
                $tariffStmt->execute([$tariffId]);
                $tariff = $tariffStmt->fetch();

                if (!$targetUser || !$tariff) {
                    $error = "Пользователь или тариф не найдены.";
                } else {
                    if (Subscription::subscribe($userId, $tariffId, $customDays)) {
                        $days    = ($tariff['code'] === 'CUSTOM' && $customDays) ? $customDays : (int)$tariff['duration_days'];
                        $endDate = date('d.m.Y', strtotime("+{$days} days"));

                        Mailer::sendSubscriptionGranted(
                            $targetUser['email'],
                            $targetUser['first_name'] . ' ' . $targetUser['last_name'],
                            $tariff['name'],
                            $days,
                            $endDate
                        );

                        Security::log($pdo, 'admin_grant_subscription', "Администратор выдал подписку '{$tariff['name']}' пользователю #{$userId}", $adminId);
                        $success = "✅ Подписка «{$tariff['name']}» активирована пользователю <strong>{$targetUser['first_name']} {$targetUser['last_name']}</strong> до {$endDate}. Уведомление отправлено на {$targetUser['email']}.";
                    } else {
                        $error = "Не удалось активировать подписку. Проверьте параметры тарифа.";
                    }
                }
            }
        }

        // ── Action 3: Revoke Key ───────────────────────────────────────────────
        elseif ($action === 'revoke_key') {
            $keyId = (int)($_POST['key_id'] ?? 0);
            $rev = $pdo->prepare("UPDATE subscription_keys SET status = 'revoked' WHERE id = ? AND status = 'active'");
            $rev->execute([$keyId]);
            Security::log($pdo, 'admin_revoke_key', "Ключ ID#{$keyId} аннулирован", $adminId);
            $success = "Ключ успешно аннулирован.";
        }
    }
}

// Fetch data for display
$tariffs = $pdo->query("SELECT * FROM tariffs WHERE is_active = 1 ORDER BY duration_days ASC")->fetchAll();
$users   = $pdo->query("SELECT id, first_name, last_name, email FROM users ORDER BY id DESC")->fetchAll();

$keys = $pdo->query("
    SELECT sk.*, t.name AS tariff_name, t.duration_days,
           u.email AS used_by_email
    FROM subscription_keys sk
    JOIN tariffs t ON sk.tariff_id = t.id
    LEFT JOIN users u ON sk.used_by = u.id
    ORDER BY sk.id DESC
    LIMIT 100
")->fetchAll();

$mailLogs = [];
$logDir = dirname(__DIR__) . '/uploads/mail_log/';
if (is_dir($logDir)) {
    $logFiles = glob($logDir . 'mail_*.html');
    if ($logFiles) {
        rsort($logFiles);
        foreach (array_slice($logFiles, 0, 10) as $lf) {
            $raw = file_get_contents($lf);
            preg_match('/TO:\s*([^<\n]+)?\s*<([^>\n]+)>/u', $raw, $mTo);
            preg_match('/SUBJECT:\s*([^\n]+)/u', $raw, $mSub);
            preg_match('/SENT:\s*([^\n]+)/u', $raw, $mSent);
            $mailLogs[] = [
                'file'    => basename($lf),
                'to'      => trim(($mTo[1] ?? '') . ' <' . ($mTo[2] ?? '') . '>'),
                'subject' => $mSub[1] ?? 'Без темы',
                'sent'    => $mSent[1] ?? date('Y-m-d H:i:s', filemtime($lf))
            ];
        }
    }
}

$pageTitle = 'Выдача подписок & Ключи | AI Business Assistant';
require dirname(__DIR__) . '/includes/header.php';
?>

<div style="margin-bottom:24px;">
    <h2><i class="fas fa-key text-primary"></i> Выдача подписок и ключей</h2>
    <p class="text-muted">Генерация одноразовых ключей активации, прямая выдача подписки пользователям и отправка уведомлений на почту</p>
</div>

<?php if ($success): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $success ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div style="display:grid; grid-template-columns: 1fr 1fr; gap:24px; margin-bottom:28px;">

    <!-- BLOCK 1: Generate Key by Email -->
    <div class="card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:24px;">
        <h3 class="mb-1"><i class="fas fa-envelope-open-text text-primary"></i> Выдать ключ на email</h3>
        <p class="text-muted mb-3" style="font-size:13px;">Система сгенерирует уникальный одноразовый ключ и отправит его получателю на почту. Получатель активирует ключ самостоятельно.</p>

        <form method="POST">
            <?= Security::csrfField() ?>
            <input type="hidden" name="action" value="generate_key">

            <div class="form-group">
                <label class="form-label">Email получателя *</label>
                <input type="email" name="recipient_email" class="form-control" required placeholder="client@company.kz">
            </div>
            <div class="form-group">
                <label class="form-label">Имя получателя (для письма)</label>
                <input type="text" name="recipient_name" class="form-control" placeholder="Алибек Джаксыбеков">
            </div>
            <div class="form-group">
                <label class="form-label">Тарифный план *</label>
                <select name="tariff_id" class="form-control" required>
                    <option value="">— Выберите тариф —</option>
                    <?php foreach ($tariffs as $t): ?>
                        <option value="<?= $t['id'] ?>">
                            <?= htmlspecialchars($t['name']) ?> · <?= $t['duration_days'] > 0 ? "{$t['duration_days']} дней" : 'Индивидуальный' ?> · <?= number_format((float)$t['price'], 0, '.', ' ') ?> ₸
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" class="btn btn-primary btn-block">
                <i class="fas fa-paper-plane"></i> Сгенерировать ключ и отправить
            </button>
        </form>
    </div>

    <!-- BLOCK 2: Direct Grant to existing user -->
    <div class="card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:24px;">
        <h3 class="mb-1"><i class="fas fa-user-check text-success"></i> Напрямую выдать подписку</h3>
        <p class="text-muted mb-3" style="font-size:13px;">Мгновенная активация подписки для уже зарегистрированного пользователя. Пользователь получит уведомление по email.</p>

        <form method="POST">
            <?= Security::csrfField() ?>
            <input type="hidden" name="action" value="grant_direct">

            <div class="form-group">
                <label class="form-label">Пользователь *</label>
                <select name="user_id" class="form-control" required>
                    <option value="">— Выберите пользователя —</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= $u['id'] ?>">
                            <?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?> (<?= htmlspecialchars($u['email']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Тарифный план *</label>
                <select name="tariff_id_grant" class="form-control" required id="grantTariff" onchange="toggleCustomDays(this)">
                    <option value="">— Выберите тариф —</option>
                    <?php foreach ($tariffs as $t): ?>
                        <option value="<?= $t['id'] ?>" data-custom="<?= $t['code'] === 'CUSTOM' ? '1' : '0' ?>">
                            <?= htmlspecialchars($t['name']) ?> · <?= $t['duration_days'] > 0 ? "{$t['duration_days']} дней" : 'Свой период' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" id="customDaysGroup" style="display:none;">
                <label class="form-label">Количество дней (для тарифа CUSTOM)</label>
                <input type="number" name="custom_days_grant" class="form-control" min="1" max="3650" placeholder="например 90">
            </div>

            <button type="submit" class="btn btn-success btn-block">
                <i class="fas fa-bolt"></i> Активировать подписку сейчас
            </button>
        </form>
    </div>
</div>

<!-- Keys Table -->
<div class="card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:24px; margin-bottom:28px;">
    <h3 class="mb-3"><i class="fas fa-list-alt text-warning"></i> Выданные ключи (последние 100)</h3>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Ключ активации</th>
                    <th>Тариф</th>
                    <th>Email получателя</th>
                    <th>Статус</th>
                    <th>Активировал</th>
                    <th>Дата создания</th>
                    <th>Действие</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($keys)): ?>
                    <tr><td colspan="7" class="text-center text-muted" style="padding:30px;">Ключи ещё не выдавались</td></tr>
                <?php else: ?>
                    <?php foreach ($keys as $k): ?>
                        <tr>
                            <td>
                                <code style="background:var(--bg-secondary); padding:4px 10px; border-radius:6px; font-size:13px; letter-spacing:2px; color:var(--primary);">
                                    <?= htmlspecialchars($k['key_code']) ?>
                                </code>
                            </td>
                            <td><?= htmlspecialchars($k['tariff_name']) ?> <small class="text-muted">(<?= $k['duration_days'] ?> дн.)</small></td>
                            <td><?= htmlspecialchars($k['recipient_email'] ?? '—') ?></td>
                            <td>
                                <?php if ($k['status'] === 'active'): ?>
                                    <span class="badge badge-success">Активен</span>
                                <?php elseif ($k['status'] === 'used'): ?>
                                    <span class="badge badge-primary">Использован</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">Аннулирован</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($k['used_by_email']): ?>
                                    <small><?= htmlspecialchars($k['used_by_email']) ?></small><br>
                                    <small class="text-muted"><?= $k['used_at'] ? date('d.m.Y H:i', strtotime($k['used_at'])) : '' ?></small>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td><small class="text-muted"><?= date('d.m.Y H:i', strtotime($k['created_at'])) ?></small></td>
                            <td>
                                <?php if ($k['status'] === 'active'): ?>
                                    <form method="POST" onsubmit="return confirm('Аннулировать ключ?')" style="margin:0;">
                                        <?= Security::csrfField() ?>
                                        <input type="hidden" name="action" value="revoke_key">
                                        <input type="hidden" name="key_id" value="<?= $k['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">
                                            <i class="fas fa-ban"></i> Аннулировать
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-muted" style="font-size:12px;">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Mail Log Preview -->
<?php if (!empty($mailLogs)): ?>
<div class="card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:24px;">
    <h3 class="mb-1"><i class="fas fa-inbox text-secondary"></i> Журнал отправленных писем</h3>
    <p class="text-muted mb-3" style="font-size:13px;">
        Все отправленные письма сохраняются локально в <code>uploads/mail_log/</code>.
        Если на вашем сервере не настроен SMTP — письма не дойдут, но ключи будут видны здесь.
    </p>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr><th>Кому</th><th>Тема</th><th>Отправлено</th><th>Просмотр</th></tr>
            </thead>
            <tbody>
                <?php foreach ($mailLogs as $ml): ?>
                    <tr>
                        <td><?= htmlspecialchars($ml['to']) ?></td>
                        <td><?= htmlspecialchars($ml['subject']) ?></td>
                        <td><small class="text-muted"><?= htmlspecialchars($ml['sent']) ?></small></td>
                        <td>
                            <a href="/admin/mail_preview.php?file=<?= urlencode($ml['file']) ?>" target="_blank" class="btn btn-secondary btn-sm">
                                <i class="fas fa-eye"></i> Открыть
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<script>
function toggleCustomDays(sel) {
    const opt = sel.options[sel.selectedIndex];
    document.getElementById('customDaysGroup').style.display = (opt.dataset.custom === '1') ? 'block' : 'none';
}
</script>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
