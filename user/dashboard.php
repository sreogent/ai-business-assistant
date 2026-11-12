<?php
declare(strict_types=1);

/**
 * AI Business Assistant - User Dashboard
 */

require_once dirname(__DIR__) . '/includes/Auth.php';
require_once dirname(__DIR__) . '/includes/Subscription.php';

$user = Auth::requireAuth('/login.php');
$userId = (int)$user['id'];

$pdo = Database::getConnection();

$trialMsg = null;
$trialErr = null;

if (isset($_GET['trial_started'])) {
    $trialMsg = "Бесплатный тариф Free на 7 дней успешно активирован. Вам доступно 200 AI-запросов в день и полный доступ ко всем функциям платформы.";
}

if (isset($_GET['action']) && in_array($_GET['action'], ['request_trial', 'activate_free'], true)) {
    $res = Subscription::activateFree($userId, 7);
    if ($res['success']) {
        $trialMsg = $res['message'];
    } else {
        $trialErr = $res['message'];
    }
}

$subInfo = Subscription::canUseAi($userId);
$activeSub = Subscription::getUserSubscription($userId);

// Check if user already used trial before (available strictly once)
$hasUsedTrial = Subscription::hasUsedFree($userId);

// Action history (recent logs)
$histStmt = $pdo->prepare("SELECT * FROM logs WHERE user_id = ? ORDER BY id DESC LIMIT 5");
$histStmt->execute([$userId]);
$history = $histStmt->fetchAll();

// General metrics
$clientCount = (int)$pdo->prepare("SELECT COUNT(*) FROM clients WHERE user_id = ?")->execute([$userId]) ? $pdo->query("SELECT COUNT(*) FROM clients WHERE user_id = {$userId}")->fetchColumn() : 0;
$taskCount = (int)$pdo->prepare("SELECT COUNT(*) FROM tasks WHERE user_id = ? AND status != 'completed'")->execute([$userId]) ? $pdo->query("SELECT COUNT(*) FROM tasks WHERE user_id = {$userId} AND status != 'completed'")->fetchColumn() : 0;
$docCount = (int)$pdo->prepare("SELECT COUNT(*) FROM documents WHERE user_id = ?")->execute([$userId]) ? $pdo->query("SELECT COUNT(*) FROM documents WHERE user_id = {$userId}")->fetchColumn() : 0;

