<?php
declare(strict_types=1);
ob_start();

/**
 * AI Business Assistant — Modern High-Tech Webmail Client
 * Glassmorphic design, instant checking, AI reply drafting & dark reader
 */

require_once __DIR__ . '/includes/Auth.php';
require_once __DIR__ . '/includes/ImapClient.php';
require_once __DIR__ . '/includes/Mailer.php';
require_once __DIR__ . '/includes/AIService.php';

$user = Auth::requireAuth('/login.php');
$userId = (int)$user['id'];
$pdo = Database::getConnection();

// Автомиграция таблиц почты
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_email_accounts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            email VARCHAR(191) NOT NULL,
            sender_name VARCHAR(150) NULL,
            imap_host VARCHAR(100) NOT NULL,
            imap_port INT DEFAULT 993,
            imap_user VARCHAR(191) NOT NULL,
            imap_pass VARCHAR(255) NOT NULL,
            smtp_host VARCHAR(100) NOT NULL,
            smtp_port INT DEFAULT 465,
            smtp_user VARCHAR(191) NOT NULL,
            smtp_pass VARCHAR(255) NOT NULL,
            smtp_encryption VARCHAR(10) DEFAULT 'ssl',
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_sent_emails (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            account_id INT NOT NULL,
            to_email VARCHAR(191) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            body_html LONGTEXT NULL,
            body_text LONGTEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_u_a (user_id, account_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_trash_emails (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            account_id INT NOT NULL,
            from_email VARCHAR(191) NULL,
            from_name VARCHAR(150) NULL,
            subject VARCHAR(255) NOT NULL,
            body_html LONGTEXT NULL,
            body_text LONGTEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_u_a (user_id, account_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
} catch (\Throwable $t) {}

// Загрузка подключенных аккаунтов пользователя
$accStmt = $pdo->prepare("SELECT * FROM user_email_accounts WHERE user_id = ? ORDER BY id DESC");
$accStmt->execute([$userId]);
$accounts = $accStmt->fetchAll();

$activeAccountId = (int)($_GET['account_id'] ?? ($accounts[0]['id'] ?? 0));
$activeAccount = null;
foreach ($accounts as $a) {
    if ((int)$a['id'] === $activeAccountId) {
        $activeAccount = $a;
        break;
    }
}
if (!$activeAccount && !empty($accounts)) {
    $activeAccount = $accounts[0];
    $activeAccountId = (int)$activeAccount['id'];
}

// ─────────────────────────────────────────────────────────────────────────────
// AJAX API
// ─────────────────────────────────────────────────────────────────────────────
if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    // 1. Подключение нового ящика
    if ($_GET['action'] === 'connect_account' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCsrf($token)) {
            echo json_encode(['success' => false, 'error' => 'CSRF токен безопасности истёк']);
            exit;
        }

        $email = trim($_POST['email'] ?? '');
        $pass  = trim($_POST['password'] ?? '');
        $name  = trim($_POST['sender_name'] ?? ($user['first_name'] . ' ' . $user['last_name']));
        $imapHost = trim($_POST['imap_host'] ?? '');
        $imapPort = (int)($_POST['imap_port'] ?? 993);
        $smtpHost = trim($_POST['smtp_host'] ?? '');
        $smtpPort = (int)($_POST['smtp_port'] ?? 465);
        $smtpEnc  = trim($_POST['smtp_encryption'] ?? 'ssl');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'error' => 'Укажите корректный email адрес']);
            exit;
        }
        if (empty($pass)) {
            echo json_encode(['success' => false, 'error' => 'Введите пароль или пароль приложения']);
            exit;
        }

        // Автоопределение параметров провайдера
        $domain = strtolower(substr(strrchr($email, "@") ?: '', 1));
        if (empty($imapHost)) {
            if (in_array($domain, ['gmail.com', 'googlemail.com'])) {
                $imapHost = 'imap.gmail.com';
                $smtpHost = 'smtp.gmail.com';
            } elseif (in_array($domain, ['yandex.ru', 'ya.ru', 'yandex.kz', 'yandex.com'])) {
                $imapHost = 'imap.yandex.ru';
                $smtpHost = 'smtp.yandex.ru';
            } elseif (in_array($domain, ['mail.ru', 'inbox.ru', 'list.ru', 'bk.ru'])) {
                $imapHost = 'imap.mail.ru';
                $smtpHost = 'smtp.mail.ru';
            } elseif (in_array($domain, ['rambler.ru', 'lenta.ru', 'ro.ru'])) {
                $imapHost = 'imap.rambler.ru';
                $smtpHost = 'smtp.rambler.ru';
            } else {
                $imapHost = 'imap.' . $domain;
                $smtpHost = 'smtp.' . $domain;
            }
        }

        // Проверяем подключение к IMAP
        $imap = new ImapClient();
        if (!$imap->connect($imapHost, $imapPort, 8)) {
            echo json_encode(['success' => false, 'error' => 'Не удалось связаться с IMAP-сервером: ' . $imap->getLastError()]);
            exit;
        }

        if (!$imap->login($email, $pass)) {
            $err = $imap->getLastError();
            if (str_contains(strtolower($err), 'application-specific password') || str_contains(strtolower($err), 'web login required') || str_contains(strtolower($err), 'invalid credentials')) {
                $err = 'Сервер отклонил пароль. Внимание: для Gmail, Mail.ru и Яндекс необходимо использовать 16-значный «Пароль для внешних приложений», а не основной пароль аккаунта.';
            }
            echo json_encode(['success' => false, 'error' => $err]);
            exit;
        }
        $imap->close();

        // Сохраняем в базу данных
        $ins = $pdo->prepare("
            INSERT INTO user_email_accounts 
            (user_id, email, sender_name, imap_host, imap_port, imap_user, imap_pass, smtp_host, smtp_port, smtp_user, smtp_pass, smtp_encryption)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $ins->execute([
            $userId, $email, $name,
            $imapHost, $imapPort, $email, $pass,
            $smtpHost, $smtpPort, $email, $pass, $smtpEnc
        ]);
        $newId = (int)$pdo->lastInsertId();

        echo json_encode(['success' => true, 'account_id' => $newId]);
        exit;
    }

    // 2. Получение списка писем (INBOX / SENT / TRASH)
    if ($_GET['action'] === 'get_inbox' || $_GET['action'] === 'get_folder') {
        $accId  = (int)($_GET['account_id'] ?? 0);
        $folder = $_GET['folder'] ?? 'INBOX';
        $acc = null;
        foreach ($accounts as $a) {
            if ((int)$a['id'] === $accId) { $acc = $a; break; }
        }

        if (!$acc) {
            echo json_encode(['success' => false, 'error' => 'Почтовый аккаунт не выбран']);
            exit;
        }

        if (in_array($folder, ['SENT', 'TRASH'], true)) {
            $imap = new ImapClient();
            $imapFolderName = '';
            $imapOk = false;

            if ($imap->connect($acc['imap_host'], (int)$acc['imap_port'], 10) && $imap->login($acc['imap_user'], $acc['imap_pass'])) {
                $imapFolderName = ($folder === 'SENT') ? $imap->findSentFolder() : $imap->findTrashFolder();
                $messages = $imap->getMessages($imapFolderName, 30);
                $imap->close();
                $imapOk = true;
                echo json_encode(['success' => true, 'messages' => $messages, 'folder_name' => $imapFolderName]);
            } else {
                $imap->close();
                if ($folder === 'SENT') {
                    $rows = $pdo->prepare("SELECT * FROM user_sent_emails WHERE user_id=? AND account_id=? ORDER BY id DESC LIMIT 30");
                    $rows->execute([$userId, $accId]);
                } else {
                    $rows = $pdo->prepare("SELECT * FROM user_trash_emails WHERE user_id=? AND account_id=? ORDER BY id DESC LIMIT 30");
                    $rows->execute([$userId, $accId]);
                }
                $msgs = [];
                foreach ($rows->fetchAll() as $r) {
                    $ts = strtotime($r['created_at']);
                    $msgs[] = [
                        'seq'       => $r['id'],
                        'uid'       => '',
                        'from'      => $r['from_email'] ?? ($acc['email']),
                        'from_name' => $r['from_name'] ?? ($acc['sender_name'] ?: $acc['email']),
                        'to'        => $r['to_email'] ?? '',
                        'subject'   => $r['subject'] ?? '(Без темы)',
                        'date'      => date('d.m.Y H:i', $ts),
                        'timestamp' => $ts,
                        'iso_date'  => date('c', $ts),
                        'seen'      => true,
                    ];
                }
                echo json_encode(['success' => true, 'messages' => $msgs, 'folder_name' => ($folder === 'SENT' ? 'Отправленные (локально)' : 'Корзина')]);
            }
            exit;
        }

        // INBOX
        $imap = new ImapClient();
        if (!$imap->connect($acc['imap_host'], (int)$acc['imap_port'], 10)) {
            echo json_encode(['success' => false, 'error' => 'Не удалось подключиться: ' . $imap->getLastError()]);
            exit;
        }
        if (!$imap->login($acc['imap_user'], $acc['imap_pass'])) {
            echo json_encode(['success' => false, 'error' => 'Ошибка входа: ' . $imap->getLastError()]);
            exit;
        }
        $messages = $imap->getMessages('INBOX', 30);
        $imap->close();
        echo json_encode(['success' => true, 'messages' => $messages, 'folder_name' => 'INBOX']);
        exit;
    }

    // 3. Получение тела конкретного письма
    if ($_GET['action'] === 'get_message') {
        $accId  = (int)($_GET['account_id'] ?? 0);
        $seq    = (int)($_GET['seq'] ?? 0);
        $folder = $_GET['folder'] ?? 'INBOX';

        $acc = null;
        foreach ($accounts as $a) {
            if ((int)$a['id'] === $accId) { $acc = $a; break; }
        }

        if (!$acc || $seq <= 0) {
            echo json_encode(['success' => false, 'error' => 'Неверные параметры запроса']);
            exit;
        }

        if (in_array($folder, ['SENT', 'TRASH'], true)) {
            $table = ($folder === 'SENT') ? 'user_sent_emails' : 'user_trash_emails';
            $row = $pdo->prepare("SELECT * FROM {$table} WHERE id=? AND user_id=?");
            $row->execute([$seq, $userId]);
            $r = $row->fetch();
            if ($r) {
                echo json_encode(['success' => true, 'body' => ['html' => $r['body_html'] ?? '', 'text' => $r['body_text'] ?? '']]);
                exit;
            }
        }

        $imap = new ImapClient();
        if (!$imap->connect($acc['imap_host'], (int)$acc['imap_port'], 10) || !$imap->login($acc['imap_user'], $acc['imap_pass'])) {
            echo json_encode(['success' => false, 'error' => 'Не удалось прочитать письмо: ' . $imap->getLastError()]);
            exit;
        }

        $imapFolder = ($folder === 'SENT') ? $imap->findSentFolder() : (($folder === 'TRASH') ? $imap->findTrashFolder() : 'INBOX');
        $body = $imap->getMessageBody($seq, $imapFolder);
        $imap->close();

        echo json_encode(['success' => true, 'body' => $body]);
        exit;
    }

    // 4. Отправка письма (Reply / Compose)
    if ($_GET['action'] === 'send_mail' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCsrf($token)) {
            echo json_encode(['success' => false, 'error' => 'CSRF токен истёк']);
            exit;
        }

        $accId   = (int)($_POST['account_id'] ?? 0);
        $to      = trim($_POST['to'] ?? '');
        $subject = trim($_POST['subject'] ?? 'Без темы');
        $body    = trim($_POST['body'] ?? '');

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'error' => 'Укажите корректный email получателя']);
            exit;
        }
        if (empty($body)) {
            echo json_encode(['success' => false, 'error' => 'Текст ответа не может быть пустым']);
            exit;
        }

        $acc = null;
        foreach ($accounts as $a) {
            if ((int)$a['id'] === $accId) { $acc = $a; break; }
        }

        if (!$acc) {
            echo json_encode(['success' => false, 'error' => 'Аккаунт отправителя не найден']);
            exit;
        }

        $htmlBody = nl2br(htmlspecialchars($body));
        $sent = sendDirectSmtp(
            $acc['smtp_host'],
            (int)$acc['smtp_port'],
            $acc['smtp_user'],
            $acc['smtp_pass'],
            $acc['smtp_encryption'] ?? 'ssl',
            $acc['email'],
            $acc['sender_name'] ?: $acc['email'],
            $to,
            $subject,
            $htmlBody,
            $body
        );

        if ($sent['success']) {
            try {
                $pdo->prepare("INSERT INTO user_sent_emails (user_id, account_id, to_email, subject, body_html, body_text) VALUES (?,?,?,?,?,?)")
                    ->execute([$userId, $accId, $to, $subject, $htmlBody, $body]);
            } catch (\Throwable $e) {}
            echo json_encode(['success' => true, 'message' => "Письмо успешно отправлено на {$to}"]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Ошибка отправки: ' . $sent['error']]);
        }
        exit;
    }

    // 5. AI Генератор ответа и выжимки
    if ($_GET['action'] === 'ai_draft_reply' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $subject = trim($_POST['subject'] ?? '');
        $from    = trim($_POST['from'] ?? '');
        $body    = trim($_POST['body'] ?? '');
        $tone    = trim($_POST['tone'] ?? 'general');

        $tonePrompts = [
            'agree' => 'Составь вежливый положительный деловой ответ (согласие/подтверждение). Поблагодари за письмо, подтверди готовность к сотрудничеству.',
            'request_details' => 'Составь вежливый деловой запрос дополнительных деталей и материалов для детального изучения предложения.',
            'decline' => 'Составь максимально вежливый, тактичный деловой отказ с благодарностью за проявленный интерес и пожеланием успехов.',
            'schedule' => 'Составь деловой ответ с предложением провести короткий созвон или онлайн-встречу для обсуждения деталей.',
            'summary' => 'Сделай краткую выжимку входящего письма (TL;DR) в 2-3 пунктах: ключевая мысль, условия и что от нас ожидают.',
            'general' => 'Составь вежливый профессиональный ответ на это входящее деловое письмо.'
        ];

        $instruction = $tonePrompts[$tone] ?? $tonePrompts['general'];
        $cleanBodySnippet = mb_substr(strip_tags($body), 0, 1500);

        $prompt = "Ты — профессиональный бизнес-ассистент компании.\n"
                . "Задача: {$instruction}\n\n"
                . "ВХОДЯЩЕЕ ПИСЬМО:\n"
                . "От: {$from}\n"
                . "Тема: {$subject}\n"
                . "Текст письма:\n{$cleanBodySnippet}\n\n"
                . "Напиши только готовый текст ответа без лишних вступительных фраз от нейросети.";

        try {
            $aiRes = AIService::processPrompt($userId, $prompt);
            $draft = trim($aiRes['response'] ?? '');
            if (!empty($draft)) {
                echo json_encode(['success' => true, 'draft' => $draft]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Не удалось сгенерировать ответ']);
            }
        } catch (\Throwable $e) {
            echo json_encode(['success' => false, 'error' => 'AI ошибка: ' . $e->getMessage()]);
        }
        exit;
    }

    // 6. Удаление аккаунта
    if ($_GET['action'] === 'delete_account' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCsrf($token)) {
            echo json_encode(['success' => false, 'error' => 'CSRF токен истёк']);
            exit;
        }
        $accId = (int)($_POST['account_id'] ?? 0);
        $pdo->prepare("DELETE FROM user_email_accounts WHERE id = ? AND user_id = ?")->execute([$accId, $userId]);
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Неизвестное действие']);
    exit;
}

