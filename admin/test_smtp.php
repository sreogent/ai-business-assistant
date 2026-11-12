<?php
declare(strict_types=1);

/**
 * AI Business Assistant - Admin: SMTP Connection Test & Mail Diagnostics
 * Тестирование отправки через сокеты и диагностика доставки писем
 */

require_once dirname(__DIR__) . '/includes/Auth.php';
require_once dirname(__DIR__) . '/includes/Mailer.php';

$admin = Auth::requireAdmin();
$pdo   = Database::getConnection();

$settings = $pdo->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);

$smtpHost   = trim($settings['smtp_host']       ?? '');
$smtpPort   = (int)($settings['smtp_port'] ?? 587);
$smtpUser   = trim($settings['smtp_user']       ?? '');
$smtpPass   = trim($settings['smtp_pass']       ?? '');
$smtpEnc    = trim($settings['smtp_encryption'] ?? 'tls');
$fromEmail  = trim($settings['mail_from_email'] ?? '');
$fromName   = trim($settings['mail_from_name']  ?? 'AI Business Assistant');
$adminEmail = $admin['email'];
$adminName  = trim($admin['first_name'] . ' ' . $admin['last_name']);

$targetEmail = trim($_POST['target_email'] ?? $adminEmail);
$targetName  = trim($_POST['target_name'] ?? ($adminName ?: 'Администратор'));

$result = null;
$ok = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!Security::verifyCsrf($token)) {
        $result = "❌ Истёк токен безопасности (CSRF). Обновите страницу и попробуйте снова.";
        $ok = false;
    } elseif (empty($smtpHost) || empty($fromEmail)) {
        $result = "⚠️ SMTP не настроен. Заполните Хост и Email отправителя в <a href='/admin/ai_settings.php' style='color:#3b82f6;'>Настройках AI</a>.";
        $ok = false;
    } elseif (!filter_var($targetEmail, FILTER_VALIDATE_EMAIL)) {
        $result = "❌ Указан некорректный email адрес получателя.";
        $ok = false;
    } else {
        $sent = Mailer::sendTestMail($targetEmail, $targetName);
        if ($sent) {
            $ok = true;
            $result = "✅ <strong>Тестовое письмо успешно отправлено через SMTP!</strong><br>"
                    . "Сервер <code>{$smtpHost}:{$smtpPort}</code> принял сообщение для доставки на <strong>" . htmlspecialchars($targetEmail) . "</strong> (код ответа 250 OK).";
        } else {
            $ok = false;
            $err = Mailer::$lastError ? htmlspecialchars(Mailer::$lastError) : 'Не удалось завершить SMTP сессию';
            $result = "❌ <strong>Ошибка при отправке через SMTP:</strong><br><code>{$err}</code>";
        }
    }
}

// Загрузка последних писем из локального журнала uploads/mail_log
$mailLogs = [];
$logDir = dirname(__DIR__) . '/uploads/mail_log/';
if (is_dir($logDir)) {
    $files = glob($logDir . 'mail_*.html');
    if ($files) {
        rsort($files); // самые свежие первыми
        foreach (array_slice($files, 0, 10) as $f) {
            $raw = file_get_contents($f);
            preg_match('/TO:\s*([^<\n]+)?\s*<([^>\n]+)>/u', $raw, $mTo);
            preg_match('/SUBJECT:\s*([^\n]+)/u', $raw, $mSub);
            preg_match('/STATUS:\s*([^\n]+)/u', $raw, $mStat);
            preg_match('/SENT:\s*([^\n]+)/u', $raw, $mSent);
            $mailLogs[] = [
                'file'    => basename($f),
                'to'      => trim(($mTo[1] ?? '') . ' <' . ($mTo[2] ?? '') . '>'),
                'subject' => $mSub[1] ?? 'Без темы',
                'status'  => $mStat[1] ?? 'SAVED',
                'sent'    => $mSent[1] ?? date('Y-m-d H:i:s', filemtime($f))
            ];
        }
    }
}

$pageTitle = 'SMTP Диагностика и Тест | AI Business Assistant';
require dirname(__DIR__) . '/includes/header.php';
?>