// Проверка активных скидок на платформе для отображения баннера
$activePromoTariff = null;
try {
    $promoCheck = $pdo->query("
        SELECT * FROM tariffs 
        WHERE is_active = 1 
          AND (discount_percent > 0 OR discount_price IS NOT NULL) 
          AND (discount_until IS NULL OR discount_until > NOW()) 
        ORDER BY discount_percent DESC 
        LIMIT 1
    ");
    $activePromoTariff = $promoCheck->fetch();
} catch (\Throwable $e) {}

$pageTitle = 'Дашборд | AI Business Assistant';
require dirname(__DIR__) . '/includes/header.php';
?>

<div style="margin-bottom: 24px;">
    <h2 style="font-weight:600; font-size:22px; letter-spacing:-0.3px;">Панель управления — <?= htmlspecialchars($user['first_name']) ?></h2>
    <p class="text-muted">Управляйте клиентами, задачами, документами и поручайте работу искусственному интеллекту.</p>
</div>

<?php if ($trialMsg): ?>
    <div class="alert alert-success" style="margin-bottom:20px;">
        <i class="fas fa-check-circle"></i> <?= htmlspecialchars($trialMsg) ?>
    </div>
<?php endif; ?>
<?php if ($trialErr): ?>
    <div class="alert alert-warning" style="margin-bottom:20px;">
        <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($trialErr) ?>
    </div>
<?php endif; ?>

<?php if ($activePromoTariff): ?>
    <!-- Лаконичное корпоративное уведомление о тарифах -->
    <div class="card" style="border-left: 3px solid #2563eb; padding: 14px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px;">
        <div style="display: flex; align-items: center; gap: 14px;">
            <div style="width: 38px; height: 38px; border-radius: var(--radius-md); background: rgba(37,99,235,0.1); border: 1px solid rgba(37,99,235,0.25); color: #60a5fa; display: flex; align-items: center; justify-content: center; font-size: 15px; flex-shrink: 0;">
                <i class="fas fa-tag"></i>
            </div>
            <div>
                <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                    <div style="font-size: 14px; font-weight: 600; color: var(--text-primary);">
                        Специальное предложение: скидка до <?= (int)($activePromoTariff['discount_percent'] ?: 30) ?>% на тарифы
                    </div>
                    <span class="badge badge-primary">
                        Акция
                    </span>
                </div>
                <p style="margin: 2px 0 0; font-size: 12.5px; color: var(--text-muted);">
                    Оформите или продлите лицензию по сниженной стоимости
                    <?php if (!empty($activePromoTariff['discount_until'])): ?>
                        • Срок действия: до <?= date('d.m.Y H:i', strtotime($activePromoTariff['discount_until'])) ?>
                    <?php endif; ?>
                </p>
            </div>
        </div>
        <a href="/user/subscription.php" class="btn btn-primary btn-sm">
            Перейти к тарифам
        </a>
    </div>
<?php endif; ?>

<?php if ((!$activeSub || $activeSub['status'] !== 'active') && !$hasUsedTrial): ?>
    <!-- Карточка бесплатного тестового периода -->
    <div class="card" style="border-left: 3px solid #10b981; padding: 14px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px;">
        <div style="display: flex; align-items: center; gap: 14px;">
            <div style="width: 38px; height: 38px; border-radius: var(--radius-md); background: rgba(16,185,129,0.1); border: 1px solid rgba(16,185,129,0.25); color: #34d399; display: flex; align-items: center; justify-content: center; font-size: 15px; flex-shrink: 0;">
                <i class="fas fa-shield-alt"></i>
            </div>
            <div>
                <div style="font-size: 14px; font-weight: 600; color: var(--text-primary);">
                    Пробный доступ: 7 дней бесплатного использования
                </div>
                <p style="margin: 2px 0 0; font-size: 12.5px; color: var(--text-muted);">
                    200 запросов ИИ в день, доступ к CRM, аналитике и документам (доступен 1 раз на аккаунт).
                </p>
            </div>
        </div>
        <a href="/user/dashboard.php?action=activate_free" class="btn btn-success btn-sm">
            Подключить Free (7 дней)
        </a>
    </div>
<?php endif; ?>

<?php if ($userHasOrg): ?>
    <!-- Карточка активной компании -->
    <div class="card" style="background:rgba(16,185,129,0.08); border:1px solid rgba(16,185,129,0.3); border-radius:var(--radius-lg); padding:20px 24px; margin-bottom:24px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
        <div style="display:flex; align-items:center; gap:14px;">
            <div style="width:44px; height:44px; border-radius:12px; background:#10b981; color:#fff; display:flex; align-items:center; justify-content:center; font-size:20px;">
                <i class="fas fa-building"></i>
            </div>
            <div>
                <div style="display:flex; align-items:center; gap:8px;">
                    <h3 style="margin:0; font-size:18px; font-weight:700; color:var(--text-primary);">
                        <?= htmlspecialchars($userOrg['name']) ?>
                    </h3>
                    <span class="badge badge-success" style="font-size:11px;">Организация активна</span>
                </div>
                <div style="font-size:13px; color:var(--text-muted); margin-top:2px;">
                    Доступны: Торговый каталог, должности, Онлайн-Касса (POS) и Чат руководства компании
                </div>
            </div>
        </div>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <a href="/user/cashier.php" class="btn btn-primary btn-sm">
                <i class="fas fa-cash-register"></i> Онлайн-Касса (POS)
            </a>
            <a href="/user/menu_items.php" class="btn btn-secondary btn-sm">
                <i class="fas fa-utensils"></i> Меню & Товары
            </a>
            <a href="/user/org_chat.php" class="btn btn-secondary btn-sm">
                <i class="fas fa-comments"></i> Чат руководства
            </a>
        </div>
    </div>
<?php else: ?>
    <!-- Плашка предложения открыть организацию -->
    <div class="card" style="background:rgba(99,102,241,0.06); border:1px solid rgba(99,102,241,0.25); border-radius:var(--radius-lg); padding:16px 20px; margin-bottom:24px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:14px;">
        <div style="display:flex; align-items:center; gap:12px;">
            <div style="width:38px; height:38px; border-radius:10px; background:rgba(99,102,241,0.2); color:var(--primary); display:flex; align-items:center; justify-content:center; font-size:18px;">
                <i class="fas fa-store"></i>
            </div>
            <div>
                <div style="font-weight:700; font-size:14px; color:var(--text-primary);">Откройте компанию для подключения Онлайн-Кассы</div>
                <div style="font-size:12px; color:var(--text-muted);">Подайте заявку, и администратор автоматически развернет каталог и кассу для вашего бизнеса.</div>
            </div>
        </div>
        <a href="/user/request_organization.php" class="btn btn-primary btn-sm">
            <i class="fas fa-plus-circle"></i> Подать заявку на организацию
        </a>
    </div>
<?php endif; ?>

<!-- Stats Grid -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon" style="background:linear-gradient(135deg, rgba(245,158,11,0.22) 0%, rgba(217,119,6,0.12) 100%); border-color:rgba(245,158,11,0.45); color:#fbbf24; box-shadow:0 0 20px rgba(245,158,11,0.25);">
            <i class="fas fa-crown"></i>
        </div>
        <div>
            <div class="stat-title">Текущий тариф</div>
            <div class="stat-value" style="font-size:20px; font-weight:800;">
                <?= htmlspecialchars($activeSub['tariff_name'] ?? 'Без тарифа') ?>
            </div>
            <small class="text-muted" style="font-size:12px;">
                <?= $activeSub ? 'До: ' . date('d.m.Y', strtotime($activeSub['end_date'])) : 'Требуется подписка' ?>
            </small>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon" style="background:linear-gradient(135deg, rgba(99,102,241,0.25) 0%, rgba(168,85,247,0.15) 100%); border-color:rgba(99,102,241,0.45); color:#a5b4fc; box-shadow:0 0 20px rgba(99,102,241,0.25);">
            <i class="fas fa-brain"></i>
        </div>
        <div>
            <div class="stat-title">AI Запросы (сегодня)</div>
            <div class="stat-value" style="font-size:20px; font-weight:800;">
                <?= $subInfo['allowed'] ? ($subInfo['used'] . ' / ' . ($subInfo['limit'] ?: '∞')) : '0' ?>
            </div>
            <small class="<?= $subInfo['allowed'] ? 'text-success' : 'text-danger' ?>" style="font-size:12px;">
                <?= $subInfo['allowed'] ? "Осталось: {$subInfo['remaining']}" : 'Лимит исчерпан' ?>
            </small>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon" style="background:linear-gradient(135deg, rgba(6,182,212,0.22) 0%, rgba(14,165,233,0.12) 100%); border-color:rgba(6,182,212,0.45); color:#38bdf8; box-shadow:0 0 20px rgba(6,182,212,0.25);">
            <i class="fas fa-user-friends"></i>
        </div>
        <div>
            <div class="stat-title">Клиенты в CRM</div>
            <div class="stat-value" style="font-size:20px; font-weight:800;"><?= $clientCount ?></div>
            <small class="text-muted" style="font-size:12px;"><a href="/user/clients.php" style="color:var(--accent);">Открыть базу &rarr;</a></small>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon" style="background:linear-gradient(135deg, rgba(16,185,129,0.22) 0%, rgba(5,150,105,0.12) 100%); border-color:rgba(16,185,129,0.45); color:#34d399; box-shadow:0 0 20px rgba(16,185,129,0.25);">
            <i class="fas fa-tasks"></i>
        </div>
        <div>
            <div class="stat-title">Активные задачи</div>
            <div class="stat-value" style="font-size:20px; font-weight:800;"><?= $taskCount ?></div>
            <small class="text-muted" style="font-size:12px;"><a href="/user/tasks.php" style="color:var(--success);">Список задач &rarr;</a></small>
        </div>
    </div>
</div>

<!-- Fast Access / AI Actions -->
<div style="display:grid; grid-template-columns: 2fr 1fr; gap:24px; margin-bottom: 28px;">
    <div class="card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:24px;">
        <h3 class="mb-3"><i class="fas fa-robot text-primary"></i> Быстрые действия с AI Ассистентом</h3>
        <p class="text-muted mb-4">Нажмите на задачу, чтобы мгновенно передать контекст нейросети:</p>
        
        <div style="display:flex; flex-wrap:wrap; gap:12px;">
            <a href="/user/ai_assistant.php?prompt=<?= urlencode('Составь коммерческое предложение для нового клиента') ?>" class="btn btn-secondary">
                <i class="fas fa-file-invoice-dollar text-primary"></i> Составить КП для клиента
            </a>
            <a href="/user/ai_assistant.php?prompt=<?= urlencode('Сделай сводный аналитический отчет по текущим продажам и воронке') ?>" class="btn btn-secondary">
                <i class="fas fa-chart-pie text-success"></i> Анализ продаж и конверсий
            </a>
            <a href="/user/ai_assistant.php?prompt=<?= urlencode('Проанализируй список активных задач и расставь приоритеты для сотрудников') ?>" class="btn btn-secondary">
                <i class="fas fa-list-check text-warning"></i> Приоритизация задач сотрудников
            </a>
            <a href="/user/documents.php" class="btn btn-secondary">
                <i class="fas fa-file-alt text-accent"></i> Разобрать договор или документ
            </a>
        </div>
    </div>

    <div class="card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:24px;">
        <h3 class="mb-3"><i class="fas fa-gem text-warning"></i> Статус подписки</h3>
        <?php if ($activeSub && $activeSub['status'] === 'active'): ?>
            <p class="text-secondary" style="font-size:14px; margin-bottom:12px;">
                Ваша подписка активна до <strong><?= date('d.m.Y H:i', strtotime($activeSub['end_date'])) ?></strong>.
            </p>
            <div class="badge badge-success mb-3"><i class="fas fa-check"></i> Активна</div>
            <a href="/user/subscription.php" class="btn btn-secondary btn-sm btn-block">Продлить или сменить тариф</a>
        <?php else: ?>
            <p class="text-danger" style="font-size:14px; margin-bottom:12px;">
                Подписка истекла или отсутствует. AI функции заблокированы.
            </p>
            <?php if (!$hasUsedTrial): ?>
                <a href="/user/dashboard.php?action=request_trial" class="btn btn-primary btn-sm btn-block mb-2" style="font-weight:600;">
                    <i class="fas fa-gift"></i> Включить пробный период (7 дней)
                </a>
            <?php endif; ?>
            <a href="/user/subscription.php" class="btn btn-secondary btn-sm btn-block">Выбрать тариф</a>
        <?php endif; ?>
    </div>
</div>

<!-- History Log -->
<div class="card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:24px;">
    <h3 class="mb-3"><i class="fas fa-history text-secondary"></i> Недавняя активность</h3>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Событие</th>
                    <th>Детали</th>
                    <th>IP адрес</th>
                    <th>Время</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($history)): ?>
                    <tr>
                        <td colspan="4" class="text-center text-muted">История активности пока пуста</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($history as $h): ?>
                        <tr>
                            <td><span class="badge badge-primary"><?= htmlspecialchars($h['event_type']) ?></span></td>
                            <td><?= htmlspecialchars($h['details'] ?? '—') ?></td>
                            <td><small class="text-muted"><?= htmlspecialchars($h['ip_address']) ?></small></td>
                            <td><?= date('d.m.Y H:i', strtotime($h['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
