<?php
declare(strict_types=1);
ob_start();

/**
 * AI Business Assistant - User AI Assistant Chat Workspace
 * Автоматическая очистка диалога с ИИ в 00:00 МСК + возможность посмотреть "Что я писал вчера"
 */

require_once dirname(__DIR__) . '/includes/Auth.php';
require_once dirname(__DIR__) . '/includes/Subscription.php';

$user = Auth::requireAuth('/login.php');
$userId = (int)$user['id'];
$pdo = Database::getConnection();

// ─── ВРЕМЕННОЕ ОТКЛЮЧЕНИЕ AI-АССИСТЕНТА ───────────────────────────────────
// Раздел активен. Управление доступом осуществляется через систему блокировок секций (Auth / Owner).
define('AI_SECTION_CLOSED', false);
// ──────────────────────────────────────────────────────────────────────────

// Check subscription and AI quotas
$subCheck = Subscription::canUseAi($userId);

// Check if user already used trial
$trialUsedStmt = $pdo->prepare("
    SELECT COUNT(*) FROM subscriptions s
    JOIN tariffs t ON s.tariff_id = t.id
    WHERE s.user_id = ? AND t.code = 'TRIAL'
");
$trialUsedStmt->execute([$userId]);
$hasUsedTrial = ((int)$trialUsedStmt->fetchColumn()) > 0;

// Fetch user's documents for context attachment
$docsStmt = $pdo->prepare("SELECT id, filename FROM documents WHERE user_id = ? ORDER BY id DESC");
$docsStmt->execute([$userId]);
$documents = $docsStmt->fetchAll();

// Расчет времени по МСК (Europe/Moscow, UTC+3)
$mskTz = new DateTimeZone('Europe/Moscow');
$todayStartMsk = new DateTime('today 00:00:00', $mskTz);
$yesterdayStartMsk = (clone $todayStartMsk)->modify('-1 day');
$yesterdayEndMsk   = (clone $todayStartMsk)->modify('-1 second');

$todayStartStr     = $todayStartMsk->format('Y-m-d H:i:s');
$yesterdayStartStr = $yesterdayStartMsk->format('Y-m-d H:i:s');
$yesterdayEndStr   = $yesterdayEndMsk->format('Y-m-d H:i:s');

// Период просмотра: 'today' (по умолчанию), 'yesterday', 'all'
$period = trim($_GET['period'] ?? 'today');
if (!in_array($period, ['today', 'yesterday', 'all'], true)) {
    $period = 'today';
}

// Очистка диалога пользователем
$notice = null;
if (isset($_POST['action']) && $_POST['action'] === 'clear_today' && Security::verifyCsrf($_POST['csrf_token'] ?? '')) {
    $pdo->prepare("DELETE FROM ai_history WHERE user_id = ? AND created_at >= ?")->execute([$userId, $todayStartStr]);
    $notice = "Диалог с ИИ за сегодня успешно очищен.";
}

// Загрузка сообщений в зависимости от периода
$timeFilter = "";
$params = [$userId];

if ($period === 'today') {
    $timeFilter = " AND created_at >= ? ";
    $params[] = $todayStartStr;
} elseif ($period === 'yesterday') {
    $timeFilter = " AND created_at >= ? AND created_at <= ? ";
    $params[] = $yesterdayStartStr;
    $params[] = $yesterdayEndStr;
}

$historyStmt = $pdo->prepare("
    SELECT * FROM ai_history 
    WHERE user_id = ? 
    {$timeFilter}
    ORDER BY id ASC 
    LIMIT 50
");
$historyStmt->execute($params);
$chatHistory = $historyStmt->fetchAll();

// Pre-filled prompt or doc from GET params
$prePrompt = trim($_GET['prompt'] ?? '');
$preDocId = !empty($_GET['doc_id']) ? (int)$_GET['doc_id'] : 0;

$pageTitle = 'AI Ассистент | AI Business Assistant';
require dirname(__DIR__) . '/includes/header.php';
?>

<?php if (defined('AI_SECTION_CLOSED') && AI_SECTION_CLOSED): ?>
<!-- ─── УВЕДОМЛЕНИЕ: РАЗДЕЛ AI ВРЕМЕННО ЗАКРЫТ ─── -->
<div style="display:flex; align-items:center; justify-content:center; min-height:70vh;">
    <div style="max-width:520px; width:100%; text-align:center; padding:40px 32px; background:var(--bg-card); border:1px solid rgba(245,158,11,0.35); border-radius:20px; box-shadow:0 0 60px rgba(245,158,11,0.1);">

        <!-- Иконка -->
        <div style="width:80px; height:80px; border-radius:50%; background:rgba(245,158,11,0.12); border:2px solid rgba(245,158,11,0.4); display:flex; align-items:center; justify-content:center; margin:0 auto 24px;">
            <i class="fas fa-robot" style="font-size:32px; color:#f59e0b; opacity:0.7;"></i>
        </div>

        <!-- Бейдж статуса -->
        <div style="display:inline-flex; align-items:center; gap:6px; background:rgba(245,158,11,0.12); border:1px solid rgba(245,158,11,0.35); border-radius:20px; padding:4px 14px; font-size:11px; font-weight:700; color:#fbbf24; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:20px;">
            <i class="fas fa-clock" style="font-size:10px;"></i> Временно недоступен
        </div>

        <!-- Заголовок -->
        <h2 style="margin:0 0 12px; font-size:22px; font-weight:800; color:var(--text-primary);">
            Раздел AI-Ассистента временно закрыт
        </h2>

        <!-- Описание -->
        <p style="margin:0 0 24px; color:var(--text-muted); font-size:14px; line-height:1.7;">
            В данный момент мы проводим технические работы и улучшения в разделе AI-ассистента.
            Раздел будет снова доступен в ближайшее время. Приносим извинения за неудобства.
        </p>

        <!-- Разделитель -->
        <div style="height:1px; background:var(--border-color); margin:0 0 24px;"></div>

        <!-- Что работает -->
        <div style="text-align:left; margin-bottom:28px;">
            <div style="font-size:12px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:12px;">
                Пока что доступны:
            </div>
            <div style="display:flex; flex-direction:column; gap:8px;">
                <div style="display:flex; align-items:center; gap:10px; font-size:13px; color:var(--text-primary);">
                    <i class="fas fa-check-circle" style="color:#10b981; width:16px;"></i> Клиенты (CRM)
                </div>
                <div style="display:flex; align-items:center; gap:10px; font-size:13px; color:var(--text-primary);">
                    <i class="fas fa-check-circle" style="color:#10b981; width:16px;"></i> Задачи и поручения
                </div>
                <div style="display:flex; align-items:center; gap:10px; font-size:13px; color:var(--text-primary);">
                    <i class="fas fa-check-circle" style="color:#10b981; width:16px;"></i> Документы и аналитика
                </div>
                <div style="display:flex; align-items:center; gap:10px; font-size:13px; color:var(--text-primary);">
                    <i class="fas fa-check-circle" style="color:#10b981; width:16px;"></i> Онлайн-касса (POS)
                </div>
                <div style="display:flex; align-items:center; gap:10px; font-size:13px; color:var(--text-primary);">
                    <i class="fas fa-check-circle" style="color:#10b981; width:16px;"></i> Техническая поддержка
                </div>
            </div>
        </div>

        <!-- Кнопки -->
        <div style="display:flex; gap:10px; justify-content:center; flex-wrap:wrap;">
            <a href="/user/dashboard.php" class="btn btn-primary">
                <i class="fas fa-th-large"></i> На главную
            </a>
            <a href="/user/support.php" class="btn btn-secondary">
                <i class="fas fa-headset"></i> Написать в поддержку
            </a>
        </div>
    </div>
</div>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
<?php ob_end_flush(); ?>
<?php exit; endif; ?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:16px;">
    <div>
        <h2 style="margin:0 0 4px; font-size:24px; font-weight:700;">
            <i class="fas fa-robot text-primary"></i> AI Бизнес-Ассистент
        </h2>
        <p class="text-muted" style="margin:0; font-size:14px;">
            Интеллектуальный советник • Очистка в 00:00 МСК • История за вчера
        </p>
    </div>
    
    <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
        <!-- Переключатель периодов: Сегодня (00:00 МСК) / Вчера / Вся история -->
        <div style="display:inline-flex; background:var(--bg-input); border-radius:8px; padding:3px; border:1px solid var(--border-color);">
            <a href="/user/ai_assistant.php?period=today" 
               style="padding:5px 12px; font-size:12px; text-decoration:none; border-radius:6px; font-weight:600; color:<?= $period === 'today' ? '#fff' : 'var(--text-muted)' ?>; background:<?= $period === 'today' ? 'var(--primary)' : 'transparent' ?>;">
                🟢 Сегодня
            </a>
            <a href="/user/ai_assistant.php?period=yesterday" 
               style="padding:5px 12px; font-size:12px; text-decoration:none; border-radius:6px; font-weight:600; color:<?= $period === 'yesterday' ? '#fff' : 'var(--text-muted)' ?>; background:<?= $period === 'yesterday' ? 'var(--primary)' : 'transparent' ?>;">
                📅 Вчера
            </a>
            <a href="/user/ai_assistant.php?period=all" 
               style="padding:5px 12px; font-size:12px; text-decoration:none; border-radius:6px; font-weight:600; color:<?= $period === 'all' ? '#fff' : 'var(--text-muted)' ?>; background:<?= $period === 'all' ? 'var(--primary)' : 'transparent' ?>;">
                📜 Все
            </a>
        </div>

        <?php if ($period === 'today' && !empty($chatHistory)): ?>
            <form method="POST" style="margin:0;" onsubmit="return confirm('Очистить сегодняшнюю переписку с ИИ?');">
                <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
                <input type="hidden" name="action" value="clear_today">
                <button type="submit" class="btn btn-secondary btn-sm" style="color:#ef4444;" title="Очистить текущий диалог">
                    <i class="fas fa-trash-alt"></i> Очистить
                </button>
            </form>
        <?php endif; ?>

        <?php if ($subCheck['allowed']): ?>
            <span class="badge badge-success" style="padding:6px 12px; font-size:12px;" title="Лимит обновляется каждые 24 часа в полночь">
                <i class="fas fa-bolt"></i> Сегодня: <?= $subCheck['remaining'] ?> / <?= $subCheck['limit'] ?: '∞' ?> <span style="opacity:0.75; font-size:10px;">(сброс в 00:00)</span>
            </span>
        <?php else: ?>
            <span class="badge badge-danger" style="padding:6px 12px; font-size:12px;">
                <i class="fas fa-lock"></i> <?= htmlspecialchars($subCheck['reason']) ?>
            </span>
        <?php endif; ?>
    </div>
</div>

<?php if ($notice): ?>
    <div class="alert alert-success" style="margin-bottom:16px;">
        <i class="fas fa-check-circle"></i> <?= htmlspecialchars($notice) ?>
    </div>
<?php endif; ?>

<?php if (!$subCheck['allowed']): ?>
    <div class="alert alert-warning" style="margin-bottom:20px;">
        <i class="fas fa-exclamation-triangle"></i>
        <div>
            <strong>Доступ к AI ограничен:</strong> <?= htmlspecialchars($subCheck['reason']) ?>
            <div style="margin-top:8px;">
                <a href="/user/subscription.php" class="btn btn-primary btn-sm">Активировать подписку</a>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Interactive Chat Container -->
<div class="chat-container">
    <div class="chat-messages" id="chat-messages">
        <!-- Сервисная плашка периода -->
        <div style="text-align:center; margin:8px 0 16px;">
            <?php if ($period === 'today'): ?>
                <span style="font-size:12px; background:rgba(99,102,241,0.1); color:#818cf8; padding:5px 14px; border-radius:12px; border:1px solid rgba(99,102,241,0.25);">
                    🕒 <strong>Очищено в 00:00 МСК:</strong> Открыт чистый диалог на сегодня. Вчерашние ответы ИИ доступны во вкладке <strong>«Вчера»</strong>.
                </span>
            <?php elseif ($period === 'yesterday'): ?>
                <span style="font-size:12px; background:rgba(245,158,11,0.1); color:#f59e0b; padding:5px 14px; border-radius:12px; border:1px solid rgba(245,158,11,0.25);">
                    📅 <strong>Что вы писали вчера (<?= $yesterdayStartMsk->format('d.m.Y') ?>):</strong> Архив сохранённых вопросов и ответов ИИ.
                </span>
            <?php else: ?>
                <span style="font-size:12px; background:rgba(255,255,255,0.06); color:var(--text-muted); padding:5px 14px; border-radius:12px;">
                    📜 <strong>Полный архив:</strong> Вся история запросов к ИИ-ассистенту.
                </span>
            <?php endif; ?>
        </div>

        <!-- Initial welcome / disclaimer message -->
        <div class="message ai">
            <div class="message-bubble">
                <strong><i class="fas fa-robot text-primary"></i> AI Business Assistant:</strong><br>
                Здравствуйте, <?= htmlspecialchars($user['first_name']) ?>! Я ваш персональный бизнес-ассистент и консультант. 
                Вы можете задавать мне <strong>любые вопросы</strong>: от скриптов продаж и маркетинга до отчетов по кассе и договоров.
                <br><br>
                <em>Быстрые подсказки:</em>
                <ul style="margin: 8px 0 0 20px;">
                    <li>«Составь коммерческое предложение для клиента»</li>
                    <li>«Как отработать возражение "дорого"?»</li>
                    <li>«Сделай анализ продаж за текущую неделю»</li>
                    <li>«Предложи акции для привлечения новых гостей»</li>
                </ul>
            </div>
        </div>

        <?php if (empty($chatHistory) && $period === 'yesterday'): ?>
            <div style="text-align:center; padding:30px; color:var(--text-muted); font-size:14px;">
                <i class="fas fa-calendar-times" style="font-size:32px; opacity:0.3; margin-bottom:10px; display:block;"></i>
                Вчера вы не отправляли запросов к ИИ.
            </div>
        <?php endif; ?>

        <?php foreach ($chatHistory as $msg): ?>
            <!-- User message -->
            <div class="message user">
                <div class="message-bubble">
                    <div style="font-size:11px; opacity:0.75; margin-bottom:4px; text-align:right;">
                        <?= date('H:i, d.m.Y', strtotime($msg['created_at'])) ?>
                    </div>
                    <?= nl2br(htmlspecialchars($msg['prompt'])) ?>
                </div>
            </div>
            <!-- AI response -->
            <div class="message ai">
                <div class="message-bubble">
                    <div style="font-size:11px; color:#818cf8; margin-bottom:4px;">
                        <i class="fas fa-robot"></i> AI Ответ • <?= date('H:i', strtotime($msg['created_at'])) ?>
                    </div>
                    <?= nl2br(htmlspecialchars($msg['response'])) ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if (!$subCheck['allowed']): ?>
        <div style="padding:20px 24px; background:rgba(239,68,68,0.06); border-top:1px solid rgba(239,68,68,0.25); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
            <div style="display:flex; align-items:center; gap:14px;">
                <div style="width:44px; height:44px; border-radius:12px; background:rgba(239,68,68,0.15); color:#ef4444; display:flex; align-items:center; justify-content:center; font-size:20px;">
                    <i class="fas fa-lock"></i>
                </div>
                <div>
                    <h4 style="margin:0; font-size:15px; font-weight:700; color:var(--text-primary);">Отправка запросов к AI заблокирована</h4>
                    <p style="margin:2px 0 0; font-size:13px; color:var(--text-muted);"><?= htmlspecialchars($subCheck['reason']) ?></p>
                </div>
            </div>
            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                <?php if (!$hasUsedTrial): ?>
                    <a href="/user/dashboard.php?action=request_trial" class="btn btn-primary" style="font-weight:700; padding:9px 18px; box-shadow:0 4px 12px rgba(99,102,241,0.35);">
                        <i class="fas fa-gift"></i> Включить пробный режим (7 дней)
                    </a>
                <?php endif; ?>
                <a href="/user/subscription.php" class="btn btn-secondary" style="padding:9px 18px;">
                    <i class="fas fa-gem"></i> Выбрать тарифный план
                </a>
            </div>
        </div>
    <?php else: ?>
        <!-- Быстрые темы для анализа бизнеса (гранты, налоги, CRM, окупаемость) -->
        <div style="padding:10px 16px; background:rgba(0,0,0,0.15); border-top:1px solid var(--border-color); display:flex; gap:8px; overflow-x:auto; white-space:nowrap; scrollbar-width:thin;">
            <button type="button" class="btn btn-secondary btn-sm" style="font-size:11px; padding:4px 10px; border-radius:20px; font-weight:600;" onclick="setAiPrompt('Программы господдержки МСБ и гранты Фонда Даму до 5 млн тенге')">
                💰 Гранты &amp; Фонд Даму
            </button>
            <button type="button" class="btn btn-secondary btn-sm" style="font-size:11px; padding:4px 10px; border-radius:20px; font-weight:600;" onclick="setAiPrompt('Составь бизнес-план и расчёт окупаемости инвестиционного проекта')">
                💼 Бизнес-план и окупаемость
            </button>
            <button type="button" class="btn btn-secondary btn-sm" style="font-size:11px; padding:4px 10px; border-radius:20px; font-weight:600;" onclick="setAiPrompt('Налоги и фискализация: онлайн-касса POS, режим СНР 3% и ЭСФ')">
                🧾 Касса, СНР 3% и Налоги
            </button>
            <button type="button" class="btn btn-secondary btn-sm" style="font-size:11px; padding:4px 10px; border-radius:20px; font-weight:600;" onclick="setAiPrompt('Сформируй сводный финансовый отчёт по CRM и воронке')">
                📊 Сводный отчёт CRM
            </button>
            <button type="button" class="btn btn-secondary btn-sm" style="font-size:11px; padding:4px 10px; border-radius:20px; font-weight:600;" onclick="setAiPrompt('План создания рабочих мест и кадровое развитие предприятия')">
                👥 Рабочие места &amp; Кадры
            </button>
        </div>

        <!-- Input bar -->
        <form class="chat-input-bar" id="ai-chat-form">
            <?= Security::csrfField() ?>

            <div style="min-width: 180px;">
                <select id="doc_select" class="form-control" style="height:44px;" title="Прикрепить контекст документа">
                    <option value="">Без файла (Вся CRM)</option>
                    <?php foreach ($documents as $doc): ?>
                        <option value="<?= $doc['id'] ?>" <?= $preDocId === (int)$doc['id'] ? 'selected' : '' ?>>
                            📄 <?= htmlspecialchars(mb_substr($doc['filename'], 0, 20)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <input type="text" id="ai-chat-input" class="form-control" placeholder="Задайте вопрос: гранты МСБ, бизнес-план, касса, налоги, скрипты..." value="<?= htmlspecialchars($prePrompt) ?>" required autocomplete="off">

            <button type="submit" class="btn btn-primary" style="height:44px; min-width:120px;">
                <i class="fas fa-paper-plane"></i> Отправить
            </button>
        </form>
    <?php endif; ?>
</div>

<script>
function setAiPrompt(text) {
    const input = document.getElementById('ai-chat-input');
    if (input) {
        input.value = text;
        input.focus();
    }
}
</script>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
