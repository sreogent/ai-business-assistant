<?php
declare(strict_types=1);

/**
 * AI Business Assistant - Новости платформы & Релизы
 * Доступно для всех пользователей и гостей.
 * Администрация может публиковать и удалять новости.
 */

require_once __DIR__ . '/includes/Auth.php';

$user = Auth::user();
$userId = $user ? (int)$user['id'] : 0;
$userRole = $user['role_name'] ?? 'guest';
$pdo = Database::getConnection();

// Публиковать новости могут ТОЛЬКО: разработчик, врио владелец и владелец (Матвей)
$isSupreme = Auth::isSupremeOwner($user);
$isDev = ($userRole === 'developer');
$isVrio = ($userRole === 'vrio_owner');
$canManageNews = ($isDev || $isVrio || $isSupreme);

// При открытии страницы новостей помечаем новости прочитанными для текущего пользователя
if ($userId > 0) {
    try {
        $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND type = 'news'")->execute([$userId]);
    } catch (\Throwable $e) {}
}

// 1. Автомиграция таблицы news
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS news (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            category VARCHAR(60) NOT NULL DEFAULT 'Обновление',
            summary TEXT NULL,
            content MEDIUMTEXT NOT NULL,
            image_url VARCHAR(255) NULL,
            author_name VARCHAR(100) NOT NULL DEFAULT 'Команда AI Business Assistant',
            is_published TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_created (created_at DESC)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // Начальные новости, если таблица пуста
    $count = (int)$pdo->query("SELECT COUNT(*) FROM news")->fetchColumn();
    if ($count === 0) {
        $initNews = [
            [
                'title'       => 'Обновление 2.5: Интеграция телефонии, звонков и голосовых заметок',
                'category'    => 'Обновления',
                'summary'     => 'Запущена система прямых звонков 1-на-1 и голосовых созвонов в каналах без задержек и с автоматическим сбросом гудков.',
                'content'     => "Мы рады представить крупное обновление коммуникаций в AI Business Assistant!\n\n1. Телефонные звонки 1-на-1:\nТеперь вы можете звонить любому сотруднику напрямую через личные сообщения. Реализованы реалистичные телефонные гудки, автоматическое прекращение вызова при сбросе собеседником и чистый звук через Agora WebRTC.\n\n2. Голосовые заметки:\nЗаписывайте голосовые сообщения прямо в чате с таймером и визуализацией записи.\n\n3. Групповые созвоны в каналах:\nВозможность подключать камеру, микрофон и проводить онлайн-совещания внутри любого корпоративного канала.",
                'author_name' => 'Команда разработки'
            ],
            [
                'title'       => 'Повышенная безопасность: Двухфакторная аутентификация (2FA)',
                'category'    => 'Безопасность',
                'summary'     => 'В профиле пользователя добавлена возможность включить 2FA с подтверждением по email и умным 2-часовым кулдауном.',
                'content'     => "Безопасность ваших корпоративных данных — наш главный приоритет.\n\nВ разделе «Настройки профиля» теперь доступно включение двухфакторной аутентификации (2FA):\n- При включении на ваш email отправляется проверочный код.\n- При входе в систему система запрашивает 4-значный код безопасности.\n- Реализован умный 2-часовой кулдаун: проверенное устройство запоминается на 2 часа, избавляя вас от необходимости вводить код при каждом переходе.",
                'author_name' => 'Служба безопасности'
            ],
            [
                'title'       => 'Искусственный интеллект для бизнеса: парсинг документов и анализ',
                'category'    => 'ИИ-функции',
                'summary'     => 'Автономная обработка файлов DOCX, XLSX и PDF с мгновенным извлечением данных и составлением сводок.',
                'content'     => "Модуль AI Ассистента получил расширенные возможности парсинга документации.\n\nТеперь система поддерживает автоматическое чтение и извлечение таблиц, счетов и договоров из форматов DOCX и Excel без внешних зависимостей. Загружайте файлы в базу знаний, задавайте вопросы ИИ и получайте структурированные ответы за секунды.",
                'author_name' => 'AI Лаборатория'
            ]
        ];

        $ins = $pdo->prepare("INSERT INTO news (title, category, summary, content, author_name) VALUES (?, ?, ?, ?, ?)");
        foreach ($initNews as $n) {
            $ins->execute([$n['title'], $n['category'], $n['summary'], $n['content'], $n['author_name']]);
        }
    }
} catch (\Throwable $e) {}

