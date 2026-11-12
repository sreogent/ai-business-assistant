<?php
declare(strict_types=1);

/**
 * AI Business Assistant - Контакты руководства платформы
 * Контакты Генерального директора сайта (matvejibadlaev@gmail.com)
 * и форма прямой отправки письма с сайта
 */

require_once __DIR__ . '/includes/Auth.php';
require_once __DIR__ . '/includes/Mailer.php';

$user = Auth::user();
$pdo = Database::getConnection();

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!Security::verifyCsrf($token)) {
        $error = "Истёк токен безопасности. Повторите попытку.";
    } else {
        $senderName = trim($_POST['name'] ?? ($user ? ($user['first_name'] . ' ' . $user['last_name']) : ''));
        $senderEmail = trim($_POST['email'] ?? ($user['email'] ?? ''));
        $subject = trim($_POST['subject'] ?? 'Обращение к Генеральному директору');
        $messageText = trim($_POST['message'] ?? '');

        if (empty($senderName) || empty($senderEmail) || empty($messageText)) {
            $error = "Пожалуйста, укажите ваше имя, контактный email и текст обращения.";
        } elseif (!filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
            $error = "Укажите корректный адрес электронной почты.";
        } else {
            // Формируем красивое HTML письмо Генеральному директору
            $directorEmail = 'matvejibadlaev@gmail.com';
            $directorName = 'Матвей Ибадлаев';

            $fullSubject = "✉️ [Обращение с сайта] " . $subject;
            $userRoleText = $user ? ($user['role_name'] ?? 'клиент') : 'Гость платформы';
            $dateFormatted = date('d.m.Y H:i');

            $htmlBody = <<<HTML
<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#0a0e17;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#0a0e17;padding:40px 20px;"><tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#1f2937;border-radius:16px;overflow:hidden;border:1px solid #374151;">
  <tr><td style="background:linear-gradient(135deg,#6366f1,#8b5cf6);padding:32px;text-align:center;">
    <h1 style="color:#fff;margin:0;font-size:22px;font-weight:700;">AI Business Assistant</h1>
    <p style="color:rgba(255,255,255,0.9);margin:8px 0 0;font-size:14px;">Прямое обращение к Генеральному директору</p>
  </td></tr>
  <tr><td style="padding:32px;">
    <p style="color:#9ca3af;font-size:15px;margin:0 0 16px;">Здравствуйте, <strong style="color:#f9fafb;">{$directorName}</strong>!</p>
    <p style="color:#9ca3af;font-size:14px;margin:0 0 20px;line-height:1.6;">
      Через форму прямой связи на сайте к вам обратился <strong>{$senderName}</strong> (<a href="mailto:{$senderEmail}" style="color:#818cf8;">{$senderEmail}</a>).
    </p>
    <div style="background:#111827;border:1px solid #374151;border-radius:10px;padding:18px;margin:0 0 24px;">
      <div style="font-size:13px;color:#9ca3af;margin-bottom:8px;">Тема: <strong style="color:#f9fafb;">{$subject}</strong></div>
      <div style="font-size:13px;color:#9ca3af;margin-bottom:12px;">Статус отправителя: <span style="color:#818cf8;font-weight:600;">{$userRoleText}</span> | Дата: {$dateFormatted}</div>
      <div style="border-top:1px solid #374151;padding-top:12px;font-size:14px;color:#f3f4f6;line-height:1.7;white-space:pre-wrap;">{$messageText}</div>
    </div>
    <div style="text-align:center;">
      <a href="mailto:{$senderEmail}?subject=Re: {$subject}" style="display:inline-block;background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;text-decoration:none;padding:12px 32px;border-radius:10px;font-weight:700;font-size:14px;">Ответить на письмо →</a>
    </div>
  </td></tr>
</table></td></tr></table></body></html>
HTML;

            $sent = Mailer::sendEmail($directorEmail, $directorName, $fullSubject, $htmlBody, $messageText);

            // Дублируем в чат руководства сайта (site_hq), если таблица есть
            try {
                $hqChan = $pdo->query("SELECT id FROM team_chat_channels WHERE channel_key = 'site_hq' LIMIT 1")->fetchColumn();
                if ($hqChan) {
                    $chatMsg = "📬 [Письмо Генеральному директору]\nОт: {$senderName} ({$senderEmail})\nТема: {$subject}\nСообщение: " . mb_substr($messageText, 0, 150) . '...';
                    $sysSenderId = $user ? (int)$user['id'] : 1;
                    $pdo->prepare("INSERT INTO team_chat_messages (channel_id, sender_id, message) VALUES (?, ?, ?)")
                        ->execute([$hqChan, $sysSenderId, $chatMsg]);
                }
            } catch (\Throwable $t) {}

            Security::log($pdo, 'contact_director_message', "Отправлено письмо ген. директору от {$senderEmail}", $user ? (int)$user['id'] : null);
            $success = "🎉 Ваше письмо успешно отправлено лично Генеральному директору на почту <strong>matvejibadlaev@gmail.com</strong>! Ответ поступит на указанный вами адрес.";
        }
    }
}

$csrfToken = Security::csrfToken();
$pageTitle = "Контакты руководства | AI Business Assistant";
require __DIR__ . '/includes/header.php';
?>

