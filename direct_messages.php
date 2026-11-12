<?php
declare(strict_types=1);
ob_start();

/**
 * AI Business Assistant - Direct Messages (Личные сообщения)
 * Мессенджер личных сообщений с поддержкой голосовых DM-звонков через Agora.
 */

require_once __DIR__ . '/includes/Auth.php';
require_once __DIR__ . '/includes/AgoraService.php';

$user   = Auth::requireAuth('/login.php');
$userId = (int)$user['id'];
$pdo    = Database::getConnection();

// ─── Миграция: создаём таблицы если не существуют ────────────────────────────
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS direct_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sender_id INT NOT NULL,
            receiver_id INT NOT NULL,
            message TEXT NOT NULL,
            reply_to_id INT NULL DEFAULT NULL,
            forwarded_from VARCHAR(150) NULL DEFAULT NULL,
            deleted_for_all TINYINT(1) NOT NULL DEFAULT 0,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_conversation (sender_id, receiver_id),
            INDEX idx_receiver (receiver_id, is_read)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    $dmCols = $pdo->query("SHOW COLUMNS FROM direct_messages")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('reply_to_id', $dmCols, true)) {
        $pdo->exec("ALTER TABLE direct_messages ADD COLUMN reply_to_id INT NULL DEFAULT NULL AFTER message");
    }
    if (!in_array('forwarded_from', $dmCols, true)) {
        $pdo->exec("ALTER TABLE direct_messages ADD COLUMN forwarded_from VARCHAR(150) NULL DEFAULT NULL AFTER reply_to_id");
    }
    if (!in_array('deleted_for_all', $dmCols, true)) {
        $pdo->exec("ALTER TABLE direct_messages ADD COLUMN deleted_for_all TINYINT(1) NOT NULL DEFAULT 0 AFTER forwarded_from");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS direct_messages_deleted (
            message_id INT NOT NULL,
            user_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (message_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
} catch (\Throwable $t) {}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS dm_calls (
            id INT AUTO_INCREMENT PRIMARY KEY,
            caller_id INT NOT NULL,
            receiver_id INT NOT NULL,
            agora_channel VARCHAR(100) NOT NULL,
            status ENUM('ringing','active','ended','missed') NOT NULL DEFAULT 'ringing',
            started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            ended_at DATETIME NULL,
            INDEX idx_receiver (receiver_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
} catch (\Throwable $t) {}

// ─── AJAX API ─────────────────────────────────────────────────────────────────
if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_GET['action'];

    // 1. get_conversations — поиск и список диалогов (по умолчанию только с кем есть переписка, по поиску — все сотрудники)
    if ($action === 'get_conversations') {
        $search = trim($_GET['search'] ?? '');
        $activeWithUser = (int)($_GET['selected_user'] ?? ($selectedUser['id'] ?? 0));
        try {
            $whereFilter = "";
            $params = [
                ':me1' => $userId, ':me2' => $userId, ':me3' => $userId,
                ':me4' => $userId, ':me5' => $userId, ':me6' => $userId,
                ':me7' => $userId, ':me8' => $userId,
            ];

            if ($search !== '') {
                $whereFilter = " AND (u.first_name LIKE :s1 OR u.last_name LIKE :s2 OR u.email LIKE :s3 OR CONCAT(u.first_name, ' ', u.last_name) LIKE :s4) ";
                $params[':s1'] = "%{$search}%";
                $params[':s2'] = "%{$search}%";
                $params[':s3'] = "%{$search}%";
                $params[':s4'] = "%{$search}%";
            } else {
                // По умолчанию показываем ТОЛЬКО тех, с кем есть активная переписка
                $whereFilter = " AND (
                    EXISTS (
                        SELECT 1 FROM direct_messages dm_has
                        WHERE ((dm_has.sender_id = u.id AND dm_has.receiver_id = :me_has1)
                            OR (dm_has.sender_id = :me_has2 AND dm_has.receiver_id = u.id))
                          AND dm_has.deleted_for_all = 0
                    ) " . ($activeWithUser > 0 ? " OR u.id = :act_user " : "") . "
                ) ";
                $params[':me_has1'] = $userId;
                $params[':me_has2'] = $userId;
                if ($activeWithUser > 0) {
                    $params[':act_user'] = $activeWithUser;
                }
            }

            $stmt = $pdo->prepare("
                SELECT
                    u.id,
                    CONCAT(u.first_name, ' ', u.last_name) AS name,
                    (
                        SELECT dm2.message
                        FROM direct_messages dm2
                        WHERE ((dm2.sender_id = u.id AND dm2.receiver_id = :me1)
                           OR (dm2.sender_id = :me2 AND dm2.receiver_id = u.id))
                          AND dm2.deleted_for_all = 0
                        ORDER BY dm2.id DESC LIMIT 1
                    ) AS last_msg,
                    (
                        SELECT DATE_FORMAT(dm3.created_at, '%H:%i')
                        FROM direct_messages dm3
                        WHERE ((dm3.sender_id = u.id AND dm3.receiver_id = :me3)
                           OR (dm3.sender_id = :me4 AND dm3.receiver_id = u.id))
                          AND dm3.deleted_for_all = 0
                        ORDER BY dm3.id DESC LIMIT 1
                    ) AS last_time,
                    (
                        SELECT COUNT(*)
                        FROM direct_messages dm4
                        WHERE dm4.sender_id = u.id
                          AND dm4.receiver_id = :me5
                          AND dm4.is_read = 0
                          AND dm4.deleted_for_all = 0
                    ) AS unread
                FROM users u
                WHERE u.id != :me6
                  AND u.status = 'active'
                  {$whereFilter}
                ORDER BY (
                    SELECT MAX(dm5.id)
                    FROM direct_messages dm5
                    WHERE (dm5.sender_id = u.id AND dm5.receiver_id = :me7)
                       OR (dm5.sender_id = :me8 AND dm5.receiver_id = u.id)
                ) DESC, u.first_name ASC
                LIMIT 50
            ");
            $stmt->execute($params);
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'users' => $users]);
        } catch (\Throwable $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // 2. get_messages — получение сообщений диалога
    if ($action === 'get_messages') {
        $withUser = (int)($_GET['user_id'] ?? 0);
        $lastId   = (int)($_GET['last_id'] ?? 0);

        if ($withUser <= 0) {
            echo json_encode(['success' => false, 'error' => 'user_id required']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("
                SELECT 
                    dm.id, dm.sender_id, dm.receiver_id, dm.message, dm.reply_to_id, dm.forwarded_from,
                    dm.audio_url, dm.audio_duration, dm.is_read, dm.created_at,
                    CONCAT(u.first_name, ' ', u.last_name) AS sender_name,
                    orig.message AS reply_text,
                    CONCAT(ou.first_name, ' ', ou.last_name) AS reply_author
                FROM direct_messages dm
                JOIN users u ON dm.sender_id = u.id
                LEFT JOIN direct_messages orig ON dm.reply_to_id = orig.id
                LEFT JOIN users ou ON orig.sender_id = ou.id
                LEFT JOIN direct_messages_deleted del_me ON del_me.message_id = dm.id AND del_me.user_id = :del_me_user
                WHERE dm.id > :last_id
                  AND dm.deleted_for_all = 0
                  AND del_me.message_id IS NULL
                  AND (
                      (dm.sender_id = :me1 AND dm.receiver_id = :them1)
                   OR (dm.sender_id = :them2 AND dm.receiver_id = :me2)
                  )
                ORDER BY dm.id ASC
                LIMIT 200
            ");
            $stmt->execute([
                ':del_me_user' => $userId,
                ':last_id'     => $lastId,
                ':me1'         => $userId, ':them1' => $withUser,
                ':them2'       => $withUser, ':me2'  => $userId,
            ]);
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Автоматически помечаем прочитанными
            if ($lastId === 0) {
                $pdo->prepare("
                    UPDATE direct_messages SET is_read = 1
                    WHERE sender_id = ? AND receiver_id = ? AND is_read = 0
                ")->execute([$withUser, $userId]);
            }

            echo json_encode(['success' => true, 'messages' => $messages]);
        } catch (\Throwable $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // 3. send_message — отправить личное сообщение
    if ($action === 'send_message' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if ($token !== '' && !Security::verifyCsrf($token)) {
            echo json_encode(['success' => false, 'error' => 'CSRF токен истёк']);
            exit;
        }

        $receiverId = (int)($_POST['receiver_id'] ?? 0);
        $message    = trim($_POST['message'] ?? '');
        $replyToId  = !empty($_POST['reply_to_id']) ? (int)$_POST['reply_to_id'] : null;
        $fwdFrom    = !empty($_POST['forwarded_from']) ? trim($_POST['forwarded_from']) : null;

        if ($receiverId <= 0 || $message === '') {
            echo json_encode(['success' => false, 'error' => 'Заполните все поля']);
            exit;
        }

        try {
            $ins = $pdo->prepare("
                INSERT INTO direct_messages (sender_id, receiver_id, message, reply_to_id, forwarded_from)
                VALUES (?, ?, ?, ?, ?)
            ");
            $ins->execute([$userId, $receiverId, $message, $replyToId, $fwdFrom]);
            $newId = (int)$pdo->lastInsertId();
            echo json_encode(['success' => true, 'id' => $newId, 'message_id' => $newId]);
        } catch (\Throwable $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // 3.1 delete_message — удалить сообщение (для себя или для всех)
    if ($action === 'delete_message' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if ($token !== '' && !Security::verifyCsrf($token)) {
            echo json_encode(['success' => false, 'error' => 'CSRF истёк']);
            exit;
        }

        $msgId = (int)($_POST['message_id'] ?? 0);
        $mode  = trim($_POST['mode'] ?? 'for_me');

        if ($msgId <= 0) {
            echo json_encode(['success' => false, 'error' => 'message_id required']);
            exit;
        }

        try {
            $mStmt = $pdo->prepare("SELECT sender_id, receiver_id FROM direct_messages WHERE id = ? LIMIT 1");
            $mStmt->execute([$msgId]);
            $msg = $mStmt->fetch(PDO::FETCH_ASSOC);

            if (!$msg) {
                echo json_encode(['success' => false, 'error' => 'Сообщение не найдено']);
                exit;
            }

            if ($mode === 'for_all') {
                if ((int)$msg['sender_id'] === $userId || in_array($userRole, ['admin', 'vrio_owner'], true)) {
                    $pdo->prepare("UPDATE direct_messages SET deleted_for_all = 1 WHERE id = ?")->execute([$msgId]);
                    echo json_encode(['success' => true]);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Удалить у всех может только автор']);
                }
            } else {
                $pdo->prepare("INSERT IGNORE INTO direct_messages_deleted (message_id, user_id) VALUES (?, ?)")
                    ->execute([$msgId, $userId]);
                echo json_encode(['success' => true]);
            }
        } catch (\Throwable $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // 3.2 check_dm_call_status — проверка статуса звонка для вызывающей стороны
    if ($action === 'check_dm_call_status') {
        $callId = (int)($_GET['call_id'] ?? 0);
        if ($callId <= 0) {
            echo json_encode(['success' => false, 'error' => 'call_id required']);
            exit;
        }
        try {
            $st = $pdo->prepare("SELECT status FROM dm_calls WHERE id = ? AND (caller_id = ? OR receiver_id = ?) LIMIT 1");
            $st->execute([$callId, $userId, $userId]);
            $callStatus = $st->fetchColumn();
            echo json_encode(['success' => true, 'status' => $callStatus ?: 'ended']);
        } catch (\Throwable $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // 4. mark_read — пометить входящие от user_id как прочитанные
    if ($action === 'mark_read' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $fromUser = (int)($_POST['user_id'] ?? 0);
        if ($fromUser > 0) {
            try {
                $pdo->prepare("
                    UPDATE direct_messages SET is_read = 1
                    WHERE sender_id = ? AND receiver_id = ? AND is_read = 0
                ")->execute([$fromUser, $userId]);
                echo json_encode(['success' => true]);
            } catch (\Throwable $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'user_id required']);
        }
        exit;
    }

    // 5. start_dm_call — инициировать голосовой DM-звонок
    if ($action === 'start_dm_call' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if ($token !== '' && !Security::verifyCsrf($token)) {
            echo json_encode(['success' => false, 'error' => 'CSRF токен истёк']);
            exit;
        }

        $receiverId = (int)($_POST['receiver_id'] ?? 0);
        if ($receiverId <= 0) {
            echo json_encode(['success' => false, 'error' => 'receiver_id required']);
            exit;
        }

        $minId    = min($userId, $receiverId);
        $maxId    = max($userId, $receiverId);
        $channel  = "dm_{$minId}_{$maxId}";

        try {
            // Завершаем предыдущие активные/звонящие вызовы между этими двумя
            $pdo->prepare("
                UPDATE dm_calls SET status = 'ended', ended_at = NOW()
                WHERE status IN ('ringing','active')
                  AND ((caller_id = ? AND receiver_id = ?) OR (caller_id = ? AND receiver_id = ?))
            ")->execute([$userId, $receiverId, $receiverId, $userId]);

            $ins = $pdo->prepare("
                INSERT INTO dm_calls (caller_id, receiver_id, agora_channel, status)
                VALUES (?, ?, ?, 'ringing')
            ");
            $ins->execute([$userId, $receiverId, $channel]);
            $callId = (int)$pdo->lastInsertId();

            $agoraToken = AgoraService::createRtcToken($channel, (string)$userId);

            echo json_encode([
                'success'   => true,
                'call_id'   => $callId,
                'channel'   => $channel,
                'token'     => $agoraToken,
                'app_id'    => AgoraService::APP_ID,
            ]);
        } catch (\Throwable $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // 6. check_dm_incoming — проверить входящие звонки
    if ($action === 'check_dm_incoming') {
        try {
            $stmt = $pdo->prepare("
                SELECT dc.id, dc.caller_id, dc.agora_channel,
                       CONCAT(u.first_name, ' ', u.last_name) AS caller_name
                FROM dm_calls dc
                JOIN users u ON dc.caller_id = u.id
                WHERE dc.receiver_id = ?
                  AND dc.status = 'ringing'
                  AND dc.started_at >= NOW() - INTERVAL 45 SECOND
                ORDER BY dc.id DESC
                LIMIT 1
            ");
            $stmt->execute([$userId]);
            $call = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($call) {
                echo json_encode(['success' => true, 'call' => $call]);
            } else {
                echo json_encode(['success' => true, 'call' => null]);
            }
        } catch (\Throwable $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // 7. answer_dm_call — принять звонок
    if ($action === 'answer_dm_call' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $callId = (int)($_POST['call_id'] ?? 0);
        if ($callId <= 0) {
            echo json_encode(['success' => false, 'error' => 'call_id required']);
            exit;
        }

        try {
            $callStmt = $pdo->prepare("
                SELECT * FROM dm_calls WHERE id = ? AND receiver_id = ? AND status = 'ringing' LIMIT 1
            ");
            $callStmt->execute([$callId, $userId]);
            $call = $callStmt->fetch(PDO::FETCH_ASSOC);

            if (!$call) {
                echo json_encode(['success' => false, 'error' => 'Звонок не найден или уже завершён']);
                exit;
            }

            $pdo->prepare("UPDATE dm_calls SET status = 'active' WHERE id = ?")->execute([$callId]);

            $agoraToken = AgoraService::createRtcToken($call['agora_channel'], (string)$userId);

            echo json_encode([
                'success' => true,
                'channel' => $call['agora_channel'],
                'token'   => $agoraToken,
                'app_id'  => AgoraService::APP_ID,
            ]);
        } catch (\Throwable $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // 8. end_dm_call — завершить звонок
    if ($action === 'end_dm_call' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $callId = (int)($_POST['call_id'] ?? 0);
        if ($callId > 0) {
            try {
                $pdo->prepare("
                    UPDATE dm_calls SET status = 'ended', ended_at = NOW()
                    WHERE id = ? AND (caller_id = ? OR receiver_id = ?)
                ")->execute([$callId, $userId, $userId]);
                echo json_encode(['success' => true]);
            } catch (\Throwable $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
        } else {
            // Завершить любой активный звонок текущего пользователя
            try {
                $pdo->prepare("
                    UPDATE dm_calls SET status = 'ended', ended_at = NOW()
                    WHERE (caller_id = ? OR receiver_id = ?) AND status IN ('ringing','active')
                ")->execute([$userId, $userId]);
                echo json_encode(['success' => true]);
            } catch (\Throwable $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
        }
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Unknown action']);
    exit;
}

// ─── Подготовка страницы ──────────────────────────────────────────────────────
$selectedUserId = (int)($_GET['user'] ?? $_GET['call'] ?? 0);
$selectedUser   = null;

if ($selectedUserId > 0) {
    $suStmt = $pdo->prepare("
        SELECT id, first_name, last_name, email, status
        FROM users WHERE id = ? AND status = 'active' LIMIT 1
    ");
    $suStmt->execute([$selectedUserId]);
    $selectedUser = $suStmt->fetch(PDO::FETCH_ASSOC);
}

$csrfToken = Security::csrfToken();
$myName    = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: 'Я';
$pageTitle = ($selectedUser
    ? 'Личные сообщения — ' . htmlspecialchars($selectedUser['first_name'] . ' ' . $selectedUser['last_name'])
    : 'Личные сообщения') . ' | AI Business Assistant';

require __DIR__ . '/includes/header.php';
?>

<style>
/* ── DM Page Layout ─────────────────────────────────────────────────── */
#dm-layout {
    display: grid;
    grid-template-columns: 300px 1fr;
    gap: 16px;
    height: calc(100vh - 190px);
    min-height: 560px;
}

/* ── Left Sidebar ────────────────────────────────────────────────────── */
#dm-sidebar {
    display: flex;
    flex-direction: column;
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    overflow: hidden;
}

#dm-search {
    padding: 12px;
    border-bottom: 1px solid var(--border-color);
    background: rgba(0,0,0,0.12);
}

#dm-search input {
    width: 100%;
    background: var(--bg-input);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    padding: 8px 12px;
    color: var(--text-primary);
    font-size: 13px;
    outline: none;
    box-sizing: border-box;
}

#dm-search input:focus {
    border-color: var(--primary);
}

#dm-contact-list {
    flex: 1;
    overflow-y: auto;
    padding: 6px;
}

.dm-contact-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    border-radius: 10px;
    cursor: pointer;
    text-decoration: none;
    transition: background 0.15s;
    border: 1px solid transparent;
    margin-bottom: 2px;
}

.dm-contact-item:hover {
    background: rgba(255,255,255,0.05);
}

.dm-contact-item.active {
    background: rgba(99,102,241,0.15);
    border-color: rgba(99,102,241,0.4);
}

.dm-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 15px;
    color: #fff;
    flex-shrink: 0;
}

.dm-contact-info {
    flex: 1;
    min-width: 0;
}

.dm-contact-name {
    font-weight: 600;
    font-size: 13px;
    color: var(--text-primary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.dm-contact-preview {
    font-size: 11px;
    color: var(--text-muted);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    margin-top: 2px;
}

.dm-contact-meta {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 4px;
    flex-shrink: 0;
}

.dm-contact-time {
    font-size: 10px;
    color: var(--text-muted);
}

.dm-unread-badge {
    background: var(--primary);
    color: #fff;
    border-radius: 10px;
    font-size: 10px;
    font-weight: 700;
    padding: 1px 6px;
    min-width: 18px;
    text-align: center;
}

/* ── Right: Conversation Panel ────────────────────────────────────────── */
#dm-main {
    display: flex;
    flex-direction: column;
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    overflow: hidden;
}

#dm-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 20px;
    border-bottom: 1px solid var(--border-color);
    background: rgba(0,0,0,0.12);
    flex-shrink: 0;
}

#dm-header-info {
    display: flex;
    align-items: center;
    gap: 12px;
}

#dm-messages-box {
    flex: 1;
    overflow-y: auto;
    padding: 20px;
    display: flex;
    flex-direction: column;
    gap: 12px;
}

#dm-messages-box::-webkit-scrollbar { width: 4px; }
#dm-messages-box::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.15); border-radius: 4px; }

.dm-msg {
    display: flex;
    flex-direction: column;
    max-width: 70%;
}

.dm-msg.outgoing {
    align-self: flex-end;
    align-items: flex-end;
}

.dm-msg.incoming {
    align-self: flex-start;
    align-items: flex-start;
}

.dm-msg-bubble {
    padding: 10px 14px;
    border-radius: 16px;
    font-size: 14px;
    line-height: 1.5;
    word-break: break-word;
    position: relative;
}

.dm-msg.outgoing .dm-msg-bubble {
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    color: #fff;
    border-bottom-right-radius: 4px;
}

.dm-msg.incoming .dm-msg-bubble {
    background: var(--bg-input);
    border: 1px solid var(--border-color);
    color: var(--text-primary);
    border-bottom-left-radius: 4px;
}

.dm-msg-time {
    font-size: 10px;
    color: var(--text-muted);
    margin-top: 4px;
    padding: 0 4px;
}

/* ── Input Bar ────────────────────────────────────────────────────────── */
#dm-input-bar {
    padding: 12px 16px;
    border-top: 1px solid var(--border-color);
    background: rgba(0,0,0,0.1);
    display: flex;
    align-items: flex-end;
    gap: 10px;
    flex-shrink: 0;
}

#dm-message-input {
    flex: 1;
    background: var(--bg-input);
    border: 1px solid var(--border-color);
    border-radius: 10px;
    padding: 10px 14px;
    color: var(--text-primary);
    font-size: 14px;
    resize: none;
    max-height: 120px;
    outline: none;
    line-height: 1.4;
    transition: border-color 0.15s;
}

#dm-message-input:focus {
    border-color: var(--primary);
}

#dm-send-btn {
    background: var(--primary);
    border: none;
    border-radius: 10px;
    width: 44px;
    height: 44px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    color: #fff;
    font-size: 18px;
    transition: opacity 0.15s;
    flex-shrink: 0;
}

#dm-send-btn:hover { opacity: 0.85; }

/* ── Placeholder ─────────────────────────────────────────────────────── */
#dm-placeholder {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    color: var(--text-muted);
    text-align: center;
    padding: 40px;
}

/* ── DM Call Overlays ─────────────────────────────────────────────────── */
#dm-calling-overlay,
#dm-incoming-overlay,
#dm-active-call-panel {
    position: fixed;
    z-index: 9999;
    border-radius: 16px;
    box-shadow: 0 20px 60px rgba(0,0,0,0.6);
    padding: 24px 28px;
    min-width: 280px;
    text-align: center;
    display: none;
}

#dm-calling-overlay {
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    background: linear-gradient(135deg, #1e1b4b, #312e81);
    border: 1px solid rgba(99,102,241,0.5);
    color: #fff;
}

#dm-incoming-overlay {
    bottom: 100px;
    right: 30px;
    background: linear-gradient(135deg, #064e3b, #065f46);
    border: 1px solid rgba(16,185,129,0.5);
    color: #fff;
    animation: slideInRight 0.3s ease;
}

#dm-active-call-panel {
    bottom: 20px;
    right: 30px;
    background: linear-gradient(135deg, #1e3a5f, #1e40af);
    border: 1px solid rgba(59,130,246,0.5);
    color: #fff;
    min-width: 240px;
}

@keyframes slideInRight {
    from { transform: translateX(100px); opacity: 0; }
    to   { transform: translateX(0);    opacity: 1; }
}

.dm-call-dots span {
    display: inline-block;
    width: 6px; height: 6px;
    border-radius: 50%;
    background: #a5b4fc;
    margin: 0 2px;
    animation: dmDotPulse 1.4s ease-in-out infinite;
}

.dm-call-dots span:nth-child(2) { animation-delay: 0.2s; }
.dm-call-dots span:nth-child(3) { animation-delay: 0.4s; }

@keyframes dmDotPulse {
    0%, 80%, 100% { transform: scale(0.6); opacity: 0.5; }
    40%           { transform: scale(1.0); opacity: 1.0; }
}

.dm-call-ring-icon {
    font-size: 48px;
    margin-bottom: 12px;
    animation: dmRingPulse 1s ease-in-out infinite;
}

@keyframes dmRingPulse {
    0%, 100% { transform: scale(1.0); }
    50%      { transform: scale(1.1); }
}
</style>

<!-- Page Header -->
<div style="margin-bottom:20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
    <div>
        <h1 style="margin:0; font-size:24px; font-weight:700;">
            <i class="fas fa-envelope" style="color:var(--primary); margin-right:8px;"></i>
            Личные сообщения
        </h1>
        <p style="margin:4px 0 0; color:var(--text-muted); font-size:14px;">
            Приватная переписка • Голосовые звонки • Уведомления о непрочитанных
        </p>
    </div>
    <div style="display:flex; gap:10px;">
        <a href="/chat.php" class="btn btn-secondary btn-sm">
            <i class="fas fa-comments"></i> Корпоративные чаты
        </a>
    </div>
</div>

<!-- Main 2-column layout -->
<div id="dm-layout">

    <!-- ── LEFT SIDEBAR: Contact list ─────────────────────────────────── -->
    <div id="dm-sidebar">
        <div id="dm-search">
            <input type="text" id="dm-search-input" placeholder="🔍 Поиск по имени...">
        </div>

        <div id="dm-contact-list">
            <div id="dm-contacts-placeholder" style="padding:24px; text-align:center; color:var(--text-muted); font-size:13px;">
                <i class="fas fa-spinner fa-spin" style="margin-bottom:8px; display:block; font-size:24px;"></i>
                Загрузка контактов...
            </div>
        </div>
    </div>

    <!-- ── RIGHT PANEL: Active conversation ──────────────────────────── -->
    <div id="dm-main">

        <?php if (!$selectedUser): ?>
        <!-- Placeholder: no conversation selected -->
        <div id="dm-placeholder">
            <i class="fas fa-paper-plane" style="font-size:56px; opacity:0.2; margin-bottom:20px;"></i>
            <h3 style="margin:0 0 8px; color:var(--text-primary);">Выберите собеседника</h3>
            <p style="font-size:13px; max-width:240px;">Выберите пользователя из списка слева, чтобы начать переписку</p>
        </div>

        <?php else: ?>
        <!-- Chat header -->
        <div id="dm-header">
            <div id="dm-header-info">
                <div class="dm-avatar" style="width:44px; height:44px; font-size:17px;">
                    <?= mb_strtoupper(mb_substr($selectedUser['first_name'], 0, 1) . mb_substr($selectedUser['last_name'], 0, 1)) ?>
                </div>
                <div>
                    <div style="font-weight:700; font-size:16px; color:var(--text-primary);">
                        <?= htmlspecialchars($selectedUser['first_name'] . ' ' . $selectedUser['last_name']) ?>
                    </div>
                    <div style="font-size:12px; color:var(--text-muted);">Личные сообщения</div>
                </div>
            </div>
            <div>
                <button class="btn btn-success btn-sm"
                    onclick="window.startDirectCall(<?= (int)$selectedUser['id'] ?>, '<?= htmlspecialchars($selectedUser['first_name'] . ' ' . $selectedUser['last_name'], ENT_QUOTES) ?>')"
                    style="background:linear-gradient(135deg, #10b981, #059669); border:none; font-weight:700; display:inline-flex; align-items:center; gap:8px; padding:8px 16px; border-radius:10px; box-shadow:0 4px 12px rgba(16,185,129,0.3);">
                    <i class="fas fa-phone-alt"></i> Позвонить
                </button>
            </div>
        </div>

        <!-- Messages feed -->
        <div id="dm-messages-box">
            <!-- Messages injected by JS -->
        </div>

        <!-- Полоса цитирования / ответа / пересылки -->
        <div id="dm-reply-bar" style="display:none; padding:8px 16px; background:rgba(99,102,241,0.12); border-top:1px solid rgba(99,102,241,0.25); display:flex; justify-content:space-between; align-items:center;">
            <div style="display:flex; align-items:center; gap:8px; overflow:hidden;">
                <i id="dm-reply-icon" class="fas fa-reply" style="color:var(--primary); font-size:13px;"></i>
                <div style="font-size:12px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                    <span id="dm-reply-label" style="font-weight:700; color:var(--primary);">Ответ на:</span>
                    <span id="dm-reply-text" style="color:var(--text-muted); margin-left:4px;"></span>
                </div>
            </div>
            <button type="button" onclick="dmCancelReply()" style="background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:14px; padding:4px;">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <!-- Input bar with Text and Voice Message recording -->
        <div id="dm-input-bar">
            <!-- Текстовый ввод -->
            <div id="dm-text-input-wrap" style="flex:1; display:flex; align-items:flex-end; gap:8px;">
                <textarea id="dm-message-input" rows="1"
                    placeholder="Написать сообщение..."
                    onkeydown="dmHandleKeydown(event)"></textarea>
                
                <!-- Кнопка голосового сообщения (микрофон) -->
                <button type="button" id="dm-voice-btn" onclick="startVoiceRecord()" title="Записать голосовое сообщение"
                    style="background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.15); border-radius:10px; width:44px; height:44px; display:flex; align-items:center; justify-content:center; cursor:pointer; color:#f1f5f9; font-size:18px; transition:all 0.15s; flex-shrink:0;">
                    <i class="fas fa-microphone"></i>
                </button>

                <button id="dm-send-btn" onclick="dmSendMessage()" title="Отправить (Enter)">
                    <i class="fas fa-paper-plane"></i>
                </button>
            </div>

            <!-- Режим записи голосового сообщения -->
            <div id="dm-voice-recording-wrap" style="display:none; flex:1; align-items:center; justify-content:space-between; background:rgba(239,68,68,0.12); border:1px solid rgba(239,68,68,0.3); border-radius:12px; padding:6px 14px;">
                <div style="display:flex; align-items:center; gap:10px;">
                    <span style="width:12px; height:12px; border-radius:50%; background:#ef4444; box-shadow:0 0 10px #ef4444; animation:dmDotPulse 1s infinite;"></span>
                    <span style="font-size:13px; font-weight:700; color:#ef4444;">Идёт запись голоса:</span>
                    <span id="dm-voice-timer" style="font-size:13px; font-weight:700; color:#fff; font-family:monospace;">00:00</span>
                </div>
                <div style="display:flex; gap:8px;">
                    <button type="button" onclick="cancelVoiceRecord()" class="btn btn-secondary btn-sm" style="font-size:12px; padding:4px 10px; border-radius:8px;">
                        <i class="fas fa-times"></i> Отмена
                    </button>
                    <button type="button" onclick="sendVoiceRecord()" class="btn btn-success btn-sm" style="font-size:12px; padding:4px 14px; border-radius:8px; background:#22c55e; border:none; font-weight:700;">
                        <i class="fas fa-paper-plane"></i> Отправить
                    </button>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<!-- ── DM Calling Overlay (shown to the caller) ───────────────────────── -->
<div id="dm-calling-overlay">
    <div class="dm-call-ring-icon">📞</div>
    <div style="font-size:14px; color:#c7d2fe; margin-bottom:6px;">Звонок</div>
    <div id="dm-calling-name" style="font-size:20px; font-weight:700; margin-bottom:16px;"></div>
    <div class="dm-call-dots" style="margin-bottom:20px;">
        <span></span><span></span><span></span>
    </div>
    <button onclick="window.endDmCall()" class="btn btn-danger btn-sm" style="font-weight:600; padding:8px 24px; border-radius:20px;">
        <i class="fas fa-phone-slash"></i> Завершить
    </button>
</div>

<!-- ── Incoming Call Overlay (shown to the callee) ────────────────────── -->
<div id="dm-incoming-overlay">
    <div class="dm-call-ring-icon">📲</div>
    <div style="font-size:14px; color:#a7f3d0; margin-bottom:6px;">Входящий звонок</div>
    <div id="dm-incoming-name" style="font-size:18px; font-weight:700; margin-bottom:18px;"></div>
    <div style="display:flex; gap:12px; justify-content:center;">
        <button id="dm-accept-btn" class="btn btn-success btn-sm" style="font-weight:600; padding:8px 20px; border-radius:20px;">
            <i class="fas fa-phone"></i> Принять
        </button>
        <button onclick="dmDeclineCall()" class="btn btn-danger btn-sm" style="font-weight:600; padding:8px 20px; border-radius:20px;">
            <i class="fas fa-phone-slash"></i> Отклонить
        </button>
    </div>
</div>

<!-- ── Active Call Panel ───────────────────────────────────────────────── -->
<div id="dm-active-call-panel">
    <div style="font-size:24px; margin-bottom:8px;">🎙️</div>
    <div style="font-size:13px; color:#bfdbfe; margin-bottom:4px;">Разговор с</div>
    <div id="dm-active-call-name" style="font-size:16px; font-weight:700; margin-bottom:6px;"></div>
    <div id="dm-call-duration" style="font-size:13px; color:#93c5fd; margin-bottom:16px;">00:00</div>
    <button onclick="window.endDmCall()" class="btn btn-danger btn-sm w-100" style="font-weight:600; border-radius:20px;">
        <i class="fas fa-phone-slash"></i> Завершить звонок
    </button>
</div>

<script>
// ════════════════════════════════════════════════════════════════════════════
//  DM Page — JavaScript Logic
// ════════════════════════════════════════════════════════════════════════════

window.DM_CSRF        = <?= json_encode($csrfToken) ?>;
window.DM_MY_ID       = <?= $userId ?>;
window.DM_MY_NAME     = <?= json_encode($myName) ?>;
window.DM_SELECTED_ID = <?= $selectedUserId ?>;
const DM_CSRF         = window.DM_CSRF;
const DM_MY_ID        = window.DM_MY_ID;
const DM_MY_NAME      = window.DM_MY_NAME;
const DM_SELECTED_ID  = window.DM_SELECTED_ID;

let dmLastMsgId      = 0;
let dmPollTimer      = null;
let dmContacts       = [];
let dmSearchQuery    = '';

let dmReplyToId      = null;
let dmReplyAuthor    = '';
let dmReplyPreview   = '';
let dmForwardText    = '';
let dmForwardFrom    = '';

function dmSetReply(msgId, authorName, previewText) {
    dmReplyToId = msgId;
    dmReplyAuthor = authorName;
    dmReplyPreview = previewText;
    dmForwardText = '';
    dmForwardFrom = '';

    const bar = document.getElementById('dm-reply-bar');
    if (bar) {
        document.getElementById('dm-reply-icon').className = 'fas fa-reply';
        document.getElementById('dm-reply-label').textContent = 'Ответ пользователю ' + authorName + ':';
        document.getElementById('dm-reply-text').textContent = previewText.substring(0, 60);
        bar.style.display = 'flex';
    }
    document.getElementById('dm-message-input')?.focus();
}

function dmSetForward(authorName, text) {
    dmForwardText = text;
    dmForwardFrom = authorName;
    dmReplyToId = null;

    const bar = document.getElementById('dm-reply-bar');
    if (bar) {
        document.getElementById('dm-reply-icon').className = 'fas fa-share';
        document.getElementById('dm-reply-label').textContent = 'Пересылка от ' + authorName + ':';
        document.getElementById('dm-reply-text').textContent = text.substring(0, 60);
        bar.style.display = 'flex';
    }
    const input = document.getElementById('dm-message-input');
    if (input && !input.value) {
        input.value = text;
    }
    input?.focus();
}

function dmCancelReply() {
    dmReplyToId = null;
    dmForwardText = '';
    dmForwardFrom = '';
    const bar = document.getElementById('dm-reply-bar');
    if (bar) bar.style.display = 'none';
}

async function dmDeleteMessage(msgId, isMyMsg) {
    let mode = 'for_me';
    if (isMyMsg) {
        const choice = confirm("Нажмите OK, чтобы удалить для ВСЕХ, или Отмена — удалить только для СЕБЯ.");
        mode = choice ? 'for_all' : 'for_me';
    } else {
        if (!confirm("Удалить это сообщение для себя?")) return;
        mode = 'for_me';
    }

    try {
        const fd = new FormData();
        fd.append('message_id', msgId);
        fd.append('mode', mode);
        fd.append('csrf_token', DM_CSRF);
        const r = await fetch('direct_messages.php?action=delete_message', { method: 'POST', body: fd });
        const data = await r.json();
        if (data.success) {
            const el = document.getElementById('dm-msg-' + msgId);
            if (el) {
                el.style.opacity = '0';
                el.style.transform = 'scale(0.95)';
                setTimeout(() => {
                    el.remove();
                    const box = document.getElementById('dm-messages-box');
                    if (box && box.querySelectorAll('.dm-msg').length === 0 && !document.getElementById('dm-empty-placeholder')) {
                        box.innerHTML = `<div id="dm-empty-placeholder" style="flex:1; display:flex; flex-direction:column; align-items:center; justify-content:center; color:var(--text-muted); text-align:center; padding:40px;">
                            <i class="fas fa-comments" style="font-size:48px; opacity:0.2; margin-bottom:16px;"></i>
                            <p style="font-size:13px;">Начните переписку — отправьте первое сообщение или голосовую заметку!</p>
                        </div>`;
                    }
                }, 250);
            }
        } else {
            alert(data.error || 'Ошибка удаления');
        }
    } catch(e) {
        alert('Ошибка удаления: ' + e.message);
    }
}

// ── Contact list ─────────────────────────────────────────────────────────────
async function dmLoadContacts() {
    try {
        const q = encodeURIComponent(dmSearchQuery.trim());
        const r = await fetch(`direct_messages.php?action=get_conversations&search=${q}&selected_user=${DM_SELECTED_ID || 0}`);
        const data = await r.json();
        if (!data.success) return;

        dmContacts = data.users || [];
        dmRenderContacts();
    } catch(e) {}
}

function dmRenderContacts() {
    const list = document.getElementById('dm-contact-list');
    if (!list) return;
    const q = dmSearchQuery.toLowerCase().trim();
    const users = dmContacts;

    if (users.length === 0) {
        list.innerHTML = `<div style="padding:24px; text-align:center; color:var(--text-muted); font-size:13px;">
            <i class="fas fa-${q ? 'search' : 'comments'}" style="font-size:28px; opacity:0.3; margin-bottom:8px; display:block;"></i>
            ${q ? 'Сотрудник не найден' : 'Нет активных переписок.<br><span style="font-size:11px; opacity:0.75;">Используйте поиск выше, чтобы найти сотрудника и начать диалог.</span>'}
        </div>`;
        return;
    }

    list.innerHTML = users.map(u => {
        const isActive = (u.id == DM_SELECTED_ID);
        const initials = (u.name || '??').split(' ').map(w => w[0] || '').join('').substring(0, 2).toUpperCase();
        const preview  = u.last_msg ? escHtml(u.last_msg).substring(0, 40) : '<span style="opacity:0.5">Нет сообщений</span>';
        const unreadBadge = u.unread > 0
            ? `<span class="dm-unread-badge">${u.unread}</span>` : '';
        const timeStr = u.last_time ? escHtml(u.last_time) : '';

        return `<a href="/direct_messages.php?user=${u.id}"
                   class="dm-contact-item${isActive ? ' active' : ''}"
                   title="${escHtml(u.name)}">
            <div class="dm-avatar">${escHtml(initials)}</div>
            <div class="dm-contact-info">
                <div class="dm-contact-name">${escHtml(u.name)}</div>
                <div class="dm-contact-preview">${preview}</div>
            </div>
            <div class="dm-contact-meta">
                <span class="dm-contact-time">${timeStr}</span>
                ${unreadBadge}
            </div>
        </a>`;
    }).join('');
}

// Search
let dmSearchDebounce = null;
document.getElementById('dm-search-input')?.addEventListener('input', function() {
    dmSearchQuery = this.value;
    clearTimeout(dmSearchDebounce);
    dmSearchDebounce = setTimeout(() => {
        dmLoadConversations();
    }, 200);
});

// ── Message feed ──────────────────────────────────────────────────────────────
function removeDmPlaceholder() {
    const pl = document.getElementById('dm-empty-placeholder');
    if (pl) pl.remove();
}

async function dmLoadMessages(isInitial = false) {
    if (!DM_SELECTED_ID) return;
    try {
        const url = `direct_messages.php?action=get_messages&user_id=${DM_SELECTED_ID}&last_id=${dmLastMsgId}`;
        const r   = await fetch(url);
        const data = await r.json();
        if (!data.success) return;

        const msgs = data.messages || [];
        if (msgs.length === 0 && !isInitial) return;

        const box = document.getElementById('dm-messages-box');
        if (!box) return;

        if (isInitial) {
            box.innerHTML = '';
            if (msgs.length === 0) {
                box.innerHTML = `<div id="dm-empty-placeholder" style="flex:1; display:flex; flex-direction:column; align-items:center; justify-content:center; color:var(--text-muted); text-align:center; padding:40px;">
                    <i class="fas fa-comments" style="font-size:48px; opacity:0.2; margin-bottom:16px;"></i>
                    <p style="font-size:13px;">Начните переписку — отправьте первое сообщение или голосовую заметку!</p>
                </div>`;
                return;
            }
        }

        if (msgs.length > 0) {
            removeDmPlaceholder();
        }

        msgs.forEach(m => {
            if (m.id > dmLastMsgId) dmLastMsgId = m.id;
            if (document.getElementById('dm-msg-' + m.id)) return;

            // Если это исходящее сообщение и в DOM еще есть временный optimistic div — удаляем его
            if (m.sender_id == DM_MY_ID) {
                const tempEl = box.querySelector(`.dm-temp-msg[data-text="${encodeURIComponent(m.message)}"]`) || box.querySelector('.dm-temp-msg');
                if (tempEl) {
                    tempEl.remove();
                }
            }

            const isOut = (m.sender_id == DM_MY_ID);
            const timeStr = formatDmTime(m.created_at);
            const div = document.createElement('div');
            div.className = `dm-msg ${isOut ? 'outgoing' : 'incoming'}`;
            div.id = `dm-msg-${m.id}`;
            
            let bubbleContent = '';

            // Отображение пересылки (Forwarded)
            if (m.forwarded_from) {
                bubbleContent += `
                    <div style="font-size:11px; opacity:0.8; margin-bottom:4px; display:flex; align-items:center; gap:4px; color:${isOut ? '#e0e7ff' : '#818cf8'};">
                        <i class="fas fa-share" style="font-size:10px;"></i> Переслано от ${escHtml(m.forwarded_from)}
                    </div>
                `;
            }

            // Отображение ответа (Reply)
            if (m.reply_text) {
                bubbleContent += `
                    <div style="background:rgba(0,0,0,0.15); border-left:3px solid ${isOut ? '#fff' : '#6366f1'}; padding:4px 8px; border-radius:4px; margin-bottom:6px; font-size:11px;">
                        <div style="font-weight:700; color:${isOut ? '#fff' : '#818cf8'};">${escHtml(m.reply_author || 'Пользователь')}</div>
                        <div style="opacity:0.85; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${escHtml(m.reply_text)}</div>
                    </div>
                `;
            }

            if (m.audio_url) {
                const durMin = Math.floor((m.audio_duration || 0) / 60).toString().padStart(2, '0');
                const durSec = ((m.audio_duration || 0) % 60).toString().padStart(2, '0');
                const durStr = `${durMin}:${durSec}`;
                bubbleContent += `
                    <div style="display:flex; align-items:center; gap:12px; min-width:210px; padding:2px 0;">
                        <button type="button" class="btn-play-voice" onclick="window.playVoiceNote(this, '${escHtml(m.audio_url)}')"
                            style="background:${isOut ? '#ffffff' : '#6366f1'}; color:${isOut ? '#4f46e5' : '#ffffff'}; border:none; border-radius:50%; width:36px; height:36px; display:flex; align-items:center; justify-content:center; cursor:pointer; flex-shrink:0; box-shadow:0 2px 6px rgba(0,0,0,0.25);">
                            <i class="fas fa-play" style="font-size:13px; margin-left:2px;"></i>
                        </button>
                        <div style="flex:1;">
                            <div style="font-size:12px; font-weight:700;">🎤 Голосовое сообщение</div>
                            <div style="font-size:11px; opacity:0.85; margin-top:2px;">${durStr}</div>
                        </div>
                    </div>
                `;
            } else {
                bubbleContent += escHtml(m.message);
            }

            // Кнопки действий: Ответить, Переслать, Удалить
            const authorForAction = isOut ? 'Вы' : (m.sender_name || 'Собеседник');
            const safePreview = escHtml(m.message || 'Голосовое сообщение').replace(/'/g, "\\'");
            const safeAuthor = escHtml(authorForAction).replace(/'/g, "\\'");

            const actionsHtml = `
                <div class="dm-msg-actions" style="margin-top:4px; display:flex; gap:10px; font-size:11px; opacity:0.75; justify-content:${isOut ? 'flex-end' : 'flex-start'};">
                    <span onclick="dmSetReply(${m.id}, '${safeAuthor}', '${safePreview}')" style="cursor:pointer;" title="Ответить">
                        <i class="fas fa-reply"></i> Ответить
                    </span>
                    <span onclick="dmSetForward('${safeAuthor}', '${safePreview}')" style="cursor:pointer;" title="Переслать">
                        <i class="fas fa-share"></i> Переслать
                    </span>
                    <span onclick="dmDeleteMessage(${m.id}, ${isOut ? 'true' : 'false'})" style="cursor:pointer; color:#f87171;" title="Удалить">
                        <i class="fas fa-trash-alt"></i>
                    </span>
                </div>
            `;

            div.innerHTML = `<div class="dm-msg-bubble">${bubbleContent}${actionsHtml}</div>
                             <div class="dm-msg-time">${timeStr}${isOut ? ' ✓' : ''}</div>`;
            box.appendChild(div);
        });

        box.scrollTop = box.scrollHeight;

        if (msgs.some(m => m.sender_id != DM_MY_ID)) {
            dmMarkRead();
        }
    } catch(e) {}
}

async function dmMarkRead() {
    if (!DM_SELECTED_ID) return;
    try {
        const fd = new FormData();
        fd.append('user_id', DM_SELECTED_ID);
        await fetch('direct_messages.php?action=mark_read', { method: 'POST', body: fd });
    } catch(e) {}
}

async function dmSendMessage() {
    if (!DM_SELECTED_ID) return;
    const input = document.getElementById('dm-message-input');
    const text  = (input?.value || '').trim();
    if (!text) return;

    // Мгновенная очистка поля ввода без задержки
    input.value = '';
    input.style.height = '';

    const currentReplyId = dmReplyToId;
    const currentReplyAuthor = dmReplyAuthor;
    const currentReplyPreview = dmReplyPreview;
    const currentFwdFrom = dmForwardFrom;
    dmCancelReply();

    const box = document.getElementById('dm-messages-box');
    const tempDiv = document.createElement('div');
    const tempId = 'dm-temp-' + Date.now();
    tempDiv.className = 'dm-msg outgoing dm-temp-msg';
    tempDiv.id = tempId;
    tempDiv.setAttribute('data-text', encodeURIComponent(text));
    tempDiv.style.opacity = '0.92';

    let optContent = '';
    if (currentFwdFrom) {
        optContent += `
            <div style="font-size:11px; opacity:0.8; margin-bottom:4px; display:flex; align-items:center; gap:4px; color:#e0e7ff;">
                <i class="fas fa-share" style="font-size:10px;"></i> Переслано от ${escHtml(currentFwdFrom)}
            </div>
        `;
    }
    if (currentReplyPreview) {
        optContent += `
            <div style="background:rgba(0,0,0,0.15); border-left:3px solid #fff; padding:4px 8px; border-radius:4px; margin-bottom:6px; font-size:11px;">
                <div style="font-weight:700; color:#fff;">${escHtml(currentReplyAuthor || 'Пользователь')}</div>
                <div style="opacity:0.85; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${escHtml(currentReplyPreview)}</div>
            </div>
        `;
    }
    optContent += escHtml(text);

    const nowTime = new Date().toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
    tempDiv.innerHTML = `
        <div class="dm-msg-bubble">${optContent}</div>
        <div class="dm-msg-time">${nowTime} <i class="fas fa-check" style="font-size:9px; opacity:0.7;"></i></div>
    `;

    removeDmPlaceholder();
    if (box) {
        box.appendChild(tempDiv);
        box.scrollTop = box.scrollHeight;
    }

    try {
        const fd = new FormData();
        fd.append('receiver_id', DM_SELECTED_ID);
        fd.append('message', text);
        if (currentReplyId) fd.append('reply_to_id', currentReplyId);
        if (currentFwdFrom) fd.append('forwarded_from', currentFwdFrom);
        fd.append('csrf_token', DM_CSRF);

        const r    = await fetch('direct_messages.php?action=send_message', { method: 'POST', body: fd });
        const data = await r.json();
        const newMsgId = data.id || data.message_id;

        if (data.success && newMsgId) {
            dmLastMsgId = Math.max(dmLastMsgId, newMsgId);

            // Если сообщение уже было отрендерено параллельным опросом dmLoadMessages — удаляем временный div
            if (document.getElementById('dm-msg-' + newMsgId)) {
                tempDiv.remove();
            } else {
                tempDiv.id = 'dm-msg-' + newMsgId;
                tempDiv.classList.remove('dm-temp-msg');
                tempDiv.style.opacity = '1';

                const safeAuthor = 'Вы';
                const safePreview = escHtml(text).replace(/'/g, "\\'");
                const actionsHtml = `
                    <div class="dm-msg-actions" style="margin-top:4px; display:flex; gap:10px; font-size:11px; opacity:0.75; justify-content:flex-end;">
                        <span onclick="dmSetReply(${newMsgId}, '${safeAuthor}', '${safePreview}')" style="cursor:pointer;" title="Ответить">
                            <i class="fas fa-reply"></i> Ответить
                        </span>
                        <span onclick="dmSetForward('${safeAuthor}', '${safePreview}')" style="cursor:pointer;" title="Переслать">
                            <i class="fas fa-share"></i> Переслать
                        </span>
                        <span onclick="dmDeleteMessage(${newMsgId}, true)" style="cursor:pointer; color:#f87171;" title="Удалить">
                            <i class="fas fa-trash-alt"></i>
                        </span>
                    </div>
                `;
                const bubble = tempDiv.querySelector('.dm-msg-bubble');
                if (bubble && !bubble.querySelector('.dm-msg-actions')) {
                    bubble.insertAdjacentHTML('beforeend', actionsHtml);
                }
            }
        } else if (!data.success) {
            tempDiv.style.border = '1px solid #ef4444';
            alert(data.error || 'Ошибка отправки');
        }
    } catch(e) {
        tempDiv.style.border = '1px solid #ef4444';
    }
}

function dmHandleKeydown(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        dmSendMessage();
    }
    const ta = e.target;
    ta.style.height = 'auto';
    ta.style.height = Math.min(ta.scrollHeight, 120) + 'px';
}

// ── Voice Messages Recording ──────────────────────────────────────────────────
let dmMediaRecorder = null;
let dmAudioChunks = [];
let dmRecordInterval = null;
let dmRecordSec = 0;

window.startVoiceRecord = async function() {
    if (!DM_SELECTED_ID) return;
    try {
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        dmAudioChunks = [];
        dmMediaRecorder = new MediaRecorder(stream);
        dmMediaRecorder.ondataavailable = e => {
            if (e.data && e.data.size > 0) dmAudioChunks.push(e.data);
        };
        dmMediaRecorder.start();

        document.getElementById('dm-text-input-wrap').style.display = 'none';
        document.getElementById('dm-voice-recording-wrap').style.display = 'flex';

        dmRecordSec = 0;
        document.getElementById('dm-voice-timer').textContent = '00:00';
        clearInterval(dmRecordInterval);
        dmRecordInterval = setInterval(() => {
            dmRecordSec++;
            const m = Math.floor(dmRecordSec / 60).toString().padStart(2, '0');
            const s = (dmRecordSec % 60).toString().padStart(2, '0');
            document.getElementById('dm-voice-timer').textContent = `${m}:${s}`;
        }, 1000);
    } catch(err) {
        alert('Не удалось получить доступ к микрофону: ' + err.message);
    }
};

window.cancelVoiceRecord = function() {
    if (dmMediaRecorder && dmMediaRecorder.state !== 'inactive') {
        try {
            dmMediaRecorder.stream.getTracks().forEach(t => t.stop());
            dmMediaRecorder.stop();
        } catch(e) {}
    }
    clearInterval(dmRecordInterval);
    document.getElementById('dm-text-input-wrap').style.display = 'flex';
    document.getElementById('dm-voice-recording-wrap').style.display = 'none';
};

window.sendVoiceRecord = function() {
    if (!dmMediaRecorder || dmMediaRecorder.state === 'inactive') return;
    const duration = dmRecordSec;
    clearInterval(dmRecordInterval);

    dmMediaRecorder.onstop = async () => {
        try {
            dmMediaRecorder.stream.getTracks().forEach(t => t.stop());
        } catch(e) {}

        const blob = new Blob(dmAudioChunks, { type: 'audio/webm' });
        const fd = new FormData();
        fd.append('voice_file', blob, `voice_${Date.now()}.webm`);
        fd.append('duration_sec', duration);
        fd.append('target_type', 'direct');
        fd.append('receiver_id', DM_SELECTED_ID);

        try {
            const res = await fetch('/api/voice_message.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                dmLoadMessages(false);
            }
        } catch(e) {
            console.error('Voice send error', e);
        }

        document.getElementById('dm-text-input-wrap').style.display = 'flex';
        document.getElementById('dm-voice-recording-wrap').style.display = 'none';
    };
    dmMediaRecorder.stop();
};

// ── Utility ───────────────────────────────────────────────────────────────────
function escHtml(str) {
    return String(str || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function formatDmTime(dtStr) {
    if (!dtStr) return '';
    try {
        const d = new Date(dtStr.replace(' ', 'T'));
        const now = new Date();
        const isToday = d.toDateString() === now.toDateString();
        const hh = d.getHours().toString().padStart(2,'0');
        const mm = d.getMinutes().toString().padStart(2,'0');
        if (isToday) return `${hh}:${mm}`;
        const dd = d.getDate().toString().padStart(2,'0');
        const mo = (d.getMonth()+1).toString().padStart(2,'0');
        return `${dd}.${mo} ${hh}:${mm}`;
    } catch(e) { return dtStr; }
}

// ── Init ──────────────────────────────────────────────────────────────────────
(async function init() {
    await dmLoadContacts();

    if (DM_SELECTED_ID) {
        await dmLoadMessages(true);
        dmPollTimer = setInterval(() => dmLoadMessages(false), 2500);

        <?php if (!empty($_GET['call']) && $selectedUser): ?>
        setTimeout(() => {
            if (typeof window.startDirectCall === 'function') {
                window.startDirectCall(<?= (int)$selectedUser['id'] ?>, <?= json_encode($selectedUser['first_name'] . ' ' . $selectedUser['last_name']) ?>);
            }
        }, 600);
        <?php endif; ?>
    }

    setInterval(dmLoadContacts, 8000);
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>