// Прямая отправка через сокеты
function sendDirectSmtp(
    string $host,
    int $port,
    string $user,
    string $pass,
    string $encryption,
    string $fromEmail,
    string $fromName,
    string $toEmail,
    string $subject,
    string $html,
    string $text = ''
): array {
    $prefix = '';
    if ($encryption === 'ssl' || $port === 465) {
        $prefix = 'ssl://';
    }
    $ctx = stream_context_create([
        'ssl' => [
            'verify_peer'      => false,
            'verify_peer_name' => false,
            'allow_self_signed'=> true
        ]
    ]);

    $errno = 0;
    $errstr = '';
    $socket = @stream_socket_client($prefix . $host . ':' . $port, $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
    if (!$socket) {
        return ['success' => false, 'error' => "Не удалось связаться с SMTP {$host}:{$port} ($errstr)"];
    }

    $read = function () use ($socket) {
        $res = '';
        while (!feof($socket)) {
            $line = fgets($socket, 515);
            $res .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $res;
    };

    $send = function (string $cmd) use ($socket, $read) {
        fwrite($socket, $cmd . "\r\n");
        return $read();
    };

    $r = $read();
    $r = $send("EHLO localhost");

    if ($encryption === 'tls' && $port === 587) {
        $send("STARTTLS");
        stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        $send("EHLO localhost");
    }

    $send("AUTH LOGIN");
    $send(base64_encode($user));
    $authRes = $send(base64_encode($pass));
    if (!str_starts_with($authRes, '235')) {
        fclose($socket);
        return ['success' => false, 'error' => 'Ошибка авторизации SMTP (проверьте пароль приложения): ' . trim($authRes)];
    }

    $send("MAIL FROM:<{$fromEmail}>");
    $rcptRes = $send("RCPT TO:<{$toEmail}>");
    if (!str_starts_with($rcptRes, '250')) {
        fclose($socket);
        return ['success' => false, 'error' => 'Адрес получателя отклонен: ' . trim($rcptRes)];
    }

    $send("DATA");

    $boundary = "b_" . md5(uniqid((string)time()));
    $headers = [
        "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$fromEmail}>",
        "To: <{$toEmail}>",
        "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=",
        "Date: " . date('r'),
        "MIME-Version: 1.0",
        "Content-Type: multipart/alternative; boundary=\"{$boundary}\""
    ];

    $payload = implode("\r\n", $headers) . "\r\n\r\n";
    $payload .= "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
    $payload .= chunk_split(base64_encode($text ?: strip_tags($html))) . "\r\n";
    $payload .= "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
    $payload .= chunk_split(base64_encode($html)) . "\r\n";
    $payload .= "--{$boundary}--\r\n.";

    $dataRes = $send($payload);
    $send("QUIT");
    fclose($socket);

    return ['success' => str_starts_with($dataRes, '250'), 'error' => trim($dataRes)];
}

$csrfToken = Security::csrfToken();
$pageTitle = 'Почтовый клиент (Webmail) | AI Business Assistant';
require __DIR__ . '/includes/header.php';
?>

<style>
/* ── Toast Notifications ─────────────────────────────────────────────────── */
#webmail-toast-container {
    position: fixed;
    top: 24px;
    right: 24px;
    z-index: 10000;
    display: flex;
    flex-direction: column;
    gap: 10px;
    pointer-events: none;
}
.webmail-toast {
    pointer-events: auto;
    min-width: 280px;
    max-width: 380px;
    padding: 12px 18px;
    border-radius: 12px;
    background: rgba(15, 23, 42, 0.92);
    border: 1px solid rgba(255, 255, 255, 0.12);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5), 0 0 20px rgba(99, 102, 241, 0.2);
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 13.5px;
    color: #f8fafc;
    animation: toastSlideIn 0.35s cubic-bezier(0.16, 1, 0.3, 1) both;
    transition: opacity 0.3s ease, transform 0.3s ease;
}
.webmail-toast.success { border-left: 4px solid #10b981; }
.webmail-toast.error   { border-left: 4px solid #ef4444; }
.webmail-toast.info    { border-left: 4px solid #6366f1; }

@keyframes toastSlideIn {
    from { opacity: 0; transform: translateX(40px) scale(0.95); }
    to   { opacity: 1; transform: translateX(0) scale(1); }
}

/* ── Webmail 3-Column Layout ─────────────────────────────────────────────── */
.webmail-container {
    display: grid;
    grid-template-columns: 240px 1fr;
    gap: 18px;
    height: calc(100vh - 180px);
    min-height: 620px;
}
@media (max-width: 860px) {
    .webmail-container { grid-template-columns: 1fr; }
}

/* ── Mail Reader Full-Screen Modal ──────────────────────────────────────── */
.mail-reader-overlay {
    position: fixed;
    inset: 0;
    background: rgba(5, 8, 18, 0.88);
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
    z-index: 10500;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    opacity: 0;
    visibility: hidden;
    transition: opacity 0.28s ease, visibility 0.28s ease;
}
.mail-reader-overlay.open {
    opacity: 1;
    visibility: visible;
}
.mail-reader-card {
    background: rgba(11, 17, 36, 0.97);
    border: 1px solid rgba(99, 102, 241, 0.25);
    border-top: 1px solid rgba(255, 255, 255, 0.18);
    border-radius: 20px;
    width: 100%;
    max-width: 1100px;
    height: 92vh;
    max-height: 900px;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    box-shadow: 0 30px 80px rgba(0,0,0,0.75), 0 0 60px rgba(99,102,241,0.12);
    transform: scale(0.95) translateY(24px);
    transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);
}
.mail-reader-overlay.open .mail-reader-card {
    transform: scale(1) translateY(0);
}

/* Reply section toggle */
.reply-panel {
    border-top: 1px solid rgba(255,255,255,0.08);
    background: rgba(8,12,26,0.98);
    flex-shrink: 0;
    overflow: hidden;
    max-height: 0;
    transition: max-height 0.35s cubic-bezier(0.16, 1, 0.3, 1);
}
.reply-panel.open {
    max-height: 420px;
}

.webmail-folder-item {
    display: flex;
    align-items: center;
    gap: 11px;
    padding: 10px 14px;
    border-radius: var(--radius-md);
    text-decoration: none;
    color: var(--text-secondary);
    font-size: 13px;
    font-weight: 600;
    transition: var(--transition);
}
.webmail-folder-item:hover {
    background: rgba(255, 255, 255, 0.05);
    color: #fff;
    transform: translateX(2px);
}
.webmail-folder-item.active {
    background: linear-gradient(90deg, rgba(99, 102, 241, 0.22) 0%, rgba(99, 102, 241, 0.04) 100%);
    color: #818cf8;
    border-left: 3px solid #6366f1;
}

/* ── Mail List Items ─────────────────────────────────────────────────────── */
.mail-list-item {
    display: flex;
    gap: 12px;
    padding: 14px 16px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
    cursor: pointer;
    transition: var(--transition);
    position: relative;
}
.mail-list-item:hover {
    background: rgba(255, 255, 255, 0.04);
}
.mail-list-item.active {
    background: linear-gradient(90deg, rgba(99, 102, 241, 0.18) 0%, rgba(99, 102, 241, 0.03) 100%);
    border-left: 3px solid #6366f1;
}
.mail-avatar {
    width: 38px;
    height: 38px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 13px;
    color: #fff;
    flex-shrink: 0;
    text-transform: uppercase;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
}
.mail-unread-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #38bdf8;
    box-shadow: 0 0 10px #38bdf8;
    display: inline-block;
    animation: blinkLive 2s infinite;
}


/* ── Sync Animation ──────────────────────────────────────────────────────── */
@keyframes spinSync {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
.is-syncing {
    animation: spinSync 0.9s linear infinite;
    color: #38bdf8 !important;
}

/* ── Modal Glassmorphism ─────────────────────────────────────────────────── */
.webmail-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(7, 10, 19, 0.75);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    z-index: 9999;
    display: flex;
    justify-content: center;
    align-items: center;
    padding: 20px;
    opacity: 0;
    visibility: hidden;
    transition: opacity 0.25s ease, visibility 0.25s ease;
}
.webmail-modal-overlay.open {
    opacity: 1;
    visibility: visible;
}
.webmail-modal-card {
    background: rgba(15, 23, 42, 0.92);
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-top: 1px solid rgba(255, 255, 255, 0.25);
    border-radius: var(--radius-xl);
    padding: 30px;
    width: 100%;
    max-width: 560px;
    box-shadow: var(--shadow-lg);
    transform: scale(0.94) translateY(20px);
    transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);
}
.webmail-modal-overlay.open .webmail-modal-card {
    transform: scale(1) translateY(0);
}
</style>

