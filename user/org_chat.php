<?php
declare(strict_types=1);

/**
 * AI Business Assistant - User Organization Leadership Chat
 * Быстрый переход в чат руководства и дирекции своей компании
 */

require_once dirname(__DIR__) . '/includes/Auth.php';

$user = Auth::requireAuth('/login.php');
$userId = (int)$user['id'];
$pdo = Database::getConnection();

$orgStmt = $pdo->prepare("
    SELECT id, name FROM organizations 
    WHERE (user_id = ? OR director_email = ?) AND is_active = 1 
    ORDER BY id DESC LIMIT 1
");
$orgStmt->execute([$userId, $user['email']]);
$org = $orgStmt->fetch();

if (!$org) {
    header("Location: /user/request_organization.php");
    exit;
}

header("Location: /chat.php?channel=org_" . $org['id']);
exit;
