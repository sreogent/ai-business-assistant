<?php
declare(strict_types=1);
ob_start();

/**
 * AI Business Assistant - User & Admin Login
 */

require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/Security.php';
require_once __DIR__ . '/includes/Auth.php';

// Auto-check DB only if not yet initialized
if (!file_exists(__DIR__ . '/setup.lock')) {
    Database::checkAndMigrate();
}

if (Auth::check()) {
    $u = Auth::user();
    if (Auth::isMaintenanceMode()) {
        $r = $u['role_name'] ?? 'user';
        $isAllowed = Auth::isSupremeOwner($u) || in_array($r, ['vrio_owner', 'admin', 'developer', 'tester', 'support_agent'], true);
        if (!$isAllowed) {
            header("Location: /maintenance.php");
            exit;
        }
    }
    $targetUrl = Auth::getRedirectForRole($u['role_name'] ?? 'user');
    header("Location: " . $targetUrl);
    echo "<script>window.location.href=" . json_encode($targetUrl) . ";</script>";
    exit;
}

$error = null;
$notice = null;

if (isset($_GET['setup_done'])) {
    $notice = "Установка завершена! Главный администратор создан. Войдите, используя указанные данные.";
}

// Maintenance mode: no notice shown on login page — engineers know the login URL

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!Security::verifyCsrf($token)) {
        $error = "Истекло время действия формы безопасности (CSRF). Пожалуйста, обновите страницу.";
    } else {
        $email = $_POST['email'] ?? '';
        $password = $_POST['password'] ?? '';

        $result = Auth::attempt($email, $password);
        if ($result['success']) {
            $user = $result['user'];
            $role = $user['role_name'] ?? 'user';

            // Вход разрешен всем авторизованным пользователям, а при входе на платформу отображается анимированная табличка тех. работ

            if (!empty($result['requires_2fa'])) {
                if (!empty($_POST['redirect'])) {
                    $_SESSION['login_redirect'] = trim($_POST['redirect']);
                }
                header("Location: /verify_2fa.php");
                echo "<script>window.location.href='/verify_2fa.php';</script>";
                exit;
            }

            $redirectUrl = trim($_POST['redirect'] ?? ($_GET['redirect'] ?? ''));
            if (!empty($redirectUrl) && str_starts_with($redirectUrl, '/')) {
                $targetUrl = $redirectUrl;
            } elseif (!empty($_SESSION['pending_activation_key'])) {
                $targetUrl = '/user/activate_key.php?key=' . urlencode($_SESSION['pending_activation_key']);
            } else {
                $targetUrl = Auth::getRedirectForRole($user['role_name'] ?? 'user');
            }
            header("Location: " . $targetUrl);
            echo "<script>window.location.href=" . json_encode($targetUrl) . ";</script>";
            exit;
        } else {
            $error = $result['message'];
        }
        end_post:;
    }
}
?>
<!DOCTYPE html>
<html lang="ru" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вход в систему | AI Business Assistant</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/themes.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="/assets/js/cosmic-space.js" defer></script>
</head>
<body class="auth-page">
    <!-- Cosmic Canvas Background with Flying Rocket -->
    <canvas id="space-canvas"></canvas>

    <div class="auth-card">
        <!-- Floating Cosmic Emblem -->
        <div style="text-align:center;">
            <div class="auth-rocket-badge">
                <i class="fas fa-rocket"></i>
            </div>
        </div>

        <div class="logo mb-3 justify-content-center" style="display:flex; justify-content:center; align-items:center;">
            <span class="logo-badge">AI</span> Business Assistant
        </div>

        <!-- Interactive Animated Tabs -->
        <div class="auth-tabs">
            <a href="/login.php" class="auth-tab active">
                <i class="fas fa-sign-in-alt"></i> Вход
            </a>
            <a href="/register.php" class="auth-tab">
                <i class="fas fa-user-plus"></i> Регистрация
            </a>
        </div>

        <h3 class="mb-1 text-center" style="font-weight:800; letter-spacing:-0.4px;">Авторизация</h3>
        <p class="text-muted text-center mb-4" style="font-size:13px;">Войдите в личный кабинет для работы с бизнес-ассистентом</p>

        <?php if (Auth::isMaintenanceMode()): ?>
            <!-- Предупреждение о тех. работах на странице входа -->
            <div style="background:linear-gradient(135deg, rgba(245,158,11,0.15), rgba(217,119,6,0.06)); border:1px solid rgba(245,158,11,0.35); border-radius:12px; padding:12px 14px; margin-bottom:20px; display:flex; align-items:flex-start; gap:12px; text-align:left; box-shadow:0 4px 15px rgba(245,158,11,0.08);">
                <div style="width:30px; height:30px; border-radius:8px; background:rgba(245,158,11,0.2); display:flex; align-items:center; justify-content:center; color:#f59e0b; flex-shrink:0; font-size:14px; margin-top:2px;">
                    <i class="fas fa-tools"></i>
                </div>
                <div>
                    <div style="font-size:12px; font-weight:800; color:#fbbf24; text-transform:uppercase; letter-spacing:0.5px; display:flex; align-items:center; gap:6px;">
                        <span style="display:inline-block; width:6px; height:6px; border-radius:50%; background:#f59e0b;"></span>
                        Ведутся технические работы
                    </div>
                    <div style="font-size:11px; color:#cbd5e1; margin-top:3px; line-height:1.4;">
                        Платформа находится на плановом обновлении. Доступ открыт сотрудникам и клиентам с разрешенными разделами.
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php 
        $incomingKey = trim($_GET['key'] ?? ($_SESSION['pending_activation_key'] ?? ''));
        if (!empty($incomingKey)): ?>
            <div class="alert alert-info" style="font-size:13px; line-height:1.5; margin-bottom:18px;">
                <i class="fas fa-key"></i> 
                Войдите в свой аккаунт для активации ключа: 
                <strong style="font-family:monospace;"><?= htmlspecialchars($incomingKey) ?></strong>
            </div>
        <?php endif; ?>

        <?php if ($notice): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?= htmlspecialchars($notice) ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="/login.php" class="auth-form-animated" onsubmit="const btn = document.getElementById('submitBtn'); btn.disabled = true; btn.innerHTML = '<i class=\'fas fa-spinner fa-spin\'></i> Вход в систему...';">
            <?= Security::csrfField() ?>
            <input type="hidden" name="redirect" value="<?= htmlspecialchars($_GET['redirect'] ?? ($_POST['redirect'] ?? '')) ?>">

            <div class="form-group">
                <label class="form-label">Email или логин</label>
                <div style="position:relative;">
                    <input
                        type="text"
                        name="email"
                        class="form-control"
                        required
                        placeholder="email@mail.com или ваш_логин"
                        value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                        autocomplete="username"
                        style="padding-left: 38px;"
                    >
                    <i class="fas fa-user" style="position:absolute; left:13px; top:50%; transform:translateY(-50%); color:var(--text-muted); font-size:13px; pointer-events:none;"></i>
                </div>
                <small style="color:var(--text-muted); font-size:11px; margin-top:4px; display:block;">
                    Полный email или логин (часть до @)
                </small>
            </div>

            <div class="form-group">
                <label class="form-label">Пароль</label>
                <div style="position:relative;">
                    <input type="password" name="password" class="form-control" required placeholder="••••••••" style="padding-left: 38px;">
                    <i class="fas fa-lock" style="position:absolute; left:13px; top:50%; transform:translateY(-50%); color:var(--text-muted); font-size:13px; pointer-events:none;"></i>
                </div>
            </div>

            <button type="submit" id="submitBtn" class="btn btn-primary btn-block auth-submit-btn mt-3" style="padding:12px 18px; font-weight:700; font-size:14px; box-shadow:0 6px 20px rgba(99,102,241,0.45);">
                <i class="fas fa-sign-in-alt"></i> Войти в аккаунт
            </button>
        </form>

        <div style="display:flex; justify-content:center; gap:16px; margin-top:22px; font-size:13px;">
            <a href="/" class="text-muted" style="transition:all 0.2s;"><i class="fas fa-arrow-left"></i> На главную</a>
            <span class="text-muted">•</span>
            <a href="/support_guest.php" class="text-muted" style="transition:all 0.2s;"><i class="fas fa-headset"></i> Поддержка</a>
        </div>
    </div>
    <script src="/assets/js/main.js"></script>
</body>
</html>