<div style="max-width:860px; margin:0 auto;">
    <div style="margin-bottom:24px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
        <div>
            <h2><i class="fas fa-paper-plane text-primary"></i> SMTP Диагностика и Тестирование</h2>
            <p class="text-muted">Проверка подключения к почтовому серверу и журнал отправленных системой писем</p>
        </div>
        <div>
            <a href="/admin/ai_settings.php" class="btn btn-secondary">
                <i class="fas fa-cog"></i> Настройки SMTP
            </a>
        </div>
    </div>

    <?php if ($result !== null): ?>
        <div class="alert <?= $ok ? 'alert-success' : 'alert-danger' ?>" style="font-size:14px; margin-bottom:24px;">
            <?= $result ?>
        </div>
    <?php endif; ?>

    <!-- Важная справка: почему письма могут не появляться во «Входящих» -->
    <div class="card" style="background:rgba(99,102,241,0.08); border:1px solid rgba(99,102,241,0.25); border-radius:var(--radius-lg); padding:20px 24px; margin-bottom:24px;">
        <h4 style="color:#818cf8; margin-bottom:12px; display:flex; align-items:center; gap:8px;">
            <i class="fas fa-info-circle"></i> Почему письмо может не появляться в папке «Входящие»:
        </h4>
        <ul style="color:var(--text-muted); font-size:13px; line-height:1.8; margin:0; padding-left:20px;">
            <li>
                <strong>Отправка с почты на эту же почту (Gmail):</strong> Когда вы отправляете письмо через SMTP от <code><?= htmlspecialchars($fromEmail ?: 'вашей почты') ?></code> на этот же адрес, алгоритм Gmail автоматически архивирует его и не всегда показывает в «Основных входящих». Ищите его в разделах <strong>«Вся почта»</strong> или <strong>«Отправленные»</strong>.
            </li>
            <li>
                <strong>Папка «Спам» (Spam):</strong> Письма, отправленные с локального веб-сервера (localhost), часто фильтруются в папку <strong>«Спам»</strong>. Откройте папку «Спам» в вашей почте и нажмите <em>«Не спам»</em>.
            </li>
            <li>
                <strong>Локальное зеркало (100% гарантия сохранности):</strong> Любое сгенерированное системой письмо (2FA-код, удаление/регистрация компании, ключи) мгновенно дублируется в журнал внизу страницы и открывается в 1 клик.
            </li>
        </ul>
    </div>

    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:24px; margin-bottom:24px;">
        <!-- Форма отправки теста -->
        <div class="card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:24px;">
            <h3 class="mb-3" style="font-size:17px;"><i class="fas fa-vial text-primary"></i> Отправить тестовое письмо</h3>
            <form method="POST">
                <?= Security::csrfField() ?>

                <div class="form-group">
                    <label class="form-label">Получатель (Email)</label>
                    <input type="email" name="target_email" class="form-control" value="<?= htmlspecialchars($targetEmail) ?>" required placeholder="user@gmail.com">
                    <small class="text-muted">Можно указать любой другой email для проверки внешней доставки</small>
                </div>

                <div class="form-group">
                    <label class="form-label">Имя получателя</label>
                    <input type="text" name="target_name" class="form-control" value="<?= htmlspecialchars($targetName) ?>" placeholder="Матвей">
                </div>

                <button type="submit" class="btn btn-primary btn-block" style="padding:12px; font-weight:600;">
                    <i class="fas fa-paper-plane"></i> Отправить через SMTP
                </button>
            </form>
        </div>

        <!-- Текущие реквизиты SMTP -->
        <div class="card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:24px;">
            <h3 class="mb-3" style="font-size:17px;"><i class="fas fa-server text-secondary"></i> Конфигурация SMTP</h3>
            <table class="table" style="font-size:13px; margin:0;">
                <tr><td class="text-muted">Хост:</td><td><strong><?= htmlspecialchars($smtpHost ?: 'Не указан') ?></strong></td></tr>
                <tr><td class="text-muted">Порт:</td><td><?= $smtpPort ?></td></tr>
                <tr><td class="text-muted">Шифрование:</td><td><span class="badge badge-info"><?= strtoupper($smtpEnc) ?></span></td></tr>
                <tr><td class="text-muted">Пользователь:</td><td><?= htmlspecialchars($smtpUser ?: '—') ?></td></tr>
                <tr><td class="text-muted">Пароль:</td><td><?= !empty($smtpPass) ? '•••••••• (задан)' : '<span class="text-danger">Не задан</span>' ?></td></tr>
                <tr><td class="text-muted">Email отправителя:</td><td><?= htmlspecialchars($fromEmail ?: '—') ?></td></tr>
                <tr><td class="text-muted">Имя отправителя:</td><td><?= htmlspecialchars($fromName) ?></td></tr>
            </table>
        </div>
    </div>

    <!-- Журнал отправленных писем -->
    <div class="card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:24px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
            <div>
                <h3 class="mb-1" style="font-size:17px;"><i class="fas fa-history text-primary"></i> Журнал отправленных писем (Локальный архив)</h3>
                <p class="text-muted mb-0" style="font-size:13px;">Каждое сформированное письмо доступно для быстрого просмотра содержимого</p>
            </div>
            <a href="/admin/test_smtp.php" class="btn btn-secondary btn-sm"><i class="fas fa-sync"></i> Обновить</a>
        </div>

        <?php if (empty($mailLogs)): ?>
            <p class="text-muted" style="margin:20px 0; text-align:center;">Журнал пуст. Письма пока не отправлялись.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table" style="font-size:13px;">
                    <thead>
                        <tr>
                            <th>Дата / Время</th>
                            <th>Кому</th>
                            <th>Тема сообщения</th>
                            <th>Статус отправки</th>
                            <th>Действие</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($mailLogs as $ml): ?>
                            <tr>
                                <td><small class="text-muted"><?= htmlspecialchars($ml['sent']) ?></small></td>
                                <td><strong><?= htmlspecialchars($ml['to']) ?></strong></td>
                                <td><?= htmlspecialchars($ml['subject']) ?></td>
                                <td>
                                    <?php if (str_starts_with($ml['status'], 'SENT_VIA_SMTP') || $ml['status'] === 'DELIVERED_VIA_SMTP'): ?>
                                        <span class="badge badge-success"><i class="fas fa-check"></i> SMTP OK</span>
                                    <?php elseif (str_starts_with($ml['status'], 'ERROR') || str_starts_with($ml['status'], 'SMTP_REJECTED')): ?>
                                        <span class="badge badge-danger" title="<?= htmlspecialchars($ml['status']) ?>"><i class="fas fa-times"></i> Ошибка</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary"><?= htmlspecialchars($ml['status']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="/admin/mail_preview.php?file=<?= urlencode($ml['file']) ?>" target="_blank" class="btn btn-secondary btn-sm" title="Открыть письмо">
                                        <i class="fas fa-eye"></i> Открыть
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
