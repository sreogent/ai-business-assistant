<?php
declare(strict_types=1);

/**
 * AI Business Assistant - Admin Tariffs + Discounts + Promocodes Manager
 */

require_once dirname(__DIR__) . '/includes/Auth.php';

$admin   = Auth::requireAdmin();
$pdo     = Database::getConnection();
$adminId = (int)$admin['id'];

$error   = null;
$success = null;

// ─── Авто-миграция колонок скидок в tariffs ─────────────────────────────────
foreach ([
    'discount_percent' => "ALTER TABLE tariffs ADD COLUMN discount_percent INT DEFAULT 0 AFTER price",
    'discount_price'   => "ALTER TABLE tariffs ADD COLUMN discount_price DECIMAL(10,2) NULL AFTER discount_percent",
    'discount_until'   => "ALTER TABLE tariffs ADD COLUMN discount_until DATETIME NULL AFTER discount_price",
    'is_promo'         => "ALTER TABLE tariffs ADD COLUMN is_promo TINYINT(1) DEFAULT 0 AFTER discount_until",
] as $col => $sql) {
    try {
        if (!$pdo->query("SHOW COLUMNS FROM tariffs LIKE '{$col}'")->fetch()) {
            $pdo->exec($sql);
        }
    } catch (\Throwable $e) {}
}

