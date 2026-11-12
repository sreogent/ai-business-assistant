<?php
declare(strict_types=1);

/**
 * AI Business Assistant - Landing Page
 */

require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/Auth.php';

// Automated initial database and tables check (only if not yet initialized)
$dbCheck = ['success' => true, 'message' => '', 'tables_created' => false];
if (!file_exists(__DIR__ . '/setup.lock')) {
    $dbCheck = Database::checkAndMigrate();
}
$pdo = Database::getConnection(true);

$adminExists = false;
if ($pdo) {
    try {
        $check = $pdo->query("SELECT COUNT(*) FROM users u JOIN roles r ON u.role_id = r.id WHERE r.name = 'admin'")->fetchColumn();
        $adminExists = (int)$check > 0;
    } catch (Exception $e) {
        $adminExists = false;
    }
}

// Fetch tariffs for public pricing & check for active discounts
$tariffs = [];
$activePromoTariff = null;
if ($pdo) {
    try {
        $tStmt = $pdo->query("
            SELECT * FROM tariffs 
            WHERE is_active = 1 
            ORDER BY 
                CASE code 
                    WHEN 'FREE' THEN 1 
                    WHEN 'MONTH' THEN 2 
                    WHEN 'HALF_YEAR' THEN 3 
                    WHEN 'YEAR' THEN 4 
                    WHEN 'CUSTOM' THEN 5 
                    ELSE 6 
                END ASC
        ");
        $tariffs = $tStmt->fetchAll();
        foreach ($tariffs as $t) {
            $isNotExpired = empty($t['discount_until']) || strtotime($t['discount_until']) > time();
            if (((int)($t['discount_percent'] ?? 0) > 0 || !empty($t['discount_price'])) && $isNotExpired) {
                if (!$activePromoTariff || (int)($t['discount_percent'] ?? 0) > (int)($activePromoTariff['discount_percent'] ?? 0)) {
                    $activePromoTariff = $t;
                }
            }
        }
    } catch (Exception $e) {}
}

$currentUser = Auth::user();
$landingActiveTheme = Auth::getActiveTheme();
$landingParticlesOn = Auth::isThemeParticlesEnabled();
?>
<!DOCTYPE html>
<html lang="ru" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Business Assistant — Виртуальный помощник для бизнеса и продаж</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/themes.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="/assets/js/cosmic-space.js" defer></script>
    <style>
        @keyframes blinkLive {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.7; transform: scale(0.96); }
        }
        @keyframes shimmerPromo {
            0% { background-position: -200% 0; }
            100% { background-position: 200% 0; }
        }
    </style>
