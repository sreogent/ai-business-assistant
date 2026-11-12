<?php
declare(strict_types=1);

/**
 * AI Business Assistant - ВРИО Владелец Платформы (Owner Console)
 * Высший уровень доступа: Финансы, Назначение ролей, Команда сайта, Глобальные переключатели
 */

require_once dirname(__DIR__) . '/includes/Auth.php';

$user = Auth::requireRole(['vrio_owner', 'admin']);
$userId = (int)$user['id'];
$pdo = Database::getConnection();

$error = null;
$success = null;

// Обработка действий ВРИО Владельца
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!Security::verifyCsrf($token)) {
        $error = "Истёк токен безопасности. Повторите попытку.";
    } else {
        $action = $_POST['action'] ?? '';

        // 1. Изменение роли сотрудника
        if ($action === 'change_user_role') {
            $targetUserId = (int)($_POST['target_user_id'] ?? 0);
            $newRoleId = (int)($_POST['role_id'] ?? 0);

            // КРИТИЧЕСКАЯ ЗАЩИТА: Никто и никогда не может изменить права Верховного Администратора
            if (Auth::isProtectedAdmin($targetUserId)) {
                $error = "⛔ КРИТИЧЕСКАЯ ЗАЩИТА: Права Верховного Администратора (Матвей Ибадлаев) пожизненные и защищены от любых изменений!";
                Security::log($pdo, 'tamper_supreme_admin_blocked', "Попытка изменения прав Верховного Администратора пользователем #{$userId}", $userId);
            } else {
                // Только Матвей Ибадлаев имеет право выдавать или забирать права Администратора
                $rStmt = $pdo->prepare("SELECT name FROM roles WHERE id = ?");
                $rStmt->execute([$newRoleId]);
                $newRoleName = $rStmt->fetchColumn();

                $currStmt = $pdo->prepare("SELECT r.name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
                $currStmt->execute([$targetUserId]);
                $currRoleName = $currStmt->fetchColumn();

                $involvesAdmin = in_array($newRoleName, ['admin', 'vrio_owner'], true) || in_array($currRoleName, ['admin', 'vrio_owner'], true);

                if ($involvesAdmin && !Auth::isSupremeOwner($user)) {
                    $error = "⛔ ОТКАЗАНО В ДОСТУПЕ: Назначать или снимать права Администратора имеет право исключительно Матвей Ибадлаев!";
                    Security::log($pdo, 'unauthorized_admin_escalation', "Попытка несанкционированного изменения прав администратора пользователем #{$userId}", $userId);
                } elseif ($targetUserId > 0 && $newRoleId > 0) {
                    $pdo->prepare("UPDATE users SET role_id = ? WHERE id = ?")->execute([$newRoleId, $targetUserId]);
                    Security::log($pdo, 'owner_change_role', "Роль пользователя #{$targetUserId} изменена на role_id={$newRoleId} ({$newRoleName})", $userId);
                    $success = "Роль пользователя успешно обновлена.";
                } else {
                    $error = "Некорректные параметры назначения роли.";
                }
            }
        }


        // 3. Сохранение глобальных системных тумблеров
        if ($action === 'save_global_switches') {
            $maintenance = isset($_POST['maintenance_mode']) ? '1' : '0';
            $registration = isset($_POST['registration_enabled']) ? '1' : '0';
            $aiGateway = isset($_POST['ai_gateway_enabled']) ? '1' : '0';

            $settings = [
                'sys_maintenance_mode'      => $maintenance,
                'sys_registration_enabled'  => $registration,
                'sys_ai_gateway_enabled'    => $aiGateway,
            ];

            foreach ($settings as $k => $v) {
                $chk = $pdo->prepare("SELECT id FROM settings WHERE setting_key = ? LIMIT 1");
                $chk->execute([$k]);
                if ($chk->fetchColumn()) {
                    $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?")->execute([$v, $k]);
                } else {
                    $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)")->execute([$k, $v]);
                }
            }

            Security::log($pdo, 'owner_update_system_switches', "Обновлены глобальные переключатели системы", $userId);
            $success = "Глобальные параметры платформы успешно сохранены.";
        }

        // 4. Мгновенное включение / выключение тех. работ (в 1 клик)
        if ($action === 'toggle_maintenance') {
            $newMode = ($_POST['target_mode'] ?? '') === '1' ? '1' : '0';
            $chk = $pdo->prepare("SELECT id FROM settings WHERE setting_key = 'sys_maintenance_mode' LIMIT 1");
            $chk->execute();
            if ($chk->fetchColumn()) {
                $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'sys_maintenance_mode'")->execute([$newMode]);
            } else {
                $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('sys_maintenance_mode', ?)")->execute([$newMode]);
            }
            Security::log($pdo, 'owner_toggle_maintenance', "Режим тех. обслуживания переключен на: {$newMode}", $userId);
            $success = ($newMode === '1')
                ? "Режим технического обслуживания успешно ВКЛЮЧЕН! Доступ ограничен выбранным ролям и разрешенным разделам."
                : "Режим технического обслуживания ВЫКЛЮЧЕН! Платформа работает в обычном штатном режиме для всех клиентов.";
        }

        // 5. Ручное управление разделами, открытыми во время тех. работ
        if ($action === 'save_maintenance_sections') {
            $allowedSecs = $_POST['allowed_sections'] ?? [];
            if (!is_array($allowedSecs)) {
                $allowedSecs = [];
            }
            $json = json_encode(array_values($allowedSecs), JSON_UNESCAPED_UNICODE);
            $chk = $pdo->prepare("SELECT id FROM settings WHERE setting_key = 'sys_maintenance_allowed_sections' LIMIT 1");
            $chk->execute();
            if ($chk->fetchColumn()) {
                $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'sys_maintenance_allowed_sections'")->execute([$json]);
            } else {
                $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('sys_maintenance_allowed_sections', ?)")->execute([$json]);
            }
            Security::log($pdo, 'owner_update_maintenance_sections', "Обновлен ручной доступ к разделам во время ТО: " . implode(', ', $allowedSecs), $userId);
            $success = "Настройки ручного доступа к разделам во время тех. работ успешно сохранены!";
        }

        // 6. Ручное управление ролями, которым разрешен вход и работа во время тех. работ
        if ($action === 'save_maintenance_roles') {
            $allowedRoles = $_POST['allowed_roles'] ?? [];
            if (!is_array($allowedRoles)) {
                $allowedRoles = [];
            }
            // ВРИО и Админ всегда обязательны
            if (!in_array('vrio_owner', $allowedRoles, true)) $allowedRoles[] = 'vrio_owner';
            if (!in_array('admin', $allowedRoles, true)) $allowedRoles[] = 'admin';

            $json = json_encode(array_values($allowedRoles), JSON_UNESCAPED_UNICODE);
            $chk = $pdo->prepare("SELECT id FROM settings WHERE setting_key = 'sys_maintenance_allowed_roles' LIMIT 1");
            $chk->execute();
            if ($chk->fetchColumn()) {
                $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'sys_maintenance_allowed_roles'")->execute([$json]);
            } else {
                $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('sys_maintenance_allowed_roles', ?)")->execute([$json]);
            }
            Security::log($pdo, 'owner_update_maintenance_roles', "Обновлен ручной доступ по ролям во время ТО: " . implode(', ', $allowedRoles), $userId);
            $success = "Настройки ручного доступа по ролям во время тех. работ успешно сохранены!";
        }

        // 7. Ручная блокировка конкретного раздела на заданное время (по таймеру)
        if ($action === 'toggle_section_lock') {
            $secKey = trim($_POST['section_key'] ?? '');
            $isLock = (int)($_POST['is_locked'] ?? 0);
            $reason = trim($_POST['lock_reason'] ?? 'Плановые технические работы');
            $duration = trim($_POST['duration'] ?? 'indefinite');

            $lockedUntil = null;
            if ($isLock === 1) {
                if ($duration === '15m')     $lockedUntil = (new DateTime('+15 minutes'))->format('Y-m-d H:i:s');
                elseif ($duration === '30m') $lockedUntil = (new DateTime('+30 minutes'))->format('Y-m-d H:i:s');
                elseif ($duration === '1h')  $lockedUntil = (new DateTime('+1 hour'))->format('Y-m-d H:i:s');
                elseif ($duration === '3h')  $lockedUntil = (new DateTime('+3 hours'))->format('Y-m-d H:i:s');
                elseif ($duration === '24h') $lockedUntil = (new DateTime('+24 hours'))->format('Y-m-d H:i:s');
            }

            $pdo->prepare("
                UPDATE section_locks 
                SET is_locked = ?, lock_reason = ?, locked_until = ?, locked_by = ?
                WHERE section_key = ?
            ")->execute([$isLock, $reason, $lockedUntil, $userId, $secKey]);

            Security::log($pdo, 'owner_section_lock', "Раздел {$secKey}: is_locked={$isLock}, reason={$reason}, until={$lockedUntil}", $userId);
            $success = $isLock ? "Раздел «{$secKey}» успешно заблокирован!" : "Блокировка с раздела «{$secKey}» успешно снята!";
        }
    }
}