// ─── Авто-создание таблицы промокодов ────────────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS promocodes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(64) NOT NULL UNIQUE,
    discount_percent INT DEFAULT 0,
    discount_amount DECIMAL(10,2) DEFAULT 0.00,
    tariff_id INT NULL,
    max_uses INT DEFAULT 0,
    used_count INT DEFAULT 0,
    expires_at DATETIME NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// ─── Обработка POST ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!Security::verifyCsrf($token)) {
        $error = "Истек токен безопасности. Повторите попытку.";
    } else {
        $action = $_POST['action'] ?? '';

        // ── Создать новый тариф ──────────────────────────────────────────────
        if ($action === 'create') {
            $code    = strtoupper(trim($_POST['code'] ?? ''));
            $name    = trim($_POST['name'] ?? '');
            $price   = (float)($_POST['price'] ?? 0.0);
            $days    = (int)($_POST['duration_days'] ?? 30);
            $aiLimit = (int)($_POST['ai_requests_limit'] ?? 500);

            if (empty($code) || empty($name)) {
                $error = "Код и название тарифа обязательны.";
            } else {
                $pdo->prepare("INSERT INTO tariffs (code, name, price, duration_days, ai_requests_limit, is_active) VALUES (?, ?, ?, ?, ?, 1)")
                    ->execute([$code, $name, $price, $days, $aiLimit]);
                Security::log($pdo, 'admin_create_tariff', "Создан тариф: {$code}", $adminId);
                $success = "Тариф «{$name}» успешно добавлен.";
            }
        }

        // ── Обновить базовые параметры тарифа ───────────────────────────────
        elseif ($action === 'update') {
            $id       = (int)($_POST['tariff_id'] ?? 0);
            $name     = trim($_POST['name'] ?? '');
            $price    = (float)($_POST['price'] ?? 0.0);
            $days     = (int)($_POST['duration_days'] ?? 0);
            $aiLimit  = (int)($_POST['ai_requests_limit'] ?? 0);
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            $pdo->prepare("UPDATE tariffs SET name=?, price=?, duration_days=?, ai_requests_limit=?, is_active=? WHERE id=?")
                ->execute([$name, $price, $days, $aiLimit, $isActive, $id]);
            Security::log($pdo, 'admin_update_tariff', "Обновлен тариф #{$id} ({$name})", $adminId);
            $success = "Параметры тарифа «{$name}» сохранены.";
        }

        // ── Применить / снять скидку на тариф ───────────────────────────────
        elseif ($action === 'apply_discount') {
            $id        = (int)($_POST['tariff_id'] ?? 0);
            $pct       = min(100, max(0, (int)($_POST['discount_percent'] ?? 0)));
            $fixedPrice = !empty($_POST['discount_price']) ? (float)$_POST['discount_price'] : null;
            $until     = trim($_POST['discount_until'] ?? '');
            $untilVal  = $until ? date('Y-m-d H:i:s', strtotime($until)) : null;
            $isPromo   = isset($_POST['is_promo']) ? 1 : 0;

            $pdo->prepare("UPDATE tariffs SET discount_percent=?, discount_price=?, discount_until=?, is_promo=? WHERE id=?")
                ->execute([$pct, $fixedPrice, $untilVal, $isPromo, $id]);

            $tarRow = $pdo->prepare("SELECT name FROM tariffs WHERE id=?");
            $tarRow->execute([$id]);
            $tarName = $tarRow->fetchColumn() ?: "#{$id}";

            Security::log($pdo, 'admin_discount_tariff', "Скидка {$pct}% на тариф «{$tarName}» (до: {$until})", $adminId);
            $success = "Скидка {$pct}% применена к тарифу «{$tarName}».";
        }

        // ── Убрать скидку ────────────────────────────────────────────────────
        elseif ($action === 'clear_discount') {
            $id = (int)($_POST['tariff_id'] ?? 0);
            $pdo->prepare("UPDATE tariffs SET discount_percent=0, discount_price=NULL, discount_until=NULL, is_promo=0 WHERE id=?")
                ->execute([$id]);
            Security::log($pdo, 'admin_clear_discount', "Убрана скидка с тарифа #{$id}", $adminId);
            $success = "Скидка снята.";
        }

        // ── Создать промокод ─────────────────────────────────────────────────
        elseif ($action === 'create_promo') {
            $code    = strtoupper(trim($_POST['promo_code'] ?? ''));
            $pct     = min(100, max(0, (int)($_POST['promo_percent'] ?? 0)));
            $amount  = (float)($_POST['promo_amount'] ?? 0);
            $maxUses = max(0, (int)($_POST['promo_max_uses'] ?? 0));
            $tarId   = !empty($_POST['promo_tariff_id']) ? (int)$_POST['promo_tariff_id'] : null;
            $expires = trim($_POST['promo_expires_at'] ?? '');
            $expiresVal = $expires ? date('Y-m-d H:i:s', strtotime($expires)) : null;

            if (empty($code)) {
                $code = strtoupper(bin2hex(random_bytes(3)));
            }
            try {
                $pdo->prepare("INSERT INTO promocodes (code, discount_percent, discount_amount, tariff_id, max_uses, expires_at) VALUES (?,?,?,?,?,?)")
                    ->execute([$code, $pct, $amount, $tarId, $maxUses, $expiresVal]);
                Security::log($pdo, 'admin_create_promo', "Создан промокод: {$code} ({$pct}%)", $adminId);
                $success = "Промокод <strong>{$code}</strong> создан.";
            } catch (\Throwable $e) {
                $error = "Промокод с таким кодом уже существует.";
            }
        }

        // ── Деактивировать / удалить промокод ───────────────────────────────
        elseif ($action === 'toggle_promo') {
            $promoId = (int)($_POST['promo_id'] ?? 0);
            $pdo->prepare("UPDATE promocodes SET is_active = 1 - is_active WHERE id=?")->execute([$promoId]);
            $success = "Статус промокода изменён.";
        }
        elseif ($action === 'delete_promo') {
            $promoId = (int)($_POST['promo_id'] ?? 0);
            $pdo->prepare("DELETE FROM promocodes WHERE id=?")->execute([$promoId]);
            Security::log($pdo, 'admin_delete_promo', "Удален промокод #{$promoId}", $adminId);
            $success = "Промокод удалён.";
        }

        // ── Удалить тариф ────────────────────────────────────────────────────
        elseif ($action === 'delete') {
            $id = (int)($_POST['tariff_id'] ?? 0);
            $pdo->prepare("DELETE FROM tariffs WHERE id=?")->execute([$id]);
            Security::log($pdo, 'admin_delete_tariff', "Удален тариф #{$id}", $adminId);
            $success = "Тариф удалён.";
        }
    }
}

// ─── Загрузка данных для отображения ────────────────────────────────────────
$tariffs   = $pdo->query("SELECT * FROM tariffs ORDER BY id ASC")->fetchAll();
$promocodes = $pdo->query("SELECT p.*, t.name AS tariff_name FROM promocodes p LEFT JOIN tariffs t ON p.tariff_id = t.id ORDER BY p.id DESC")->fetchAll();

