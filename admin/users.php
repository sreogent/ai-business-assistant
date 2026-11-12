<?php
declare(strict_types=1);

/**
 * AI Business Assistant - Admin User Management
 * View, Block/Unblock, Change Role, Delete users
 */

require_once dirname(__DIR__) . '/includes/Auth.php';

$admin = Auth::requireAdmin();
$currentAdminId = (int)$admin['id'];
$pdo = Database::getConnection();

$error = null;
$success = null;

// Handle User Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!Security::verifyCsrf($token)) {
        $error = "Истек токен безопасности. Повторите попытку.";
    } else {
        $action = $_POST['action'] ?? '';
        $targetUserId = (int)($_POST['target_user_id'] ?? 0);

        if ($targetUserId <= 0) {
            $error = "Некорректный идентификатор пользователя.";
        } elseif ($action === 'toggle_status') {
            if (Auth::isProtectedAdmin($targetUserId)) {
                $error = "⛔ КРИТИЧЕСКАЯ ЗАЩИТА: Аккаунт Верховного Администратора (Матвей Ибадлаев) защищен от блокировки.";
            } elseif ($targetUserId === $currentAdminId) {
                $error = "Нельзя заблокировать собственный аккаунт.";
            } else {
                $targetStatus = $_POST['status'] === 'active' ? 'blocked' : 'active';
                $stmt = $pdo->prepare("UPDATE users SET status = ? WHERE id = ?");
                $stmt->execute([$targetStatus, $targetUserId]);
                Security::log($pdo, 'admin_toggle_user_status', "Статус пользователя #{$targetUserId} изменен на: {$targetStatus}", $currentAdminId);
                $success = "Статус пользователя успешно обновлен.";
            }
        } elseif ($action === 'change_role') {
            if (Auth::isProtectedAdmin($targetUserId)) {
                $error = "⛔ КРИТИЧЕСКАЯ ЗАЩИТА: Права Верховного Администратора (Матвей Ибадлаев) пожизненные и не могут быть изменены!";
                Security::log($pdo, 'tamper_supreme_admin_blocked', "Попытка изменения роли Верховного Администратора пользователем #{$currentAdminId}", $currentAdminId);
            } else {
                $newRoleId = (int)($_POST['role_id'] ?? 0);
                $rStmt = $pdo->prepare("SELECT name FROM roles WHERE id = ?");
                $rStmt->execute([$newRoleId]);
                $rName = $rStmt->fetchColumn();

                $currStmt = $pdo->prepare("SELECT r.name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
                $currStmt->execute([$targetUserId]);
                $currRoleName = $currStmt->fetchColumn();

                $involvesAdmin = in_array($rName, ['admin', 'vrio_owner'], true) || in_array($currRoleName, ['admin', 'vrio_owner'], true);
                $isHighRole = in_array($rName, ['manager', 'director', 'admin', 'vrio_owner'], true);

                if ($involvesAdmin && !Auth::isSupremeOwner($admin)) {
                    $error = "⛔ ОТКАЗАНО В ДОСТУПЕ: Назначать или снимать права Администратора имеет право исключительно Матвей Ибадлаев!";
                    Security::log($pdo, 'unauthorized_admin_escalation', "Попытка изменения прав администратора пользователем #{$currentAdminId}", $currentAdminId);
                } elseif ($isHighRole && Auth::isDirectorPosPasswordSet() && !Auth::isSupremeOwner($admin)) {
                    $directorPwd = (string)($_POST['director_password'] ?? '');
                    if (!Auth::verifyDirectorPosPassword($directorPwd)) {
                        $error = "⛔ Для назначения роли «{$rName}» требуется ввести правильный пароль директора!";
                        Security::log($pdo, 'failed_director_password_role_change', "Неверный пароль директора при попытке назначить роль {$rName} пользователю #{$targetUserId}", $currentAdminId);
                    } else {
                        $stmt = $pdo->prepare("UPDATE users SET role_id = ? WHERE id = ?");
                        $stmt->execute([$newRoleId, $targetUserId]);

                        if ($targetUserId === $currentAdminId) {
                            $_SESSION['user_role'] = $rName;
                        }

                        Security::log($pdo, 'admin_change_user_role', "Роль пользователя #{$targetUserId} изменена на: role_id={$newRoleId} ({$rName}) [подтверждено паролем директора]", $currentAdminId);
                        $success = "Роль пользователя успешно обновлена с подтверждением пароля директора.";
                    }
                } elseif ($targetUserId === $currentAdminId && !in_array($rName, ['admin', 'vrio_owner'], true)) {
                    $error = "Защита от блокировки: вы не можете понизить свою роль до обычного пользователя, чтобы не потерять доступ к панели управления.";
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET role_id = ? WHERE id = ?");
                    $stmt->execute([$newRoleId, $targetUserId]);

                    if ($targetUserId === $currentAdminId) {
                        $_SESSION['user_role'] = $rName;
                    }

                    Security::log($pdo, 'admin_change_user_role', "Роль пользователя #{$targetUserId} изменена на: role_id={$newRoleId} ({$rName})", $currentAdminId);
                    $success = "Роль пользователя успешно обновлена.";
                }
            }
        } elseif ($action === 'delete') {
            if (Auth::isProtectedAdmin($targetUserId)) {
                $error = "⛔ КРИТИЧЕСКАЯ ЗАЩИТА: Аккаунт Верховного Администратора (Матвей Ибадлаев) защищен от удаления!";
                Security::log($pdo, 'tamper_supreme_admin_delete_blocked', "Попытка удаления Верховного Администратора пользователем #{$currentAdminId}", $currentAdminId);
            } elseif ($targetUserId === $currentAdminId) {
                $error = "Нельзя удалить собственный аккаунт.";
            } else {
                $del = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $del->execute([$targetUserId]);
                Security::log($pdo, 'admin_delete_user', "Пользователь #{$targetUserId} удален администратором", $currentAdminId);
                $success = "Пользователь и связанные данные успешно удалены.";
            }
        }
    }
}

