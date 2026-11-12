<?php
declare(strict_types=1);

/**
 * AI Business Assistant - Site Leadership Chat
 * Быстрый переход в чат руководства сайта, разработчиков, тестировщиков и админов
 */

require_once dirname(__DIR__) . '/includes/Auth.php';

$user = Auth::requireAuth('/login.php');

if (!Auth::isStaff()) {
    http_response_code(403);
    require dirname(__DIR__) . '/includes/403.php';
    exit;
}

header("Location: /chat.php?channel=site_hq");
exit;
