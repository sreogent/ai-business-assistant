<?php
declare(strict_types=1);

/**
 * AI Business Assistant - Organizations & Directors Management
 * Управление компаниями, типами бизнеса, реквизитами и генеральными директорами
 */

require_once dirname(__DIR__) . '/includes/Auth.php';
require_once dirname(__DIR__) . '/includes/Mailer.php';
require_once dirname(__DIR__) . '/includes/OrganizationGenerator.php';

$admin = Auth::requireAdmin();
$pdo = Database::getConnection();
$adminId = (int)$admin['id'];

$cfg = $pdo->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$baseUrl = rtrim($cfg['app_base_url'] ?? 'http://127.0.0.1:8000', '/');

// Убедимся, что колонка business_type существует
try {
    $cols = $pdo->query("SHOW COLUMNS FROM organizations")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('business_type', $cols, true)) {
        $pdo->exec("ALTER TABLE organizations ADD COLUMN business_type VARCHAR(50) NOT NULL DEFAULT 'general' AFTER name");
    }
} catch (\Exception $e) {}

$businessTypes = OrganizationGenerator::getBusinessTypes();

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!Security::verifyCsrf($token)) {
        $error = "Истёк токен безопасности. Повторите попытку.";
    } else {
        $action = $_POST['action'] ?? '';

        // 1. Создание организации
        if ($action === 'create') {
            $name          = trim($_POST['name'] ?? '');
            $businessType  = trim($_POST['business_type'] ?? 'general');
            $legalName     = trim($_POST['legal_name'] ?? '');
            $directorName  = trim($_POST['director_name'] ?? '');
            $directorEmail = trim($_POST['director_email'] ?? '');
            $directorPhone = trim($_POST['director_phone'] ?? '');
            $inn           = trim($_POST['inn'] ?? '');
            $kpp           = trim($_POST['kpp'] ?? '');
            $address       = trim($_POST['address'] ?? '');
            $bankName      = trim($_POST['bank_name'] ?? '');
            $bankAccount   = trim($_POST['bank_account'] ?? '');
            $description   = trim($_POST['description'] ?? '');
            $autoGenerate  = !empty($_POST['auto_generate_catalog']);

            if (empty($name) || empty($directorName)) {
                $error = "Название организации и ФИО директора обязательны для заполнения.";
            } else {
                $assignedUserId = (int)($_POST['assigned_user_id'] ?? 0);
                if ($assignedUserId <= 0 && !empty($directorEmail)) {
                    $uStmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
                    $uStmt->execute([$directorEmail]);
                    $uRow = $uStmt->fetch();
                    if ($uRow) {
                        $assignedUserId = (int)$uRow['id'];
                    }
                }
                if ($assignedUserId <= 0) {
                    $assignedUserId = $adminId;
                }

                $ins = $pdo->prepare("
                    INSERT INTO organizations 
                    (user_id, business_type, name, legal_name, director_name, director_email, director_phone, inn, kpp, address, bank_name, bank_account, description)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $ins->execute([
                    $assignedUserId, $businessType, $name, $legalName, $directorName, $directorEmail, $directorPhone,
                    $inn, $kpp, $address, $bankName, $bankAccount, $description
                ]);
                $newOrgId = (int)$pdo->lastInsertId();

                Security::log($pdo, 'admin_create_organization', "Создана организация: {$name} [тип: {$businessType}] (Директор: {$directorName}, User: #{$assignedUserId})", $adminId);

                // Автоматическая генерация каталога и должностей под сферу бизнеса
                $genNotice = '';
                if ($autoGenerate && $newOrgId > 0) {
                    $stats = OrganizationGenerator::setupOrganization($newOrgId, $businessType, $assignedUserId);
                    $bTitle = $businessTypes[$businessType]['title'] ?? $businessType;
                    $genNotice = "<br>✨ <strong>AI-настройка:</strong> успешно созданы стартовые категории ({$stats['categories']}), позиции/услуги ({$stats['items']}) и должности ({$stats['positions']}) под сферу «{$bTitle}».";
                }

                $mailNotice = '';
                if (!empty($directorEmail) && filter_var($directorEmail, FILTER_VALIDATE_EMAIL)) {
                    $sent = Mailer::sendOrganizationCreated($directorEmail, $directorName, $name, $legalName, $inn, $baseUrl);
                    $mailNotice = $sent 
                        ? " Официальное письмо отправлено Генеральному Директору на <strong>" . htmlspecialchars($directorEmail) . "</strong>." 
                        : " (Письмо директору сохранено в журнал отправки)";
                }

                $success = "✅ Организация «" . htmlspecialchars($name) . "» успешно добавлена!{$mailNotice}{$genNotice}";
            }
        }

        // 2. Редактирование организации
        elseif ($action === 'update') {
            $orgId         = (int)($_POST['org_id'] ?? 0);
            $name          = trim($_POST['name'] ?? '');
            $businessType  = trim($_POST['business_type'] ?? 'general');
            $legalName     = trim($_POST['legal_name'] ?? '');
            $directorName  = trim($_POST['director_name'] ?? '');
            $directorEmail = trim($_POST['director_email'] ?? '');
            $directorPhone = trim($_POST['director_phone'] ?? '');
            $inn           = trim($_POST['inn'] ?? '');
            $kpp           = trim($_POST['kpp'] ?? '');
            $address       = trim($_POST['address'] ?? '');
            $bankName      = trim($_POST['bank_name'] ?? '');
            $bankAccount   = trim($_POST['bank_account'] ?? '');
            $description   = trim($_POST['description'] ?? '');
            $assignedUserId = (int)($_POST['assigned_user_id'] ?? 0);

            if ($orgId <= 0 || empty($name) || empty($directorName)) {
                $error = "Заполните обязательные поля: название и ФИО директора.";
            } else {
                if ($assignedUserId <= 0 && !empty($directorEmail)) {
                    $uStmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
                    $uStmt->execute([$directorEmail]);
                    $uRow = $uStmt->fetch();
                    if ($uRow) {
                        $assignedUserId = (int)$uRow['id'];
                    }
                }

                if ($assignedUserId > 0) {
                    $upd = $pdo->prepare("
                        UPDATE organizations 
                        SET user_id = ?, business_type = ?, name = ?, legal_name = ?, director_name = ?, director_email = ?, director_phone = ?,
                            inn = ?, kpp = ?, address = ?, bank_name = ?, bank_account = ?, description = ?
                        WHERE id = ?
                    ");
                    $upd->execute([
                        $assignedUserId, $businessType, $name, $legalName, $directorName, $directorEmail, $directorPhone,
                        $inn, $kpp, $address, $bankName, $bankAccount, $description, $orgId
                    ]);
                } else {
                    $upd = $pdo->prepare("
                        UPDATE organizations 
                        SET business_type = ?, name = ?, legal_name = ?, director_name = ?, director_email = ?, director_phone = ?,
                            inn = ?, kpp = ?, address = ?, bank_name = ?, bank_account = ?, description = ?
                        WHERE id = ?
                    ");
                    $upd->execute([
                        $businessType, $name, $legalName, $directorName, $directorEmail, $directorPhone,
                        $inn, $kpp, $address, $bankName, $bankAccount, $description, $orgId
                    ]);
                }
                Security::log($pdo, 'admin_update_organization', "Обновлена организация #{$orgId} ({$name})", $adminId);
                $success = "Данные организации успешно обновлены.";
            }
        }

        // 3. Переключение статуса активности
        elseif ($action === 'toggle_status') {
            $orgId = (int)($_POST['org_id'] ?? 0);
            $pdo->prepare("UPDATE organizations SET is_active = IF(is_active=1, 0, 1) WHERE id = ?")->execute([$orgId]);
            $success = "Статус организации изменён.";
        }

        // 4. Полное каскадное удаление организации со всеми связанными данными и уведомлением на почту
        elseif ($action === 'delete') {
            $orgId = (int)($_POST['org_id'] ?? 0);

            // Получаем реквизиты компании и контакты директора до удаления
            $orgStmt = $pdo->prepare("SELECT * FROM organizations WHERE id = ? LIMIT 1");
            $orgStmt->execute([$orgId]);
            $org = $orgStmt->fetch();

            if (!$org) {
                $error = "Организация #{$orgId} не найдена в базе данных.";
            } else {
                $orgName       = $org['name'];
                $directorName  = $org['director_name'] ?: 'Руководитель';
                $directorEmail = trim($org['director_email'] ?? '');

                // 1) Чеки кассы и позиции в чеках
                $orderIds = $pdo->query("SELECT id FROM cash_orders WHERE organization_id = {$orgId}")->fetchAll(PDO::FETCH_COLUMN);
                $ordersCount = count($orderIds);
                if (!empty($orderIds)) {
                    $inOrders = implode(',', array_map('intval', $orderIds));
                    $pdo->exec("DELETE FROM cash_order_items WHERE order_id IN ({$inOrders})");
                }
                $pdo->prepare("DELETE FROM cash_orders WHERE organization_id = ?")->execute([$orgId]);

                // 2) Товары, блюда и услуги номенклатуры
                $itemsStmt = $pdo->prepare("DELETE FROM menu_items WHERE organization_id = ?");
                $itemsStmt->execute([$orgId]);
                $itemsCount = $itemsStmt->rowCount();

                // 3) Категории меню / каталога
                $catsStmt = $pdo->prepare("DELETE FROM menu_categories WHERE organization_id = ?");
                $catsStmt->execute([$orgId]);
                $catsCount = $catsStmt->rowCount();

                // 4) Штатные должности компании
                $posStmt = $pdo->prepare("DELETE FROM positions WHERE organization_id = ?");
                $posStmt->execute([$orgId]);
                $posCount = $posStmt->rowCount();

                // 5) Корпоративные каналы и сообщения чатов организации
                $chanIds = $pdo->query("SELECT id FROM team_chat_channels WHERE organization_id = {$orgId} OR channel_key = 'org_{$orgId}'")->fetchAll(PDO::FETCH_COLUMN);
                $deletedChatsCount = count($chanIds);
                if (!empty($chanIds)) {
                    $inChans = implode(',', array_map('intval', $chanIds));
                    $pdo->exec("DELETE FROM team_chat_messages WHERE channel_id IN ({$inChans})");
                    $pdo->exec("DELETE FROM team_chat_channels WHERE id IN ({$inChans})");
                }

                // 6) Удаление самой компании
                $pdo->prepare("DELETE FROM organizations WHERE id = ?")->execute([$orgId]);

                $stats = [
                    'categories' => $catsCount,
                    'items'      => $itemsCount,
                    'positions'  => $posCount,
                    'orders'     => $ordersCount,
                    'chats'      => $deletedChatsCount
                ];

                // 6) Отправка официального уведомления Генеральному Директору
                $mailNotice = '';
                if (!empty($directorEmail) && filter_var($directorEmail, FILTER_VALIDATE_EMAIL)) {
                    $sent = Mailer::sendOrganizationDeleted($directorEmail, $directorName, $orgName, $baseUrl, $stats);
                    if ($sent) {
                        $mailNotice = "<br>✉️ Официальное уведомление об удалении отправлено Генеральному Директору на <strong>" . htmlspecialchars($directorEmail) . "</strong> (проверьте папку «Входящие» или «Спам»).";
                    } else {
                        $err = Mailer::$lastError ? ": " . htmlspecialchars(Mailer::$lastError) : "";
                        $mailNotice = "<br>⚠️ Уведомление директору сохранено в журнал (ошибка SMTP{$err}).";
                    }
                } else {
                    $mailNotice = "<br>ℹ️ Email генерального директора не был указан — письмо не отправлялось.";
                }

                Security::log(
                    $pdo,
                    'admin_delete_organization',
                    "Удалена организация #{$orgId} ({$orgName}). Очищено: {$catsCount} категорий, {$itemsCount} позиций, {$posCount} должностей, {$ordersCount} заказов",
                    $adminId
                );

                $success = "🗑️ Организация «<strong>" . htmlspecialchars($orgName) . "</strong>» и вся её инфраструктура полностью удалены!<br>"
                         . "• Удалено категорий меню: <strong>{$catsCount}</strong><br>"
                         . "• Удалено товаров / услуг: <strong>{$itemsCount}</strong><br>"
                         . "• Удалено штатных должностей: <strong>{$posCount}</strong><br>"
                         . "• Очищено кассовых чеков: <strong>{$ordersCount}</strong>"
                         . $mailNotice;
            }
        }

        // 5. Выдача доступа сотруднику к организации и назначение должности (Администратор)
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

            $orgCheck = $pdo->prepare("SELECT id, name FROM organizations WHERE id = ?");
            $orgCheck->execute([$orgId]);
            $orgData = $orgCheck->fetch();

            if (!$orgData || $targetUserId <= 0) {
                $error = "Пожалуйста, выберите организацию и сотрудника.";
            } else {
                $finalPositionTitle = '';
                if ($positionId) {
                    $pStmt = $pdo->prepare("SELECT title FROM positions WHERE id = ?");
                    $pStmt->execute([$positionId]);
                    $finalPositionTitle = (string)$pStmt->fetchColumn();
                }

                if (empty($finalPositionTitle) && !empty($customPosition)) {
                    $finalPositionTitle = $customPosition;
                    $insPos = $pdo->prepare("INSERT INTO positions (organization_id, title, user_id, department) VALUES (?, ?, ?, 'Основной')");
                    $insPos->execute([$orgId, $finalPositionTitle, $adminId]);
                    $positionId = (int)$pdo->lastInsertId();
                }

                if (empty($finalPositionTitle)) {
                    $finalPositionTitle = 'Сотрудник';
                }

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
                $memStmt->execute([$orgId, $targetUserId, $positionId, $finalPositionTitle, $roleInOrg, $adminId]);

                $uStmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
                $uStmt->execute([$targetUserId]);
                $uRow = $uStmt->fetch();
                $targetName = $uRow ? ($uRow['first_name'] . ' ' . $uRow['last_name']) : "ID #{$targetUserId}";

                $pdo->prepare("
                    INSERT INTO notifications (user_id, type, title, body, link)
                    VALUES (?, 'info', 'Доступ к организации', ?, '/user/organizations.php')
                ")->execute([$targetUserId, "Администратор выдал вам доступ к организации «{$orgData['name']}» в должности: {$finalPositionTitle} (роль: {$roleInOrg})."]);

                Security::log($pdo, 'admin_grant_org_access', "Админ выдал доступ к орг #{$orgId} ({$orgData['name']}) сотруднику #{$targetUserId} ({$targetName}), должность: {$finalPositionTitle}", $adminId);

                $success = "Сотруднику <strong>" . htmlspecialchars($targetName) . "</strong> успешно выдан доступ к организации в должности: <strong>" . htmlspecialchars($finalPositionTitle) . "</strong>!";
            }
        }

        // 6. Отзыв доступа сотрудника
        elseif ($action === 'revoke_member_access') {
            $memberId = (int)($_POST['member_id'] ?? 0);
            $mCheck = $pdo->prepare("
                SELECT om.id, om.user_id, o.name as org_name, u.first_name, u.last_name 
                FROM organization_members om
                JOIN organizations o ON om.organization_id = o.id
                JOIN users u ON om.user_id = u.id
                WHERE om.id = ?
            ");
            $mCheck->execute([$memberId]);
            $mRow = $mCheck->fetch();

            if ($mRow) {
                $pdo->prepare("DELETE FROM organization_members WHERE id = ?")->execute([$memberId]);
                Security::log($pdo, 'admin_revoke_org_access', "Админ отозвал доступ сотрудника #{$mRow['user_id']} ({$mRow['first_name']} {$mRow['last_name']}) к орг «{$mRow['org_name']}»", $adminId);
                $success = "Доступ сотрудника <strong>" . htmlspecialchars($mRow['first_name'] . ' ' . $mRow['last_name']) . "</strong> успешно отозван.";
            } else {
                $error = "Запись сотрудника не найдена.";
            }
        }
    }
}

$organizations = $pdo->query("SELECT * FROM organizations ORDER BY id DESC")->fetchAll();

// Безопасная выборка пользователей с конкатенацией имени и JOIN roles
$allUsers = $pdo->query("
    SELECT u.id, CONCAT(u.first_name, ' ', u.last_name) AS name, u.email, r.name AS role 
    FROM users u 
    JOIN roles r ON u.role_id = r.id 
    ORDER BY u.first_name ASC
")->fetchAll();

$membersList = [];
$positionsList = [];
try {
    $membersList = $pdo->query("
        SELECT om.*, o.name AS org_name, u.first_name, u.last_name, u.email, r.name AS role_name
        FROM organization_members om
        JOIN organizations o ON om.organization_id = o.id
        JOIN users u ON om.user_id = u.id
        LEFT JOIN roles r ON u.role_id = r.id
        ORDER BY om.id DESC
    ")->fetchAll();

    $positionsList = $pdo->query("SELECT id, organization_id, title, department FROM positions ORDER BY title ASC")->fetchAll();
} catch (\Exception $e) {}

$pageTitle = 'Организации и Директора | AI Business Assistant';
require dirname(__DIR__) . '/includes/header.php';
?>

<div style="margin-bottom:24px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
    <div>
        <h2><i class="fas fa-building text-primary"></i> Организации и Директора</h2>
        <p class="text-muted">Управление юридическими лицами, сферами бизнеса и интеллектуальной настройкой каталогов</p>
    </div>
    <div style="display:flex; gap:10px;">
        <a href="/admin/menu_items.php" class="btn btn-secondary">
            <i class="fas fa-list-alt"></i> Каталог / Меню
        </a>
        <a href="/admin/positions.php" class="btn btn-secondary">
            <i class="fas fa-user-tag"></i> Должности
        </a>
        <a href="/admin/cashier.php" class="btn btn-primary">
            <i class="fas fa-cash-register"></i> Касса
        </a>
    </div>
</div>

<?php if ($success): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $success ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div style="display:grid; grid-template-columns: 1fr 2fr; gap:24px; align-items:start;">

    <!-- Форма создания/редактирования -->
    <div class="card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:24px;">
        <h3 id="formTitle" class="mb-3"><i class="fas fa-plus-circle text-primary"></i> Новая организация</h3>
        
        <form method="POST" id="orgForm">
            <?= Security::csrfField() ?>
            <input type="hidden" name="action" id="formAction" value="create">
            <input type="hidden" name="org_id" id="formOrgId" value="">

            <div class="form-group">
                <label class="form-label">Сфера деятельности / Тип бизнеса *</label>
                <select name="business_type" id="f_business_type" class="form-control" style="font-weight:600; color:#818cf8;" required>
                    <?php foreach ($businessTypes as $key => $bt): ?>
                        <option value="<?= $key ?>" <?= $key === 'coffee_shop' ? 'selected' : '' ?>>
                            <?= $bt['title'] ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted">Система автоматически подстроит интерфейс (меню или прайс-лист) и создаст подходящие позиции</small>
            </div>

            <div class="form-group">
                <label class="form-label">Название организации *</label>
                <input type="text" name="name" id="f_name" class="form-control" required placeholder="ТОО «Тендер Про» / Кофейня «Coffee Time»">
            </div>

            <div class="form-group">
                <label class="form-label">Юридическое наименование</label>
                <input type="text" name="legal_name" id="f_legal_name" class="form-control" placeholder="ТОО «Тендер Про Консалтинг»">
            </div>

            <div class="form-group">
                <label class="form-label">ФИО Генерального Директора *</label>
                <input type="text" name="director_name" id="f_director_name" class="form-control" required placeholder="Алибек Касымов">
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                <div class="form-group">
                    <label class="form-label">Email директора</label>
                    <input type="email" name="director_email" id="f_director_email" class="form-control" placeholder="director@company.kz">
                </div>
                <div class="form-group">
                    <label class="form-label">Телефон директора</label>
                    <input type="text" name="director_phone" id="f_director_phone" class="form-control" placeholder="+7 777 123 4567">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Привязать к аккаунту пользователя</label>
                <select name="assigned_user_id" id="f_assigned_user_id" class="form-control">
                    <option value="0">— Автоматически (по Email директора) —</option>
                    <?php foreach ($allUsers as $u): ?>
                        <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['name']) ?> (<?= htmlspecialchars($u['email']) ?>) [<?= htmlspecialchars($u['role']) ?>]</option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted">Выбранный пользователь получит доступ к кассе, позициям и каталогу организации</small>
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                <div class="form-group">
                    <label class="form-label">ИНН / БИН</label>
                    <input type="text" name="inn" id="f_inn" class="form-control" placeholder="123456789012">
                </div>
                <div class="form-group">
                    <label class="form-label">КПП</label>
                    <input type="text" name="kpp" id="f_kpp" class="form-control" placeholder="123456789">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Юридический адрес</label>
                <input type="text" name="address" id="f_address" class="form-control" placeholder="г. Алматы, пр. Достык, 105">
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                <div class="form-group">
                    <label class="form-label">Банк</label>
                    <input type="text" name="bank_name" id="f_bank_name" class="form-control" placeholder="Kaspi Bank / Halyk Bank">
                </div>
                <div class="form-group">
                    <label class="form-label">Расчетный счёт (IBAN)</label>
                    <input type="text" name="bank_account" id="f_bank_account" class="form-control" placeholder="KZ123456789012345678">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Описание / Специфика</label>
                <textarea name="description" id="f_description" class="form-control" rows="2" placeholder="Сопровождение государственных закупок, тендеры под ключ..."></textarea>
            </div>

            <div class="form-group" id="autoGenGroup" style="background:rgba(99,102,241,0.08); border:1px dashed #6366f1; border-radius:8px; padding:12px;">
                <label style="display:flex; align-items:center; gap:8px; cursor:pointer; margin:0; font-size:13px;">
                    <input type="checkbox" name="auto_generate_catalog" id="f_auto_generate" value="1" checked style="width:18px; height:18px;">
                    <span><strong>⚡ AI-генератор:</strong> автоматически создать стартовый каталог (услуги/меню) и штатные должности под эту сферу</span>
                </label>
            </div>

            <div style="display:flex; gap:10px;">
                <button type="submit" id="submitBtn" class="btn btn-primary" style="flex:1;">
                    <i class="fas fa-save"></i> Сохранить организацию
                </button>
                <button type="button" id="cancelBtn" class="btn btn-secondary" style="display:none;" onclick="resetForm()">
                    Отмена
                </button>
            </div>
        </form>
    </div>

    <!-- Список организаций -->
    <div class="card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:24px;">
        <h3 class="mb-3"><i class="fas fa-list text-secondary"></i> Список организаций (<?= count($organizations) ?>)</h3>

        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Организация / Сфера</th>
                        <th>Директор</th>
                        <th>Контакты / Реквизиты</th>
                        <th>Сотрудники</th>
                        <th>Статус</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($organizations)): ?>
                        <tr><td colspan="6" class="text-center text-muted" style="padding:32px;">Организаций пока не добавлено</td></tr>
                    <?php else: ?>
                        <?php foreach ($organizations as $org): ?>
                            <?php 
                                $bType = $org['business_type'] ?? 'general';
                                $bMeta = $businessTypes[$bType] ?? $businessTypes['general'];
                                $orgMembersCount = count(array_filter($membersList, fn($m) => (int)$m['organization_id'] === (int)$org['id']));
                            ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($org['name']) ?></strong>
                                    <div style="margin-top:4px;">
                                        <span class="badge" style="background:rgba(99,102,241,0.15); color:#818cf8; border:1px solid rgba(99,102,241,0.3);">
                                            <i class="fas <?= $bMeta['icon'] ?>"></i> <?= htmlspecialchars($bMeta['title']) ?>
                                        </span>
                                    </div>
                                    <?php if (!empty($org['legal_name'])): ?>
                                        <small class="text-muted"><?= htmlspecialchars($org['legal_name']) ?></small>
                                    <?php endif; ?>
                                    <?php if (!empty($org['description'])): ?>
                                        <br><small class="text-muted" style="font-style:italic;"><?= htmlspecialchars($org['description']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong class="text-primary"><i class="fas fa-user-tie"></i> <?= htmlspecialchars($org['director_name']) ?></strong>
                                    <?php if (!empty($org['director_email'])): ?>
                                        <br><small class="text-muted"><?= htmlspecialchars($org['director_email']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($org['director_phone'])): ?>
                                        <small><i class="fas fa-phone-alt"></i> <?= htmlspecialchars($org['director_phone']) ?></small><br>
                                    <?php endif; ?>
                                    <?php if (!empty($org['inn'])): ?>
                                        <small class="text-muted">БИН/ИНН: <?= htmlspecialchars($org['inn']) ?></small><br>
                                    <?php endif; ?>
                                    <?php if (!empty($org['bank_name'])): ?>
                                        <small class="text-muted"><?= htmlspecialchars($org['bank_name']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge" style="background:rgba(59,130,246,0.15); color:#60a5fa; border:1px solid rgba(59,130,246,0.3); font-size:13px;">
                                        <i class="fas fa-users"></i> <?= $orgMembersCount ?> чел.
                                    </span>
                                </td>
                                <td>
                                    <?php if ($org['is_active']): ?>
                                        <span class="badge badge-success">Активна</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger">Отключена</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="display:flex; gap:6px; flex-wrap:wrap;">
                                        <button type="button" class="btn btn-primary btn-sm" onclick="openGrantAccessModal(<?= $org['id'] ?>, '<?= htmlspecialchars(addslashes($org['name'])) ?>')" title="Выдать доступ сотруднику и назначить должность">
                                            <i class="fas fa-user-plus"></i> Доступ
                                        </button>

                                        <button type="button" class="btn btn-secondary btn-sm" onclick='editOrg(<?= json_encode($org, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>)'>
                                             <i class="fas fa-edit"></i>
                                        </button>

                                        <form method="POST" style="margin:0;">
                                            <?= Security::csrfField() ?>
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="org_id" value="<?= $org['id'] ?>">
                                            <button type="submit" class="btn btn-warning btn-sm" title="Переключить статус">
                                                <i class="fas fa-power-off"></i>
                                            </button>
                                        </form>

                                        <form method="POST" style="margin:0;" onsubmit="return confirm('Внимание! При удалении организации «<?= htmlspecialchars(addslashes($org['name'])) ?>» будут полностью стёрты все её настройки, каталог меню/товаров, категории, должности и история кассы, а директору будет направлено официальное письмо. Продолжить удаление?')">
                                            <?= Security::csrfField() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="org_id" value="<?= $org['id'] ?>">
                                            <button type="submit" class="btn btn-danger btn-sm" title="Удалить со всеми данными">
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
    </div>
</div>

<!-- 2. ТАБЛИЦА: СОТРУДНИКИ С ДОСТУПОМ И ДОЛЖНОСТИ -->
<div class="card mt-4" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:24px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px; flex-wrap:wrap; gap:12px;">
        <div>
            <h3 style="margin:0;"><i class="fas fa-id-badge text-primary"></i> Сотрудники с доступом к организациям (<?= count($membersList) ?>)</h3>
            <p class="text-muted" style="margin:4px 0 0 0; font-size:13px;">Управление доступом пользователей к торговым точкам, должностями и правами в кассе</p>
        </div>
        <button type="button" class="btn btn-primary btn-sm" onclick="openGrantAccessModal()">
            <i class="fas fa-user-plus"></i> Назначить сотрудника
        </button>
    </div>

    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Сотрудник</th>
                    <th>Организация</th>
                    <th>Должность</th>
                    <th>Роль в кассе / точке</th>
                    <th>Дата назначения</th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($membersList)): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted" style="padding:28px;">
                            <i class="fas fa-users-slash" style="font-size:28px; opacity:0.3; margin-bottom:8px; display:block;"></i>
                            Доступ сотрудникам к организациям пока не выдавался.<br>
                            Нажмите «Назначить сотрудника», чтобы привязать пользователя и указать должность.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($membersList as $m): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($m['first_name'] . ' ' . $m['last_name']) ?></strong>
                                <br><small class="text-muted"><i class="fas fa-envelope"></i> <?= htmlspecialchars($m['email']) ?></small>
                            </td>
                            <td>
                                <strong class="text-primary"><i class="fas fa-building"></i> <?= htmlspecialchars($m['org_name']) ?></strong>
                            </td>
                            <td>
                                <span class="badge badge-primary" style="font-size:13px;">
                                    <i class="fas fa-user-tag"></i> <?= htmlspecialchars($m['position_title'] ?: 'Сотрудник') ?>
                                </span>
                            </td>
                            <td>
                                <?php 
                                    $roleBadgeColor = match($m['role_in_org']) {
                                        'director' => 'badge-danger',
                                        'manager'  => 'badge-warning',
                                        'cashier'  => 'badge-info',
                                        default    => 'badge-secondary'
                                    };
                                    $roleTitle = match($m['role_in_org']) {
                                        'director' => 'Директор',
                                        'manager'  => 'Менеджер',
                                        'cashier'  => 'Кассир',
                                        default    => 'Сотрудник'
                                    };
                                ?>
                                <span class="badge <?= $roleBadgeColor ?>" style="font-size:12px;">
                                    <?= htmlspecialchars($roleTitle) ?>
                                </span>
                            </td>
                            <td>
                                <small class="text-muted"><?= date('d.m.Y H:i', strtotime($m['created_at'])) ?></small>
                            </td>
                            <td>
                                <div style="display:flex; gap:6px;">
                                    <button type="button" class="btn btn-secondary btn-sm" onclick="openGrantAccessModal(<?= (int)$m['organization_id'] ?>, '', <?= (int)$m['user_id'] ?>, <?= (int)($m['position_id'] ?? 0) ?>, '<?= htmlspecialchars(addslashes($m['position_title'] ?? '')) ?>', '<?= htmlspecialchars(addslashes($m['role_in_org'])) ?>')" title="Изменить должность / роль">
                                        <i class="fas fa-user-edit"></i>
                                    </button>
                                    <form method="POST" style="margin:0;" onsubmit="return confirm('Отозвать доступ к организации «<?= htmlspecialchars(addslashes($m['org_name'])) ?>» у сотрудника <?= htmlspecialchars(addslashes($m['first_name'] . ' ' . $m['last_name'])) ?>?')">
                                        <?= Security::csrfField() ?>
                                        <input type="hidden" name="action" value="revoke_member_access">
                                        <input type="hidden" name="member_id" value="<?= $m['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm" title="Отозвать доступ">
                                            <i class="fas fa-user-times"></i>
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

<!-- МОДАЛЬНОЕ ОКНО: ВЫДАТЬ ДОСТУП СОТРУДНИКУ И НАЗНАЧИТЬ ДОЛЖНОСТЬ -->
<div id="grantAccessModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7); z-index:9999; align-items:center; justify-content:center; padding:20px;">
    <div style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); max-width:540px; width:100%; padding:28px; box-shadow:0 20px 40px rgba(0,0,0,0.5);">
        <h3 class="mb-2" style="color:var(--primary);"><i class="fas fa-user-plus"></i> Выдать доступ сотруднику</h3>
        <p class="text-muted mb-3" style="font-size:13px;">
            Предоставьте сотруднику доступ к организации и назначьте ему должность и полномочия.
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
                    <?php foreach ($allUsers as $u): ?>
                        <option value="<?= $u['id'] ?>">
                            <?= htmlspecialchars($u['name']) ?> (<?= htmlspecialchars($u['email']) ?> — <?= htmlspecialchars($u['role']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
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

            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
                <button type="button" class="btn btn-secondary" onclick="closeGrantAccessModal()">Отмена</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Сохранить доступ</button>
            </div>
        </form>
    </div>
</div>

<script>
function editOrg(org) {
    document.getElementById('formTitle').innerHTML = '<i class="fas fa-edit text-warning"></i> Редактировать организацию';
    document.getElementById('formAction').value = 'update';
    document.getElementById('formOrgId').value = org.id;

    document.getElementById('f_business_type').value = org.business_type || 'general';
    document.getElementById('f_name').value = org.name || '';
    document.getElementById('f_legal_name').value = org.legal_name || '';
    document.getElementById('f_director_name').value = org.director_name || '';
    document.getElementById('f_director_email').value = org.director_email || '';
    document.getElementById('f_director_phone').value = org.director_phone || '';
    document.getElementById('f_inn').value = org.inn || '';
    document.getElementById('f_kpp').value = org.kpp || '';
    document.getElementById('f_address').value = org.address || '';
    document.getElementById('f_bank_name').value = org.bank_name || '';
    document.getElementById('f_bank_account').value = org.bank_account || '';
    document.getElementById('f_description').value = org.description || '';
    document.getElementById('f_assigned_user_id').value = org.user_id || '0';

    document.getElementById('autoGenGroup').style.display = 'none';

    document.getElementById('submitBtn').innerHTML = '<i class="fas fa-check"></i> Обновить данные';
    document.getElementById('submitBtn').className = 'btn btn-warning';
    document.getElementById('cancelBtn').style.display = 'inline-block';

    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function resetForm() {
    document.getElementById('formTitle').innerHTML = '<i class="fas fa-plus-circle text-primary"></i> Новая организация';
    document.getElementById('formAction').value = 'create';
    document.getElementById('formOrgId').value = '';
    document.getElementById('orgForm').reset();
    document.getElementById('f_business_type').value = 'coffee_shop';
    document.getElementById('f_assigned_user_id').value = '0';
    document.getElementById('autoGenGroup').style.display = 'block';
    document.getElementById('submitBtn').innerHTML = '<i class="fas fa-save"></i> Сохранить организацию';
    document.getElementById('submitBtn').className = 'btn btn-primary';
    document.getElementById('cancelBtn').style.display = 'none';
}
function openGrantAccessModal(orgId = null, orgName = '', userId = null, positionId = null, customPos = '', roleInOrg = 'cashier') {
    const modal = document.getElementById('grantAccessModal');
    if (orgId) {
        document.getElementById('grantOrgId').value = orgId;
    }
    if (userId) {
        document.getElementById('grantUserId').value = userId;
    }
    if (positionId) {
        document.getElementById('grantPosId').value = positionId;
    } else {
        document.getElementById('grantPosId').value = '';
    }
    document.getElementById('grantCustomPos').value = customPos || '';
    if (roleInOrg) {
        document.getElementById('grantRoleInOrg').value = roleInOrg;
    }
    modal.style.display = 'flex';
}

function closeGrantAccessModal() {
    document.getElementById('grantAccessModal').style.display = 'none';
}
</script>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