// 2. Обработка действий публикации / удаления
$notice = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManageNews) {
    $token = $_POST['csrf_token'] ?? '';
    if (!Security::verifyCsrf($token)) {
        $error = "Истёк токен безопасности. Повторите попытку.";
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create_news') {
            $title    = trim($_POST['title'] ?? '');
            $category = trim($_POST['category'] ?? 'Обновление');
            $summary  = trim($_POST['summary'] ?? '');
            $content  = trim($_POST['content'] ?? '');
            $author   = trim($_POST['author_name'] ?? ($user ? ($user['first_name'] . ' ' . $user['last_name']) : 'Администрация'));

            if (empty($title) || empty($content)) {
                $error = "Заголовок и текст новости обязательны для заполнения.";
            } else {
                if (empty($summary)) {
                    $summary = mb_substr(strip_tags($content), 0, 160) . '...';
                }
                $ins = $pdo->prepare("INSERT INTO news (title, category, summary, content, author_name) VALUES (?, ?, ?, ?, ?)");
                $ins->execute([$title, $category, $summary, $content, $author]);

                // Рассылаем уведомление о новой новости всем активным пользователям платформы
                try {
                    $uStmt = $pdo->query("SELECT id FROM users WHERE status = 'active'");
                    $allUsers = $uStmt->fetchAll(PDO::FETCH_COLUMN);
                    if (!empty($allUsers)) {
                        $notifStmt = $pdo->prepare("
                            INSERT INTO notifications (user_id, type, title, body, link, is_read)
                            VALUES (?, 'news', ?, ?, '/news.php', 0)
                        ");
                        $notifTitle = 'Новость платформы: ' . mb_substr($title, 0, 80);
                        $notifBody = mb_substr($summary ?: strip_tags($content), 0, 150);
                        foreach ($allUsers as $uId) {
                            $notifStmt->execute([(int)$uId, $notifTitle, $notifBody]);
                        }
                    }
                } catch (\Throwable $notifEx) {}

                $notice = "Новость успешно опубликована, уведомления отправлены всем пользователям!";
            }
        } elseif ($action === 'delete_news') {
            $newsId = (int)($_POST['news_id'] ?? 0);
            if ($newsId > 0) {
                $pdo->prepare("DELETE FROM news WHERE id = ?")->execute([$newsId]);
                $notice = "Новость удалена.";
            }
        }
    }
}

// 3. Выборка новостей с фильтром и поиском
$selectedCat = trim($_GET['cat'] ?? 'all');
$searchQuery = trim($_GET['q'] ?? '');

$where = ["is_published = 1"];
$params = [];

if ($selectedCat !== 'all' && !empty($selectedCat)) {
    $where[] = "category = :cat";
    $params[':cat'] = $selectedCat;
}

if (!empty($searchQuery)) {
    $where[] = "(title LIKE :q1 OR summary LIKE :q2 OR content LIKE :q3)";
    $params[':q1'] = "%{$searchQuery}%";
    $params[':q2'] = "%{$searchQuery}%";
    $params[':q3'] = "%{$searchQuery}%";
}

$whereSql = implode(' AND ', $where);
$stmt = $pdo->prepare("SELECT * FROM news WHERE {$whereSql} ORDER BY id DESC LIMIT 50");
$stmt->execute($params);
$newsList = $stmt->fetchAll(PDO::FETCH_ASSOC);

$categories = ['Все' => 'all', 'Обновления' => 'Обновления', 'Безопасность' => 'Безопасность', 'ИИ-функции' => 'ИИ-функции', 'События' => 'События'];

$pageTitle = 'Новости платформы & Релизы | AI Business Assistant';
$csrfToken = Security::csrfToken();

