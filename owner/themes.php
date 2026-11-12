<?php
declare(strict_types=1);

/**
 * AI Business Assistant - Управление Сезонными Стилями & Темами
 * ДОСТУП ИСКЛЮЧИТЕЛЬНО ДЛЯ:
 * 1. Матвей Ибадлаев (Верховный Владелец)
 * 2. Пользователи с системной ролью 'developer' (Разработчик)
 */

require_once dirname(__DIR__) . '/includes/Auth.php';
require_once dirname(__DIR__) . '/includes/Security.php';

$user = Auth::requireAuth();

// Строжайшая проверка доступа: ТОЛЬКО Матвей Ибадлаев или роль 'developer'
if (!Auth::canManageSiteTheme($user)) {
    http_response_code(403);
    $pdo = Database::getConnection();
    Security::log($pdo, 'unauthorized_theme_access', "Попытка несанкционированного доступа к смене темы пользователем #" . ($user['id'] ?? 0), (int)($user['id'] ?? 0));
    require dirname(__DIR__) . '/includes/403.php';
    exit;
}

$pdo = Database::getConnection(true);
$error = null;
$success = null;

// Обработка смены темы или тумблера частиц
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!Security::verifyCsrf($token)) {
        $error = "Истёк токен безопасности (CSRF). Пожалуйста, обновите страницу.";
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'set_theme') {
            $selectedTheme = trim($_POST['theme'] ?? 'dark');
            $enableParticles = isset($_POST['particles']) ? ($_POST['particles'] === '1') : Auth::isThemeParticlesEnabled();

            $res = Auth::setActiveTheme($selectedTheme, $enableParticles, $user);
            if ($res['success']) {
                $success = $res['message'];
            } else {
                $error = $res['message'];
            }
        } elseif ($action === 'toggle_particles') {
            $currTheme = Auth::getActiveTheme();
            $newParticlesState = isset($_POST['particles_enabled']) && $_POST['particles_enabled'] === '1';

            $res = Auth::setActiveTheme($currTheme, $newParticlesState, $user);
            if ($res['success']) {
                $success = $newParticlesState 
                    ? "✨ Сезонные анимированные частицы (снег, лепестки, листья) успешно ВКЛЮЧЕНЫ!" 
                    : "Сезонные анимированные эффекты выключены.";
            } else {
                $error = $res['message'];
            }
        }
    }
}

$activeThemeKey = Auth::getActiveTheme();
$isParticlesOn = Auth::isThemeParticlesEnabled();
$catalog = Auth::getThemeCatalog();
$currentThemeData = $catalog[$activeThemeKey] ?? $catalog['dark'];

$csrfToken = Security::csrfToken();
$pageTitle = "Управление Стилями и Сезонными Темами | AI Business Assistant";
require dirname(__DIR__) . '/includes/header.php';
?>