<!-- Floating Toast Container -->
<div id="webmail-toast-container"></div>

<!-- Top Page Header -->
<div style="margin-bottom:18px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:14px;">
    <div>
        <h2 style="margin:0; font-size:22px; font-weight:800; display:flex; align-items:center; gap:10px; letter-spacing:-0.4px;">
            <i class="fas fa-envelope-open-text" style="color:var(--primary);"></i> Веб-почта платформы
        </h2>
        <p style="margin:4px 0 0; color:var(--text-muted); font-size:13px;">
            Gmail, Яндекс, Mail.ru • Защищённая SSL синхронизация • Умные AI-ответы в 1 клик
        </p>
    </div>
    <div style="display:flex; gap:10px; align-items:center;">
        <button type="button" class="btn btn-primary btn-sm" onclick="openComposeModal()" style="padding:8px 16px;">
            <i class="fas fa-pen-fancy"></i> Написать письмо
        </button>
        <button type="button" class="btn btn-secondary btn-sm" onclick="openConnectModal()" style="padding:8px 16px;">
            <i class="fas fa-plus"></i> Подключить ящик
        </button>
    </div>
</div>

<div class="webmail-container">
    
    <!-- 1. ЛЕВАЯ КОЛОНКА: АККАУНТ И ПАПКИ -->
    <div class="card" style="padding:18px; display:flex; flex-direction:column; overflow:hidden;">
        <button type="button" class="btn btn-primary" onclick="openComposeModal()" style="width:100%; margin-bottom:18px; padding:11px; font-weight:700; border-radius:12px;">
            <i class="fas fa-paper-plane" style="margin-right:8px;"></i> Написать письмо
        </button>

        <div style="font-size:11px; text-transform:uppercase; color:var(--text-muted); letter-spacing:0.8px; font-weight:700; margin-bottom:10px; padding-left:4px;">
            Папки
        </div>

        <div style="display:flex; flex-direction:column; gap:4px;">
            <a href="javascript:void(0)" onclick="loadFolder('INBOX')" class="webmail-folder-item" id="folder-btn-INBOX">
                <i class="fas fa-inbox" style="width:18px; color:#38bdf8;"></i> Входящие
                <span id="folder-count-INBOX" class="badge badge-primary" style="margin-left:auto; font-size:10px; display:none;">0</span>
            </a>
            <a href="javascript:void(0)" onclick="loadFolder('SENT')" class="webmail-folder-item" id="folder-btn-SENT">
                <i class="fas fa-paper-plane" style="width:18px; color:#10b981;"></i> Отправленные
            </a>
            <a href="javascript:void(0)" onclick="loadFolder('TRASH')" class="webmail-folder-item" id="folder-btn-TRASH">
                <i class="fas fa-trash-alt" style="width:18px; color:#ef4444;"></i> Корзина
            </a>
        </div>

        <!-- Разделитель: Мои ящики -->
        <div style="margin-top:24px; font-size:11px; text-transform:uppercase; color:var(--text-muted); letter-spacing:0.8px; font-weight:700; margin-bottom:10px; padding-left:4px; display:flex; justify-content:space-between; align-items:center;">
            <span>Мои ящики</span>
            <span style="font-size:11.5px; cursor:pointer; color:var(--primary); font-weight:700;" onclick="openConnectModal()">+ Добавить</span>
        </div>

        <div style="flex:1; overflow-y:auto; display:flex; flex-direction:column; gap:8px;">
            <?php if (empty($accounts)): ?>
                <div style="padding:20px 10px; text-align:center; color:var(--text-muted); font-size:12.5px; background:rgba(0,0,0,0.2); border-radius:12px; border:1px dashed var(--border-color);">
                    <i class="fas fa-at" style="font-size:24px; opacity:0.3; margin-bottom:8px; display:block;"></i>
                    Пока нет подключенных ящиков
                    <button type="button" class="btn btn-secondary btn-sm" onclick="openConnectModal()" style="margin-top:12px; width:100%; font-size:11.5px;">
                        Подключить Gmail / Яндекс
                    </button>
                </div>
            <?php else: ?>
                <?php foreach ($accounts as $acc): 
                    $isCur = ($acc['id'] === $activeAccountId);
                    $provIcon = 'fa-envelope';
                    $em = strtolower($acc['email']);
                    if (str_contains($em, 'gmail')) $provIcon = 'fa-google';
                    elseif (str_contains($em, 'yandex') || str_contains($em, 'ya.ru')) $provIcon = 'fa-yandex';
                    elseif (str_contains($em, 'mail.ru')) $provIcon = 'fa-at';
                ?>
                    <div style="display:flex; justify-content:space-between; align-items:center; padding:10px 12px; border-radius:12px; background:<?= $isCur ? 'linear-gradient(90deg, rgba(99,102,241,0.22) 0%, rgba(99,102,241,0.05) 100%)' : 'rgba(255,255,255,0.03)' ?>; border:1px solid <?= $isCur ? 'rgba(99,102,241,0.45)' : 'rgba(255,255,255,0.06)' ?>;">
                        <a href="/webmail.php?account_id=<?= $acc['id'] ?>" style="text-decoration:none; color:inherit; flex:1; overflow:hidden;">
                            <div style="display:flex; align-items:center; gap:8px;">
                                <i class="fab <?= $provIcon ?>" style="color:<?= $isCur ? '#818cf8' : 'var(--text-muted)' ?>; font-size:13px;"></i>
                                <span style="font-size:12px; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; color:<?= $isCur ? '#fff' : 'var(--text-primary)' ?>;">
                                    <?= htmlspecialchars($acc['email']) ?>
                                </span>
                            </div>
                            <div style="font-size:11px; color:var(--text-muted); margin-top:2px; padding-left:21px;">
                                <?= htmlspecialchars($acc['sender_name'] ?: 'Почта') ?>
                            </div>
                        </a>
                        <button type="button" onclick="deleteAccount(<?= $acc['id'] ?>, '<?= htmlspecialchars(addslashes($acc['email'])) ?>')" style="background:none; border:none; color:#ef4444; opacity:0.6; cursor:pointer; padding:4px; transition:opacity 0.2s;" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.6'" title="Удалить ящик">
                            <i class="fas fa-trash-alt" style="font-size:12px;"></i>
                        </button>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div style="padding-top:14px; border-top:1px solid rgba(255,255,255,0.07); font-size:11px; color:var(--text-muted); line-height:1.5;">
            <i class="fas fa-shield-alt" style="color:#10b981;"></i> <strong>SSL шифрование:</strong> Ваши письма передаются по защищённому каналу провайдера.
        </div>
    </div>

    <!-- 2. СРЕДНЯЯ КОЛОНКА: СПИСОК ПИСЕМ И СИНХРОНИЗАЦИЯ -->
    <div class="card" style="padding:0; display:flex; flex-direction:column; overflow:hidden;">
        <!-- Header списка с поиском и кнопкой проверки -->
        <div style="padding:14px 16px; border-bottom:1px solid rgba(255,255,255,0.08); background:rgba(0,0,0,0.2);">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                <div style="font-weight:800; font-size:14.5px; display:flex; align-items:center; gap:8px;">
                    <span id="folderTitle">Входящие</span>
                    <span id="mailCountBadge" class="badge badge-primary" style="font-size:11px;">0</span>
                </div>
                <div style="display:flex; align-items:center; gap:8px;">
                    <span id="syncStatusLabel" style="font-size:11px; color:var(--text-muted);">Актуально</span>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="refreshCurrentFolder()" id="btnSyncMail" title="Проверить почту" style="padding:6px 10px; border-radius:8px;">
                        <i class="fas fa-sync-alt" id="syncIcon"></i>
                    </button>
                </div>
            </div>

            <!-- Живой поиск по письмам -->
            <div style="position:relative;">
                <input type="text" id="mailSearchInput" class="form-control" placeholder="Поиск по теме или отправителю..." oninput="filterMails(this.value)" style="padding-left:34px; height:34px; font-size:12.5px; border-radius:8px;">
                <i class="fas fa-search" style="position:absolute; left:12px; top:50%; transform:translateY(-50%); font-size:12px; color:var(--text-muted); pointer-events:none;"></i>
            </div>
        </div>

        <!-- Контейнер писем -->
        <div id="mailListContainer" style="flex:1; overflow-y:auto;">
            <?php if (!$activeAccount): ?>
                <div style="padding:50px 20px; text-align:center; color:var(--text-muted);">
                    <i class="fas fa-envelope" style="font-size:38px; opacity:0.25; margin-bottom:12px; display:block;"></i>
                    <p style="margin:0 0 14px; font-size:14px;">Почтовый ящик не подключен</p>
                    <button type="button" class="btn btn-primary btn-sm" onclick="openConnectModal()">
                        <i class="fas fa-plus"></i> Подключить почту
                    </button>
                </div>
            <?php else: ?>
                <div id="mailListLoading" style="padding:50px 20px; text-align:center; color:var(--text-muted);">
                    <i class="fas fa-spinner fa-spin" style="font-size:26px; color:var(--primary); margin-bottom:10px; display:block;"></i>
                    Синхронизация писем из <?= htmlspecialchars($activeAccount['email']) ?>...
                </div>
            <?php endif; ?>
        </div>
    </div>


