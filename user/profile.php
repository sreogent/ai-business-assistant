<?php
declare(strict_types=1);

/**
 * AI Business Assistant - User Profile Settings
 */

require_once dirname(__DIR__) . '/includes/Auth.php';

$user = Auth::requireAuth('/login.php');
$userId = (int)$user['id'];
$pdo = Database::getConnection();

$error = null;
$success = null;
$twoFaNotice = null;
$show2FaCodeInput = false;

// Проверяем актуальное состояние 2FA в БД
try {
    $col2fa = $pdo->query("SHOW COLUMNS FROM users LIKE 'two_fa_enabled'")->fetch();
    if (!$col2fa) {
        $pdo->exec("ALTER TABLE users ADD COLUMN two_fa_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
    }
    $st2fa = $pdo->prepare("SELECT two_fa_enabled FROM users WHERE id = ?");
    $st2fa->execute([$userId]);
    $user['two_fa_enabled'] = (int)$st2fa->fetchColumn();
} catch (\Throwable $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!Security::verifyCsrf($token)) {
        $error = "Истек токен безопасности. Повторите попытку.";
    } else {
        $formAction = $_POST['form_action'] ?? 'save_profile';

        // 1. Запрос кода на email для включения 2FA
        if ($formAction === 'request_2fa_code') {
            require_once dirname(__DIR__) . '/includes/Mailer.php';
            $code = str_pad((string)random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
            $_SESSION['2fa_enable_code'] = $code;
            $_SESSION['2fa_enable_expires'] = time() + 600;

            Mailer::send2FAEnableCode($user['email'], $user['first_name'], $code);
            Security::log($pdo, '2fa_enable_code_sent', "Код для включения 2FA отправлен на {$user['email']}", $userId);
            $twoFaNotice = "Проверочный 4-значный код отправлен на вашу почту {$user['email']}. Введите его ниже для подтверждения:";
            $show2FaCodeInput = true;
        }

        // 2. Подтверждение кода и включение 2FA
        elseif ($formAction === 'confirm_2fa_code') {
            $inputCode = trim($_POST['code_2fa'] ?? '');
            $savedCode = $_SESSION['2fa_enable_code'] ?? null;
            $expires = $_SESSION['2fa_enable_expires'] ?? 0;

            if (!$savedCode || time() > $expires) {
                $error = "Срок действия кода подтверждения истёк. Запросите код повторно.";
            } elseif ($inputCode !== $savedCode) {
                $error = "Неверный код подтверждения. Проверьте цифры из письма.";
                $show2FaCodeInput = true;
            } else {
                $pdo->prepare("UPDATE users SET two_fa_enabled = 1 WHERE id = ?")->execute([$userId]);
                Auth::set2FATrustCookie($pdo, $userId);
                unset($_SESSION['2fa_enable_code'], $_SESSION['2fa_enable_expires']);
                Security::log($pdo, '2fa_enabled', "2FA успешно включена пользователем", $userId);
                $success = "🎉 Двухфакторная аутентификация (2FA) успешно включена! Кулдаун доверенного устройства активирован на 2 часа.";
                $user['two_fa_enabled'] = 1;
            }
        }

        // 3. Отключение 2FA (с проверкой пароля)
        elseif ($formAction === 'disable_2fa') {
            $passConfirm = $_POST['disable_password'] ?? '';
            if (empty($passConfirm) || !password_verify($passConfirm, $user['password_hash'])) {
                $error = "Неверный пароль. Для отключения 2FA требуется подтверждение паролем.";
            } else {
                $pdo->prepare("UPDATE users SET two_fa_enabled = 0, two_fa_trusted_hash = NULL, two_fa_trusted_expires = NULL WHERE id = ?")->execute([$userId]);
                setcookie('2fa_trust', '', time() - 3600, '/');
                Security::log($pdo, '2fa_disabled', "2FA отключена пользователем", $userId);
                $success = "Двухфакторная аутентификация (2FA) отключена.";
                $user['two_fa_enabled'] = 0;
            }
        }

        // 4. Сохранение данных профиля и пароля
        elseif ($formAction === 'save_profile') {
            $firstName = trim($_POST['first_name'] ?? '');
            $lastName = trim($_POST['last_name'] ?? '');
            $phone = preg_replace('/[^\d+]/', '', trim($_POST['phone'] ?? ''));
            $oldPass = $_POST['old_password'] ?? '';
            $newPass = $_POST['new_password'] ?? '';

            if (empty($firstName) || empty($lastName) || empty($phone)) {
                $error = "Имя, фамилия и телефон обязательны для заполнения.";
            } else {
                // Check if phone changed and is taken
                $phoneCheck = $pdo->prepare("SELECT id FROM users WHERE phone = ? AND id != ? LIMIT 1");
                $phoneCheck->execute([$phone, $userId]);
                if ($phoneCheck->fetch()) {
                    $error = "Этот номер телефона уже занят другим пользователем.";
                } else {
                    // If password change requested
                    if (!empty($newPass)) {
                        if (empty($oldPass) || !password_verify($oldPass, $user['password_hash'])) {
                            $error = "Неверный текущий пароль.";
                        } elseif (mb_strlen($newPass) < 8) {
                            $error = "Новый пароль должен содержать минимум 8 символов.";
                        } else {
                            $newHash = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);
                            $update = $pdo->prepare("UPDATE users SET first_name = ?, last_name = ?, phone = ?, password_hash = ? WHERE id = ?");
                            $update->execute([$firstName, $lastName, $phone, $newHash, $userId]);
                            $success = "Профиль и пароль успешно обновлены!";
                            $user = Auth::user();
                        }
                    } else {
                        $update = $pdo->prepare("UPDATE users SET first_name = ?, last_name = ?, phone = ? WHERE id = ?");
                        $update->execute([$firstName, $lastName, $phone, $userId]);
                        $success = "Данные профиля успешно сохранены!";
                        $user = Auth::user();
                    }
                }
            }
        }
    }
}