$pageTitle = 'Тарифы и скидки | AI Business Assistant';
require dirname(__DIR__) . '/includes/header.php';
?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:24px; flex-wrap:wrap; gap:16px;">
    <div>
        <h2 style="margin:0; display:flex; align-items:center; gap:10px;">
            <i class="fas fa-tags" style="color:#f59e0b;"></i> Тарифы, скидки и промокоды
        </h2>
        <p class="text-muted" style="margin:4px 0 0;">Управление ценами, спецпредложениями и кодами активации скидок</p>
    </div>
    <div style="display:flex; gap:10px;">
        <button class="btn btn-primary" onclick="document.getElementById('new-tariff-modal').style.display='flex'">
            <i class="fas fa-plus"></i> Новый тариф
        </button>
        <button class="btn btn-warning" onclick="document.getElementById('new-promo-modal').style.display='flex'" style="color:#000;">
            <i class="fas fa-ticket-alt"></i> Промокод
        </button>
    </div>
</div>

<?php if ($success): ?>
    <div class="alert alert-success" style="margin-bottom:16px;"><i class="fas fa-check-circle"></i> <?= $success ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger" style="margin-bottom:16px;"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- ─── Сетка тарифов ─────────────────────────────────────────────────────── -->
<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap:20px; margin-bottom:32px;">
    <?php foreach ($tariffs as $t):
        $hasDiscount = ((int)($t['discount_percent'] ?? 0) > 0) || !empty($t['discount_price']);
        $tariffPrice = (float)$t['price'];
        $discountedPrice = $tariffPrice;
        if ($hasDiscount) {
            if (!empty($t['discount_price'])) {
                $discountedPrice = (float)$t['discount_price'];
            } elseif ((int)($t['discount_percent'] ?? 0) > 0) {
                $discountedPrice = round($tariffPrice * (1 - (int)$t['discount_percent'] / 100), 2);
            }
        }
        $isDiscountExpired = !empty($t['discount_until']) && strtotime($t['discount_until']) < time();
        $discountActive = $hasDiscount && !$isDiscountExpired;
    ?>
    <div class="card" style="padding:0; overflow:hidden; border:<?= $discountActive ? '1.5px solid rgba(245,158,11,0.6)' : '1px solid var(--border-color)' ?>; box-shadow:<?= $discountActive ? '0 0 20px rgba(245,158,11,0.15)' : 'none' ?>;">

        <!-- Шапка карточки -->
        <div style="padding:14px 16px; background:rgba(0,0,0,0.2); border-bottom:1px solid var(--border-color); display:flex; align-items:center; justify-content:space-between; gap:8px; flex-wrap:wrap;">
            <div style="display:flex; align-items:center; gap:8px;">
                <span class="badge" style="background:rgba(99,102,241,0.2); color:#818cf8; font-size:10px; font-weight:800;"><?= htmlspecialchars($t['code']) ?></span>
                <?php if (!$t['is_active']): ?>
                    <span class="badge" style="background:rgba(239,68,68,0.15); color:#f87171; font-size:10px;">Отключен</span>
                <?php else: ?>
                    <span class="badge" style="background:rgba(16,185,129,0.15); color:#34d399; font-size:10px;">Активен</span>
                <?php endif; ?>
                <?php if ($discountActive): ?>
                    <span class="badge" style="background:rgba(245,158,11,0.2); color:#fbbf24; font-size:10px; font-weight:800; animation:blinkLive 1.5s infinite;">
                        🔥 СКИДКА <?= (int)$t['discount_percent'] ?>%
                    </span>
                <?php elseif ($hasDiscount && $isDiscountExpired): ?>
                    <span class="badge" style="background:rgba(100,116,139,0.2); color:#94a3b8; font-size:10px;">Скидка истекла</span>
                <?php endif; ?>
                <?php if ($t['is_promo'] ?? 0): ?>
                    <span class="badge" style="background:rgba(139,92,246,0.2); color:#a78bfa; font-size:10px;">PROMO</span>
                <?php endif; ?>
            </div>
            <div style="font-size:11px; color:var(--text-muted);">#{$t['id']}</div>
        </div>

        <div style="padding:16px;">

            <!-- Блок цены -->
            <div style="display:flex; align-items:baseline; gap:10px; margin-bottom:14px;">
                <?php if ($discountActive): ?>
                    <div>
                        <span style="font-size:26px; font-weight:800; color:#fbbf24;"><?= number_format((float)$discountedPrice, 0, '.', ' ') ?> ₸</span>
                        <div style="font-size:12px; color:#94a3b8; text-decoration:line-through; margin-top:2px;"><?= number_format((float)$t['price'], 0, '.', ' ') ?> ₸</div>
                        <?php if (!empty($t['discount_until'])): ?>
                            <div style="font-size:10px; color:#f59e0b; margin-top:2px;">⏰ Акция до: <?= date('d.m.Y H:i', strtotime($t['discount_until'])) ?></div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <span style="font-size:26px; font-weight:800; color:var(--text-primary);"><?= number_format((float)$t['price'], 0, '.', ' ') ?> ₸</span>
                <?php endif; ?>
                <span style="font-size:12px; color:var(--text-muted);">/ <?= (int)$t['duration_days'] ?> дней</span>
            </div>

            <!-- Форма редактирования тарифа -->
            <form method="POST" id="form-update-<?= $t['id'] ?>">
                <?= Security::csrfField() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="tariff_id" value="<?= $t['id'] ?>">

                <div class="form-group" style="margin-bottom:10px;">
                    <label class="form-label" style="font-size:11px;">Название</label>
                    <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($t['name']) ?>" required style="font-size:13px;">
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:8px; margin-bottom:10px;">
                    <div class="form-group">
                        <label class="form-label" style="font-size:11px;">Цена ₸</label>
                        <input type="number" step="0.01" name="price" class="form-control" value="<?= (float)$t['price'] ?>" required style="font-size:13px;">
                    </div>
                    <div class="form-group">
                        <label class="form-label" style="font-size:11px;">Дней</label>
                        <input type="number" name="duration_days" class="form-control" value="<?= (int)$t['duration_days'] ?>" required style="font-size:13px;">
                    </div>
                    <div class="form-group">
                        <label class="form-label" style="font-size:11px;">AI лимит</label>
                        <input type="number" name="ai_requests_limit" class="form-control" value="<?= (int)$t['ai_requests_limit'] ?>" required style="font-size:13px;">
                    </div>
                </div>
                <div style="display:flex; align-items:center; gap:8px; margin-bottom:12px;">
                    <input type="checkbox" name="is_active" id="active_<?= $t['id'] ?>" <?= $t['is_active'] ? 'checked' : '' ?>>
                    <label for="active_<?= $t['id'] ?>" style="margin:0; font-size:12px; cursor:pointer;">Открыт для клиентов</label>
                </div>
                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                    <button type="submit" class="btn btn-secondary btn-sm" style="font-size:12px;">
                        <i class="fas fa-save"></i> Сохранить
                    </button>
                    <button type="button" class="btn btn-sm" onclick="toggleDiscount(<?= $t['id'] ?>)" style="background:rgba(245,158,11,0.2); color:#fbbf24; border:1px solid rgba(245,158,11,0.4); font-size:12px;">
                        <i class="fas fa-percentage"></i> Скидка
                    </button>
                    <button type="button" class="btn btn-danger btn-sm" onclick="confirmDelete(<?= $t['id'] ?>, '<?= htmlspecialchars(addslashes($t['name'])) ?>')" style="font-size:12px;">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </form>

            <!-- Блок управления скидкой (скрытый) -->
            <div id="discount-panel-<?= $t['id'] ?>" style="display:none; margin-top:14px; padding:14px; background:rgba(245,158,11,0.06); border:1px solid rgba(245,158,11,0.25); border-radius:12px;">
                <div style="font-size:12px; font-weight:700; color:#fbbf24; margin-bottom:10px; display:flex; align-items:center; gap:6px;">
                    <i class="fas fa-fire"></i> Настройка скидки для «<?= htmlspecialchars($t['name']) ?>»
                </div>
                <form method="POST">
                    <?= Security::csrfField() ?>
                    <input type="hidden" name="action" value="apply_discount">
                    <input type="hidden" name="tariff_id" value="<?= $t['id'] ?>">
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:10px;">
                        <div>
                            <label style="font-size:11px; color:var(--text-muted);">Скидка %</label>
                            <input type="number" name="discount_percent" min="0" max="100" class="form-control" value="<?= (int)($t['discount_percent'] ?? 0) ?>" style="font-size:13px;">
                        </div>
                        <div>
                            <label style="font-size:11px; color:var(--text-muted);">Или фиксированная цена ₸</label>
                            <input type="number" name="discount_price" min="0" step="0.01" class="form-control" value="<?= $t['discount_price'] ?? '' ?>" placeholder="оставьте пустым для %" style="font-size:12px;">
                        </div>
                    </div>
                    <div style="margin-bottom:10px;">
                        <label style="font-size:11px; color:var(--text-muted);">Акция действует до</label>
                        <input type="datetime-local" name="discount_until" class="form-control" value="<?= $t['discount_until'] ? date('Y-m-d\TH:i', strtotime($t['discount_until'])) : '' ?>" style="font-size:13px;">
                    </div>
                    <div style="display:flex; align-items:center; gap:8px; margin-bottom:12px;">
                        <input type="checkbox" name="is_promo" id="promo_<?= $t['id'] ?>" <?= ($t['is_promo'] ?? 0) ? 'checked' : '' ?>>
                        <label for="promo_<?= $t['id'] ?>" style="margin:0; font-size:12px; cursor:pointer;">Отметить как «PROMO» акцию</label>
                    </div>
                    <div style="display:flex; gap:8px;">
                        <button type="submit" class="btn btn-warning btn-sm" style="color:#000; font-weight:700; font-size:12px;">
                            <i class="fas fa-fire"></i> Применить скидку
                        </button>
                        <?php if ($hasDiscount): ?>
                        <button type="submit" form="form-clear-<?= $t['id'] ?>" class="btn btn-secondary btn-sm" style="font-size:12px;">
                            <i class="fas fa-times"></i> Убрать скидку
                        </button>
                        <?php endif; ?>
                    </div>
                </form>
                <?php if ($hasDiscount): ?>
                <form method="POST" id="form-clear-<?= $t['id'] ?>" style="display:none;">
                    <?= Security::csrfField() ?>
                    <input type="hidden" name="action" value="clear_discount">
                    <input type="hidden" name="tariff_id" value="<?= $t['id'] ?>">
                </form>
                <?php endif; ?>
            </div>

        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Форма удаления (скрытая) -->