</div>

<!-- МОДАЛКА: ПОЛНОЭКРАННОЕ ЧТЕНИЕ ПИСЬМА -->
<div id="mailReaderModal" class="mail-reader-overlay" onclick="closeReaderOnBackdrop(event)">
    <div class="mail-reader-card" id="mailReaderCard">

        <!-- Шапка письма -->
        <div style="padding:18px 24px; border-bottom:1px solid rgba(255,255,255,0.08); background:rgba(0,0,0,0.3); flex-shrink:0;">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:14px; margin-bottom:12px;">
                <h3 id="viewSubject" style="margin:0; font-size:19px; font-weight:800; color:#f8fafc; line-height:1.3; flex:1;"></h3>
                <div style="display:flex; gap:6px; flex-shrink:0; align-items:center;">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="toggleMailTheme()" id="btnThemeToggle" title="Тёмный / Светлый фон письма" style="padding:6px 10px;">
                        <i class="fas fa-adjust"></i>
                    </button>
<button type="button" class="btn btn-primary btn-sm" onclick="toggleReplyPanel()" id="btnToggleReply" style="padding:6px 14px; font-weight:700;">
                        <i class="fas fa-reply"></i> Ответить
                    </button>
                    <button type="button" onclick="closeMailReader()" style="background:rgba(255,255,255,0.07); border:1px solid rgba(255,255,255,0.12); border-radius:10px; color:var(--text-muted); cursor:pointer; font-size:18px; padding:6px 10px; transition:all 0.2s;" onmouseover="this.style.background='rgba(239,68,68,0.2)';this.style.color='#ef4444';" onmouseout="this.style.background='rgba(255,255,255,0.07)';this.style.color='var(--text-muted)';">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <div style="display:flex; align-items:center; gap:10px;">
                    <div id="viewSenderAvatar" class="mail-avatar" style="width:36px; height:36px; font-size:12px;"></div>
                    <div>
                        <strong id="viewFrom" style="color:#f1f5f9; font-size:14px;"></strong>
                        <span id="viewFromEmail" style="color:var(--text-muted); margin-left:6px; font-size:12px;"></span>
                    </div>
                </div>
                <div id="viewDate" style="color:var(--text-muted); font-size:12px;"></div>
            </div>
        </div>



                <!-- Тело письма (занимает всё свободное место) -->
        <div id="viewBody" style="flex:1; overflow-y:auto; background:rgba(8,12,22,0.9); color:#f1f5f9;"></div>

        <!-- Reply Panel (сворачивается/разворачивается) -->
        <div id="replyPanel" class="reply-panel">
            <div style="padding:16px 22px 20px;">
                <!-- Поле ввода ответа -->
                                <!-- Поле ввода ответа -->
                <form onsubmit="submitReply(event)">
                    <textarea id="replyText" class="form-control" rows="4"
                        placeholder="Напишите ответ... Ctrl+Enter для отправки."
                        required
                        style="margin-bottom:10px; font-size:13.5px; border-radius:12px; resize:vertical; min-height:100px;"></textarea>
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <span style="font-size:11px; color:var(--text-muted);">
                            <kbd style="background:rgba(255,255,255,0.08); padding:2px 6px; border-radius:5px; font-size:10px;">Ctrl + Enter</kbd> — отправить
                        </span>
                        <button type="submit" id="btnSendReply" class="btn btn-primary" style="padding:9px 22px; font-weight:700; border-radius:12px;">
                            <i class="fas fa-paper-plane"></i> Отправить ответ
                        </button>
                    </div>
                </form>
            </div>
        </div>

    </div>
