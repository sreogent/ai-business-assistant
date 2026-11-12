<?php
declare(strict_types=1);

/**
 * AI Business Assistant - User Registration
 */

require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/Security.php';
require_once __DIR__ . '/includes/Auth.php';

// Auto-check DB only if not yet initialized
if (!file_exists(__DIR__ . '/setup.lock')) {
    Database::checkAndMigrate();
}

if (Auth::check()) {
    header("Location: /user/dashboard.php");
    exit;
}

$error = null;
$success = null;

$isTrial = (isset($_GET['trial']) && $_GET['trial'] == '1') || (isset($_POST['trial']) && $_POST['trial'] == '1');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!Security::verifyCsrf($token)) {
        $error = "Истекло время действия формы безопасности (CSRF). Пожалуйста, повторите попытку.";
    } else {
        $firstName = $_POST['first_name'] ?? '';
        $lastName = $_POST['last_name'] ?? '';
        $email = $_POST['email'] ?? '';
        $phone = $_POST['phone'] ?? '';
        $password = $_POST['password'] ?? '';
        $passwordConfirm = $_POST['password_confirm'] ?? '';

        if ($password !== $passwordConfirm) {
            $error = "Введенные пароли не совпадают.";
        } else {
            $res = Auth::register($firstName, $lastName, $email, $phone, $password, $isTrial);
            if ($res['success']) {
                // Auto-login newly registered user
                Auth::attempt($email, $password);
                $_SESSION['new_account_tour'] = true;
                if (!empty($_SESSION['pending_activation_key'])) {
                    $redirectUrl = "/user/activate_key.php?key=" . urlencode($_SESSION['pending_activation_key']);
                } elseif (!empty($_GET['key'])) {
                    $redirectUrl = "/user/activate_key.php?key=" . urlencode(trim($_GET['key']));
                } else {
                    $redirectUrl = $isTrial ? "/user/dashboard.php?trial_started=1" : "/user/dashboard.php?registered=1";
                }
                header("Location: " . $redirectUrl);
                exit;
            } else {
                $error = $res['message'];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Регистрация | AI Business Assistant</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/themes.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="/assets/js/cosmic-space.js" defer></script>
</head>
<body class="auth-page">
    <!-- Cosmic Canvas Background with Flying Rocket -->
    <canvas id="space-canvas"></canvas>

    <div class="auth-card" style="max-width: 530px;">
        <!-- Floating Cosmic Emblem -->
        <div style="text-align:center;">
            <div class="auth-rocket-badge">
                <i class="fas fa-user-astronaut"></i>
            </div>
        </div>

        <div class="logo mb-3 justify-content-center" style="display:flex; justify-content:center; align-items:center;">
            <span class="logo-badge">AI</span> Business Assistant
        </div>

        <!-- Interactive Animated Tabs -->
        <div class="auth-tabs">
            <a href="/login.php" class="auth-tab">
                <i class="fas fa-sign-in-alt"></i> Вход
            </a>
            <a href="/register.php" class="auth-tab active">
                <i class="fas fa-user-plus"></i> Регистрация
            </a>
        </div>

        <h3 class="mb-1 text-center" style="font-weight:800; letter-spacing:-0.4px;">Создание аккаунта</h3>
        <p class="text-muted text-center mb-4" style="font-size:13px;">Зарегистрируйтесь для мгновенного доступа к AI ассистенту</p>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($isTrial): ?>
            <div class="alert alert-info" style="margin-bottom:20px; background:rgba(99,102,241,0.14); border:1px solid rgba(99,102,241,0.35); color:var(--text-primary); border-radius:12px; padding:12px 16px;">
                <i class="fas fa-gift text-primary" style="font-size:20px;"></i>
                <div>
                    <strong style="color:var(--text-primary);">Тестовый доступ (7 дней бесплатно)</strong>
                    <div style="font-size:12px; color:var(--text-muted); margin-top:2px;">
                        Вам будут доступны 200 AI-запросов в день и полный функционал CRM, документов и задач.
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <form method="POST" action="/register.php<?= $isTrial ? '?trial=1' : '' ?>" class="auth-form-animated">
            <?= Security::csrfField() ?>
            <?php if ($isTrial): ?>
                <input type="hidden" name="trial" value="1">
            <?php endif; ?>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <div class="form-group">
                    <label class="form-label">Имя</label>
                    <input type="text" name="first_name" class="form-control" required placeholder="Иван" value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Фамилия</label>
                    <input type="text" name="last_name" class="form-control" required placeholder="Петров" value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Email</label>
                <div style="position:relative;">
                    <input type="email" name="email" class="form-control" required placeholder="alex@company.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" style="padding-left: 38px;">
                    <i class="fas fa-envelope" style="position:absolute; left:13px; top:50%; transform:translateY(-50%); color:var(--text-muted); font-size:13px; pointer-events:none;"></i>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Телефон</label>
                <div style="position:relative;">
                    <input type="tel" name="phone" class="form-control" required placeholder="+7 777 000 11 22" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" style="padding-left: 38px;">
                    <i class="fas fa-phone" style="position:absolute; left:13px; top:50%; transform:translateY(-50%); color:var(--text-muted); font-size:13px; pointer-events:none;"></i>
                </div>
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <div class="form-group">
                    <label class="form-label">Пароль (мин. 8 симв.)</label>
                    <input type="password" name="password" class="form-control" required minlength="8" placeholder="••••••••">
                </div>
                <div class="form-group">
                    <label class="form-label">Подтверждение</label>
                    <input type="password" name="password_confirm" class="form-control" required minlength="8" placeholder="••••••••">
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-block auth-submit-btn mt-3" style="padding:12px 18px; font-weight:700; font-size:14px; box-shadow:0 6px 20px rgba(99,102,241,0.45);">
                <i class="fas fa-user-plus"></i> Зарегистрироваться
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