if ($user) {
    require __DIR__ . '/includes/header.php';
} else {
    // Гостевой заголовок
    ?>
    <!DOCTYPE html>
    <html lang="ru">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlspecialchars($pageTitle) ?></title>
        <link rel="stylesheet" href="/assets/css/style.css">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    </head>
    <body style="background:var(--bg-primary); min-height:100vh; color:var(--text-primary); font-family:system-ui, sans-serif;">
    <header style="background:var(--bg-card); border-bottom:1px solid var(--border-color); padding:14px 24px;">
        <div style="max-width:1200px; margin:0 auto; display:flex; justify-content:space-between; align-items:center;">
            <a href="/" style="display:flex; align-items:center; gap:10px; text-decoration:none; color:inherit; font-weight:800; font-size:18px;">
                <span style="width:36px; height:36px; border-radius:10px; background:linear-gradient(135deg,#6366f1,#4f46e5); display:flex; align-items:center; justify-content:center; color:#fff;">
                    <i class="fas fa-brain"></i>
                </span>
                <span>AI Business Assistant</span>
            </a>
            <div style="display:flex; gap:12px; align-items:center;">
                <a href="/news.php" class="btn btn-secondary btn-sm" style="font-weight:600;"><i class="fas fa-newspaper"></i> Новости</a>
                <a href="/support_guest.php" class="btn btn-secondary btn-sm" style="font-weight:600;"><i class="fas fa-headset"></i> Поддержка</a>
                <a href="/login.php" class="btn btn-primary btn-sm" style="font-weight:600;"><i class="fas fa-sign-in-alt"></i> Войти</a>
                <a href="/register.php" class="btn btn-outline-primary btn-sm" style="font-weight:600;">Регистрация</a>
            </div>
        </div>
    </header>
    <main style="max-width:1200px; margin:32px auto; padding:0 20px;">
    <?php
}
?>

<div style="margin-bottom:28px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
    <div>
        <h1 style="margin:0 0 6px; font-size:26px; font-weight:800; display:flex; align-items:center; gap:10px;">
            <i class="fas fa-newspaper" style="color:var(--primary);"></i> Новости платформы
        </h1>
        <p style="margin:0; color:var(--text-muted); font-size:14px;">
            Официальные обновления, релизы функций, анонсы и важные события AI Business Assistant
        </p>
    </div>
    <div style="display:flex; gap:10px;">
        <?php if ($canManageNews): ?>
            <button type="button" class="btn btn-primary btn-sm" onclick="openCreateNewsModal()" style="font-weight:700;">
                <i class="fas fa-plus-circle"></i> Опубликовать новость
            </button>
        <?php endif; ?>
        <a href="/support_guest.php" class="btn btn-secondary btn-sm" style="font-weight:600;">
            <i class="fas fa-headset"></i> Техподдержка
        </a>
    </div>
</div>

<?php if ($notice): ?>
    <div class="alert alert-success" style="margin-bottom:20px;"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($notice) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger" style="margin-bottom:20px;"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Панель фильтров и поиска -->
<div class="card" style="padding:16px 20px; margin-bottom:28px; background:var(--bg-card); border:1px solid var(--border-color); border-radius:12px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:14px;">
    <div style="display:flex; gap:8px; flex-wrap:wrap;">
        <?php foreach ($categories as $catLabel => $catVal): 
            $isActive = ($selectedCat === $catVal);
        ?>
            <a href="?cat=<?= urlencode($catVal) ?><?= !empty($searchQuery) ? '&q=' . urlencode($searchQuery) : '' ?>" 
               class="btn btn-sm <?= $isActive ? 'btn-primary' : 'btn-secondary' ?>" style="font-weight:600;">
                <?= htmlspecialchars($catLabel) ?>
            </a>
        <?php endforeach; ?>
    </div>
    <form method="GET" style="display:flex; gap:8px; margin:0; min-width:260px;">
        <input type="hidden" name="cat" value="<?= htmlspecialchars($selectedCat) ?>">
        <input type="text" name="q" value="<?= htmlspecialchars($searchQuery) ?>" class="form-control form-control-sm" placeholder="🔍 Поиск по новостям..." style="height:36px;">
        <button type="submit" class="btn btn-secondary btn-sm" style="height:36px; padding:0 14px;">Найти</button>
    </form>
</div>

<!-- Сетка новостей -->
<?php if (empty($newsList)): ?>
    <div class="card" style="padding:48px 24px; text-align:center; color:var(--text-muted); background:var(--bg-card); border:1px solid var(--border-color); border-radius:12px;">
        <i class="fas fa-newspaper" style="font-size:48px; opacity:0.25; margin-bottom:16px; display:block;"></i>
        <h3 style="margin:0 0 8px; font-size:18px;">В этом разделе пока нет публикаций</h3>
        <p style="margin:0; font-size:14px;">Попробуйте выбрать другую категорию или сбросить поисковый запрос.</p>
    </div>
