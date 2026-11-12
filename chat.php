<?php
declare(strict_types=1);
ob_start();

/**
 * AI Business Assistant - Unified Chat & Messaging System
 * Корпоративные каналы, автоматическая очистка в 00:00 МСК, ответы на сообщения, прикрепление файлов,
 * удаление сообщений ("у себя" / "у всех") и удаление чатов создателем/админом.
 */

require_once __DIR__ . '/includes/Auth.php';

$user = Auth::requireAuth('/login.php');
$userId = (int)$user['id'];
$userRole = $user['role_name'] ?? 'user';
$pdo = Database::getConnection();

// Роли персонала платформы
$platformStaffRoles = ['vrio_owner', 'admin', 'developer', 'tester', 'support_agent'];
$isPlatformStaff = in_array($userRole, $platformStaffRoles, true);
$isAdmin = Auth::isAdmin();

// 1. Автомиграция структуры таблиц чата (прикрепление файлов, ответы, удаление)
try {
    $cols = $pdo->query("SHOW COLUMNS FROM team_chat_messages")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('reply_to_id', $cols, true)) {
        $pdo->exec("ALTER TABLE team_chat_messages ADD COLUMN reply_to_id INT NULL DEFAULT NULL AFTER message");
    }
    if (!in_array('forwarded_from', $cols, true)) {
        $pdo->exec("ALTER TABLE team_chat_messages ADD COLUMN forwarded_from VARCHAR(150) NULL DEFAULT NULL AFTER reply_to_id");
    }
    if (!in_array('file_path', $cols, true)) {
        $pdo->exec("ALTER TABLE team_chat_messages ADD COLUMN file_path VARCHAR(255) NULL DEFAULT NULL AFTER reply_to_id");
    }
    if (!in_array('file_name', $cols, true)) {
        $pdo->exec("ALTER TABLE team_chat_messages ADD COLUMN file_name VARCHAR(255) NULL DEFAULT NULL AFTER file_path");
    }
    if (!in_array('file_size', $cols, true)) {
        $pdo->exec("ALTER TABLE team_chat_messages ADD COLUMN file_size INT NULL DEFAULT NULL AFTER file_name");
    }
    if (!in_array('file_type', $cols, true)) {
        $pdo->exec("ALTER TABLE team_chat_messages ADD COLUMN file_type VARCHAR(50) NULL DEFAULT NULL AFTER file_size");
    }
    if (!in_array('deleted_for_all', $cols, true)) {
        $pdo->exec("ALTER TABLE team_chat_messages ADD COLUMN deleted_for_all TINYINT(1) NOT NULL DEFAULT 0 AFTER file_type");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS team_chat_deleted_messages (
            message_id INT NOT NULL,
            user_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (message_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    $uploadDir = __DIR__ . '/uploads/chat_files';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
} catch (\Throwable $t) {}

// Расчет временных меток по часовому поясу МСК (UTC+3)
$mskTz = new DateTimeZone('Europe/Moscow');
$nowMsk = new DateTime('now', $mskTz);
$todayStartMsk = new DateTime('today 00:00:00', $mskTz);
$yesterdayStartMsk = (clone $todayStartMsk)->modify('-1 day');
$yesterdayEndMsk   = (clone $todayStartMsk)->modify('-1 second');

$todayStartStr     = $todayStartMsk->format('Y-m-d H:i:s');
$yesterdayStartStr = $yesterdayStartMsk->format('Y-m-d H:i:s');
$yesterdayEndStr   = $yesterdayEndMsk->format('Y-m-d H:i:s');

// Текущий фильтр периода: 'today' (по умолчанию), 'yesterday', 'all'
$period = trim($_GET['period'] ?? 'today');
if (!in_array($period, ['today', 'yesterday', 'all'], true)) {
    $period = 'today';
}

// Находим организацию пользователя (если есть)
$orgStmt = $pdo->prepare("
    SELECT id, name, business_type 
    FROM organizations 
    WHERE (user_id = ? OR director_email = ?) AND is_active = 1 
    ORDER BY id DESC LIMIT 1
");
$orgStmt->execute([$userId, $user['email']]);
$userOrg = $orgStmt->fetch();

// Автоматически создаем канал организации, если он еще не создан
if ($userOrg) {
    $orgKey = 'org_' . $userOrg['id'];
    $checkOrgChan = $pdo->prepare("SELECT id FROM team_chat_channels WHERE channel_key = ? LIMIT 1");
    $checkOrgChan->execute([$orgKey]);
    if (!$checkOrgChan->fetchColumn()) {
        $pdo->prepare("
            INSERT INTO team_chat_channels (channel_key, type, organization_id, name, description, created_by)
            VALUES (?, 'org_management', ?, ?, ?, ?)
        ")->execute([
            $orgKey,
            $userOrg['id'],
            'Руководство: ' . $userOrg['name'],
            'Внутренний закрытый чат руководства и дирекции компании «' . $userOrg['name'] . '»',
            $userId
        ]);
    }
}

// Загружаем список доступных каналов
$availableChannels = [];

// 1. Канал руководства сайта (доступен только персоналу платформы)
if ($isPlatformStaff) {
    $siteChan = $pdo->query("SELECT * FROM team_chat_channels WHERE channel_key = 'site_hq' LIMIT 1")->fetch();
    if ($siteChan) {
        $availableChannels[] = [
            'id'          => $siteChan['id'],
            'key'         => $siteChan['channel_key'],
            'type'        => 'site_management',
            'icon'        => 'fa-crown',
            'color'       => '#f59e0b',
            'name'        => $siteChan['name'],
            'badge'       => 'Руководство сайта',
            'description' => $siteChan['description'],
            'created_by'  => $siteChan['created_by']
        ];
    }
}

// 2. Каналы руководства организаций
if ($userOrg) {
    $myOrgChan = $pdo->prepare("SELECT * FROM team_chat_channels WHERE channel_key = ? LIMIT 1");
    $myOrgChan->execute(['org_' . $userOrg['id']]);
    $oc = $myOrgChan->fetch();
    if ($oc) {
        $availableChannels[] = [
            'id'          => $oc['id'],
            'key'         => $oc['channel_key'],
            'type'        => 'org_management',
            'icon'        => 'fa-building',
            'color'       => '#10b981',
            'name'        => $oc['name'],
            'badge'       => 'Дирекция компании',
            'description' => $oc['description'],
            'created_by'  => $oc['created_by']
        ];
    }
}

// Администраторам и ВРИО Владельцам доступны также каналы всех организаций
if (in_array($userRole, ['vrio_owner', 'admin'], true)) {
    $allOrgChans = $pdo->query("
        SELECT tc.*, o.name AS org_name 
        FROM team_chat_channels tc 
        INNER JOIN organizations o ON tc.organization_id = o.id 
        WHERE tc.type = 'org_management' 
        ORDER BY tc.id DESC
    ")->fetchAll();

    foreach ($allOrgChans as $aoc) {
        $alreadyIn = false;
        foreach ($availableChannels as $ac) {
            if ($ac['id'] === $aoc['id']) { $alreadyIn = true; break; }
        }
        if (!$alreadyIn) {
            $availableChannels[] = [
                'id'          => $aoc['id'],
                'key'         => $aoc['channel_key'],
                'type'        => 'org_management',
                'icon'        => 'fa-building',
                'color'       => '#3b82f6',
                'name'        => $aoc['name'],
                'badge'       => 'Организация',
                'description' => $aoc['description'],
                'created_by'  => $aoc['created_by']
            ];
        }
    }
}

// 3. Пользовательские созданные чаты и группы
$customChans = $pdo->query("
    SELECT * FROM team_chat_channels 
    WHERE type NOT IN ('site_management') OR created_by = {$userId}
    ORDER BY id DESC
")->fetchAll();

foreach ($customChans as $cc) {
    $alreadyIn = false;
    foreach ($availableChannels as $ac) {
        if ($ac['id'] === $cc['id']) { $alreadyIn = true; break; }
    }
    if (!$alreadyIn) {
        $availableChannels[] = [
            'id'          => $cc['id'],
            'key'         => $cc['channel_key'],
            'type'        => $cc['type'],
            'icon'        => 'fa-users',
            'color'       => '#8b5cf6',
            'name'        => $cc['name'],
            'badge'       => ($cc['type'] === 'org_management' ? 'Организация' : 'Группа'),
            'description' => $cc['description'],
            'created_by'  => $cc['created_by']
        ];
    }
}

// Выбранный канал
$activeChannelKey = trim($_GET['channel'] ?? '');
$activeChannel = null;

if (!empty($activeChannelKey)) {
    foreach ($availableChannels as $c) {
        if ($c['key'] === $activeChannelKey) {
            $activeChannel = $c;
            break;
        }
    }
}

if (!$activeChannel && !empty($availableChannels)) {
    $activeChannel = $availableChannels[0];
    $activeChannelKey = $activeChannel['key'];
}

// Проверка прав на удаление активного чата
$canDeleteActiveChat = false;
if ($activeChannel && $activeChannel['key'] !== 'site_hq') {
    if ($isAdmin || $isPlatformStaff || ((int)($activeChannel['created_by'] ?? 0) === $userId)) {
        $canDeleteActiveChat = true;
    }
}

// Обработка POST-запросов (создание, удаление чата, жалобы)
$chatNotice = null;
$chatError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (Security::verifyCsrf($token)) {
        $postAction = $_POST['action'] ?? '';

        // 1. Создание нового чата
        if ($postAction === 'create_chat') {
            $chatName = trim($_POST['chat_name'] ?? '');
            $chatDesc = trim($_POST['chat_desc'] ?? '');
            $chatType = trim($_POST['chat_type'] ?? 'general');

            if (!empty($chatName)) {
                $chanKey = 'c_' . bin2hex(random_bytes(4));
                $orgId = ($chatType === 'org_management' && $userOrg) ? (int)$userOrg['id'] : null;

                $ins = $pdo->prepare("
                    INSERT INTO team_chat_channels (channel_key, type, organization_id, name, description, created_by)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $ins->execute([$chanKey, $chatType, $orgId, $chatName, $chatDesc, $userId]);
                $newChanId = (int)$pdo->lastInsertId();

                $creatorName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: 'Пользователь';
                $pdo->prepare("
                    INSERT INTO team_chat_messages (channel_id, sender_id, message)
                    VALUES (?, ?, ?)
                ")->execute([$newChanId, $userId, "👋 Чат «{$chatName}» создан пользователем {$creatorName}! Приятного общения."]);

                header("Location: /chat.php?channel=" . urlencode($chanKey) . "&chat_created=1");
                exit;
            }
        }

        // 2. Удаление чата создателем или администратором
        if ($postAction === 'delete_chat') {
            $targetChanId = (int)($_POST['channel_id'] ?? 0);
            $chanStmt = $pdo->prepare("SELECT * FROM team_chat_channels WHERE id = ? LIMIT 1");
            $chanStmt->execute([$targetChanId]);
            $targetChan = $chanStmt->fetch();

            if ($targetChan && $targetChan['channel_key'] !== 'site_hq') {
                $canDel = ($isAdmin || $isPlatformStaff || ((int)$targetChan['created_by'] === $userId));
                if ($canDel) {
                    $pdo->prepare("DELETE FROM team_chat_messages WHERE channel_id = ?")->execute([$targetChanId]);
                    $pdo->prepare("DELETE FROM team_chat_channels WHERE id = ?")->execute([$targetChanId]);
                    Security::log($pdo, 'delete_chat_channel', "Удален чат: {$targetChan['name']} (#{$targetChanId})", $userId);
                    header("Location: /chat.php?chat_deleted=1");
                    exit;
                } else {
                    $chatError = "У вас нет прав для удаления этого чата. Удалить может только создатель или Администратор.";
                }
            }
        }

        // 3. Подача жалобы
        if ($postAction === 'submit_complaint') {
            $compCat = trim($_POST['complaint_category'] ?? 'bar');
            $compTitle = trim($_POST['complaint_title'] ?? '');
            $compDesc = trim($_POST['complaint_desc'] ?? '');
            $compSev = trim($_POST['complaint_severity'] ?? 'medium');

            if (!empty($compTitle) && !empty($compDesc)) {
                $catTitles = [
                    'bar'      => '🍸 Жалоба на бар / обслуживание',
                    'cashier'  => '💳 Жалоба на кассу / POS',
                    'bug'      => '🐞 Технический баг платформы',
                    'payment'  => '💰 Вопрос по чеку / оплате',
                    'staff'    => '👤 Жалоба на персонал',
                    'other'    => '📋 Другая жалоба'
                ];
                $catTitle = $catTitles[$compCat] ?? $compCat;

                $pdo->prepare("
                    INSERT INTO bug_reports (tester_id, title, severity, status, description, steps_to_reproduce)
                    VALUES (?, ?, ?, 'new', ?, ?)
                ")->execute([$userId, "[{$catTitle}] " . $compTitle, $compSev, $compDesc, "Категория обращения: {$catTitle}"]);
                $reportId = (int)$pdo->lastInsertId();

                try {
                    $siteHqId = $pdo->query("SELECT id FROM team_chat_channels WHERE channel_key = 'site_hq' LIMIT 1")->fetchColumn();
                    if ($siteHqId) {
                        $uName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: 'Сотрудник';
                        $msg = "⚠️ [НОВОЕ ОБРАЩЕНИЕ / ЖАЛОБА #{$reportId}]\nТип: {$catTitle}\nТема: {$compTitle}\nОт: {$uName}\nСуть: " . mb_substr($compDesc, 0, 160);
                        $pdo->prepare("INSERT INTO team_chat_messages (channel_id, sender_id, message) VALUES (?, ?, ?)")
                            ->execute([$siteHqId, $userId, $msg]);
                    }
                } catch (\Throwable $t) {}

                header("Location: /chat.php?channel=" . urlencode($activeChannelKey) . "&complaint_sent=1");
                exit;
            }
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// AJAX API (отправка сообщений, файлов, удаление у себя/у всех, получение)
// ─────────────────────────────────────────────────────────────────────────────
if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    // Получение сообщений с учетом фильтра 00:00 МСК
    if ($_GET['action'] === 'get_messages' && $activeChannel) {
        $lastId = (int)($_GET['last_id'] ?? 0);
        $reqPeriod = trim($_GET['period'] ?? 'today');

        $timeSql = "";
        $params = [
            'channel_id' => $activeChannel['id'],
            'user_id'    => $userId,
            'last_id'    => $lastId
        ];

        if ($reqPeriod === 'today') {
            $timeSql = " AND m.created_at >= :p_start ";
            $params['p_start'] = $todayStartStr;
        } elseif ($reqPeriod === 'yesterday') {
            $timeSql = " AND m.created_at >= :p_start AND m.created_at <= :p_end ";
            $params['p_start'] = $yesterdayStartStr;
            $params['p_end']   = $yesterdayEndStr;
        }

        $query = "
            SELECT 
                m.*, 
                u.first_name, u.last_name, u.email, 
                r.name AS role_name, r.description AS role_description,
                rmsg.message AS reply_text,
                ru.first_name AS reply_user_first_name,
                ru.last_name AS reply_user_last_name
            FROM team_chat_messages m
            LEFT JOIN users u ON m.sender_id = u.id
            LEFT JOIN roles r ON u.role_id = r.id
            LEFT JOIN team_chat_messages rmsg ON m.reply_to_id = rmsg.id
            LEFT JOIN users ru ON rmsg.sender_id = ru.id
            LEFT JOIN team_chat_deleted_messages del_me ON del_me.message_id = m.id AND del_me.user_id = :user_id
            WHERE m.channel_id = :channel_id 
              AND m.id > :last_id
              AND m.deleted_for_all = 0
              AND del_me.message_id IS NULL
              {$timeSql}
            ORDER BY m.id ASC
            LIMIT 100
        ";

        $msgStmt = $pdo->prepare($query);
        $msgStmt->execute($params);
        $rows = $msgStmt->fetchAll();

        echo json_encode(['success' => true, 'messages' => $rows]);
        exit;
    }

    // Отправка сообщения (с поддержкой цитирования и файлов)
    if ($_GET['action'] === 'send_message' && $_SERVER['REQUEST_METHOD'] === 'POST' && $activeChannel) {
        $token = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCsrf($token)) {
            echo json_encode(['success' => false, 'error' => 'CSRF токен истёк']);
            exit;
        }

        $message = trim($_POST['message'] ?? '');
        $replyToId = (int)($_POST['reply_to_id'] ?? 0);
        if ($replyToId <= 0) {
            $replyToId = null;
        }

        $filePath = null;
        $fileName = null;
        $fileSize = null;
        $fileType = null;

        // Обработка загруженного файла
        if (!empty($_FILES['chat_file']) && $_FILES['chat_file']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['chat_file'];
            $maxSize = 25 * 1024 * 1024; // 25 MB

            if ($file['size'] > $maxSize) {
                echo json_encode(['success' => false, 'error' => 'Файл слишком большой (максимум 25 МБ)']);
                exit;
            }

            $origName = basename($file['name']);
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            $safeExts = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'pdf', 'txt', 'doc', 'docx', 'xls', 'xlsx', 'zip', 'rar', 'csv'];

            if (!in_array($ext, $safeExts, true)) {
                echo json_encode(['success' => false, 'error' => 'Недопустимый формат файла']);
                exit;
            }

            $newFileName = time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $targetPath = __DIR__ . '/uploads/chat_files/' . $newFileName;

            if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                $filePath = '/uploads/chat_files/' . $newFileName;
                $fileName = $origName;
                $fileSize = (int)$file['size'];
                $fileType = $ext;
            }
        }

        if (empty($message) && empty($filePath)) {
            echo json_encode(['success' => false, 'error' => 'Введите сообщение или прикрепите файл']);
            exit;
        }

        $forwardedFrom = trim($_POST['forwarded_from'] ?? '');
        if ($forwardedFrom === '') {
            $forwardedFrom = null;
        }

        $ins = $pdo->prepare("
            INSERT INTO team_chat_messages (channel_id, sender_id, message, reply_to_id, forwarded_from, file_path, file_name, file_size, file_type)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $ins->execute([
            $activeChannel['id'],
            $userId,
            $message,
            $replyToId,
            $forwardedFrom,
            $filePath,
            $fileName,
            $fileSize,
            $fileType
        ]);
        $msgId = (int)$pdo->lastInsertId();

        echo json_encode(['success' => true, 'message_id' => $msgId]);
        exit;
    }

    // Приглашение / добавление сотрудника в чат
    if ($_GET['action'] === 'add_channel_member' && $_SERVER['REQUEST_METHOD'] === 'POST' && $activeChannel) {
        $token = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCsrf($token)) {
            echo json_encode(['success' => false, 'error' => 'CSRF токен истёк']);
            exit;
        }
        $addUserId = (int)($_POST['user_id'] ?? 0);
        if ($addUserId > 0) {
            $uStmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ? LIMIT 1");
            $uStmt->execute([$addUserId]);
            $addedUser = $uStmt->fetch();
            if ($addedUser) {
                $addedName = trim(($addedUser['first_name'] ?? '') . ' ' . ($addedUser['last_name'] ?? '')) ?: 'Сотрудник';
                $inviterName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: 'Сотрудник';
                $pdo->prepare("
                    INSERT INTO team_chat_messages (channel_id, sender_id, message)
                    VALUES (?, ?, ?)
                ")->execute([$activeChannel['id'], $userId, "👋 {$inviterName} пригласил(а) {$addedName} в этот чат."]);
                echo json_encode(['success' => true, 'name' => $addedName]);
                exit;
            }
        }
        echo json_encode(['success' => false, 'error' => 'Пользователь не найден']);
        exit;
    }

    // Удаление сообщения: "У себя"
    if ($_GET['action'] === 'delete_for_me' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCsrf($token)) {
            echo json_encode(['success' => false, 'error' => 'CSRF токен истёк']);
            exit;
        }
        $msgId = (int)($_POST['message_id'] ?? 0);
        if ($msgId > 0) {
            $pdo->prepare("
                INSERT IGNORE INTO team_chat_deleted_messages (message_id, user_id) 
                VALUES (?, ?)
            ")->execute([$msgId, $userId]);
            echo json_encode(['success' => true]);
            exit;
        }
    }

    // Удаление сообщения: "У всех"
    if ($_GET['action'] === 'delete_for_all' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCsrf($token)) {
            echo json_encode(['success' => false, 'error' => 'CSRF токен истёк']);
            exit;
        }
        $msgId = (int)($_POST['message_id'] ?? 0);
        if ($msgId > 0) {
            $mStmt = $pdo->prepare("SELECT sender_id FROM team_chat_messages WHERE id = ? LIMIT 1");
            $mStmt->execute([$msgId]);
            $msg = $mStmt->fetch();

            if ($msg) {
                $canDelAll = ($isAdmin || (int)$msg['sender_id'] === $userId);
                if ($canDelAll) {
                    $pdo->prepare("UPDATE team_chat_messages SET deleted_for_all = 1 WHERE id = ?")->execute([$msgId]);
                    echo json_encode(['success' => true]);
                    exit;
                } else {
                    echo json_encode(['success' => false, 'error' => 'Удалить у всех может только автор или Администратор']);
                    exit;
                }
            }
        }
    }

    // Поиск пользователей платформы для приглашения / добавления в чаты
    if ($_GET['action'] === 'search_users') {
        $q = trim($_GET['q'] ?? '');
        $params = [':me' => $userId];
        $where = "u.id != :me AND u.status = 'active'";
        if ($q !== '') {
            $where .= " AND (u.first_name LIKE :s1 OR u.last_name LIKE :s2 OR u.email LIKE :s3 OR CONCAT(u.first_name, ' ', u.last_name) LIKE :s4)";
            $params[':s1'] = "%{$q}%";
            $params[':s2'] = "%{$q}%";
            $params[':s3'] = "%{$q}%";
            $params[':s4'] = "%{$q}%";
        }
        $uStmt = $pdo->prepare("
            SELECT u.id, u.first_name, u.last_name, u.email, r.name AS role_name, r.description AS role_title
            FROM users u
            JOIN roles r ON u.role_id = r.id
            WHERE {$where}
            ORDER BY u.first_name ASC
            LIMIT 25
        ");
        $uStmt->execute($params);
        $found = $uStmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'users' => $found]);
        exit;
    }

    // Получить список участников канала
    if ($_GET['action'] === 'get_channel_members' && $activeChannel) {
        $chanId = (int)$activeChannel['id'];
        $mStmt = $pdo->prepare("
            SELECT DISTINCT u.id, u.first_name, u.last_name, u.email, r.name AS role_name
            FROM team_chat_messages m
            JOIN users u ON m.sender_id = u.id
            JOIN roles r ON u.role_id = r.id
            WHERE m.channel_id = ?
            ORDER BY u.first_name ASC
            LIMIT 50
        ");
        $mStmt->execute([$chanId]);
        $activeMembers = $mStmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'members' => $activeMembers]);
        exit;
    }
}

// Загрузка начальных сообщений для выбранного периода
$initialMessages = [];
if ($activeChannel) {
    $timeSql = "";
    $params = [
        'channel_id' => $activeChannel['id'],
        'user_id'    => $userId
    ];

    if ($period === 'today') {
        $timeSql = " AND m.created_at >= :p_start ";
        $params['p_start'] = $todayStartStr;
    } elseif ($period === 'yesterday') {
        $timeSql = " AND m.created_at >= :p_start AND m.created_at <= :p_end ";
        $params['p_start'] = $yesterdayStartStr;
        $params['p_end']   = $yesterdayEndStr;
    }

    $mStmt = $pdo->prepare("
        SELECT 
            m.*, 
            u.first_name, u.last_name, u.email, 
            r.name AS role_name, r.description AS role_description,
            rmsg.message AS reply_text,
            ru.first_name AS reply_user_first_name,
            ru.last_name AS reply_user_last_name
        FROM team_chat_messages m
        LEFT JOIN users u ON m.sender_id = u.id
        LEFT JOIN roles r ON u.role_id = r.id
        LEFT JOIN team_chat_messages rmsg ON m.reply_to_id = rmsg.id
        LEFT JOIN users ru ON rmsg.sender_id = ru.id
        LEFT JOIN team_chat_deleted_messages del_me ON del_me.message_id = m.id AND del_me.user_id = :user_id
        WHERE m.channel_id = :channel_id 
          AND m.deleted_for_all = 0
          AND del_me.message_id IS NULL
          {$timeSql}
        ORDER BY m.id ASC
        LIMIT 100
    ");
    $mStmt->execute($params);
    $initialMessages = $mStmt->fetchAll();
}

$roleBadges = [
    'vrio_owner'    => ['title' => 'ВРИО Владелец',   'bg' => 'linear-gradient(135deg, #d97706, #b45309)', 'color' => '#fff', 'icon' => 'fa-crown'],
    'developer'     => ['title' => 'Разработчик',     'bg' => 'linear-gradient(135deg, #06b6d4, #0891b2)', 'color' => '#fff', 'icon' => 'fa-code'],
    'tester'        => ['title' => 'Тестировщик',     'bg' => 'linear-gradient(135deg, #10b981, #059669)', 'color' => '#fff', 'icon' => 'fa-vial'],
    'support_agent' => ['title' => 'Агент поддержки', 'bg' => 'linear-gradient(135deg, #3b82f6, #2563eb)', 'color' => '#fff', 'icon' => 'fa-headset'],
    'admin'         => ['title' => 'Администратор',   'bg' => 'linear-gradient(135deg, #6366f1, #4f46e5)', 'color' => '#fff', 'icon' => 'fa-shield-alt'],
    'director'      => ['title' => 'Директор',        'bg' => 'linear-gradient(135deg, #8b5cf6, #7c3aed)', 'color' => '#fff', 'icon' => 'fa-user-tie'],
    'manager'       => ['title' => 'Менеджер',        'bg' => 'linear-gradient(135deg, #ec4899, #db2777)', 'color' => '#fff', 'icon' => 'fa-briefcase'],
    'user'          => ['title' => 'Пользователь',    'bg' => 'rgba(255,255,255,0.1)',                    'color' => '#9ca3af', 'icon' => 'fa-user'],
];

$csrfToken = Security::csrfToken();
$pageTitle = ($activeChannel ? $activeChannel['name'] : 'Корпоративные чаты') . " | AI Business Assistant";
require __DIR__ . '/includes/header.php';
?>

<style>
.chat-reply-quote {
    background: rgba(0,0,0,0.25);
    border-left: 3px solid #818cf8;
    padding: 6px 10px;
    border-radius: 6px;
    margin-bottom: 8px;
    font-size: 12px;
    cursor: pointer;
    transition: opacity 0.15s;
}
.chat-reply-quote:hover { opacity: 0.85; }
.chat-msg-actions {
    display: none;
    gap: 6px;
    align-items: center;
    margin-top: 4px;
}
.chat-message-item:hover .chat-msg-actions {
    display: flex;
}
.btn-msg-action {
    background: rgba(255,255,255,0.08);
    border: none;
    color: var(--text-muted);
    font-size: 11px;
    padding: 2px 8px;
    border-radius: 4px;
    cursor: pointer;
    transition: all 0.15s;
}
.btn-msg-action:hover {
    background: rgba(255,255,255,0.2);
    color: var(--text-primary);
}
.file-badge-preview {
    display: flex;
    align-items: center;
    gap: 8px;
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    padding: 8px 12px;
    border-radius: 8px;
    margin-top: 8px;
}
</style>

<div style="margin-bottom:20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
    <div>
        <h1 style="margin:0; font-size:24px; font-weight:700;">
            <i class="fas fa-comments" style="color:var(--primary); margin-right:8px;"></i> Корпоративные чаты и каналы связи
        </h1>
        <p style="margin:4px 0 0; color:var(--text-muted); font-size:14px;">
            Внутренние закрытые каналы • Очистка в 00:00 МСК • Ответы на сообщения • Отправка файлов
        </p>
    </div>
    <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <button type="button" class="btn btn-primary btn-sm" onclick="openCreateChatModal()">
            <i class="fas fa-plus"></i> Создать чат
        </button>
        <button type="button" class="btn btn-secondary btn-sm" onclick="openComplaintModal()" style="border-color:#ef4444; color:#ef4444;">
            <i class="fas fa-exclamation-triangle"></i> Подать жалобу / Баг
        </button>
        <?php if ($isPlatformStaff): ?>
            <a href="/admin/dashboard.php" class="btn btn-secondary btn-sm">
                <i class="fas fa-arrow-left"></i> В панель управления
            </a>
        <?php endif; ?>
        <?php if ($userOrg): ?>
            <a href="/user/organizations.php" class="btn btn-secondary btn-sm">
                <i class="fas fa-building"></i> Кабинет организации
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if (isset($_GET['chat_created'])): ?>
    <div class="alert alert-success" style="margin-bottom:16px;">
        <i class="fas fa-check-circle"></i> Новый чат успешно создан и готов к переписке!
    </div>
<?php endif; ?>

<?php if (isset($_GET['chat_deleted'])): ?>
    <div class="alert alert-success" style="margin-bottom:16px;">
        <i class="fas fa-check-circle"></i> Чат успешно удалён.
    </div>
<?php endif; ?>

<?php if (!empty($chatError)): ?>
    <div class="alert alert-danger" style="margin-bottom:16px;">
        <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($chatError) ?>
    </div>
<?php endif; ?>

<div style="display:grid; grid-template-columns: 320px 1fr; gap:20px; height:calc(100vh - 200px); min-height:600px;">
    
    <!-- ЛЕВАЯ КОЛОНКА: СПИСОК КАНАЛОВ И ПЕРЕПИСОК -->
    <div class="card" style="padding:0; overflow:hidden; display:flex; flex-direction:column; border:1px solid var(--border-color);">
        <div style="padding:16px; border-bottom:1px solid var(--border-color); background:rgba(0,0,0,0.15);">
            <div style="font-weight:700; font-size:15px; margin-bottom:4px; display:flex; align-items:center; justify-content:space-between;">
                <span>Каналы и группы</span>
                <span style="font-size:11px; color:var(--text-muted); background:var(--bg-input); padding:2px 8px; border-radius:12px;">
                    <?= count($availableChannels) ?>
                </span>
            </div>
            <div style="font-size:12px; color:var(--text-muted); margin-bottom:10px;">
                Ваша роль: <span style="color:var(--text-primary); font-weight:600;"><?= $roleBadges[$userRole]['title'] ?? $userRole ?></span>
            </div>
            <!-- Поиск каналов -->
            <div style="position:relative;">
                <input type="text" id="channelSearchInput" placeholder="Поиск каналов..." 
                       style="width:100%; padding:6px 12px 6px 30px; font-size:12px; background:var(--bg-input); border:1px solid var(--border-color); border-radius:8px; color:var(--text-primary);">
                <i class="fas fa-search" style="position:absolute; left:10px; top:50%; transform:translateY(-50%); font-size:11px; color:var(--text-muted);"></i>
            </div>
        </div>

        <div style="flex:1; overflow-y:auto; padding:8px;">
            <?php if (empty($availableChannels)): ?>
                <div style="padding:24px; text-align:center; color:var(--text-muted); font-size:13px;">
                    <i class="fas fa-lock" style="font-size:28px; opacity:0.4; margin-bottom:8px; display:block;"></i>
                    У вас пока нет доступа к закрытым каналам.
                </div>
            <?php else: ?>
                <div id="chatChannelList" style="display:flex; flex-direction:column; gap:6px;">
                    <?php foreach ($availableChannels as $chan): 
                        $isActive = ($chan['key'] === $activeChannelKey);
                    ?>
                        <a href="/chat.php?channel=<?= urlencode($chan['key']) ?>&period=<?= urlencode($period) ?>" 
                           style="display:flex; align-items:center; gap:12px; padding:12px; border-radius:10px; text-decoration:none; transition:background 0.15s;
                                  <?= $isActive ? 'background:rgba(99,102,241,0.15); border:1px solid rgba(99,102,241,0.4);' : 'background:transparent; border:1px solid transparent;' ?>">
                            <div style="width:38px; height:38px; border-radius:10px; background:<?= $chan['color'] ?>20; color:<?= $chan['color'] ?>; display:flex; align-items:center; justify-content:center; font-size:16px; flex-shrink:0;">
                                <i class="fas <?= $chan['icon'] ?>"></i>
                            </div>
                            <div style="flex:1; min-width:0;">
                                <div style="display:flex; justify-content:space-between; align-items:center;">
                                    <span style="font-weight:600; font-size:13px; color:<?= $isActive ? 'var(--primary)' : 'var(--text-primary)' ?>; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                        <?= htmlspecialchars($chan['name']) ?>
                                    </span>
                                </div>
                                <div style="display:flex; align-items:center; gap:6px; margin-top:2px;">
                                    <span class="badge" style="font-size:10px; padding:1px 6px; background:<?= $chan['color'] ?>20; color:<?= $chan['color'] ?>;">
                                        <?= $chan['badge'] ?>
                                    </span>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div style="padding:12px 16px; border-top:1px solid var(--border-color); background:rgba(0,0,0,0.1); font-size:11px; color:var(--text-muted); line-height:1.5;">
            🕒 <strong>Очистка в 00:00 МСК:</strong> Каждый день чат начинается с нового листа. Вчерашние сообщения доступны по кнопке «Вчера».
        </div>
    </div>

    <!-- ПРАВАЯ КОЛОНКА: ОКНО АКТИВНОГО ЧАТА -->
    <div class="card" style="padding:0; overflow:hidden; display:flex; flex-direction:column; border:1px solid var(--border-color);">
        <?php if (!$activeChannel): ?>
            <div style="flex:1; display:flex; flex-direction:column; align-items:center; justify-content:center; padding:40px; text-align:center; color:var(--text-muted);">
                <i class="fas fa-comments" style="font-size:48px; opacity:0.3; margin-bottom:16px;"></i>
                <h3 style="margin:0 0 8px; color:var(--text-primary);">Выберите канал для общения</h3>
            </div>
        <?php else: ?>
            <!-- Шапка чата: инфо о канале, переключатель Сегодня/Вчера, кнопка Удалить чат -->
            <div style="padding:14px 20px; border-bottom:1px solid var(--border-color); background:rgba(0,0,0,0.15); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
                <div style="display:flex; align-items:center; gap:12px;">
                    <div style="width:40px; height:40px; border-radius:10px; background:<?= $activeChannel['color'] ?>20; color:<?= $activeChannel['color'] ?>; display:flex; align-items:center; justify-content:center; font-size:18px;">
                        <i class="fas <?= $activeChannel['icon'] ?>"></i>
                    </div>
                    <div>
                        <div style="font-weight:700; font-size:16px; color:var(--text-primary); display:flex; align-items:center; gap:8px;">
                            <span><?= htmlspecialchars($activeChannel['name']) ?></span>
                            <span class="badge" style="font-size:11px; background:<?= $activeChannel['color'] ?>20; color:<?= $activeChannel['color'] ?>;">
                                <?= $activeChannel['badge'] ?>
                            </span>
                        </div>
                        <div style="font-size:12px; color:var(--text-muted); margin-top:2px;">
                            <?= htmlspecialchars($activeChannel['description'] ?? 'Закрытый канал координации') ?>
                        </div>
                    </div>
                </div>

                <div style="display:flex; align-items:center; gap:10px;">
                    <!-- Переключатель периодов (Сегодня / Вчера / Вся история) -->
                    <div style="display:inline-flex; background:var(--bg-input); border-radius:8px; padding:3px; border:1px solid var(--border-color);">
                        <a href="/chat.php?channel=<?= urlencode($activeChannelKey) ?>&period=today" 
                           class="btn-period <?= $period === 'today' ? 'active' : '' ?>"
                           style="padding:4px 10px; font-size:12px; text-decoration:none; border-radius:6px; font-weight:600; color:<?= $period === 'today' ? '#fff' : 'var(--text-muted)' ?>; background:<?= $period === 'today' ? 'var(--primary)' : 'transparent' ?>;">
                            🟢 Сегодня
                        </a>
                        <a href="/chat.php?channel=<?= urlencode($activeChannelKey) ?>&period=yesterday" 
                           class="btn-period <?= $period === 'yesterday' ? 'active' : '' ?>"
                           style="padding:4px 10px; font-size:12px; text-decoration:none; border-radius:6px; font-weight:600; color:<?= $period === 'yesterday' ? '#fff' : 'var(--text-muted)' ?>; background:<?= $period === 'yesterday' ? 'var(--primary)' : 'transparent' ?>;">
                            📅 Вчера
                        </a>
                        <a href="/chat.php?channel=<?= urlencode($activeChannelKey) ?>&period=all" 
                           class="btn-period <?= $period === 'all' ? 'active' : '' ?>"
                           style="padding:4px 10px; font-size:12px; text-decoration:none; border-radius:6px; font-weight:600; color:<?= $period === 'all' ? '#fff' : 'var(--text-muted)' ?>; background:<?= $period === 'all' ? 'var(--primary)' : 'transparent' ?>;">
                            📜 Все
                        </a>
                    </div>

                    <!-- Кнопка перехода в личные сообщения -->
                    <a href="/direct_messages.php" class="btn btn-outline-primary btn-sm" 
                       style="font-weight:600; display:inline-flex; align-items:center; gap:6px; border-radius:8px;" 
                       title="Перейти в личные сообщения">
                        <i class="fas fa-user-friends"></i> Личные сообщения
                    </a>

                    <!-- Кнопка созвона в канале -->
                    <button type="button" class="btn btn-success btn-sm" 
                            onclick="window.startChatCall('<?= htmlspecialchars($activeChannelKey, ENT_QUOTES) ?>', '<?= htmlspecialchars($activeChannel['name'], ENT_QUOTES) ?>')"
                            style="background:#10b981; border-color:#059669; font-weight:600; display:inline-flex; align-items:center; gap:6px; color:#ffffff;" 
                            title="Начать групповой голосовой созвон в канале">
                        <i class="fas fa-phone-alt"></i> Созвон
                    </button>

                    <!-- Кнопка участников и добавления сотрудников -->
                    <button type="button" class="btn btn-secondary btn-sm" 
                            onclick="window.openChatMembersModal(<?= (int)$activeChannel['id'] ?>)"
                            style="display:inline-flex; align-items:center; gap:6px; font-weight:600;" 
                            title="Участники канала и приглашение людей">
                        <i class="fas fa-user-plus"></i> Участники
                    </button>

                    <!-- Кнопка удаления чата (создателю или админу) -->
                    <?php if ($canDeleteActiveChat): ?>
                        <button type="button" class="btn btn-secondary btn-sm" 
                                data-id="<?= (int)$activeChannel['id'] ?>" 
                                data-name="<?= htmlspecialchars($activeChannel['name'] ?? 'Чат', ENT_QUOTES) ?>" 
                                onclick="confirmDeleteChat(this.dataset.id, this.dataset.name)" 
                                style="color:#ef4444; border-color:rgba(239,68,68,0.4);" title="Удалить чат (доступно создателю и админу)">
                            <i class="fas fa-trash"></i> Удалить чат
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Область сообщений со скроллом -->
            <div id="chatMessagesBox" style="flex:1; overflow-y:auto; padding:20px; display:flex; flex-direction:column; gap:16px;">
                <!-- Сервисное уведомление о текущем периоде -->
                <div style="text-align:center; margin:8px 0;">
                    <?php if ($period === 'today'): ?>
                        <span style="font-size:12px; background:rgba(99,102,241,0.1); color:#818cf8; padding:4px 12px; border-radius:12px; border:1px solid rgba(99,102,241,0.2);">
                            🕒 Чат за сегодня (с 00:00 МСК). Вчерашние сообщения доступны по кнопке «Вчера».
                        </span>
                    <?php elseif ($period === 'yesterday'): ?>
                        <span style="font-size:12px; background:rgba(245,158,11,0.1); color:#f59e0b; padding:4px 12px; border-radius:12px; border:1px solid rgba(245,158,11,0.2);">
                            📅 Просмотр сообщений за вчерашний день (<?= $yesterdayStartMsk->format('d.m.Y') ?> по МСК).
                        </span>
                    <?php else: ?>
                        <span style="font-size:12px; background:rgba(255,255,255,0.06); color:var(--text-muted); padding:4px 12px; border-radius:12px;">
                            📜 Полный архив сообщений чата за всё время
                        </span>
                    <?php endif; ?>
                </div>

                <?php if (empty($initialMessages)): ?>
                    <div id="emptyNotice" style="text-align:center; padding:40px; color:var(--text-muted);">
                        <i class="fas fa-paper-plane" style="font-size:36px; opacity:0.3; margin-bottom:12px; display:block;"></i>
                        <p style="margin:0; font-size:14px;">
                            <?php if ($period === 'today'): ?>
                                Сегодня сообщений еще не было. Начните диалог первым!
                            <?php elseif ($period === 'yesterday'): ?>
                                Вчера в этом чате сообщений не отправлялось.
                            <?php else: ?>
                                Сообщений пока нет.
                            <?php endif; ?>
                        </p>
                    </div>
                <?php else: ?>
                    <?php 
                    $lastMsgId = 0;
                    foreach ($initialMessages as $m): 
                        $isMe = ((int)$m['sender_id'] === $userId);
                        $senderRole = $m['role_name'] ?? 'user';
                        $rb = $roleBadges[$senderRole] ?? ['title' => $senderRole, 'bg' => 'rgba(255,255,255,0.1)', 'color' => '#fff', 'icon' => 'fa-user'];
                        $senderName = trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? '')) ?: 'Сотрудник #' . $m['sender_id'];
                        $lastMsgId = max($lastMsgId, (int)$m['id']);
                        $canDelAll = ($isAdmin || $isMe);
                    ?>
                        <div class="chat-message-item" id="msg-item-<?= $m['id'] ?>" 
                             data-id="<?= (int)$m['id'] ?>" 
                             data-author="<?= htmlspecialchars($senderName, ENT_QUOTES) ?>" 
                             data-preview="<?= htmlspecialchars(mb_substr($m['message'] ?? 'Файл', 0, 80), ENT_QUOTES) ?>" 
                             style="display:flex; flex-direction:column; align-items: <?= $isMe ? 'flex-end' : 'flex-start' ?>;">
                            <div style="display:flex; align-items:center; gap:8px; margin-bottom:4px; font-size:11px; color:var(--text-muted);">
                                <span style="font-weight:700; color:<?= $isMe ? 'var(--primary)' : 'var(--text-primary)' ?>;">
                                    <?= htmlspecialchars($senderName) ?>
                                </span>
                                <span class="badge" style="background:<?= $rb['bg'] ?>; color:<?= $rb['color'] ?>; font-size:10px; padding:2px 6px; font-weight:600;">
                                    <i class="fas <?= $rb['icon'] ?>"></i> <?= $rb['title'] ?>
                                </span>
                                <span>•</span>
                                <span><?= date('H:i, d.m', strtotime($m['created_at'])) ?></span>
                            </div>

                            <div style="max-width:75%; padding:12px 16px; border-radius:12px; font-size:14px; line-height:1.5; word-break:break-word; position:relative;
                                <?= $isMe 
                                    ? 'background:linear-gradient(135deg, #4f46e5, #6366f1); color:#ffffff; border-bottom-right-radius:2px;' 
                                    : 'background:var(--bg-input); color:var(--text-primary); border:1px solid var(--border-color); border-bottom-left-radius:2px;' 
                                ?>">
                                
                                <!-- Отметка о пересылке (Forwarded) -->
                                <?php if (!empty($m['forwarded_from'])): ?>
                                    <div style="font-size:11px; opacity:0.85; margin-bottom:4px; display:flex; align-items:center; gap:4px; color:<?= $isMe ? '#e0e7ff' : '#818cf8' ?>;">
                                        <i class="fas fa-share" style="font-size:10px;"></i> Переслано от <?= htmlspecialchars($m['forwarded_from']) ?>
                                    </div>
                                <?php endif; ?>

                                <!-- Цитата / Ответ на сообщение -->
                                <?php if (!empty($m['reply_to_id']) && !empty($m['reply_text'])): 
                                    $replyAuthor = trim(($m['reply_user_first_name'] ?? '') . ' ' . ($m['reply_user_last_name'] ?? '')) ?: 'Собеседник';
                                ?>
                                    <div class="chat-reply-quote" onclick="scrollToMessage(<?= (int)$m['reply_to_id'] ?>)">
                                        <i class="fas fa-reply" style="margin-right:4px;"></i>
                                        <strong><?= htmlspecialchars($replyAuthor) ?>:</strong>
                                        <?= htmlspecialchars(mb_substr($m['reply_text'], 0, 80)) . (mb_strlen($m['reply_text']) > 80 ? '...' : '') ?>
                                    </div>
                                <?php endif; ?>

                                <!-- Текст сообщения -->
                                <?php if (!empty($m['message'])): ?>
                                    <div><?= nl2br(htmlspecialchars($m['message'])) ?></div>
                                <?php endif; ?>

                                <!-- Прикрепленный файл / изображение -->
                                <?php if (!empty($m['file_path'])): 
                                    $isImg = in_array(strtolower($m['file_type'] ?? ''), ['png', 'jpg', 'jpeg', 'gif', 'webp'], true);
                                ?>
                                    <div style="margin-top:8px;">
                                        <?php if ($isImg): ?>
                                            <a href="<?= htmlspecialchars($m['file_path']) ?>" target="_blank">
                                                <img src="<?= htmlspecialchars($m['file_path']) ?>" alt="<?= htmlspecialchars($m['file_name']) ?>" 
                                                     style="max-width:280px; max-height:220px; border-radius:8px; display:block; border:1px solid rgba(255,255,255,0.2);">
                                            </a>
                                        <?php else: ?>
                                            <a href="<?= htmlspecialchars($m['file_path']) ?>" download="<?= htmlspecialchars($m['file_name']) ?>" 
                                               style="display:flex; align-items:center; gap:8px; padding:8px 12px; background:rgba(0,0,0,0.2); border-radius:6px; color:inherit; text-decoration:none; font-size:12px; border:1px solid rgba(255,255,255,0.15);">
                                                <i class="fas fa-file-download" style="font-size:16px;"></i>
                                                <span style="flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                                    <?= htmlspecialchars($m['file_name']) ?> (<?= round(($m['file_size'] ?? 0)/1024) ?> КБ)
                                                </span>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Кнопки действий: Ответить, Переслать, Удалить у себя, Удалить у всех -->
                            <div class="chat-msg-actions">
                                <button type="button" class="btn-msg-action" onclick="handleReplyClick(this)" title="Ответить на сообщение">
                                    <i class="fas fa-reply"></i> Ответить
                                </button>
                                <button type="button" class="btn-msg-action" onclick="handleForwardClick(this)" title="Переслать сообщение">
                                    <i class="fas fa-share"></i> Переслать
                                </button>
                                <button type="button" class="btn-msg-action" onclick="handleDeleteClick(this, 'for_me')" title="Скрыть сообщение только у себя">
                                    <i class="fas fa-eye-slash"></i> У себя
                                </button>
                                <?php if ($canDelAll): ?>
                                    <button type="button" class="btn-msg-action" onclick="handleDeleteClick(this, 'for_all')" style="color:#f87171;" title="Удалить для всех участников">
                                        <i class="fas fa-trash-alt"></i> У всех
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Плашка цитирования (при ответе на сообщение) -->
            <div id="replyBox" style="display:none; padding:8px 20px; background:rgba(99,102,241,0.12); border-top:1px solid rgba(99,102,241,0.3); border-left:4px solid var(--primary);">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <div style="font-size:12px; color:var(--text-primary); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; padding-right:12px;">
                        <i class="fas fa-reply" style="color:var(--primary); margin-right:6px;"></i>
                        Ответ для <strong id="replyAuthor" style="color:var(--primary);"></strong>:
                        <span id="replyPreview" style="color:var(--text-muted); margin-left:4px;"></span>
                    </div>
                    <button type="button" onclick="cancelReply()" style="background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:16px;">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>

            <!-- Плашка пересылки (при пересылке сообщения) -->
            <div id="forwardBox" style="display:none; padding:8px 20px; background:rgba(139,92,246,0.12); border-top:1px solid rgba(139,92,246,0.3); border-left:4px solid #8b5cf6;">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <div style="font-size:12px; color:var(--text-primary); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; padding-right:12px;">
                        <i class="fas fa-share" style="color:#8b5cf6; margin-right:6px;"></i>
                        Переслать от <strong id="forwardAuthor" style="color:#a78bfa;"></strong>:
                        <span id="forwardPreview" style="color:var(--text-muted); margin-left:4px;"></span>
                    </div>
                    <button type="button" onclick="cancelForward()" style="background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:16px;">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>

            <!-- Превью прикрепленного файла перед отправкой -->
            <div id="filePreviewBox" style="display:none; padding:8px 20px; background:rgba(16,185,129,0.1); border-top:1px solid rgba(16,185,129,0.3);">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <div style="font-size:12px; color:#10b981; display:flex; align-items:center; gap:8px;">
                        <i class="fas fa-paperclip"></i>
                        <span id="fileNameLabel" style="font-weight:600;"></span>
                    </div>
                    <button type="button" onclick="cancelFile()" style="background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:16px;">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>

            <!-- Поле ввода и отправки сообщений -->
            <div style="padding:16px 20px; border-top:1px solid var(--border-color); background:var(--bg-card);">
                <form id="chatForm" onsubmit="event.preventDefault(); submitChatMessage(event); return false;" style="display:flex; gap:10px; align-items:flex-end;">
                    <input type="hidden" name="csrf_token" id="chatCsrfToken" value="<?= $csrfToken ?>">
                    <input type="hidden" name="reply_to_id" id="replyToId" value="">
                    <input type="hidden" name="forwarded_from" id="forwardedFromInput" value="">

                    <!-- Кнопка скрепки для прикрепления файла -->
                    <label for="chatFileInput" class="btn btn-secondary" style="height:48px; width:48px; padding:0; display:flex; align-items:center; justify-content:center; cursor:pointer; flex-shrink:0;" title="Прикрепить фото или документ">
                        <i class="fas fa-paperclip" style="font-size:18px;"></i>
                    </label>
                    <input type="file" id="chatFileInput" name="chat_file" style="display:none;" onchange="handleFileSelected(this)">

                    <div style="flex:1;">
                        <textarea name="message" id="chatInputMessage" class="form-control" rows="2" style="resize:none; padding:10px 14px;" 
                                  placeholder="Напишите сообщение в чат... (Enter — отправка, Shift+Enter — перенос)" required></textarea>
                    </div>

                    <button type="submit" id="chatSendBtn" class="btn btn-primary" style="height:48px; padding:0 24px; flex-shrink:0;">
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Модалка удаления чата -->
<div id="deleteChatModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7); z-index:9999; justify-content:center; align-items:center; padding:16px;">
    <div class="card" style="max-width:440px; width:100%; border:1px solid #ef4444; box-shadow:0 12px 30px rgba(0,0,0,0.5);">
        <h3 style="margin:0 0 12px; font-size:18px; font-weight:700; color:#ef4444;">
            <i class="fas fa-trash-alt" style="margin-right:8px;"></i> Удалить этот чат?
        </h3>
        <p style="font-size:14px; color:var(--text-muted); line-height:1.5; margin:0 0 20px;">
            Вы уверены, что хотите полностью удалить чат <strong id="deleteChatName" style="color:var(--text-primary);"></strong> и все его сообщения? Это действие необратимо.
        </p>
        <form method="POST" action="/chat.php">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" value="delete_chat">
            <input type="hidden" name="channel_id" id="deleteChatId" value="">
            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="btn btn-secondary" onclick="closeDeleteChatModal()">Отмена</button>
                <button type="submit" class="btn btn-danger" style="background:#ef4444; border-color:#ef4444;">
                    <i class="fas fa-trash"></i> Да, удалить навсегда
                </button>
            </div>
        </form>
    </div>
</div>

<script>
let lastMessageId = <?= $lastMsgId ?? 0 ?>;
const activeChannelKey = <?= json_encode($activeChannelKey) ?>;
const currentUserId = <?= (int)$userId ?>;
const isUserAdmin = <?= $isAdmin ? 'true' : 'false' ?>;
const currentPeriod = <?= json_encode($period) ?>;
const roleBadgesConfig = <?= json_encode($roleBadges) ?>;

function scrollChatToBottom() {
    const box = document.getElementById('chatMessagesBox');
    if (box) {
        box.scrollTop = box.scrollHeight;
    }
}

function scrollToMessage(id) {
    const el = document.getElementById('msg-item-' + id);
    if (el) {
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        el.style.transition = 'background 0.3s';
        const orig = el.style.background;
        el.style.background = 'rgba(99,102,241,0.2)';
        setTimeout(() => { el.style.background = orig; }, 1200);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Ответы на сообщения (Reply)
// ─────────────────────────────────────────────────────────────────────────────
function setReply(msgId, authorName, previewText) {
    document.getElementById('replyToId').value = msgId;
    document.getElementById('replyAuthor').textContent = authorName;
    document.getElementById('replyPreview').textContent = '«' + previewText + '»';
    document.getElementById('replyBox').style.display = 'block';
    document.getElementById('chatInputMessage').focus();
}

function cancelReply() {
    document.getElementById('replyToId').value = '';
    document.getElementById('replyBox').style.display = 'none';
}

// ─────────────────────────────────────────────────────────────────────────────
// Пересылка сообщений (Forward)
// ─────────────────────────────────────────────────────────────────────────────
function setForward(authorName, previewText) {
    const fwdInput = document.getElementById('forwardedFromInput');
    if (fwdInput) fwdInput.value = authorName;
    const fwdAuthor = document.getElementById('forwardAuthor');
    if (fwdAuthor) fwdAuthor.textContent = authorName;
    const fwdPreview = document.getElementById('forwardPreview');
    if (fwdPreview) fwdPreview.textContent = '«' + previewText + '»';
    const fwdBox = document.getElementById('forwardBox');
    if (fwdBox) fwdBox.style.display = 'block';
    cancelReply();
    document.getElementById('chatInputMessage').focus();
}

function cancelForward() {
    const fwdInput = document.getElementById('forwardedFromInput');
    if (fwdInput) fwdInput.value = '';
    const fwdBox = document.getElementById('forwardBox');
    if (fwdBox) fwdBox.style.display = 'none';
}

function handleForwardClick(btn) {
    const parent = btn.closest('.chat-message-item');
    if (!parent) return;
    const author = parent.dataset.author || 'Собеседник';
    const preview = parent.dataset.preview || 'Сообщение';
    setForward(author, preview);
}

// ─────────────────────────────────────────────────────────────────────────────
// Прикрепление файлов
// ─────────────────────────────────────────────────────────────────────────────
function handleFileSelected(input) {
    if (input.files && input.files[0]) {
        const file = input.files[0];
        document.getElementById('fileNameLabel').textContent = file.name + ' (' + Math.round(file.size / 1024) + ' КБ)';
        document.getElementById('filePreviewBox').style.display = 'block';
        document.getElementById('chatInputMessage').removeAttribute('required');
    }
}

function cancelFile() {
    const input = document.getElementById('chatFileInput');
    if (input) input.value = '';
    document.getElementById('filePreviewBox').style.display = 'none';
    document.getElementById('chatInputMessage').setAttribute('required', 'required');
}

// ─────────────────────────────────────────────────────────────────────────────
// Удаление сообщений (У себя / У всех)
// ─────────────────────────────────────────────────────────────────────────────
async function deleteMessage(msgId, mode) {
    const confirmText = (mode === 'for_all') 
        ? 'Удалить это сообщение для всех участников чата?' 
        : 'Удалить это сообщение у себя? Другие участники продолжат его видеть.';
    
    if (!confirm(confirmText)) return;

    const csrf = document.getElementById('chatCsrfToken').value;
    const action = (mode === 'for_all') ? 'delete_for_all' : 'delete_for_me';

    const formData = new FormData();
    formData.append('csrf_token', csrf);
    formData.append('message_id', msgId);

    try {
        const resp = await fetch(`/chat.php?channel=${encodeURIComponent(activeChannelKey)}&action=${action}`, {
            method: 'POST',
            body: formData
        });
        const res = await resp.json();
        if (res.success) {
            const el = document.getElementById('msg-item-' + msgId);
            if (el) {
                el.style.transition = 'opacity 0.25s, transform 0.25s';
                el.style.opacity = '0';
                el.style.transform = 'scale(0.95)';
                setTimeout(() => el.remove(), 250);
            }
        } else {
            alert(res.error || 'Ошибка удаления сообщения');
        }
    } catch (err) {
        console.error('Ошибка:', err);
    }
}

function handleReplyClick(btn) {
    const parent = btn.closest('.chat-message-item');
    if (!parent) return;
    const msgId = parent.dataset.id;
    const author = parent.dataset.author || 'Собеседник';
    const preview = parent.dataset.preview || 'Сообщение';
    setReply(msgId, author, preview);
}

function handleDeleteClick(btn, mode) {
    const parent = btn.closest('.chat-message-item');
    if (!parent) return;
    deleteMessage(parent.dataset.id, mode);
}

// ─────────────────────────────────────────────────────────────────────────────
// Удаление чата
// ─────────────────────────────────────────────────────────────────────────────
function confirmDeleteChat(chanId, chanName) {
    document.getElementById('deleteChatId').value = chanId;
    document.getElementById('deleteChatName').textContent = '«' + chanName + '»';
    document.getElementById('deleteChatModal').style.display = 'flex';
}
function closeDeleteChatModal() {
    document.getElementById('deleteChatModal').style.display = 'none';
}

// ─────────────────────────────────────────────────────────────────────────────
// Отправка сообщений
// ─────────────────────────────────────────────────────────────────────────────
window.addEventListener('DOMContentLoaded', () => {
    scrollChatToBottom();
    
    const textarea = document.getElementById('chatInputMessage');
    if (textarea) {
        textarea.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                document.getElementById('chatForm').dispatchEvent(new Event('submit', { cancelable: true }));
            }
        });
    }

    // Фильтр каналов в боковой панели
    document.getElementById('channelSearchInput')?.addEventListener('input', function() {
        const query = this.value.toLowerCase().trim();
        const links = document.querySelectorAll('#chatChannelList a');
        links.forEach(a => {
            const txt = a.textContent.toLowerCase();
            a.style.display = (!query || txt.includes(query)) ? 'flex' : 'none';
        });
    });
});

// Модальное окно участников и поиска людей для добавления
window.openChatMembersModal = async function(channelId) {
    const modal = document.getElementById('chatMembersModal');
    if (!modal) return;
    modal.style.display = 'flex';
    document.getElementById('memberSearchInput').value = '';
    await loadChannelMembers(channelId);
    await searchUsersForChat('');
};

window.closeChatMembersModal = function() {
    const modal = document.getElementById('chatMembersModal');
    if (modal) modal.style.display = 'none';
};

async function loadChannelMembers(channelId) {
    const box = document.getElementById('chatMembersList');
    if (!box) return;
    box.innerHTML = '<div style="color:var(--text-muted); font-size:12px; text-align:center; padding:12px;"><i class="fas fa-spinner fa-spin"></i> Загрузка участников...</div>';
    try {
        const r = await fetch(`/chat.php?channel=${encodeURIComponent(activeChannelKey)}&action=get_channel_members`);
        const data = await r.json();
        if (data.success && data.members && data.members.length > 0) {
            box.innerHTML = data.members.map(m => `
                <div style="display:flex; align-items:center; justify-content:space-between; padding:8px 12px; background:rgba(255,255,255,0.04); border-radius:8px; margin-bottom:6px;">
                    <div style="display:flex; align-items:center; gap:10px;">
                        <div style="width:32px; height:32px; border-radius:50%; background:linear-gradient(135deg, #6366f1, #4f46e5); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:12px;">
                            ${escapeHtml((m.first_name[0] || '') + (m.last_name[0] || '')).toUpperCase()}
                        </div>
                        <div>
                            <div style="font-size:13px; font-weight:600; color:var(--text-primary);">${escapeHtml(m.first_name + ' ' + m.last_name)}</div>
                            <div style="font-size:11px; color:var(--text-muted);">${escapeHtml(m.email)}</div>
                        </div>
                    </div>
                    <a href="/direct_messages.php?user=${m.id}" class="btn btn-secondary btn-sm" style="font-size:11px; padding:3px 10px;" title="Личное сообщение">
                        <i class="fas fa-comment"></i> В ЛС
                    </a>
                </div>
            `).join('');
        } else {
            box.innerHTML = '<div style="color:var(--text-muted); font-size:12px; text-align:center; padding:12px;">Участники пока не писали в этот канал</div>';
        }
    } catch(e) {
        box.innerHTML = '<div style="color:#ef4444; font-size:12px;">Ошибка загрузки</div>';
    }
}

async function searchUsersForChat(query) {
    const box = document.getElementById('userSearchResults');
    if (!box) return;
    box.innerHTML = '<div style="color:var(--text-muted); font-size:12px; text-align:center; padding:8px;"><i class="fas fa-spinner fa-spin"></i> Поиск...</div>';
    try {
        const r = await fetch(`/chat.php?action=search_users&q=${encodeURIComponent(query)}`);
        const data = await r.json();
        if (data.success && data.users && data.users.length > 0) {
            box.innerHTML = data.users.map(u => `
                <div style="display:flex; align-items:center; justify-content:space-between; padding:8px 12px; background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.06); border-radius:8px; margin-bottom:6px;">
                    <div>
                        <div style="font-size:13px; font-weight:600; color:var(--text-primary);">${escapeHtml(u.first_name + ' ' + u.last_name)}</div>
                        <div style="font-size:11px; color:var(--text-muted);">${escapeHtml(u.email)} · <span style="color:#818cf8;">${escapeHtml(u.role_title || u.role_name)}</span></div>
                    </div>
                    <div style="display:flex; gap:6px;">
                        <button type="button" class="btn btn-success btn-sm" style="font-size:11px; padding:3px 10px; background:#10b981; border:none;" onclick="addUserToChannel(${u.id}, '${escapeHtml(u.first_name + ' ' + u.last_name)}')">
                            <i class="fas fa-plus"></i> В этот чат
                        </button>
                        <a href="/direct_messages.php?user=${u.id}" class="btn btn-primary btn-sm" style="font-size:11px; padding:3px 10px;">
                            <i class="fas fa-paper-plane"></i> В ЛС
                        </a>
                        <button type="button" class="btn btn-secondary btn-sm" style="font-size:11px; padding:3px 10px;" onclick="mentionInChat('${escapeHtml(u.first_name + ' ' + u.last_name)}')">
                            <i class="fas fa-at"></i> @тег
                        </button>
                    </div>
                </div>
            `).join('');
        } else {
            box.innerHTML = '<div style="color:var(--text-muted); font-size:12px; text-align:center; padding:12px;">Пользователи не найдены</div>';
        }
    } catch(e) {
        box.innerHTML = '<div style="color:#ef4444; font-size:12px;">Ошибка поиска</div>';
    }
}

async function addUserToChannel(targetUserId, targetName) {
    const csrf = document.getElementById('chatCsrfToken').value;
    const fd = new FormData();
    fd.append('csrf_token', csrf);
    fd.append('user_id', targetUserId);
    try {
        const r = await fetch(`/chat.php?channel=${encodeURIComponent(activeChannelKey)}&action=add_channel_member`, {
            method: 'POST',
            body: fd
        });
        const d = await r.json();
        if (d.success) {
            closeChatMembersModal();
            fetchNewMessages();
        } else {
            alert(d.error || 'Не удалось добавить');
        }
    } catch(e) {
        alert('Ошибка добавления');
    }
}

function mentionInChat(name) {
    const input = document.getElementById('chatInputMessage');
    if (input) {
        input.value += (input.value ? ' ' : '') + '@' + name + ' ';
        closeChatMembersModal();
        input.focus();
    }
}

async function submitChatMessage(e) {
    if (e) {
        e.preventDefault();
        e.stopPropagation();
    }
    const textarea = document.getElementById('chatInputMessage');
    const msg = textarea.value.trim();
    const fileInput = document.getElementById('chatFileInput');
    const hasFile = (fileInput && fileInput.files && fileInput.files.length > 0);

    if (!msg && !hasFile) return false;

    // Мгновенная очистка поля ввода — никакой задержки и зависания
    textarea.value = '';
    textarea.style.height = 'auto';

    const csrf = document.getElementById('chatCsrfToken').value;
    const replyId = document.getElementById('replyToId').value;
    const fwdFrom = document.getElementById('forwardedFromInput')?.value || '';

    cancelReply();
    cancelForward();
    cancelFile();

    // Оптимистичное добавление пузыря в чат без ожидания ответа
    const box = document.getElementById('chatMessagesBox');
    const notice = document.getElementById('emptyNotice');
    if (notice) notice.remove();

    const tempDiv = document.createElement('div');
    tempDiv.className = 'chat-message-item';
    tempDiv.style.cssText = 'display:flex; flex-direction:column; align-items:flex-end; opacity:0.95;';
    
    let optFwdHtml = fwdFrom ? `<div style="font-size:11px; opacity:0.85; margin-bottom:4px; color:#e0e7ff;"><i class="fas fa-share" style="font-size:10px;"></i> Переслано от ${escapeHtml(fwdFrom)}</div>` : '';
    let optMsgHtml = msg ? `<div>${escapeHtml(msg).replace(/\n/g, '<br>')}</div>` : '';
    let optFileHtml = hasFile ? `<div style="font-size:12px; opacity:0.85; margin-top:4px;"><i class="fas fa-paperclip"></i> Загрузка файла...</div>` : '';

    tempDiv.innerHTML = `
        <div style="display:flex; align-items:center; gap:8px; margin-bottom:4px; font-size:11px; color:var(--text-muted);">
            <span style="font-weight:700; color:var(--primary);">Вы</span>
            <span>•</span>
            <span>сейчас <i class="fas fa-check" style="font-size:10px; opacity:0.6;"></i></span>
        </div>
        <div style="max-width:75%; padding:12px 16px; border-radius:12px; font-size:14px; line-height:1.5; word-break:break-word; background:linear-gradient(135deg, #4f46e5, #6366f1); color:#ffffff; border-bottom-right-radius:2px;">
            ${optFwdHtml}
            ${optMsgHtml}
            ${optFileHtml}
        </div>
    `;
    box.appendChild(tempDiv);
    scrollChatToBottom();

    const formData = new FormData();
    formData.append('csrf_token', csrf);
    formData.append('message', msg);
    if (replyId) formData.append('reply_to_id', replyId);
    if (fwdFrom) formData.append('forwarded_from', fwdFrom);
    if (hasFile) formData.append('chat_file', fileInput.files[0]);

    fetch(`/chat.php?channel=${encodeURIComponent(activeChannelKey)}&action=send_message`, {
        method: 'POST',
        body: formData
    }).then(r => r.json()).then(res => {
        if (res.success) {
            tempDiv.id = 'msg-item-' + res.message_id;
            tempDiv.setAttribute('data-id', res.message_id);
            tempDiv.setAttribute('data-author', 'Вы');
            tempDiv.setAttribute('data-preview', (msg || 'Файл').substring(0, 80));
            lastMessageId = Math.max(lastMessageId, res.message_id);
            fetchNewMessages();
        } else {
            tempDiv.style.border = '1px solid #ef4444';
            alert(res.error || 'Ошибка отправки');
        }
    }).catch(err => {
        console.error('Ошибка отправки:', err);
    });

    textarea.focus();
    return false;
}

async function fetchNewMessages() {
    if (!activeChannelKey || currentPeriod !== 'today') return;

    try {
        const resp = await fetch(`/chat.php?channel=${encodeURIComponent(activeChannelKey)}&action=get_messages&last_id=${lastMessageId}&period=${encodeURIComponent(currentPeriod)}`);
        const res = await resp.json();
        if (res.success && res.messages && res.messages.length > 0) {
            const box = document.getElementById('chatMessagesBox');
            const notice = document.getElementById('emptyNotice');
            if (notice) notice.remove();

            res.messages.forEach(m => {
                if (m.id > lastMessageId) {
                    lastMessageId = m.id;
                    const isMe = (parseInt(m.sender_id) === currentUserId);
                    const rb = roleBadgesConfig[m.role_name] || { title: m.role_name || 'Пользователь', bg: 'rgba(255,255,255,0.1)', color: '#fff', icon: 'fa-user' };
                    const senderName = ((m.first_name || '') + ' ' + (m.last_name || '')).trim() || ('Сотрудник #' + m.sender_id);
                    const canDelAll = (isUserAdmin || isMe);

                    // Проверяем не было ли уже отрисовано оптимистично
                    if (document.getElementById('msg-item-' + m.id)) return;

                    const div = document.createElement('div');
                    div.className = 'chat-message-item';
                    div.id = 'msg-item-' + m.id;
                    div.setAttribute('data-id', m.id);
                    div.setAttribute('data-author', senderName);
                    div.setAttribute('data-preview', (m.message || 'Файл').substring(0, 80));
                    div.style.cssText = `display:flex; flex-direction:column; align-items: ${isMe ? 'flex-end' : 'flex-start'};`;

                    let replyHtml = '';
                    if (m.reply_to_id && m.reply_text) {
                        const rAuthor = ((m.reply_user_first_name || '') + ' ' + (m.reply_user_last_name || '')).trim() || 'Собеседник';
                        replyHtml = `
                            <div class="chat-reply-quote" onclick="scrollToMessage(${parseInt(m.reply_to_id)})">
                                <i class="fas fa-reply" style="margin-right:4px;"></i>
                                <strong>${escapeHtml(rAuthor)}:</strong> ${escapeHtml(m.reply_text.substring(0, 80))}
                            </div>
                        `;
                    }

                    let fwdHtml = '';
                    if (m.forwarded_from) {
                        fwdHtml = `
                            <div style="font-size:11px; opacity:0.85; margin-bottom:4px; display:flex; align-items:center; gap:4px; color:${isMe ? '#e0e7ff' : '#818cf8'};">
                                <i class="fas fa-share" style="font-size:10px;"></i> Переслано от ${escapeHtml(m.forwarded_from)}
                            </div>
                        `;
                    }

                    let fileHtml = '';
                    if (m.file_path) {
                        const isImg = ['png', 'jpg', 'jpeg', 'gif', 'webp'].includes((m.file_type || '').toLowerCase());
                        if (isImg) {
                            fileHtml = `
                                <div style="margin-top:8px;">
                                    <a href="${escapeHtml(m.file_path)}" target="_blank">
                                        <img src="${escapeHtml(m.file_path)}" style="max-width:280px; max-height:220px; border-radius:8px; display:block; border:1px solid rgba(255,255,255,0.2);">
                                    </a>
                                </div>
                            `;
                        } else {
                            fileHtml = `
                                <div style="margin-top:8px;">
                                    <a href="${escapeHtml(m.file_path)}" download="${escapeHtml(m.file_name)}" 
                                       style="display:flex; align-items:center; gap:8px; padding:8px 12px; background:rgba(0,0,0,0.2); border-radius:6px; color:inherit; text-decoration:none; font-size:12px; border:1px solid rgba(255,255,255,0.15);">
                                        <i class="fas fa-file-download" style="font-size:16px;"></i>
                                        <span style="flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                            ${escapeHtml(m.file_name)} (${Math.round((m.file_size || 0)/1024)} КБ)
                                        </span>
                                    </a>
                                </div>
                            `;
                        }
                    }

                    const safeMsg = m.message ? escapeHtml(m.message).replace(/\n/g, '<br>') : '';
                    const safeAuthor = escapeHtml(senderName);

                    div.innerHTML = `
                        <div style="display:flex; align-items:center; gap:8px; margin-bottom:4px; font-size:11px; color:var(--text-muted);">
                            <span style="font-weight:700; color:${isMe ? 'var(--primary)' : 'var(--text-primary)'};">
                                ${safeAuthor}
                            </span>
                            <span class="badge" style="background:${rb.bg}; color:${rb.color}; font-size:10px; padding:2px 6px; font-weight:600;">
                                <i class="fas ${rb.icon}"></i> ${escapeHtml(rb.title)}
                            </span>
                            <span>•</span>
                            <span>${formatTime(m.created_at)}</span>
                        </div>
                        <div style="max-width:75%; padding:12px 16px; border-radius:12px; font-size:14px; line-height:1.5; word-break:break-word;
                            ${isMe 
                                ? 'background:linear-gradient(135deg, #4f46e5, #6366f1); color:#ffffff; border-bottom-right-radius:2px;' 
                                : 'background:var(--bg-input); color:var(--text-primary); border:1px solid var(--border-color); border-bottom-left-radius:2px;' 
                            }">
                            ${fwdHtml}
                            ${replyHtml}
                            ${safeMsg ? `<div>${safeMsg}</div>` : ''}
                            ${fileHtml}
                        </div>
                        <div class="chat-msg-actions">
                            <button type="button" class="btn-msg-action" onclick="handleReplyClick(this)">
                                <i class="fas fa-reply"></i> Ответить
                            </button>
                            <button type="button" class="btn-msg-action" onclick="handleForwardClick(this)">
                                <i class="fas fa-share"></i> Переслать
                            </button>
                            <button type="button" class="btn-msg-action" onclick="handleDeleteClick(this, 'for_me')" title="Скрыть у себя">
                                <i class="fas fa-eye-slash"></i> У себя
                            </button>
                            ${canDelAll ? `
                                <button type="button" class="btn-msg-action" onclick="handleDeleteClick(this, 'for_all')" style="color:#f87171;" title="Удалить у всех">
                                    <i class="fas fa-trash-alt"></i> У всех
                                </button>
                            ` : ''}
                        </div>
                    `;

                    box.appendChild(div);
                }
            });

            scrollChatToBottom();
        }
    } catch (err) {
        console.error('Ошибка поллинга сообщений:', err);
    }
}

function escapeHtml(text) {
    if (!text) return '';
    const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
    return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
}

function formatTime(str) {
    if (!str) return '';
    const d = new Date(str.replace(' ', 'T'));
    const h = String(d.getHours()).padStart(2, '0');
    const m = String(d.getMinutes()).padStart(2, '0');
    return `${h}:${m}`;
}

function openCreateChatModal() {
    document.getElementById('createChatModal').style.display = 'flex';
}
function closeCreateChatModal() {
    document.getElementById('createChatModal').style.display = 'none';
}

function openComplaintModal() {
    document.getElementById('complaintModal').style.display = 'flex';
}
function closeComplaintModal() {
    document.getElementById('complaintModal').style.display = 'none';
}
</script>

<!-- Модальное окно создания чата -->
<div id="createChatModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7); z-index:9999; justify-content:center; align-items:center; padding:16px;">
    <div class="card" style="max-width:480px; width:100%; border:1px solid var(--border-color); box-shadow:0 12px 30px rgba(0,0,0,0.5);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
            <h3 style="margin:0; font-size:18px; font-weight:700;">
                <i class="fas fa-plus-circle" style="color:var(--primary); margin-right:8px;"></i> Создать новый чат
            </h3>
            <button type="button" onclick="closeCreateChatModal()" style="background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:18px;">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <form method="POST" action="/chat.php">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" value="create_chat">

            <div class="form-group" style="margin-bottom:14px;">
                <label style="display:block; margin-bottom:6px; font-weight:600; font-size:13px;">Название чата *</label>
                <input type="text" name="chat_name" class="form-control" placeholder="Например: Смена бара, Кухня, Маркетинг..." required>
            </div>

            <div class="form-group" style="margin-bottom:14px;">
                <label style="display:block; margin-bottom:6px; font-weight:600; font-size:13px;">Тип канала</label>
                <select name="chat_type" class="form-control">
                    <option value="general">👥 Рабочая группа / Общий чат</option>
                    <?php if ($userOrg): ?>
                        <option value="org_management">🏢 Внутренний чат компании «<?= htmlspecialchars($userOrg['name']) ?>»</option>
                    <?php endif; ?>
                </select>
            </div>

            <div class="form-group" style="margin-bottom:20px;">
                <label style="display:block; margin-bottom:6px; font-weight:600; font-size:13px;">Описание чата</label>
                <textarea name="chat_desc" class="form-control" rows="2" placeholder="Для каких вопросов и задач создается группа?"></textarea>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="btn btn-secondary" onclick="closeCreateChatModal()">Отмена</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-check"></i> Создать чат
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Модальное окно подачи жалобы / баг-репорта -->
<div id="complaintModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7); z-index:9999; justify-content:center; align-items:center; padding:16px;">
    <div class="card" style="max-width:500px; width:100%; border:1px solid #ef4444; box-shadow:0 12px 30px rgba(0,0,0,0.5);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
            <h3 style="margin:0; font-size:18px; font-weight:700; color:#ef4444;">
                <i class="fas fa-exclamation-triangle" style="margin-right:8px;"></i> Подать жалобу / Сообщить о баге
            </h3>
            <button type="button" onclick="closeComplaintModal()" style="background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:18px;">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <form method="POST" action="/chat.php">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" value="submit_complaint">

            <div class="form-group" style="margin-bottom:14px;">
                <label style="display:block; margin-bottom:6px; font-weight:600; font-size:13px;">Категория обращения *</label>
                <select name="complaint_category" class="form-control" required>
                    <option value="bar">🍸 Жалоба на бар / обслуживание / напитки</option>
                    <option value="cashier">💳 Жалоба на кассу / POS-терминал / чек</option>
                    <option value="bug">🐞 Техническая ошибка / баг на сайте</option>
                    <option value="payment">💰 Вопрос по оплате или тарифу</option>
                    <option value="staff">👤 Жалоба на действия персонала</option>
                    <option value="other">📋 Другой вопрос / жалоба</option>
                </select>
            </div>

            <div class="form-group" style="margin-bottom:14px;">
                <label style="display:block; margin-bottom:6px; font-weight:600; font-size:13px;">Тема обращения *</label>
                <input type="text" name="complaint_title" class="form-control" placeholder="Кратко суть проблемы..." required>
            </div>

            <div class="form-group" style="margin-bottom:14px;">
                <label style="display:block; margin-bottom:6px; font-weight:600; font-size:13px;">Срочность</label>
                <select name="complaint_severity" class="form-control">
                    <option value="low">🟢 Обычная</option>
                    <option value="medium" selected>🟡 Средняя</option>
                    <option value="high">🟠 Высокая</option>
                    <option value="critical">🔴 Критическая (Срочно руководству)</option>
                </select>
            </div>

            <div class="form-group" style="margin-bottom:20px;">
                <label style="display:block; margin-bottom:6px; font-weight:600; font-size:13px;">Подробное описание ситуации *</label>
                <textarea name="complaint_desc" class="form-control" rows="4" placeholder="Опишите, что произошло, время, номер чека или скриншот ошибки..." required></textarea>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="btn btn-secondary" onclick="closeComplaintModal()">Отмена</button>
                <button type="submit" class="btn btn-primary" style="background:#ef4444; border-color:#ef4444;">
                    <i class="fas fa-paper-plane"></i> Отправить обращение
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Модальное окно участников чата и поиска людей -->
<div id="chatMembersModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7); z-index:9999; justify-content:center; align-items:center; padding:16px;">
    <div class="card" style="max-width:540px; width:100%; border:1px solid var(--border-color); box-shadow:0 12px 35px rgba(0,0,0,0.6); max-height:85vh; display:flex; flex-direction:column;">
        <div style="display:flex; justify-content:space-between; align-items:center; padding-bottom:14px; border-bottom:1px solid var(--border-color);">
            <h3 style="margin:0; font-size:17px; font-weight:700;">
                <i class="fas fa-users" style="color:var(--primary); margin-right:8px;"></i> Участники и поиск людей
            </h3>
            <button type="button" onclick="closeChatMembersModal()" style="background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:18px;">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div style="padding:14px 0 8px;">
            <div style="font-size:12px; font-weight:700; color:var(--text-muted); text-transform:uppercase; margin-bottom:6px;">
                Найти сотрудника / пользователя:
            </div>
            <div style="position:relative;">
                <input type="text" id="memberSearchInput" class="form-control" placeholder="Введите имя, фамилию или email..." oninput="searchUsersForChat(this.value)">
                <i class="fas fa-search" style="position:absolute; right:12px; top:50%; transform:translateY(-50%); color:var(--text-muted);"></i>
            </div>
        </div>

        <div id="userSearchResults" style="max-height:180px; overflow-y:auto; margin-bottom:14px; padding-right:4px;">
            <!-- Результаты поиска пользователей -->
        </div>

        <div style="font-size:12px; font-weight:700; color:var(--text-muted); text-transform:uppercase; margin-bottom:6px; border-top:1px solid var(--border-color); padding-top:12px;">
            Активные авторы в этом канале:
        </div>
        <div id="chatMembersList" style="flex:1; max-height:220px; overflow-y:auto; padding-right:4px;">
            <!-- Список участников канала -->
        </div>

        <div style="display:flex; justify-content:flex-end; padding-top:14px; border-top:1px solid var(--border-color);">
            <button type="button" class="btn btn-secondary" onclick="closeChatMembersModal()">Закрыть</button>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
