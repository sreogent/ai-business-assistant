<?php
declare(strict_types=1);

/**
 * AI Business Assistant - Тарифы и подписка
 *
 * Тарифы:
 *   - FREE: 7 дней, 200 запросов ИИ в день, доступен 1 раз
 *   - MONTH: 30 дней, 2 990 ₸
 *   - HALF_YEAR: 180 дней, 14 990 ₸
 *   - YEAR: 365 дней, 24 990 ₸
 *   - CUSTOM: любой срок от 1 до 3650 дней, автоматический расчёт стоимости
 */

require_once dirname(__DIR__) . '/includes/Auth.php';
require_once dirname(__DIR__) . '/includes/Subscription.php';

$user   = Auth::requireAuth('/login.php');
$userId = (int)$user['id'];
$pdo    = Database::getConnection();

// Создаём таблицу payment_requests если ещё нет (с поддержкой колонки amount)
$pdo->exec("CREATE TABLE IF NOT EXISTS payment_requests (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT NOT NULL,
    tariff_id     INT NOT NULL,
    custom_days   INT NULL,
    amount        DECIMAL(10, 2) NULL,
    contact_info  VARCHAR(255) NULL,
    status        ENUM('pending','approved','rejected') DEFAULT 'pending',
    admin_note    TEXT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pr_user   FOREIGN KEY (user_id)   REFERENCES users   (id) ON DELETE CASCADE,
    CONSTRAINT fk_pr_tariff FOREIGN KEY (tariff_id) REFERENCES tariffs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$error   = null;
$success = null;

// Обработка запроса бесплатного тарифа Free (доступен только 1 раз)
if (isset($_GET['action']) && in_array($_GET['action'], ['request_trial', 'activate_free'], true)) {
    $res = Subscription::activateFree($userId, 7);
    if ($res['success']) {
        $success = $res['message'];
    } else {
        $error = $res['message'];
    }
}

// Обработка заявки на оплату
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!Security::verifyCsrf($token)) {
        $error = "Истек токен безопасности. Повторите попытку.";
    } else {
        $tariffId    = (int)($_POST['tariff_id'] ?? 0);
        $customDays  = !empty($_POST['custom_days']) ? (int)$_POST['custom_days'] : null;
        $contactInfo = Security::sanitize(trim($_POST['contact_info'] ?? ''));

        if ($tariffId <= 0) {
            $error = "Выберите тариф.";
        } else {
            // Загружаем выбранный тариф
            $tarStmt = $pdo->prepare("SELECT * FROM tariffs WHERE id = ?");
            $tarStmt->execute([$tariffId]);
            $selectedTariff = $tarStmt->fetch();

            if (!$selectedTariff) {
                $error = "Выбранный тариф не найден.";
            } elseif ($selectedTariff['code'] === 'FREE') {
                // Если отправлена заявка на Free — активируем сразу
                $res = Subscription::activateFree($userId, 7);
                if ($res['success']) {
                    $success = $res['message'];
                } else {
                    $error = $res['message'];
                }
            } else {
                // Рассчитываем правильную сумму
                $calculatedAmount = 0.00;
                if ($selectedTariff['code'] === 'CUSTOM') {
                    $customDays = max(1, min(3650, (int)$customDays));
                    $calculatedAmount = Subscription::calculateCustomPrice($customDays);
                } else {
                    $origPrice = (float)$selectedTariff['price'];
                    $hasDiscount = ((int)($selectedTariff['discount_percent'] ?? 0) > 0) || !empty($selectedTariff['discount_price']);
                    $isDiscountExpired = !empty($selectedTariff['discount_until']) && strtotime($selectedTariff['discount_until']) < time();
                    if ($hasDiscount && !$isDiscountExpired) {
                        if (!empty($selectedTariff['discount_price'])) {
                            $calculatedAmount = (float)$selectedTariff['discount_price'];
                        } elseif ((int)$selectedTariff['discount_percent'] > 0) {
                            $calculatedAmount = round($origPrice * (1 - (int)$selectedTariff['discount_percent'] / 100), 2);
                        }
                    } else {
                        $calculatedAmount = $origPrice;
                    }
                }

                // Проверяем нет ли уже ожидающей заявки
                $existing = $pdo->prepare("SELECT id FROM payment_requests WHERE user_id = ? AND tariff_id = ? AND status = 'pending'");
                $existing->execute([$userId, $tariffId]);
                if ($existing->fetch()) {
                    $error = "Заявка на этот тариф уже отправлена и ожидает рассмотрения администратором.";
                } else {
                    $ins = $pdo->prepare("INSERT INTO payment_requests (user_id, tariff_id, custom_days, amount, contact_info) VALUES (?, ?, ?, ?, ?)");
                    $ins->execute([$userId, $tariffId, $customDays, $calculatedAmount, $contactInfo]);
                    Security::log($pdo, 'payment_request_sent', "Пользователь отправил заявку на тариф ID: {$tariffId} (дней: {$customDays}, сумма: {$calculatedAmount})", $userId);
                    $formattedSum = number_format($calculatedAmount, 0, '.', ' ');
                    $daysText = $customDays ? " на {$customDays} дн." : "";
                    $success = "✅ Заявка на тариф «{$selectedTariff['name']}»{$daysText} (сумма: {$formattedSum} ₸) отправлена! Администратор свяжется с вами для оплаты, после чего подписка активируется.";
                }
            }
        }
    }
}

