<?php
declare(strict_types=1);

/**
 * AI Business Assistant - Voice Messages API
 * Загрузка и отправка голосовых сообщений (аудиозаметок) для личных сообщений и чатов.
 */

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/includes/Auth.php';

$user = Auth::user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Необходима авторизация']);
    exit;
}

$userId = (int)$user['id'];
$userName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: 'Сотрудник';
$pdo = Database::getConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Метод не поддерживается']);
    exit;
}

if (empty($_FILES['voice_file'])) {
    echo json_encode(['success' => false, 'message' => 'Аудиофайл не передан']);
    exit;
}

$file = $_FILES['voice_file'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'Ошибка при загрузке аудиофайла']);
    exit;
}

// Проверяем размер (до 15 МБ)
if ($file['size'] > 15 * 1024 * 1024) {
    echo json_encode(['success' => false, 'message' => 'Файл слишком большой (макс. 15 МБ)']);
    exit;
}

$uploadDir = dirname(__DIR__) . '/uploads/voice_messages';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$ext = pathinfo($file['name'], PATHINFO_EXTENSION);
if (!in_array(strtolower($ext), ['webm', 'wav', 'mp3', 'ogg', 'm4a'], true)) {
    $ext = 'webm';
}

$filename = 'voice_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$destPath = $uploadDir . '/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    echo json_encode(['success' => false, 'message' => 'Не удалось сохранить аудиофайл']);
    exit;
}

$webUrl = '/uploads/voice_messages/' . $filename;
$durationSec = max(1, (int)($_POST['duration_sec'] ?? 1));
$targetType = trim($_POST['target_type'] ?? 'direct'); // 'direct' or 'channel'

try {
    if ($targetType === 'direct') {
        $receiverId = (int)($_POST['receiver_id'] ?? 0);
        if ($receiverId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Не указан получатель']);
            exit;
        }

        $ins = $pdo->prepare("
            INSERT INTO direct_messages (sender_id, receiver_id, message, audio_url, audio_duration, is_read)
            VALUES (?, ?, ?, ?, ?, 0)
        ");
        $ins->execute([$userId, $receiverId, '🎤 Голосовое сообщение', $webUrl, $durationSec]);
        $msgId = (int)$pdo->lastInsertId();

        echo json_encode([
            'success'   => true,
            'msg_id'    => $msgId,
            'audio_url' => $webUrl,
            'duration'  => $durationSec
        ]);
        exit;
    }

    if ($targetType === 'channel') {
        $channelId = (int)($_POST['channel_id'] ?? 0);
        if ($channelId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Не указан канал чата']);
            exit;
        }

        $durStr = sprintf("%02d:%02d", floor($durationSec / 60), $durationSec % 60);
        $voiceHtml = '<div class="chat-voice-msg" data-audio="' . htmlspecialchars($webUrl, ENT_QUOTES) . '" style="display:inline-flex; align-items:center; gap:10px; background:rgba(99,102,241,0.15); border:1px solid rgba(99,102,241,0.3); border-radius:14px; padding:6px 14px; margin-top:4px;">'
                   . '<button type="button" class="btn-play-voice" onclick="window.playVoiceNote(this, \'' . htmlspecialchars($webUrl, ENT_QUOTES) . '\')" style="background:#6366f1; border:none; border-radius:50%; width:32px; height:32px; color:#fff; display:flex; align-items:center; justify-content:center; cursor:pointer;"><i class="fas fa-play" style="font-size:12px; margin-left:2px;"></i></button>'
                   . '<div style="display:flex; flex-direction:column;"><span style="font-size:12px; font-weight:600;">🎤 Голосовое сообщение</span><span class="voice-dur" style="font-size:10px; color:#94a3b8;">' . $durStr . '</span></div>'
                   . '</div>';

        $ins = $pdo->prepare("INSERT INTO team_chat_messages (channel_id, sender_id, message) VALUES (?, ?, ?)");
        $ins->execute([$channelId, $userId, $voiceHtml]);
        $msgId = (int)$pdo->lastInsertId();

        echo json_encode([
            'success'   => true,
            'msg_id'    => $msgId,
            'audio_url' => $webUrl,
            'duration'  => $durationSec
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Неизвестный тип назначения']);
} catch (\Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Ошибка базы данных: ' . $e->getMessage()]);
}
