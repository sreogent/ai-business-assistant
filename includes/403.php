<?php
declare(strict_types=1);

/**
 * 403 Forbidden Error Template
 */
http_response_code(403);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>403 Доступ запрещен | AI Business Assistant</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="auth-page">
    <div class="auth-card text-center">
        <div class="logo mb-4">
            <span class="logo-badge">AI</span> Business Assistant
        </div>
        <div class="alert alert-danger" style="display:inline-block; margin-bottom: 20px;">
            <svg width="48" height="48" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
        </div>
        <h2 class="mb-2">403 — Доступ запрещен</h2>
        <p class="text-muted mb-4">У вас нет прав для просмотра этой страницы. Данный раздел предназначен исключительно для уполномоченных администраторов.</p>
        <a href="/login.php" class="btn btn-primary">Вернуться к авторизации</a>
        <a href="/user/dashboard.php" class="btn btn-secondary">В личный кабинет</a>
    </div>
</body>
</html>