<?php else: ?>
    <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(340px, 1fr)); gap:20px;">
        <?php foreach ($newsList as $item): 
            $catColors = [
                'Обновления' => ['bg' => 'rgba(99,102,241,0.15)', 'color' => '#818cf8', 'icon' => 'fa-rocket'],
                'Безопасность' => ['bg' => 'rgba(34,197,94,0.15)', 'color' => '#22c55e', 'icon' => 'fa-shield-alt'],
                'ИИ-функции' => ['bg' => 'rgba(236,72,153,0.15)', 'color' => '#f472b6', 'icon' => 'fa-brain'],
                'События' => ['bg' => 'rgba(245,158,11,0.15)', 'color' => '#fbbf24', 'icon' => 'fa-calendar-alt']
            ];
            $cMeta = $catColors[$item['category']] ?? ['bg' => 'rgba(255,255,255,0.08)', 'color' => '#cbd5e1', 'icon' => 'fa-tag'];
        ?>
            <div class="card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:14px; padding:22px; display:flex; flex-direction:column; justify-content:space-between; transition:transform 0.15s, border-color 0.15s;">
                <div>
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                        <span class="badge" style="background:<?= $cMeta['bg'] ?>; color:<?= $cMeta['color'] ?>; font-size:11px; font-weight:700; padding:4px 10px; border-radius:8px;">
                            <i class="fas <?= $cMeta['icon'] ?>"></i> <?= htmlspecialchars($item['category']) ?>
                        </span>
                        <span style="font-size:12px; color:var(--text-muted);">
                            <i class="far fa-clock"></i> <?= date('d.m.Y', strtotime($item['created_at'])) ?>
                        </span>
                    </div>

                    <h3 style="font-size:17px; font-weight:700; margin:0 0 10px; line-height:1.4; color:var(--text-primary);">
                        <?= htmlspecialchars($item['title']) ?>
                    </h3>

                    <p style="font-size:13px; color:var(--text-muted); line-height:1.6; margin:0 0 16px;">
                        <?= htmlspecialchars($item['summary']) ?>
                    </p>
                </div>

                <div style="display:flex; justify-content:space-between; align-items:center; border-top:1px solid var(--border-color); padding-top:14px; margin-top:8px;">
                    <div style="font-size:11px; color:var(--text-muted);">
                        <i class="fas fa-user-circle"></i> <?= htmlspecialchars($item['author_name']) ?>
                    </div>
                    <div style="display:flex; gap:8px;">
                        <button type="button" class="btn btn-outline-primary btn-sm" style="font-size:12px; padding:4px 12px; font-weight:600;" 
                                onclick='openViewNewsModal(<?= json_encode($item, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                            Читать &rarr;
                        </button>
                        <?php if ($canManageNews): ?>
                            <form method="POST" style="margin:0;" onsubmit="return confirm('Удалить эту новость?');">
                                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                <input type="hidden" name="action" value="delete_news">
                                <input type="hidden" name="news_id" value="<?= (int)$item['id'] ?>">
                                <button type="submit" class="btn btn-secondary btn-sm" style="font-size:12px; padding:4px 8px; color:#ef4444;" title="Удалить">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Модальное окно просмотра новости -->
<div id="viewNewsModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.75); backdrop-filter:blur(6px); z-index:9999; justify-content:center; align-items:center; padding:20px;">
    <div class="card" style="max-width:680px; width:100%; max-height:85vh; display:flex; flex-direction:column; background:var(--bg-card); border:1px solid var(--border-color); border-radius:16px; padding:28px; box-shadow:0 20px 40px rgba(0,0,0,0.5);">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:16px;">
            <div>
                <span id="modalNewsCategory" class="badge" style="font-size:11px; font-weight:700; padding:4px 10px; border-radius:8px;"></span>
                <span id="modalNewsDate" style="font-size:12px; color:var(--text-muted); margin-left:8px;"></span>
            </div>
            <button type="button" onclick="closeViewNewsModal()" style="background:none; border:none; color:var(--text-muted); font-size:20px; cursor:pointer;">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <h2 id="modalNewsTitle" style="font-size:20px; font-weight:800; margin:0 0 16px; line-height:1.4;"></h2>
        <div id="modalNewsContent" style="flex:1; overflow-y:auto; font-size:14px; line-height:1.7; color:var(--text-primary); white-space:pre-line; padding-right:8px;"></div>
        <div style="display:flex; justify-content:space-between; align-items:center; border-top:1px solid var(--border-color); padding-top:16px; margin-top:20px;">
            <div id="modalNewsAuthor" style="font-size:12px; color:var(--text-muted);"></div>
            <button type="button" class="btn btn-secondary btn-sm" onclick="closeViewNewsModal()">Закрыть</button>
        </div>
    </div>