<form method="POST" id="form-delete-tariff" style="display:none;">
    <?= Security::csrfField() ?>
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="tariff_id" id="delete-tariff-id">
</form>

<!-- ─── Раздел промокодов ──────────────────────────────────────────────────── -->
<div class="card" style="margin-bottom:24px;">
    <div style="display:flex; justify-content:space-between; align-items:center; padding:16px 20px; border-bottom:1px solid var(--border-color);">
        <div style="display:flex; align-items:center; gap:10px;">
            <div style="width:36px; height:36px; border-radius:10px; background:rgba(245,158,11,0.2); display:flex; align-items:center; justify-content:center; color:#f59e0b;">
                <i class="fas fa-ticket-alt"></i>
            </div>
            <div>
                <div style="font-weight:700; font-size:14px;">Промокоды и купоны</div>
                <div style="font-size:11px; color:var(--text-muted);">Разовые коды с дополнительными скидками для клиентов</div>
            </div>
        </div>
        <button class="btn btn-warning btn-sm" onclick="document.getElementById('new-promo-modal').style.display='flex'" style="color:#000; font-weight:700;">
            <i class="fas fa-plus"></i> Создать промокод
        </button>
    </div>
    <div style="overflow-x:auto;">
        <?php if (empty($promocodes)): ?>
            <div style="padding:32px; text-align:center; color:var(--text-muted);">
                <i class="fas fa-ticket-alt" style="font-size:32px; opacity:0.3; margin-bottom:10px; display:block;"></i>
                Промокодов пока нет. Нажмите «Создать промокод» чтобы добавить первый.
            </div>
        <?php else: ?>
        <table style="width:100%; border-collapse:collapse; font-size:13px;">
            <thead>
                <tr style="background:rgba(0,0,0,0.2); text-align:left;">
                    <th style="padding:10px 14px; font-size:11px; text-transform:uppercase; color:var(--text-muted); letter-spacing:0.5px;">Код</th>
                    <th style="padding:10px 14px; font-size:11px; text-transform:uppercase; color:var(--text-muted); letter-spacing:0.5px;">Скидка</th>
                    <th style="padding:10px 14px; font-size:11px; text-transform:uppercase; color:var(--text-muted); letter-spacing:0.5px;">Тариф</th>
                    <th style="padding:10px 14px; font-size:11px; text-transform:uppercase; color:var(--text-muted); letter-spacing:0.5px;">Использован</th>
                    <th style="padding:10px 14px; font-size:11px; text-transform:uppercase; color:var(--text-muted); letter-spacing:0.5px;">Истекает</th>
                    <th style="padding:10px 14px; font-size:11px; text-transform:uppercase; color:var(--text-muted); letter-spacing:0.5px;">Статус</th>
                    <th style="padding:10px 14px; font-size:11px; text-transform:uppercase; color:var(--text-muted); letter-spacing:0.5px;">Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($promocodes as $p):
                    $isExpired = !empty($p['expires_at']) && strtotime($p['expires_at']) < time();
                    $isExhausted = $p['max_uses'] > 0 && $p['used_count'] >= $p['max_uses'];
                    $effectiveActive = $p['is_active'] && !$isExpired && !$isExhausted;
                ?>
                <tr style="border-bottom:1px solid var(--border-color);">
                    <td style="padding:10px 14px;">
                        <code style="background:rgba(245,158,11,0.15); color:#fbbf24; padding:3px 8px; border-radius:6px; font-size:13px; font-weight:700; letter-spacing:1px;"><?= htmlspecialchars($p['code']) ?></code>
                    </td>
                    <td style="padding:10px 14px;">
                        <?php if ($p['discount_percent'] > 0): ?>
                            <span style="color:#10b981; font-weight:700;">-<?= (int)$p['discount_percent'] ?>%</span>
                        <?php endif; ?>
                        <?php if ($p['discount_amount'] > 0): ?>
                            <span style="color:#10b981; font-weight:700;">-<?= number_format((float)$p['discount_amount'], 0, '.', ' ') ?> ₸</span>
                        <?php endif; ?>
                    </td>
                    <td style="padding:10px 14px; color:var(--text-muted);">
                        <?= $p['tariff_name'] ? htmlspecialchars($p['tariff_name']) : '<em>Любой</em>' ?>
                    </td>
                    <td style="padding:10px 14px; color:var(--text-muted);">
                        <?= (int)$p['used_count'] ?><?= $p['max_uses'] > 0 ? ' / ' . (int)$p['max_uses'] : '' ?>
                        <?php if ($isExhausted): ?>
                            <span style="color:#ef4444; font-size:11px;"> Исчерпан</span>
                        <?php endif; ?>
                    </td>
                    <td style="padding:10px 14px; font-size:12px; color:var(--text-muted);">
                        <?= $p['expires_at'] ? date('d.m.Y H:i', strtotime($p['expires_at'])) : '∞' ?>
                        <?php if ($isExpired): ?>
                            <div style="color:#ef4444; font-size:10px;">Истёк</div>
                        <?php endif; ?>
                    </td>
                    <td style="padding:10px 14px;">
                        <span class="badge" style="background:<?= $effectiveActive ? 'rgba(16,185,129,0.2)' : 'rgba(239,68,68,0.15)' ?>; color:<?= $effectiveActive ? '#34d399' : '#f87171' ?>; font-size:11px;">
                            <?= $effectiveActive ? 'Активен' : 'Неактивен' ?>
                        </span>
                    </td>
                    <td style="padding:10px 14px;">
                        <div style="display:flex; gap:6px;">
                            <form method="POST" style="display:inline;">
                                <?= Security::csrfField() ?>
                                <input type="hidden" name="action" value="toggle_promo">
                                <input type="hidden" name="promo_id" value="<?= $p['id'] ?>">
                                <button type="submit" class="btn btn-secondary btn-sm" style="font-size:11px; padding:4px 8px;" title="<?= $p['is_active'] ? 'Деактивировать' : 'Активировать' ?>">
                                    <i class="fas fa-<?= $p['is_active'] ? 'pause' : 'play' ?>"></i>
                                </button>
                            </form>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Удалить промокод <?= htmlspecialchars(addslashes($p['code'])) ?>?')">
                                <?= Security::csrfField() ?>
                                <input type="hidden" name="action" value="delete_promo">
                                <input type="hidden" name="promo_id" value="<?= $p['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm" style="font-size:11px; padding:4px 8px;">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<!-- ─── Модалка: Новый тариф ──────────────────────────────────────────────── -->