// Загрузка настроек
$settings = $pdo->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$isMaintenance   = ($settings['sys_maintenance_mode'] ?? '0') === '1';
$isRegEnabled    = ($settings['sys_registration_enabled'] ?? '1') === '1';
$isAiGateway     = ($settings['sys_ai_gateway_enabled'] ?? '1') === '1';
$maintMap = Auth::getMaintenanceSectionMap();
$maintAllowedSections = Auth::getMaintenanceAllowedSections();
$maintRoleConfig = Auth::getMaintenanceRoleConfig();
$maintAllowedRoles = Auth::getMaintenanceAllowedRoles();
$sectionLocks = Auth::getSectionLocks();

// Финансовые показатели
$totalRevenue = (float)$pdo->query("SELECT SUM(t.price) FROM subscriptions s JOIN tariffs t ON s.tariff_id = t.id WHERE s.status = 'active'")->fetchColumn() ?: 0;
$activeSubsCount = (int)$pdo->query("SELECT COUNT(*) FROM subscriptions WHERE status = 'active'")->fetchColumn();
$totalUsersCount = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$totalOrgsCount = (int)$pdo->query("SELECT COUNT(*) FROM organizations WHERE is_active = 1")->fetchColumn();
$totalPosOrders = (int)$pdo->query("SELECT COUNT(*) FROM cash_orders WHERE status = 'paid'")->fetchColumn();
$totalPosTurnover = (float)$pdo->query("SELECT SUM(total) FROM cash_orders WHERE status = 'paid'")->fetchColumn() ?: 0;

