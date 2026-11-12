<?php
declare(strict_types=1);

/**
 * AI Business Assistant - Header Template & Role Navigation Manager
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Security.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/AutoMigrate.php';
AutoMigrate::run(); // Авто-создание недостающих таблиц при старте

$user = Auth::user();
Auth::enforceMaintenanceMode();
$currentUri = $_SERVER['REQUEST_URI'] ?? '';
$userRole = $user['role_name'] ?? 'user';

// Ролевые флаги
$isAdmin = Auth::isAdmin();
$isOwner = Auth::isOwner();
$isDev   = Auth::isDeveloper();
$isTest  = Auth::isTester();
$isSupp  = Auth::isSupport();
$isStaff = Auth::isStaff();

$userHasOrg = false;
$userOrg = null;
$userPendingOrgReqs = 0;
$userActiveTickets = 0;
$adminPendingOrgReqs = 0;
$adminPendingTickets = 0;
$userUnreadNews = 0;
$latestUnreadNews = null;

try {
    $pdoHeader = Database::getConnection(true);
    if ($pdoHeader && $user) {
        // Проверяем наличие организации для ВСЕХ пользователей (включая админов и владельцев)
        $checkOrgStmt = $pdoHeader->prepare("
            SELECT id, name, business_type 
            FROM organizations 
            WHERE (user_id = ? OR director_email = ?) AND is_active = 1 
            ORDER BY id DESC LIMIT 1
        ");
        $checkOrgStmt->execute([$user['id'], $user['email']]);
        $userOrg = $checkOrgStmt->fetch();
        $userHasOrg = !empty($userOrg);

        $checkReqStmt = $pdoHeader->prepare("SELECT COUNT(*) FROM organization_requests WHERE user_id = ? AND status IN ('pending', 'in_review')");
        $checkReqStmt->execute([$user['id']]);
        $userPendingOrgReqs = (int)$checkReqStmt->fetchColumn();

        $checkTickStmt = $pdoHeader->prepare("SELECT COUNT(*) FROM support_tickets WHERE user_id = ? AND status NOT IN ('closed', 'archive')");
        $checkTickStmt->execute([$user['id']]);
        $userActiveTickets = (int)$checkTickStmt->fetchColumn();

        // Проверяем непрочитанные новости для пользователя
        try {
            $stNews = $pdoHeader->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'news' AND is_read = 0");
            $stNews->execute([(int)$user['id']]);
            $userUnreadNews = (int)$stNews->fetchColumn();

            if ($userUnreadNews > 0) {
                $stLatest = $pdoHeader->prepare("SELECT id, title, body FROM notifications WHERE user_id = ? AND type = 'news' AND is_read = 0 ORDER BY id DESC LIMIT 1");
                $stLatest->execute([(int)$user['id']]);
                $latestUnreadNews = $stLatest->fetch();
            }
        } catch (\Throwable $ne) {}

        if ($isStaff) {
            $adminPendingOrgReqs = (int)$pdoHeader->query("SELECT COUNT(*) FROM organization_requests WHERE status = 'pending'")->fetchColumn();
            $adminPendingTickets = (int)$pdoHeader->query("SELECT COUNT(*) FROM support_tickets WHERE status = 'pending'")->fetchColumn();
        }
    }
} catch (\Throwable $e) {}

// Ролевые бейджи для шапки
$headerRoleBadges = [
    'vrio_owner'    => ['title' => 'ВРИО Владелец',   'bg' => 'linear-gradient(135deg, #d97706, #b45309)', 'color' => '#fff', 'icon' => 'fa-crown'],
    'developer'     => ['title' => 'Разработчик',     'bg' => 'linear-gradient(135deg, #06b6d4, #0891b2)', 'color' => '#fff', 'icon' => 'fa-code'],
    'tester'        => ['title' => 'Тестировщик',     'bg' => 'linear-gradient(135deg, #10b981, #059669)', 'color' => '#fff', 'icon' => 'fa-vial'],
    'support_agent' => ['title' => 'Агент поддержки', 'bg' => 'linear-gradient(135deg, #3b82f6, #2563eb)', 'color' => '#fff', 'icon' => 'fa-headset'],
    'admin'         => ['title' => 'Администратор',   'bg' => 'linear-gradient(135deg, #6366f1, #4f46e5)', 'color' => '#fff', 'icon' => 'fa-shield-alt'],
    'director'      => ['title' => 'Директор бизнеса','bg' => 'linear-gradient(135deg, #8b5cf6, #7c3aed)', 'color' => '#fff', 'icon' => 'fa-user-tie'],
    'manager'       => ['title' => 'Менеджер',        'bg' => 'linear-gradient(135deg, #ec4899, #db2777)', 'color' => '#fff', 'icon' => 'fa-briefcase'],
    'user'          => ['title' => 'Пользователь',    'bg' => 'rgba(255,255,255,0.1)',                    'color' => '#9ca3af', 'icon' => 'fa-user'],
];
$currentRoleBadge = $headerRoleBadges[$userRole] ?? $headerRoleBadges['user'];

$isMaintActive = Auth::isMaintenanceMode();
$maintAllowedSections = $isMaintActive ? Auth::getMaintenanceAllowedSections() : [];
$isSupportAgentMaint = $isMaintActive && ($userRole === 'support_agent');
$isUserMaint = $isMaintActive && !$isStaff;

$rawSectionLocks = Auth::getSectionLocks();
$activeLocksMap = [];
foreach ($rawSectionLocks as $sl) {
    if ((int)$sl['is_locked'] === 1) {
        $activeLocksMap[$sl['section_key']] = $sl;
        if ($sl['section_key'] === 'crm') $activeLocksMap['clients'] = $sl;
        if ($sl['section_key'] === 'docs') $activeLocksMap['documents'] = $sl;
    }
}

// Функция проверки доступности пункта меню во время ТО и ручной блокировки разделов
$isSecAllowed = function(string $secKey) use ($isMaintActive, $isStaff, $isSupportAgentMaint, $maintAllowedSections, $isAdmin, $isOwner, $isDev, $isTest, $activeLocksMap) {
    if (isset($activeLocksMap[$secKey])) {
        return false;
    }
    if (!$isMaintActive) return true;
    if ($isAdmin || $isOwner || $isDev || $isTest) return true;
    if ($isSupportAgentMaint) {
        return ($secKey === 'support');
    }
    return in_array($secKey, $maintAllowedSections, true);
};
?>
<!DOCTYPE html>
<html lang="ru" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(Security::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
    <script>
        window.APP_CSRF = <?= json_encode(Security::csrfToken()) ?>;
        window.DM_CSRF = window.APP_CSRF;
        window.CSRF_TOKEN = window.APP_CSRF;
        window.CURRENT_USER_ID = <?= (int)($user['id'] ?? 0) ?>;
        window.DM_MY_ID = <?= (int)($user['id'] ?? 0) ?>;
        window.DM_MY_NAME = <?= json_encode(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: 'Сотрудник') ?>;
    </script>
    <title><?= htmlspecialchars($pageTitle ?? 'AI Business Assistant', ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/themes.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        .sidebar-link-maint-locked {
            opacity: 0.32 !important;
            pointer-events: none !important;
            cursor: not-allowed !important;
            filter: grayscale(0.85) !important;
        }
        .sidebar-link-support-active {
            background: rgba(59, 130, 246, 0.25) !important;
            border: 1px solid rgba(59, 130, 246, 0.6) !important;
            color: #60a5fa !important;
            font-weight: 800 !important;
            box-shadow: 0 0 16px rgba(59, 130, 246, 0.35) !important;
            border-radius: 8px !important;
        }
        @keyframes blinkLive {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.3; }
        }
        /* Анимации для модального окна тех. работ */
        @keyframes maintBackdropFadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        @keyframes maintModalDrop {
            0% { opacity: 0; transform: scale(0.88) translateY(-24px); }
            100% { opacity: 1; transform: scale(1) translateY(0); }
        }
        @keyframes maintGearSpin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        @keyframes maintGearRevSpin {
            0% { transform: rotate(360deg); }
            100% { transform: rotate(0deg); }
        }
        @keyframes maintPulseRing {
            0% { transform: scale(0.88); opacity: 0.9; box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.7); }
            70% { transform: scale(1.18); opacity: 0; box-shadow: 0 0 0 24px rgba(245, 158, 11, 0); }
            100% { transform: scale(0.88); opacity: 0; box-shadow: 0 0 0 0 rgba(245, 158, 11, 0); }
        }
        @keyframes maintShimmerBar {
            0% { background-position: -250% 0; }
            100% { background-position: 250% 0; }
        }
        @keyframes maintGlowBorder {
            0%, 100% { border-color: rgba(245, 158, 11, 0.45); box-shadow: 0 25px 70px rgba(0,0,0,0.8), 0 0 35px rgba(245, 158, 11, 0.2); }
            50% { border-color: rgba(245, 158, 11, 0.85); box-shadow: 0 25px 70px rgba(0,0,0,0.8), 0 0 55px rgba(245, 158, 11, 0.4); }
        }
    </style>
    <script>
    // Полная блокировка браузерного перетаскивания ссылок и фантомов с адресом localhost
    window.addEventListener('dragstart', function(e) {
        if (!e.target.closest || !e.target.closest('.allow-drag')) {
            e.preventDefault();
            return false;
        }
    }, true);
    document.addEventListener('dragstart', function(e) {
        if (!e.target.closest || !e.target.closest('.allow-drag')) {
            e.preventDefault();
            return false;
        }
    }, true);
    </script>
