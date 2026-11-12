<?php
declare(strict_types=1);

/**
 * AI Business Assistant - User CRM / Clients Section with Interaction History & Call Outcomes
 */

require_once dirname(__DIR__) . '/includes/Auth.php';
require_once dirname(__DIR__) . '/includes/Database.php';
require_once dirname(__DIR__) . '/includes/Security.php';

$user = Auth::requireAuth('/login.php');
$userId = (int)$user['id'];
$isStaffUser = Auth::isStaff() ? 1 : 0;
$pdo = Database::getConnection();

// Автоматическая проверка и создание таблицы истории и колонок, если отсутствуют
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS client_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_id INT NOT NULL,
            user_id INT NOT NULL,
            result_type VARCHAR(50) NOT NULL,
            result_label VARCHAR(100) NOT NULL,
            comment TEXT NULL,
            next_contact_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ch_client (client_id),
            INDEX idx_ch_created (created_at),
            CONSTRAINT fk_ch_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE,
            CONSTRAINT fk_ch_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $cCols = $pdo->query("SHOW COLUMNS FROM clients")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('assigned_to', $cCols)) {
        $pdo->exec("ALTER TABLE clients ADD COLUMN assigned_to INT NULL AFTER user_id, ADD INDEX idx_cl_assigned (assigned_to)");
    }
} catch (\Throwable $e) {}