// Список ключевой команды сайта
$teamUsers = $pdo->query("
    SELECT u.*, r.name AS role_name, r.description AS role_description 
    FROM users u 
    INNER JOIN roles r ON u.role_id = r.id 
    ORDER BY 
        CASE 
            WHEN r.name = 'vrio_owner' THEN 1
            WHEN r.name = 'admin' THEN 2
            WHEN r.name = 'developer' THEN 3
            WHEN r.name = 'tester' THEN 4
            WHEN r.name = 'support_agent' THEN 5
            WHEN r.name = 'director' THEN 6
            ELSE 7
        END ASC, u.id ASC
")->fetchAll();

$allRoles = $pdo->query("SELECT * FROM roles ORDER BY id ASC")->fetchAll();

$roleBadges = [
    'vrio_owner'    => ['title' => 'ВРИО Владелец',   'bg' => 'linear-gradient(135deg, #d97706, #b45309)', 'color' => '#fff', 'icon' => 'fa-crown'],
    'developer'     => ['title' => 'Разработчик',     'bg' => 'linear-gradient(135deg, #06b6d4, #0891b2)', 'color' => '#fff', 'icon' => 'fa-code'],
    'tester'        => ['title' => 'Тестировщик',     'bg' => 'linear-gradient(135deg, #10b981, #059669)', 'color' => '#fff', 'icon' => 'fa-vial'],
    'support_agent' => ['title' => 'Агент поддержки', 'bg' => 'linear-gradient(135deg, #3b82f6, #2563eb)', 'color' => '#fff', 'icon' => 'fa-headset'],
    'admin'         => ['title' => 'Администратор',   'bg' => 'linear-gradient(135deg, #6366f1, #4f46e5)', 'color' => '#fff', 'icon' => 'fa-shield-alt'],
    'director'      => ['title' => 'Директор бизнеса','bg' => 'linear-gradient(135deg, #8b5cf6, #7c3aed)', 'color' => '#fff', 'icon' => 'fa-user-tie'],
    'manager'       => ['title' => 'Менеджер',        'bg' => 'linear-gradient(135deg, #ec4899, #db2777)', 'color' => '#fff', 'icon' => 'fa-briefcase'],
    'user'          => ['title' => 'Пользователь',    'bg' => 'rgba(255,255,255,0.1)',                    'color' => '#9ca3af', 'icon' => 'fa-user'],
];

$csrfToken = Security::csrfToken();
$pageTitle = "Кабинет ВРИО Владельца | AI Business Assistant";
require dirname(__DIR__) . '/includes/header.php';
?>

<div style="margin-bottom:24px; display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px;">
    <div>
        <div style="display:flex; align-items:center; gap:10px; margin-bottom:6px;">
            <span class="badge" style="background:linear-gradient(135deg,#d97706,#b45309); color:#fff; font-size:12px; font-weight:700; padding:4px 10px;">
                <i class="fas fa-crown"></i> ВРИО Владелец Платформы
            </span>
            <span style="font-size:13px; color:var(--text-muted);">Суверенная консоль управления</span>
        </div>
        <h1 style="margin:0; font-size:26px; font-weight:800;">
            Панель верховного управления платформой
        </h1>
    </div>

    <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <a href="/admin/dashboard.php" class="btn btn-secondary btn-sm">
            <i class="fas fa-shield-alt"></i> Админ-панель
        </a>
        <a href="/developer/dashboard.php" class="btn btn-secondary btn-sm">
            <i class="fas fa-code"></i> Dev-консоль
        </a>
        <a href="/tester/dashboard.php" class="btn btn-secondary btn-sm">
            <i class="fas fa-vial"></i> QA-песочница
        </a>
        <a href="/support/dashboard.php" class="btn btn-secondary btn-sm">
            <i class="fas fa-headset"></i> Поддержка
        </a>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger" style="margin-bottom:20px;">
        <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success" style="margin-bottom:20px;">
        <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?>
    </div>
<?php endif; ?>

<!-- Карточки ключевых показателей платформы -->
<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(210px, 1fr)); gap:16px; margin-bottom:24px;">
    <div class="card" style="padding:18px; border-left:4px solid #10b981;">
        <div style="font-size:12px; text-transform:uppercase; color:var(--text-muted); font-weight:600;">Выручка подписок</div>
        <div style="font-size:24px; font-weight:800; color:#10b981; margin-top:4px;">
            <?= number_format($totalRevenue, 0, '.', ' ') ?> ₸
        </div>
        <div style="font-size:12px; color:var(--text-muted); margin-top:4px;">
            Активных тарифов: <strong><?= $activeSubsCount ?></strong>
        </div>
    </div>

    <div class="card" style="padding:18px; border-left:4px solid #3b82f6;">
        <div style="font-size:12px; text-transform:uppercase; color:var(--text-muted); font-weight:600;">Оборот касс (POS)</div>
        <div style="font-size:24px; font-weight:800; color:#3b82f6; margin-top:4px;">
            <?= number_format($totalPosTurnover, 0, '.', ' ') ?> ₸
        </div>
        <div style="font-size:12px; color:var(--text-muted); margin-top:4px;">
            Пробито чеков: <strong><?= $totalPosOrders ?></strong>
        </div>
    </div>

    <div class="card" style="padding:18px; border-left:4px solid #8b5cf6;">
        <div style="font-size:12px; text-transform:uppercase; color:var(--text-muted); font-weight:600;">Организаций в системе</div>
        <div style="font-size:24px; font-weight:800; color:#8b5cf6; margin-top:4px;">
            <?= $totalOrgsCount ?>
        </div>
        <div style="font-size:12px; color:var(--text-muted); margin-top:4px;">
            Всего пользователей: <strong><?= $totalUsersCount ?></strong>
        </div>
    </div>

    <div class="card" style="padding:18px; border-left:4px solid #f59e0b;">
        <div style="font-size:12px; text-transform:uppercase; color:var(--text-muted); font-weight:600;">Штаб руководства</div>
        <div style="margin-top:8px;">
            <a href="/chat.php?channel=site_hq" class="btn btn-primary btn-sm" style="width:100%;">
                <i class="fas fa-crown"></i> Чат руководства сайта
            </a>
        </div>
    </div>
