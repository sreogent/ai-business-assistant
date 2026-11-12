<?php
declare(strict_types=1);

/**
 * AI Business Assistant - AI Chat API Endpoint
 */

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/includes/Auth.php';
require_once dirname(__DIR__) . '/includes/Subscription.php';
require_once dirname(__DIR__) . '/includes/AIService.php';

$user = Auth::user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Необходима авторизация']);
    exit;
}

$userId = (int)$user['id'];

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['success' => false, 'message' => 'Неверный формат запроса']);
    exit;
}

// Check CSRF
$token = $input['csrf_token'] ?? '';
if (!Security::verifyCsrf($token)) {
    echo json_encode(['success' => false, 'message' => 'Ошибка безопасности (CSRF). Обновите страницу.']);
    exit;
}

// Check Subscription & AI limits
$subCheck = Subscription::canUseAi($userId);
if (!$subCheck['allowed']) {
    echo json_encode(['success' => false, 'message' => $subCheck['reason']]);
    exit;
}

$prompt = trim($input['prompt'] ?? '');
if (empty($prompt)) {
    echo json_encode(['success' => false, 'message' => 'Запрос не может быть пустым']);
    exit;
}

$docId = !empty($input['document_id']) ? (int)$input['document_id'] : null;

// Call AI Service
try {
    $result = AIService::processPrompt($userId, $prompt, $docId);
    echo json_encode([
        'success' => true,
        'response' => $result['response'],
        'provider' => $result['provider']
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Ошибка обработки запроса: ' . $e->getMessage()]);
}
