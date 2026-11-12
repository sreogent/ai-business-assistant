<?php
declare(strict_types=1);

/**
 * AI Business Assistant - User Organizations & Staff Management
 * Просмотр организаций, выдача доступа сотрудникам и назначение должностей
 */

require_once dirname(__DIR__) . '/includes/Auth.php';
require_once dirname(__DIR__) . '/includes/AutoMigrate.php';
AutoMigrate::run();

$user   = Auth::requireAuth('/login.php');
$userId = (int)$user['id'];
$pdo    = Database::getConnection();

$error = null;
$success = null;

// Обработка действий
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!Security::verifyCsrf($token)) {
        $error = "Истёк токен безопасности. Повторите попытку.";
    } else {
        $action = $_POST['action'] ?? '';

        // Попытка создания организации клиентом блокируется
        if ($action === 'create') {
            $error = "⛔ Создание новых организаций осуществляется Администратором платформы.";
        }

        // Обновление контактных реквизитов своей организации
        elseif ($action === 'update') {
            $orgId         = (int)($_POST['org_id'] ?? 0);
            $directorPhone = trim($_POST['director_phone'] ?? '');
            $address       = trim($_POST['address'] ?? '');
            $bankName      = trim($_POST['bank_name'] ?? '');
            $bankAccount   = trim($_POST['bank_account'] ?? '');
            $description   = trim($_POST['description'] ?? '');

            if ($orgId <= 0) {
                $error = "Некорректная организация.";
            } else {
                $upd = $pdo->prepare("
                    UPDATE organizations 
                    SET director_phone = ?, address = ?, bank_name = ?, bank_account = ?, description = ?
                    WHERE id = ? AND (user_id = ? OR director_email = ?)
                ");
                $upd->execute([$directorPhone, $address, $bankName, $bankAccount, $description, $orgId, $userId, $user['email']]);
                $success = "Реквизиты организации успешно обновлены.";
            }
        }

        // Выдача доступа к организации сотруднику и назначение должности
        elseif ($action === 'grant_member_access') {
            $orgId          = (int)($_POST['org_id'] ?? 0);
            $targetUserId   = (int)($_POST['user_id'] ?? 0);
            $positionId     = !empty($_POST['position_id']) ? (int)$_POST['position_id'] : null;
            $customPosition = trim($_POST['custom_position'] ?? '');
            $roleInOrg      = trim($_POST['role_in_org'] ?? 'employee');

            $allowedOrgRoles = ['director', 'manager', 'cashier', 'employee'];
            if (!in_array($roleInOrg, $allowedOrgRoles, true)) {
                $roleInOrg = 'employee';
            }

            // Проверка прав: пользователь должен быть владельцем/директором организации или админом
            $checkOwner = $pdo->prepare("
                SELECT id, name FROM organizations 
                WHERE id = ? AND (user_id = ? OR director_email = ? OR ? = 1)
            ");
            $checkOwner->execute([$orgId, $userId, $user['email'], Auth::isAdmin() ? 1 : 0]);
            $orgData = $checkOwner->fetch();

            if (!$orgData) {
                $error = "У вас нет прав на управление доступом к этой организации.";
            } elseif ($targetUserId <= 0) {
                $error = "Пожалуйста, выберите сотрудника из списка.";
            } else {
                // Получаем название должности
                $finalPositionTitle = '';
                if ($positionId) {
                    $pStmt = $pdo->prepare("SELECT title, organization_id FROM positions WHERE id = ?");
                    $pStmt->execute([$positionId]);
                    $pRow = $pStmt->fetch();
                    if ($pRow) {
                        $finalPositionTitle = $pRow['title'];
                    }
                }

                // Если ввели новую должность вручную — создаем ее в таблице positions
                if (empty($finalPositionTitle) && !empty($customPosition)) {
                    $finalPositionTitle = $customPosition;
                    $insPos = $pdo->prepare("
                        INSERT INTO positions (organization_id, title, user_id, department) 
                        VALUES (?, ?, ?, 'Основной')
                    ");
                    $insPos->execute([$orgId, $finalPositionTitle, $userId]);
                    $positionId = (int)$pdo->lastInsertId();
                }

                if (empty($finalPositionTitle)) {
                    $finalPositionTitle = 'Сотрудник';
                }

                // Добавляем или обновляем запись в organization_members
                $memStmt = $pdo->prepare("
                    INSERT INTO organization_members 
                    (organization_id, user_id, position_id, position_title, role_in_org, created_by)
                    VALUES (?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                        position_id = VALUES(position_id),
                        position_title = VALUES(position_title),
                        role_in_org = VALUES(role_in_org),
                        updated_at = NOW()
                ");
                $memStmt->execute([$orgId, $targetUserId, $positionId, $finalPositionTitle, $roleInOrg, $userId]);

                // Получаем данные целевого пользователя для уведомления
                $uStmt = $pdo->prepare("SELECT first_name, last_name, email FROM users WHERE id = ?");
                $uStmt->execute([$targetUserId]);
                $targetUser = $uStmt->fetch();
                $targetName = $targetUser ? ($targetUser['first_name'] . ' ' . $targetUser['last_name']) : "ID #{$targetUserId}";

                // Отправляем системное уведомление сотруднику
                $notifStmt = $pdo->prepare("
                    INSERT INTO notifications (user_id, type, title, body, link)
                    VALUES (?, 'info', 'Доступ к организации', ?, '/user/organizations.php')
                ");
                $notifMsg = "Вам выдан доступ к организации «{$orgData['name']}» на должность: {$finalPositionTitle} (роль: {$roleInOrg}).";
                $notifStmt->execute([$targetUserId, $notifMsg]);

                Security::log($pdo, 'grant_org_access', "Выдан доступ к орг #{$orgId} ({$orgData['name']}) сотруднику #{$targetUserId} ({$targetName}), должность: {$finalPositionTitle}", $userId);

                $success = "Сотруднику <strong>" . htmlspecialchars($targetName) . "</strong> успешно выдан доступ к организации в должности: <strong>" . htmlspecialchars($finalPositionTitle) . "</strong>!";
            }
        }

        // Отозвать доступ у сотрудника
        elseif ($action === 'revoke_member_access') {
            $memberId = (int)($_POST['member_id'] ?? 0);

            // Проверяем, что запись принадлежит организации текущего пользователя
            $mCheck = $pdo->prepare("
                SELECT om.id, om.user_id, om.position_title, o.name as org_name, u.first_name, u.last_name 
                FROM organization_members om
                JOIN organizations o ON om.organization_id = o.id
                JOIN users u ON om.user_id = u.id
                WHERE om.id = ? AND (o.user_id = ? OR o.director_email = ? OR ? = 1)
            ");
            $mCheck->execute([$memberId, $userId, $user['email'], Auth::isAdmin() ? 1 : 0]);
            $memberRow = $mCheck->fetch();

            if (!$memberRow) {
                $error = "Сотрудник не найден или у вас нет прав на отзыв доступа.";
            } else {
                $pdo->prepare("DELETE FROM organization_members WHERE id = ?")->execute([$memberId]);

                // Уведомление сотруднику
                $pdo->prepare("
                    INSERT INTO notifications (user_id, type, title, body, link)
                    VALUES (?, 'warning', 'Отзыв доступа', ?, '/user/organizations.php')
                ")->execute([$memberRow['user_id'], "Доступ к организации «{$memberRow['org_name']}» был отозван."]);

                Security::log($pdo, 'revoke_org_access', "Отозван доступ сотрудника #{$memberRow['user_id']} ({$memberRow['first_name']} {$memberRow['last_name']}) к орг «{$memberRow['org_name']}»", $userId);

                $success = "Доступ сотрудника <strong>" . htmlspecialchars($memberRow['first_name'] . ' ' . $memberRow['last_name']) . "</strong> к организации успешно отозван.";
            }
        }
    }
}

// Загружаем собственные организации пользователя
$myOrganizations = $pdo->prepare("
    SELECT * FROM organizations 
    WHERE user_id = ? OR director_email = ? 
    ORDER BY id DESC
");
$myOrganizations->execute([$userId, $user['email']]);
$organizations = $myOrganizations->fetchAll();

// Загружаем организации, к которым пользователю открыт доступ в качестве сотрудника
$sharedOrgsStmt = $pdo->prepare("
    SELECT o.*, om.id as member_id, om.position_title, om.role_in_org, om.created_at as access_granted_at,
           u_owner.first_name as owner_first_name, u_owner.last_name as owner_last_name
    FROM organization_members om
    JOIN organizations o ON om.organization_id = o.id
    LEFT JOIN users u_owner ON o.user_id = u_owner.id
    WHERE om.user_id = ?
    ORDER BY o.name ASC
");
$sharedOrgsStmt->execute([$userId]);
$sharedOrganizations = $sharedOrgsStmt->fetchAll();

// Если нет ни своих, ни доступных, и не админ
if (empty($organizations) && empty($sharedOrganizations) && !Auth::isAdmin()) {
    header("Location: /user/request_organization.php");
    exit;
}

// Загружаем всех сотрудников для своих организаций
$myOrgIds = array_column($organizations, 'id');
$membersList = [];
$positionsList = [];

if (!empty($myOrgIds)) {
    $inPlaceholders = implode(',', array_fill(0, count($myOrgIds), '?'));
    
    // Список участников
    $memStmt = $pdo->prepare("
        SELECT om.*, o.name as org_name, u.first_name, u.last_name, u.email, u.phone
        FROM organization_members om
        JOIN organizations o ON om.organization_id = o.id
        JOIN users u ON om.user_id = u.id
        WHERE om.organization_id IN ({$inPlaceholders})
        ORDER BY om.id DESC
    ");
    $memStmt->execute($myOrgIds);
    $membersList = $memStmt->fetchAll();

    // Список должностей
    $posStmt = $pdo->prepare("
        SELECT id, organization_id, title, department 
        FROM positions 
        WHERE organization_id IN ({$inPlaceholders}) OR user_id = ?
        ORDER BY title ASC
    ");
    $posParams = array_merge($myOrgIds, [$userId]);
    $posStmt->execute($posParams);
    $positionsList = $posStmt->fetchAll();
}

// Загружаем список всех активных пользователей системы для возможности выдачи доступа
$allUsersStmt = $pdo->prepare("
    SELECT id, first_name, last_name, email, phone 
    FROM users 
    WHERE id != ? AND status = 'active'
    ORDER BY first_name ASC, last_name ASC
");
$allUsersStmt->execute([$userId]);
$availableUsers = $allUsersStmt->fetchAll();

$pageTitle = 'Организации и Сотрудники | AI Business Assistant';
require dirname(__DIR__) . '/includes/header.php';
?>

<div style="margin-bottom:24px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
    <div>
        <h2><i class="fas fa-building text-primary"></i> Организация и Управление бизнесом</h2>
        <p class="text-muted">Центр управления организацией: CRM-клиенты, задачи, документы, аналитика, сотрудники и онлайн-касса</p>
    </div>
    <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <?php if (!empty($organizations)): ?>
            <button type="button" class="btn btn-primary" onclick="openGrantAccessModal()">
                <i class="fas fa-user-plus"></i> Выдать доступ сотруднику
            </button>
        <?php endif; ?>
        <a href="/user/clients.php" class="btn btn-secondary">
            <i class="fas fa-user-friends"></i> CRM & Клиенты
        </a>
        <a href="/user/tasks.php" class="btn btn-secondary">
            <i class="fas fa-check-square"></i> Задачи
        </a>
        <a href="/user/documents.php" class="btn btn-secondary">
            <i class="fas fa-file-contract"></i> Документы
        </a>
        <a href="/user/analytics.php" class="btn btn-secondary">
            <i class="fas fa-chart-line"></i> Аналитика
        </a>
        <a href="/user/positions.php" class="btn btn-secondary">
            <i class="fas fa-user-tag"></i> Должности
        </a>
        <a href="/user/cashier.php" class="btn btn-secondary">
            <i class="fas fa-cash-register"></i> Онлайн-Касса
        </a>
    </div>
</div>

<!-- БЫСТРЫЕ МОДУЛИ ОРГАНИЗАЦИИ: CRM, ЗАДАЧИ, ДОКУМЕНТЫ, АНАЛИТИКА -->
<div class="row mb-4" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:16px;">
    <a href="/user/clients.php" style="text-decoration:none; color:inherit;">
        <div class="card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:20px; transition:transform 0.2s, border-color 0.2s; display:flex; align-items:center; gap:16px;">
            <div style="width:48px; height:48px; border-radius:12px; background:rgba(99,102,241,0.15); color:#818cf8; display:flex; align-items:center; justify-content:center; font-size:22px; flex-shrink:0;">
                <i class="fas fa-users-cog"></i>
            </div>
            <div>
                <div style="font-weight:700; font-size:16px;">CRM & Клиенты</div>
                <div class="text-muted" style="font-size:13px; margin-top:2px;">Воронка, фиксация звонков и статусы</div>
            </div>
        </div>
    </a>

    <a href="/user/tasks.php" style="text-decoration:none; color:inherit;">
        <div class="card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:20px; transition:transform 0.2s, border-color 0.2s; display:flex; align-items:center; gap:16px;">
            <div style="width:48px; height:48px; border-radius:12px; background:rgba(16,185,129,0.15); color:#10b981; display:flex; align-items:center; justify-content:center; font-size:22px; flex-shrink:0;">
                <i class="fas fa-tasks"></i>
            </div>
            <div>
                <div style="font-weight:700; font-size:16px;">Задачи</div>
                <div class="text-muted" style="font-size:13px; margin-top:2px;">Поручения сотрудникам и дедлайны</div>
            </div>
        </div>
    </a>

    <a href="/user/documents.php" style="text-decoration:none; color:inherit;">
        <div class="card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:20px; transition:transform 0.2s, border-color 0.2s; display:flex; align-items:center; gap:16px;">
            <div style="width:48px; height:48px; border-radius:12px; background:rgba(245,158,11,0.15); color:#f59e0b; display:flex; align-items:center; justify-content:center; font-size:22px; flex-shrink:0;">
                <i class="fas fa-file-invoice"></i>
            </div>
            <div>
                <div style="font-weight:700; font-size:16px;">Документы</div>
                <div class="text-muted" style="font-size:13px; margin-top:2px;">Договоры, акты, счета и файлы</div>
            </div>
        </div>
    </a>

    <a href="/user/analytics.php" style="text-decoration:none; color:inherit;">
        <div class="card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:20px; transition:transform 0.2s, border-color 0.2s; display:flex; align-items:center; gap:16px;">
            <div style="width:48px; height:48px; border-radius:12px; background:rgba(6,182,212,0.15); color:#06b6d4; display:flex; align-items:center; justify-content:center; font-size:22px; flex-shrink:0;">
                <i class="fas fa-chart-pie"></i>
            </div>
            <div>
                <div style="font-weight:700; font-size:16px;">Аналитика</div>
                <div class="text-muted" style="font-size:13px; margin-top:2px;">Графики выручки и конверсии</div>
            </div>
        </div>
    </a>
</div>

<?php if ($success): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $success ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- 1. ТАБЛИЦА СОБСТВЕННЫХ ОРГАНИЗАЦИЙ -->
<div class="card mb-4" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:24px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px; flex-wrap:wrap; gap:12px;">
        <h3 style="margin:0;"><i class="fas fa-briefcase text-primary"></i> Мои организации (<?= count($organizations) ?>)</h3>
        <?php if (!empty($organizations)): ?>
            <button type="button" class="btn btn-primary btn-sm" onclick="openGrantAccessModal()">
                <i class="fas fa-user-plus"></i> Назначить сотрудника
            </button>
        <?php endif; ?>
    </div>

    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Организация</th>
                    <th>Генеральный директор</th>
                    <th>Реквизиты и адрес</th>
                    <th>Сотрудники</th>
                    <th>Статус</th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($organizations)): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted" style="padding:36px;">
                            <i class="fas fa-building" style="font-size:32px; opacity:0.3; margin-bottom:10px; display:block;"></i>
                            У вас пока нет зарегистрированных организаций.<br>
                            Обратитесь к Администратору платформы для подключения юридического лица.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($organizations as $org): ?>
                        <?php 
                            $orgMembersCount = count(array_filter($membersList, fn($m) => (int)$m['organization_id'] === (int)$org['id']));
                        ?>
                        <tr>
                            <td>
                                <strong style="font-size:16px; color:var(--text-primary);"><?= htmlspecialchars($org['name']) ?></strong>
                                <?php if (!empty($org['legal_name'])): ?>
                                    <br><small class="text-muted"><?= htmlspecialchars($org['legal_name']) ?></small>
                                <?php endif; ?>
                                <?php if (!empty($org['description'])): ?>
                                    <br><small class="text-muted" style="font-style:italic;"><?= htmlspecialchars($org['description']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong class="text-primary"><i class="fas fa-user-tie"></i> <?= htmlspecialchars($org['director_name']) ?></strong>
                                <?php if (!empty($org['director_email'])): ?>
                                    <br><small class="text-muted"><i class="fas fa-envelope"></i> <?= htmlspecialchars($org['director_email']) ?></small>
                                <?php endif; ?>
                                <?php if (!empty($org['director_phone'])): ?>
                                    <br><small class="text-muted"><i class="fas fa-phone-alt"></i> <?= htmlspecialchars($org['director_phone']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($org['inn'])): ?>
                                    <small><strong>БИН/ИНН:</strong> <?= htmlspecialchars($org['inn']) ?></small><br>
                                <?php endif; ?>
                                <?php if (!empty($org['address'])): ?>
                                    <small class="text-muted"><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($org['address']) ?></small><br>
                                <?php endif; ?>
                                <?php if (!empty($org['bank_name'])): ?>
                                    <small class="text-muted"><?= htmlspecialchars($org['bank_name']) ?> (<?= htmlspecialchars($org['bank_account'] ?: 'IBAN') ?>)</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge badge-info" style="font-size:13px;">
                                    <i class="fas fa-users"></i> <?= $orgMembersCount ?> чел.
                                </span>
                            </td>
                            <td>
                                <?php if ($org['is_active']): ?>
                                    <span class="badge badge-success"><i class="fas fa-check"></i> Активна</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">Приостановлена</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display:flex; gap:6px; flex-wrap:wrap;">
                                    <button type="button" class="btn btn-primary btn-sm" onclick="openGrantAccessModal(<?= $org['id'] ?>, '<?= htmlspecialchars(addslashes($org['name'])) ?>')" title="Выдать доступ сотруднику">
                                        <i class="fas fa-user-plus"></i> Доступ
                                    </button>
                                    <button type="button" class="btn btn-secondary btn-sm" onclick='openEditModal(<?= json_encode($org, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>)' title="Изменить контакты">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- 2. ТАБЛИЦА СОТРУДНИКОВ И НАЗНАЧЕННЫХ ДОЛЖНОСТЕЙ -->
<div class="card mb-4" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:24px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px; flex-wrap:wrap; gap:12px;">
        <div>
            <h3 style="margin:0;"><i class="fas fa-id-badge text-warning"></i> Сотрудники с доступом и должности (<?= count($membersList) ?>)</h3>
            <p class="text-muted" style="margin:4px 0 0; font-size:13px;">Сотрудники, которым вы предоставили доступ к кассе и управлению организацией</p>
        </div>
        <?php if (!empty($organizations)): ?>
            <button type="button" class="btn btn-warning btn-sm" onclick="openGrantAccessModal()">
                <i class="fas fa-plus"></i> Назначить должность сотруднику
            </button>
        <?php endif; ?>
    </div>

    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Сотрудник</th>
                    <th>Организация</th>
                    <th>Должность</th>
                    <th>Роль в системе</th>
                    <th>Дата назначения</th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($membersList)): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted" style="padding:32px;">
                            <i class="fas fa-user-friends" style="font-size:30px; opacity:0.3; margin-bottom:8px; display:block;"></i>
                            Вы пока не предоставили доступ ни одному сотруднику.<br>
                            Нажмите <strong>«Выдать доступ сотруднику»</strong>, чтобы привязать кассира или менеджера к вашей организации.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($membersList as $mem): ?>
                        <tr>
                            <td>
                                <strong style="color:var(--text-primary); font-size:15px;">
                                    <?= htmlspecialchars($mem['first_name'] . ' ' . $mem['last_name']) ?>
                                </strong>
                                <br><small class="text-muted"><i class="fas fa-envelope"></i> <?= htmlspecialchars($mem['email']) ?></small>
                                <?php if (!empty($mem['phone'])): ?>
                                    <br><small class="text-muted"><i class="fas fa-phone"></i> <?= htmlspecialchars($mem['phone']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong class="text-primary"><?= htmlspecialchars($mem['org_name']) ?></strong>
                            </td>
                            <td>
                                <span class="badge" style="background:rgba(99,102,241,0.18); color:var(--primary); border:1px solid var(--primary); font-size:13px; font-weight:700;">
                                    <i class="fas fa-user-tag"></i> <?= htmlspecialchars($mem['position_title'] ?: 'Сотрудник') ?>
                                </span>
                            </td>
                            <td>
                                <?php
                                $roleColors = [
                                    'manager'  => 'background:#f59e0b; color:#000;',
                                    'cashier'  => 'background:#10b981; color:#fff;',
                                    'director' => 'background:#3b82f6; color:#fff;',
                                    'employee' => 'background:var(--bg-secondary); color:var(--text-secondary);'
                                ];
                                $roleTitles = [
                                    'manager'  => 'Менеджер',
                                    'cashier'  => 'Кассир',
                                    'director' => 'Директор',
                                    'employee' => 'Сотрудник'
                                ];
                                $rStyle = $roleColors[$mem['role_in_org']] ?? $roleColors['employee'];
                                $rTitle = $roleTitles[$mem['role_in_org']] ?? htmlspecialchars($mem['role_in_org']);
                                ?>
                                <span class="badge" style="<?= $rStyle ?> font-weight:700;">
                                    <?= $rTitle ?>
                                </span>
                            </td>
                            <td>
                                <small class="text-muted"><?= date('d.m.Y H:i', strtotime($mem['created_at'])) ?></small>
                            </td>
                            <td>
                                <div style="display:flex; gap:6px;">
                                    <button type="button" class="btn btn-secondary btn-sm" onclick='openEditMemberModal(<?= json_encode($mem, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>)' title="Изменить должность">
                                        <i class="fas fa-edit"></i>
                                    </button>

                                    <form method="POST" style="margin:0;" onsubmit="return confirm('Вы уверены, что хотите отозвать доступ к организации у сотрудника «<?= htmlspecialchars(addslashes($mem['first_name'] . ' ' . $mem['last_name'])) ?>»?')">
                                        <?= Security::csrfField() ?>
                                        <input type="hidden" name="action" value="revoke_member_access">
                                        <input type="hidden" name="member_id" value="<?= $mem['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm" title="Отозвать доступ">
                                            <i class="fas fa-user-minus"></i>
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
</div>

<!-- 3. ТАБЛИЦА ОРГАНИЗАЦИЙ, К КОТОРЫМ ВАМ ОТКРЫТ ДОСТУП В КАЧЕСТВЕ СОТРУДНИКА -->
<?php if (!empty($sharedOrganizations)): ?>
<div class="card mb-4" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:24px;">
    <h3 class="mb-3"><i class="fas fa-user-check text-success"></i> Организации, к которым вам открыт доступ (<?= count($sharedOrganizations) ?>)</h3>
    <p class="text-muted mb-3" style="font-size:13px;">Вам назначен доступ в этих организациях руководством:</p>

    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Организация</th>
                    <th>Владелец / Директор</th>
                    <th>Ваша должность</th>
                    <th>Ваша роль</th>
                    <th>Дата назначения</th>
                    <th>Действие</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sharedOrganizations as $so): ?>
                    <tr>
                        <td>
                            <strong style="color:var(--text-primary); font-size:15px;"><?= htmlspecialchars($so['name']) ?></strong>
                            <?php if (!empty($so['legal_name'])): ?>
                                <br><small class="text-muted"><?= htmlspecialchars($so['legal_name']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong><?= htmlspecialchars($so['director_name']) ?></strong>
                            <?php if (!empty($so['director_phone'])): ?>
                                <br><small class="text-muted"><?= htmlspecialchars($so['director_phone']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge-primary" style="font-size:13px;">
                                <i class="fas fa-user-tag"></i> <?= htmlspecialchars($so['position_title'] ?: 'Сотрудник') ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge badge-info" style="font-size:12px;">
                                <?= htmlspecialchars(ucfirst($so['role_in_org'])) ?>
                            </span>
                        </td>
                        <td>
                            <small class="text-muted"><?= date('d.m.Y H:i', strtotime($so['access_granted_at'])) ?></small>
                        </td>
                        <td>
                            <a href="/user/cashier.php" class="btn btn-primary btn-sm">
                                <i class="fas fa-cash-register"></i> Открыть в Кассе
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- МОДАЛЬНОЕ ОКНО: ВЫДАТЬ ДОСТУП СОТРУДНИКУ И НАЗНАЧИТЬ ДОЛЖНОСТЬ -->
<div id="grantAccessModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7); z-index:9999; align-items:center; justify-content:center; padding:20px;">
    <div style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); max-width:540px; width:100%; padding:28px; box-shadow:0 20px 40px rgba(0,0,0,0.5);">
        <h3 class="mb-2" style="color:var(--primary);"><i class="fas fa-user-plus"></i> Выдать доступ сотруднику</h3>
        <p class="text-muted mb-3" style="font-size:13px;">
            Предоставьте сотруднику доступ к вашей организации и назначьте ему должность.
        </p>

        <form method="POST">
            <?= Security::csrfField() ?>
            <input type="hidden" name="action" value="grant_member_access">

            <!-- Выбор организации -->
            <div class="form-group mb-3">
                <label class="form-label">Организация *</label>
                <select name="org_id" id="grantOrgId" class="form-control" required>
                    <?php foreach ($organizations as $org): ?>
                        <option value="<?= $org['id'] ?>"><?= htmlspecialchars($org['name']) ?> (БИН/ИНН: <?= htmlspecialchars($org['inn'] ?: '—') ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Выбор сотрудника -->
            <div class="form-group mb-3">
                <label class="form-label">Сотрудник (Пользователь системы) *</label>
                <select name="user_id" id="grantUserId" class="form-control" required>
                    <option value="">-- Выберите сотрудника из списка --</option>
                    <?php foreach ($availableUsers as $au): ?>
                        <option value="<?= $au['id'] ?>">
                            <?= htmlspecialchars($au['first_name'] . ' ' . $au['last_name']) ?> (<?= htmlspecialchars($au['email']) ?><?= !empty($au['phone']) ? ', ' . htmlspecialchars($au['phone']) : '' ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (empty($availableUsers)): ?>
                    <small class="text-danger">В системе пока нет других пользователей. Зарегистрируйте аккаунт сотрудника через форму регистрации.</small>
                <?php endif; ?>
            </div>

            <!-- Выбор должности из существующих -->
            <div class="form-group mb-2">
                <label class="form-label">Должность из справочника</label>
                <select name="position_id" id="grantPosId" class="form-control">
                    <option value="">-- Выберите должность или введите ниже --</option>
                    <?php foreach ($positionsList as $pos): ?>
                        <option value="<?= $pos['id'] ?>" data-org="<?= (int)$pos['organization_id'] ?>">
                            <?= htmlspecialchars($pos['title']) ?><?= !empty($pos['department']) ? ' (' . htmlspecialchars($pos['department']) . ')' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Либо ввод новой должности -->
            <div class="form-group mb-3">
                <label class="form-label">Или введите название новой должности</label>
                <input type="text" name="custom_position" id="grantCustomPos" class="form-control" placeholder="Например: Старший кассир, Администратор зала, Бухгалтер...">
                <small class="text-muted">Если вы введёте новую должность, она автоматически сохранится в системе.</small>
            </div>

            <!-- Роль в организации -->
            <div class="form-group mb-3">
                <label class="form-label">Роль в торговой точке / кассе *</label>
                <select name="role_in_org" id="grantRoleInOrg" class="form-control" required>
                    <option value="cashier" selected>Кассир (Оформление продаж и чеков)</option>
                    <option value="manager">Менеджер (Оформление чеков + право на возврат и удаление)</option>
                    <option value="director">Директор (Полный доступ к точке)</option>
                    <option value="employee">Сотрудник (Обычный доступ)</option>
                </select>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="btn btn-secondary" onclick="closeGrantAccessModal()">Отмена</button>
                <button type="submit" class="btn btn-primary" <?= empty($availableUsers) ? 'disabled' : '' ?>>
                    <i class="fas fa-check"></i> Выдать доступ и должность
                </button>
            </div>
        </form>
    </div>
</div>

<!-- МОДАЛЬНОЕ ОКНО РЕДАКТИРОВАНИЯ КОНТАКТОВ ОРГАНИЗАЦИИ -->
<div id="editOrgModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7); z-index:9999; align-items:center; justify-content:center; padding:20px;">
    <div style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); max-width:540px; width:100%; padding:28px; box-shadow:0 20px 40px rgba(0,0,0,0.5);">
        <h3 class="mb-3"><i class="fas fa-edit text-warning"></i> Обновить контакты организации</h3>
        <p class="text-muted mb-3" style="font-size:13px;">
            Организация: <strong id="mOrgName" class="text-primary"></strong>
        </p>

        <form method="POST">
            <?= Security::csrfField() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="org_id" id="mOrgId" value="">

            <div class="form-group mb-2">
                <label class="form-label">Телефон</label>
                <input type="text" name="director_phone" id="mPhone" class="form-control">
            </div>

            <div class="form-group mb-2">
                <label class="form-label">Юридический / фактический адрес</label>
                <input type="text" name="address" id="mAddress" class="form-control">
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;" class="mb-2">
                <div class="form-group mb-0">
                    <label class="form-label">Банк</label>
                    <input type="text" name="bank_name" id="mBank" class="form-control">
                </div>
                <div class="form-group mb-0">
                    <label class="form-label">Расчетный счёт (IBAN)</label>
                    <input type="text" name="bank_account" id="mAccount" class="form-control">
                </div>
            </div>

            <div class="form-group mb-3">
                <label class="form-label">Описание / Сфера деятельности</label>
                <textarea name="description" id="mDesc" class="form-control" rows="2"></textarea>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Отмена</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Сохранить изменения
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openGrantAccessModal(orgId = null, orgName = '') {
    if (orgId) {
        document.getElementById('grantOrgId').value = orgId;
    }
    document.getElementById('grantCustomPos').value = '';
    document.getElementById('grantPosId').value = '';
    document.getElementById('grantAccessModal').style.display = 'flex';
}

function closeGrantAccessModal() {
    document.getElementById('grantAccessModal').style.display = 'none';
}

function openEditMemberModal(mem) {
    document.getElementById('grantOrgId').value = mem.organization_id;
    document.getElementById('grantUserId').value = mem.user_id;
    document.getElementById('grantCustomPos').value = mem.position_title || '';
    if (mem.position_id) {
        document.getElementById('grantPosId').value = mem.position_id;
    }
    document.getElementById('grantRoleInOrg').value = mem.role_in_org || 'employee';
    document.getElementById('grantAccessModal').style.display = 'flex';
}

function openEditModal(org) {
    document.getElementById('mOrgId').value = org.id;
    document.getElementById('mOrgName').innerText = org.name;
    document.getElementById('mPhone').value = org.director_phone || '';
    document.getElementById('mAddress').value = org.address || '';
    document.getElementById('mBank').value = org.bank_name || '';
    document.getElementById('mAccount').value = org.bank_account || '';
    document.getElementById('mDesc').value = org.description || '';
    document.getElementById('editOrgModal').style.display = 'flex';
}

function closeEditModal() {
    document.getElementById('editOrgModal').style.display = 'none';
}
</script>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