<div style="max-width: 1300px; margin: 0 auto; padding-bottom: 50px;">

    <!-- Хедер страницы с бейджами эксклюзивного доступа -->
    <div style="margin-bottom: 24px; display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px;">
        <div>
            <div style="display:flex; align-items:center; gap:10px; margin-bottom:6px;">
                <h1 style="font-size:24px; margin:0; font-weight:800; display:flex; align-items:center; gap:10px;">
                    <i class="fas fa-palette" style="color:#ec4899;"></i> Сезонные Стили & Праздничные Темы
                </h1>
                <span class="badge" style="background:rgba(236, 72, 153, 0.15); color:#ec4899; border:1px solid rgba(236, 72, 153, 0.3); font-size:11px; font-weight:700;">
                    <i class="fas fa-lock"></i> Только Матвей & Разработчик
                </span>
            </div>
            <p style="color:var(--text-secondary); margin:0; font-size:14px;">
                Централизованное управление визуальной атмосферой всей платформы, сайта и личных кабинетов.
            </p>
        </div>

        <div style="display:flex; gap:10px; align-items:center;">
            <a href="/" target="_blank" class="btn btn-secondary btn-sm" title="Открыть главную страницу сайта в новом окне">
                <i class="fas fa-external-link-alt"></i> Проверить сайт
            </a>
            <?php if (Auth::isSupremeOwner($user)): ?>
                <a href="/owner/dashboard.php" class="btn btn-secondary btn-sm">
                    <i class="fas fa-crown" style="color:#f59e0b;"></i> Кабинет Владельца
                </a>
            <?php else: ?>
                <a href="/developer/dashboard.php" class="btn btn-secondary btn-sm">
                    <i class="fas fa-code" style="color:#06b6d4;"></i> Кабинет Разработчика
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Уведомления об успехе или ошибке -->
    <?php if ($success): ?>
        <div class="alert alert-success" style="font-size:14px; font-weight:600; box-shadow:0 4px 20px rgba(16,185,129,0.2);">
            <i class="fas fa-check-circle" style="font-size:18px;"></i>
            <div><?= htmlspecialchars($success) ?></div>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger" style="font-size:14px; font-weight:600;">
            <i class="fas fa-exclamation-triangle" style="font-size:18px;"></i>
            <div><?= htmlspecialchars($error) ?></div>
        </div>
    <?php endif; ?>

    <!-- Карточка активной темы и глобальный тумблер частиц -->
    <div class="card" style="margin-bottom: 28px; padding: 22px; border: 1.5px solid var(--border-color); background: linear-gradient(135deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01));">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:20px;">
            <div style="display:flex; align-items:center; gap:16px;">
                <div style="width:58px; height:58px; border-radius:16px; background:<?= $currentThemeData['bg_preview'] ?>; border:2px solid <?= $currentThemeData['accent_color'] ?>; display:flex; align-items:center; justify-content:center; font-size:24px; color:<?= $currentThemeData['accent_color'] ?>; box-shadow:0 0 25px rgba(0,0,0,0.5);">
                    <i class="fas <?= $currentThemeData['icon'] ?>"></i>
                </div>
                <div>
                    <div style="display:flex; align-items:center; gap:8px;">
                        <span style="font-size:11px; text-transform:uppercase; letter-spacing:1px; color:var(--text-muted); font-weight:700;">Текущий стиль платформы</span>
                        <span class="badge" style="background:rgba(16, 185, 129, 0.15); color:#10b981; font-size:10px; font-weight:800;">АКТИВЕН ДЛЯ ВСЕХ</span>
                    </div>
                    <div style="font-size:20px; font-weight:800; color:var(--text-primary); margin-top:2px;">
                        <?= htmlspecialchars($currentThemeData['name']) ?>
                    </div>
                    <div style="font-size:12px; color:var(--text-secondary); margin-top:2px;">
                        <?= htmlspecialchars($currentThemeData['desc']) ?>
                    </div>
                </div>
            </div>

            <!-- Тумблер анимаций / частиц -->
            <form method="POST" action="/owner/themes.php" style="margin:0; background:rgba(0,0,0,0.25); border:1px solid var(--border-color); padding:12px 18px; border-radius:14px; display:flex; align-items:center; gap:14px;">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="action" value="toggle_particles">
                <input type="hidden" name="particles_enabled" value="<?= $isParticlesOn ? '0' : '1' ?>">

                <div>
                    <div style="font-size:13px; font-weight:700; color:var(--text-primary); display:flex; align-items:center; gap:6px;">
                        <i class="fas fa-magic" style="color:#fbbf24;"></i> Сезонные атмосферные частицы
                    </div>
                    <div style="font-size:11px; color:var(--text-muted);">
                        Снежинки, лепестки сакуры, золотые искры, кленовые листья
                    </div>
                </div>

                <button type="submit" class="btn <?= $isParticlesOn ? 'btn-primary' : 'btn-secondary' ?> btn-sm" style="font-weight:700; padding:8px 16px;">
                    <?php if ($isParticlesOn): ?>
                        <i class="fas fa-toggle-on"></i> Включены
                    <?php else: ?>
                        <i class="fas fa-toggle-off"></i> Выключены
                    <?php endif; ?>
                </button>
            </form>
        </div>
    </div>

    <!-- Заголовок каталога -->
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
        <h2 style="font-size:18px; font-weight:800; margin:0; display:flex; align-items:center; gap:8px;">
            <i class="fas fa-cubes" style="color:var(--primary);"></i> Доступные коллекции стилей
        </h2>
        <span style="font-size:12px; color:var(--text-muted);">
            Нажмите «Предпросмотр», чтобы примерить тему прямо сейчас, или «Применить», чтобы включить её для всех.
        </span>
    </div>

    <!-- Сетка карточек тем -->
    <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(360px, 1fr)); gap:22px;">
        <?php foreach ($catalog as $tId => $t): 
            $isActive = ($tId === $activeThemeKey);
        ?>
            <div class="card theme-card" style="padding:0; overflow:hidden; position:relative; display:flex; flex-direction:column; transition:all 0.25s ease; border:<?= $isActive ? '2px solid ' . $t['accent_color'] : '1px solid var(--border-color)' ?>; box-shadow:<?= $isActive ? '0 0 30px ' . $t['accent_color'] . '33' : 'var(--shadow)' ?>;">
                
                <!-- Верхняя цветная шапка темы -->
                <div style="height:85px; background:<?= $t['bg_preview'] ?>; padding:16px 20px; display:flex; justify-content:space-between; align-items:flex-start; position:relative; overflow:hidden;">
                    <div style="position:absolute; right:-10px; bottom:-15px; font-size:75px; opacity:0.12; color:#fff; pointer-events:none;">
                        <i class="fas <?= $t['icon'] ?>"></i>
                    </div>

                    <div style="display:inline-flex; align-items:center; gap:8px; background:rgba(0,0,0,0.4); backdrop-filter:blur(6px); -webkit-backdrop-filter:blur(6px); padding:4px 10px; border-radius:20px; border:1px solid rgba(255,255,255,0.15); font-size:11px; font-weight:700; color:#fff;">
                        <i class="fas <?= $t['icon'] ?>" style="color:<?= $t['accent_color'] ?>;"></i>
                        <span><?= htmlspecialchars($t['badge']) ?></span>
                    </div>

                    <?php if ($isActive): ?>
                        <span class="badge" style="background:#10b981; color:#fff; font-size:11px; font-weight:800; padding:4px 10px; box-shadow:0 0 12px rgba(16,185,129,0.5);">
                            <i class="fas fa-check-circle"></i> АКТИВНА
                        </span>
                    <?php endif; ?>
                </div>

                <!-- Тело карточки -->
                <div style="padding:18px 20px; flex:1; display:flex; flex-direction:column;">
                    <div style="display:flex; align-items:baseline; justify-content:space-between; margin-bottom:6px;">
                        <h3 style="font-size:17px; font-weight:800; margin:0; color:var(--text-primary);">
                            <?= htmlspecialchars($t['name']) ?>
                        </h3>
                    </div>

                    <p style="font-size:12px; color:var(--text-secondary); line-height:1.5; margin:0 0 14px; min-height:36px;">
                        <?= htmlspecialchars($t['desc']) ?>
                    </p>

                    <!-- Мини-демонстрация темы -->
                    <div style="background:rgba(0,0,0,0.3); border:1px solid var(--border-color); border-radius:10px; padding:10px 12px; margin-bottom:14px; font-size:11px;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                            <span style="color:var(--text-muted); font-weight:600;">Эффект частиц:</span>
                            <span style="font-weight:700; color:<?= $t['accent_color'] ?>;">
                                <?= htmlspecialchars($t['particles_name']) ?>
                            </span>
                        </div>
                        <div style="display:flex; gap:6px; align-items:center;">
                            <span style="color:var(--text-muted); font-weight:600; font-size:10px;">Палитра:</span>
                            <div style="display:flex; gap:5px; margin-left:auto;">
                                <span style="width:18px; height:18px; border-radius:50%; background:<?= $t['accent_color'] ?>; display:inline-block; border:1px solid rgba(255,255,255,0.2);" title="Основной акцент"></span>
                                <span style="width:18px; height:18px; border-radius:50%; background:<?= $t['secondary_color'] ?>; display:inline-block; border:1px solid rgba(255,255,255,0.2);" title="Второй акцент"></span>
                            </div>
                        </div>
                    </div>

                    <!-- Кнопки действий -->
                    <div style="margin-top:auto; display:flex; gap:8px;">
                        <!-- Кнопка живого предпросмотра на лету -->
                        <button type="button" class="btn btn-secondary btn-sm" onclick="previewThemeLive('<?= $tId ?>')" style="flex:1; font-weight:600; font-size:12px;" title="Примерить этот стиль прямо сейчас">
                            <i class="fas fa-eye"></i> Примерка
                        </button>

                        <!-- Форма сохранения темы на весь сайт -->
                        <form method="POST" action="/owner/themes.php" style="margin:0; flex:1.4;">
                            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                            <input type="hidden" name="action" value="set_theme">
                            <input type="hidden" name="theme" value="<?= $tId ?>">
                            <input type="hidden" name="particles" value="<?= $isParticlesOn ? '1' : '0' ?>">

                            <?php if ($isActive): ?>
                                <button type="button" class="btn btn-sm" disabled style="width:100%; font-weight:800; background:rgba(16,185,129,0.15); color:#10b981; border:1px solid rgba(16,185,129,0.3); cursor:default;">
                                    <i class="fas fa-check"></i> Активна
                                </button>
                            <?php else: ?>
                                <button type="submit" class="btn btn-primary btn-sm" style="width:100%; font-weight:800; background:linear-gradient(135deg, <?= $t['accent_color'] ?>, <?= $t['secondary_color'] ?>);">
                                    <i class="fas fa-bolt"></i> Применить
                                </button>
                            <?php endif; ?>
                        </form>
                    </div>

                </div>
            </div>
        <?php endforeach; ?>
    </div>

