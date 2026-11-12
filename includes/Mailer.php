<?php
declare(strict_types=1);

/**
 * AI Business Assistant - Mailer Service
 *
 * Отправка почты через SMTP напрямую через PHP-сокеты (без внешних зависимостей).
 * Настройки SMTP берутся из таблицы settings (admin → Настройки AI / SMTP).
 * Поддерживает: SMTP без шифрования (25), STARTTLS (587), SSL (465).
 * Все отправленные письма дублируются в uploads/mail_log/ для локального просмотра.
 */

require_once __DIR__ . '/Database.php';

class Mailer {

    public static ?string $lastError = null;

    // ─────────────────────────────────────────────────────────────────────────
    // Настройки из БД
    // ─────────────────────────────────────────────────────────────────────────

    private static ?array $cfg = null;

    private static function cfg(): array {
        if (self::$cfg !== null) return self::$cfg;
        try {
            $pdo  = Database::getConnection();
            $rows = $pdo->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (\Exception $e) {
            $rows = [];
        }
        self::$cfg = $rows;
        return self::$cfg;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Публичные методы отправки
    // ─────────────────────────────────────────────────────────────────────────

    /** Произвольная отправка HTML/текстового письма */
    public static function sendEmail(
        string $toEmail, string $toName,
        string $subject, string $html, string $text = ''
    ): bool {
        if (empty($text)) {
            $text = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $html));
        }
        return self::dispatch($toEmail, $toName, $subject, $html, $text);
    }

    /** Ключ подписки — пользователь активирует сам */
    public static function sendSubscriptionKey(
        string $toEmail, string $toName,
        string $keyCode, string $tariffName,
        int $durationDays, string $activateUrl
    ): bool {
        $subject = "🎁 Ваш ключ подписки — {$tariffName}";
        $html    = self::tplKey($toName, $keyCode, $tariffName, $durationDays, $activateUrl);
        $text    = "Здравствуйте, {$toName}!\n\nКлюч подписки «{$tariffName}» ({$durationDays} дней):\n\n{$keyCode}\n\nАктивация: {$activateUrl}";
        return self::dispatch($toEmail, $toName, $subject, $html, $text);
    }

    /** Администратор напрямую активировал подписку */
    public static function sendSubscriptionGranted(
        string $toEmail, string $toName,
        string $tariffName, int $durationDays, string $endDate
    ): bool {
        $cfg     = self::cfg();
        $baseUrl = rtrim($cfg['app_base_url'] ?? 'http://127.0.0.1:8000', '/');
        $subject = "✅ Подписка активирована — {$tariffName}";
        $html    = self::tplGranted($toName, $tariffName, $durationDays, $endDate, $baseUrl);
        $text    = "Здравствуйте, {$toName}!\n\nПодписка «{$tariffName}» ({$durationDays} дн.) активирована до {$endDate}.\nЛичный кабинет: {$baseUrl}/user/dashboard.php";
        return self::dispatch($toEmail, $toName, $subject, $html, $text);
    }

    /** Администратор отозвал / деактивировал подписку */
    public static function sendSubscriptionRevoked(
        string $toEmail, string $toName,
        string $tariffName, string $reason, string $baseUrl
    ): bool {
        $subject = "⚠️ Ваша подписка деактивирована — {$tariffName}";
        $html    = self::tplRevoked($toName, $tariffName, $reason, $baseUrl);
        $reasonText = !empty($reason) ? "\nПричина: {$reason}" : "";
        $text    = "Здравствуйте, {$toName}!\n\nВаша подписка на тариф «{$tariffName}» была деактивирована администратором.{$reasonText}\n\nДля возобновления доступа перейдите в личный кабинет: {$baseUrl}/user/subscription.php";
        return self::dispatch($toEmail, $toName, $subject, $html, $text);
    }

    /** Уведомление Генеральному Директору о создании организации */
    public static function sendOrganizationCreated(
        string $toEmail, string $directorName,
        string $orgName, string $legalName, string $inn, string $baseUrl
    ): bool {
        $subject = "🏢 Регистрация организации «{$orgName}» | AI Business Assistant";
        $html    = self::tplOrganizationCreated($directorName, $orgName, $legalName, $inn, $baseUrl);
        $text    = "Здравствуйте, {$directorName}!\n\nОрганизация «{$orgName}» успешно создана и подключена к платформе AI Business Assistant.\n\nВход в личный кабинет: {$baseUrl}/login.php";
        return self::dispatch($toEmail, $directorName, $subject, $html, $text);
    }

    /** Уведомление Генеральному Директору об удалении организации и очистке данных */
    public static function sendOrganizationDeleted(
        string $toEmail, string $directorName,
        string $orgName, string $baseUrl, array $deletedStats = []
    ): bool {
        $subject = "🗑️ Организация «{$orgName}» удалена из системы | AI Business Assistant";
        $html    = self::tplOrganizationDeleted($directorName, $orgName, $baseUrl, $deletedStats);
        $text    = "Здравствуйте, {$directorName}!\n\nОрганизация «{$orgName}» и все связанные настройки (каталог, касса, должности) были полностью удалены администратором платформы AI Business Assistant.\n\nЛичный кабинет: {$baseUrl}/login.php";
        return self::dispatch($toEmail, $directorName, $subject, $html, $text);
    }

    /** Заявка на регистрацию организации создана (пользователю на почту с уникальным номером) */
    public static function sendOrganizationRequestCreated(
        string $toEmail, string $userName,
        string $reqNum, string $orgName, string $bTypeTitle, string $baseUrl
    ): bool {
        $subject = "📝 Заявка на создание организации #{$reqNum} принята | AI Business Assistant";
        $html    = self::tplOrgReqCreated($userName, $reqNum, $orgName, $bTypeTitle, $baseUrl);
        $text    = "Здравствуйте, {$userName}!\n\nВаша заявка на регистрацию организации «{$orgName}» успешно зарегистрирована.\nУникальный номер заявки: {$reqNum}\nСфера бизнеса: {$bTypeTitle}\nСтатус: На рассмотрении администратора.\n\nОтслеживать статус заявки: {$baseUrl}/user/request_organization.php";
        return self::dispatch($toEmail, $userName, $subject, $html, $text);
    }