$pageTitle = 'Настройки профиля | AI Business Assistant';
require dirname(__DIR__) . '/includes/header.php';
?>

<div style="max-width: 600px; margin: 0 auto;">
    <h2 class="mb-2">Настройки профиля</h2>
    <p class="text-muted mb-4">Управление персональными данными и безопасностью учетной записи</p>

    <?php if ($success): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:28px;">
        <form method="POST">
            <?= Security::csrfField() ?>
            <input type="hidden" name="form_action" value="save_profile">

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                <div class="form-group">
                    <label class="form-label">Имя</label>
                    <input type="text" name="first_name" class="form-control" required value="<?= htmlspecialchars($user['first_name']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Фамилия</label>
                    <input type="text" name="last_name" class="form-control" required value="<?= htmlspecialchars($user['last_name']) ?>">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Email (логин)</label>
                <input type="email" class="form-control" disabled value="<?= htmlspecialchars($user['email']) ?>" style="opacity:0.7;">
                <small class="text-muted">Email привязан к учетной записи и не подлежит изменению.</small>
            </div>

            <div class="form-group">
                <label class="form-label">Телефон</label>
                <input type="tel" name="phone" class="form-control" required value="<?= htmlspecialchars($user['phone']) ?>">
            </div>

            <hr style="border:0; border-top:1px solid var(--border-color); margin:24px 0;">

            <h4 style="font-size:15px; margin-bottom:12px;"><i class="fas fa-key text-primary"></i> Изменение пароля (оставьте пустым, если не меняете)</h4>

            <div class="form-group">
                <label class="form-label">Текущий пароль</label>
                <input type="password" name="old_password" class="form-control" placeholder="••••••••">
            </div>

            <div class="form-group">
                <label class="form-label">Новый пароль (минимум 8 символов)</label>
                <input type="password" name="new_password" class="form-control" placeholder="••••••••">
            </div>

            <button type="submit" class="btn btn-primary btn-block mt-3">
                <i class="fas fa-save"></i> Сохранить изменения
            </button>
        </form>
    </div>

    <!-- Блок 2FA безопасности -->
    <div class="card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:28px; margin-top:24px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
            <div style="display:flex; align-items:center; gap:12px;">
                <div style="width:40px; height:40px; border-radius:10px; background:rgba(99,102,241,0.15); display:flex; align-items:center; justify-content:center; color:#818cf8; font-size:20px;">
                    <i class="fas fa-shield-alt"></i>
                </div>
                <div>
                    <h3 style="margin:0; font-size:16px; font-weight:700;">Двухфакторная аутентификация (2FA)</h3>
                    <p style="margin:2px 0 0; font-size:12px; color:var(--text-muted);">Защита аккаунта одноразовым кодом на email</p>
                </div>
            </div>
            <div>
                <?php if (!empty($user['two_fa_enabled'])): ?>
                    <span class="badge" style="background:rgba(34,197,94,0.15); color:#22c55e; border:1px solid rgba(34,197,94,0.3); padding:6px 12px; font-size:12px; font-weight:700;">
                        <i class="fas fa-check-circle"></i> Включена
                    </span>
                <?php else: ?>
                    <span class="badge" style="background:rgba(148,163,184,0.15); color:#94a3b8; border:1px solid rgba(148,163,184,0.3); padding:6px 12px; font-size:12px; font-weight:700;">
                        <i class="fas fa-times-circle"></i> Отключена
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <p style="font-size:13px; color:var(--text-muted); line-height:1.6; margin-bottom:18px;">
            При активной 2FA каждый вход в систему требует подтверждения 4-значным проверочным кодом, отправляемым на ваш email <strong><?= htmlspecialchars($user['email']) ?></strong>.
            <br>
            <span style="color:#818cf8;"><i class="fas fa-clock"></i> <strong>Кулдаун 2 часа:</strong> после подтверждения устройства код не запрашивается повторно при повторных входах в течение 2 часов.</span>
        </p>

        <?php if ($twoFaNotice): ?>
            <div class="alert alert-info" style="margin-bottom:18px; font-size:13px;">
                <i class="fas fa-envelope-open-text"></i> <?= htmlspecialchars($twoFaNotice) ?>
            </div>
        <?php endif; ?>

        <?php if ($show2FaCodeInput): ?>
            <!-- Форма ввода кода подтверждения -->
            <form method="POST" style="background:rgba(99,102,241,0.06); border:1px solid rgba(99,102,241,0.25); border-radius:12px; padding:20px; margin-bottom:16px;">
                <?= Security::csrfField() ?>
                <input type="hidden" name="form_action" value="confirm_2fa_code">

                <div class="form-group" style="text-align:center;">
                    <label class="form-label" style="font-weight:700; font-size:14px; margin-bottom:8px;">Введите 4-значный код из письма:</label>
                    <input type="text" name="code_2fa" maxlength="4" pattern="[0-9]{4}" inputmode="numeric" required autofocus
                           style="font-size:28px; font-weight:900; letter-spacing:10px; text-align:center; height:54px; width:180px; margin:0 auto; font-family:monospace; color:#818cf8; border:2px solid var(--primary); border-radius:10px; background:var(--bg-input);"
                           oninput="this.value = this.value.replace(/\D/g, '')">
                    <small class="text-muted" style="display:block; margin-top:8px;">Код отправлен на <?= htmlspecialchars($user['email']) ?> (действует 10 минут)</small>
                </div>

                <div style="display:flex; gap:10px; justify-content:center; margin-top:14px;">
                    <button type="submit" class="btn btn-primary" style="padding:10px 24px; font-weight:700;">
                        <i class="fas fa-check"></i> Подтвердить и включить 2FA
                    </button>
                </div>
            </form>
        <?php elseif (empty($user['two_fa_enabled'])): ?>
            <!-- Кнопка запроса кода для включения -->
            <form method="POST">
                <?= Security::csrfField() ?>
                <input type="hidden" name="form_action" value="request_2fa_code">
                <button type="submit" class="btn btn-outline-primary" style="font-weight:700; display:inline-flex; align-items:center; gap:8px;">
                    <i class="fas fa-shield-alt"></i> Включить 2FA защиту
                </button>
            </form>
        <?php else: ?>
            <!-- Форма отключения 2FA -->
            <div style="border-top:1px solid var(--border-color); padding-top:16px; margin-top:16px;">
                <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('disable2faWrap').style.display = 'block'; this.style.display = 'none';" style="color:#f87171;">
                    <i class="fas fa-lock-open"></i> Отключить 2FA
                </button>
                <div id="disable2faWrap" style="display:none; margin-top:12px; background:rgba(239,68,68,0.06); border:1px solid rgba(239,68,68,0.25); border-radius:12px; padding:16px;">
                    <form method="POST">
                        <?= Security::csrfField() ?>
                        <input type="hidden" name="form_action" value="disable_2fa">
                        <div class="form-group" style="margin-bottom:12px;">
                            <label class="form-label" style="font-size:12px; color:#f87171; font-weight:600;">Подтвердите текущий пароль для отключения 2FA:</label>
                            <input type="password" name="disable_password" class="form-control" required placeholder="Введите ваш текущий пароль">
                        </div>
                        <div style="display:flex; gap:8px;">
                            <button type="submit" class="btn btn-danger btn-sm" style="background:#ef4444; border:none; font-weight:700;">
                                Отключить
                            </button>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('disable2faWrap').style.display = 'none';">
                                Отмена
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
