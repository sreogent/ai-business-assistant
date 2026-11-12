<?php
declare(strict_types=1);

/**
 * AI Business Assistant - Automated Setup & Health Check
 * Verifies DB connection, executes migrations, and allows creation of 1st administrator.
 * Automatically locks or self-deletes once an administrator is created.
 */

require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/Security.php';

$lockFile = __DIR__ . '/setup.lock';
if (file_exists($lockFile)) {
    http_response_code(403);
    die("Установка уже выполнена. Файл setup.php заблокирован в целях безопасности. Удалите setup.lock вручную только при необходимости повторной инициализации.");
}

$migrationStatus = Database::checkAndMigrate();
$pdo = Database::getConnection(true);

$adminCount = 0;
if ($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT COUNT(*) FROM users u 
            JOIN roles r ON u.role_id = r.id 
            WHERE r.name = 'admin'
        ");
        $adminCount = (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        $adminCount = 0;
    }
}

// If admin already exists, lock immediately
if ($adminCount > 0) {
    file_put_contents($lockFile, date('Y-m-d H:i:s') . " - Administrator already present.\n");
    header("Location: /login.php");
    exit;
}

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $email = trim(mb_strtolower($_POST['email'] ?? ''));
    $phone = preg_replace('/[^\d+]/', '', trim($_POST['phone'] ?? ''));
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';

    if (empty($firstName) || empty($lastName) || empty($email) || empty($phone) || empty($password)) {
        $error = "Все поля обязательны для заполнения.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Указан некорректный адрес электронной почты.";
    } elseif (mb_strlen($password) < 8) {
        $error = "Пароль администратора должен быть не короче 8 символов.";
    } elseif ($password !== $passwordConfirm) {
        $error = "Пароли не совпадают.";
    } elseif (!$pdo) {
        $error = "Нет подключения к базе данных. Проверьте .env файл.";
    } else {
        try {
            // Verify admin doesn't exist again
            $check = $pdo->query("SELECT COUNT(*) FROM users u JOIN roles r ON u.role_id = r.id WHERE r.name = 'admin'")->fetchColumn();
            if ((int)$check > 0) {
                file_put_contents($lockFile, date('Y-m-d H:i:s') . " - Admin created elsewhere.\n");
                header("Location: /login.php");
                exit;
            }

            $roleStmt = $pdo->prepare("SELECT id FROM roles WHERE name = 'admin' LIMIT 1");
            $roleStmt->execute();
            $adminRoleId = $roleStmt->fetchColumn();

            $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $stmt = $pdo->prepare("
                INSERT INTO users (role_id, first_name, last_name, email, phone, password_hash, status)
                VALUES (?, ?, ?, ?, ?, ?, 'active')
            ");
            $stmt->execute([$adminRoleId, $firstName, $lastName, $email, $phone, $passwordHash]);
            $newAdminId = (int)$pdo->lastInsertId();

            Security::log($pdo, 'admin_created_via_setup', "Главный администратор создан: {$email}", $newAdminId);

            // Automatically lock setup.php to prevent multiple admins
            file_put_contents($lockFile, date('Y-m-d H:i:s') . " - Installed and locked successfully by {$email}\n");

            // Redirect to login with notice
            header("Location: /login.php?setup_done=1");
            exit;

        } catch (PDOException $e) {
            $error = "Ошибка при создании администратора: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Установка и проверка системы | AI Business Assistant</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="auth-page">
    <div class="auth-card" style="max-width: 580px;">
        <div class="logo mb-4 text-center">
            <span class="logo-badge">AI</span> Setup & Health Check
        </div>

        <h3 class="mb-3 text-center">Мастер первого запуска</h3>
        <p class="text-muted text-center mb-4">Проверка готовности базы данных и создание главного администратора</p>

        <!-- Database Status -->
        <?php if ($migrationStatus['success']): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <div>
                    <strong><?= htmlspecialchars($migrationStatus['message']) ?></strong>
                    <div style="font-size:12px; margin-top:2px;">
                        <?= $migrationStatus['tables_created'] ? 'Таблицы схемы были успешно созданы.' : 'Все системные таблицы проверены и готовы к работе.' ?>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle"></i>
                <div>
                    <strong>Ошибка подключения к БД:</strong>
                    <div style="font-size:12px; margin-top:4px;"><?= htmlspecialchars($migrationStatus['message']) ?></div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-times-circle"></i> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($migrationStatus['success']): ?>
            <div class="card p-3 mb-4" style="background:var(--bg-secondary); border-radius:var(--radius-md); padding:16px;">
                <h4 style="font-size:15px; margin-bottom:12px;"><i class="fas fa-user-shield text-primary"></i> Создание главного администратора</h4>
                <p class="text-muted" style="font-size:13px; margin-bottom:16px;">
                    В системе еще нет учетной записи администратора. Заполните форму ниже. После создания файл установки будет заблокирован автоматически.
                </p>

                <form method="POST" action="/setup.php">
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                        <div class="form-group">
                            <label class="form-label">Имя</label>
                            <input type="text" name="first_name" class="form-control" required placeholder="Александр" value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Фамилия</label>
                            <input type="text" name="last_name" class="form-control" required placeholder="Иванов" value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" required placeholder="admin@business.ai" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Телефон</label>
                        <input type="tel" name="phone" class="form-control" required placeholder="+79991234567" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                        <div class="form-group">
                            <label class="form-label">Пароль (мин. 8 символов)</label>
                            <input type="password" name="password" class="form-control" required minlength="8" placeholder="••••••••">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Повторите пароль</label>
                            <input type="password" name="password_confirm" class="form-control" required minlength="8" placeholder="••••••••">
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-block mt-3">
                        <i class="fas fa-lock"></i> Завершить установку и создать администратора
                    </button>
                </form>
            </div>
        <?php else: ?>
            <p class="text-secondary text-center">
                Пожалуйста, запустите MySQL сервер (XAMPP / OpenServer) и настройте параметры доступа в файле <code>.env</code>, затем обновите эту страницу.
            </p>
        <?php endif; ?>
    </div>
</body>
</html>