$currentSub = Subscription::getUserSubscription($userId);

// Проверка использования бесплатного тарифа (строго 1 раз)
$hasUsedFree = Subscription::hasUsedFree($userId);

// Загружаем активные тарифы в логичном порядке (FREE -> MONTH -> HALF_YEAR -> YEAR -> CUSTOM)
$tariffs = $pdo->query("
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
")->fetchAll();

// Мои заявки
$myRequests = $pdo->prepare("
    SELECT pr.*, t.name AS tariff_name, t.price AS tariff_price, t.code AS tariff_code
    FROM payment_requests pr
    JOIN tariffs t ON pr.tariff_id = t.id
    WHERE pr.user_id = ?
    ORDER BY pr.id DESC
    LIMIT 10
");
$myRequests->execute([$userId]);
$myRequests = $myRequests->fetchAll();

$pageTitle = 'Тарифы и подписка | AI Business Assistant';
require dirname(__DIR__) . '/includes/header.php';
?>

<div style="margin-bottom:24px;">
    <h2><i class="fas fa-gem text-primary"></i> Тарифы и подписка</h2>
    <p class="text-muted">Выберите подходящий тарифный план для полноценного доступа к AI-функциям</p>
</div>

<?php if ($success): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $success ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Текущая подписка -->
<div class="card mb-4" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:24px;">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
        <div>
            <h3 class="mb-1">
                Текущий план:
                <span class="text-primary"><?= htmlspecialchars($currentSub['tariff_name'] ?? 'Нет активной подписки') ?></span>
            </h3>
            <p class="text-muted" style="margin:0;">
                <?php if ($currentSub && $currentSub['status'] === 'active'): ?>
                    Действует до <strong><?= date('d.m.Y H:i', strtotime($currentSub['end_date'])) ?></strong>
                    <?php if ($currentSub['tariff_code'] === 'FREE'): ?>
                        <span class="badge badge-warning" style="margin-left:8px;"><i class="fas fa-bolt"></i> 200 запросов ИИ в день</span>
                    <?php endif; ?>
                <?php else: ?>
                    Для работы с AI ассистентом требуется активная подписка.
                <?php endif; ?>
            </p>
        </div>
        <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
            <?php if ($currentSub && $currentSub['status'] === 'active'): ?>
                <span class="badge badge-success" style="font-size:14px; padding:8px 14px;">
                    <i class="fas fa-check"></i> Активна
                </span>
            <?php else: ?>
                <span class="badge badge-danger" style="font-size:14px; padding:8px 14px;">
                    <i class="fas fa-times"></i> Не активна
                </span>
            <?php endif; ?>
            <a href="/user/activate_key.php" class="btn btn-secondary btn-sm">
                <i class="fas fa-key"></i> Ввести ключ активации
            </a>
        </div>
    </div>
</div>

<?php if ((!$currentSub || $currentSub['status'] !== 'active') && !$hasUsedFree): ?>
    <!-- Промо-блок бесплатного тарифа Free -->
    <div class="card mb-4" style="border-left: 3px solid #10b981; padding: 18px 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
        <div style="display: flex; align-items: center; gap: 16px;">
            <div style="width: 40px; height: 40px; border-radius: var(--radius-md); background: rgba(16,185,129,0.1); border: 1px solid rgba(16,185,129,0.25); color: #34d399; display: flex; align-items: center; justify-content: center; font-size: 16px;">
                <i class="fas fa-shield-alt"></i>
            </div>
            <div>
                <h3 style="margin: 0; font-size: 15px; font-weight: 600; color: var(--text-primary);">
                    Тариф Free — 7 дней ознакомительного доступа
                </h3>
                <p style="margin: 3px 0 0; font-size: 12.5px; color: var(--text-muted);">
                    200 запросов ИИ в день, доступ к CRM, аналитике и документам (предоставляется 1 раз на аккаунт).
                </p>
            </div>
        </div>
        <a href="/user/subscription.php?action=activate_free" class="btn btn-success btn-sm">
            Активировать Free
        </a>
    </div>
<?php endif; ?>

<!-- Блок информации о покупке -->
<div class="alert" style="background:rgba(59,130,246,0.1); border:1px solid rgba(59,130,246,0.3); border-radius:var(--radius-lg); padding:16px 20px; margin-bottom:28px;">
    <div style="display:flex; gap:14px; align-items:flex-start;">
        <i class="fas fa-info-circle" style="color:#3b82f6; margin-top:2px; font-size:16px;"></i>
        <div>
            <strong style="color:var(--text-primary);">Как оформить подписку?</strong>
            <ul style="margin:8px 0 0; padding-left:18px; color:var(--text-muted); font-size:13px; line-height:1.8;">
                <li><strong>Тариф Free:</strong> Активируется бесплатно в 1 клик на 7 дней (доступен 1 раз для каждого пользователя).</li>
                <li><strong>Платные тарифы:</strong> Оставьте заявку ниже → администратор выставит счёт/Kaspi QR → после оплаты подписка активируется.</li>
                <li><strong>Ключ активации:</strong> Если у вас уже есть лицензионный ключ — <a href="/user/activate_key.php" style="color:#3b82f6;">активируйте его здесь</a>.</li>
            </ul>
        </div>
    </div>
</div>

<!-- Тарифные карточки -->
<div class="tariffs-grid">
    <?php foreach ($tariffs as $t): ?>
        <?php
        // Есть ли уже pending-заявка на этот тариф?
        $hasPending = false;
        foreach ($myRequests as $r) {
            if ((int)$r['tariff_id'] === (int)$t['id'] && $r['status'] === 'pending') {
                $hasPending = true;
                break;
            }
        }
        $isCurrentActive = $currentSub && (int)$currentSub['tariff_id'] === (int)$t['id'] && $currentSub['status'] === 'active';
        $isFree = ($t['code'] === 'FREE');
        $isCustom = ($t['code'] === 'CUSTOM');
        ?>
        <div class="tariff-card <?= $t['code'] === 'HALF_YEAR' ? 'featured' : '' ?> <?= $isFree ? 'tariff-free-card' : '' ?>" style="position:relative;">
            <?php if ($t['code'] === 'HALF_YEAR'): ?>
                <span class="badge badge-primary" style="position:absolute; top:-10px; left:50%; transform:translateX(-50%);">
                    Рекомендуемый
                </span>
            <?php elseif ($isFree): ?>
                <span class="badge badge-success" style="position:absolute; top:-10px; left:50%; transform:translateX(-50%);">
                    Ознакомительный
                </span>
            <?php elseif ($isCustom): ?>
                <span class="badge" style="position:absolute; top:-10px; left:50%; transform:translateX(-50%); background:rgba(148,163,184,0.12); color:#cbd5e1; border:1px solid rgba(148,163,184,0.25);">
                    Индивидуальный
                </span>
            <?php endif; ?>

            <h3><?= htmlspecialchars($t['name']) ?></h3>

            <?php if ($isCustom): ?>
                <!-- Калькулятор цены для Свой тариф -->
                <div class="tariff-price" id="customPriceDisplay">1 000 ₸</div>
                <p class="text-muted" id="customRateNote" style="font-size:12px; margin-bottom:14px;">100 ₸ / день</p>
            <?php elseif ($isFree): ?>
                <!-- Цена для Free -->
                <div class="tariff-price" style="color:#10b981;">0 ₸</div>
                <p class="text-muted" style="font-size:13px; margin-bottom:16px;">
                    на 7 дней <span style="color:#f59e0b; font-weight:600;">(доступен 1 раз)</span>
                </p>
            <?php else: ?>
                <!-- Обычные тарифы -->
                <div class="tariff-price">
                    <?php
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
                    <?php if ($discountActive): ?>
                        <span style="color:#fbbf24; font-weight:800;"><?= number_format((float)$finalPrice, 0, '.', ' ') ?> ₸</span>
                        <div style="font-size:13px; color:#94a3b8; text-decoration:line-through; font-weight:normal; margin-top:2px;">
                            <?= number_format((float)$origPrice, 0, '.', ' ') ?> ₸
                        </div>
                    <?php else: ?>
                        <?= number_format((float)$origPrice, 0, '.', ' ') ?> ₸
                    <?php endif; ?>
                </div>
                <p class="text-muted" style="font-size:13px; margin-bottom:16px;">
                    <?= $t['duration_days'] > 0 ? "на {$t['duration_days']} дней" : 'Индивидуальный период' ?>
                </p>
            <?php endif; ?>

            <ul class="tariff-features">
                <?php if ($isFree): ?>
                    <li><i class="fas fa-bolt text-warning"></i> <strong>200</strong> AI запросов в день</li>
                    <li><i class="fas fa-check text-success"></i> Анализ документов PDF, DOCX, TXT</li>
                    <li><i class="fas fa-check text-success"></i> Полный CRM-функционал</li>
                    <li><i class="fas fa-check text-success"></i> Аналитика и отчёты</li>
                    <li><i class="fas fa-info-circle text-info"></i> Активация 1 раз на аккаунт</li>
                <?php elseif ($isCustom): ?>
                    <li><i class="fas fa-bolt text-warning"></i> <strong>500</strong> AI запросов в день</li>
                    <li><i class="fas fa-calculator text-primary"></i> Автоматический расчёт стоимости</li>
                    <li><i class="fas fa-calendar-alt text-info"></i> Любой срок от 1 до 3650 дней</li>
                    <li><i class="fas fa-check text-success"></i> Анализ PDF, DOCX и CRM-модули</li>
                <?php else: ?>
                    <li><i class="fas fa-bolt text-warning"></i> <?= $t['ai_requests_limit'] > 0 ? "<strong>{$t['ai_requests_limit']}</strong> AI запросов" : 'Безлимитно' ?></li>
                    <li><i class="fas fa-check text-success"></i> Анализ документов PDF, DOCX, TXT</li>
                    <li><i class="fas fa-check text-success"></i> Полный CRM-функционал</li>
                    <li><i class="fas fa-check text-success"></i> Аналитика и отчёты</li>
                <?php endif; ?>
            </ul>

            <?php if ($isCurrentActive): ?>
                <div class="btn btn-secondary btn-block" style="cursor:default; opacity:0.7;">
                    <i class="fas fa-check"></i> Текущий тариф
                </div>

            <?php elseif ($isFree): ?>
                <?php if ($hasUsedFree): ?>
                    <div class="btn btn-secondary btn-block" style="cursor:not-allowed; opacity:0.6;" title="Тариф Free можно использовать только один раз">
                        <i class="fas fa-lock"></i> Уже использован (1 раз)
                    </div>
                <?php else: ?>
                    <a href="/user/subscription.php?action=activate_free" class="btn btn-success btn-block" style="background:linear-gradient(135deg,#10b981,#059669); color:#fff; font-weight:700; box-shadow:0 4px 14px rgba(16,185,129,0.4); border:none; padding:12px 18px;">
                        <i class="fas fa-gift"></i> Активировать Free
                    </a>
                <?php endif; ?>

            <?php elseif ($hasPending): ?>
                <div class="btn btn-secondary btn-block" style="cursor:default; opacity:0.7; font-size:13px;">
                    <i class="fas fa-clock"></i> Заявка на рассмотрении
                </div>

            <?php else: ?>
                <!-- Форма заявки -->
                <form method="POST">
                    <?= Security::csrfField() ?>
                    <input type="hidden" name="tariff_id" value="<?= $t['id'] ?>">

                    <?php if ($isCustom): ?>
                        <div class="form-group mb-2">
                            <label style="font-size:11px; font-weight:700; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.5px; display:block; text-align:left;">
                                Укажите срок (в днях):
                            </label>
                            <input type="number" id="customDaysInput" name="custom_days" class="form-control text-center"
                                   value="<?= !empty($_GET['days']) ? max(1, min(3650, (int)$_GET['days'])) : 10 ?>" min="1" max="3650" required placeholder="Например: 15"
                                   style="font-size:16px; font-weight:800; background:var(--bg-input); border-color:var(--primary);">
                        </div>
                    <?php endif; ?>

                    <div class="form-group mb-2">
                        <input type="text" name="contact_info" class="form-control"
                               placeholder="Ваш телефон или @telegram" maxlength="200" required>
                        <small class="text-muted" style="font-size:11px;">Как с вами связаться для оплаты?</small>
                    </div>

                    <?php if ($isCustom): ?>
                        <button type="submit" class="btn btn-primary btn-block" id="customSubmitBtn">
                            <i class="fas fa-paper-plane"></i> Заказать на <span id="customBtnDays">10</span> дн.
                        </button>
                    <?php else: ?>
                        <button type="submit" class="btn btn-primary btn-block">
                            <i class="fas fa-paper-plane"></i> Оставить заявку
                        </button>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<!-- Мои заявки -->
<?php if (!empty($myRequests)): ?>
<div class="card mt-4" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:24px;">
    <h3 class="mb-3"><i class="fas fa-list text-secondary"></i> Мои заявки</h3>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Тариф</th>
                    <th>Сумма</th>
                    <th>Статус</th>
                    <th>Контакт</th>
                    <th>Примечание</th>
                    <th>Дата</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($myRequests as $r): ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($r['tariff_name']) ?></strong>
                            <?php if ($r['custom_days']): ?>
                                <small class="text-muted">(<?= $r['custom_days'] ?> дн.)</small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span style="color:#fbbf24; font-weight:700;">
                                <?php if (!empty($r['amount']) && (float)$r['amount'] > 0): ?>
                                    <?= number_format((float)$r['amount'], 0, '.', ' ') ?> ₸
                                <?php elseif (!empty($r['tariff_price']) && (float)$r['tariff_price'] > 0): ?>
                                    <?= number_format((float)$r['tariff_price'], 0, '.', ' ') ?> ₸
                                <?php else: ?>
                                    0 ₸
                                <?php endif; ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($r['status'] === 'pending'): ?>
                                <span class="badge badge-warning">На рассмотрении</span>
                            <?php elseif ($r['status'] === 'approved'): ?>
                                <span class="badge badge-success">Одобрена</span>
                            <?php else: ?>
                                <span class="badge badge-danger">Отклонена</span>
                            <?php endif; ?>
                        </td>
                        <td><small><?= htmlspecialchars($r['contact_info'] ?? '—') ?></small></td>
                        <td><small class="text-muted"><?= htmlspecialchars($r['admin_note'] ?? '—') ?></small></td>
                        <td><small class="text-muted"><?= date('d.m.Y H:i', strtotime($r['created_at'])) ?></small></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<script>
// Автоматический пересчёт правильной суммы для Своего тарифа
function updateCustomPrice() {
    const input = document.getElementById('customDaysInput');
    const priceDisplay = document.getElementById('customPriceDisplay');
    const rateNote = document.getElementById('customRateNote');
    const btnDays = document.getElementById('customBtnDays');
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
        rate = Math.round(2990 / 30); // ~100 ₸/день
        price = Math.round(days * (2990 / 30));
        discountNote = ' (по ставке Start)';
    } else if (days < 365) {
        rate = Math.round(14990 / 180); // ~83 ₸/день
        price = Math.round(days * (14990 / 180));
        discountNote = ' (выгода ~17%)';
    } else {
        rate = Math.round(24990 / 365); // ~68 ₸/день
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
}

document.addEventListener('DOMContentLoaded', function() {
    const input = document.getElementById('customDaysInput');
    if (input) {
        input.addEventListener('input', updateCustomPrice);
        input.addEventListener('change', updateCustomPrice);
        updateCustomPrice();
    }
});
</script>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
