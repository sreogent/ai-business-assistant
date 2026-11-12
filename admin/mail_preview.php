<?php
declare(strict_types=1);

/**
 * AI Business Assistant - Admin: Email HTML Preview
 * Safely renders a saved mail_log HTML file for admin preview
 */

require_once dirname(__DIR__) . '/includes/Auth.php';
Auth::requireAdmin();

$file = basename($_GET['file'] ?? '');
if (!preg_match('/^mail_[\w]+\.html$/', $file)) {
    http_response_code(400);
    die('Неверное имя файла.');
}

$logDir  = dirname(__DIR__) . '/uploads/mail_log/';
$fullPath = $logDir . $file;

if (!file_exists($fullPath)) {
    http_response_code(404);
    die('Файл не найден.');
}

// Strip the <!-- comment header --> and render only the HTML email body
$content = file_get_contents($fullPath);
$clean   = preg_replace('/^<!--.*?-->\n/s', '', $content);

// Inject a simple back bar on top
echo '<div style="background:#1f2937;padding:10px 20px;font-family:sans-serif;font-size:13px;color:#9ca3af;border-bottom:1px solid #374151;">';
echo '<a href="javascript:history.back()" style="color:#3b82f6;text-decoration:none;">← Назад</a> &nbsp;|&nbsp; <a href="/admin/test_smtp.php" style="color:#818cf8;text-decoration:none;">SMTP Диагностика</a> &nbsp;|&nbsp; <a href="/admin/subscription_keys.php" style="color:#9ca3af;text-decoration:none;">Ключи подписок</a>';
echo ' &nbsp;|&nbsp; Файл: ' . htmlspecialchars($file);
echo '</div>';
echo $clean;
