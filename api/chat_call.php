<?php
declare(strict_types=1);

/**
 * AI Business Assistant - Chat Voice Calls & Meetings API
 */

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/includes/Auth.php';
require_once dirname(__DIR__) . '/includes/AgoraService.php';

$user = Auth::user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Необходима авторизация']);
    exit;
}

$userId = (int)$user['id'];
$userName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: 'Сотрудник';
$pdo = Database::getConnection();

$action = trim($_GET['action'] ?? $_POST['action'] ?? '');

try {
    // 1. Запуск созвона в чате
    if ($action === 'start_call') {
        $channelKey = trim($_POST['channel_key'] ?? $_GET['channel_key'] ?? '');
        if (empty($channelKey)) {
            echo json_encode(['success' => false, 'message' => 'Не указан чат']);
            exit;
        }

        $chanStmt = $pdo->prepare("SELECT * FROM team_chat_channels WHERE channel_key = ? LIMIT 1");
        $chanStmt->execute([$channelKey]);
        $channel = $chanStmt->fetch();

        if (!$channel) {
            echo json_encode(['success' => false, 'message' => 'Чат не найден']);
            exit;
        }

        $channelId = (int)$channel['id'];

        // Завершаем старые созвоны в этом канале
        $pdo->prepare("UPDATE team_chat_calls SET status = 'ended', ended_at = NOW() WHERE channel_id = ? AND status IN ('ringing', 'active')")
            ->execute([$channelId]);

        // Создаем новый звонок (без спама сообщениями в ленту чата)
        $insCall = $pdo->prepare("
            INSERT INTO team_chat_calls (channel_id, channel_key, initiator_id, status)
            VALUES (?, ?, ?, 'ringing')
        ");
        $insCall->execute([$channelId, $channelKey, $userId]);
        $callId = (int)$pdo->lastInsertId();

        // Генерируем токен Agora
        $roomKey = 'chat_' . $channelKey;
        $token = AgoraService::createRtcToken($roomKey, (string)$userId, 7200);

        echo json_encode([
            'success'      => true,
            'call_id'      => $callId,
            'app_id'       => AgoraService::APP_ID,
            'channel_key'  => $roomKey,
            'token'        => $token,
            'channel_name' => $channel['name'],
            'initiator'    => $userName,
            'uid'          => $userId
        ]);
        exit;
    }

    // 2. Проверка активных входящих звонков (поллинг для фонового звука)
    if ($action === 'check_incoming') {
        // Ищем созвоны, начатые за последние 40 секунд кем-то другим
        $stmt = $pdo->prepare("
            SELECT tc.*, c.name AS channel_name, c.channel_key AS c_key, u.first_name, u.last_name
            FROM team_chat_calls tc
            INNER JOIN team_chat_channels c ON tc.channel_id = c.id
            INNER JOIN users u ON tc.initiator_id = u.id
            WHERE tc.status IN ('ringing', 'active')
              AND tc.initiator_id != ?
              AND tc.started_at >= (NOW() - INTERVAL 45 SECOND)
            ORDER BY tc.id DESC LIMIT 1
        ");
        $stmt->execute([$userId]);
        $activeCall = $stmt->fetch();

        if ($activeCall) {
            $initiatorName = trim($activeCall['first_name'] . ' ' . $activeCall['last_name']) ?: 'Коллега';
            echo json_encode([
                'has_call'       => true,
                'call_id'        => (int)$activeCall['id'],
                'channel_id'     => (int)$activeCall['channel_id'],
                'channel_key'    => $activeCall['c_key'],
                'channel_name'   => $activeCall['channel_name'],
                'initiator_name' => $initiatorName,
                'status'         => $activeCall['status']
            ]);
        } else {
            echo json_encode(['has_call' => false]);
        }
    }

    // 3. Присоединение к созвону
    if ($action === 'join_call') {
        $callId = (int)($_POST['call_id'] ?? $_GET['call_id'] ?? 0);
        $callStmt = $pdo->prepare("
            SELECT tc.*, c.name AS channel_name, c.channel_key AS c_key
            FROM team_chat_calls tc
            INNER JOIN team_chat_channels c ON tc.channel_id = c.id
            WHERE tc.id = ? AND tc.status IN ('ringing', 'active')
            LIMIT 1
        ");
        $callStmt->execute([$callId]);
        $call = $callStmt->fetch();

        if (!$call) {
            echo json_encode(['success' => false, 'message' => 'Созвон уже завершён или не существует']);
            exit;
        }

        // Переводим статус в активный
        $pdo->prepare("UPDATE team_chat_calls SET status = 'active' WHERE id = ?")->execute([$callId]);

        $roomKey = 'chat_' . $call['c_key'];
        $token = AgoraService::createRtcToken($roomKey, (string)$userId, 7200);

        echo json_encode([
            'success'      => true,
            'call_id'      => $callId,
            'app_id'       => AgoraService::APP_ID,
            'channel_key'  => $roomKey,
            'token'        => $token,
            'channel_name' => $call['channel_name'],
            'uid'          => $userId
        ]);
        exit;
    }

    // 4. Завершение созвона
    if ($action === 'end_call') {
        $callId = (int)($_POST['call_id'] ?? $_GET['call_id'] ?? 0);
        if ($callId > 0) {
            $pdo->prepare("UPDATE team_chat_calls SET status = 'ended', ended_at = NOW() WHERE id = ?")->execute([$callId]);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    // 5. Добавление участника в чат
    if ($action === 'add_member') {
        $channelId = (int)($_POST['channel_id'] ?? 0);
        $targetUserId = (int)($_POST['target_user_id'] ?? 0);

        if ($channelId <= 0 || $targetUserId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Неверные параметры']);
            exit;
        }

        $check = $pdo->prepare("SELECT 1 FROM team_chat_members WHERE channel_id = ? AND user_id = ?");
        $check->execute([$channelId, $targetUserId]);
        if (!$check->fetchColumn()) {
            $pdo->prepare("INSERT INTO team_chat_members (channel_id, user_id, added_by) VALUES (?, ?, ?)")
                ->execute([$channelId, $targetUserId, $userId]);
        }

        // Узнаем имя добавленного
        $uStmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
        $uStmt->execute([$targetUserId]);
        $targetU = $uStmt->fetch();
        $targetName = trim(($targetU['first_name'] ?? '') . ' ' . ($targetU['last_name'] ?? '')) ?: 'Сотрудник';

        $pdo->prepare("INSERT INTO team_chat_messages (channel_id, sender_id, message) VALUES (?, ?, ?)")
            ->execute([$channelId, $userId, "➕ {$userName} добавил(а) {$targetName} в этот чат."]);

        echo json_encode(['success' => true, 'message' => "Пользователь {$targetName} успешно добавлен в чат"]);
        exit;
    }

    // 6. Получение списка участников и кандидатов на добавление
    if ($action === 'get_members') {
        $channelId = (int)($_GET['channel_id'] ?? 0);

        // Все пользователи системы
        $allUsers = $pdo->query("SELECT id, first_name, last_name, email, role_id FROM users WHERE status = 'active' ORDER BY first_name ASC")->fetchAll();

        // Текущие добавленные участники
        $memStmt = $pdo->prepare("
            SELECT m.user_id, u.first_name, u.last_name, u.email, r.name AS role_name
            FROM team_chat_members m
            INNER JOIN users u ON m.user_id = u.id
            INNER JOIN roles r ON u.role_id = r.id
            WHERE m.channel_id = ?
        ");
        $memStmt->execute([$channelId]);
        $members = $memStmt->fetchAll();

        echo json_encode([
            'success'   => true,
            'members'   => $members,
            'all_users' => $allUsers
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Неизвестное действие']);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Ошибка сервера: ' . $e->getMessage()]);
}