    /** Заявка на регистрацию организации одобрена */
    public static function sendOrganizationRequestApproved(
        string $toEmail, string $userName,
        string $reqNum, string $orgName, string $baseUrl
    ): bool {
        $subject = "🎉 Ваша заявка #{$reqNum} одобрена! Организация «{$orgName}» создана";
        $html    = self::tplOrgReqApproved($userName, $reqNum, $orgName, $baseUrl);
        $text    = "Здравствуйте, {$userName}!\n\nПоздравляем! Ваша заявка #{$reqNum} на регистрацию организации «{$orgName}» успешно одобрена администратором.\nРаздел «Бизнес & POS», касса и номенклатура теперь доступны в вашем личном кабинете: {$baseUrl}/user/organizations.php";
        return self::dispatch($toEmail, $userName, $subject, $html, $text);
    }

    /** Заявка на регистрацию организации отклонена */
    public static function sendOrganizationRequestRejected(
        string $toEmail, string $userName,
        string $reqNum, string $orgName, string $reason, string $baseUrl
    ): bool {
        $subject = "⚠️ Заявка #{$reqNum} на регистрацию «{$orgName}» отклонена";
        $html    = self::tplOrgReqRejected($userName, $reqNum, $orgName, $reason, $baseUrl);
        $text    = "Здравствуйте, {$userName}!\n\nВаша заявка #{$reqNum} на регистрацию «{$orgName}» была отклонена администратором платформы.\nПричина: {$reason}\n\nВы можете подать новую заявку: {$baseUrl}/user/request_organization.php";
        return self::dispatch($toEmail, $userName, $subject, $html, $text);
    }

    /** Создано обращение в техподдержку */
    public static function sendSupportTicketCreated(
        string $toEmail, string $userName,
        string $ticketNum, string $ticketSubject, string $category, string $baseUrl
    ): bool {
        $subject = "🎫 Обращение в техподдержку #{$ticketNum}: {$ticketSubject}";
        $html    = self::tplSupportTicketCreated($userName, $ticketNum, $ticketSubject, $category, $baseUrl);
        $text    = "Здравствуйте, {$userName}!\n\nВаше обращение в техподдержку успешно зарегистрировано.\nНомер тикета: #{$ticketNum}\nТема: {$ticketSubject}\nКатегория: {$category}\nСтатус: В ожидании ответа специалистов.\n\nЧат с поддержкой: {$baseUrl}/user/support_chat.php?ticket={$ticketNum}";
        return self::dispatch($toEmail, $userName, $subject, $html, $text);
    }

    /** Уведомление об ответе техподдержки или смене статуса тикета */
    public static function sendSupportReplyNotification(
        string $toEmail, string $userName,
        string $ticketNum, string $ticketSubject, string $replyText, string $statusTitle, string $baseUrl
    ): bool {
        $subject = "💬 Ответ поддержки по тикету #{$ticketNum} [{$statusTitle}]";
        $html    = self::tplSupportReplyNotification($userName, $ticketNum, $ticketSubject, $replyText, $statusTitle, $baseUrl);
        $text    = "Здравствуйте, {$userName}!\n\nПоступил новый ответ от службы поддержки по тикету #{$ticketNum} («{$ticketSubject}»):\nСтатус: {$statusTitle}\n\nОтвет специалиста:\n{$replyText}\n\nОткрыть диалог: {$baseUrl}/user/support_chat.php?ticket={$ticketNum}";
        return self::dispatch($toEmail, $userName, $subject, $html, $text);
    }

    /** Отправка 4-значного кода безопасности для авторизации (2FA) */
    public static function send2FACode(string $toEmail, string $userName, string $code, string $roleTitle): bool {
        $subject = "🔐 Код подтверждения входа: {$code} | AI Business Assistant";
        $html    = self::tpl2FACode($userName, $code, $roleTitle);
        $text    = "Здравствуйте, {$userName}!\n\nВаш 4-значный код безопасности для входа в систему ({$roleTitle}): {$code}\nСрок действия кода: 10 минут.\nЕсли это были не вы, немедленно смените пароль.";
        return self::dispatch($toEmail, $userName, $subject, $html, $text);
    }

    /** Отправка 4-значного кода для включения 2FA в профиле */
    public static function send2FAEnableCode(string $toEmail, string $userName, string $code): bool {
        $subject = "🛡️ Код активации 2FA защиты: {$code} | AI Business Assistant";
        $roleTitle = "Включение двухфакторной аутентификации в профиле";
        $html    = self::tpl2FACode($userName, $code, $roleTitle);
        $text    = "Здравствуйте, {$userName}!\n\nВаш код для включения двухфакторной аутентификации (2FA): {$code}\nВведите этот код в профиле для завершения настройки защиты.\nКод действует 10 минут.";
        return self::dispatch($toEmail, $userName, $subject, $html, $text);
    }