</div>

<?php if ($canManageNews): ?>
<!-- Модальное окно создания новости -->
<div id="createNewsModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.75); backdrop-filter:blur(6px); z-index:9999; justify-content:center; align-items:center; padding:20px;">
    <div class="card" style="max-width:620px; width:100%; max-height:90vh; overflow-y:auto; background:var(--bg-card); border:1px solid var(--border-color); border-radius:16px; padding:28px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
            <h3 style="margin:0; font-size:18px; font-weight:700;"><i class="fas fa-edit text-primary"></i> Новая публикация</h3>
            <button type="button" onclick="closeCreateNewsModal()" style="background:none; border:none; color:var(--text-muted); font-size:20px; cursor:pointer;">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" value="create_news">

            <div class="form-group" style="margin-bottom:14px;">
                <label class="form-label" style="font-size:13px; font-weight:600;">Заголовок новости</label>
                <input type="text" name="title" class="form-control" required placeholder="Например: Обновление 2.6: Интеграция с банками">
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:14px;">
                <div class="form-group">
                    <label class="form-label" style="font-size:13px; font-weight:600;">Категория</label>
                    <select name="category" class="form-control">
                        <option value="Обновления">🚀 Обновления</option>
                        <option value="Безопасность">🛡️ Безопасность</option>
                        <option value="ИИ-функции">🧠 ИИ-функции</option>
                        <option value="События">📅 События</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" style="font-size:13px; font-weight:600;">Автор публикации</label>
                    <input type="text" name="author_name" class="form-control" value="<?= htmlspecialchars($user ? ($user['first_name'] . ' ' . $user['last_name']) : 'Администрация') ?>">
                </div>
            </div>

            <div class="form-group" style="margin-bottom:14px;">
                <label class="form-label" style="font-size:13px; font-weight:600;">Краткий анонс (отображается в карточке)</label>
                <textarea name="summary" class="form-control" rows="2" placeholder="Краткое описание на 1-2 предложения..."></textarea>
            </div>

            <div class="form-group" style="margin-bottom:20px;">
                <label class="form-label" style="font-size:13px; font-weight:600;">Полный текст новости</label>
                <textarea name="content" class="form-control" rows="8" required placeholder="Подробный текст новости..."></textarea>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="btn btn-secondary" onclick="closeCreateNewsModal()">Отмена</button>
                <button type="submit" class="btn btn-primary" style="font-weight:700;"><i class="fas fa-check"></i> Опубликовать</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
function openViewNewsModal(item) {
    document.getElementById('modalNewsCategory').textContent = item.category || 'Новость';
    document.getElementById('modalNewsCategory').style.background = 'rgba(99,102,241,0.2)';
    document.getElementById('modalNewsCategory').style.color = '#818cf8';
    document.getElementById('modalNewsDate').textContent = item.created_at ? item.created_at.substring(0, 10) : '';
    document.getElementById('modalNewsTitle').textContent = item.title;
    document.getElementById('modalNewsContent').textContent = item.content;
    document.getElementById('modalNewsAuthor').innerHTML = '<i class="fas fa-user-circle"></i> Автор: ' + (item.author_name || 'Администрация');
    document.getElementById('viewNewsModal').style.display = 'flex';
}
function closeViewNewsModal() {
    document.getElementById('viewNewsModal').style.display = 'none';
}
function openCreateNewsModal() {
    document.getElementById('createNewsModal').style.display = 'flex';
}
function closeCreateNewsModal() {
    document.getElementById('createNewsModal').style.display = 'none';
}
</script>

<?php
if ($user) {
    require __DIR__ . '/includes/footer.php';
} else {
    echo "</main></body></html>";
}
?>