</div>

<!-- МОДАЛКА: ПОДКЛЮЧИТЬ ПОЧТУ (GMAIL / YANDEX / MAIL.RU) -->
<div id="connectModal" class="webmail-modal-overlay">
    <div class="webmail-modal-card">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
            <h3 style="margin:0; font-size:18px; font-weight:800; display:flex; align-items:center; gap:8px;">
                <i class="fas fa-envelope-open" style="color:var(--primary);"></i> Подключить почтовый ящик
            </h3>
            <button type="button" onclick="closeConnectModal()" style="background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:20px;">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <form id="connectForm" onsubmit="submitConnectAccount(event)">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

            <div class="form-group" style="margin-bottom:14px;">
                <label class="form-label">Ваш Email адрес *</label>
                <input type="email" name="email" id="accEmail" class="form-control" placeholder="ivanov@gmail.com или petrov@yandex.ru" required oninput="autoDetectMailSettings(this.value)">
            </div>

            <div class="form-group" style="margin-bottom:14px;">
                <label class="form-label">Пароль приложения *</label>
                <input type="password" name="password" id="accPass" class="form-control" placeholder="16-значный специальный пароль" required>
                <div style="font-size:11.5px; color:#fcd34d; margin-top:6px; line-height:1.4; background:rgba(245,158,11,0.12); padding:8px 12px; border-radius:8px; border:1px solid rgba(245,158,11,0.25);" id="passHint">
                    💡 <strong>Важно:</strong> Для Gmail, Mail.ru и Яндекс нужен <strong>«Пароль для внешних приложений»</strong> (создаётся в настройках безопасности Google/Яндекс за 30 секунд).
                </div>
            </div>

            <div class="form-group" style="margin-bottom:14px;">
                <label class="form-label">Имя отправителя (как вас увидят получатели)</label>
                <input type="text" name="sender_name" class="form-control" value="<?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?>" placeholder="Иван Иванов">
            </div>

            <!-- Дополнительные параметры серверов -->
            <div style="margin-bottom:18px;">
                <a href="javascript:void(0)" onclick="toggleAdvancedSettings()" style="font-size:12px; color:var(--primary); font-weight:600; text-decoration:none;">
                    ⚙️ Настройки серверов IMAP / SMTP (определяются автоматически)
                </a>
                <div id="advSettings" style="display:none; margin-top:10px; padding:14px; background:rgba(0,0,0,0.3); border-radius:10px; border:1px solid rgba(255,255,255,0.07);">
                    <div style="display:grid; grid-template-columns: 2fr 1fr; gap:10px; margin-bottom:10px;">
                        <div>
                            <label style="font-size:11px; color:var(--text-muted);">IMAP Сервер</label>
                            <input type="text" name="imap_host" id="imapHost" class="form-control" style="font-size:12px;">
                        </div>
                        <div>
                            <label style="font-size:11px; color:var(--text-muted);">IMAP Порт</label>
                            <input type="number" name="imap_port" id="imapPort" class="form-control" value="993" style="font-size:12px;">
                        </div>
                    </div>
                    <div style="display:grid; grid-template-columns: 2fr 1fr; gap:10px;">
                        <div>
                            <label style="font-size:11px; color:var(--text-muted);">SMTP Сервер</label>
                            <input type="text" name="smtp_host" id="smtpHost" class="form-control" style="font-size:12px;">
                        </div>
                        <div>
                            <label style="font-size:11px; color:var(--text-muted);">SMTP Порт</label>
                            <input type="number" name="smtp_port" id="smtpPort" class="form-control" value="465" style="font-size:12px;">
                        </div>
                    </div>
                </div>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="btn btn-secondary" onclick="closeConnectModal()">Отмена</button>
                <button type="submit" id="btnConnectSubmit" class="btn btn-primary" style="padding:9px 18px; font-weight:700;">
                    <i class="fas fa-plug"></i> Проверить и подключить
                </button>
            </div>
        </form>
    </div>
