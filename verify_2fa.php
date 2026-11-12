<?php
declare(strict_types=1);
ob_start();

/**
 * AI Business Assistant - 2FA Security Code Verification
 * Ввод специального 4-значного кода безопасности для привилегированных ролей.
 * Кулдаун после успешного входа: 2 часа (на доверенном устройстве повторный ввод не запрашивается).
 */

require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/Security.php';
require_once __DIR__ . '/includes/Auth.php';
require_once __DIR__ . '/includes/Mailer.php';

Security::startSession();

// Если пользователь уже авторизован
if (Auth::check()) {
    $u = Auth::user();
    $targetUrl = Auth::getRedirectForRole($u['role_name'] ?? 'user');
    header("Location: " . $targetUrl);
    echo "<script>window.location.href=" . json_encode($targetUrl) . ";</script>";
    exit;
}

$pendingId   = $_SESSION['2fa_pending_user_id'] ?? null;
$savedCode   = $_SESSION['2fa_code'] ?? null;
$userRole    = $_SESSION['2fa_role'] ?? 'admin';
$userEmail   = $_SESSION['2fa_email'] ?? '';
$userName    = $_SESSION['2fa_name'] ?? 'Пользователь';

// Если нет ожидающей авторизации — редирект на вход
if (!$pendingId || !$savedCode) {
    header("Location: /login.php");
    echo "<script>window.location.href='/login.php';</script>";
    exit;
}

$roleTitles = [
    'vrio_owner'    => 'ВРИО Владелец платформы',
    'admin'         => 'Администратор платформы',
    'developer'     => 'Разработчик платформы',
    'tester'        => 'Тестировщик платформы',
    'support_agent' => 'Агент поддержки',
    'director'      => 'Генеральный Директор',
    'manager'       => 'Менеджер'
];
$roleDisplay = $roleTitles[$userRole] ?? 'Администратор платформы';

$error = null;
$notice = null;