<div style="max-width:960px; margin:0 auto; padding:10px 0 30px;">
    
    <div style="margin-bottom:24px;">
        <h1 style="margin:0 0 8px; font-size:26px; font-weight:800;">
            <i class="fas fa-address-book" style="color:var(--primary); margin-right:8px;"></i> Контакты руководства платформы
        </h1>
        <p style="margin:0; color:var(--text-muted); font-size:14px;">
            Прямая связь с Генеральным директором и создателем проекта для бизнес-предложений, партнёрства и решения срочных вопросов
        </p>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success" style="font-size:14px; margin-bottom:24px;">
            <i class="fas fa-check-circle"></i> <?= $success ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger" style="font-size:14px; margin-bottom:24px;">
            <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <div style="display:grid; grid-template-columns: 360px 1fr; gap:24px; align-items:start;">
        
        <!-- КАРТОЧКА ГЕНЕРАЛЬНОГО ДИРЕКТОРА -->
        <div class="card" style="padding:24px; border:1px solid var(--border-color); background:var(--bg-card);">
            <div style="text-align:center; margin-bottom:20px;">
                <div style="width:84px; height:84px; border-radius:50%; background:linear-gradient(135deg, #6366f1, #8b5cf6); color:#fff; display:flex; align-items:center; justify-content:center; font-size:32px; font-weight:800; margin:0 auto 14px; box-shadow:0 8px 24px rgba(99,102,241,0.35);">
                    МИ
                </div>
                <h3 style="margin:0 0 4px; font-size:18px; font-weight:700; color:var(--text-primary);">
                    Матвей Ибадлаев
                </h3>
                <span class="badge" style="background:linear-gradient(135deg,#d97706,#b45309); color:#fff; font-size:11px; padding:3px 10px; font-weight:700;">
                    <i class="fas fa-crown"></i> Генеральный Директор платформы
                </span>
            </div>

            <div style="display:flex; flex-direction:column; gap:14px; font-size:13px; border-top:1px solid var(--border-color); padding-top:18px;">
                <div>
                    <div style="font-size:11px; text-transform:uppercase; color:var(--text-muted); font-weight:600; margin-bottom:2px;">
                        Официальный Email:
                    </div>
                    <a href="mailto:matvejibadlaev@gmail.com" style="color:var(--primary); font-size:14px; font-weight:600; text-decoration:none; display:flex; align-items:center; gap:6px;">
                        <i class="fas fa-envelope"></i> matvejibadlaev@gmail.com
                    </a>
                </div>

                <div>
                    <div style="font-size:11px; text-transform:uppercase; color:var(--text-muted); font-weight:600; margin-bottom:2px;">
                        Время ответа:
                    </div>
                    <div style="color:var(--text-primary);">
                        <i class="fas fa-clock" style="color:#10b981;"></i> Обычно в течение нескольких часов
                    </div>
                </div>

                <div>
                    <div style="font-size:11px; text-transform:uppercase; color:var(--text-muted); font-weight:600; margin-bottom:2px;">
                        Темы обращений:
                    </div>
                    <ul style="margin:4px 0 0 16px; padding:0; color:var(--text-secondary); font-size:12px; line-height:1.6;">
                        <li>Вопросы стратегического партнерства</li>
                        <li>Индивидуальные условия для крупных сетей</li>
                        <li>Вопросы качества обслуживания и поддержка</li>
                        <li>Предложения по развитию функционала</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- ФОРМА ОТПРАВКИ ПИСЬМА -->
        <div class="card" style="padding:24px; border:1px solid var(--border-color); background:var(--bg-card);">
            <h3 style="margin:0 0 8px; font-size:18px; font-weight:700;">
                <i class="fas fa-paper-plane" style="color:var(--primary); margin-right:8px;"></i> Написать письмо Генеральному директору
            </h3>
            <p style="margin:0 0 20px; color:var(--text-muted); font-size:13px;">
                Сообщение будет отправлено напрямую на персональный адрес <strong style="color:var(--text-primary);">matvejibadlaev@gmail.com</strong>
            </p>

            <form method="POST" action="/contacts.php">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:14px; margin-bottom:14px;">
                    <div class="form-group" style="margin:0;">
                        <label style="display:block; font-size:12px; font-weight:600; margin-bottom:6px;">Ваше имя *</label>
                        <input type="text" name="name" class="form-control" 
                               value="<?= htmlspecialchars($user ? ($user['first_name'] . ' ' . $user['last_name']) : '') ?>" 
                               placeholder="Как к вам обращаться?" required>
                    </div>

                    <div class="form-group" style="margin:0;">
                        <label style="display:block; font-size:12px; font-weight:600; margin-bottom:6px;">Ваш Email для ответа *</label>
                        <input type="email" name="email" class="form-control" 
                               value="<?= htmlspecialchars($user['email'] ?? '') ?>" 
                               placeholder="example@domain.com" required>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:14px;">
                    <label style="display:block; font-size:12px; font-weight:600; margin-bottom:6px;">Тема обращения *</label>
                    <input type="text" name="subject" class="form-control" 
                           placeholder="Например: Предложение о сотрудничестве или вопрос по подписке..." required>
                </div>

                <div class="form-group" style="margin-bottom:20px;">
                    <label style="display:block; font-size:12px; font-weight:600; margin-bottom:6px;">Текст письма *</label>
                    <textarea name="message" class="form-control" rows="6" 
                              placeholder="Подробно опишите суть вашего предложения или вопроса..." required></textarea>
                </div>

                <button type="submit" class="btn btn-primary" style="padding:12px 24px; font-size:14px; font-weight:700;">
                    <i class="fas fa-envelope-open-text"></i> Отправить письмо Генеральному директору
                </button>
            </form>
        </div>

    </div>

</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