// Fetch users
$users = $pdo->query("
    SELECT u.*, r.name as role_name 
    FROM users u 
    JOIN roles r ON u.role_id = r.id 
    ORDER BY u.id DESC
")->fetchAll();

$roles = $pdo->query("SELECT * FROM roles ORDER BY id ASC")->fetchAll();

$pageTitle = 'Пользователи | AI Business Assistant';
require dirname(__DIR__) . '/includes/header.php';
?>

<div style="margin-bottom:24px;">
    <h2>Управление пользователями</h2>
    <p class="text-muted">Просмотр учетных записей, блокировка доступа, изменение ролей и удаление</p>
</div>

<?php if ($success): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="table-responsive">
    <table class="table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Имя и Фамилия</th>
                <th>Email</th>
                <th>Телефон</th>
                <th>Роль</th>
                <th>Статус</th>
                <th>Регистрация</th>
                <th>Действия</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $u): 
                $isProtected = Auth::isProtectedAdmin((int)$u['id']);
            ?>
                <tr style="<?= $isProtected ? 'background:rgba(245, 158, 11, 0.05);' : '' ?>">
                    <td>#<?= $u['id'] ?></td>
                    <td>
                        <strong>
                            <?php if ($isProtected): ?><i class="fas fa-crown" style="color:#f59e0b; margin-right:4px;"></i><?php endif; ?>
                            <?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?>
                        </strong>
                        <?php if ($u['id'] === $currentAdminId): ?>
                            <span class="badge badge-info" style="font-size:10px; margin-left:4px;">Вы</span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($u['email']) ?></td>
                    <td><?= htmlspecialchars($u['phone']) ?></td>
                    <td>
                        <?php if ($isProtected): ?>
                            <span class="badge" style="background:linear-gradient(135deg, #f59e0b, #d97706); color:#fff; font-size:11px; padding:4px 8px; font-weight:700;">
                                <i class="fas fa-crown"></i> Главный Администратор (Неизменяемо)
                            </span>
                        <?php elseif ($u['id'] !== $currentAdminId): ?>
                            <form method="POST" style="margin:0;" onsubmit="return handleRoleChangeSubmit(this);">
                                <?= Security::csrfField() ?>
                                <input type="hidden" name="action" value="change_role">
                                <input type="hidden" name="target_user_id" value="<?= $u['id'] ?>">
                                <input type="hidden" name="director_password" value="" class="dir-pwd-field">
                                <select name="role_id" onchange="handleRoleSelect(this)" class="form-control" style="padding:4px 8px; font-size:12px; width:auto; display:inline-block;">
                                    <?php foreach ($roles as $r): ?>
                                        <?php
                                        // Назначать роль админа может ТОЛЬКО Матвей Ибадлаев
                                        if (in_array($r['name'], ['admin', 'vrio_owner'], true) && !Auth::isSupremeOwner($admin)) {
                                            continue;
                                        }
                                        ?>
                                        <option value="<?= $r['id'] ?>" data-name="<?= htmlspecialchars($r['name']) ?>" <?= (int)$u['role_id'] === (int)$r['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($r['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        <?php else: ?>
                            <span class="badge badge-primary"><?= htmlspecialchars($u['role_name']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($isProtected): ?>
                            <span class="badge badge-success"><i class="fas fa-shield-alt"></i> Защищен</span>
                        <?php else: ?>
                            <span class="badge <?= $u['status'] === 'active' ? 'badge-success' : 'badge-danger' ?>">
                                <?= $u['status'] === 'active' ? 'Активен' : 'Заблокирован' ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td><small class="text-muted"><?= date('d.m.Y', strtotime($u['created_at'])) ?></small></td>
                    <td>
                        <?php if ($isProtected): ?>
                            <span class="text-muted" style="font-size:11px; font-weight:600;"><i class="fas fa-lock text-warning"></i> Защищен</span>
                        <?php elseif ($u['id'] !== $currentAdminId): ?>
                            <div style="display:flex; gap:6px;">
                                <!-- Toggle block/active -->
                                <form method="POST" style="margin:0;">
                                    <?= Security::csrfField() ?>
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="target_user_id" value="<?= $u['id'] ?>">
                                    <input type="hidden" name="status" value="<?= $u['status'] ?>">
                                    <button type="submit" class="btn btn-secondary btn-sm" title="<?= $u['status'] === 'active' ? 'Заблокировать' : 'Разблокировать' ?>">
                                        <i class="fas <?= $u['status'] === 'active' ? 'fa-ban text-danger' : 'fa-unlock text-success' ?>"></i>
                                    </button>
                                </form>

                                <!-- Delete -->
                                <form method="POST" onsubmit="return confirm('Вы действительно хотите удалить пользователя и все его данные?')" style="margin:0;">
                                    <?= Security::csrfField() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="target_user_id" value="<?= $u['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm" title="Удалить">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        <?php else: ?>
                            <small class="text-muted">Текущий админ</small>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script>
function handleRoleSelect(selectEl) {
    const form = selectEl.form;
    const selectedOption = selectEl.options[selectEl.selectedIndex];
    const roleName = selectedOption.getAttribute('data-name') || '';
    const highRoles = ['manager', 'director', 'admin', 'vrio_owner'];
    const isPasswordSet = <?= Auth::isDirectorPosPasswordSet() ? 'true' : 'false' ?>;
    const isSupreme = <?= Auth::isSupremeOwner($admin) ? 'true' : 'false' ?>;

    if (highRoles.includes(roleName) && isPasswordSet && !isSupreme) {
        const pwd = prompt('Для назначения роли «' + roleName + '» введите пароль директора:');
        if (!pwd) {
            alert('Смена роли отменена: требуется пароль директора.');
            window.location.reload();
            return false;
        }
        form.querySelector('.dir-pwd-field').value = pwd;
    }
    form.submit();
}

function handleRoleChangeSubmit(form) {
    return true;
}
</script>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