</div>

<!-- МОДАЛКА: НАПИСАТЬ НОВОЕ ПИСЬМО (COMPOSE) -->
<div id="composeModal" class="webmail-modal-overlay">
    <div class="webmail-modal-card" style="max-width:620px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
            <h3 style="margin:0; font-size:18px; font-weight:800; display:flex; align-items:center; gap:8px;">
                <i class="fas fa-pen-fancy" style="color:var(--primary);"></i> Новое письмо
            </h3>
            <button type="button" onclick="closeComposeModal()" style="background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:20px;">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <form id="composeForm" onsubmit="submitCompose(event)">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="account_id" value="<?= $activeAccountId ?>">

            <div class="form-group" style="margin-bottom:14px;">
                <label class="form-label">Кому (Email) *</label>
                <input type="email" name="to" class="form-control" placeholder="client@example.com" required>
            </div>

            <div class="form-group" style="margin-bottom:14px;">
                <label class="form-label">Тема сообщения *</label>
                <input type="text" name="subject" class="form-control" placeholder="Укажите тему письма..." required>
            </div>

            <div class="form-group" style="margin-bottom:18px;">
                <label class="form-label">Текст сообщения *</label>
                <textarea name="body" class="form-control" rows="8" placeholder="Здравствуйте! ..." required style="font-size:13.5px;"></textarea>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="btn btn-secondary" onclick="closeComposeModal()">Отмена</button>
                <button type="submit" id="btnComposeSend" class="btn btn-primary" style="padding:9px 20px; font-weight:700;">
                    <i class="fas fa-paper-plane"></i> Отправить письмо
                </button>
            </div>
        </form>
    </div>
</div>

<script>
const activeAccountId = <?= (int)$activeAccountId ?>;
let currentMessages   = [];
let selectedMessage   = null;
let currentFolder     = 'INBOX';
let mailThemeDark     = true;

// ── Toast Notification System ────────────────────────────────────────────────
function showToast(type, message) {
    const container = document.getElementById('webmail-toast-container');
    if (!container) return;

    const toast = document.createElement('div');
    toast.className = 'webmail-toast ' + type;

    let icon = 'fa-info-circle';
    if (type === 'success') icon = 'fa-check-circle text-success';
    else if (type === 'error') icon = 'fa-exclamation-triangle text-danger';

    toast.innerHTML = `
        <i class="fas ${icon}" style="font-size:18px; flex-shrink:0;"></i>
        <div style="flex:1; line-height:1.4;">${escapeHtml(message)}</div>
    `;

    container.appendChild(toast);

    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(40px) scale(0.9)';
        setTimeout(() => toast.remove(), 300);
    }, 4000);
}