// Обработка повторной отправки кода
if (isset($_GET['action']) && $_GET['action'] === 'resend') {
    $lastSent = $_SESSION['2fa_last_resend_time'] ?? 0;
    if (time() - $lastSent < 30) {
        $waitSec = 30 - (time() - $lastSent);
        $error = "Пожалуйста, подождите {$waitSec} сек. перед повторной отправкой кода.";
    } else {
        $newCode = str_pad((string)random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
        $_SESSION['2fa_code'] = $newCode;
        $_SESSION['2fa_expires'] = time() + 600;
        $_SESSION['2fa_last_resend_time'] = time();

        Mailer::send2FACode($userEmail, $userName, $newCode, $roleDisplay);
        $notice = "Новый 4-значный код безопасности успешно отправлен на вашу почту!";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!Security::verifyCsrf($token)) {
        $error = "Истёк токен безопасности формы. Повторите попытку.";
    } else {
        $inputCode = trim($_POST['code_2fa'] ?? '');
        $res = Auth::verify2FA($inputCode);

        if ($res['success']) {
            $user = $res['user'];
            $targetUrl = '';
            if (!empty($_SESSION['login_redirect'])) {
                $targetUrl = $_SESSION['login_redirect'];
                unset($_SESSION['login_redirect']);
            } elseif (!empty($_SESSION['pending_activation_key'])) {
                $targetUrl = '/user/activate_key.php?key=' . urlencode($_SESSION['pending_activation_key']);
            } else {
                $targetUrl = Auth::getRedirectForRole($user['role_name'] ?? 'user');
            }
            header("Location: " . $targetUrl);
            echo "<script>window.location.href=" . json_encode($targetUrl) . ";</script>";
            exit;
        } else {
            $error = $res['message'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Подтверждение входа 2FA | AI Business Assistant</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/themes.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="/assets/js/cosmic-space.js" defer></script>
    <style>
        .pin-box {
            width: 60px;
            height: 68px;
            text-align: center;
            font-size: 28px;
            font-weight: 800;
            border-radius: 14px;
            background: rgba(10, 16, 32, 0.85);
            border: 1.5px solid rgba(255, 255, 255, 0.12);
            color: #818cf8;
            font-family: monospace;
            transition: all 0.22s cubic-bezier(0.16, 1, 0.3, 1);
            backdrop-filter: blur(10px);
        }
        .pin-box:focus {
            outline: none;
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.35), 0 0 20px rgba(99, 102, 241, 0.3);
            background: rgba(14, 22, 44, 0.95);
            transform: translateY(-2px);
            color: #ffffff;
        }
        .pin-box.filled {
            border-color: rgba(56, 189, 248, 0.65);
            color: #38bdf8;
            box-shadow: 0 0 14px rgba(56, 189, 248, 0.2);
        }
    </style>
</head>
<body class="auth-page">
    <!-- Cosmic Canvas Background with animated flying rocket -->
    <canvas id="space-canvas"></canvas>

    <div class="auth-card" style="max-width: 480px;">
        <!-- Animated Security Shield Badge -->
        <div style="text-align:center;">
            <div class="auth-rocket-badge" style="background:linear-gradient(135deg, rgba(99,102,241,0.3) 0%, rgba(6,182,212,0.2) 100%); border-color:rgba(99,102,241,0.5); box-shadow:0 0 25px rgba(99,102,241,0.4);">
                <i class="fas fa-shield-alt"></i>
            </div>
        </div>

        <h3 class="mb-1 text-center" style="font-weight:800; letter-spacing:-0.4px;">Код безопасности 2FA</h3>
        
        <div style="display:flex; justify-content:center; margin: 8px 0 18px;">
            <span class="badge badge-primary" style="padding:5px 12px; font-size:11.5px; letter-spacing:0.3px;">
                <i class="fas fa-user-shield"></i> <?= htmlspecialchars($roleDisplay) ?>
            </span>
        </div>

        <?php if ($notice): ?>
            <div class="alert alert-success" style="font-size:13px; margin-bottom:18px;">
                <i class="fas fa-check-circle"></i> <?= htmlspecialchars($notice) ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger" style="font-size:13px; margin-bottom:18px;">
                <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <!-- Email & Cooldown Callout -->
        <div style="background:rgba(99,102,241,0.08); border:1px solid rgba(99,102,241,0.22); border-radius:14px; padding:14px 18px; margin-bottom:24px; text-align:center;">
            <div style="font-size:12px; color:var(--text-muted); margin-bottom:4px;">Одноразовый код отправлен на почту:</div>
            <div style="font-weight:700; color:#38bdf8; font-size:14px; word-break:break-all;"><?= htmlspecialchars($userEmail) ?></div>
            <div style="margin-top:8px; padding-top:8px; border-top:1px solid rgba(255,255,255,0.06); font-size:11.5px; color:#a5b4fc; display:flex; align-items:center; justify-content:center; gap:6px;">
                <i class="fas fa-history" style="color:#38bdf8;"></i> <span>Кулдаун: <strong>2 часа</strong> (вход без повторного кода)</span>
            </div>
        </div>

        <form method="POST" id="pinForm" class="auth-form-animated">
            <?= Security::csrfField() ?>
            <input type="hidden" name="code_2fa" id="code_2fa" value="">

            <div class="form-group mb-4" style="text-align:center;">
                <label class="form-label text-center" style="display:block; font-size:11.5px; text-transform:uppercase; letter-spacing:1px; margin-bottom:14px; color:var(--text-secondary); font-weight:700;">
                    Введите 4 цифры из письма
                </label>

                <!-- 4 Segmented Interactive PIN Boxes -->
                <div style="display:flex; justify-content:center; gap:12px;">
                    <input type="text" class="pin-box" maxlength="1" pattern="[0-9]" inputmode="numeric" autocomplete="one-time-code" autofocus data-idx="0">
                    <input type="text" class="pin-box" maxlength="1" pattern="[0-9]" inputmode="numeric" autocomplete="one-time-code" data-idx="1">
                    <input type="text" class="pin-box" maxlength="1" pattern="[0-9]" inputmode="numeric" autocomplete="one-time-code" data-idx="2">
                    <input type="text" class="pin-box" maxlength="1" pattern="[0-9]" inputmode="numeric" autocomplete="one-time-code" data-idx="3">
                </div>
            </div>

            <button type="submit" id="submitBtn" class="btn btn-primary btn-block auth-submit-btn" style="padding:13px 18px; font-size:14.5px; font-weight:700; box-shadow:0 6px 22px rgba(99,102,241,0.45);">
                <i class="fas fa-unlock-alt"></i> Подтвердить и войти
            </button>
        </form>

        <div style="display:flex; justify-content:space-between; align-items:center; margin-top:22px; padding-top:16px; border-top:1px solid rgba(255,255,255,0.06); font-size:12.5px;">
            <a href="/login.php" class="text-muted" style="text-decoration:none; transition:color 0.2s;">
                <i class="fas fa-arrow-left"></i> Назад ко входу
            </a>
            <a href="/verify_2fa.php?action=resend" style="color:var(--primary); text-decoration:none; font-weight:600; display:inline-flex; align-items:center; gap:6px; transition:color 0.2s;">
                <i class="fas fa-redo"></i> Отправить снова
            </a>
        </div>
    </div>

    <script src="/assets/js/main.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const pinBoxes = Array.from(document.querySelectorAll('.pin-box'));
        const hiddenInput = document.getElementById('code_2fa');
        const form = document.getElementById('pinForm');
        const submitBtn = document.getElementById('submitBtn');

        function updateHiddenInput() {
            const code = pinBoxes.map(b => b.value).join('');
            hiddenInput.value = code;
            pinBoxes.forEach(b => {
                if (b.value) b.classList.add('filled');
                else b.classList.remove('filled');
            });
            if (code.length === 4) {
                // Auto submit when 4 digits are completed
                setTimeout(() => {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Проверка кода...';
                    form.submit();
                }, 150);
            }
        }

        pinBoxes.forEach((box, idx) => {
            box.addEventListener('input', (e) => {
                const val = box.value.replace(/\D/g, '');
                box.value = val ? val[0] : '';
                if (box.value && idx < pinBoxes.length - 1) {
                    pinBoxes[idx + 1].focus();
                    pinBoxes[idx + 1].select();
                }
                updateHiddenInput();
            });

            box.addEventListener('keydown', (e) => {
                if (e.key === 'Backspace' && !box.value && idx > 0) {
                    pinBoxes[idx - 1].focus();
                    pinBoxes[idx - 1].select();
                } else if (e.key === 'ArrowLeft' && idx > 0) {
                    pinBoxes[idx - 1].focus();
                } else if (e.key === 'ArrowRight' && idx < pinBoxes.length - 1) {
                    pinBoxes[idx + 1].focus();
                }
            });

            box.addEventListener('paste', (e) => {
                e.preventDefault();
                const text = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '');
                if (text) {
                    for (let i = 0; i < pinBoxes.length; i++) {
                        pinBoxes[i].value = text[i] || '';
                    }
                    const nextEmpty = pinBoxes.findIndex(b => !b.value);
                    if (nextEmpty !== -1) {
                        pinBoxes[nextEmpty].focus();
                    } else {
                        pinBoxes[pinBoxes.length - 1].focus();
                    }
                    updateHiddenInput();
                }
            });
        });

        // Focus first box on load
        if (pinBoxes[0]) pinBoxes[0].focus();

        form.addEventListener('submit', () => {
            const code = pinBoxes.map(b => b.value).join('');
            hiddenInput.value = code;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Проверка кода...';
        });
    });
    </script>
</body>
</html>