</head>
<body>
<div class="app-container">
    <!-- Боковая панель навигации -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <a href="/" class="logo">
                <span class="logo-badge">AI</span> Business Assistant
            </a>
        </div>
        <ul class="sidebar-menu">
            
            <?php if (str_starts_with($currentUri, '/admin') && $isStaff): ?>
                <?php if ($isSupportAgentMaint): ?>
                    <!-- Уведомление для агента поддержки во время тех. работ в админ-панели -->
                    <li style="margin:6px 10px 12px 10px; padding:10px 12px; background:rgba(239,68,68,0.15); border:1px solid rgba(239,68,68,0.4); border-radius:10px;">
                        <div style="font-size:11px; font-weight:800; color:#ef4444; display:flex; align-items:center; gap:6px; text-transform:uppercase;">
                            <i class="fas fa-lock"></i> Доступ ограничен
                        </div>
                        <div style="font-size:10px; color:#cbd5e1; margin-top:3px; line-height:1.3;">
                            Режим тех. работ: Вам открыты <strong>только тикеты и чат поддержки</strong>.
                        </div>
                    </li>
                <?php endif; ?>

                <!-- Панель управления Администратора / Платформы -->
                <li style="margin-bottom:6px; padding-left:14px; font-size:11px; text-transform:uppercase; color:var(--text-muted); letter-spacing:1px; font-weight:700;">
                    Консоль Платформы
                </li>
                <li>
                    <a href="/admin/dashboard.php" class="sidebar-link <?= (str_contains($currentUri, 'dashboard') && str_starts_with($currentUri, '/admin')) ? 'active' : '' ?> <?= $isSupportAgentMaint ? 'sidebar-link-maint-locked' : '' ?>">
                        <i class="fas fa-chart-pie"></i> Общий дашборд <?= $isSupportAgentMaint ? '<i class="fas fa-lock" style="font-size:10px; margin-left:auto;"></i>' : '' ?>
                    </a>
                </li>
                <li>
                    <a href="/admin/users.php" class="sidebar-link <?= str_contains($currentUri, 'users') ? 'active' : '' ?> <?= $isSupportAgentMaint ? 'sidebar-link-maint-locked' : '' ?>">
                        <i class="fas fa-users-cog"></i> Пользователи & Роли <?= $isSupportAgentMaint ? '<i class="fas fa-lock" style="font-size:10px; margin-left:auto;"></i>' : '' ?>
                    </a>
                </li>
                <li>
                    <a href="/chat.php?channel=site_hq" class="sidebar-link <?= str_contains($currentUri, 'site_hq') ? 'active' : '' ?> <?= $isSupportAgentMaint ? 'sidebar-link-maint-locked' : '' ?>">
                        <i class="fas fa-crown"></i> Чат руководства сайта <?= $isSupportAgentMaint ? '<i class="fas fa-lock" style="font-size:10px; margin-left:auto;"></i>' : '' ?>
                    </a>
                </li>
                <li>
                    <a href="/webmail.php" class="sidebar-link <?= str_contains($currentUri, 'webmail.php') ? 'active' : '' ?> <?= $isSupportAgentMaint ? 'sidebar-link-maint-locked' : '' ?>">
                        <i class="fas fa-envelope-open-text"></i> Почта (Webmail) <?= $isSupportAgentMaint ? '<i class="fas fa-lock" style="font-size:10px; margin-left:auto;"></i>' : '' ?>
                    </a>
                </li>

                <li style="margin-top:10px; margin-bottom:4px; padding-left:14px; font-size:11px; text-transform:uppercase; color:var(--text-muted); letter-spacing:1px; font-weight:700;">
                    Консоли специалистов
                </li>
                <?php if ($isOwner): ?>
                <li>
                    <a href="/owner/dashboard.php" class="sidebar-link" style="color:#d97706;">
                        <i class="fas fa-crown"></i> Кабинет ВРИО Владельца
                    </a>
                </li>
                <?php endif; ?>

                <?php if ($isDev): ?>
                <li>
                    <a href="/developer/dashboard.php" class="sidebar-link" style="color:#06b6d4;">
                        <i class="fas fa-code"></i> Кабинет Разработчика
                    </a>
                </li>
                <?php endif; ?>
                <?php if ($isTest): ?>
                <li>
                    <a href="/tester/dashboard.php" class="sidebar-link" style="color:#10b981;">
                        <i class="fas fa-vial"></i> Кабинет Тестировщика
                    </a>
                </li>
                <?php endif; ?>
                <?php if ($isSupp): ?>
                <li>
                    <a href="/support/dashboard.php" class="sidebar-link <?= $isSupportAgentMaint ? 'sidebar-link-support-active' : '' ?>" style="color:#3b82f6;">
                        <i class="fas fa-headset"></i> Рабочее место поддержки
                    </a>
                </li>
                <?php endif; ?>

                <li style="margin-top:10px; margin-bottom:4px; padding-left:14px; font-size:11px; text-transform:uppercase; color:var(--text-muted); letter-spacing:1px; font-weight:700;">
                    Бизнес & POS
                </li>
                <li>
                    <a href="/admin/org_requests.php" class="sidebar-link <?= str_contains($currentUri, 'org_requests') ? 'active' : '' ?> <?= $isSupportAgentMaint ? 'sidebar-link-maint-locked' : '' ?>">
                        <i class="fas fa-clipboard-check"></i> Заявки на компании <?= $isSupportAgentMaint ? '<i class="fas fa-lock" style="font-size:10px; margin-left:auto;"></i>' : '' ?>
                        <?php if ($adminPendingOrgReqs > 0 && !$isSupportAgentMaint): ?>
                            <span style="background:#3b82f6;color:#fff;border-radius:20px;font-size:11px;font-weight:700;padding:1px 7px;margin-left:auto;"><?= $adminPendingOrgReqs ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li>
                    <a href="/admin/organizations.php" class="sidebar-link <?= str_contains($currentUri, 'organizations') ? 'active' : '' ?> <?= $isSupportAgentMaint ? 'sidebar-link-maint-locked' : '' ?>">
                        <i class="fas fa-building"></i> Организации <?= $isSupportAgentMaint ? '<i class="fas fa-lock" style="font-size:10px; margin-left:auto;"></i>' : '' ?>
                    </a>
                </li>
                <li>
                    <a href="/admin/positions.php" class="sidebar-link <?= str_contains($currentUri, 'positions') ? 'active' : '' ?> <?= $isSupportAgentMaint ? 'sidebar-link-maint-locked' : '' ?>">
                        <i class="fas fa-user-tag"></i> Должности компании <?= $isSupportAgentMaint ? '<i class="fas fa-lock" style="font-size:10px; margin-left:auto;"></i>' : '' ?>
                    </a>
                </li>
                <li>
                    <a href="/admin/menu_items.php" class="sidebar-link <?= str_contains($currentUri, 'menu_items') ? 'active' : '' ?> <?= $isSupportAgentMaint ? 'sidebar-link-maint-locked' : '' ?>">
                        <i class="fas fa-utensils"></i> Меню & Товары <?= $isSupportAgentMaint ? '<i class="fas fa-lock" style="font-size:10px; margin-left:auto;"></i>' : '' ?>
                    </a>
                </li>
                <li>
                    <a href="/admin/cashier.php" class="sidebar-link <?= str_contains($currentUri, 'cashier') ? 'active' : '' ?> <?= $isSupportAgentMaint ? 'sidebar-link-maint-locked' : '' ?>">
                        <i class="fas fa-cash-register"></i> Касса / POS-терминал <?= $isSupportAgentMaint ? '<i class="fas fa-lock" style="font-size:10px; margin-left:auto;"></i>' : '' ?>
                    </a>
                </li>

                <li style="margin-top:10px; margin-bottom:4px; padding-left:14px; font-size:11px; text-transform:uppercase; color:var(--text-muted); letter-spacing:1px; font-weight:700;">
                    Техподдержка
                </li>
                <li>
                    <a href="/admin/support.php" class="sidebar-link <?= str_contains($currentUri, 'support') ? 'active' : '' ?> <?= $isSupportAgentMaint ? 'sidebar-link-support-active' : '' ?>">
                        <i class="fas fa-headset"></i> Чат поддержки & Тикеты
                        <?php if ($adminPendingTickets > 0): ?>
                            <span style="background:#ef4444;color:#fff;border-radius:20px;font-size:11px;font-weight:700;padding:1px 7px;margin-left:auto;"><?= $adminPendingTickets ?></span>
                        <?php endif; ?>
                    </a>
                </li>

                <li style="margin-top:10px; margin-bottom:4px; padding-left:14px; font-size:11px; text-transform:uppercase; color:var(--text-muted); letter-spacing:1px; font-weight:700;">
                    Тарифы & Финансы
                </li>
                <li>
                    <a href="/admin/tariffs.php" class="sidebar-link <?= str_contains($currentUri, 'tariffs') ? 'active' : '' ?> <?= $isSupportAgentMaint ? 'sidebar-link-maint-locked' : '' ?>">
                        <i class="fas fa-tags"></i> Тарифы & Цены <?= $isSupportAgentMaint ? '<i class="fas fa-lock" style="font-size:10px; margin-left:auto;"></i>' : '' ?>
                    </a>
                </li>
                <li>
                    <a href="/admin/payment_requests.php" class="sidebar-link <?= str_contains($currentUri, 'payment_requests') ? 'active' : '' ?> <?= $isSupportAgentMaint ? 'sidebar-link-maint-locked' : '' ?>">
                        <i class="fas fa-file-invoice"></i> Заявки на оплату <?= $isSupportAgentMaint ? '<i class="fas fa-lock" style="font-size:10px; margin-left:auto;"></i>' : '' ?>
                    </a>
                </li>
                <li>
                    <a href="/admin/subscriptions.php" class="sidebar-link <?= str_contains($currentUri, 'subscriptions.php') ? 'active' : '' ?> <?= $isSupportAgentMaint ? 'sidebar-link-maint-locked' : '' ?>">
                        <i class="fas fa-id-card-alt"></i> Подписки <?= $isSupportAgentMaint ? '<i class="fas fa-lock" style="font-size:10px; margin-left:auto;"></i>' : '' ?>
                    </a>
                </li>
                <li>
                    <a href="/admin/subscription_keys.php" class="sidebar-link <?= str_contains($currentUri, 'subscription_keys') ? 'active' : '' ?> <?= $isSupportAgentMaint ? 'sidebar-link-maint-locked' : '' ?>">
                        <i class="fas fa-key"></i> Ключи подписок <?= $isSupportAgentMaint ? '<i class="fas fa-lock" style="font-size:10px; margin-left:auto;"></i>' : '' ?>
                    </a>
                </li>

                <li style="margin-top:10px; margin-bottom:4px; padding-left:14px; font-size:11px; text-transform:uppercase; color:var(--text-muted); letter-spacing:1px; font-weight:700;">
                    Система
                </li>
                <li>
                    <a href="/admin/ai_settings.php" class="sidebar-link <?= str_contains($currentUri, 'ai_settings') ? 'active' : '' ?> <?= $isSupportAgentMaint ? 'sidebar-link-maint-locked' : '' ?>">
                        <i class="fas fa-brain"></i> Настройки AI & SMTP <?= $isSupportAgentMaint ? '<i class="fas fa-lock" style="font-size:10px; margin-left:auto;"></i>' : '' ?>
                    </a>
                </li>
                <li>
                    <a href="/admin/test_smtp.php" class="sidebar-link <?= str_contains($currentUri, 'test_smtp') ? 'active' : '' ?> <?= $isSupportAgentMaint ? 'sidebar-link-maint-locked' : '' ?>">
                        <i class="fas fa-paper-plane"></i> SMTP Диагностика <?= $isSupportAgentMaint ? '<i class="fas fa-lock" style="font-size:10px; margin-left:auto;"></i>' : '' ?>
                    </a>
                </li>
                <li>
                    <a href="/admin/logs.php" class="sidebar-link <?= str_contains($currentUri, 'logs') ? 'active' : '' ?> <?= $isSupportAgentMaint ? 'sidebar-link-maint-locked' : '' ?>">
                        <i class="fas fa-list-alt"></i> Журнал аудита <?= $isSupportAgentMaint ? '<i class="fas fa-lock" style="font-size:10px; margin-left:auto;"></i>' : '' ?>
                    </a>
                </li>
                <li>
                    <a href="/news.php" class="sidebar-link <?= str_contains($currentUri, 'news.php') ? 'active' : '' ?>">
                        <i class="fas fa-newspaper"></i> Новости & Релизы
                        <?php if ($userUnreadNews > 0): ?>
                            <span style="background:#ef4444;color:#fff;border-radius:20px;font-size:11px;font-weight:700;padding:1px 7px;margin-left:auto;"><?= $userUnreadNews ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li>
                    <a href="/user/dashboard.php" class="sidebar-link <?= $isSupportAgentMaint ? 'sidebar-link-maint-locked' : '' ?>" style="color:var(--text-primary); font-weight:600;">
                        <i class="fas fa-arrow-right"></i> В личный кабинет <?= $isSupportAgentMaint ? '<i class="fas fa-lock" style="font-size:10px; margin-left:auto;"></i>' : '' ?>
                    </a>
                </li>

            <?php else: ?>
                <!-- Пользовательское рабочее пространство (User Workspace) -->

                <?php if ($isSupportAgentMaint): ?>
                    <!-- Уведомление для агента поддержки во время тех. работ -->
                    <li style="margin:6px 10px 12px 10px; padding:10px 12px; background:rgba(239,68,68,0.12); border:1px solid rgba(239,68,68,0.35); border-radius:10px;">
                        <div style="font-size:11px; font-weight:800; color:#ef4444; display:flex; align-items:center; gap:6px; text-transform:uppercase;">
                            <i class="fas fa-lock"></i> Доступ ограничен
                        </div>
                        <div style="font-size:10px; color:#cbd5e1; margin-top:3px; line-height:1.3;">
                            Режим тех. работ. Вам открыты <strong>только тикеты поддержки</strong>.
                        </div>
                    </li>
                <?php elseif ($isUserMaint): ?>
                    <!-- Уведомление для обычного клиента во время тех. работ -->
                    <li style="margin:6px 10px 12px 10px; padding:10px 12px; background:rgba(245,158,11,0.12); border:1px solid rgba(245,158,11,0.35); border-radius:10px;">
                        <div style="font-size:11px; font-weight:800; color:#fbbf24; display:flex; align-items:center; gap:6px; text-transform:uppercase;">
                            <i class="fas fa-tools"></i> Идут тех. работы
                        </div>
                        <div style="font-size:10px; color:#cbd5e1; margin-top:3px; line-height:1.3;">
                            Доступны только открытые руководством разделы.
                        </div>
                    </li>
                <?php endif; ?>

                <li>
                    <a href="/user/dashboard.php" class="sidebar-link <?= (str_contains($currentUri, 'dashboard') && !str_starts_with($currentUri, '/admin')) ? 'active' : '' ?>">
                        <i class="fas fa-th-large"></i> Дашборд
                    </a>
                </li>
                <li>
                    <a href="/chat.php" class="sidebar-link <?= (str_contains($currentUri, 'chat.php') && !str_contains($currentUri, 'support')) ? 'active' : '' ?> <?= (!$isSecAllowed('chat')) ? 'sidebar-link-maint-locked' : '' ?>">
                        <i class="fas fa-comments"></i> Корпоративные чаты <?= (!$isSecAllowed('chat')) ? '<i class="fas fa-lock" style="font-size:10px; margin-left:auto;"></i>' : '' ?>
                    </a>
                </li>
                <li>
                    <a href="/direct_messages.php" class="sidebar-link <?= str_contains($currentUri, 'direct_messages.php') ? 'active' : '' ?> <?= (!$isSecAllowed('direct')) ? 'sidebar-link-maint-locked' : '' ?>">
                        <i class="fas fa-user-friends"></i> Личные сообщения & Звонки <?= (!$isSecAllowed('direct')) ? '<i class="fas fa-lock" style="font-size:10px; margin-left:auto;"></i>' : '' ?>
                    </a>
                </li>
                <li>
                    <a href="/webmail.php" class="sidebar-link <?= str_contains($currentUri, 'webmail.php') ? 'active' : '' ?> <?= (!$isSecAllowed('chat')) ? 'sidebar-link-maint-locked' : '' ?>">
                        <i class="fas fa-envelope-open-text"></i> Почта (Webmail) <?= (!$isSecAllowed('chat')) ? '<i class="fas fa-lock" style="font-size:10px; margin-left:auto;"></i>' : '' ?>
                    </a>
                </li>
                <li>
                    <a href="/user/ai_assistant.php" class="sidebar-link <?= str_contains($currentUri, 'ai_assistant') ? 'active' : '' ?> <?= (!$isSecAllowed('ai')) ? 'sidebar-link-maint-locked' : '' ?>">
                        <i class="fas fa-robot"></i> AI Ассистент <?= (!$isSecAllowed('ai')) ? '<i class="fas fa-lock" style="font-size:10px; margin-left:auto;"></i>' : '' ?>
                    </a>
                </li>

                <!-- Блок Организации и Управление бизнесом -->
                <?php if ($userHasOrg): ?>
                    <li style="margin-top:14px; margin-bottom:4px; padding-left:14px; font-size:10px; text-transform:uppercase; color:var(--text-muted); letter-spacing:1px; font-weight:700;">
                        Компания: <span style="color:#38bdf8;"><?= htmlspecialchars(mb_substr($userOrg['name'] ?? 'Бизнес', 0, 16)) ?></span>
                    </li>
                    <li>
                        <a href="/user/organizations.php" class="sidebar-link <?= str_contains($currentUri, 'organizations') ? 'active' : '' ?> <?= (!$isSecAllowed('org')) ? 'sidebar-link-maint-locked' : '' ?>">
                            <i class="fas fa-building"></i> Организация & Сотрудники <?= (!$isSecAllowed('org')) ? '<i class="fas fa-lock" style="font-size:10px; margin-left:auto;"></i>' : '' ?>
                        </a>
                    </li>
                    <li>
                        <a href="/user/clients.php" class="sidebar-link <?= str_contains($currentUri, 'clients') ? 'active' : '' ?> <?= (!$isSecAllowed('clients')) ? 'sidebar-link-maint-locked' : '' ?>">
                            <i class="fas fa-user-friends"></i> CRM &amp; Клиенты <?= (!$isSecAllowed('clients')) ? '<i class="fas fa-lock" style="font-size:10px; margin-left:auto;"></i>' : '' ?>
                        </a>
                    </li>
                    <li>
                        <a href="/user/tasks.php" class="sidebar-link <?= str_contains($currentUri, 'tasks') ? 'active' : '' ?> <?= (!$isSecAllowed('tasks')) ? 'sidebar-link-maint-locked' : '' ?>">
                            <i class="fas fa-check-square"></i> Задачи <?= (!$isSecAllowed('tasks')) ? '<i class="fas fa-lock" style="font-size:10px; margin-left:auto;"></i>' : '' ?>
                        </a>
                    </li>
                    <li>
                        <a href="/user/documents.php" class="sidebar-link <?= str_contains($currentUri, 'documents') ? 'active' : '' ?> <?= (!$isSecAllowed('documents')) ? 'sidebar-link-maint-locked' : '' ?>">
                            <i class="fas fa-file-contract"></i> Документы <?= (!$isSecAllowed('documents')) ? '<i class="fas fa-lock" style="font-size:10px; margin-left:auto;"></i>' : '' ?>
                        </a>
                    </li>
                    <li>
                        <a href="/user/analytics.php" class="sidebar-link <?= str_contains($currentUri, 'analytics') ? 'active' : '' ?> <?= (!$isSecAllowed('analytics')) ? 'sidebar-link-maint-locked' : '' ?>">
                            <i class="fas fa-chart-line"></i> Аналитика <?= (!$isSecAllowed('analytics')) ? '<i class="fas fa-lock" style="font-size:10px; margin-left:auto;"></i>' : '' ?>
                        </a>
                    </li>
                    <li>
                        <a href="/user/org_chat.php" class="sidebar-link <?= str_contains($currentUri, 'org_chat') ? 'active' : '' ?> <?= (!$isSecAllowed('org')) ? 'sidebar-link-maint-locked' : '' ?>">
                            <i class="fas fa-comments"></i> Чат руководства компании <?= (!$isSecAllowed('org')) ? '<i class="fas fa-lock" style="font-size:10px; margin-left:auto;"></i>' : '' ?>
                        </a>
                    </li>
                    <li>
                        <a href="/user/positions.php" class="sidebar-link <?= str_contains($currentUri, 'positions') ? 'active' : '' ?> <?= (!$isSecAllowed('org')) ? 'sidebar-link-maint-locked' : '' ?>">
                            <i class="fas fa-user-tag"></i> Должности компании <?= (!$isSecAllowed('org')) ? '<i class="fas fa-lock" style="font-size:10px; margin-left:auto;"></i>' : '' ?>
                        </a>
                    </li>
                    <li>
                        <a href="/user/menu_items.php" class="sidebar-link <?= str_contains($currentUri, 'menu_items') ? 'active' : '' ?> <?= (!$isSecAllowed('pos')) ? 'sidebar-link-maint-locked' : '' ?>">
                            <i class="fas fa-utensils"></i> Меню & Товары <?= (!$isSecAllowed('pos')) ? '<i class="fas fa-lock" style="font-size:10px; margin-left:auto;"></i>' : '' ?>
                        </a>
                    </li>
                    <li>
                        <a href="/user/cashier.php" class="sidebar-link <?= str_contains($currentUri, 'cashier') ? 'active' : '' ?> <?= (!$isSecAllowed('pos')) ? 'sidebar-link-maint-locked' : '' ?>">
                            <i class="fas fa-cash-register"></i> Онлайн-Касса (POS) <?= (!$isSecAllowed('pos')) ? '<i class="fas fa-lock" style="font-size:10px; margin-left:auto;"></i>' : '' ?>
                        </a>
                    </li>
                <?php else: ?>
                    <li style="margin-top:14px; margin-bottom:4px; padding-left:14px; font-size:11px; text-transform:uppercase; color:var(--text-muted); letter-spacing:1px; font-weight:700;">
                        Организация
                    </li>
                    <li>
                        <a href="/user/request_organization.php" class="sidebar-link <?= str_contains($currentUri, 'request_organization') ? 'active' : '' ?> <?= (!$isSecAllowed('org')) ? 'sidebar-link-maint-locked' : '' ?>">
                            <i class="fas fa-building-circle-arrow-right"></i> Зарегистрировать организацию <?= (!$isSecAllowed('org')) ? '<i class="fas fa-lock" style="font-size:10px; margin-left:auto;"></i>' : '' ?>
                            <?php if ($userPendingOrgReqs > 0): ?>
                                <span style="background:#f59e0b;color:#000;border-radius:20px;font-size:11px;font-weight:700;padding:1px 7px;margin-left:auto;">В обработке</span>
                            <?php endif; ?>
                        </a>
                    </li>
                <?php endif; ?>

                <li style="margin-top:14px; margin-bottom:4px; padding-left:14px; font-size:11px; text-transform:uppercase; color:var(--text-muted); letter-spacing:1px; font-weight:700;">
                    Техподдержка
                </li>
                <li>
                    <?php if ($isSupportAgentMaint): ?>
                        <a href="/support/dashboard.php" class="sidebar-link sidebar-link-support-active">
                            <i class="fas fa-headset"></i> <span>Тикеты поддержки</span>
                            <span class="badge" style="background:#3b82f6; color:#fff; font-size:10px; margin-left:auto;">ДОСТУПНО</span>
                        </a>
                    <?php else: ?>
                        <a href="/user/support.php" class="sidebar-link <?= str_contains($currentUri, 'support') ? 'active' : '' ?> <?= (!$isSecAllowed('support')) ? 'sidebar-link-maint-locked' : '' ?>">
                            <i class="fas fa-headset"></i> Чат поддержки <?= (!$isSecAllowed('support')) ? '<i class="fas fa-lock" style="font-size:10px; margin-left:auto;"></i>' : '' ?>
                            <?php if ($userActiveTickets > 0): ?>
                                <span style="background:#6366f1;color:#fff;border-radius:20px;font-size:11px;font-weight:700;padding:1px 7px;margin-left:auto;"><?= $userActiveTickets ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endif; ?>
                </li>

                <li style="margin-top:14px; margin-bottom:4px; padding-left:14px; font-size:11px; text-transform:uppercase; color:var(--text-muted); letter-spacing:1px; font-weight:700;">
                    Тарифы
                </li>
                <li>
                    <a href="/user/subscription.php" class="sidebar-link <?= str_contains($currentUri, 'subscription') ? 'active' : '' ?> <?= (!$isSecAllowed('tariffs')) ? 'sidebar-link-maint-locked' : '' ?>">
                        <i class="fas fa-gem"></i> Подписка & Тарифы <?= (!$isSecAllowed('tariffs')) ? '<i class="fas fa-lock" style="font-size:10px; margin-left:auto;"></i>' : '' ?>
                    </a>
                </li>
                <li>
                    <a href="/user/activate_key.php" class="sidebar-link <?= str_contains($currentUri, 'activate_key') ? 'active' : '' ?> <?= (!$isSecAllowed('tariffs')) ? 'sidebar-link-maint-locked' : '' ?>">
                        <i class="fas fa-key"></i> Активировать ключ <?= (!$isSecAllowed('tariffs')) ? '<i class="fas fa-lock" style="font-size:10px; margin-left:auto;"></i>' : '' ?>
                    </a>
                </li>
                <li>
                    <a href="/contacts.php" class="sidebar-link <?= str_contains($currentUri, 'contacts.php') ? 'active' : '' ?>">
                        <i class="fas fa-address-book"></i> Контакты руководства
                    </a>
                </li>
                <li>
                    <a href="/news.php" class="sidebar-link <?= str_contains($currentUri, 'news.php') ? 'active' : '' ?>">
                        <i class="fas fa-newspaper"></i> Новости платформы
                        <?php if ($userUnreadNews > 0): ?>
                            <span style="background:#ef4444;color:#fff;border-radius:20px;font-size:11px;font-weight:700;padding:1px 7px;margin-left:auto;"><?= $userUnreadNews ?></span>
                        <?php endif; ?>
                    </a>
                </li>

                <!-- Служебные переходы для сотрудников платформы -->
                <?php if ($isStaff): ?>
                    <li style="margin-top:14px; margin-bottom:4px; padding-left:14px; font-size:11px; text-transform:uppercase; color:#f59e0b; letter-spacing:1px; font-weight:800;">
                        Панели персонала
                    </li>
                    <?php if ($isOwner): ?>
                        <li>
                            <a href="/owner/dashboard.php" class="sidebar-link" style="color:#d97706;">
                                <i class="fas fa-crown"></i> ВРИО Владелец
                            </a>
                        </li>
                    <?php endif; ?>

                    <?php if ($isDev): ?>
                        <li>
                            <a href="/developer/dashboard.php" class="sidebar-link" style="color:#06b6d4;">
                                <i class="fas fa-code"></i> Консоль Разработчика
                            </a>
                        </li>
                    <?php endif; ?>
                    <?php if ($isTest): ?>
                        <li>
                            <a href="/tester/dashboard.php" class="sidebar-link" style="color:#10b981;">
                                <i class="fas fa-vial"></i> Консоль Тестировщика
                            </a>
                        </li>
                    <?php endif; ?>
                    <?php if ($isSupp): ?>
                        <li>
                            <a href="/support/dashboard.php" class="sidebar-link" style="color:#3b82f6;">
                                <i class="fas fa-headset"></i> Консоль Поддержки
                            </a>
                        </li>
                    <?php endif; ?>
                    <?php if ($isAdmin): ?>
                        <li>
                            <a href="/admin/dashboard.php" class="sidebar-link" style="color:#6366f1;">
                                <i class="fas fa-shield-alt"></i> Панель Администратора
                            </a>
                        </li>
                    <?php endif; ?>
                <?php endif; ?>

            <?php endif; ?>
        </ul>

        <div class="sidebar-footer">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <small class="text-muted" style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:180px;">
                    <?= htmlspecialchars($user['email'] ?? '') ?>
                </small>
            </div>
            <a href="/logout.php" class="btn btn-secondary btn-sm btn-block">
                <i class="fas fa-sign-out-alt"></i> Выйти
            </a>
        </div>
    </aside>

    <!-- Основной рабочий контент -->
    <main class="main-content">
        <header class="topbar">
            <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                <button class="mobile-nav-toggle" id="mobile-toggle">
                    <i class="fas fa-bars"></i>
                </button>

                <!-- Кнопки навигации Назад / Вперед для приложения и сайта -->
                <div style="display:inline-flex; gap:6px; align-items:center;">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="if(window.history.length > 1) { window.history.back(); } else { window.location.href='/'; }" title="Вернуться назад на предыдущую страницу" style="padding:5px 12px; font-size:12px; display:inline-flex; align-items:center; gap:6px; border-radius:8px; font-weight:600;">
                        <i class="fas fa-arrow-left"></i> <span>Назад</span>
                    </button>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="window.history.forward()" title="Вперёд" style="padding:5px 10px; font-size:12px; display:inline-flex; align-items:center; border-radius:8px;">
                        <i class="fas fa-arrow-right"></i>
                    </button>
                </div>

                <h4 style="margin:0; font-size:16px; font-weight:600;"><?= htmlspecialchars($pageTitle ?? 'AI Business Assistant') ?></h4>
            </div>

            <div style="display:flex; align-items:center; gap:12px;">
                <!-- Бейдж текущей роли -->
                <span class="badge" style="background:<?= $currentRoleBadge['bg'] ?>; color:<?= $currentRoleBadge['color'] ?>; font-size:11px; padding:4px 8px; font-weight:700;">
                    <i class="fas <?= $currentRoleBadge['icon'] ?>"></i> <?= $currentRoleBadge['title'] ?>
                </span>

                <!-- Быстрый доступ к корпоративным чатам -->
                <a href="/chat.php" class="btn btn-secondary btn-sm" title="Корпоративные чаты">
                    <i class="fas fa-comments"></i> Чаты
                </a>

                <!-- Контакты Генерального директора -->
                <a href="/contacts.php" class="btn btn-secondary btn-sm" title="Контакты руководства">
                    <i class="fas fa-envelope"></i> Контакты
                </a>

                <!-- Новости платформы с бейджем непрочитанных -->
                <a href="/news.php" class="btn btn-secondary btn-sm" title="Новости платформы" style="position:relative; display:inline-flex; align-items:center; gap:6px;">
                    <i class="fas fa-newspaper"></i> <span>Новости</span>
                    <?php if ($userUnreadNews > 0): ?>
                        <span style="position:absolute; top:-5px; right:-5px; background:#ef4444; color:#fff; font-size:10px; font-weight:800; border-radius:10px; padding:1px 5px; box-shadow:0 0 8px rgba(239,68,68,0.8); line-height:1.2;">
                            <?= $userUnreadNews ?>
                        </span>
                    <?php endif; ?>
                </a>



                <a href="/user/profile.php" class="btn btn-secondary btn-sm">
                    <i class="fas fa-user"></i> <?= htmlspecialchars($user['first_name'] ?? 'Профиль') ?>
                </a>
            </div>
        </header>
        <?php if (!empty($latestUnreadNews) && !str_contains($currentUri, 'news.php')): ?>
        <!-- Интерактивное всплывающее уведомление о свежей новости -->
        <div id="news-notification-toast" style="position:fixed; bottom:24px; left:24px; z-index:9990; max-width:360px; background:linear-gradient(135deg, rgba(30,27,75,0.96), rgba(15,23,42,0.96)); backdrop-filter:blur(10px); border:1px solid rgba(99,102,241,0.5); border-radius:14px; padding:14px 18px; box-shadow:0 15px 35px rgba(0,0,0,0.5), 0 0 20px rgba(99,102,241,0.25); animation:slideInLeft 0.4s ease; display:flex; gap:12px; align-items:flex-start;">
            <div style="width:36px; height:36px; border-radius:10px; background:linear-gradient(135deg, #6366f1, #4f46e5); display:flex; align-items:center; justify-content:center; color:#fff; font-size:16px; flex-shrink:0;">
                <i class="fas fa-bell"></i>
            </div>
            <div style="flex:1; min-width:0;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                    <span style="font-size:11px; text-transform:uppercase; font-weight:800; color:#818cf8; letter-spacing:0.5px;">Новость платформы</span>
                    <button type="button" onclick="document.getElementById('news-notification-toast').remove()" style="background:none; border:none; color:#94a3b8; cursor:pointer; font-size:14px; padding:0 2px;">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div style="font-size:13px; font-weight:700; color:#fff; margin-bottom:4px; line-height:1.3; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                    <?= htmlspecialchars($latestUnreadNews['title']) ?>
                </div>
                <div style="font-size:12px; color:#cbd5e1; line-height:1.4; margin-bottom:8px; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;">
                    <?= htmlspecialchars($latestUnreadNews['body'] ?? '') ?>
                </div>
                <a href="/news.php" style="display:inline-flex; align-items:center; gap:6px; font-size:12px; font-weight:700; color:#38bdf8; text-decoration:none;">
                    <span>Читать подробнее</span> <i class="fas fa-arrow-right" style="font-size:10px;"></i>
                </a>
            </div>
        </div>
        <?php endif; ?>
        <div class="content-body">