// Список всех сотрудников/менеджеров для назначения
$allManagers = [];
try {
    $mStmt = $pdo->query("SELECT id, first_name, last_name, email FROM users WHERE status = 'active' ORDER BY first_name ASC");
    $allManagers = $mStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {}

// Определение статусов клиента
$statusList = [
    'lead'        => ['label' => 'Лид (Новый)',            'badge' => 'badge-primary',   'color' => '#6366f1', 'icon' => 'fa-user-tag'],
    'negotiation' => ['label' => 'Переговоры',             'badge' => 'badge-info',      'color' => '#06b6d4', 'icon' => 'fa-comments'],
    'callback'    => ['label' => 'Перезвонить',            'badge' => 'badge-warning',   'color' => '#f59e0b', 'icon' => 'fa-calendar-alt'],
    'called'      => ['label' => '📞 Звонок совершён',     'badge' => 'badge-success',   'color' => '#10b981', 'icon' => 'fa-phone-volume'],
    'ndz'         => ['label' => 'НДЗ (Недозвон)',         'badge' => 'badge-danger',    'color' => '#ef4444', 'icon' => 'fa-phone-slash'],
    'rzn'         => ['label' => 'РЗН (Ничем)',            'badge' => 'badge-secondary', 'color' => '#6b7280', 'icon' => 'fa-comment-slash'],
    'deal'        => ['label' => 'Сделка / Клиент',        'badge' => 'badge-success',   'color' => '#10b981', 'icon' => 'fa-check-circle'],
    'refusal'     => ['label' => '❌ Отказ',               'badge' => 'badge-danger',    'color' => '#ef4444', 'icon' => 'fa-ban'],
    'archived'    => ['label' => 'В архиве',               'badge' => 'badge-dark',      'color' => '#4b5563', 'icon' => 'fa-archive'],
];

// Результаты связи для фиксации звонков / контактов
$resultTypes = [
    'called'      => ['label' => '📞 Звонок совершён',             'status' => 'called',      'color' => '#10b981', 'icon' => 'fa-phone-volume'],
    'ndz'         => ['label' => 'НДЗ (Не дозвонился)',            'status' => 'ndz',         'color' => '#ef4444', 'icon' => 'fa-phone-slash'],
    'rzn'         => ['label' => 'РЗН (Разговор закончен ничем)', 'status' => 'rzn',         'color' => '#6b7280', 'icon' => 'fa-comment-slash'],
    'callback'    => ['label' => 'Перезвонить (Назначить дату)',   'status' => 'callback',    'color' => '#f59e0b', 'icon' => 'fa-calendar-alt'],
    'refusal'     => ['label' => '❌ ОТКАЗ (Клиент отказался)',    'status' => 'refusal',     'color' => '#ef4444', 'icon' => 'fa-ban'],
    'interested'  => ['label' => 'Заинтересован / Переговоры',     'status' => 'negotiation', 'color' => '#06b6d4', 'icon' => 'fa-thumbs-up'],
    'deal'        => ['label' => 'Успешная сделка / Оплата',       'status' => 'deal',        'color' => '#10b981', 'icon' => 'fa-check-double'],
    'note'        => ['label' => 'Обычная заметка',                'status' => null,          'color' => '#8b5cf6', 'icon' => 'fa-sticky-note'],
];

// Обработка AJAX запроса на получение истории по клиенту
if (isset($_GET['action']) && $_GET['action'] === 'get_history') {
    header('Content-Type: application/json; charset=utf-8');
    $cId = (int)($_GET['client_id'] ?? 0);
    $chk = $pdo->prepare("
        SELECT c.*, 
               COALESCE(u_assigned.first_name, u_owner.first_name) as manager_first_name,
               COALESCE(u_assigned.last_name, u_owner.last_name) as manager_last_name,
               COALESCE(u_assigned.email, u_owner.email) as manager_email
        FROM clients c
        LEFT JOIN users u_owner ON c.user_id = u_owner.id
        LEFT JOIN users u_assigned ON c.assigned_to = u_assigned.id
        WHERE c.id = ? AND (c.user_id = ? OR c.assigned_to = ? OR ? = 1)
    ");
    $chk->execute([$cId, $userId, $userId, $isStaffUser]);
    $clientData = $chk->fetch(PDO::FETCH_ASSOC);

    if (!$clientData) {
        echo json_encode(['success' => false, 'message' => 'Клиент не найден']);
        exit;
    }

    $mName = trim(($clientData['manager_first_name'] ?? '') . ' ' . ($clientData['manager_last_name'] ?? ''));
    $clientData['manager_name'] = !empty($mName) ? $mName : ($clientData['manager_email'] ?? 'Не назначен');

    $hStmt = $pdo->prepare("
        SELECT h.*, u.first_name, u.last_name 
        FROM client_history h
        LEFT JOIN users u ON h.user_id = u.id
        WHERE h.client_id = ?
        ORDER BY h.created_at DESC, h.id DESC
    ");
    $hStmt->execute([$cId]);
    $history = $hStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'client' => $clientData,
        'history' => $history
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$error = null;
$success = null;

// Обработка POST-действий (создание, редактирование, результат связи, удаление)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!Security::verifyCsrf($token)) {
        $error = "Недействительный токен безопасности. Повторите отправку.";
    } else {
        $action = $_POST['action'] ?? '';

        // 1. Создание нового клиента
        if ($action === 'create') {
            $name = trim($_POST['name'] ?? '');
            $companyName = trim($_POST['company_name'] ?? '');
            $position = trim($_POST['position'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $email = trim(mb_strtolower($_POST['email'] ?? ''));
            $status = $_POST['status'] ?? 'lead';
            if (!isset($statusList[$status])) $status = 'lead';
            $assignedTo = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : $userId;
            $dealSum = (float)str_replace([' ', ','], ['', '.'], $_POST['deal_sum'] ?? '0');
            $nextContactAt = !empty($_POST['next_contact_at']) ? str_replace('T', ' ', $_POST['next_contact_at']) : null;
            $notes = trim($_POST['notes'] ?? '');

            if (empty($name)) {
                $error = "Укажите имя или контактное лицо клиента.";
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO clients (user_id, assigned_to, name, company_name, position, phone, email, status, next_contact_at, deal_sum, notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$userId, $assignedTo, $name, $companyName, $position, $phone, $email, $status, $nextContactAt, $dealSum, $notes]);
                $newClientId = (int)$pdo->lastInsertId();

                // Добавляем запись в историю о создании
                $hIns = $pdo->prepare("
                    INSERT INTO client_history (client_id, user_id, result_type, result_label, comment, next_contact_at)
                    VALUES (?, ?, 'note', 'Создание карточки клиента', ?, ?)
                ");
                $initComment = "Клиент добавлен в CRM. Статус: " . ($statusList[$status]['label'] ?? $status);
                if (!empty($notes)) $initComment .= "\nЗаметка: " . $notes;
                $hIns->execute([$newClientId, $userId, $initComment, $nextContactAt]);

                $success = "Клиент «" . htmlspecialchars($name) . "» успешно добавлен в CRM!";
            }

        // 2. Редактирование всех параметров клиента
        } elseif ($action === 'update') {
            $clientId = (int)($_POST['client_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $companyName = trim($_POST['company_name'] ?? '');
            $position = trim($_POST['position'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $email = trim(mb_strtolower($_POST['email'] ?? ''));
            $status = $_POST['status'] ?? 'lead';
            if (!isset($statusList[$status])) $status = 'lead';
            $assignedTo = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : $userId;
            $dealSum = (float)str_replace([' ', ','], ['', '.'], $_POST['deal_sum'] ?? '0');
            $nextContactAt = !empty($_POST['next_contact_at']) ? str_replace('T', ' ', $_POST['next_contact_at']) : null;
            $notes = trim($_POST['notes'] ?? '');

            if (empty($name)) {
                $error = "Имя клиента не может быть пустым.";
            } else {
                $upd = $pdo->prepare("
                    UPDATE clients SET
                        name = ?,
                        company_name = ?,
                        position = ?,
                        phone = ?,
                        email = ?,
                        status = ?,
                        assigned_to = ?,
                        deal_sum = ?,
                        next_contact_at = ?,
                        notes = ?
                    WHERE id = ? AND (user_id = ? OR assigned_to = ? OR ? = 1)
                ");
                $upd->execute([$name, $companyName, $position, $phone, $email, $status, $assignedTo, $dealSum, $nextContactAt, $notes, $clientId, $userId, $userId, $isStaffUser]);

                // Фиксируем изменение в истории
                $hIns = $pdo->prepare("
                    INSERT INTO client_history (client_id, user_id, result_type, result_label, comment, next_contact_at)
                    VALUES (?, ?, 'note', 'Изменение параметров клиента', ?, ?)
                ");
                $hIns->execute([$clientId, $userId, "Данные клиента отредактированы. Статус: " . ($statusList[$status]['label'] ?? $status), $nextContactAt]);

                $success = "Данные клиента успешно сохранены!";
            }

        // 3. Добавление результата связи / звонка (НДЗ, РЗН, Перезвонить, ОТКАЗ и т.д.)
        } elseif ($action === 'add_history') {
            $clientId = (int)($_POST['client_id'] ?? 0);
            $resultType = $_POST['result_type'] ?? 'note';
            $comment = trim($_POST['comment'] ?? '');
            $nextContactAt = !empty($_POST['next_contact_at']) ? str_replace('T', ' ', $_POST['next_contact_at']) : null;
            $updateStatus = isset($_POST['update_client_status']) && $_POST['update_client_status'] === '1';
            $manualStatus = $_POST['new_status'] ?? '';

            if (!isset($resultTypes[$resultType])) {
                $resultType = 'note';
            }
            $resultDef = $resultTypes[$resultType];
            $resultLabel = $resultDef['label'];

            // Проверяем принадлежность клиента
            $chk = $pdo->prepare("SELECT id, name, status FROM clients WHERE id = ? AND (user_id = ? OR assigned_to = ? OR ? = 1)");
            $chk->execute([$clientId, $userId, $userId, $isStaffUser]);
            $clientRow = $chk->fetch(PDO::FETCH_ASSOC);

            if (!$clientRow) {
                $error = "Клиент не найден или у вас нет доступа.";
            } else {
                // Добавляем запись в таблицу истории
                $hIns = $pdo->prepare("
                    INSERT INTO client_history (client_id, user_id, result_type, result_label, comment, next_contact_at)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $hIns->execute([$clientId, $userId, $resultType, $resultLabel, $comment, $nextContactAt]);

                // Определяем новый статус клиента
                $targetStatus = $clientRow['status'];
                if ($updateStatus) {
                    if (!empty($manualStatus) && isset($statusList[$manualStatus])) {
                        $targetStatus = $manualStatus;
                    } elseif ($resultDef['status']) {
                        $targetStatus = $resultDef['status'];
                    }
                }

                // Обновляем карточку клиента
                $updClient = $pdo->prepare("
                    UPDATE clients SET
                        last_contact_at = NOW(),
                        last_result = ?,
                        next_contact_at = ?,
                        status = ?
                    WHERE id = ? AND (user_id = ? OR assigned_to = ? OR ? = 1)
                ");
                $updClient->execute([$resultLabel, $nextContactAt, $targetStatus, $clientId, $userId, $userId, $isStaffUser]);

                $success = "Результат связи «{$resultLabel}» зафиксирован в истории клиента!";
            }

        // 4. Удаление клиента
        } elseif ($action === 'delete') {
            $clientId = (int)($_POST['client_id'] ?? 0);
            $del = $pdo->prepare("DELETE FROM clients WHERE id = ? AND (user_id = ? OR assigned_to = ? OR ? = 1)");
            $del->execute([$clientId, $userId, $userId, $isStaffUser]);
            $success = "Клиент и вся история взаимодействия успешно удалены.";

        // 5. Удаление записи из истории
        } elseif ($action === 'delete_history') {
            $historyId = (int)($_POST['history_id'] ?? 0);
            $delH = $pdo->prepare("DELETE FROM client_history WHERE id = ? AND user_id = ?");
            $delH->execute([$historyId, $userId]);
            $success = "Запись удалена из истории.";
        }
    }
}

// Фильтры и поиск
$filterStatus = $_GET['status'] ?? 'all';
$filterCallsToday = isset($_GET['calls_today']) && $_GET['calls_today'] === '1';
$searchQuery = trim($_GET['search'] ?? '');

$sql = "
    SELECT c.*, 
           COALESCE(u_assigned.first_name, u_owner.first_name) as manager_first_name,
           COALESCE(u_assigned.last_name, u_owner.last_name) as manager_last_name,
           COALESCE(u_assigned.email, u_owner.email) as manager_email,
           (SELECT COUNT(*) FROM client_history ch WHERE ch.client_id = c.id) as history_count
    FROM clients c 
    LEFT JOIN users u_owner ON c.user_id = u_owner.id
    LEFT JOIN users u_assigned ON c.assigned_to = u_assigned.id
    WHERE (c.user_id = ? OR c.assigned_to = ? OR ? = 1)
";
$params = [$userId, $userId, $isStaffUser];

if ($filterStatus !== 'all' && isset($statusList[$filterStatus])) {
    $sql .= " AND c.status = ?";
    $params[] = $filterStatus;
}

if ($filterCallsToday) {
    // Звонки на сегодня или просроченные
    $sql .= " AND c.next_contact_at IS NOT NULL AND c.next_contact_at <= DATE_ADD(CURDATE(), INTERVAL 1 DAY)";
}

if (!empty($searchQuery)) {
    $sql .= " AND (c.name LIKE ? OR c.company_name LIKE ? OR c.phone LIKE ? OR c.email LIKE ? OR c.notes LIKE ?)";
    $like = '%' . $searchQuery . '%';
    $params = array_merge($params, [$like, $like, $like, $like, $like]);
}

$sql .= " ORDER BY (CASE WHEN c.status = 'callback' AND c.next_contact_at IS NOT NULL THEN 0 ELSE 1 END), c.next_contact_at ASC, c.id DESC";

$clientsStmt = $pdo->prepare($sql);
$clientsStmt->execute($params);
$clients = $clientsStmt->fetchAll(PDO::FETCH_ASSOC);

// Статистика для карточек сверху
$statsStmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_count,
        SUM(CASE WHEN status = 'called' THEN 1 ELSE 0 END) as called_count,
        SUM(CASE WHEN status = 'callback' THEN 1 ELSE 0 END) as callback_count,
        SUM(CASE WHEN status = 'ndz' THEN 1 ELSE 0 END) as ndz_count,
        SUM(CASE WHEN status = 'rzn' THEN 1 ELSE 0 END) as rzn_count,
        SUM(CASE WHEN status = 'refusal' THEN 1 ELSE 0 END) as refusal_count,
        SUM(CASE WHEN status = 'deal' THEN 1 ELSE 0 END) as deal_count,
        SUM(CASE WHEN status = 'callback' AND next_contact_at IS NOT NULL AND next_contact_at < NOW() THEN 1 ELSE 0 END) as overdue_callbacks,
        SUM(CASE WHEN status = 'callback' AND next_contact_at IS NOT NULL AND DATE(next_contact_at) = CURDATE() THEN 1 ELSE 0 END) as today_callbacks,
        SUM(COALESCE(deal_sum, 0)) as total_deal_sum
    FROM clients 
    WHERE (user_id = ? OR assigned_to = ? OR ? = 1)
");
$statsStmt->execute([$userId, $userId, $isStaffUser]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [
    'total_count' => 0, 'called_count' => 0, 'callback_count' => 0, 'ndz_count' => 0, 'rzn_count' => 0, 'refusal_count' => 0, 'deal_count' => 0,
    'overdue_callbacks' => 0, 'today_callbacks' => 0, 'total_deal_sum' => 0
];

$pageTitle = 'Ведение клиентов (CRM) | AI Business Assistant';
require dirname(__DIR__) . '/includes/header.php';
?>

<style>
/* Стили для CRM метрик и бейджей */
.crm-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}
.crm-stat-card {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    padding: 18px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    transition: transform 0.2s, box-shadow 0.2s;
}
.crm-stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.2);
}
.crm-stat-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
}
.crm-badge-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    white-space: nowrap;
}
.crm-timeline {
    position: relative;
    padding-left: 28px;
    margin-top: 16px;
}
.crm-timeline::before {
    content: '';
    position: absolute;
    left: 10px;
    top: 5px;
    bottom: 5px;
    width: 2px;
    background: var(--border-color);
}
.crm-timeline-item {
    position: relative;
    margin-bottom: 20px;
}
.crm-timeline-item:last-child {
    margin-bottom: 0;
}
.crm-timeline-dot {
    position: absolute;
    left: -28px;
    top: 4px;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background: var(--bg-card);
    border: 3px solid var(--primary);
    display: flex;
    align-items: center;
    justify-content: center;
}
.crm-timeline-box {
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 12px 16px;
}
.quick-date-btn {
    padding: 4px 10px;
    font-size: 12px;
    border-radius: 6px;
    background: rgba(255,255,255,0.06);
    border: 1px solid var(--border-color);
    color: var(--text-primary);
    cursor: pointer;
    transition: background 0.15s;
}
.quick-date-btn:hover {
    background: var(--primary);
    color: #fff;
    border-color: var(--primary);
}

/* ==========================================================================
   Таблица CRM: компактность, идеальная компоновка и скрытие уродливых полос
   ========================================================================== */
.table-responsive {
    overflow-x: auto;
    border-radius: var(--radius-md);
    border: 1px solid var(--border-color);
    background: var(--bg-card);
    scrollbar-width: none !important; /* Firefox */
    -ms-overflow-style: none !important; /* IE / Edge */
}
.table-responsive::-webkit-scrollbar {
    display: none !important; /* Chrome, Edge, Safari */
    width: 0 !important;
    height: 0 !important;
}
.crm-table {
    width: 100%;
    margin-bottom: 0;
    border-collapse: collapse;
}
.crm-table th {
    padding: 12px 14px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--text-muted);
    border-bottom: 1px solid var(--border-color);
    background: rgba(255, 255, 255, 0.02);
    white-space: nowrap;
}
.crm-table td {
    padding: 12px 14px;
    vertical-align: middle;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
}
.crm-table tr:hover td {
    background: rgba(255, 255, 255, 0.02);
}

/* ==========================================================================
   Универсальные стили модальных окон: ИДЕАЛЬНОЕ ВЫРАВНИВАНИЕ ПО ЦЕНТРУ ЭКРАНА
   ========================================================================== */
.crm-modal-backdrop {
    display: none;
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    right: 0 !important;
    bottom: 0 !important;
    width: 100vw !important;
    height: 100vh !important;
    background: rgba(3, 7, 18, 0.85) !important;
    backdrop-filter: blur(8px) !important;
    -webkit-backdrop-filter: blur(8px) !important;
    z-index: 9999999 !important;
    align-items: center !important;
    justify-content: center !important;
    padding: 20px !important;
    box-sizing: border-box !important;
    overflow: hidden !important;
}

.crm-modal-backdrop.show,
.crm-modal-backdrop[style*="display: flex"],
.crm-modal-backdrop[style*="display:flex"],
.crm-modal-backdrop[style*="display: block"],
.crm-modal-backdrop[style*="display:block"] {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
}

/* Карточка модального окна (ВСЕГДА ПО ЦЕНТРУ, ОГРАНИЧЕНА ПО ВЫСОТЕ) */
.crm-modal-card {
    position: relative !important;
    margin: auto !important; /* Строго по центру */
    width: 100% !important;
    max-height: 86vh !important;
    display: flex !important;
    flex-direction: column !important;
    background: #0f172a !important;
    border: 1px solid rgba(255, 255, 255, 0.14) !important;
    border-radius: 20px !important;
    box-shadow: 0 30px 80px rgba(0, 0, 0, 0.95), 0 0 35px rgba(99, 102, 241, 0.25) !important;
    box-sizing: border-box !important;
    overflow: hidden !important;
}

/* Фиксированный заголовок модального окна */
.crm-modal-header {
    flex-shrink: 0 !important;
    padding: 18px 24px 14px 24px !important;
    border-bottom: 1px solid rgba(255, 255, 255, 0.08) !important;
    display: flex !important;
    justify-content: space-between !important;
    align-items: center !important;
}

/* Форма как вертикальный flex контейнер */
.crm-modal-form {
    display: flex !important;
    flex-direction: column !important;
    flex: 1 1 auto !important;
    min-height: 0 !important;
    overflow: hidden !important;
    margin: 0 !important;
}

/* СКРОЛЛИТСЯ ТОЛЬКО ЭТА ЧАСТЬ (ТЕЛО ФОРМЫ / ИСТОРИЯ) */
.crm-modal-body {
    flex: 1 1 auto !important;
    min-height: 0 !important;
    overflow-y: auto !important; /* СКРОЛЛИТСЯ ТОЛЬКО ВНУТРЕННИЙ СПИСОК */
    overflow-x: hidden !important;
    padding: 20px 24px !important;
}

.crm-modal-body::-webkit-scrollbar {
    width: 6px;
}
.crm-modal-body::-webkit-scrollbar-track {
    background: rgba(255, 255, 255, 0.03);
    border-radius: 4px;
}
.crm-modal-body::-webkit-scrollbar-thumb {
    background: rgba(99, 102, 241, 0.5);
    border-radius: 4px;
}
.crm-modal-body::-webkit-scrollbar-thumb:hover {
    background: rgba(99, 102, 241, 0.85);
}

/* Фиксированный подвал с кнопками */
.crm-modal-footer {
    flex-shrink: 0 !important;
    padding: 14px 24px 18px 24px !important;
    border-top: 1px solid rgba(255, 255, 255, 0.08) !important;
    display: flex !important;
    justify-content: flex-end !important;
    gap: 12px !important;
    background: #0f172a !important;
}
</style>

<!-- Заголовок и главная кнопка -->
<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:16px;">
    <div>
        <h2 style="margin:0 0 4px 0; display:flex; align-items:center; gap:10px;">
            <i class="fas fa-users-cog" style="color:var(--primary);"></i> Ведение клиентов (CRM)
        </h2>
        <p class="text-muted" style="margin:0; font-size:14px;">Управление воронкой продаж, фиксация звонков, НДЗ, РЗН, отказов и планирование связей</p>
    </div>
    <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <button class="btn btn-primary" onclick="openNewClientModal()">
            <i class="fas fa-user-plus"></i> Добавить клиента
        </button>
    </div>
</div>

<?php if ($success): ?>
    <div class="alert alert-success" style="margin-bottom:20px;"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger" style="margin-bottom:20px;"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Карточки ключевых показателей CRM -->
<div class="crm-stats-grid">
    <div class="crm-stat-card">
        <div>
            <div class="stat-title">Всего клиентов</div>
            <div class="stat-value"><?= (int)$stats['total_count'] ?></div>
            <small class="text-muted">в базе CRM</small>
        </div>
        <div class="crm-stat-icon" style="background:rgba(99, 102, 241, 0.15); color:#818cf8;">
            <i class="fas fa-address-book"></i>
        </div>
    </div>

    <div class="crm-stat-card">
        <div>
            <div class="stat-title">Звонок совершён</div>
            <div class="stat-value" style="color:#10b981;"><?= (int)($stats['called_count'] ?? 0) ?></div>
            <small class="text-muted">успешные контакты</small>
        </div>
        <div class="crm-stat-icon" style="background:rgba(16, 185, 129, 0.15); color:#10b981;">
            <i class="fas fa-phone-volume"></i>
        </div>
    </div>

    <div class="crm-stat-card" style="<?= ((int)$stats['overdue_callbacks'] > 0) ? 'border-color:#ef4444;' : '' ?>">
        <div>
            <div class="stat-title">Перезвонить</div>
            <div class="stat-value" style="color:#f59e0b;"><?= (int)$stats['callback_count'] ?></div>
            <?php if ((int)$stats['overdue_callbacks'] > 0): ?>
                <span class="badge" style="background:#ef4444; color:#fff; font-size:11px;">⚠️ Просрочено: <?= (int)$stats['overdue_callbacks'] ?></span>
            <?php elseif ((int)$stats['today_callbacks'] > 0): ?>
                <span class="badge" style="background:#f59e0b; color:#fff; font-size:11px;">🔔 На сегодня: <?= (int)$stats['today_callbacks'] ?></span>
            <?php else: ?>
                <small class="text-muted">запланированные контакты</small>
            <?php endif; ?>
        </div>
        <div class="crm-stat-icon" style="background:rgba(245, 158, 11, 0.15); color:#f59e0b;">
            <i class="fas fa-phone-volume"></i>
        </div>
    </div>

    <div class="crm-stat-card">
        <div>
            <div class="stat-title">НДЗ (Недозвоны)</div>
            <div class="stat-value" style="color:#ef4444;"><?= (int)$stats['ndz_count'] ?></div>
            <small class="text-muted">требуют повторного набора</small>
        </div>
        <div class="crm-stat-icon" style="background:rgba(239, 68, 68, 0.15); color:#ef4444;">
            <i class="fas fa-phone-slash"></i>
        </div>
    </div>

    <div class="crm-stat-card">
        <div>
            <div class="stat-title">РЗН (Ничем)</div>
            <div class="stat-value" style="color:#9ca3af;"><?= (int)$stats['rzn_count'] ?></div>
            <small class="text-muted">разговор без результата</small>
        </div>
        <div class="crm-stat-icon" style="background:rgba(156, 163, 175, 0.15); color:#9ca3af;">
            <i class="fas fa-comment-slash"></i>
        </div>
    </div>

    <div class="crm-stat-card">
        <div>
            <div class="stat-title">Отказы</div>
            <div class="stat-value" style="color:#ef4444;"><?= (int)$stats['refusal_count'] ?></div>
            <small class="text-muted">отказались от услуг</small>
        </div>
        <div class="crm-stat-icon" style="background:rgba(239, 68, 68, 0.15); color:#ef4444;">
            <i class="fas fa-ban"></i>
        </div>
    </div>

    <div class="crm-stat-card">
        <div>
            <div class="stat-title">Сделки</div>
            <div class="stat-value" style="color:#10b981;"><?= (int)$stats['deal_count'] ?></div>
            <small class="text-muted"><?= number_format((float)$stats['total_deal_sum'], 0, '.', ' ') ?> ₸</small>
        </div>
        <div class="crm-stat-icon" style="background:rgba(16, 185, 129, 0.15); color:#10b981;">
            <i class="fas fa-handshake"></i>
        </div>
    </div>
</div>

<!-- Панель фильтров и поиска -->
<div class="card mb-4" style="padding:16px 20px;">
    <form method="GET" style="display:flex; gap:12px; align-items:center; flex-wrap:wrap; justify-content:space-between; margin:0;">
        <div style="display:flex; gap:10px; align-items:center; flex:1; min-width:280px;">
            <div style="position:relative; width:100%;">
                <i class="fas fa-search" style="position:absolute; left:14px; top:12px; color:var(--text-muted);"></i>
                <input 
                    type="text" 
                    name="search" 
                    class="form-control" 
                    style="padding-left:38px; height:40px;" 
                    placeholder="Поиск по имени, компании, номеру телефона или email..." 
                    value="<?= htmlspecialchars($searchQuery) ?>"
                >
            </div>
            <button type="submit" class="btn btn-secondary" style="height:40px; padding:0 16px;">
                Найти
            </button>
        </div>

        <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
            <!-- Фильтр по статусу -->
            <select name="status" class="form-control" style="height:40px; width:auto; min-width:180px;" onchange="this.form.submit()">
                <option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>Все статусы</option>
                <?php foreach ($statusList as $sKey => $sVal): ?>
                    <option value="<?= $sKey ?>" <?= $filterStatus === $sKey ? 'selected' : '' ?>>
                        <?= $sVal['label'] ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <!-- Кнопка фильтра звонков на сегодня -->
            <a href="/user/clients.php?calls_today=<?= $filterCallsToday ? '0' : '1' ?>" class="btn <?= $filterCallsToday ? 'btn-warning' : 'btn-secondary' ?>" style="height:40px; display:inline-flex; align-items:center; gap:6px;">
                <i class="fas fa-bell"></i> <span>Звонки сегодня / Просроченные</span>
            </a>

            <?php if (!empty($searchQuery) || $filterStatus !== 'all' || $filterCallsToday): ?>
                <a href="/user/clients.php" class="btn btn-secondary" style="height:40px;" title="Сбросить все фильтры">
                    <i class="fas fa-times"></i> Сбросить
                </a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Таблица клиентов: компактный вид без горизонтального скролла -->
<div class="table-responsive">
    <table class="crm-table">
        <thead>
            <tr>
                <th style="min-width:200px;">Клиент и контакты</th>
                <th style="min-width:140px;">Ответственный</th>
                <th style="min-width:110px;">Статус</th>
                <th style="min-width:100px;">Сумма</th>
                <th style="min-width:160px;">История и связь</th>
                <th style="text-align:right; min-width:170px;">Действия</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($clients)): ?>
                <tr>
                    <td colspan="6" class="text-center text-muted" style="padding: 40px 20px;">
                        <i class="fas fa-users" style="font-size:36px; opacity:0.3; margin-bottom:12px; display:block;"></i>
                        Клиенты по заданным критериям не найдены.<br>
                        <button class="btn btn-primary btn-sm mt-3" onclick="openNewClientModal()">
                            <i class="fas fa-plus"></i> Добавить нового клиента
                        </button>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($clients as $c): ?>
                    <?php
                    $cStatus = $c['status'] ?? 'lead';
                    $statusInfo = $statusList[$cStatus] ?? $statusList['lead'];

                    // Имя ответственного сотрудника
                    $managerName = trim(($c['manager_first_name'] ?? '') . ' ' . ($c['manager_last_name'] ?? ''));
                    if (empty($managerName)) {
                        $managerName = $c['manager_email'] ?? 'Не назначен';
                    }
                    $isAssignedToMe = ((int)($c['assigned_to'] ?: $c['user_id']) === $userId);

                    // Проверка даты следующего контакта
                    $nextContact = $c['next_contact_at'] ? strtotime($c['next_contact_at']) : null;
                    $isOverdue = false;
                    $isToday = false;
                    if ($nextContact) {
                        $now = time();
                        $todayStart = strtotime('today midnight');
                        $todayEnd = strtotime('tomorrow midnight') - 1;
                        if ($nextContact < $now && $cStatus === 'callback') {
                            $isOverdue = true;
                        } elseif ($nextContact >= $todayStart && $nextContact <= $todayEnd) {
                            $isToday = true;
                        }
                    }
                    ?>
                    <tr>
                        <!-- 1. Клиент, компания и контакты (в одном блоке) -->
                        <td>
                            <div style="font-weight:700; font-size:14px; color:#f1f5f9; display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
                                <span><?= htmlspecialchars($c['name']) ?></span>
                                <?php if (!empty($c['company_name'])): ?>
                                    <span style="font-size:12px; font-weight:500; color:#94a3b8;">(<?= htmlspecialchars($c['company_name']) ?>)</span>
                                <?php endif; ?>
                                <?php if (!empty($c['position'])): ?>
                                    <span style="font-size:11px; color:var(--text-muted);">• <?= htmlspecialchars($c['position']) ?></span>
                                <?php endif; ?>
                            </div>
                            <div style="display:flex; flex-wrap:wrap; align-items:center; gap:8px 12px; margin-top:4px; font-size:12px;">
                                <?php if (!empty($c['phone'])): ?>
                                    <a href="tel:<?= htmlspecialchars($c['phone']) ?>" style="color:#38bdf8; text-decoration:none; font-weight:600; display:inline-flex; align-items:center; gap:4px;">
                                        <i class="fas fa-phone-alt" style="font-size:10px;"></i> <?= htmlspecialchars($c['phone']) ?>
                                    </a>
                                <?php endif; ?>
                                <?php if (!empty($c['email'])): ?>
                                    <a href="mailto:<?= htmlspecialchars($c['email']) ?>" style="color:var(--text-muted); text-decoration:none; display:inline-flex; align-items:center; gap:4px;">
                                        <i class="fas fa-envelope" style="font-size:10px;"></i> <?= htmlspecialchars($c['email']) ?>
                                    </a>
                                <?php endif; ?>
                                <?php if (empty($c['phone']) && empty($c['email'])): ?>
                                    <span class="text-muted" style="font-size:11px;">Нет контактов</span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($c['notes'])): ?>
                                <div style="font-size:11px; color:#94a3b8; margin-top:3px; max-width:280px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?= htmlspecialchars($c['notes']) ?>">
                                    <i class="fas fa-sticky-note" style="color:var(--text-muted); font-size:10px;"></i> <?= htmlspecialchars($c['notes']) ?>
                                </div>
                            <?php endif; ?>
                        </td>

                        <!-- 2. Ответственный сотрудник -->
                        <td>
                            <div style="display:flex; align-items:center; gap:8px;">
                                <div style="width:28px; height:28px; border-radius:50%; background:linear-gradient(135deg, #6366f1, #38bdf8); display:flex; align-items:center; justify-content:center; color:#fff; font-weight:700; font-size:11px; flex-shrink:0;">
                                    <?= mb_strtoupper(mb_substr($managerName, 0, 1)) ?>
                                </div>
                                <div style="line-height:1.2;">
                                    <div style="font-weight:600; font-size:12px; color:#f1f5f9; white-space:nowrap;">
                                        <?= htmlspecialchars($managerName) ?>
                                        <?php if ($isAssignedToMe): ?>
                                            <span class="badge" style="background:rgba(99, 102, 241, 0.25); color:#818cf8; font-size:9px; padding:1px 4px; border-radius:3px;">Вы</span>
                                        <?php endif; ?>
                                    </div>
                                    <small class="text-muted" style="font-size:10px; display:block; margin-top:2px;"><?= htmlspecialchars($c['manager_email'] ?? '') ?></small>
                                </div>
                            </div>
                        </td>

                        <!-- 3. Статус в CRM -->
                        <td>
                            <span class="crm-badge-pill" style="background:<?= $statusInfo['color'] ?>25; color:<?= $statusInfo['color'] ?>; border:1px solid <?= $statusInfo['color'] ?>50; font-size:11px; padding:3px 8px;">
                                <i class="fas <?= $statusInfo['icon'] ?>"></i> <?= $statusInfo['label'] ?>
                            </span>
                        </td>

                        <!-- 4. Сумма сделки -->
                        <td>
                            <?php if ((float)$c['deal_sum'] > 0): ?>
                                <strong style="color:#10b981; font-size:13px; white-space:nowrap;">
                                    <?= number_format((float)$c['deal_sum'], 0, '.', ' ') ?> ₸
                                </strong>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>

                        <!-- 5. История и план связи (Последний звонок + Следующий контакт в одном блоке) -->
                        <td>
                            <?php if (!empty($c['last_result'])): ?>
                                <div style="font-size:12px; font-weight:600; color:#cbd5e1; display:flex; align-items:center; gap:4px; white-space:nowrap;">
                                    <i class="fas fa-phone-alt" style="font-size:10px; color:#38bdf8;"></i> <?= htmlspecialchars($c['last_result']) ?>
                                </div>
                                <?php if (!empty($c['last_contact_at'])): ?>
                                    <div style="font-size:10px; color:var(--text-muted); margin-bottom:4px;">
                                        <?= date('d.m.Y H:i', strtotime($c['last_contact_at'])) ?>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div style="font-size:11px; color:var(--text-muted); margin-bottom:3px;">Контактов не было</div>
                            <?php endif; ?>

                            <?php if ($nextContact): ?>
                                <div style="display:inline-flex; align-items:center; gap:4px; font-size:11px; font-weight:600; padding:2px 6px; border-radius:4px; white-space:nowrap; <?= $isOverdue ? 'background:rgba(239,68,68,0.15); color:#ef4444; border:1px solid rgba(239,68,68,0.3);' : ($isToday ? 'background:rgba(245,158,11,0.15); color:#f59e0b; border:1px solid rgba(245,158,11,0.3);' : 'background:rgba(255,255,255,0.05); color:#cbd5e1;') ?>">
                                    <i class="fas fa-calendar-alt"></i> След: <?= date('d.m H:i', $nextContact) ?>
                                    <?php if ($isOverdue): ?> (просрочен!)<?php elseif ($isToday): ?> (сегодня)<?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </td>

                        <!-- 6. Действия -->
                        <td style="text-align:right; white-space:nowrap;">
                            <div style="display:inline-flex; gap:5px; align-items:center;">
                                <!-- Кнопка добавления результата связи / звонка -->
                                <button 
                                    type="button" 
                                    class="btn btn-warning btn-sm" 
                                    title="Зафиксировать результат звонка (НДЗ, РЗН, Перезвонить, Отказ)" 
                                    onclick="openCallResultModal(<?= (int)$c['id'] ?>, '<?= htmlspecialchars(addslashes($c['name'])) ?>', '<?= htmlspecialchars(addslashes($c['phone'] ?? '')) ?>', '<?= htmlspecialchars($c['status']) ?>')"
                                    style="padding:4px 8px; font-size:12px; font-weight:600;"
                                >
                                    <i class="fas fa-phone-alt"></i> Результат
                                </button>

                                <!-- Кнопка просмотра истории -->
                                <button 
                                    type="button" 
                                    class="btn btn-secondary btn-sm" 
                                    title="История взаимодействий" 
                                    onclick="openHistoryModal(<?= (int)$c['id'] ?>, '<?= htmlspecialchars(addslashes($c['name'])) ?>')"
                                    style="padding:4px 8px; font-size:12px;"
                                >
                                    <i class="fas fa-history"></i> <span class="badge" style="background:rgba(255,255,255,0.15); margin-left:2px; font-size:10px;"><?= (int)$c['history_count'] ?></span>
                                </button>

                                <!-- Редактирование клиента -->
                                <button 
                                    type="button" 
                                    class="btn btn-secondary btn-sm" 
                                    title="Редактировать значения клиента" 
                                    onclick='openEditClientModal(<?= json_encode($c, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_UNESCAPED_UNICODE) ?>)'
                                    style="padding:4px 7px; font-size:12px;"
                                >
                                    <i class="fas fa-edit"></i>
                                </button>

                                <!-- AI КП -->
                                <a 
                                    href="/user/ai_assistant.php?prompt=<?= urlencode("Составь персональное коммерческое предложение для клиента: {$c['name']}" . (!empty($c['company_name']) ? " ({$c['company_name']})" : "") . (!empty($c['notes']) ? ". Заметки: {$c['notes']}" : "")) ?>" 
                                    class="btn btn-secondary btn-sm" 
                                    title="Сформировать КП с помощью AI"
                                    style="padding:4px 7px; font-size:12px;"
                                >
                                    <i class="fas fa-robot text-primary"></i>
                                </a>

                                <!-- Удалить -->
                                <form method="POST" onsubmit="return confirm('Удалить клиента «<?= htmlspecialchars(addslashes($c['name'])) ?>» и всю его историю?')" style="margin:0; display:inline;">
                                    <?= Security::csrfField() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="client_id" value="<?= (int)$c['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm" title="Удалить" style="padding:4px 7px; font-size:12px;">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- ========================================================================= -->
<!-- МОДАЛЬНОЕ ОКНО 1: ДОБАВИТЬ РЕЗУЛЬТАТ СВЯЗИ / ЗВОНКА (НДЗ, РЗН, Перезвонить, ОТКАЗ) -->
<!-- ========================================================================= -->
<div id="call-result-modal" class="crm-modal-backdrop">
    <div class="crm-modal-card" style="max-width: 560px;">
        <!-- Фиксированный заголовок -->
        <div class="crm-modal-header">
            <div>
                <h3 style="margin:0; font-size:18px; display:flex; align-items:center; gap:8px;">
                    <i class="fas fa-phone-volume text-warning"></i> Зафиксировать результат связи
                </h3>
                <small class="text-muted" id="call-result-client-name" style="font-size:13px; font-weight:600; color:#38bdf8;"></small>
            </div>
            <button type="button" onclick="closeCallResultModal()" style="background:none; border:none; font-size:24px; color:var(--text-muted); cursor:pointer;">&times;</button>
        </div>

        <form method="POST" class="crm-modal-form">
            <?= Security::csrfField() ?>
            <input type="hidden" name="action" value="add_history">
            <input type="hidden" name="client_id" id="call_result_client_id" value="0">

            <!-- СКРОЛЛИТСЯ ТОЛЬКО ЭТА ЧАСТЬ С ПОЛЯМИ ВВОДА -->
            <div class="crm-modal-body">
                <!-- Выбор результата связи -->
                <div class="form-group mb-3">
                    <label class="form-label" style="font-weight:700;">Результат контакта / звонка *</label>
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:8px;">
                        <!-- Звонок совершён -->
                        <label style="display:flex; align-items:center; gap:8px; padding:10px 12px; border:1px solid rgba(16, 185, 129, 0.5); border-radius:10px; cursor:pointer; background:rgba(16, 185, 129, 0.1); grid-column:span 2;">
                            <input type="radio" name="result_type" value="called" id="radio_called" onchange="handleResultTypeChange('called')" checked required>
                            <div>
                                <strong style="color:#10b981; font-size:14px;">📞 Звонок совершён</strong>
                                <div style="font-size:11px; color:var(--text-muted);">Разговор успешно состоялся, контакт установлен</div>
                            </div>
                        </label>

                        <!-- НДЗ -->
                        <label style="display:flex; align-items:center; gap:8px; padding:10px 12px; border:1px solid rgba(239, 68, 68, 0.4); border-radius:10px; cursor:pointer; background:rgba(239, 68, 68, 0.08);">
                            <input type="radio" name="result_type" value="ndz" onchange="handleResultTypeChange('ndz')">
                            <div>
                                <strong style="color:#ef4444; font-size:13px;">📵 НДЗ</strong>
                                <div style="font-size:11px; color:var(--text-muted);">Не дозвонился</div>
                            </div>
                        </label>

                        <!-- РЗН -->
                        <label style="display:flex; align-items:center; gap:8px; padding:10px 12px; border:1px solid rgba(156, 163, 175, 0.4); border-radius:10px; cursor:pointer; background:rgba(156, 163, 175, 0.08);">
                            <input type="radio" name="result_type" value="rzn" onchange="handleResultTypeChange('rzn')">
                            <div>
                                <strong style="color:#9ca3af; font-size:13px;">⏹️ РЗН</strong>
                                <div style="font-size:11px; color:var(--text-muted);">Закончен ничем</div>
                            </div>
                        </label>

                        <!-- Перезвонить -->
                        <label style="display:flex; align-items:center; gap:8px; padding:10px 12px; border:1px solid rgba(245, 158, 11, 0.5); border-radius:10px; cursor:pointer; background:rgba(245, 158, 11, 0.1); grid-column:span 2;">
                            <input type="radio" name="result_type" value="callback" id="radio_callback" onchange="handleResultTypeChange('callback')">
                            <div>
                                <strong style="color:#f59e0b; font-size:14px;">📞 Перезвонить</strong>
                                <div style="font-size:11px; color:var(--text-muted);">Назначить точную дату и время следующего звонка</div>
                            </div>
                        </label>

                        <!-- ОТКАЗ -->
                        <label style="display:flex; align-items:center; gap:8px; padding:10px 12px; border:1px solid rgba(239, 68, 68, 0.5); border-radius:10px; cursor:pointer; background:rgba(239, 68, 68, 0.12); grid-column:span 2;">
                            <input type="radio" name="result_type" value="refusal" id="radio_refusal" onchange="handleResultTypeChange('refusal')">
                            <div>
                                <strong style="color:#ef4444; font-size:14px;">❌ ОТКАЗ</strong>
                                <div style="font-size:11px; color:#fca5a5;">Клиент отказался / Не актуально / Выбрал других</div>
                            </div>
                        </label>

                        <!-- Заинтересован -->
                        <label style="display:flex; align-items:center; gap:8px; padding:10px 12px; border:1px solid var(--border-color); border-radius:10px; cursor:pointer;">
                            <input type="radio" name="result_type" value="interested" onchange="handleResultTypeChange('interested')">
                            <div>
                                <strong style="color:#06b6d4; font-size:13px;">👍 Заинтересован</strong>
                                <div style="font-size:11px; color:var(--text-muted);">Переговоры</div>
                            </div>
                        </label>

                        <!-- Сделка -->
                        <label style="display:flex; align-items:center; gap:8px; padding:10px 12px; border:1px solid var(--border-color); border-radius:10px; cursor:pointer;">
                            <input type="radio" name="result_type" value="deal" onchange="handleResultTypeChange('deal')">
                            <div>
                                <strong style="color:#10b981; font-size:13px;">🤝 Сделка</strong>
                                <div style="font-size:11px; color:var(--text-muted);">Оплата / Договор</div>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Блок назначения даты перезвона (активируется/выделяется при Перезвонить) -->
                <div id="call_result_date_container" class="form-group mb-3" style="background:rgba(245, 158, 11, 0.06); border:1px dashed rgba(245, 158, 11, 0.4); border-radius:12px; padding:14px;">
                    <label class="form-label" style="display:flex; justify-content:space-between; align-items:center;">
                        <span style="font-weight:700; color:#f59e0b;"><i class="fas fa-calendar-alt"></i> Дата и время следующего звонка / связи:</span>
                    </label>
                    <input 
                        type="datetime-local" 
                        name="next_contact_at" 
                        id="call_result_next_contact" 
                        class="form-control"
                        style="font-size:15px; font-weight:600;"
                    >
                    <div style="display:flex; gap:6px; flex-wrap:wrap; margin-top:8px;">
                        <button type="button" class="quick-date-btn" onclick="setQuickDate(1, 0)">+1 час</button>
                        <button type="button" class="quick-date-btn" onclick="setQuickDate(3, 0)">+3 часа</button>
                        <button type="button" class="quick-date-btn" onclick="setQuickDate(24, 10)">Завтра в 10:00</button>
                        <button type="button" class="quick-date-btn" onclick="setQuickDate(24, 14)">Завтра в 14:00</button>
                        <button type="button" class="quick-date-btn" onclick="setQuickDate(48, 11)">Через 2 дня</button>
                    </div>
                </div>

                <!-- Комментарий / Заметка по итогу разговора -->
                <div class="form-group mb-3">
                    <label class="form-label">Комментарий / Детали разговора / Причина отказа</label>
                    <textarea 
                        name="comment" 
                        id="call_result_comment" 
                        class="form-control" 
                        rows="3" 
                        placeholder="Например: Не взял трубку, гудки шли. Попробовать набрать во второй половине дня..."
                    ></textarea>
                </div>

                <!-- Опция автоматического обновления статуса -->
                <div class="form-group mb-2" style="background:rgba(255,255,255,0.03); border:1px solid var(--border-color); border-radius:10px; padding:12px;">
                    <label style="display:flex; align-items:center; gap:10px; margin:0; cursor:pointer;">
                        <input type="checkbox" name="update_client_status" id="update_client_status_check" value="1" checked style="width:18px; height:18px;">
                        <div>
                            <strong style="font-size:13px; color:#f1f5f9;">Синхронизировать статус клиента</strong>
                            <div style="font-size:12px; color:var(--text-muted);" id="status_sync_hint">
                                Статус клиента будет автоматически изменен на выбранный результат связи
                            </div>
                        </div>
                    </label>
                </div>
            </div>

            <!-- Фиксированный подвал с кнопками -->
            <div class="crm-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeCallResultModal()">Отмена</button>
                <button type="submit" class="btn btn-primary" style="padding:10px 24px; font-weight:700;">
                    <i class="fas fa-check"></i> Сохранить результат
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- МОДАЛЬНОЕ ОКНО 2: ПОЛНАЯ ИСТОРИЯ ВЗАИМОДЕЙСТВИЯ (Хронологический таймлайн) -->
<!-- ========================================================================= -->
<div id="history-modal" class="crm-modal-backdrop">
    <div class="crm-modal-card" style="max-width: 680px;">
        <!-- Фиксированный заголовок -->
        <div class="crm-modal-header">
            <div>
                <h3 style="margin:0; font-size:20px; display:flex; align-items:center; gap:8px;">
                    <i class="fas fa-history text-primary"></i> История клиента
                </h3>
                <div id="history_client_header_info" style="margin-top:4px; font-size:14px; font-weight:600; color:#38bdf8;"></div>
            </div>
            <button type="button" onclick="closeHistoryModal()" style="background:none; border:none; font-size:24px; color:var(--text-muted); cursor:pointer;">&times;</button>
        </div>

        <!-- Фиксированная подшапка с кнопкой добавления звонка -->
        <div style="padding:12px 24px 0 24px; flex-shrink:0; display:flex; justify-content:space-between; align-items:center;">
            <span class="text-muted" style="font-size:13px;">Хронологический лог всех контактов и звонков</span>
            <button type="button" class="btn btn-warning btn-sm" id="history_add_call_btn">
                <i class="fas fa-phone-alt"></i> + Зафиксировать звонок
            </button>
        </div>

        <!-- Индикатор загрузки -->
        <div id="history-loading" style="text-align:center; padding:30px;">
            <div class="spinner-border text-primary" style="width:36px; height:36px;"></div>
            <div class="text-muted mt-2">Загрузка истории...</div>
        </div>

        <!-- СКРОЛЛИТСЯ ТОЛЬКО ЭТА ЧАСТЬ С ИСТОРИЕЙ -->
        <div id="history-content" class="crm-modal-body" style="display:none;">
            <div id="history-timeline" class="crm-timeline"></div>
        </div>

        <!-- Фиксированный подвал с кнопкой Закрыть -->
        <div class="crm-modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeHistoryModal()">Закрыть</button>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- МОДАЛЬНОЕ ОКНО 3: РЕДАКТИРОВАНИЕ ЗНАЧЕНИЙ КЛИЕНТА (ПОЛНЫЙ EDIT)            -->
<!-- ========================================================================= -->
<div id="edit-client-modal" class="crm-modal-backdrop">
    <div class="crm-modal-card" style="max-width: 620px;">
        <!-- Фиксированный заголовок -->
        <div class="crm-modal-header">
            <h3 style="margin:0; font-size:18px; display:flex; align-items:center; gap:8px;">
                <i class="fas fa-user-edit text-primary"></i> Редактировать клиента
            </h3>
            <button type="button" onclick="closeEditClientModal()" style="background:none; border:none; font-size:24px; color:var(--text-muted); cursor:pointer;">&times;</button>
        </div>

        <form method="POST" class="crm-modal-form">
            <?= Security::csrfField() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="client_id" id="edit_client_id" value="0">

            <!-- СКРОЛЛИТСЯ ТОЛЬКО ЭТА ЧАСТЬ С ПОЛЯМИ -->
            <div class="crm-modal-body">
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                    <div class="form-group mb-3">
                        <label class="form-label">Имя / Контактное лицо *</label>
                        <input type="text" name="name" id="edit_name" class="form-control" required>
                    </div>
                    <div class="form-group mb-3">
                        <label class="form-label">Организация / Компания</label>
                        <input type="text" name="company_name" id="edit_company_name" class="form-control" placeholder="ТОО 'КазСнаб'">
                    </div>
                </div>

                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                    <div class="form-group mb-3">
                        <label class="form-label">Должность</label>
                        <input type="text" name="position" id="edit_position" class="form-control" placeholder="Директор по закупкам">
                    </div>
                    <div class="form-group mb-3">
                        <label class="form-label">Ответственный менеджер</label>
                        <select name="assigned_to" id="edit_assigned_to" class="form-control">
                            <?php foreach ($allManagers as $m): ?>
                                <option value="<?= $m['id'] ?>">
                                    <?= htmlspecialchars(trim($m['first_name'] . ' ' . $m['last_name'])) ?: htmlspecialchars($m['email']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                    <div class="form-group mb-3">
                        <label class="form-label">Телефон</label>
                        <input type="tel" name="phone" id="edit_phone" class="form-control" placeholder="+7 777 000 11 22">
                    </div>
                    <div class="form-group mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" id="edit_email" class="form-control" placeholder="client@company.kz">
                    </div>
                </div>

                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                    <div class="form-group mb-3">
                        <label class="form-label">Статус в воронке CRM</label>
                        <select name="status" id="edit_status" class="form-control">
                            <?php foreach ($statusList as $sKey => $sVal): ?>
                                <option value="<?= $sKey ?>"><?= $sVal['label'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group mb-3">
                        <label class="form-label">Сумма сделки (₸)</label>
                        <input type="number" step="0.01" name="deal_sum" id="edit_deal_sum" class="form-control" placeholder="0">
                    </div>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label">Следующий контакт / звонок</label>
                    <input type="datetime-local" name="next_contact_at" id="edit_next_contact_at" class="form-control">
                </div>

                <div class="form-group mb-2">
                    <label class="form-label">Заметки / История потребности</label>
                    <textarea name="notes" id="edit_notes" class="form-control" rows="3"></textarea>
                </div>
            </div>

            <!-- Фиксированный подвал с кнопками -->
            <div class="crm-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeEditClientModal()">Отмена</button>
                <button type="submit" class="btn btn-primary" style="padding:10px 24px; font-weight:700;">
                    <i class="fas fa-save"></i> Сохранить изменения
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- МОДАЛЬНОЕ ОКНО 4: ДОБАВЛЕНИЕ НОВОГО КЛИЕНТА                               -->
<!-- ========================================================================= -->
<div id="new-client-modal" class="crm-modal-backdrop">
    <div class="crm-modal-card" style="max-width: 620px;">
        <!-- Фиксированный заголовок -->
        <div class="crm-modal-header">
            <h3 style="margin:0; font-size:18px; display:flex; align-items:center; gap:8px;">
                <i class="fas fa-user-plus text-primary"></i> Добавить нового клиента
            </h3>
            <button type="button" onclick="closeNewClientModal()" style="background:none; border:none; font-size:24px; color:var(--text-muted); cursor:pointer;">&times;</button>
        </div>

        <form method="POST" class="crm-modal-form">
            <?= Security::csrfField() ?>
            <input type="hidden" name="action" value="create">

            <!-- СКРОЛЛИТСЯ ТОЛЬКО ЭТА ЧАСТЬ С ПОЛЯМИ -->
            <div class="crm-modal-body">
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                    <div class="form-group mb-3">
                        <label class="form-label">Имя / Контактное лицо *</label>
                        <input type="text" name="name" class="form-control" required placeholder="Иван Иванов">
                    </div>
                    <div class="form-group mb-3">
                        <label class="form-label">Компания / Организация</label>
                        <input type="text" name="company_name" class="form-control" placeholder="ТОО 'Вектор'">
                    </div>
                </div>

                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                    <div class="form-group mb-3">
                        <label class="form-label">Должность</label>
                        <input type="text" name="position" class="form-control" placeholder="Коммерческий директор">
                    </div>
                    <div class="form-group mb-3">
                        <label class="form-label">Ответственный менеджер</label>
                        <select name="assigned_to" class="form-control">
                            <?php foreach ($allManagers as $m): ?>
                                <option value="<?= $m['id'] ?>" <?= ($m['id'] === $userId) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars(trim($m['first_name'] . ' ' . $m['last_name'])) ?: htmlspecialchars($m['email']) ?>
                                    <?= ($m['id'] === $userId) ? ' (Вы)' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                    <div class="form-group mb-3">
                        <label class="form-label">Телефон</label>
                        <input type="tel" name="phone" class="form-control" placeholder="+7 777 123 45 67">
                    </div>
                    <div class="form-group mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" placeholder="client@company.kz">
                    </div>
                </div>

                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                    <div class="form-group mb-3">
                        <label class="form-label">Статус в CRM</label>
                        <select name="status" class="form-control">
                            <?php foreach ($statusList as $sKey => $sVal): ?>
                                <option value="<?= $sKey ?>"><?= $sVal['label'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group mb-3">
                        <label class="form-label">Сумма потенциальной сделки (₸)</label>
                        <input type="number" step="0.01" name="deal_sum" class="form-control" placeholder="0">
                    </div>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label">Дата первого звонка / связи</label>
                    <input type="datetime-local" name="next_contact_at" class="form-control">
                </div>

                <div class="form-group mb-2">
                    <label class="form-label">Заметка / Что интересует клиента</label>
                    <textarea name="notes" class="form-control" rows="3" placeholder="Интересует поставка сырья, запрос КП на 500 тыс. тенге..."></textarea>
                </div>
            </div>

            <!-- Фиксированный подвал с кнопками -->
            <div class="crm-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeNewClientModal()">Отмена</button>
                <button type="submit" class="btn btn-primary" style="padding:10px 24px; font-weight:700;">
                    <i class="fas fa-plus"></i> Создать клиента
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// ==========================================
// Управление модальными окнами и CRM логика
// ==========================================

// Перемещаем модальные окна в document.body, чтобы они всегда находились строго по центру всего экрана браузера
document.addEventListener('DOMContentLoaded', () => {
    ['history-modal', 'call-result-modal', 'edit-client-modal', 'new-client-modal'].forEach(id => {
        const el = document.getElementById(id);
        if (el && el.parentNode !== document.body) {
            document.body.appendChild(el);
        }
    });
});

function setBodyScrollLocked(locked) {
    if (locked) {
        document.body.style.overflow = 'hidden';
    } else {
        const anyOpen = ['call-result-modal', 'history-modal', 'edit-client-modal', 'new-client-modal']
            .some(id => {
                const el = document.getElementById(id);
                return el && (el.style.display === 'flex' || el.style.display === 'block');
            });
        if (!anyOpen) {
            document.body.style.overflow = '';
        }
    }
}

function openNewClientModal() {
    document.getElementById('new-client-modal').style.display = 'flex';
    setBodyScrollLocked(true);
}
function closeNewClientModal() {
    document.getElementById('new-client-modal').style.display = 'none';
    setBodyScrollLocked(false);
}

// 1. Открытие модалки добавления результата связи (НДЗ, РЗН, Перезвонить, Отказ)
let currentCallClientData = { id: 0, name: '', phone: '', status: '' };

function openCallResultModal(clientId, clientName, clientPhone, clientStatus) {
    currentCallClientData = { id: clientId, name: clientName, phone: clientPhone, status: clientStatus };
    document.getElementById('call_result_client_id').value = clientId;
    document.getElementById('call-result-client-name').textContent = clientName + (clientPhone ? ' (' + clientPhone + ')' : '');
    document.getElementById('call_result_comment').value = '';
    document.getElementById('call_result_next_contact').value = '';

    // Сброс radio
    const radios = document.querySelectorAll('input[name="result_type"]');
    radios.forEach(r => r.checked = false);

    // По умолчанию предвыбираем "callback", если текущий статус callback, иначе "ndz"
    if (clientStatus === 'callback') {
        const rCb = document.getElementById('radio_callback');
        if (rCb) { rCb.checked = true; handleResultTypeChange('callback'); }
    } else if (clientStatus === 'refusal') {
        const rRef = document.getElementById('radio_refusal');
        if (rRef) { rRef.checked = true; handleResultTypeChange('refusal'); }
    } else {
        const first = document.querySelector('input[name="result_type"][value="ndz"]');
        if (first) { first.checked = true; handleResultTypeChange('ndz'); }
    }

    document.getElementById('call-result-modal').style.display = 'flex';
    setBodyScrollLocked(true);
}

function closeCallResultModal() {
    document.getElementById('call-result-modal').style.display = 'none';
    setBodyScrollLocked(false);
}

function handleResultTypeChange(type) {
    const dateContainer = document.getElementById('call_result_date_container');
    const commentInput = document.getElementById('call_result_comment');
    const statusHint = document.getElementById('status_sync_hint');

    if (type === 'callback') {
        dateContainer.style.background = 'rgba(245, 158, 11, 0.15)';
        dateContainer.style.borderColor = '#f59e0b';
        if (!document.getElementById('call_result_next_contact').value) {
            setQuickDate(24, 11); // предвыбор на завтра в 11:00
        }
        commentInput.placeholder = 'Договорились созвониться, подготовить КП по ценам...';
        statusHint.textContent = 'Статус клиента автоматически станет «Перезвонить» с датой звонка';
    } else if (type === 'refusal') {
        dateContainer.style.background = 'rgba(239, 68, 68, 0.05)';
        dateContainer.style.borderColor = 'rgba(239, 68, 68, 0.3)';
        commentInput.placeholder = 'Причина отказа: дорого, не устроило предложение, выбрали конкурентов...';
        statusHint.textContent = 'Статус клиента автоматически станет «❌ Отказ»';
    } else if (type === 'ndz') {
        dateContainer.style.background = 'rgba(245, 158, 11, 0.05)';
        dateContainer.style.borderColor = 'rgba(245, 158, 11, 0.4)';
        commentInput.placeholder = 'Гудки идут, трубку не берет. Попробовать перенабрать через 2-3 часа...';
        statusHint.textContent = 'Статус клиента автоматически станет «НДЗ (Недозвон)»';
    } else if (type === 'rzn') {
        dateContainer.style.background = 'rgba(245, 158, 11, 0.05)';
        dateContainer.style.borderColor = 'rgba(245, 158, 11, 0.4)';
        commentInput.placeholder = 'Поговорили, но клиент пока не готов, конкретики нет...';
        statusHint.textContent = 'Статус клиента автоматически станет «РЗН (Ничем)»';
    } else if (type === 'deal') {
        commentInput.placeholder = 'Согласовали поставку, выставлен счет на оплату...';
        statusHint.textContent = 'Статус клиента автоматически станет «Сделка / Клиент»';
    } else {
        commentInput.placeholder = 'Заметка по разговору...';
        statusHint.textContent = 'Статус будет обновлен в соответствии с типом контакта';
    }
}

// Быстрая установка времени
function setQuickDate(hoursToAdd, targetHour) {
    const d = new Date();
    if (targetHour > 0) {
        d.setDate(d.getDate() + Math.floor(hoursToAdd / 24));
        d.setHours(targetHour, 0, 0, 0);
    } else {
        d.setHours(d.getHours() + hoursToAdd);
    }

    // Format YYYY-MM-DDTHH:mm
    const year = d.getFullYear();
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    const hours = String(d.getHours()).padStart(2, '0');
    const minutes = String(d.getMinutes()).padStart(2, '0');

    document.getElementById('call_result_next_contact').value = `${year}-${month}-${day}T${hours}:${minutes}`;
}

// 2. Открытие модалки истории взаимодействия
async function openHistoryModal(clientId, clientName) {
    const modal = document.getElementById('history-modal');
    const loading = document.getElementById('history-loading');
    const content = document.getElementById('history-content');
    const timeline = document.getElementById('history-timeline');
    const headerInfo = document.getElementById('history_client_header_info');
    const addCallBtn = document.getElementById('history_add_call_btn');

    modal.style.display = 'flex';
    setBodyScrollLocked(true);
    loading.style.display = 'block';
    content.style.display = 'none';
    timeline.innerHTML = '';
    headerInfo.textContent = clientName;

    try {
        const resp = await fetch(`/user/clients.php?action=get_history&client_id=${clientId}`);
        const data = await resp.json();

        loading.style.display = 'none';

        if (!data.success) {
            timeline.innerHTML = `<div class="alert alert-danger">${data.message || 'Ошибка загрузки'}</div>`;
            content.style.display = 'block';
            return;
        }

        const cl = data.client;
        headerInfo.innerHTML = `
            <strong>${escapeHtml(cl.name)}</strong> 
            ${cl.phone ? '• 📞 ' + escapeHtml(cl.phone) : ''} 
            ${cl.company_name ? '• 🏢 ' + escapeHtml(cl.company_name) : ''}
            <div style="font-size:12px; color:#cbd5e1; margin-top:3px;">
                <i class="fas fa-user-tie" style="color:#818cf8;"></i> Ответственный: <strong style="color:#38bdf8;">${escapeHtml(cl.manager_name || 'Не назначен')}</strong>
            </div>
        `;

        addCallBtn.onclick = () => {
            closeHistoryModal();
            openCallResultModal(cl.id, cl.name, cl.phone || '', cl.status);
        };

        if (!data.history || data.history.length === 0) {
            timeline.innerHTML = `
                <div style="text-align:center; padding:30px; color:var(--text-muted);">
                    <i class="fas fa-history" style="font-size:32px; opacity:0.3; margin-bottom:10px; display:block;"></i>
                    Истории взаимодействий по данному клиенту пока нет.<br>
                    <button class="btn btn-warning btn-sm mt-3" onclick="closeHistoryModal(); openCallResultModal(${cl.id}, '${escapeHtml(cl.name)}', '${escapeHtml(cl.phone || '')}', '${cl.status}')">
                        <i class="fas fa-phone-alt"></i> Зафиксировать первый контакт
                    </button>
                </div>
            `;
        } else {
            let html = '';
            data.history.forEach(item => {
                let badgeColor = '#6366f1';
                let icon = 'fa-comment';
                if (item.result_type === 'ndz') { badgeColor = '#ef4444'; icon = 'fa-phone-slash'; }
                else if (item.result_type === 'rzn') { badgeColor = '#9ca3af'; icon = 'fa-comment-slash'; }
                else if (item.result_type === 'callback') { badgeColor = '#f59e0b'; icon = 'fa-calendar-alt'; }
                else if (item.result_type === 'refusal') { badgeColor = '#ef4444'; icon = 'fa-ban'; }
                else if (item.result_type === 'deal') { badgeColor = '#10b981'; icon = 'fa-check-double'; }
                else if (item.result_type === 'interested') { badgeColor = '#06b6d4'; icon = 'fa-thumbs-up'; }

                const dateFormatted = new Date(item.created_at).toLocaleString('ru-RU', {
                    day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit'
                });

                let nextCallHtml = '';
                if (item.next_contact_at) {
                    const nextFormatted = new Date(item.next_contact_at).toLocaleString('ru-RU', {
                        day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit'
                    });
                    nextCallHtml = `
                        <div style="margin-top:6px; font-size:12px; font-weight:700; color:#f59e0b;">
                            <i class="fas fa-calendar-check"></i> Назначен звонок на: ${nextFormatted}
                        </div>
                    `;
                }

                const managerNameText = (item.first_name || item.last_name) ? (item.first_name || '') + ' ' + (item.last_name || '') : 'Система';

                html += `
                    <div class="crm-timeline-item">
                        <div class="crm-timeline-dot" style="border-color:${badgeColor}; color:${badgeColor};">
                            <i class="fas ${icon}" style="font-size:10px;"></i>
                        </div>
                        <div class="crm-timeline-box">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px; flex-wrap:wrap; gap:8px;">
                                <span class="badge" style="background:${badgeColor}22; color:${badgeColor}; border:1px solid ${badgeColor}66; font-size:11px; padding:3px 8px;">
                                    <i class="fas ${icon}"></i> ${escapeHtml(item.result_label)}
                                </span>
                                <small class="text-muted" style="font-size:11px;">
                                    <i class="far fa-clock"></i> ${dateFormatted}
                                </small>
                            </div>
                            <div style="font-size:13px; color:#f1f5f9; white-space:pre-wrap; line-height:1.5;">${escapeHtml(item.comment || 'Без комментария')}</div>
                            ${nextCallHtml}
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-top:8px; border-top:1px solid rgba(255,255,255,0.05); padding-top:6px;">
                                <small class="text-muted" style="font-size:11px;">
                                    <i class="fas fa-user-circle"></i> Менеджер: <strong style="color:#cbd5e1;">${escapeHtml(managerNameText)}</strong>
                                </small>
                                <form method="POST" onsubmit="return confirm('Удалить эту запись из истории?')" style="margin:0;">
                                    <input type="hidden" name="csrf_token" value="${document.querySelector('input[name=\"csrf_token\"]').value}">
                                    <input type="hidden" name="action" value="delete_history">
                                    <input type="hidden" name="history_id" value="${item.id}">
                                    <button type="submit" style="background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:11px;" title="Удалить запись">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                `;
            });
            timeline.innerHTML = html;
        }

        content.style.display = 'block';
    } catch (e) {
        loading.style.display = 'none';
        timeline.innerHTML = `<div class="alert alert-danger">Ошибка загрузки данных: ${escapeHtml(e.message)}</div>`;
        content.style.display = 'block';
    }
}

function closeHistoryModal() {
    document.getElementById('history-modal').style.display = 'none';
    setBodyScrollLocked(false);
}

// 3. Открытие модалки редактирования клиента
function openEditClientModal(client) {
    document.getElementById('edit_client_id').value = client.id;
    document.getElementById('edit_name').value = client.name || '';
    document.getElementById('edit_company_name').value = client.company_name || '';
    document.getElementById('edit_position').value = client.position || '';
    document.getElementById('edit_phone').value = client.phone || '';
    document.getElementById('edit_email').value = client.email || '';
    document.getElementById('edit_deal_sum').value = client.deal_sum || 0;
    document.getElementById('edit_status').value = client.status || 'lead';
    document.getElementById('edit_notes').value = client.notes || '';

    const assignedSelect = document.getElementById('edit_assigned_to');
    if (assignedSelect) {
        assignedSelect.value = client.assigned_to || client.user_id || '';
    }

    if (client.next_contact_at) {
        const d = new Date(client.next_contact_at);
        const year = d.getFullYear();
        const month = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        const hours = String(d.getHours()).padStart(2, '0');
        const minutes = String(d.getMinutes()).padStart(2, '0');
        document.getElementById('edit_next_contact_at').value = `${year}-${month}-${day}T${hours}:${minutes}`;
    } else {
        document.getElementById('edit_next_contact_at').value = '';
    }

    document.getElementById('edit-client-modal').style.display = 'flex';
    setBodyScrollLocked(true);
}

function closeEditClientModal() {
    document.getElementById('edit-client-modal').style.display = 'none';
    setBodyScrollLocked(false);
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Закрытие модальных окон при клике вне карточки
window.addEventListener('click', (e) => {
    ['call-result-modal', 'history-modal', 'edit-client-modal', 'new-client-modal'].forEach(id => {
        const modal = document.getElementById(id);
        if (modal && e.target === modal) {
            modal.style.display = 'none';
            setBodyScrollLocked(false);
        }
    });
});
</script>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