</head>
<body>
    <!-- Cosmic Space Canvas Background with animated flying rocket -->
    <canvas id="space-canvas"></canvas>

    <!-- Navigation -->
    <header class="landing-header" style="position:relative; z-index:10; display:flex; justify-content:space-between; align-items:center; padding:16px 24px;">
        <div style="display:flex; align-items:center; gap:24px;">
            <a href="/" class="logo" style="text-decoration:none; font-weight:800; font-size:18px; color:var(--text-primary); display:flex; align-items:center; gap:8px;">
                <span class="logo-badge">AI</span> Business Assistant
            </a>
            <nav style="display:flex; gap:14px; align-items:center;">
                <a href="/news.php" style="color:var(--text-muted); text-decoration:none; font-size:14px; font-weight:600; display:flex; align-items:center; gap:6px;">
                    <i class="fas fa-newspaper"></i> Новости
                </a>
                <a href="/support_guest.php" style="color:var(--primary); text-decoration:none; font-size:14px; font-weight:700; display:flex; align-items:center; gap:6px;">
                    <i class="fas fa-headset"></i> Поддержка
                </a>
                <a href="/user/activate_key.php" style="color:var(--text-muted); text-decoration:none; font-size:14px; font-weight:600; display:flex; align-items:center; gap:6px;">
                    <i class="fas fa-key"></i> Активировать ключ
                </a>
            </nav>
        </div>
        <div class="nav-actions" style="display:flex; gap:10px; align-items:center;">
            <?php if ($currentUser): ?>
                <a href="<?= Auth::getRedirectForRole($currentUser['role_name'] ?? 'user') ?>" class="btn btn-primary">
                    <i class="fas fa-columns"></i> В панель управления
                </a>
            <?php else: ?>
                <a href="/login.php" class="btn btn-secondary">Вход</a>
                <a href="/register.php" class="btn btn-primary">Регистрация</a>
            <?php endif; ?>
        </div>
    </header>

    <?php if ($activePromoTariff): ?>
    <!-- Большой промо-баннер со скидками и изображением акции на главном экране -->
    <div style="background: linear-gradient(90deg, #b45309 0%, #b91c1c 50%, #7c2d12 100%); border-bottom: 2px solid #f59e0b; padding: 14px 20px; box-shadow: 0 6px 30px rgba(245, 158, 11, 0.35); position: relative; overflow: hidden;">
        <div style="max-width: 1200px; margin: 0 auto; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 14px; position: relative; z-index: 2;">
            <div style="display: flex; align-items: center; gap: 14px;">
                <div style="width: 50px; height: 50px; border-radius: 14px; background: rgba(0, 0, 0, 0.4); border: 2px solid #fde047; display: flex; align-items: center; justify-content: center; font-size: 26px; color: #fbbf24; box-shadow: 0 0 25px rgba(245, 158, 11, 0.8); flex-shrink: 0; animation: blinkLive 1.4s infinite;">
                    <i class="fas fa-fire"></i>
                </div>
                <div>
                    <div style="font-weight: 800; font-size: 16px; color: #fff; text-transform: uppercase; letter-spacing: 0.5px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                        <span>🔥 СЕЗОННАЯ АКЦИЯ: СКИДКА ДО <?= (int)($activePromoTariff['discount_percent'] ?: 30) ?>% НА ТАРИФЫ И ЛИЦЕНЗИИ!</span>
                        <span style="background: #fbbf24; color: #000; font-size: 11px; font-weight: 900; padding: 2px 8px; border-radius: 6px;">ВЫГОДНО</span>
                    </div>
                    <div style="font-size: 13px; color: #fef08a; margin-top: 2px;">
                        Зафиксируйте цену со скидкой.
                        <?php if (!empty($activePromoTariff['discount_until'])): ?>
                            <span style="opacity: 0.95;">• Предложение действует до <strong><?= date('d.m.Y H:i', strtotime($activePromoTariff['discount_until'])) ?></strong></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div style="display: flex; gap: 10px; align-items: center;">
                <a href="#tariffs" class="btn btn-warning" style="background: #fbbf24; color: #000; font-weight: 800; border: none; padding: 10px 22px; font-size: 14px; box-shadow: 0 4px 15px rgba(0,0,0,0.4); display: flex; align-items: center; gap: 8px;">
                    <i class="fas fa-tags"></i> Забрать скидку
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- System Diagnostic Banner (only on error) -->
    <?php if (!$dbCheck['success']): ?>
    <div style="max-width: 1200px; margin: 15px auto 0; padding: 0 20px;">
            <div class="alert alert-danger" style="margin-bottom: 0;">
                <i class="fas fa-exclamation-triangle"></i>
                <div>
                    <strong>Ошибка подключения к базе данных:</strong>
                    <span><?= htmlspecialchars($dbCheck['message']) ?></span>
                </div>
            </div>
    </div>
    <?php elseif (!$adminExists && !file_exists(__DIR__ . '/setup.lock')): ?>
    <div style="max-width: 1200px; margin: 15px auto 0; padding: 0 20px;">
            <div class="alert alert-warning" style="margin-bottom: 0;">
                <i class="fas fa-key"></i>
                <div>
                    <strong>Администратор ещё не создан.</strong>
                    <a href="/setup.php" style="color:var(--primary); text-decoration:underline; margin-left:8px;">Перейти к настройке →</a>
                </div>
            </div>
    </div>
    <?php endif; ?>

    <!-- Hero Section -->
    <section class="hero">
        <?php if ($activePromoTariff): ?>
            <div style="margin-bottom: 18px;">
                <a href="#tariffs" style="text-decoration:none; display: inline-flex; align-items: center; gap: 10px; padding: 8px 20px; border-radius: 30px; background: rgba(245, 158, 11, 0.18); border: 1.5px solid rgba(245, 158, 11, 0.55); box-shadow: 0 0 25px rgba(245, 158, 11, 0.25); color: #fbbf24; font-size: 13px; font-weight: 700; transition: transform 0.2s;" onmouseover="this.style.transform='scale(1.03)'" onmouseout="this.style.transform='scale(1)'">
                    <span style="width: 10px; height: 10px; border-radius: 50%; background: #f59e0b; box-shadow: 0 0 10px #f59e0b; display: inline-block; animation: blinkLive 1.2s infinite;"></span>
                    <span>🔥 Действует скидка до <?= (int)($activePromoTariff['discount_percent'] ?: 30) ?>% на тарифы!</span>
                    <i class="fas fa-arrow-right" style="font-size: 11px;"></i>
                </a>
            </div>
        <?php endif; ?>
        <span class="badge badge-primary mb-3"><i class="fas fa-sparkles"></i> AI-платформа нового поколения для B2B</span>
        <h1>Умный виртуальный помощник для предпринимателей и команд</h1>
        <p>
            Автоматизируйте рутину с помощью искусственного интеллекта: мгновенные ответы клиентам, глубокий анализ продаж, 
            автоматическая генерация коммерческих предложений (КП), разбор документов и контроль задач.
        </p>
        <div style="display:flex; justify-content:center; gap:16px; flex-wrap:wrap;">
            <?php if ($activePromoTariff): ?>
                <a href="#tariffs" class="btn btn-warning" style="padding:14px 28px; font-size:16px; font-weight:800; background:linear-gradient(135deg,#f59e0b,#ef4444); color:#fff; border:none; box-shadow: 0 4px 18px rgba(245,158,11,0.45);">
                    <i class="fas fa-fire"></i> Тарифы со скидкой -<?= (int)($activePromoTariff['discount_percent'] ?: 30) ?>%
                </a>
            <?php endif; ?>
            <a href="<?= $currentUser ? '/user/dashboard.php?action=request_trial' : '/register.php?trial=1' ?>" class="btn btn-primary" style="padding:14px 28px; font-size:16px; box-shadow: 0 4px 18px rgba(99,102,241,0.45); font-weight:700;">
                <i class="fas fa-gift"></i> Запросить тестовый режим (7 дней бесплатно)
            </a>
            <a href="/contacts.php" class="btn btn-secondary" style="padding:14px 28px; font-size:16px;">
                <i class="fas fa-envelope"></i> Контакты руководства
            </a>
            <a href="/support_guest.php" class="btn btn-secondary" style="padding:14px 28px; font-size:16px;">
                <i class="fas fa-headset"></i> Поддержка без входа
            </a>
            <a href="#features" class="btn btn-secondary" style="padding:14px 28px; font-size:16px;">
                <i class="fas fa-info-circle"></i> Возможности системы
            </a>
        </div>
    </section>

    <!-- Key Features Grid -->
    <section id="features" style="max-width:1200px; margin: 0 auto; padding: 20px;">
        <h2 class="text-center mb-2">Все инструменты для масштабирования бизнеса</h2>
        <p class="text-muted text-center mb-5">Объединяем аналитику, коммуникации и документооборот в единой защищенной среде</p>

        <div class="features-grid" style="margin:0; padding:0;">
            <div class="feature-card">
                <div class="feature-icon"><i class="fas fa-comments"></i></div>
                <h3>Ответы клиентам & AI Чат</h3>
                <p class="text-muted mt-2">Персонализированная работа с обращениями. Нейросеть отвечает строго по фактам вашей компании без галлюцинаций.</p>
            </div>

            <div class="feature-card">
                <div class="feature-icon"><i class="fas fa-chart-line"></i></div>
                <h3>Аналитика и отчеты</h3>
                <p class="text-muted mt-2">Мгновенный срез по воронке, конверсиям лидов и эффективности задач с наглядными графиками.</p>
            </div>

            <div class="feature-card">
                <div class="feature-icon"><i class="fas fa-file-invoice-dollar"></i></div>
                <h3>Генерация КП</h3>
                <p class="text-muted mt-2">Создание структурированных коммерческих предложений на основе реальных данных клиента за 5 секунд.</p>
            </div>

            <div class="feature-card">
                <div class="feature-icon"><i class="fas fa-file-pdf"></i></div>
                <h3>Анализ документов</h3>
                <p class="text-muted mt-2">Загружайте PDF, DOCX и TXT. ИИ моментально выделит главное, найдет риски и составит выжимку договора.</p>
            </div>

            <div class="feature-card">
                <div class="feature-icon"><i class="fas fa-user-friends"></i></div>
                <h3>Ведение клиентов (CRM)</h3>
                <p class="text-muted mt-2">Удобный учет контактов, этапов сделок и заметок с привязкой к интеллектуальному контексту.</p>
            </div>

            <div class="feature-card">
                <div class="feature-icon"><i class="fas fa-tasks"></i></div>
                <h3>Управление задачами</h3>
                <p class="text-muted mt-2">Контроль дедлайнов, приоритизация поручений сотрудникам и отслеживание статусов выполнения.</p>
            </div>
        </div>
    </section>

    <!-- Tariffs / Pricing -->
    <section id="tariffs" style="background:var(--bg-secondary); padding: 70px 20px; margin-top: 60px;">
        <div style="max-width:1100px; margin: 0 auto;">
            <div style="text-align:center; margin-bottom: 40px;">
                <?php if ($activePromoTariff): ?>
                    <span class="badge badge-primary" style="margin-bottom:12px; padding:5px 12px; font-size:11px;">
                        Специальное предложение: скидка до <?= (int)($activePromoTariff['discount_percent'] ?: 30) ?>%
                    </span>
                <?php endif; ?>
                <h2 style="margin: 0 0 10px;">Прозрачные тарифные планы</h2>
                <p class="text-muted" style="margin: 0;">Выберите подходящий период подписки для вашего бизнеса</p>
            </div>

            <div class="tariffs-grid">
                <?php foreach ($tariffs as $t): 
                    $hasDiscount = ((int)($t['discount_percent'] ?? 0) > 0) || !empty($t['discount_price']);
                    $origPrice = (float)$t['price'];
                    $finalPrice = $origPrice;
                    if ($hasDiscount) {
                        if (!empty($t['discount_price'])) {
                            $finalPrice = (float)$t['discount_price'];
                        } elseif ((int)($t['discount_percent'] ?? 0) > 0) {
                            $finalPrice = round($origPrice * (1 - (int)$t['discount_percent'] / 100), 2);
                        }
                    }
                    $isDiscountExpired = !empty($t['discount_until']) && strtotime($t['discount_until']) < time();
                    $discountActive = $hasDiscount && !$isDiscountExpired;
                ?>
                    <div class="tariff-card <?= $t['code'] === 'HALF_YEAR' ? 'featured' : '' ?>" style="<?= $discountActive ? 'border:2px solid rgba(245,158,11,0.6); box-shadow:0 0 30px rgba(245,158,11,0.2); position:relative;' : '' ?>">
                        <?php if ($discountActive): ?>
                            <div style="position:absolute; top:-14px; left:50%; transform:translateX(-50%); background:linear-gradient(135deg,#f59e0b,#ef4444); color:#fff; font-size:11px; font-weight:800; padding:4px 14px; border-radius:20px; white-space:nowrap; box-shadow:0 4px 14px rgba(245,158,11,0.5); text-transform:uppercase; letter-spacing:0.5px;">
                                СКИДКА <?= (int)($t['discount_percent'] ?? 0) > 0 ? (int)$t['discount_percent'] . '%' : 'АКЦИЯ' ?>
                            </div>
                        <?php elseif ($t['code'] === 'HALF_YEAR'): ?>
                            <span class="badge badge-primary" style="position:absolute; top:-12px; left:50%; transform:translateX(-50%);">
                                Рекомендуемый
                            </span>
                        <?php endif; ?>

                        <h3><?= htmlspecialchars($t['name']) ?></h3>

                        <?php if ($t['code'] === 'FREE'): ?>
                            <!-- Цена для Free -->
                            <div class="tariff-price" style="color:#10b981; font-weight:800; font-size:32px;">0 ₸</div>
                            <p class="text-muted" style="font-size:13px; margin-bottom:14px;">
                                Срок доступа: 7 дней <span style="color:#f59e0b; font-weight:700;">(доступен 1 раз)</span>
                            </p>
                            <ul class="tariff-features">
                                <li><i class="fas fa-bolt text-warning"></i> <strong>200</strong> AI запросов в день</li>
                                <li><i class="fas fa-check text-success"></i> Анализ PDF, DOCX и TXT</li>
                                <li><i class="fas fa-check text-success"></i> Полная CRM и менеджер задач</li>
                                <li><i class="fas fa-gift text-success"></i> Доступен 1 раз для теста</li>
                            </ul>
                            <a href="<?= $currentUser ? '/user/subscription.php?action=activate_free' : '/register.php' ?>" class="btn btn-success btn-block" style="background:linear-gradient(135deg,#10b981,#059669); color:#fff; font-weight:700; box-shadow:0 4px 14px rgba(16,185,129,0.4); border:none; padding:12px 18px;">
                                <i class="fas fa-gift"></i> Попробовать бесплатно
                            </a>

                        <?php elseif ($t['code'] === 'CUSTOM'): ?>
                            <!-- Калькулятор для Свой тариф прямо на главном экране -->
                            <div class="tariff-price" id="homeCustomPriceDisplay" style="color:var(--primary); font-weight:800; font-size:30px;">1 000 ₸</div>
                            <p class="text-muted" id="homeCustomRateNote" style="font-size:12px; margin-bottom:10px;">100 ₸ / день</p>
                            <div style="margin-bottom:14px; text-align:left;">
                                <label style="font-size:11px; font-weight:700; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.5px; display:block; margin-bottom:4px;">
                                    Укажите срок (в днях):
                                </label>
                                <input type="number" id="homeCustomDaysInput" value="10" min="1" max="3650" placeholder="Количество дней"
                                       style="width:100%; font-size:16px; font-weight:800; text-align:center; padding:8px 12px; border-radius:var(--radius-md); border:1.5px solid var(--primary); background:rgba(255,255,255,0.06); color:var(--text-primary); outline:none;">
                            </div>
                            <ul class="tariff-features">
                                <li><i class="fas fa-bolt text-warning"></i> <strong>500</strong> AI запросов в день</li>
                                <li><i class="fas fa-calculator text-primary"></i> Мгновенный расчёт стоимости</li>
                                <li><i class="fas fa-calendar-alt text-info"></i> Любой период от 1 до 3650 дней</li>
                                <li><i class="fas fa-check text-success"></i> Полный доступ ко всем модулям</li>
                            </ul>
                            <a href="<?= $currentUser ? '/user/subscription.php?days=10' : '/register.php?days=10' ?>" id="homeCustomBtn" class="btn btn-primary btn-block" style="font-weight:700; padding:12px 18px;">
                                <i class="fas fa-calculator"></i> Выбрать на <span id="homeCustomBtnDays">10</span> дн.
                            </a>

                        <?php else: ?>
                            <!-- Обычные тарифы (Start, Pro, Enterprise) -->
                            <div class="tariff-price">
                                <?php if ($discountActive): ?>
                                    <span style="color:#fbbf24; font-weight:800; font-size:28px;"><?= number_format((float)$finalPrice, 0, '.', ' ') ?> ₸</span>
                                    <div style="font-size:14px; color:#94a3b8; text-decoration:line-through; font-weight:normal; margin-top:2px;">
                                        <?= number_format((float)$origPrice, 0, '.', ' ') ?> ₸
                                    </div>
                                <?php else: ?>
                                    <?= number_format((float)$origPrice, 0, '.', ' ') ?> ₸
                                <?php endif; ?>
                            </div>
                            <p class="text-muted" style="font-size:13px; margin-bottom:14px;">
                                <?= $t['duration_days'] > 0 ? "Срок доступа: {$t['duration_days']} дней" : 'Индивидуальный период' ?>
                                <?php if ($discountActive && !empty($t['discount_until'])): ?>
                                    <br><span style="color:#f59e0b; font-size:11px; font-weight:600;">⏰ Скидка действует до: <?= date('d.m.Y H:i', strtotime($t['discount_until'])) ?></span>
                                <?php endif; ?>
                            </p>
                            <ul class="tariff-features">
                                <li><i class="fas fa-bolt text-warning"></i> <?= $t['ai_requests_limit'] > 0 ? "<strong>{$t['ai_requests_limit']}</strong> AI запросов" : 'По согласованию' ?></li>
                                <li><i class="fas fa-check text-success"></i> Анализ PDF, DOCX и TXT</li>
                                <li><i class="fas fa-check text-success"></i> Полная CRM и менеджер задач</li>
                                <li><i class="fas fa-check text-success"></i> Генератор КП и отчетов</li>
                                <?php if ($discountActive): ?>
                                    <li style="color:#fbbf24; font-weight:600;"><i class="fas fa-tag"></i> Фиксация цены со скидкой</li>
                                <?php endif; ?>
                            </ul>
                            <a href="<?= $currentUser ? '/user/subscription.php' : '/register.php' ?>" class="btn <?= ($discountActive || $t['code'] === 'HALF_YEAR') ? 'btn-primary' : 'btn-secondary' ?> btn-block" style="<?= $discountActive ? 'background:linear-gradient(135deg,#f59e0b,#d97706); color:#000; font-weight:800; border:none;' : '' ?>">
                                <?= $discountActive ? '<i class="fas fa-fire"></i> Забрать со скидкой' : 'Выбрать тариф' ?>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer style="padding: 30px 20px; text-align: center; border-top: 1px solid var(--border-color); color: var(--text-muted); font-size: 14px;">
        <div style="display:flex; justify-content:center; flex-wrap:wrap; gap:20px; margin-bottom:12px; font-weight:600;">
            <a href="/news.php" style="color:var(--text-muted); text-decoration:none;"><i class="fas fa-newspaper"></i> Новости</a>
            <span>•</span>
            <a href="/support_guest.php" style="color:var(--primary); text-decoration:none;"><i class="fas fa-headset"></i> Поддержка без входа</a>
            <span>•</span>
            <a href="/user/activate_key.php" style="color:var(--text-muted); text-decoration:none;"><i class="fas fa-key"></i> Активировать ключ</a>
            <span>•</span>
            <a href="/contacts.php" style="color:var(--text-muted); text-decoration:none;"><i class="fas fa-envelope"></i> Контакты</a>
            <span>•</span>
            <a href="/login.php" style="color:var(--text-muted); text-decoration:none;"><i class="fas fa-sign-in-alt"></i> Вход</a>
        </div>
        <p>&copy; <?= date('Y') ?> AI Business Assistant. Полноценная SaaS-платформа для бизнеса.</p>
    </footer>

    <!-- Плавающая кнопка быстрой связи с поддержкой для гостей -->
    <a href="/support_guest.php" id="floatingSupportBtn" style="position:fixed; bottom:24px; right:24px; z-index:999; display:flex; align-items:center; gap:10px; padding:12px 20px; border-radius:30px; background:linear-gradient(135deg,#3b82f6,#2563eb); color:#fff; text-decoration:none; font-weight:700; font-size:14px; box-shadow:0 8px 25px rgba(59,130,246,0.5); transition:transform 0.2s;" onmouseover="this.style.transform='scale(1.05)'" onmouseout="this.style.transform='scale(1)'">
        <i class="fas fa-headset" style="font-size:18px;"></i>
        <span>Поддержка онлайн</span>
    </a>

        <script>
    function updateHomeCustomPrice() {
        const input = document.getElementById('homeCustomDaysInput');
        const priceDisplay = document.getElementById('homeCustomPriceDisplay');
        const rateNote = document.getElementById('homeCustomRateNote');
        const btnDays = document.getElementById('homeCustomBtnDays');
        const btn = document.getElementById('homeCustomBtn');
        if (!input || !priceDisplay) return;

        let days = parseInt(input.value, 10);
        if (isNaN(days) || days < 1) days = 1;
        if (days > 3650) days = 3650;

        let price = 0;
        let rate = 100;
        let discountNote = '';

        if (days < 30) {
            rate = 100;
            price = days * 100;
        } else if (days < 180) {
            rate = Math.round(2990 / 30);
            price = Math.round(days * (2990 / 30));
            discountNote = ' (по ставке Start)';
        } else if (days < 365) {
            rate = Math.round(14990 / 180);
            price = Math.round(days * (14990 / 180));
            discountNote = ' (выгода ~17%)';
        } else {
            rate = Math.round(24990 / 365);
            price = Math.round(days * (24990 / 365));
            discountNote = ' (выгода ~32%)';
        }

        priceDisplay.textContent = new Intl.NumberFormat('ru-RU').format(price) + ' ₸';
        if (rateNote) {
            rateNote.textContent = rate + ' ₸ / день' + discountNote;
        }
        if (btnDays) {
            btnDays.textContent = days;
        }
        if (btn) {
            const isUser = <?php echo $currentUser ? 'true' : 'false'; ?>;
            const targetBase = isUser ? '/user/subscription.php' : '/register.php';
            btn.href = targetBase + '?days=' + days;
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        const input = document.getElementById('homeCustomDaysInput');
        if (input) {
            input.addEventListener('input', updateHomeCustomPrice);
            input.addEventListener('change', updateHomeCustomPrice);
            updateHomeCustomPrice();
        }
    });
    </script>
    <script src="/assets/js/main.js"></script>
</body>
</html>