    /** Тестовая отправка почты для проверки подключения */
    public static function sendTestMail(string $toEmail, string $toName): bool {
        $subject = "🧪 Тестовое письмо SMTP | AI Business Assistant";
        $time = date('d.m.Y H:i:s');
        $html = <<<HTML
<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#0a0e17;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#0a0e17;padding:40px 20px;"><tr><td align="center">
<table width="560" cellpadding="0" cellspacing="0" style="background:#1f2937;border-radius:16px;overflow:hidden;border:1px solid #374151;">
  <tr><td style="background:linear-gradient(135deg,#10b981,#059669);padding:32px;text-align:center;">
    <h1 style="color:#fff;margin:0;font-size:22px;font-weight:700;">AI Business Assistant</h1>
    <p style="color:rgba(255,255,255,0.9);margin:8px 0 0;font-size:14px;">Тест SMTP-подключения</p>
  </td></tr>
  <tr><td style="padding:32px;">
    <p style="color:#9ca3af;font-size:15px;margin:0 0 18px;">Здравствуйте, <strong style="color:#f9fafb;">{$toName}</strong>!</p>
    <p style="color:#9ca3af;font-size:14px;margin:0 0 20px;line-height:1.7;">
      Поздравляем! Ваш почтовый шлюз SMTP настроен корректно и успешно отправляет электронные письма.
    </p>
    <div style="background:#111827;border:1px solid #374151;border-radius:10px;padding:16px;margin:0 0 24px;">
      <p style="color:#6b7280;font-size:11px;text-transform:uppercase;margin:0 0 8px;font-weight:700;">Параметры проверки:</p>
      <div style="font-size:13px;color:#e5e7eb;line-height:1.8;">
        <div>• Время отправки: <strong>{$time}</strong></div>
        <div>• Адрес получателя: <strong>{$toEmail}</strong></div>
        <div>• Протокол: <strong>SMTP TLS/SSL Socket</strong></div>
      </div>
    </div>
  </td></tr>
</table></td></tr></table></body></html>
HTML;
        $text = "Здравствуйте, {$toName}!\n\nТестовое письмо успешно отправлено через SMTP ({$time}). Почтовый сервер функционирует стабильно.";
        return self::dispatch($toEmail, $toName, $subject, $html, $text);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Ядро: SMTP через сокеты
    // ─────────────────────────────────────────────────────────────────────────

    private static function dispatch(
        string $toEmail, string $toName,
        string $subject, string $html, string $text
    ): bool {
        self::$lastError = null;
        $cfg       = self::cfg();
        $fromEmail = trim($cfg['mail_from_email'] ?? '');
        $fromName  = trim($cfg['mail_from_name']  ?? 'AI Business Assistant');
        $smtpHost  = trim($cfg['smtp_host']       ?? '');
        $smtpPort  = (int)($cfg['smtp_port'] ?? 587);
        $smtpUser  = trim($cfg['smtp_user']       ?? '');
        $smtpPass  = str_replace(' ', '', trim($cfg['smtp_pass'] ?? ''));
        $smtpEnc   = strtolower(trim($cfg['smtp_encryption'] ?? 'tls')); // tls | ssl | none
        $toEmail   = trim($toEmail);
        $toName    = trim($toName);

        if (empty($smtpHost) || empty($fromEmail)) {
            self::$lastError = "SMTP хост или email отправителя не заполнены в настройках";
            self::saveToLog($toEmail, $toName, $subject, $text, $html, 'NOT_CONFIGURED');
            return false;
        }

        try {
            $sent = self::smtpSend(
                $smtpHost, $smtpPort, $smtpUser, $smtpPass, $smtpEnc,
                $fromEmail, $fromName,
                $toEmail, $toName,
                $subject, $html, $text
            );
            self::saveToLog($toEmail, $toName, $subject, $text, $html, $sent ? 'SENT_VIA_SMTP' : 'SMTP_REJECTED');
            return $sent;
        } catch (\Throwable $e) {
            self::$lastError = $e->getMessage();
            self::saveToLog($toEmail, $toName, $subject, $text, $html, 'ERROR: ' . $e->getMessage());
            error_log("[Mailer] SMTP error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Отправка письма напрямую через SMTP сокеты
     * Поддерживает: plain (25), STARTTLS (587), SSL/TLS (465)
     */
    private static function smtpSend(
        string $host, int $port, string $user, string $pass, string $enc,
        string $fromEmail, string $fromName,
        string $toEmail, string $toName,
        string $subject, string $html, string $text
    ): bool {
        $timeout = 15;

        // Домен для EHLO и Message-ID
        $domain = substr(strrchr($fromEmail, "@"), 1);
        $ehloDomain = !empty($domain) ? $domain : 'gmail.com';

        // Создаем сокет-контекст с отключенной проверкой сертификата для надежности в XAMPP/Windows
        $context = stream_context_create([
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true
            ]
        ]);

        $remote = ($enc === 'ssl' ? "ssl://{$host}:{$port}" : "tcp://{$host}:{$port}");
        $conn = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);

        if (!$conn) {
            throw new \RuntimeException("Не удалось подключиться к SMTP {$host}:{$port} — {$errstr} ({$errno})");
        }

        stream_set_timeout($conn, $timeout);

        $read = fn() => fgets($conn, 1024);
        $send = function(string $cmd) use ($conn) {
            fwrite($conn, $cmd . "\r\n");
        };

        // Приветствие сервера
        $read();

        // EHLO
        $send("EHLO {$ehloDomain}");
        while ($line = $read()) {
            if (substr($line, 3, 1) === ' ') break;
        }

        // STARTTLS если нужно
        if ($enc === 'tls') {
            $send("STARTTLS");
            $tlsResp = $read();
            if (!str_starts_with((string)$tlsResp, '220')) {
                fclose($conn);
                throw new \RuntimeException("STARTTLS отклонён сервером: {$tlsResp}");
            }
            if (!stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($conn);
                throw new \RuntimeException("Не удалось установить шифрование TLS сокет-соединения");
            }
            $send("EHLO {$ehloDomain}");
            while ($line = $read()) {
                if (substr($line, 3, 1) === ' ') break;
            }
        }

        // Авторизация
        if (!empty($user) && !empty($pass)) {
            $send("AUTH LOGIN");
            $read();
            $send(base64_encode($user));
            $read();
            $send(base64_encode($pass));
            $authResp = $read();
            if (!str_starts_with((string)$authResp, '235')) {
                fclose($conn);
                throw new \RuntimeException("SMTP AUTH не прошёл: {$authResp}");
            }
        }

        // Envelope
        $send("MAIL FROM:<{$fromEmail}>");
        $mailFromResp = $read();
        if (!str_starts_with((string)$mailFromResp, '250')) {
            fclose($conn);
            throw new \RuntimeException("MAIL FROM отклонён: {$mailFromResp}");
        }

        $send("RCPT TO:<{$toEmail}>");
        $rcptResp = $read();
        if (!str_starts_with((string)$rcptResp, '250') && !str_starts_with((string)$rcptResp, '251')) {
            fclose($conn);
            throw new \RuntimeException("RCPT TO отклонён: {$rcptResp}");
        }

        $send("DATA");
        $dataReady = $read();
        if (!str_starts_with((string)$dataReady, '354')) {
            fclose($conn);
            throw new \RuntimeException("DATA команда не принята: {$dataReady}");
        }

        // Multipart письмо (text + html)
        $boundary = 'AIBA_' . bin2hex(random_bytes(8));
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $encodedFrom    = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
        $encodedTo      = '=?UTF-8?B?' . base64_encode($toName) . '?=';

        $headers  = "From: {$encodedFrom} <{$fromEmail}>\r\n";
        $headers .= "Reply-To: {$encodedFrom} <{$fromEmail}>\r\n";
        $headers .= "To: {$encodedTo} <{$toEmail}>\r\n";
        $headers .= "Subject: {$encodedSubject}\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "X-Mailer: AI Business Assistant\r\n";
        $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
        $headers .= "Date: " . date('r') . "\r\n";
        $headers .= "Message-ID: <" . time() . "." . bin2hex(random_bytes(6)) . "@" . $ehloDomain . ">\r\n";

        $body  = "--{$boundary}\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($text)) . "\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($html)) . "\r\n";
        $body .= "--{$boundary}--\r\n";

        fwrite($conn, $headers . "\r\n" . $body . "\r\n.\r\n");
        $dataResp = $read();

        $send("QUIT");
        fclose($conn);

        return str_starts_with((string)$dataResp, '250');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Локальный лог писем (для просмотра в панели и отладки)
    // ─────────────────────────────────────────────────────────────────────────

    private static function saveToLog(
        string $toEmail, string $toName,
        string $subject, string $text, string $html,
        string $status = 'SAVED'
    ): void {
        $dir = dirname(__DIR__) . '/uploads/mail_log/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        if (!file_exists($dir . '.htaccess')) {
            file_put_contents($dir . '.htaccess', "Deny from all\n");
        }
        $file = $dir . 'mail_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.html';
        $content = "<!--\nTO: {$toName} <{$toEmail}>\nSUBJECT: {$subject}\nSTATUS: {$status}\nSENT: " . date('Y-m-d H:i:s') . "\nTEXT:\n{$text}\n-->\n" . $html;
        file_put_contents($file, $content);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HTML Шаблоны
    // ─────────────────────────────────────────────────────────────────────────

    private static function tplKey(string $name, string $key, string $tariff, int $days, string $url): string {
        return <<<HTML
<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#0a0e17;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#0a0e17;padding:40px 20px;"><tr><td align="center">
<table width="560" cellpadding="0" cellspacing="0" style="background:#1f2937;border-radius:16px;overflow:hidden;border:1px solid #374151;">
  <tr><td style="background:linear-gradient(135deg,#6366f1,#8b5cf6);padding:32px;text-align:center;">
    <h1 style="color:#fff;margin:0;font-size:22px;font-weight:700;">AI Business Assistant</h1>
    <p style="color:rgba(255,255,255,0.8);margin:8px 0 0;font-size:14px;">Ключ активации подписки</p>
  </td></tr>
  <tr><td style="padding:32px;">
    <p style="color:#9ca3af;font-size:15px;margin:0 0 16px;">Здравствуйте, <strong style="color:#f9fafb;">{$name}</strong>!</p>
    <p style="color:#9ca3af;font-size:14px;margin:0 0 24px;line-height:1.6;">
      Вам предоставлен ключ для активации тарифа <strong style="color:#818cf8;">«{$tariff}»</strong> сроком на <strong>{$days} дней</strong>.
    </p>
    <div style="background:#111827;border:2px dashed #6366f1;border-radius:12px;padding:20px;text-align:center;margin:0 0 24px;">
      <p style="color:#6b7280;font-size:11px;text-transform:uppercase;letter-spacing:1px;margin:0 0 8px;">Ваш персональный ключ:</p>
      <p style="color:#f9fafb;font-size:24px;font-weight:700;letter-spacing:4px;margin:0;font-family:monospace;color:#818cf8;">{$key}</p>
    </div>
    <div style="text-align:center;margin:0 0 24px;">
      <a href="{$url}" style="display:inline-block;background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;text-decoration:none;padding:12px 32px;border-radius:10px;font-weight:600;font-size:14px;">Активировать подписку →</a>
    </div>
  </td></tr>
</table></td></tr></table></body></html>
HTML;
    }

    private static function tplGranted(string $name, string $tariff, int $days, string $endDate, string $baseUrl): string {
        return <<<HTML
<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#0a0e17;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#0a0e17;padding:40px 20px;"><tr><td align="center">
<table width="560" cellpadding="0" cellspacing="0" style="background:#1f2937;border-radius:16px;overflow:hidden;border:1px solid #374151;">
  <tr><td style="background:linear-gradient(135deg,#10b981,#059669);padding:32px;text-align:center;">
    <h1 style="color:#fff;margin:0;font-size:22px;font-weight:700;">AI Business Assistant</h1>
    <p style="color:rgba(255,255,255,0.85);margin:8px 0 0;font-size:14px;">Подписка успешно подключена</p>
  </td></tr>
  <tr><td style="padding:32px;">
    <p style="color:#9ca3af;font-size:15px;margin:0 0 16px;">Здравствуйте, <strong style="color:#f9fafb;">{$name}</strong>!</p>
    <p style="color:#9ca3af;font-size:14px;margin:0 0 20px;line-height:1.6;">
      Администратор платформы активировал вам подписку на тариф <strong style="color:#10b981;">«{$tariff}»</strong>.
    </p>
    <div style="background:#111827;border-radius:10px;padding:16px;margin:0 0 24px;border:1px solid #374151;">
      <table width="100%" cellpadding="4" style="font-size:13px;color:#9ca3af;">
        <tr><td>Тариф:</td><td style="color:#f9fafb;font-weight:600;" align="right">{$tariff}</td></tr>
        <tr><td>Срок действия:</td><td style="color:#f9fafb;font-weight:600;" align="right">{$days} дней</td></tr>
        <tr><td>Активна до:</td><td style="color:#10b981;font-weight:600;" align="right">{$endDate}</td></tr>
      </table>
    </div>
    <div style="text-align:center;margin:0 0 16px;">
      <a href="{$baseUrl}/user/dashboard.php" style="display:inline-block;background:linear-gradient(135deg,#10b981,#059669);color:#fff;text-decoration:none;padding:12px 32px;border-radius:10px;font-weight:600;font-size:14px;">Перейти в личный кабинет →</a>
    </div>
  </td></tr>
</table></td></tr></table></body></html>
HTML;
    }

    private static function tplRevoked(string $name, string $tariff, string $reason, string $baseUrl): string {
        $reasonBlock = '';
        if (!empty($reason)) {
            $reasonEscaped = htmlspecialchars($reason, ENT_QUOTES, 'UTF-8');
            $reasonBlock = <<<HTML
            <div style="background:#111827;border-left:4px solid #ef4444;border-radius:6px;padding:12px 16px;margin:0 0 20px;">
              <p style="color:#9ca3af;font-size:12px;text-transform:uppercase;letter-spacing:1px;margin:0 0 4px;">Причина отзыва:</p>
              <p style="color:#fca5a5;font-size:14px;margin:0;font-style:italic;">«{$reasonEscaped}»</p>
            </div>
HTML;
        }

        return <<<HTML
<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#0a0e17;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#0a0e17;padding:40px 20px;"><tr><td align="center">
<table width="560" cellpadding="0" cellspacing="0" style="background:#1f2937;border-radius:16px;overflow:hidden;border:1px solid #374151;">
  <tr><td style="background:linear-gradient(135deg,#ef4444,#dc2626);padding:32px;text-align:center;">
    <h1 style="color:#fff;margin:0;font-size:22px;font-weight:700;">AI Business Assistant</h1>
    <p style="color:rgba(255,255,255,0.85);margin:8px 0 0;font-size:14px;">Статус подписки изменён</p>
  </td></tr>
  <tr><td style="padding:32px;">
    <p style="color:#9ca3af;font-size:15px;margin:0 0 16px;">Здравствуйте, <strong style="color:#f9fafb;">{$name}</strong>!</p>
    <p style="color:#9ca3af;font-size:14px;margin:0 0 16px;line-height:1.6;">
      Информируем вас о том, что ваша подписка на тариф <strong style="color:#ef4444;">«{$tariff}»</strong> была <strong>деактивирована</strong> администратором платформы.
    </p>
    {$reasonBlock}
    <p style="color:#9ca3af;font-size:13px;margin:0 0 24px;line-height:1.6;">
      Доступ к премиум-функциям и AI-ассистенту приостановлен. Вы можете выбрать новый тариф в личном кабинете.
    </p>
    <div style="text-align:center;margin:0 0 16px;">
      <a href="{$baseUrl}/user/subscription.php" style="display:inline-block;background:#374151;color:#fff;text-decoration:none;padding:12px 32px;border-radius:10px;font-weight:600;font-size:14px;">Выбрать тариф →</a>
    </div>
  </td></tr>
</table></td></tr></table></body></html>
HTML;
    }

    private static function tplOrganizationCreated(string $directorName, string $orgName, string $legalName, string $inn, string $baseUrl): string {
        $orgEscaped      = htmlspecialchars($orgName, ENT_QUOTES, 'UTF-8');
        $directorEscaped = htmlspecialchars($directorName, ENT_QUOTES, 'UTF-8');
        $legalEscaped    = htmlspecialchars($legalName ?: $orgName, ENT_QUOTES, 'UTF-8');
        $innEscaped      = htmlspecialchars($inn ?: 'Не указан', ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#0a0e17;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#0a0e17;padding:40px 20px;"><tr><td align="center">
<table width="560" cellpadding="0" cellspacing="0" style="background:#1f2937;border-radius:16px;overflow:hidden;border:1px solid #374151;">
  <tr><td style="background:linear-gradient(135deg,#3b82f6,#8b5cf6);padding:32px;text-align:center;">
    <h1 style="color:#fff;margin:0;font-size:22px;font-weight:700;">AI Business Assistant</h1>
    <p style="color:rgba(255,255,255,0.88);margin:8px 0 0;font-size:14px;">Регистрация новой организации</p>
  </td></tr>
  <tr><td style="padding:32px;">
    <p style="color:#9ca3af;font-size:15px;margin:0 0 18px;">Уважаемый(ая) <strong style="color:#f9fafb;">{$directorEscaped}</strong>!</p>
    <p style="color:#9ca3af;font-size:14px;margin:0 0 20px;line-height:1.7;">
      Поздравляем! Ваша организация <strong style="color:#3b82f6;">«{$orgEscaped}»</strong> успешно создана и зарегистрирована в платформе <strong>AI Business Assistant</strong>.
    </p>
    <div style="background:#111827;border:1px solid #374151;border-radius:10px;padding:18px;margin:0 0 24px;">
      <p style="color:#6b7280;font-size:11px;text-transform:uppercase;margin:0 0 10px;font-weight:700;letter-spacing:1px;">Реквизиты организации:</p>
      <div style="font-size:13px;color:#e5e7eb;line-height:1.8;">
        <div><strong>Наименование:</strong> {$orgEscaped}</div>
        <div><strong>Юр. наименование:</strong> {$legalEscaped}</div>
        <div><strong>Генеральный директор:</strong> {$directorEscaped}</div>
        <div><strong>ИНН / БИН:</strong> {$innEscaped}</div>
      </div>
    </div>
    <p style="color:#9ca3af;font-size:13px;margin:0 0 24px;line-height:1.6;">
      Теперь для вашей компании доступны: онлайн-касса (POS), меню и номенклатура, управление должностями, AI-анализ документов, генерация коммерческих предложений и клиентская аналитика.
    </p>
    <div style="text-align:center;">
      <a href="{$baseUrl}/login.php" style="display:inline-block;background:linear-gradient(135deg,#3b82f6,#8b5cf6);color:#fff;text-decoration:none;padding:12px 30px;border-radius:10px;font-weight:700;font-size:14px;">Войти в систему →</a>
    </div>
  </td></tr>
  <tr><td style="background:#111827;padding:18px 32px;border-top:1px solid #374151;text-align:center;">
    <p style="color:#4b5563;font-size:12px;margin:0;">AI Business Assistant — платформа автоматизации бизнеса</p>
  </td></tr>
</table></td></tr></table></body></html>
HTML;
    }

    private static function tplOrganizationDeleted(string $directorName, string $orgName, string $baseUrl, array $stats): string {
        $orgEscaped      = htmlspecialchars($orgName, ENT_QUOTES, 'UTF-8');
        $directorEscaped = htmlspecialchars($directorName, ENT_QUOTES, 'UTF-8');
        $catCount        = (int)($stats['categories'] ?? 0);
        $itemCount       = (int)($stats['items'] ?? 0);
        $posCount        = (int)($stats['positions'] ?? 0);
        $orderCount      = (int)($stats['orders'] ?? 0);

        return <<<HTML
<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#0a0e17;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#0a0e17;padding:40px 20px;"><tr><td align="center">
<table width="560" cellpadding="0" cellspacing="0" style="background:#1f2937;border-radius:16px;overflow:hidden;border:1px solid #374151;">
  <tr><td style="background:linear-gradient(135deg,#ef4444,#dc2626);padding:32px;text-align:center;">
    <h1 style="color:#fff;margin:0;font-size:22px;font-weight:700;">AI Business Assistant</h1>
    <p style="color:rgba(255,255,255,0.88);margin:8px 0 0;font-size:14px;">Уведомление об удалении организации</p>
  </td></tr>
  <tr><td style="padding:32px;">
    <p style="color:#9ca3af;font-size:15px;margin:0 0 18px;">Уважаемый(ая) <strong style="color:#f9fafb;">{$directorEscaped}</strong>!</p>
    <p style="color:#9ca3af;font-size:14px;margin:0 0 20px;line-height:1.7;">
      Информируем вас о том, что организация <strong style="color:#f87171;">«{$orgEscaped}»</strong> была полностью удалена из платформы <strong>AI Business Assistant</strong> администратором сервиса.
    </p>
    <div style="background:#111827;border:1px solid #374151;border-radius:10px;padding:18px;margin:0 0 24px;">
      <p style="color:#6b7280;font-size:11px;text-transform:uppercase;margin:0 0 10px;font-weight:700;letter-spacing:1px;">Очистка данных компании:</p>
      <div style="font-size:13px;color:#e5e7eb;line-height:1.8;">
        <div>• <strong>Категории каталога/меню:</strong> удалено {$catCount} записей</div>
        <div>• <strong>Услуги и позиции прайс-листа:</strong> удалено {$itemCount} записей</div>
        <div>• <strong>Штатные должности:</strong> удалено {$posCount} записей</div>
        <div>• <strong>Чеки и заказы кассы:</strong> удалено {$orderCount} записей</div>
      </div>
    </div>
    <p style="color:#9ca3af;font-size:13px;margin:0 0 24px;line-height:1.6;">
      Доступ к кассовому терминалу, каталогу номенклатуры и отчетам данной организации деактивирован. Если это произошло по ошибке, обратитесь к администратору платформы.
    </p>
    <div style="text-align:center;">
      <a href="{$baseUrl}/login.php" style="display:inline-block;background:#374151;color:#fff;text-decoration:none;padding:12px 30px;border-radius:10px;font-weight:700;font-size:14px;">Перейти в платформу</a>
    </div>
  </td></tr>
  <tr><td style="background:#111827;padding:18px 32px;border-top:1px solid #374151;text-align:center;">
    <p style="color:#4b5563;font-size:12px;margin:0;">Служба безопасности AI Business Assistant</p>
  </td></tr>
</table></td></tr></table></body></html>
HTML;
    }

    private static function tpl2FACode(string $userName, string $code, string $roleTitle): string {
        $nameEscaped = htmlspecialchars($userName, ENT_QUOTES, 'UTF-8');
        $roleEscaped = htmlspecialchars($roleTitle, ENT_QUOTES, 'UTF-8');
        $codeEscaped = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#0a0e17;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#0a0e17;padding:40px 20px;"><tr><td align="center">
<table width="560" cellpadding="0" cellspacing="0" style="background:#1f2937;border-radius:16px;overflow:hidden;border:1px solid #374151;">
  <tr><td style="background:linear-gradient(135deg,#6366f1,#8b5cf6);padding:32px;text-align:center;">
    <h1 style="color:#fff;margin:0;font-size:22px;font-weight:700;">AI Business Assistant</h1>
    <p style="color:rgba(255,255,255,0.88);margin:8px 0 0;font-size:14px;">Двухфакторная аутентификация (2FA)</p>
  </td></tr>
  <tr><td style="padding:32px;">
    <p style="color:#9ca3af;font-size:15px;margin:0 0 18px;">Здравствуйте, <strong style="color:#f9fafb;">{$nameEscaped}</strong>!</p>
    <p style="color:#9ca3af;font-size:14px;margin:0 0 20px;line-height:1.7;">
      Зафиксирована попытка входа с расширенными правами доступа: <strong style="color:#818cf8;">{$roleEscaped}</strong>.
      Для завершения авторизации введите специальный 4-значный код безопасности:
    </p>
    <div style="background:#111827;border:2px dashed #6366f1;border-radius:12px;padding:24px;text-align:center;margin:0 0 24px;">
      <p style="color:#6b7280;font-size:11px;text-transform:uppercase;letter-spacing:1px;margin:0 0 8px;">Одноразовый код безопасности</p>
      <p style="color:#f9fafb;font-size:36px;font-weight:900;letter-spacing:10px;margin:0;font-family:monospace;color:#818cf8;">{$codeEscaped}</p>
    </div>
    <p style="color:#9ca3af;font-size:13px;margin:0;line-height:1.6;text-align:center;">
      ⏱️ Код действителен в течение <strong>10 минут</strong>.<br>
      Если вы не пытались войти в аккаунт, немедленно смените пароль.
    </p>
  </td></tr>
  <tr><td style="background:#111827;padding:18px 32px;border-top:1px solid #374151;text-align:center;">
    <p style="color:#4b5563;font-size:12px;margin:0;">AI Business Assistant — протокол повышенной безопасности</p>
  </td></tr>
</table></td></tr></table></body></html>
HTML;
    }

    private static function tplOrgReqCreated(string $userName, string $reqNum, string $orgName, string $bTypeTitle, string $baseUrl): string {
        $uNameEscaped = htmlspecialchars($userName, ENT_QUOTES, 'UTF-8');
        $reqNumEscaped = htmlspecialchars($reqNum, ENT_QUOTES, 'UTF-8');
        $orgNameEscaped = htmlspecialchars($orgName, ENT_QUOTES, 'UTF-8');
        $bTypeEscaped = htmlspecialchars($bTypeTitle, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#0a0e17;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#0a0e17;padding:40px 20px;"><tr><td align="center">
<table width="560" cellpadding="0" cellspacing="0" style="background:#1f2937;border-radius:16px;overflow:hidden;border:1px solid #374151;">
  <tr><td style="background:linear-gradient(135deg,#3b82f6,#6366f1);padding:32px;text-align:center;">
    <h1 style="color:#fff;margin:0;font-size:22px;font-weight:700;">AI Business Assistant</h1>
    <p style="color:rgba(255,255,255,0.9);margin:8px 0 0;font-size:14px;">Заявка на создание организации принята</p>
  </td></tr>
  <tr><td style="padding:32px;">
    <p style="color:#9ca3af;font-size:15px;margin:0 0 16px;">Здравствуйте, <strong style="color:#f9fafb;">{$uNameEscaped}</strong>!</p>
    <p style="color:#9ca3af;font-size:14px;margin:0 0 20px;line-height:1.7;">
      Ваша заявка на открытие и подключение организации <strong style="color:#818cf8;">«{$orgNameEscaped}»</strong> успешно зарегистрирована в системе и передана администраторам платформы.
    </p>
    <div style="background:#111827;border:2px dashed #6366f1;border-radius:12px;padding:20px;text-align:center;margin:0 0 24px;">
      <p style="color:#6b7280;font-size:11px;text-transform:uppercase;letter-spacing:1px;margin:0 0 6px;">Уникальный номер вашей заявки:</p>
      <p style="color:#818cf8;font-size:24px;font-weight:900;letter-spacing:2px;margin:0;font-family:monospace;">#{$reqNumEscaped}</p>
    </div>
    <div style="background:#111827;border:1px solid #374151;border-radius:10px;padding:16px;margin:0 0 24px;">
      <div style="font-size:13px;color:#e5e7eb;line-height:1.8;">
        <div>• <strong>Название:</strong> {$orgNameEscaped}</div>
        <div>• <strong>Сфера деятельности:</strong> {$bTypeEscaped}</div>
        <div>• <strong>Текущий статус:</strong> <span style="color:#f59e0b;font-weight:700;">Ожидает рассмотрения</span></div>
      </div>
    </div>
    <p style="color:#9ca3af;font-size:13px;margin:0 0 24px;line-height:1.6;">
      Как только администратор подтвердит заявку, для вас автоматически откроются разделы: Касса (POS), Каталог товаров и номенклатура, управление штатом и отчетность.
    </p>
    <div style="text-align:center;">
      <a href="{$baseUrl}/user/request_organization.php" style="display:inline-block;background:linear-gradient(135deg,#3b82f6,#6366f1);color:#fff;text-decoration:none;padding:12px 30px;border-radius:10px;font-weight:700;font-size:14px;">Отслеживать статус →</a>
    </div>
  </td></tr>
  <tr><td style="background:#111827;padding:18px 32px;border-top:1px solid #374151;text-align:center;">
    <p style="color:#4b5563;font-size:12px;margin:0;">Служба клиентского сервиса AI Business Assistant</p>
  </td></tr>
</table></td></tr></table></body></html>
HTML;
    }

    private static function tplOrgReqApproved(string $userName, string $reqNum, string $orgName, string $baseUrl): string {
        $uNameEscaped = htmlspecialchars($userName, ENT_QUOTES, 'UTF-8');
        $reqNumEscaped = htmlspecialchars($reqNum, ENT_QUOTES, 'UTF-8');
        $orgNameEscaped = htmlspecialchars($orgName, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#0a0e17;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#0a0e17;padding:40px 20px;"><tr><td align="center">
<table width="560" cellpadding="0" cellspacing="0" style="background:#1f2937;border-radius:16px;overflow:hidden;border:1px solid #374151;">
  <tr><td style="background:linear-gradient(135deg,#10b981,#059669);padding:32px;text-align:center;">
    <h1 style="color:#fff;margin:0;font-size:22px;font-weight:700;">AI Business Assistant</h1>
    <p style="color:rgba(255,255,255,0.9);margin:8px 0 0;font-size:14px;">Заявка одобрена! Организация активирована</p>
  </td></tr>
  <tr><td style="padding:32px;">
    <p style="color:#9ca3af;font-size:15px;margin:0 0 16px;">Здравствуйте, <strong style="color:#f9fafb;">{$uNameEscaped}</strong>!</p>
    <p style="color:#9ca3af;font-size:14px;margin:0 0 20px;line-height:1.7;">
      Отличные новости! Ваша заявка <strong style="color:#10b981;">#{$reqNumEscaped}</strong> на открытие организации <strong style="color:#f9fafb;">«{$orgNameEscaped}»</strong> была успешно одобрена администратором платформы.
    </p>
    <p style="color:#9ca3af;font-size:14px;margin:0 0 24px;line-height:1.6;">
      Вся инфраструктура компании (онлайн-касса POS, прайс-лист/меню, роли персонала) настроена и готова к работе. В боковом меню личного кабинета теперь доступен полноценный раздел <strong>«Бизнес & POS»</strong>.
    </p>
    <div style="text-align:center;">
      <a href="{$baseUrl}/user/organizations.php" style="display:inline-block;background:linear-gradient(135deg,#10b981,#059669);color:#fff;text-decoration:none;padding:12px 32px;border-radius:10px;font-weight:700;font-size:14px;">Перейти в организацию →</a>
    </div>
  </td></tr>
  <tr><td style="background:#111827;padding:18px 32px;border-top:1px solid #374151;text-align:center;">
    <p style="color:#4b5563;font-size:12px;margin:0;">AI Business Assistant — платформа автоматизации бизнеса</p>
  </td></tr>
</table></td></tr></table></body></html>
HTML;
    }

    private static function tplOrgReqRejected(string $userName, string $reqNum, string $orgName, string $reason, string $baseUrl): string {
        $uNameEscaped = htmlspecialchars($userName, ENT_QUOTES, 'UTF-8');
        $reqNumEscaped = htmlspecialchars($reqNum, ENT_QUOTES, 'UTF-8');
        $orgNameEscaped = htmlspecialchars($orgName, ENT_QUOTES, 'UTF-8');
        $reasonEscaped = htmlspecialchars($reason ?: 'Несоответствие данных или неполные реквизиты.', ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#0a0e17;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#0a0e17;padding:40px 20px;"><tr><td align="center">
<table width="560" cellpadding="0" cellspacing="0" style="background:#1f2937;border-radius:16px;overflow:hidden;border:1px solid #374151;">
  <tr><td style="background:linear-gradient(135deg,#ef4444,#dc2626);padding:32px;text-align:center;">
    <h1 style="color:#fff;margin:0;font-size:22px;font-weight:700;">AI Business Assistant</h1>
    <p style="color:rgba(255,255,255,0.9);margin:8px 0 0;font-size:14px;">Статус заявки на регистрацию</p>
  </td></tr>
  <tr><td style="padding:32px;">
    <p style="color:#9ca3af;font-size:15px;margin:0 0 16px;">Здравствуйте, <strong style="color:#f9fafb;">{$uNameEscaped}</strong>!</p>
    <p style="color:#9ca3af;font-size:14px;margin:0 0 20px;line-height:1.7;">
      Информируем вас о том, что заявка <strong style="color:#ef4444;">#{$reqNumEscaped}</strong> на открытие организации «{$orgNameEscaped}» была отклонена администратором платформы.
    </p>
    <div style="background:#111827;border-left:4px solid #ef4444;border-radius:8px;padding:16px;margin:0 0 24px;">
      <p style="color:#6b7280;font-size:11px;text-transform:uppercase;letter-spacing:1px;margin:0 0 6px;">Причина отклонения:</p>
      <p style="color:#f9fafb;font-size:14px;margin:0;line-height:1.6;">{$reasonEscaped}</p>
    </div>
    <div style="text-align:center;">
      <a href="{$baseUrl}/user/request_organization.php" style="display:inline-block;background:#374151;color:#fff;text-decoration:none;padding:12px 30px;border-radius:10px;font-weight:700;font-size:14px;">Подать новую заявку →</a>
    </div>
  </td></tr>
</table></td></tr></table></body></html>
HTML;
    }

    private static function tplSupportTicketCreated(string $userName, string $ticketNum, string $ticketSubject, string $category, string $baseUrl): string {
        $uNameEscaped = htmlspecialchars($userName, ENT_QUOTES, 'UTF-8');
        $tNumEscaped = htmlspecialchars($ticketNum, ENT_QUOTES, 'UTF-8');
        $subEscaped = htmlspecialchars($ticketSubject, ENT_QUOTES, 'UTF-8');
        $catEscaped = htmlspecialchars($category, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#0a0e17;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#0a0e17;padding:40px 20px;"><tr><td align="center">
<table width="560" cellpadding="0" cellspacing="0" style="background:#1f2937;border-radius:16px;overflow:hidden;border:1px solid #374151;">
  <tr><td style="background:linear-gradient(135deg,#8b5cf6,#6366f1);padding:32px;text-align:center;">
    <h1 style="color:#fff;margin:0;font-size:22px;font-weight:700;">AI Business Assistant</h1>
    <p style="color:rgba(255,255,255,0.9);margin:8px 0 0;font-size:14px;">Обращение в техподдержку #{$tNumEscaped}</p>
  </td></tr>
  <tr><td style="padding:32px;">
    <p style="color:#9ca3af;font-size:15px;margin:0 0 16px;">Здравствуйте, <strong style="color:#f9fafb;">{$uNameEscaped}</strong>!</p>
    <p style="color:#9ca3af;font-size:14px;margin:0 0 20px;line-height:1.7;">
      Ваш запрос в техническую поддержку принят. Дежурный специалист уже подключился к обработке.
    </p>
    <div style="background:#111827;border:1px solid #374151;border-radius:10px;padding:16px;margin:0 0 24px;">
      <div style="font-size:13px;color:#e5e7eb;line-height:1.8;">
        <div>• <strong>Номер обращения:</strong> <span style="color:#a78bfa;font-family:monospace;font-weight:700;">#{$tNumEscaped}</span></div>
        <div>• <strong>Тема:</strong> {$subEscaped}</div>
        <div>• <strong>Категория:</strong> {$catEscaped}</div>
        <div>• <strong>Статус:</strong> <span style="color:#f59e0b;font-weight:700;">Ожидание ответа</span></div>
      </div>
    </div>
    <div style="text-align:center;">
      <a href="{$baseUrl}/user/support_chat.php?ticket={$tNumEscaped}" style="display:inline-block;background:linear-gradient(135deg,#8b5cf6,#6366f1);color:#fff;text-decoration:none;padding:12px 30px;border-radius:10px;font-weight:700;font-size:14px;">Открыть диалог с поддержкой →</a>
    </div>
  </td></tr>
</table></td></tr></table></body></html>
HTML;
    }

    private static function tplSupportReplyNotification(string $userName, string $ticketNum, string $ticketSubject, string $replyText, string $statusTitle, string $baseUrl): string {
        $uNameEscaped = htmlspecialchars($userName, ENT_QUOTES, 'UTF-8');
        $tNumEscaped = htmlspecialchars($ticketNum, ENT_QUOTES, 'UTF-8');
        $subEscaped = htmlspecialchars($ticketSubject, ENT_QUOTES, 'UTF-8');
        $statEscaped = htmlspecialchars($statusTitle, ENT_QUOTES, 'UTF-8');
        $replyEscaped = nl2br(htmlspecialchars($replyText, ENT_QUOTES, 'UTF-8'));

        return <<<HTML
<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#0a0e17;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#0a0e17;padding:40px 20px;"><tr><td align="center">
<table width="560" cellpadding="0" cellspacing="0" style="background:#1f2937;border-radius:16px;overflow:hidden;border:1px solid #374151;">
  <tr><td style="background:linear-gradient(135deg,#06b6d4,#3b82f6);padding:32px;text-align:center;">
    <h1 style="color:#fff;margin:0;font-size:22px;font-weight:700;">AI Business Assistant</h1>
    <p style="color:rgba(255,255,255,0.9);margin:8px 0 0;font-size:14px;">Новый ответ по тикету #{$tNumEscaped}</p>
  </td></tr>
  <tr><td style="padding:32px;">
    <p style="color:#9ca3af;font-size:15px;margin:0 0 16px;">Здравствуйте, <strong style="color:#f9fafb;">{$uNameEscaped}</strong>!</p>
    <p style="color:#9ca3af;font-size:14px;margin:0 0 16px;line-height:1.7;">
      По вашему обращению <strong style="color:#38bdf8;">#{$tNumEscaped}</strong> («{$subEscaped}») поступил ответ от специалиста службы поддержки:
    </p>
    <div style="background:#111827;border-left:4px solid #38bdf8;border-radius:8px;padding:16px;margin:0 0 20px;">
      <div style="font-size:14px;color:#f3f4f6;line-height:1.7;">{$replyEscaped}</div>
    </div>
    <p style="color:#9ca3af;font-size:13px;margin:0 0 24px;">
      Текущий статус обращения: <strong style="color:#f9fafb;">{$statEscaped}</strong>
    </p>
    <div style="text-align:center;">
      <a href="{$baseUrl}/user/support_chat.php?ticket={$tNumEscaped}" style="display:inline-block;background:linear-gradient(135deg,#06b6d4,#3b82f6);color:#fff;text-decoration:none;padding:12px 30px;border-radius:10px;font-weight:700;font-size:14px;">Ответить в чате →</a>
    </div>
  </td></tr>
</table></td></tr></table></body></html>
HTML;
    }
}