<div id="new-tariff-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.75); z-index:9999; justify-content:center; align-items:center; padding:16px;">
    <div class="card" style="max-width:480px; width:100%; border:1px solid var(--border-color);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
            <h3 style="margin:0; font-size:16px; font-weight:700;"><i class="fas fa-plus" style="color:var(--primary);"></i> Новый тарифный план</h3>
            <button type="button" onclick="document.getElementById('new-tariff-modal').style.display='none'" style="background:none; border:none; font-size:20px; color:var(--text-muted); cursor:pointer;">&times;</button>
        </div>
        <form method="POST">
            <?= Security::csrfField() ?>
            <input type="hidden" name="action" value="create">
            <div class="form-group" style="margin-bottom:12px;">
                <label class="form-label" style="font-size:12px;">Системный код (лат.) *</label>
                <input type="text" name="code" class="form-control" required placeholder="BUSINESS_30" style="text-transform:uppercase;">
            </div>
            <div class="form-group" style="margin-bottom:12px;">
                <label class="form-label" style="font-size:12px;">Отображаемое название *</label>
                <input type="text" name="name" class="form-control" required placeholder="Бизнес-30">
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:10px; margin-bottom:12px;">
                <div class="form-group">
                    <label class="form-label" style="font-size:12px;">Цена ₸</label>
                    <input type="number" step="0.01" name="price" class="form-control" required placeholder="9990">
                </div>
                <div class="form-group">
                    <label class="form-label" style="font-size:12px;">Дней</label>
                    <input type="number" name="duration_days" class="form-control" required placeholder="30">
                </div>
                <div class="form-group">
                    <label class="form-label" style="font-size:12px;">AI лимит</label>
                    <input type="number" name="ai_requests_limit" class="form-control" required placeholder="500">
                </div>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%;"><i class="fas fa-plus"></i> Создать тариф</button>
        </form>
    </div>