<?php if (Auth::isMaintenanceMode()): ?>
    <?php
        $isRoleAllowed = Auth::isUserRoleAllowedInMaintenance($user);
        $roleConfigList = Auth::getMaintenanceRoleConfig();
        $roleCfg = $roleConfigList[$userRole] ?? ['name' => $userRole, 'icon' => 'fa-user', 'color' => '#94a3b8'];
        $roleDisplayName = $roleCfg['name'] ?? $userRole;
        $isSupreme = Auth::isSupremeOwner($user);
        $forceShowModal = isset($_GET['maint_blocked']) || (!$isRoleAllowed && !$isSupportAgentMaint);
    ?>

    <!-- Анимированная табличка / модальное окно тех. работ -->
    <div id="maintAnimatedModal" style="display:none; position:fixed; inset:0; z-index:999999; background:rgba(3, 7, 18, 0.84); backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px); animation: maintBackdropFadeIn 0.3s ease; padding:16px; overflow-y:auto;">
        <div style="min-height:100%; display:flex; align-items:center; justify-content:center;">
            <div class="maint-modal-card" style="position:relative; width:100%; max-width:540px; background:linear-gradient(165deg, rgba(17, 24, 39, 0.98), rgba(10, 15, 29, 0.99)); border:1.5px solid rgba(245, 158, 11, 0.5); border-radius:20px; overflow:hidden; animation: maintModalDrop 0.4s cubic-bezier(0.16, 1, 0.3, 1), maintGlowBorder 4s infinite ease-in-out;">
                
                <!-- Анимированная бегущая строка / прогресс-полоска -->
                <div style="height:4px; width:100%; background:linear-gradient(90deg, #f59e0b, #ec4899, #38bdf8, #f59e0b); background-size:250% 100%; animation: maintShimmerBar 2.5s linear infinite;"></div>

                <!-- Кнопка закрытия (если доступ разрешен или верховный) -->
                <?php if ($isRoleAllowed || $isSupreme || $isSupportAgentMaint): ?>
                    <button type="button" onclick="closeMaintModal()" style="position:absolute; top:14px; right:14px; width:34px; height:34px; border-radius:10px; background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.1); color:#94a3b8; display:flex; align-items:center; justify-content:center; cursor:pointer; font-size:16px; transition:all 0.2s;" onmouseover="this.style.color='#fff'; this.style.background='rgba(255,255,255,0.15)';" onmouseout="this.style.color='#94a3b8'; this.style.background='rgba(255,255,255,0.06)';">
                        <i class="fas fa-times"></i>
                    </button>
                <?php endif; ?>

                <div style="padding:28px 24px 22px; text-align:center;">
                    
                    <!-- Анимированный блок с шестеренками -->
                    <div style="position:relative; width:92px; height:92px; margin:0 auto 16px;">
                        <!-- Пульсирующее гало -->
                        <div style="position:absolute; inset:0; border-radius:50%; background:rgba(245, 158, 11, 0.2); animation: maintPulseRing 2.2s ease-out infinite;"></div>
                        <!-- Фоновый круг -->
                        <div style="position:absolute; inset:6px; border-radius:50%; background:radial-gradient(circle, rgba(245, 158, 11, 0.25) 0%, rgba(245, 158, 11, 0.05) 70%, transparent 100%); border:1px dashed rgba(245, 158, 11, 0.4); display:flex; align-items:center; justify-content:center;">
                            <!-- Главная шестерня -->
                            <i class="fas fa-gear" style="font-size:38px; color:#f59e0b; filter:drop-shadow(0 0 12px rgba(245, 158, 11, 0.8)); animation: maintGearSpin 9s linear infinite;"></i>
                        </div>
                        <!-- Вспомогательная шестеренка спутник -->
                        <div style="position:absolute; bottom:6px; right:6px; width:30px; height:30px; border-radius:50%; background:#0f172a; border:1px solid rgba(56, 189, 248, 0.5); display:flex; align-items:center; justify-content:center;">
                            <i class="fas fa-cog" style="font-size:15px; color:#38bdf8; filter:drop-shadow(0 0 8px rgba(56, 189, 248, 0.8)); animation: maintGearRevSpin 5s linear infinite;"></i>
                        </div>
                    </div>

                    <!-- Бейдж статуса тех. работ -->
                    <div style="display:inline-flex; align-items:center; gap:8px; padding:4px 14px; border-radius:20px; background:rgba(245, 158, 11, 0.15); border:1px solid rgba(245, 158, 11, 0.4); margin-bottom:12px;">
                        <span style="width:8px; height:8px; border-radius:50%; background:#f59e0b; box-shadow:0 0 10px #f59e0b; display:inline-block; animation: blinkLive 1.2s infinite;"></span>
                        <span style="font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:0.8px; color:#fbbf24;">
                            ТЕХНИЧЕСКИЕ РАБОТЫ
                        </span>
                    </div>

                    <h2 style="font-size:20px; font-weight:800; color:#f8fafc; margin:0 0 8px; letter-spacing:-0.3px;">
                        Ведутся технические работы
                    </h2>
                    
                    <p style="font-size:13px; color:#94a3b8; line-height:1.55; margin:0 0 18px;">
                        Инженерная команда выполняет плановую модернизацию серверной инфраструктуры, оптимизацию базы данных и обновление модулей безопасности.
                    </p>

                    <!-- Блок статуса роли пользователя -->
                    <div style="background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.08); border-radius:14px; padding:14px 16px; margin-bottom:18px; text-align:left;">
                        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; flex-wrap:wrap; gap:8px;">
                            <div style="display:flex; align-items:center; gap:8px;">
                                <div style="width:28px; height:28px; border-radius:8px; background:<?= $roleCfg['color'] ?>22; display:flex; align-items:center; justify-content:center; font-size:13px; color:<?= $roleCfg['color'] ?>;">
                                    <i class="fas <?= $roleCfg['icon'] ?>"></i>
                                </div>
                                <div>
                                    <div style="font-size:10px; text-transform:uppercase; letter-spacing:0.5px; color:#64748b; font-weight:700;">Ваш статус входа</div>
                                    <div style="font-size:13px; font-weight:700; color:#f1f5f9;"><?= htmlspecialchars($roleDisplayName) ?></div>
                                </div>
                            </div>

                            <?php if ($isSupreme): ?>
                                <span class="badge" style="background:rgba(217,119,6,0.2); border:1px solid rgba(217,119,6,0.5); color:#fbbf24; font-size:11px; font-weight:700; padding:4px 9px;">
                                    <i class="fas fa-crown"></i> Верховный Владелец
                                </span>
                            <?php elseif ($isRoleAllowed): ?>
                                <span class="badge" style="background:rgba(16,185,129,0.2); border:1px solid rgba(16,185,129,0.5); color:#34d399; font-size:11px; font-weight:700; padding:4px 9px;">
                                    <i class="fas fa-check-circle"></i> Доступ разрешен
                                </span>
                            <?php elseif ($isSupportAgentMaint): ?>
                                <span class="badge" style="background:rgba(59,130,246,0.2); border:1px solid rgba(59,130,246,0.5); color:#60a5fa; font-size:11px; font-weight:700; padding:4px 9px;">
                                    <i class="fas fa-headset"></i> Только тикеты
                                </span>
                            <?php else: ?>
                                <span class="badge" style="background:rgba(239,68,68,0.2); border:1px solid rgba(239,68,68,0.5); color:#f87171; font-size:11px; font-weight:700; padding:4px 9px;">
                                    <i class="fas fa-lock"></i> Доступ ограничен
                                </span>
                            <?php endif; ?>
                        </div>

                        <!-- Пояснение для пользователя -->
                        <div style="font-size:12px; color:#cbd5e1; line-height:1.45;">
                            <?php if ($isSupreme): ?>
                                👑 <strong>Матвей, вам предоставлен абсолютный доступ.</strong> Вся платформа полностью открыта. Управлять доступом можно в <a href="/owner/dashboard.php" style="color:#fbbf24; text-decoration:underline;">Панели ТО</a>.
                            <?php elseif ($isRoleAllowed): ?>
                                🟢 Руководством платформы вам предоставлен рабочий доступ. Вы можете свободно пользоваться разрешенными функциями системы.
                            <?php elseif ($isSupportAgentMaint): ?>
                                🎧 Режим службы поддержки: Вам открыты тикеты и связь с пользователями. Остальные разделы заблокированы до окончания тех. работ.
                            <?php else: ?>
                                🔒 На время технического обслуживания разделы сайта временно закрыты администратором. Вы можете ожидать завершения работ или обратиться в поддержку.
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Индикаторы системного статуса -->
                    <div style="display:grid; grid-template-columns:repeat(3, 1fr); gap:8px; margin-bottom:20px; font-size:11px;">
                        <div style="padding:8px; border-radius:10px; background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.06); text-align:center;">
                            <div style="color:#94a3b8; font-size:10px; margin-bottom:2px;">База данных</div>
                            <div style="color:#38bdf8; font-weight:700; display:flex; align-items:center; justify-content:center; gap:4px;">
                                <i class="fas fa-sync-alt fa-spin" style="font-size:9px;"></i> В норме
                            </div>
                        </div>
                        <div style="padding:8px; border-radius:10px; background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.06); text-align:center;">
                            <div style="color:#94a3b8; font-size:10px; margin-bottom:2px;">Шлюз ИИ</div>
                            <div style="color:#fbbf24; font-weight:700; display:flex; align-items:center; justify-content:center; gap:4px;">
                                <i class="fas fa-pause-circle" style="font-size:9px;"></i> Ожидание
                            </div>
                        </div>
                        <div style="padding:8px; border-radius:10px; background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.06); text-align:center;">
                            <div style="color:#94a3b8; font-size:10px; margin-bottom:2px;">Безопасность</div>
                            <div style="color:#34d399; font-weight:700; display:flex; align-items:center; justify-content:center; gap:4px;">
                                <i class="fas fa-shield-alt" style="font-size:9px;"></i> Активна
                            </div>
                        </div>
                    </div>

                    <!-- Кнопки действий -->
                    <div style="display:flex; flex-direction:column; gap:8px;">
                        <?php if ($isSupreme): ?>
                            <div style="display:flex; gap:8px;">
                                <a href="/owner/dashboard.php" class="btn btn-warning" style="flex:1; font-weight:800; padding:11px 16px; font-size:13px; text-decoration:none; display:flex; align-items:center; justify-content:center; gap:8px;">
                                    <i class="fas fa-sliders-h"></i> Панель ТО
                                </a>
                                <button type="button" onclick="closeMaintModal()" class="btn btn-secondary" style="flex:1; font-weight:700; padding:11px 16px; font-size:13px;">
                                    <i class="fas fa-arrow-right"></i> Продолжить
                                </button>
                            </div>

                        <?php elseif ($isRoleAllowed): ?>
                            <button type="button" onclick="closeMaintModal()" class="btn btn-primary" style="width:100%; font-weight:800; padding:12px; font-size:14px; background:linear-gradient(135deg, #10b981, #059669); border:none; box-shadow:0 4px 15px rgba(16,185,129,0.35); cursor:pointer;">
                                <i class="fas fa-check"></i> Понятно, перейти к работе
                            </button>

                        <?php elseif ($isSupportAgentMaint): ?>
                            <div style="display:flex; gap:8px;">
                                <a href="/support/dashboard.php" class="btn btn-primary" style="flex:2; font-weight:800; padding:11px; font-size:13px; text-decoration:none; display:flex; align-items:center; justify-content:center; gap:8px;">
                                    <i class="fas fa-headset"></i> Открыть тикеты
                                </a>
                                <button type="button" onclick="closeMaintModal()" class="btn btn-secondary" style="flex:1; font-weight:700; padding:11px; font-size:13px;">
                                    Свернуть
                                </button>
                            </div>

                        <?php else: ?>
                            <div style="display:flex; gap:8px;">
                                <button type="button" onclick="location.reload()" class="btn btn-primary" style="flex:2; font-weight:800; padding:11px; font-size:13px; background:linear-gradient(135deg,#f59e0b,#d97706); border:none; color:#000; cursor:pointer;">
                                    <i class="fas fa-sync-alt"></i> Проверить статус
                                </button>
                                <a href="/support/dashboard.php" class="btn btn-secondary" style="flex:1; font-weight:700; padding:11px; font-size:13px; text-decoration:none; display:flex; align-items:center; justify-content:center; gap:6px;">
                                    <i class="fas fa-headset"></i> Помощь
                                </a>
                            </div>
                            <div style="display:flex; gap:8px; margin-top:4px;">
                                <button type="button" onclick="closeMaintModal()" class="btn btn-secondary btn-sm" style="flex:1; font-size:11px; color:#94a3b8; cursor:pointer;">
                                    Свернуть уведомление
                                </button>
                                <a href="/logout.php" class="btn btn-secondary btn-sm" style="flex:1; font-size:11px; color:#ef4444; text-decoration:none; display:flex; align-items:center; justify-content:center; gap:6px;">
                                    <i class="fas fa-sign-out-alt"></i> Выйти из аккаунта
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- JS контроллер модального окна ТО -->
    <script>
    function openMaintenanceModal() {
        var m = document.getElementById('maintAnimatedModal');
        if (m) {
            m.style.display = 'block';
            document.body.style.overflow = 'hidden';
        }
    }
    function closeMaintModal() {
        var m = document.getElementById('maintAnimatedModal');
        if (m) {
            m.style.display = 'none';
            document.body.style.overflow = '';
            try { sessionStorage.setItem('maint_modal_dismissed', '1'); } catch(e) {}
        }
    }
    document.addEventListener('DOMContentLoaded', function() {
        var forceShow = <?= $forceShowModal ? 'true' : 'false' ?>;
        var alreadyDismissed = false;
        try {
            alreadyDismissed = (sessionStorage.getItem('maint_modal_dismissed') === '1');
        } catch(e) {}

        // Если принудительный показ или окно еще не показывалось в этой сессии:
        if (forceShow || !alreadyDismissed) {
            openMaintenanceModal();
        }
    });
    </script>

    <?php if ($isSupportAgentMaint): ?>
        <!-- Баннер режима ТО для Агента Поддержки -->
        <div onclick="openMaintenanceModal()" style="cursor:pointer; background:linear-gradient(135deg, rgba(239,68,68,0.2), rgba(220,38,38,0.08)); border:1.5px solid rgba(239,68,68,0.6); border-radius:14px; padding:14px 20px; margin-bottom:20px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; box-shadow:0 0 30px rgba(239,68,68,0.25);">
            <div style="display:flex; align-items:center; gap:12px;">
                <div style="width:38px; height:38px; border-radius:10px; background:rgba(239,68,68,0.25); display:flex; align-items:center; justify-content:center; color:#ef4444; font-size:18px;">
                    <i class="fas fa-lock"></i>
                </div>
                <div>
                    <div style="color:#ef4444; font-size:14px; font-weight:800; text-transform:uppercase; letter-spacing:0.5px; display:flex; align-items:center; gap:8px;">
                        <span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:#ef4444; box-shadow:0 0 10px #ef4444;"></span>
                        РЕЖИМ ТЕХ. РАБОТ: ДОСТУП ОГРАНИЧЕН
                    </div>
                    <div style="font-size:12px; color:#cbd5e1; margin-top:2px;">
                        Вам открыт доступ <strong>ИСКЛЮЧИТЕЛЬНО к разделу тикетов и поддержке</strong>. Все остальные модули сайта отключены. Нажмите для подробностей.
                    </div>
                </div>
            </div>
            <a href="/support/dashboard.php" class="btn btn-danger btn-sm" style="font-weight:800; padding:6px 14px; font-size:12px;" onclick="event.stopPropagation();">
                <i class="fas fa-headset"></i> Открыть тикеты поддержки
            </a>
        </div>

    <?php elseif ($isUserMaint): ?>
        <!-- Баннер режима ТО для обычных клиентов -->
        <div onclick="openMaintenanceModal()" style="cursor:pointer; background:linear-gradient(135deg, rgba(245,158,11,0.18), rgba(217,119,6,0.08)); border:1.5px solid rgba(245,158,11,0.5); border-radius:14px; padding:14px 20px; margin-bottom:20px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; box-shadow:0 0 25px rgba(245,158,11,0.18);">
            <div style="display:flex; align-items:center; gap:12px;">
                <div style="width:38px; height:38px; border-radius:10px; background:rgba(245,158,11,0.25); display:flex; align-items:center; justify-content:center; color:#f59e0b; font-size:18px;">
                    <i class="fas fa-tools"></i>
                </div>
                <div>
                    <div style="color:#fbbf24; font-size:14px; font-weight:800; text-transform:uppercase; letter-spacing:0.5px; display:flex; align-items:center; gap:8px;">
                        <span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:#f59e0b; box-shadow:0 0 10px #f59e0b;"></span>
                        ВНИМАНИЕ: НА САЙТЕ ВЕДУТСЯ ТЕХНИЧЕСКИЕ РАБОТЫ
                    </div>
                    <div style="font-size:12px; color:#cbd5e1; margin-top:2px;">
                        Инженерная команда обновляет сервисы. Нажмите на этот баннер, чтобы посмотреть детали и статус доступа.
                    </div>
                </div>
            </div>
            <span class="badge" style="background:rgba(245,158,11,0.2); color:#fbbf24; border:1px solid rgba(245,158,11,0.4); font-size:11px; padding:6px 12px; font-weight:700;">
                <i class="fas fa-spinner fa-spin"></i> Статус тех. работ
            </span>
        </div>

    <?php else: ?>
        <!-- Баннер для Матвея / Владельца / Администратора -->
        <div onclick="openMaintenanceModal()" style="cursor:pointer; background:linear-gradient(90deg, rgba(245, 158, 11, 0.2), rgba(217, 119, 6, 0.1)); border:1px solid rgba(245, 158, 11, 0.4); border-radius:12px; padding:12px 18px; margin-bottom:20px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; box-shadow:0 0 20px rgba(245, 158, 11, 0.15);">
            <div style="display:flex; align-items:center; gap:10px;">
                <span style="width:10px; height:10px; border-radius:50%; background:#f59e0b; box-shadow:0 0 10px #f59e0b; display:inline-block;"></span>
                <strong style="color:#fbbf24; font-size:13px; text-transform:uppercase; letter-spacing:0.5px;">
                    <i class="fas fa-tools"></i> Режим технического обслуживания активен
                </strong>
                <span style="font-size:12px; color:#cbd5e1;">(Вы имеете полный верховный доступ. Нажмите для просмотра таблички ТО)</span>
            </div>
            <?php if (Auth::isSupremeOwner($user) || in_array($userRole, ['vrio_owner', 'admin'], true)): ?>
                <a href="/owner/dashboard.php" class="btn btn-warning btn-sm" style="font-size:11px; padding:4px 10px; font-weight:700;" onclick="event.stopPropagation();">
                    <i class="fas fa-sliders-h"></i> Панель управления ТО
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php
// Обучающее модальное окно с презентацией проекта и возможностей при входе
require_once __DIR__ . '/welcome_tour_modal.php';
?>