// ── Форматирование даты письма ───────────────────────────────────────────────
function formatMailDate(isoDate, rawDate) {
    let d = null;
    if (isoDate) d = new Date(isoDate);
    else if (rawDate) d = new Date(rawDate);

    if (!d || isNaN(d.getTime())) return rawDate || '';

    const now = new Date();
    const diff = now - d;
    const diffM = Math.floor(diff / 60000);
    const diffH = diff / 3600000;
    const diffD = diff / 86400000;

    const pad = n => String(n).padStart(2, '0');
    const timeStr = `${pad(d.getHours())}:${pad(d.getMinutes())}`;

    if (diffM < 2)   return 'Только что';
    if (diffM < 60)  return `${diffM} мин назад`;
    if (diffH < 24 && d.toDateString() === now.toDateString()) return `Сегодня, ${timeStr}`;
    if (diffD < 2)   return `Вчера, ${timeStr}`;
    if (diffD < 7)   {
        const days = ['Вс', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб'];
        return `${days[d.getDay()]}, ${timeStr}`;
    }
    return `${pad(d.getDate())}.${pad(d.getMonth() + 1)}.${d.getFullYear()}`;
}

// Генерация аватара по имени отправителя
function getAvatarGradient(name) {
    const gradients = [
        'linear-gradient(135deg, #6366f1, #4f46e5)',
        'linear-gradient(135deg, #06b6d4, #0891b2)',
        'linear-gradient(135deg, #10b981, #059669)',
        'linear-gradient(135deg, #ec4899, #db2777)',
        'linear-gradient(135deg, #f59e0b, #d97706)',
        'linear-gradient(135deg, #8b5cf6, #7c3aed)'
    ];
    let hash = 0;
    for (let i = 0; i < name.length; i++) hash += name.charCodeAt(i);
    return gradients[Math.abs(hash) % gradients.length];
}

function getInitials(name) {
    if (!name) return '??';
    const parts = name.trim().split(/\s+/);
    if (parts.length >= 2) return (parts[0][0] + parts[1][0]).toUpperCase();
    return name.substring(0, 2).toUpperCase();
}

// ── Управление модалками ─────────────────────────────────────────────────────
function openConnectModal() {
    document.getElementById('connectModal').classList.add('open');
}
function closeConnectModal() {
    document.getElementById('connectModal').classList.remove('open');
}
function openComposeModal() {
    <?php if (!$activeAccount): ?>
        showToast('info', 'Сначала подключите почтовый ящик');
        openConnectModal();
        return;
    <?php endif; ?>
    document.getElementById('composeModal').classList.add('open');
}
function closeComposeModal() {
    document.getElementById('composeModal').classList.remove('open');
}
function toggleAdvancedSettings() {
    const el = document.getElementById('advSettings');
    el.style.display = (el.style.display === 'none' ? 'block' : 'none');
}

function autoDetectMailSettings(email) {
    email = email.toLowerCase().trim();
    const domain = email.split('@')[1] || '';
    const imap = document.getElementById('imapHost');
    const smtp = document.getElementById('smtpHost');

    if (domain === 'gmail.com' || domain === 'googlemail.com') {
        imap.value = 'imap.gmail.com'; smtp.value = 'smtp.gmail.com';
    } else if (domain.includes('yandex') || domain === 'ya.ru') {
        imap.value = 'imap.yandex.ru'; smtp.value = 'smtp.yandex.ru';
    } else if (['mail.ru', 'inbox.ru', 'list.ru', 'bk.ru'].includes(domain)) {
        imap.value = 'imap.mail.ru'; smtp.value = 'smtp.mail.ru';
    } else if (domain === 'rambler.ru') {
        imap.value = 'imap.rambler.ru'; smtp.value = 'smtp.rambler.ru';
    } else if (domain) {
        imap.value = 'imap.' + domain; smtp.value = 'smtp.' + domain;
    }
}

// ── Переключение активной папки ──────────────────────────────────────────────
function setActiveFolder(folder) {
    currentFolder = folder;
    ['INBOX', 'SENT', 'TRASH'].forEach(f => {
        const btn = document.getElementById('folder-btn-' + f);
        if (btn) btn.classList.toggle('active', f === folder);
    });
    const titles = { INBOX: 'Входящие', SENT: 'Отправленные', TRASH: 'Корзина' };
    const titleEl = document.getElementById('folderTitle');
    if (titleEl) titleEl.textContent = titles[folder] || folder;
}

function loadFolder(folder) {
    setActiveFolder(folder);
    loadFolderFromServer(folder);
}

function refreshCurrentFolder() {
    loadFolderFromServer(currentFolder, true);
}

// ── Загрузка писем с сервера (Проверка почты) ─────────────────────────────────
async function loadFolderFromServer(folder, isManualSync = false) {
    if (!activeAccountId) return;
    const container = document.getElementById('mailListContainer');
    const badge = document.getElementById('mailCountBadge');
    const syncIcon = document.getElementById('syncIcon');
    const syncStatus = document.getElementById('syncStatusLabel');

    if (syncIcon) syncIcon.classList.add('is-syncing');
    if (syncStatus) syncStatus.textContent = 'Проверка...';

    // Сбрасываем правый просмотр
    // Reader is a modal — no inline preview pane to reset

    if (!isManualSync) {
        container.innerHTML = `
            <div style="padding:40px 20px; text-align:center; color:var(--text-muted);">
                <i class="fas fa-spinner fa-spin" style="font-size:24px; color:var(--primary); margin-bottom:10px; display:block;"></i>
                Загрузка писем...
            </div>
        `;
    }

    try {
        const resp = await fetch(`/webmail.php?action=get_folder&account_id=${activeAccountId}&folder=${folder}`);
        const res = await resp.json();

        if (res.success && res.messages) {
            currentMessages = res.messages;
            badge.textContent = res.messages.length;

            const now = new Date();
            const timeStr = `${String(now.getHours()).padStart(2,'0')}:${String(now.getMinutes()).padStart(2,'0')}`;
            if (syncStatus) syncStatus.textContent = `Обновлено ${timeStr}`;

            if (res.messages.length === 0) {
                const emptyText = folder === 'SENT' ? 'Нет отправленных писем' : (folder === 'TRASH' ? 'Корзина пуста' : 'Папка «Входящие» пуста');
                const emptyIcon = folder === 'SENT' ? 'fa-paper-plane' : (folder === 'TRASH' ? 'fa-trash' : 'fa-inbox');
                container.innerHTML = `
                    <div style="padding:50px 20px; text-align:center; color:var(--text-muted);">
                        <i class="fas ${emptyIcon}" style="font-size:36px; opacity:0.25; margin-bottom:12px; display:block;"></i>
                        ${emptyText}
                    </div>
                `;
                if (isManualSync) showToast('info', 'Входящие проверены. Новых писем нет.');
                return;
            }

            renderMailList(container, res.messages, folder);
            if (isManualSync) showToast('success', `📬 Почта проверена! Писем в папке: ${res.messages.length}`);
        } else {
            container.innerHTML = `<div style="padding:24px; color:#f87171; font-size:13px; text-align:center;">${escapeHtml(res.error || 'Сбой синхронизации')}</div>`;
            if (isManualSync) showToast('error', res.error || 'Ошибка проверки почты');
        }
    } catch (err) {
        container.innerHTML = `<div style="padding:24px; color:#f87171; font-size:13px; text-align:center;">Сбой запроса: ${escapeHtml(err.message)}</div>`;
        if (isManualSync) showToast('error', 'Сбой связи с сервером почты');
    } finally {
        if (syncIcon) syncIcon.classList.remove('is-syncing');
    }
}

// ── Рендер списка писем ──────────────────────────────────────────────────────
function renderMailList(container, messages, folder) {
    container.innerHTML = '';
    messages.forEach(m => {
        const item = document.createElement('div');
        item.className = 'mail-list-item ' + (m.seen ? '' : 'unread');
        item.id = 'mail-item-' + m.seq;
        item.onclick = () => selectMail(m, folder);

        const dateStr = formatMailDate(m.iso_date || '', m.raw_date || m.date || '');
        const senderName = m.from_name || m.from || 'Неизвестный';
        const fromLabel = folder === 'SENT' ? 'Кому: ' + (m.to || '') : senderName;
        const initials = getInitials(senderName);
        const grad = getAvatarGradient(senderName);

        item.innerHTML = `
            <div class="mail-avatar" style="background:${grad};">
                ${initials}
            </div>
            <div style="flex:1; min-width:0;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px; gap:8px;">
                    <div style="font-weight:700; font-size:13px; color:var(--text-primary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                        ${!m.seen ? '<span class="mail-unread-dot" style="margin-right:6px;"></span>' : ''}
                        ${escapeHtml(fromLabel)}
                    </div>
                    <div style="font-size:11px; color:var(--text-muted); white-space:nowrap; flex-shrink:0;">
                        ${escapeHtml(dateStr)}
                    </div>
                </div>
                <div style="font-size:13px; color:${m.seen ? 'var(--text-secondary)' : '#ffffff'}; font-weight:${m.seen ? 'normal' : '700'}; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                    ${escapeHtml(m.subject || '(Без темы)')}
                </div>
            </div>
        `;
        container.appendChild(item);
    });
}

// Фильтр поиска по письмам
function filterMails(query) {
    query = query.toLowerCase().trim();
    const container = document.getElementById('mailListContainer');
    if (!currentMessages || currentMessages.length === 0) return;

    if (!query) {
        renderMailList(container, currentMessages, currentFolder);
        return;
    }

    const filtered = currentMessages.filter(m => {
        const sub = (m.subject || '').toLowerCase();
        const from = (m.from || '').toLowerCase();
        const fromName = (m.from_name || '').toLowerCase();
        const to = (m.to || '').toLowerCase();
        return sub.includes(query) || from.includes(query) || fromName.includes(query) || to.includes(query);
    });

    renderMailList(container, filtered, currentFolder);
}

// ── Открытие полноэкранного ридера ──────────────────────────────────────────
async function selectMail(m, folder) {
    selectedMessage = m;
    selectedMessage._folder = folder || currentFolder;

    document.querySelectorAll('.mail-list-item').forEach(el => el.classList.remove('active'));
    const cur = document.getElementById('mail-item-' + m.seq);
    if (cur) cur.classList.add('active');

    // Закрыть reply panel
    closeReplyPanel();

    // Заполнить шапку
    document.getElementById('viewSubject').textContent = m.subject || '(Без темы)';
    const senderName = m.from_name || m.from;
    document.getElementById('viewFrom').textContent = senderName;
    document.getElementById('viewFromEmail').textContent = '<' + m.from + '>';

    const avEl = document.getElementById('viewSenderAvatar');
    avEl.style.background = getAvatarGradient(senderName);
    avEl.textContent = getInitials(senderName);

    document.getElementById('viewDate').textContent = formatMailDate(m.iso_date || '', m.raw_date || m.date || '');
    document.getElementById('replyText').value = '';

    const bodyContainer = document.getElementById('viewBody');
    bodyContainer.innerHTML = '<div style="padding:60px; text-align:center; color:var(--text-muted);"><i class="fas fa-spinner fa-spin" style="font-size:28px; color:var(--primary); margin-bottom:12px; display:block;"></i>Загрузка письма...</div>';

    // Открываем ридер
    openMailReader();

    try {
        const f = selectedMessage._folder || currentFolder;
        const resp = await fetch(`/webmail.php?action=get_message&account_id=${activeAccountId}&seq=${m.seq}&folder=${f}`);
        const res = await resp.json();

        if (res.success && res.body) {
            selectedMessage._bodyData = res.body;
            renderMailBody(res.body);
        } else {
            bodyContainer.innerHTML = '<div style="color:#ef4444; padding:40px; text-align:center;">Не удалось загрузить письмо</div>';
        }
    } catch (err) {
        bodyContainer.innerHTML = '<div style="color:#ef4444; padding:40px; text-align:center;">Ошибка: ' + escapeHtml(err.message) + '</div>';
    }
}

function openMailReader() {
    document.getElementById('mailReaderModal').classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeMailReader() {
    document.getElementById('mailReaderModal').classList.remove('open');
    document.body.style.overflow = '';
    document.querySelectorAll('.mail-list-item').forEach(el => el.classList.remove('active'));
    selectedMessage = null;
}

function closeReaderOnBackdrop(e) {
    if (e.target === document.getElementById('mailReaderModal')) {
        closeMailReader();
    }
}

function toggleReplyPanel() {
    const panel = document.getElementById('replyPanel');
    const isOpen = panel.classList.contains('open');
    if (isOpen) {
        closeReplyPanel();
    } else {
        panel.classList.add('open');
        document.getElementById('btnToggleReply').innerHTML = '<i class="fas fa-chevron-down"></i> Скрыть';
        setTimeout(() => {
            const ta = document.getElementById('replyText');
            if (ta) ta.focus();
        }, 350);
    }
}

function closeReplyPanel() {
    document.getElementById('replyPanel').classList.remove('open');
    const btn = document.getElementById('btnToggleReply');
    if (btn) btn.innerHTML = '<i class="fas fa-reply"></i> Ответить';
}

// Рендер тела письма (внутри ридера)
function renderMailBody(bodyData) {
    const bodyContainer = document.getElementById('viewBody');
    let htmlContent = (bodyData.html || '').trim();
    let textContent = (bodyData.text || '').trim();

    if (htmlContent) {
        bodyContainer.innerHTML = '';
        const iframe = document.createElement('iframe');
        iframe.id = 'mailBodyIframe';
        iframe.style.cssText = 'width:100%;height:100%;min-height:400px;border:none;display:block;';
        iframe.sandbox = 'allow-same-origin allow-popups';
        bodyContainer.style.padding = '0';
        bodyContainer.appendChild(iframe);

        const bg = mailThemeDark ? '#0a0f1e' : '#ffffff';
        const color = mailThemeDark ? '#e8edf5' : '#111827';
        const linkColor = mailThemeDark ? '#38bdf8' : '#2563eb';

        iframe.srcdoc = `<!DOCTYPE html><html><head><meta charset="utf-8"><base target="_blank"><style>*{box-sizing:border-box;}body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;margin:0;padding:24px 28px;color:${color};background:${bg};word-break:break-word;line-height:1.65;font-size:14px;}img{max-width:100%;height:auto;}a{color:${linkColor};}table{max-width:100%;}pre,code{white-space:pre-wrap;word-break:break-all;}</style></head><body>${htmlContent}</body></html>`;
    } else if (textContent) {
        const bg = mailThemeDark ? '#0a0f1e' : '#ffffff';
        const color = mailThemeDark ? '#e8edf5' : '#111827';
        bodyContainer.style.padding = '28px';
        bodyContainer.innerHTML = `<div style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;white-space:pre-wrap;line-height:1.7;color:${color};font-size:14px;">${escapeHtml(textContent)}</div>`;
    } else {
        bodyContainer.style.padding = '40px';
        bodyContainer.innerHTML = '<div style="color:var(--text-muted);text-align:center;padding:60px 20px;"><i class="fas fa-envelope-open" style="font-size:40px;opacity:0.2;margin-bottom:14px;display:block;"></i><em>Письмо не содержит текста</em></div>';
    }
}

// Переключение тёмного/светлого фона письма
function toggleMailTheme() {
    mailThemeDark = !mailThemeDark;
    const btn = document.getElementById('btnThemeToggle');
    if (btn) btn.title = mailThemeDark ? 'Тёмный фон активен' : 'Светлый фон активен';
    if (selectedMessage && selectedMessage._bodyData) {
        renderMailBody(selectedMessage._bodyData);
    }
    showToast('info', mailThemeDark ? 'Тёмный фон письма включён' : 'Светлый фон письма включён');
}






// ── Отправка ответа (Reply) ──────────────────────────────────────────────────
async function submitReply(e) {
    if (e) e.preventDefault();
    if (!selectedMessage) return;

    const text = document.getElementById('replyText').value.trim();
    if (!text) {
        showToast('error', 'Введите текст ответа');
        return;
    }

    const btn = document.getElementById('btnSendReply');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Отправка...';

    const formData = new FormData();
    formData.append('csrf_token', '<?= $csrfToken ?>');
    formData.append('account_id', activeAccountId);
    formData.append('to', selectedMessage.from);
    formData.append('subject', (selectedMessage.subject.startsWith('Re:') ? '' : 'Re: ') + selectedMessage.subject);
    formData.append('body', text);

    try {
        const resp = await fetch('/webmail.php?action=send_mail', { method: 'POST', body: formData });
        const res = await resp.json();

        if (res.success) {
            showToast('success', `✅ Ответ успешно отправлен на ${selectedMessage.from}!`);
            document.getElementById('replyText').value = '';
        } else {
            showToast('error', res.error || 'Не удалось отправить ответ');
        }
    } catch (err) {
        showToast('error', 'Ошибка связи: ' + err.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> Отправить ответ';
    }
}

// ── Отправка нового письма (Compose) ─────────────────────────────────────────
async function submitCompose(e) {
    e.preventDefault();
    const form = document.getElementById('composeForm');
    const btn = document.getElementById('btnComposeSend');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Отправка...';

    const formData = new FormData(form);

    try {
        const resp = await fetch('/webmail.php?action=send_mail', { method: 'POST', body: formData });
        const res = await resp.json();

        if (res.success) {
            showToast('success', '✅ Новое письмо успешно отправлено!');
            closeComposeModal();
            form.reset();
        } else {
            showToast('error', res.error || 'Сбой при отправке');
        }
    } catch (err) {
        showToast('error', 'Ошибка связи: ' + err.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> Отправить письмо';
    }
}

// ── Подключение ящика ────────────────────────────────────────────────────────
async function submitConnectAccount(e) {
    e.preventDefault();
    const btn = document.getElementById('btnConnectSubmit');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Проверка подключения...';

    const formData = new FormData(document.getElementById('connectForm'));

    try {
        const resp = await fetch('/webmail.php?action=connect_account', { method: 'POST', body: formData });
        const res = await resp.json();

        if (res.success) {
            showToast('success', 'Почтовый ящик успешно проверен и подключен!');
            setTimeout(() => {
                window.location.href = '/webmail.php?account_id=' + res.account_id;
            }, 800);
        } else {
            showToast('error', res.error || 'Не удалось подключиться к серверу');
        }
    } catch (err) {
        showToast('error', 'Сбой связи с сервером: ' + err.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-plug"></i> Проверить и подключить';
    }
}

// ── Удаление ящика ───────────────────────────────────────────────────────────
async function deleteAccount(id, email) {
    if (!confirm('Отключить почтовый ящик ' + email + '?')) return;
    const formData = new FormData();
    formData.append('csrf_token', '<?= $csrfToken ?>');
    formData.append('account_id', id);

    const resp = await fetch('/webmail.php?action=delete_account', { method: 'POST', body: formData });
    const res = await resp.json();
    if (res.success) {
        showToast('info', 'Почтовый ящик отключен');
        setTimeout(() => window.location.href = '/webmail.php', 500);
    }
}

function escapeHtml(text) {
    if (!text) return '';
    const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
    return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
}

// Hotkey: Ctrl + Enter для отправки ответа
document.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
        const activeEl = document.activeElement;
        if (activeEl && activeEl.id === 'replyText') {
            e.preventDefault();
            submitReply();
        }
    }
    if (e.key === 'Escape') {
        const reader = document.getElementById('mailReaderModal');
        if (reader && reader.classList.contains('open')) {
            closeMailReader();
        }
    }
});

window.addEventListener('DOMContentLoaded', () => {
    setActiveFolder('INBOX');
    if (activeAccountId) {
        loadFolderFromServer('INBOX');
    }
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