</div>

<!-- ─── Модалка: Создать промокод ────────────────────────────────────────── -->
<div id="new-promo-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.75); z-index:9999; justify-content:center; align-items:center; padding:16px;">
    <div class="card" style="max-width:500px; width:100%; border:1px solid var(--border-color);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
            <h3 style="margin:0; font-size:16px; font-weight:700;"><i class="fas fa-ticket-alt" style="color:#f59e0b;"></i> Создать промокод</h3>
            <button type="button" onclick="document.getElementById('new-promo-modal').style.display='none'" style="background:none; border:none; font-size:20px; color:var(--text-muted); cursor:pointer;">&times;</button>
        </div>
        <form method="POST">
            <?= Security::csrfField() ?>
            <input type="hidden" name="action" value="create_promo">
            <div class="form-group" style="margin-bottom:12px;">
                <label class="form-label" style="font-size:12px;">Код промокода (оставьте пустым для автогенерации)</label>
                <input type="text" name="promo_code" class="form-control" placeholder="SUMMER25 или пусто" style="text-transform:uppercase;">
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:12px;">
                <div class="form-group">
                    <label class="form-label" style="font-size:12px;">Скидка %</label>
                    <input type="number" name="promo_percent" class="form-control" min="0" max="100" placeholder="15" value="0">
                </div>
                <div class="form-group">
                    <label class="form-label" style="font-size:12px;">Или скидка ₸</label>
                    <input type="number" name="promo_amount" class="form-control" min="0" placeholder="1000" value="0">
                </div>
            </div>
            <div class="form-group" style="margin-bottom:12px;">
                <label class="form-label" style="font-size:12px;">Применимый тариф (оставьте пустым = любой)</label>
                <select name="promo_tariff_id" class="form-control">
                    <option value="">— Любой тариф —</option>
                    <?php foreach ($tariffs as $t): ?>
                        <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?> (<?= number_format((float)$t['price'], 0, '.', ' ') ?> ₸)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:12px;">
                <div class="form-group">
                    <label class="form-label" style="font-size:12px;">Макс. использований (0 = ∞)</label>
                    <input type="number" name="promo_max_uses" class="form-control" min="0" value="0">
                </div>
                <div class="form-group">
                    <label class="form-label" style="font-size:12px;">Действует до</label>
                    <input type="datetime-local" name="promo_expires_at" class="form-control">
                </div>
            </div>
            <button type="submit" class="btn btn-warning" style="width:100%; color:#000; font-weight:700;">
                <i class="fas fa-ticket-alt"></i> Создать промокод
            </button>
        </form>
    </div>
</div>

<script>
function toggleDiscount(id) {
    const panel = document.getElementById('discount-panel-' + id);
    panel.style.display = (panel.style.display === 'none' ? 'block' : 'none');
}
function confirmDelete(id, name) {
    if (confirm('Удалить тариф «' + name + '»? Это действие необратимо.')) {
        document.getElementById('delete-tariff-id').value = id;
        document.getElementById('form-delete-tariff').submit();
    }
}
</script>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