</div>

<div style="display:grid; grid-template-columns: 1fr 340px; gap:20px; align-items:start; margin-bottom:24px;">
    
    <!-- ЛЕВАЯ КОЛОНКА: УПРАВЛЕНИЕ КОМАНДОЙ И НАЗНАЧЕНИЕ РОЛЕЙ -->
    <div class="card" style="padding:20px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
            <div>
                <h3 style="margin:0; font-size:18px; font-weight:700;">
                    <i class="fas fa-users-cog" style="color:var(--primary); margin-right:8px;"></i> Команда сайта & Назначение ролей
                </h3>
                <p style="margin:4px 0 0; color:var(--text-muted); font-size:13px;">
                    ВРИО Владелец может мгновенно назначать роли Разработчика, Тестировщика, Агента поддержки или Администратора
                </p>
            </div>
        </div>

        <!-- Переменная: какой пользователь сейчас "выбран" для редактирования -->
        <?php
        $selectedEditId = (int)($_GET['edit_user'] ?? 0);
        // Находим выбранного пользователя
        $selectedEditUser = null;
        foreach ($teamUsers as $_tu) {
            if ((int)$_tu['id'] === $selectedEditId && !Auth::isProtectedAdmin($selectedEditId)) {
                $selectedEditUser = $_tu;
                break;
            }
        }
        ?>

        <!-- Список сотрудников: карточки с кнопкой "Выбрать" -->
        <div style="display:flex; flex-direction:column; gap:8px; margin-bottom:16px;">
            <?php foreach ($teamUsers as $u):
                $rName = $u['role_name'];
                $isProtected = Auth::isProtectedAdmin((int)$u['id']);
                $rb = $roleBadges[$rName] ?? ['title' => $rName, 'bg' => 'rgba(255,255,255,0.1)', 'color' => '#fff', 'icon' => 'fa-user'];
                $isSelected = ($selectedEditId === (int)$u['id']);
            ?>
                <div style="display:flex; align-items:center; gap:10px; padding:10px 14px; border-radius:10px; border:1px solid <?= $isSelected ? 'rgba(99,102,241,0.6)' : 'var(--border-color)' ?>; background:<?= $isSelected ? 'rgba(99,102,241,0.08)' : 'rgba(255,255,255,0.02)' ?>; transition:all 0.2s;">
                    <!-- Аватар / иконка роли -->
                    <div style="width:36px; height:36px; border-radius:10px; background:<?= $rb['bg'] ?>; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                        <i class="fas <?= $rb['icon'] ?>" style="color:<?= $rb['color'] ?>; font-size:14px;"></i>
                    </div>
                    <!-- Инфо -->
                    <div style="flex:1; min-width:0;">
                        <div style="font-weight:700; font-size:13px; color:var(--text-primary); display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
                            <?php if ($isProtected): ?><i class="fas fa-crown" style="color:#f59e0b; font-size:11px;"></i><?php endif; ?>
                            <?= htmlspecialchars(trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: 'ID #' . $u['id']) ?>
                            <?php if ($u['id'] === $userId): ?>
                                <span style="font-size:9px; background:rgba(99,102,241,0.2); color:#818cf8; padding:1px 5px; border-radius:4px; font-weight:700;">ВЫ</span>
                            <?php endif; ?>
                        </div>
                        <div style="font-size:11px; color:var(--text-muted); margin-top:1px;"><?= htmlspecialchars($u['email']) ?></div>
                        <div style="margin-top:4px;">
                            <span class="badge" style="background:<?= $rb['bg'] ?>; color:<?= $rb['color'] ?>; font-size:10px; padding:2px 7px; font-weight:600; border-radius:6px;">
                                <?= $rb['title'] ?>
                            </span>
                            <?php if ($isProtected): ?>
                                <span style="font-size:10px; color:#f59e0b; margin-left:4px;"><i class="fas fa-lock"></i> Защищено</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <!-- Кнопка выбрать / отмена -->
                    <?php if (!$isProtected): ?>
                        <?php if ($isSelected): ?>
                            <a href="/owner/dashboard.php" class="btn btn-sm" style="background:rgba(255,255,255,0.08); color:var(--text-muted); font-size:11px; white-space:nowrap; flex-shrink:0;">
                                <i class="fas fa-times"></i> Отмена
                            </a>
                        <?php else: ?>
                            <a href="/owner/dashboard.php?edit_user=<?= $u['id'] ?>" class="btn btn-primary btn-sm" style="font-size:11px; white-space:nowrap; flex-shrink:0;">
                                <i class="fas fa-user-edit"></i> Выбрать
                            </a>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Форма редактирования: появляется только когда выбран пользователь -->
        <?php if ($selectedEditUser): 
            $rb2 = $roleBadges[$selectedEditUser['role_name']] ?? ['title' => $selectedEditUser['role_name'], 'bg' => 'rgba(255,255,255,0.1)', 'color' => '#fff', 'icon' => 'fa-user'];
        ?>
            <div style="padding:16px; border-radius:12px; border:2px solid rgba(99,102,241,0.5); background:rgba(99,102,241,0.06);">
                <div style="font-size:13px; font-weight:700; color:#818cf8; margin-bottom:12px; display:flex; align-items:center; gap:8px;">
                    <i class="fas fa-user-edit"></i>
                    Изменение роли: <?= htmlspecialchars(trim(($selectedEditUser['first_name'] ?? '') . ' ' . ($selectedEditUser['last_name'] ?? '')) ?: 'ID #' . $selectedEditUser['id']) ?>
                </div>
                <div style="font-size:12px; color:var(--text-muted); margin-bottom:12px;">
                    Email: <?= htmlspecialchars($selectedEditUser['email']) ?> &nbsp;|&nbsp;
                    Текущая роль: <strong style="color:#818cf8;"><?= $rb2['title'] ?></strong>
                </div>
                <form method="POST" action="/owner/dashboard.php?edit_user=<?= $selectedEditUser['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="action" value="change_user_role">
                    <input type="hidden" name="target_user_id" value="<?= $selectedEditUser['id'] ?>">
                    <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                        <select name="role_id" class="form-control" style="flex:1; min-width:180px; font-size:13px;">
                            <?php foreach ($allRoles as $role): ?>
                                <?php
                                if (in_array($role['name'], ['admin', 'vrio_owner'], true) && !Auth::isSupremeOwner($user)) {
                                    continue;
                                }
                                ?>
                                <option value="<?= $role['id'] ?>" <?= ((int)$selectedEditUser['role_id'] === (int)$role['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($role['name']) ?> — <?= htmlspecialchars($role['description'] ?? '') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-primary" style="font-weight:700; white-space:nowrap;">
                            <i class="fas fa-save"></i> Сохранить роль
                        </button>
                    </div>
                </form>
            </div>
        <?php else: ?>
            <div style="text-align:center; padding:16px; border-radius:10px; background:rgba(255,255,255,0.02); border:1px dashed var(--border-color); color:var(--text-muted); font-size:13px;">
                <i class="fas fa-arrow-up" style="margin-bottom:6px; display:block; font-size:20px; opacity:0.4;"></i>
                Выберите сотрудника выше, чтобы изменить его роль
            </div>
        <?php endif; ?>
    </div>

    <!-- ПРАВАЯ КОЛОНКА: СИСТЕМНЫЕ ПЕРЕКЛЮЧАТЕЛИ И НАВИГАЦИЯ -->
    <div style="display:flex; flex-direction:column; gap:16px;">
        
        <!-- КНОПКА И ЦЕНТР УПРАВЛЕНИЯ РЕЖИМОМ ТЕХНИЧЕСКОГО ОБСЛУЖИВАНИЯ -->
        <div class="card" style="padding:22px; border:<?= $isMaintenance ? '2px solid rgba(239,68,68,0.7)' : '1px solid var(--border-color)' ?>; box-shadow:<?= $isMaintenance ? '0 0 35px rgba(239,68,68,0.2)' : 'none' ?>; background:var(--bg-card); border-radius:var(--radius-lg);">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <div style="width:34px; height:34px; border-radius:10px; background:<?= $isMaintenance ? 'rgba(239,68,68,0.15)' : 'rgba(245,158,11,0.15)' ?>; display:flex; align-items:center; justify-content:center;">
                        <i class="fas fa-tools" style="color:<?= $isMaintenance ? '#ef4444' : '#f59e0b' ?>; font-size:16px;"></i>
                    </div>
                    <div>
                        <h3 style="margin:0; font-size:16px; font-weight:800; color:var(--text-primary);">Режим тех. работ</h3>
                        <small class="text-muted" style="font-size:11px;">Глобальный переключатель доступа</small>
                    </div>
                </div>
                <?php if ($isMaintenance): ?>
                    <span class="badge" style="background:rgba(239,68,68,0.2); color:#ef4444; border:1px solid rgba(239,68,68,0.4); font-size:11px; font-weight:700; padding:4px 8px; text-transform:uppercase;">
                        <i class="fas fa-circle" style="font-size:7px; animation: blink 1.2s infinite;"></i> Включен
                    </span>
                <?php else: ?>
                    <span class="badge" style="background:rgba(16,185,129,0.15); color:#10b981; border:1px solid rgba(16,185,129,0.3); font-size:11px; font-weight:700; padding:4px 8px; text-transform:uppercase;">
                        <i class="fas fa-check" style="font-size:9px;"></i> Штатный режим
                    </span>
                <?php endif; ?>
            </div>

            <!-- ГЛАВНАЯ КНОПКА ВКЛЮЧЕНИЯ/ВЫКЛЮЧЕНИЯ ТЕХ. РАБОТ -->
            <form method="POST" action="/owner/dashboard.php" style="margin-bottom:16px;" onsubmit="return confirm('<?= $isMaintenance ? 'ВНИМАНИЕ: Вы действительно хотите ВЫКЛЮЧИТЬ режим тех. обслуживания и открыть доступ всем пользователям?' : 'ВНИМАНИЕ: Вы действительно хотите ВКЛЮЧИТЬ режим тех. обслуживания? Доступ обычным клиентам будет заблокирован (кроме разрешенных вручную разделов).' ?>');">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="action" value="toggle_maintenance">
                <input type="hidden" name="target_mode" value="<?= $isMaintenance ? '0' : '1' ?>">

                <?php if ($isMaintenance): ?>
                    <button type="submit" class="btn" style="width:100%; padding:14px; background:linear-gradient(135deg, #10b981, #059669); color:#fff; font-size:15px; font-weight:800; border:none; border-radius:12px; cursor:pointer; box-shadow:0 6px 20px rgba(16,185,129,0.35); display:flex; align-items:center; justify-content:center; gap:8px;">
                        <i class="fas fa-power-off"></i> ВЫКЛЮЧИТЬ ТЕХ. РАБОТЫ (ОТКРЫТЬ ДОСТУП)
                    </button>
                    <div style="font-size:11px; color:#ef4444; text-align:center; margin-top:8px; font-weight:600;">
                        ⚠️ Платформа сейчас заблокирована для обычных клиентов
                    </div>
                <?php else: ?>
                    <button type="submit" class="btn" style="width:100%; padding:14px; background:linear-gradient(135deg, #f59e0b, #d97706); color:#000; font-size:15px; font-weight:800; border:none; border-radius:12px; cursor:pointer; box-shadow:0 6px 20px rgba(245,158,11,0.35); display:flex; align-items:center; justify-content:center; gap:8px;">
                        <i class="fas fa-tools"></i> ВКЛЮЧИТЬ РЕЖИМ ТЕХ. РАБОТ
                    </button>
                    <div style="font-size:11px; color:var(--text-muted); text-align:center; margin-top:8px;">
                        Закрывает сайт для клиентов, оставляя доступ только вам и инженерам
                    </div>
                <?php endif; ?>
            </form>

            <!-- РУЧНОЕ УПРАВЛЕНИЕ ДОСТУПОМ ПО РОЛЯМ -->
            <div style="border-top:1px dashed var(--border-color); padding-top:14px; margin-bottom:16px;">
                <div style="margin-bottom:10px;">
                    <strong style="font-size:13px; color:#38bdf8; display:flex; align-items:center; gap:6px;">
                        <i class="fas fa-user-shield"></i> Ручной доступ РОЛЯМ во время тех. работ
                    </strong>
                    <div style="font-size:11px; color:var(--text-muted); margin-top:2px;">
                        Отметьте роли, которым разрешено работать на сайте во время ТО:
                    </div>
                </div>

                <form method="POST" action="/owner/dashboard.php">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="action" value="save_maintenance_roles">

                    <div style="display:flex; flex-direction:column; gap:6px; margin-bottom:12px;">
                        <?php foreach ($maintRoleConfig as $rKey => $rVal): 
                            $isAllowed = in_array($rKey, $maintAllowedRoles, true);
                        ?>
                            <label style="display:flex; align-items:center; justify-content:space-between; padding:8px 10px; border-radius:8px; background:<?= $isAllowed ? 'rgba(56,189,248,0.08)' : 'rgba(255,255,255,0.02)' ?>; border:1px solid <?= $isAllowed ? 'rgba(56,189,248,0.3)' : 'var(--border-color)' ?>; cursor:<?= $rVal['locked'] ? 'default' : 'pointer' ?>;">
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <i class="fas <?= $rVal['icon'] ?>" style="color:<?= $rVal['color'] ?>; width:16px;"></i>
                                    <div>
                                        <div style="font-size:12px; font-weight:700; color:<?= $isAllowed ? '#f1f5f9' : '#94a3b8' ?>;">
                                            <?= htmlspecialchars($rVal['name']) ?>
                                            <?php if ($rVal['locked']): ?>
                                                <span class="badge" style="background:rgba(255,255,255,0.1); font-size:9px; padding:1px 4px;">Всегда активен</span>
                                            <?php endif; ?>
                                        </div>
                                        <div style="font-size:10px; color:var(--text-muted);"><?= htmlspecialchars($rVal['desc']) ?></div>
                                    </div>
                                </div>
                                <?php if ($rVal['locked']): ?>
                                    <input type="hidden" name="allowed_roles[]" value="<?= $rKey ?>">
                                <?php endif; ?>
                                <input type="checkbox" <?= !$rVal['locked'] ? 'name="allowed_roles[]"' : '' ?> value="<?= $rKey ?>" <?= $isAllowed ? 'checked' : '' ?> <?= $rVal['locked'] ? 'disabled checked' : '' ?> style="width:16px; height:16px;">
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <button type="submit" class="btn btn-secondary btn-sm" style="width:100%; font-weight:700;">
                        <i class="fas fa-save"></i> Сохранить доступ для ролей
                    </button>
                </form>
            </div>

            <!-- РУЧНОЕ УПРАВЛЕНИЕ ДОСТУПОМ К РАЗДЕЛАМ ДЛЯ МАТВЕЯ И ВРИО -->
            <div style="border-top:1px dashed var(--border-color); padding-top:14px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                    <div>
                        <strong style="font-size:13px; color:#fbbf24; display:flex; align-items:center; gap:6px;">
                            <i class="fas fa-sliders-h"></i> Ручной доступ к РАЗДЕЛАМ во время ТО
                        </strong>
                        <div style="font-size:11px; color:var(--text-muted); margin-top:2px;">
                            Отметьте модули, которые будут открыты пользователям:
                        </div>
                    </div>
                </div>

                <form method="POST" action="/owner/dashboard.php">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="action" value="save_maintenance_sections">

                    <div style="display:grid; grid-template-columns:1fr; gap:6px; max-height:220px; overflow-y:auto; padding-right:4px; margin-bottom:12px;">
                        <?php foreach ($maintMap as $sKey => $sVal): 
                            $isOpen = in_array($sKey, $maintAllowedSections, true);
                        ?>
                            <label style="display:flex; align-items:center; justify-content:space-between; padding:8px 10px; border-radius:8px; background:<?= $isOpen ? 'rgba(16,185,129,0.08)' : 'rgba(255,255,255,0.02)' ?>; border:1px solid <?= $isOpen ? 'rgba(16,185,129,0.3)' : 'var(--border-color)' ?>; cursor:pointer;">
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <i class="fas <?= $sVal['icon'] ?>" style="color:<?= $isOpen ? '#10b981' : 'var(--text-muted)' ?>; width:16px;"></i>
                                    <div>
                                        <div style="font-size:12px; font-weight:700; color:<?= $isOpen ? '#10b981' : '#cbd5e1' ?>;">
                                            <?= htmlspecialchars($sVal['name']) ?>
                                        </div>
                                        <div style="font-size:10px; color:var(--text-muted);"><?= htmlspecialchars($sVal['description']) ?></div>
                                    </div>
                                </div>
                                <input type="checkbox" name="allowed_sections[]" value="<?= $sKey ?>" <?= $isOpen ? 'checked' : '' ?> style="width:16px; height:16px;">
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <button type="submit" class="btn btn-secondary btn-sm" style="width:100%; font-weight:700;">
                        <i class="fas fa-save"></i> Сохранить ручной доступ к разделам
                    </button>
                </form>
            </div>
        </div>

        <!-- Глобальные тумблеры платформы -->
        <div class="card" style="padding:20px;">
            <h3 style="margin:0 0 14px; font-size:15px; font-weight:700;">
                <i class="fas fa-toggle-on" style="color:var(--primary); margin-right:8px;"></i> Дополнительные переключатели
            </h3>

            <form method="POST" action="/owner/dashboard.php">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="action" value="save_global_switches">
                <input type="hidden" name="maintenance_mode" value="<?= $isMaintenance ? '1' : '0' ?>">

                <div style="display:flex; flex-direction:column; gap:12px; margin-bottom:16px; font-size:13px;">
                    <label style="display:flex; align-items:center; justify-content:space-between; cursor:pointer;">
                        <span>
                            <strong>Регистрация новых клиентов:</strong>
                            <div style="font-size:11px; color:var(--text-muted);">Разрешить регистрацию на сайте</div>
                        </span>
                        <input type="checkbox" name="registration_enabled" value="1" <?= $isRegEnabled ? 'checked' : '' ?> style="width:18px; height:18px;">
                    </label>

                    <label style="display:flex; align-items:center; justify-content:space-between; cursor:pointer;">
                        <span>
                            <strong>AI Шлюз (Нейросети):</strong>
                            <div style="font-size:11px; color:var(--text-muted);">Работа ИИ-ассистента и генераций</div>
                        </span>
                        <input type="checkbox" name="ai_gateway_enabled" value="1" <?= $isAiGateway ? 'checked' : '' ?> style="width:18px; height:18px;">
                    </label>
                </div>

                <button type="submit" class="btn btn-secondary btn-sm" style="width:100%;">
                    <i class="fas fa-save"></i> Применить переключатели
                </button>
            </form>
        </div>

        <!-- Управление временной блокировкой разделов платформы -->
        <div class="card" style="padding:20px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                <h3 style="margin:0; font-size:15px; font-weight:700;">
                    <i class="fas fa-lock" style="color:#ef4444; margin-right:8px;"></i> Блокировка разделов на время
                </h3>
                <span class="badge" style="background:rgba(239,68,68,0.15); color:#ef4444; border:1px solid rgba(239,68,68,0.3); font-size:10px; padding:3px 8px; font-weight:700;">
                    ТАЙМЕР & РУЧНОЙ ДОСТУП
                </span>
            </div>
            <p style="font-size:12px; color:var(--text-muted); margin:0 0 16px;">
                Возможность временно закрыть любой модуль (CRM, касса, AI, чаты и др.) на выбранное время с таймером обратного отсчета для пользователей.
            </p>

            <div style="display:flex; flex-direction:column; gap:10px; max-height:420px; overflow-y:auto; padding-right:4px;">
                <?php foreach ($sectionLocks as $sl): 
                    $isLocked = (int)$sl['is_locked'] === 1;
                    $lockUntil = $sl['locked_until'];
                ?>
                    <div style="padding:12px; border-radius:10px; background:<?= $isLocked ? 'rgba(239,68,68,0.08)' : 'rgba(255,255,255,0.02)' ?>; border:1px solid <?= $isLocked ? 'rgba(239,68,68,0.35)' : 'var(--border-color)' ?>;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                            <div style="font-size:13px; font-weight:700; color:<?= $isLocked ? '#ef4444' : 'var(--text-primary)' ?>; display:flex; align-items:center; gap:6px;">
                                <i class="fas <?= $isLocked ? 'fa-lock' : 'fa-unlock' ?>"></i>
                                <?= htmlspecialchars($sl['section_name']) ?>
                            </div>
                            <span class="badge" style="background:<?= $isLocked ? '#ef4444' : '#10b981' ?>; color:#fff; font-size:10px; font-weight:700;">
                                <?= $isLocked ? 'ЗАБЛОКИРОВАН' : 'ОТКРЫТ' ?>
                            </span>
                        </div>

                        <?php if ($isLocked): ?>
                            <div style="font-size:11px; color:#cbd5e1; margin-bottom:8px;">
                                <strong>Причина:</strong> <?= htmlspecialchars($sl['lock_reason'] ?: 'Технические работы') ?><br>
                                <?php if ($lockUntil): ?>
                                    <span style="color:#38bdf8;"><strong>До:</strong> <?= (new DateTime($lockUntil))->format('d.m.Y H:i') ?></span>
                                <?php else: ?>
                                    <span style="color:#f59e0b;">Бессрочно (до ручного снятия)</span>
                                <?php endif; ?>
                            </div>
                            <form method="POST" action="/owner/dashboard.php">
                                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                <input type="hidden" name="action" value="toggle_section_lock">
                                <input type="hidden" name="section_key" value="<?= htmlspecialchars($sl['section_key']) ?>">
                                <input type="hidden" name="is_locked" value="0">
                                <button type="submit" class="btn btn-sm" style="background:#10b981; color:#fff; border:none; border-radius:6px; font-size:11px; font-weight:700; padding:5px 12px; cursor:pointer;">
                                    <i class="fas fa-lock-open"></i> Снять блокировку
                                </button>
                            </form>
                        <?php else: ?>
                            <form method="POST" action="/owner/dashboard.php" style="display:flex; flex-direction:column; gap:6px;">
                                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                <input type="hidden" name="action" value="toggle_section_lock">
                                <input type="hidden" name="section_key" value="<?= htmlspecialchars($sl['section_key']) ?>">
                                <input type="hidden" name="is_locked" value="1">
                                <div style="display:flex; gap:6px;">
                                    <select name="duration" style="background:var(--bg-input); border:1px solid var(--border-color); border-radius:6px; color:#fff; font-size:11px; padding:4px 6px;">
                                        <option value="15m">15 мин</option>
                                        <option value="30m">30 мин</option>
                                        <option value="1h" selected>1 час</option>
                                        <option value="3h">3 часа</option>
                                        <option value="24h">24 часа</option>
                                        <option value="indefinite">Бессрочно</option>
                                    </select>
                                    <input type="text" name="lock_reason" placeholder="Причина (напр. Обновление)" value="Технический перерыв" style="flex:1; background:var(--bg-input); border:1px solid var(--border-color); border-radius:6px; color:#fff; font-size:11px; padding:4px 8px;">
                                    <button type="submit" class="btn btn-sm" style="background:#ef4444; color:#fff; border:none; border-radius:6px; font-size:11px; font-weight:700; padding:4px 10px; cursor:pointer;">
                                        <i class="fas fa-ban"></i> Блок
                                    </button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Быстрые переходы во все консоли сотрудников -->
        <div class="card" style="padding:20px;">
            <h3 style="margin:0 0 14px; font-size:15px; font-weight:700;">
                <i class="fas fa-compass" style="color:var(--primary); margin-right:8px;"></i> Консоли специалистов
            </h3>

            <div style="display:flex; flex-direction:column; gap:8px;">
                <a href="/developer/dashboard.php" class="btn btn-secondary" style="justify-content:flex-start; text-align:left;">
                    <i class="fas fa-code" style="color:#06b6d4; width:20px;"></i> Консоль Разработчика
                </a>
                <a href="/tester/dashboard.php" class="btn btn-secondary" style="justify-content:flex-start; text-align:left;">
                    <i class="fas fa-vial" style="color:#10b981; width:20px;"></i> Консоль Тестировщика (QA)
                </a>
                <a href="/support/dashboard.php" class="btn btn-secondary" style="justify-content:flex-start; text-align:left;">
                    <i class="fas fa-headset" style="color:#3b82f6; width:20px;"></i> Консоль Агента поддержки
                </a>
                <a href="/admin/dashboard.php" class="btn btn-secondary" style="justify-content:flex-start; text-align:left;">
                    <i class="fas fa-shield-alt" style="color:#6366f1; width:20px;"></i> Консоль Администратора
                </a>
                <a href="/chat.php?channel=site_hq" class="btn btn-primary" style="justify-content:flex-start; text-align:left; margin-top:4px;">
                    <i class="fas fa-crown" style="width:20px;"></i> Чат руководства сайта
                </a>
            </div>
        </div>

    </div>
</div>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
