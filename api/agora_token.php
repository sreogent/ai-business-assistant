<?php
declare(strict_types=1);

/**
 * AI Business Assistant - Agora Token API
 * Возвращает App ID, токен и параметры подключения к каналу связи.
 */

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/includes/Auth.php';
require_once dirname(__DIR__) . '/includes/AgoraService.php';

$user = Auth::user();
$channelName = trim($_GET['channel_name'] ?? $_POST['channel_name'] ?? '');
$isGuest = isset($_GET['guest']) || isset($_POST['guest']);

// Если не гость, проверяем авторизацию сотрудника
if (!$isGuest && !$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Необходима авторизация']);
    exit;
}

if (empty($channelName)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Не указано имя канала связи']);
    exit;
}

// Фильтруем имя канала (только буквы, цифры, дефис и подчеркивание)
$cleanChannel = preg_replace('/[^a-zA-Z0-9_\-]/', '', $channelName);
if (empty($cleanChannel)) {
    $cleanChannel = 'call_' . time();
}

$uid = $user ? (string)$user['id'] : (string)rand(100000, 999999);

try {
    $token = AgoraService::createRtcToken($cleanChannel, '0', 7200);

    echo json_encode([
        'success'      => true,
        'app_id'       => AgoraService::APP_ID,
        'channel_name' => $cleanChannel,
        'token'        => $token,
        'uid'          => 0,
        'user_name'    => $user ? trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) : 'Собеседник'
    ], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Ошибка генерации токена: ' . $e->getMessage()]);
}