</div>

<!-- Скрипт быстрого живого предпросмотра -->
<script>
function previewThemeLive(themeId) {
    document.documentElement.setAttribute('data-theme', themeId);
    
    // Перезапуск частиц с новой темой
    const canvas = document.getElementById('theme-particles-canvas');
    if (canvas && canvas.parentNode) {
        canvas.parentNode.removeChild(canvas);
    }
    
    // Симулируем событие DOMContentLoaded для переинициализации эффектов
    const event = new Event('DOMContentLoaded');
    document.dispatchEvent(event);

    // Всплывающее уведомление о примерке
    const toast = document.createElement('div');
    toast.style.position = 'fixed';
    toast.style.bottom = '24px';
    toast.style.right = '24px';
    toast.style.zIndex = '999999';
    toast.style.background = 'rgba(15, 23, 42, 0.95)';
    toast.style.border = '1px solid var(--primary)';
    toast.style.color = '#fff';
    toast.style.padding = '12px 20px';
    toast.style.borderRadius = '12px';
    toast.style.fontSize = '13px';
    toast.style.fontWeight = '700';
    toast.style.boxShadow = '0 10px 30px rgba(0,0,0,0.6)';
    toast.innerHTML = '<i class="fas fa-wand-magic-sparkles" style="color:var(--primary); margin-right:8px;"></i> Примерка темы включена! Чтобы сохранить её для всех пользователей, нажмите «Применить».';
    document.body.appendChild(toast);

    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transition = 'opacity 0.5s ease';
        setTimeout(() => toast.remove(), 500);
    }, 4000);
}
</script>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
