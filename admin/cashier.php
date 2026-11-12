<?php
declare(strict_types=1);

/**
 * AI Business Assistant - POS Cashier & Checkout (Кассовый терминал)
 * Онлайн-касса для розничных продаж, ресторанов и услуг с чеками
 */

require_once dirname(__DIR__) . '/includes/Auth.php';

$admin = Auth::requireAdmin();
$pdo = Database::getConnection();
$adminId = (int)$admin['id'];
$currentRole = $admin['role_name'] ?? 'admin';

// Переключатель роли для проверки прав в кассе (Админ / Директор / Менеджер / Кассир)
if (isset($_GET['as_role'])) {
    $allowedRoles = ['admin', 'director', 'manager', 'user'];
    if (in_array($_GET['as_role'], $allowedRoles, true)) {
        if ($_GET['as_role'] === 'user' || $_GET['as_role'] === 'admin') {
            $_SESSION['pos_simulate_role'] = $_GET['as_role'];
        }
    }
}
$activePosRole = $_SESSION['pos_simulate_role'] ?? $currentRole;

// Таблицы заказов и чеков
$pdo->exec("CREATE TABLE IF NOT EXISTS cash_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL,
    cashier_id INT NOT NULL,
    order_number VARCHAR(64) NOT NULL,
    total DECIMAL(12,2) NOT NULL DEFAULT 0,
    discount DECIMAL(12,2) NOT NULL DEFAULT 0,
    final_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    payment_method ENUM('kaspi', 'cash', 'card', 'transfer') NOT NULL DEFAULT 'kaspi',
    status ENUM('paid', 'cancelled', 'refunded') NOT NULL DEFAULT 'paid',
    customer_note TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_co_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
    CONSTRAINT fk_co_cashier FOREIGN KEY (cashier_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS cash_order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    item_id INT NULL,
    item_name VARCHAR(255) NOT NULL,
    price DECIMAL(12,2) NOT NULL,
    quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
    subtotal DECIMAL(12,2) NOT NULL,
    CONSTRAINT fk_coi_order FOREIGN KEY (order_id) REFERENCES cash_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Инициализация корзины в сессии
if (!isset($_SESSION['pos_cart'])) {
    $_SESSION['pos_cart'] = [];
}

$error = null;
$success = null;
$printedOrder = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!Security::verifyCsrf($token)) {
        $error = "Истёк токен безопасности. Повторите попытку.";
    } else {
        $action = $_POST['action'] ?? '';

        // 1. Добавить товар в чек
        if ($action === 'add_to_cart') {
            $itemId = (int)($_POST['item_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT id, name, price, unit FROM menu_items WHERE id = ? AND is_active = 1");
            $stmt->execute([$itemId]);
            $item = $stmt->fetch();

            if ($item) {
                if (isset($_SESSION['pos_cart'][$itemId])) {
                    $_SESSION['pos_cart'][$itemId]['qty'] += 1;
                } else {
                    $_SESSION['pos_cart'][$itemId] = [
                        'id'    => $item['id'],
                        'name'  => $item['name'],
                        'price' => (float)$item['price'],
                        'unit'  => $item['unit'] ?: 'шт',
                        'qty'   => 1.0,
                    ];
                }
            }
        }

        // 2. Обновить количество
        elseif ($action === 'update_qty') {
            $itemId = (int)($_POST['item_id'] ?? 0);
            $qty    = (float)($_POST['qty'] ?? 1);
            if (isset($_SESSION['pos_cart'][$itemId])) {
                if ($qty <= 0) {
                    unset($_SESSION['pos_cart'][$itemId]);
                } else {
                    $_SESSION['pos_cart'][$itemId]['qty'] = $qty;
                }
            }
        }

        // 3. Удалить позицию из чека
        elseif ($action === 'remove_item') {
            $itemId = (int)($_POST['item_id'] ?? 0);
            unset($_SESSION['pos_cart'][$itemId]);
        }

        // 4. Очистить корзину чека
        elseif ($action === 'clear_cart') {
            $_SESSION['pos_cart'] = [];
        }

        // 5. Пробить чек / Провести оплату
        elseif ($action === 'checkout') {
            if (empty($_SESSION['pos_cart'])) {
                $error = "Чек пуст. Добавьте товары из меню.";
            } else {
                $orgId         = !empty($_POST['org_id']) ? (int)$_POST['org_id'] : null;
                $paymentMethod = $_POST['payment_method'] ?? 'kaspi';
                $discount      = max(0, (float)($_POST['discount'] ?? 0));
                $note          = trim($_POST['customer_note'] ?? '');

                // Считаем сумму
                $subtotal = 0.0;
                foreach ($_SESSION['pos_cart'] as $cItem) {
                    $subtotal += ($cItem['price'] * $cItem['qty']);
                }

                $finalAmount = max(0, $subtotal - $discount);
                $orderNumber = 'CHK-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

                $pdo->beginTransaction();
                try {
                    $orderStmt = $pdo->prepare("
                        INSERT INTO cash_orders 
                        (organization_id, cashier_id, order_number, total, discount, final_amount, payment_method, status, customer_note)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'paid', ?)
                    ");
                    $orderStmt->execute([$orgId, $adminId, $orderNumber, $subtotal, $discount, $finalAmount, $paymentMethod, $note]);
                    $orderId = (int)$pdo->lastInsertId();

                    $itemStmt = $pdo->prepare("
                        INSERT INTO cash_order_items (order_id, item_id, item_name, price, quantity, subtotal)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    foreach ($_SESSION['pos_cart'] as $cItem) {
                        $itemSub = $cItem['price'] * $cItem['qty'];
                        $itemStmt->execute([$orderId, $cItem['id'], $cItem['name'], $cItem['price'], $cItem['qty'], $itemSub]);
                    }

                    $pdo->commit();

                    Security::log($pdo, 'pos_sale', "Пробит чек {$orderNumber} на сумму " . number_format($finalAmount, 0, '.', ' ') . " ₸", $adminId);

                    // Сохраняем данные для отображения чека
                    $printedOrder = [
                        'id'           => $orderId,
                        'order_number' => $orderNumber,
                        'total'        => $subtotal,
                        'discount'     => $discount,
                        'final_amount' => $finalAmount,
                        'payment'      => $paymentMethod,
                        'items'        => $_SESSION['pos_cart'],
                        'date'         => date('d.m.Y H:i:s'),
                    ];

                    $_SESSION['pos_cart'] = [];
                    $success = "🎉 Чек <strong>{$orderNumber}</strong> успешно проведён! Сумма: " . number_format($finalAmount, 0, '.', ' ') . " ₸";
                } catch (\Throwable $e) {
                    $pdo->rollBack();
                    $error = "Ошибка при проведении чека: " . $e->getMessage();
                }
            }
        }

        // Установка / смена пароля директора
        if ($action === 'set_director_password') {
            $newPassword = trim($_POST['director_password'] ?? '');
            if (empty($newPassword)) {
                $error = "Пароль директора не может быть пустым.";
            } else {
                Auth::setDirectorPosPassword($newPassword);
                Security::log($pdo, 'set_director_pos_password', "Пароль директора сохранен администратором #{$adminId}", $adminId);
                $success = "🔑 Пароль директора успешно сохранён! Теперь переключение на роль Менеджера и Директора защищено паролем.";
            }
        }

        // Переключение роли с проверкой пароля директора
        elseif ($action === 'switch_pos_role') {
            $targetRole = trim($_POST['target_role'] ?? 'admin');
            $enteredPassword = (string)($_POST['director_password'] ?? '');

            if ($targetRole === 'user' || $targetRole === 'admin') {
                $_SESSION['pos_simulate_role'] = $targetRole;
                $success = "Режим кассы изменён на: " . ($targetRole === 'admin' ? 'Администратор' : 'Кассир');
            } elseif (in_array($targetRole, ['manager', 'director'], true)) {
                if (!Auth::isDirectorPosPasswordSet()) {
                    $error = "⚠️ Пароль директора ещё не установлен! Сначала задайте пароль директора.";
                } elseif (!Auth::verifyDirectorPosPassword($enteredPassword)) {
                    $error = "⛔ Неверный пароль директора! Доступ к роли «" . ($targetRole === 'director' ? 'Директор' : 'Менеджер') . "» отклонён.";
                    Security::log($pdo, 'failed_director_password_admin', "Неверный ввод пароля директора при переходе в режим {$targetRole} админом #{$adminId}", $adminId);
                } else {
                    $_SESSION['pos_simulate_role'] = $targetRole;
                    $success = "🔓 Пароль директора подтверждён! Успешный вход под ролью: <strong>" . ($targetRole === 'director' ? 'Генеральный Директор' : 'Менеджер кассы') . "</strong>.";
                }
            }
        }

        // 6. Удалить чек (Менеджер и выше: manager, director, admin, etc.)
        elseif ($action === 'delete_order') {
            $orderId = (int)($_POST['order_id'] ?? 0);
            $userRole = $_SESSION['pos_simulate_role'] ?? $currentRole;

            $allowedToDelete = in_array($userRole, ['manager', 'director', 'admin', 'vrio_owner', 'owner', 'supreme_owner', 'developer'], true);

            if (!$allowedToDelete) {
                $error = "⛔ Доступ запрещён: Удалять чеки имеют право ТОЛЬКО сотрудники с ролью Менеджер и выше (ваша текущая роль: {$userRole}).";
            } else {
                $oStmt = $pdo->prepare("SELECT order_number, final_amount FROM cash_orders WHERE id = ?");
                $oStmt->execute([$orderId]);
                $ord = $oStmt->fetch();

                if ($ord) {
                    $pdo->prepare("DELETE FROM cash_order_items WHERE order_id = ?")->execute([$orderId]);
                    $pdo->prepare("DELETE FROM cash_orders WHERE id = ?")->execute([$orderId]);
                    Security::log($pdo, 'pos_order_deleted', "Чек {$ord['order_number']} на сумму {$ord['final_amount']} ₸ удалён пользователем #{$adminId} (роль: {$userRole})", $adminId);
                    $success = "🗑️ Чек <strong>{$ord['order_number']}</strong> успешно удалён из базы данных.";
                } else {
                    $error = "Чек не найден.";
                }
            }
        }

        // 7. Провести возврат средств (Менеджер и выше)
        elseif ($action === 'refund_order') {
            $orderId = (int)($_POST['order_id'] ?? 0);
            $reason  = trim($_POST['refund_reason'] ?? 'Возврат по запросу клиента');
            $userRole = $_SESSION['pos_simulate_role'] ?? $currentRole;

            $allowedToRefund = in_array($userRole, ['manager', 'director', 'admin', 'vrio_owner', 'owner', 'supreme_owner', 'developer'], true);

            if (!$allowedToRefund) {
                $error = "⛔ Доступ запрещён: Провести возврат по чеку имеют право ТОЛЬКО сотрудники с ролью Менеджер и выше (ваша текущая роль: {$userRole}).";
            } else {
                $oStmt = $pdo->prepare("SELECT order_number, final_amount, status FROM cash_orders WHERE id = ?");
                $oStmt->execute([$orderId]);
                $ord = $oStmt->fetch();

                if (!$ord) {
                    $error = "Чек не найден.";
                } elseif ($ord['status'] === 'refunded') {
                    $error = "По этому чеку возврат средств уже был проведён ранее.";
                } else {
                    $refStmt = $pdo->prepare("
                        UPDATE cash_orders 
                        SET status = 'refunded', refund_by = ?, refund_at = NOW(), refund_reason = ?
                        WHERE id = ?
                    ");
                    $refStmt->execute([$adminId, $reason, $orderId]);

                    Security::log($pdo, 'pos_order_refunded', "Возврат чека {$ord['order_number']} на сумму {$ord['final_amount']} ₸ проведён #{$adminId} (роль: {$userRole}). Причина: {$reason}", $adminId);
                    $success = "↩️ Возврат по чеку <strong>{$ord['order_number']}</strong> успешно проведён! Статус изменён на «Возврат».";
                }
            }
        }
    }
}

// Загрузка списков
require_once dirname(__DIR__) . '/includes/OrganizationGenerator.php';

$filterOrgId = !empty($_GET['org_id']) ? (int)$_GET['org_id'] : 0;
$organizations = $pdo->query("SELECT * FROM organizations WHERE is_active = 1 ORDER BY name ASC")->fetchAll();

$currentOrg = null;
if ($filterOrgId > 0) {
    foreach ($organizations as $o) {
        if ((int)$o['id'] === $filterOrgId) {
            $currentOrg = $o;
            break;
        }
    }
}
$bType = $currentOrg['business_type'] ?? 'general';
$bTypes = OrganizationGenerator::getBusinessTypes();
$meta = $bTypes[$bType] ?? $bTypes['general'];
$isService = ($meta['item_type'] === 'services');

if ($filterOrgId > 0) {
    $catStmt = $pdo->prepare("SELECT * FROM menu_categories WHERE (organization_id = ? OR organization_id IS NULL) AND is_active = 1 ORDER BY sort_order ASC, name ASC");
    $catStmt->execute([$filterOrgId]);
    $categories = $catStmt->fetchAll();
} else {
    $categories = $pdo->query("SELECT * FROM menu_categories WHERE is_active = 1 ORDER BY sort_order ASC, name ASC")->fetchAll();
}

$selectedCategory = !empty($_GET['category']) ? (int)$_GET['category'] : 0;
$itemSql = "SELECT * FROM menu_items WHERE is_active = 1";
$itemParams = [];
if ($filterOrgId > 0) {
    $itemSql .= " AND (organization_id = ? OR organization_id IS NULL)";
    $itemParams[] = $filterOrgId;
}
if ($selectedCategory > 0) {
    $itemSql .= " AND category_id = ?";
    $itemParams[] = $selectedCategory;
}
$itemSql .= " ORDER BY name ASC";
$itemsStmt = $pdo->prepare($itemSql);
$itemsStmt->execute($itemParams);
$items = $itemsStmt->fetchAll();

// Статистика кассы за сегодня
$todayStats = $pdo->query("
    SELECT 
        COUNT(*) as total_orders,
        COALESCE(SUM(final_amount), 0) as total_revenue
    FROM cash_orders 
    WHERE DATE(created_at) = CURDATE() AND status = 'paid'
")->fetch();

// Последние 15 пробитых чеков
$recentOrders = $pdo->query("
    SELECT co.*, u.first_name, u.last_name, o.name AS org_name
    FROM cash_orders co
    JOIN users u ON co.cashier_id = u.id
    LEFT JOIN organizations o ON co.organization_id = o.id
    ORDER BY co.id DESC 
    LIMIT 15
")->fetchAll();

// Подсчёт корзины
$cartTotal = 0.0;
$cartItemsCount = 0;
foreach ($_SESSION['pos_cart'] as $ci) {
    $cartTotal += ($ci['price'] * $ci['qty']);
    $cartItemsCount += $ci['qty'];
}

$pageTitle = 'Кассовый терминал (POS) | AI Business Assistant';
require dirname(__DIR__) . '/includes/header.php';
?>

<!-- Предупреждение, если пароль директора ещё не установлен -->
<?php if (!Auth::isDirectorPosPasswordSet()): ?>
    <div class="alert alert-warning" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:20px; border-radius:var(--radius-lg); padding:16px 20px;">
        <div style="display:flex; align-items:center; gap:12px;">
            <i class="fas fa-exclamation-triangle" style="font-size:22px; color:#f59e0b;"></i>
            <div>
                <strong style="color:var(--text-primary); font-size:15px;">Пароль директора не установлен!</strong>
                <p style="margin:2px 0 0; color:var(--text-muted); font-size:13px;">
                    Установите пароль директора для защиты смены ролей на «Менеджер» и «Директор», а также для удаления и возврата чеков.
                </p>
            </div>
        </div>
        <button type="button" class="btn btn-warning btn-sm" onclick="openSetDirectorPasswordModal()" style="font-weight:700;">
            <i class="fas fa-key"></i> Установить пароль директора
        </button>
    </div>
<?php endif; ?>

<div style="margin-bottom:20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
    <div>
        <h2><i class="fas fa-cash-register text-primary"></i> Касса / POS-терминал</h2>
        <p class="text-muted" style="margin-bottom:8px;">Оформление продаж, приём оплаты через Kaspi / Карты / Наличные, фискальные чеки</p>
        
        <!-- Панель проверки прав и ролей (Админ / Директор / Менеджер / Кассир) -->
        <div style="display:inline-flex; align-items:center; gap:8px; background:var(--bg-secondary); border:1px solid var(--border-color); border-radius:20px; padding:4px 12px; font-size:12px; flex-wrap:wrap;">
            <span class="text-muted"><i class="fas fa-user-shield"></i> Активная роль в кассе:</span>
            <?php
            $roleBadges = [
                'admin'    => ['title' => 'Администратор сайта', 'color' => '#8b5cf6', 'perms' => 'Удаление чеков и возврат'],
                'director' => ['title' => 'Генеральный Директор', 'color' => '#3b82f6', 'perms' => 'Удаление чеков и возврат'],
                'manager'  => ['title' => 'Менеджер кассы',       'color' => '#f59e0b', 'perms' => 'Удаление чеков и возврат'],
                'user'     => ['title' => 'Кассир',               'color' => '#10b981', 'perms' => 'Только продажа']
            ];
            $currentBadge = $roleBadges[$activePosRole] ?? $roleBadges['admin'];
            ?>
            <strong style="color:<?= $currentBadge['color'] ?>;"><?= $currentBadge['title'] ?></strong>
            <small style="color:var(--text-muted);">— <?= $currentBadge['perms'] ?></small>
            
            <div style="display:inline-flex; gap:4px; margin-left:8px; align-items:center;">
                <form method="POST" style="margin:0; display:inline;">
                    <?= Security::csrfField() ?>
                    <input type="hidden" name="action" value="switch_pos_role">
                    <input type="hidden" name="target_role" value="admin">
                    <button type="submit" class="btn btn-sm" style="padding:2px 8px; font-size:11px; font-weight:700; border-radius:12px; <?= $activePosRole==='admin' ? 'background:#8b5cf6;color:#fff;' : 'background:transparent;border:1px solid var(--border-color);' ?>">Админ</button>
                </form>

                <button type="button" onclick="openRolePasswordModal('director', 'Генеральный Директор')" class="btn btn-sm" style="padding:2px 8px; font-size:11px; font-weight:700; border-radius:12px; <?= $activePosRole==='director' ? 'background:#3b82f6;color:#fff;' : 'background:transparent;border:1px solid var(--border-color);' ?>" title="Требуется пароль директора">
                    <i class="fas fa-lock" style="font-size:9px;"></i> Директор
                </button>

                <button type="button" onclick="openRolePasswordModal('manager', 'Менеджер кассы')" class="btn btn-sm" style="padding:2px 8px; font-size:11px; font-weight:700; border-radius:12px; <?= $activePosRole==='manager' ? 'background:#f59e0b;color:#000;' : 'background:transparent;border:1px solid var(--border-color);' ?>" title="Требуется пароль директора">
                    <i class="fas fa-lock" style="font-size:9px;"></i> Менеджер
                </button>

                <form method="POST" style="margin:0; display:inline;">
                    <?= Security::csrfField() ?>
                    <input type="hidden" name="action" value="switch_pos_role">
                    <input type="hidden" name="target_role" value="user">
                    <button type="submit" class="btn btn-sm" style="padding:2px 8px; font-size:11px; font-weight:700; border-radius:12px; <?= $activePosRole==='user' ? 'background:#10b981;color:#fff;' : 'background:transparent;border:1px solid var(--border-color);' ?>">Кассир</button>
                </form>
            </div>
        </div>
    </div>
    <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
        <button type="button" class="btn btn-secondary btn-sm" onclick="openSetDirectorPasswordModal()" title="Настройка пароля директора">
            <i class="fas fa-key text-warning"></i> <?= Auth::isDirectorPosPasswordSet() ? 'Пароль директора' : 'Задать пароль директора' ?>
        </button>
        <div style="background:var(--bg-card); border:1px solid var(--border-color); padding:10px 18px; border-radius:var(--radius-lg); text-align:right;">
            <small class="text-muted">Выручка за сегодня:</small><br>
            <strong style="color:#10b981; font-size:18px;"><?= number_format((float)$todayStats['total_revenue'], 0, '.', ' ') ?> ₸</strong>
            <small class="text-muted">(<?= (int)$todayStats['total_orders'] ?> чеков)</small>
        </div>
        <a href="/admin/menu_items.php" class="btn btn-secondary">
            <i class="fas fa-utensils"></i> Редактор меню
        </a>
    </div>
</div>

<?php if ($success): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $success ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Модальный / Печатный Чек после оплаты -->
<?php if ($printedOrder): ?>
<div class="card mb-4" style="background:#111827; border:2px dashed #10b981; border-radius:var(--radius-lg); padding:24px; max-width:440px; margin:0 auto 24px auto; font-family:monospace;">
    <div style="text-align:center; border-bottom:1px dashed #374151; padding-bottom:12px; margin-bottom:12px;">
        <h3 style="margin:0; font-size:18px; color:#f9fafb;">AI BUSINESS ASSISTANT</h3>
        <p style="margin:4px 0 0; color:#9ca3af; font-size:12px;">ТОВАРНЫЙ ЧЕК № <?= htmlspecialchars($printedOrder['order_number']) ?></p>
        <p style="margin:2px 0 0; color:#6b7280; font-size:11px;"><?= $printedOrder['date'] ?></p>
    </div>

    <table style="width:100%; font-size:12px; margin-bottom:12px;">
        <thead>
            <tr style="border-bottom:1px solid #374151; color:#9ca3af;">
                <th style="text-align:left; padding:4px 0;">Наименование</th>
                <th style="text-align:center;">Кол-во</th>
                <th style="text-align:right;">Сумма</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($printedOrder['items'] as $pi): ?>
                <tr>
                    <td style="padding:4px 0; color:#f9fafb;"><?= htmlspecialchars($pi['name']) ?></td>
                    <td style="text-align:center; color:#9ca3af;"><?= $pi['qty'] ?></td>
                    <td style="text-align:right; color:#f9fafb;"><?= number_format($pi['price'] * $pi['qty'], 0, '.', ' ') ?> ₸</td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div style="border-top:1px dashed #374151; padding-top:10px; font-size:13px;">
        <?php if ($printedOrder['discount'] > 0): ?>
            <div style="display:flex; justify-content:space-between; color:#ef4444; margin-bottom:4px;">
                <span>Скидка:</span>
                <span>-<?= number_format($printedOrder['discount'], 0, '.', ' ') ?> ₸</span>
            </div>
        <?php endif; ?>
        <div style="display:flex; justify-content:space-between; font-size:16px; font-weight:bold; color:#10b981;">
            <span>ИТОГО К ОПЛАТЕ:</span>
            <span><?= number_format($printedOrder['final_amount'], 0, '.', ' ') ?> ₸</span>
        </div>
        <div style="display:flex; justify-content:space-between; color:#9ca3af; margin-top:6px; font-size:11px;">
            <span>Способ оплаты:</span>
            <span><?= strtoupper($printedOrder['payment']) ?></span>
        </div>
    </div>

    <div style="text-align:center; margin-top:16px; border-top:1px dashed #374151; padding-top:12px;">
        <button onclick="window.print()" class="btn btn-success btn-sm">
            <i class="fas fa-print"></i> Распечатать чек
        </button>
    </div>
</div>
<?php endif; ?>

<div style="display:grid; grid-template-columns: 2fr 1.2fr; gap:24px; align-items:start;">

    <!-- ЛЕВАЯ ЧАСТЬ: Витрина меню и выбор блюд -->
    <div>
        <!-- Фильтры категорий -->
        <div style="display:flex; gap:8px; overflow-x:auto; padding-bottom:12px; margin-bottom:16px;">
            <a href="/admin/cashier.php" class="btn btn-sm <?= $selectedCategory === 0 ? 'btn-primary' : 'btn-secondary' ?>">
                <i class="fas fa-th-large"></i> Все (<?= count($items) ?>)
            </a>
            <?php foreach ($categories as $cat): ?>
                <a href="/admin/cashier.php?category=<?= $cat['id'] ?>" class="btn btn-sm <?= $selectedCategory === (int)$cat['id'] ? 'btn-primary' : 'btn-secondary' ?>">
                    <?= htmlspecialchars($cat['name']) ?>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Сетка товаров меню -->
        <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap:14px;">
            <?php if (empty($items)): ?>
                <div class="card" style="grid-column: 1/-1; padding:32px; text-align:center; background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg);">
                    <p class="text-muted" style="margin:0;">В этой категории пока нет товаров. <a href="/admin/menu_items.php" style="color:var(--primary);">Добавить товары в меню</a></p>
                </div>
            <?php else: ?>
                <?php foreach ($items as $it): ?>
                    <form method="POST" style="margin:0;">
                        <?= Security::csrfField() ?>
                        <input type="hidden" name="action" value="add_to_cart">
                        <input type="hidden" name="item_id" value="<?= $it['id'] ?>">

                        <button type="submit" style="width:100%; text-align:left; background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:16px; cursor:pointer; color:inherit; transition:all 0.2s;" onmouseover="this.style.borderColor='var(--primary)'" onmouseout="this.style.borderColor='var(--border-color)'">
                            <div style="font-weight:700; font-size:15px; margin-bottom:6px; color:var(--text-primary);">
                                <?= htmlspecialchars($it['name']) ?>
                            </div>
                            <?php if (!empty($it['description'])): ?>
                                <div style="font-size:11px; color:var(--text-muted); margin-bottom:10px; height:28px; overflow:hidden;">
                                    <?= htmlspecialchars($it['description']) ?>
                                </div>
                            <?php endif; ?>
                            <div style="display:flex; justify-content:space-between; align-items:center;">
                                <strong style="color:#10b981; font-size:16px;">
                                    <?= number_format((float)$it['price'], 0, '.', ' ') ?> ₸
                                </strong>
                                <span style="background:var(--primary); color:#fff; border-radius:50%; width:28px; height:28px; display:inline-flex; align-items:center; justify-content:center; font-size:12px;">
                                    <i class="fas fa-plus"></i>
                                </span>
                            </div>
                        </button>
                    </form>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- ПРАВАЯ ЧАСТЬ: Корзина чека и оплата -->
    <div class="card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:24px; position:sticky; top:20px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; border-bottom:1px solid var(--border-color); padding-bottom:12px;">
            <h3 style="margin:0;"><i class="fas fa-shopping-basket text-primary"></i> Текущий чек</h3>
            <?php if (!empty($_SESSION['pos_cart'])): ?>
                <form method="POST" style="margin:0;" onsubmit="return confirm('Очистить весь чек?')">
                    <?= Security::csrfField() ?>
                    <input type="hidden" name="action" value="clear_cart">
                    <button type="submit" class="btn btn-secondary btn-sm" style="font-size:11px;">
                        <i class="fas fa-trash"></i> Очистить
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <!-- Список позиций в чеке -->
        <div style="max-height:300px; overflow-y:auto; margin-bottom:16px;">
            <?php if (empty($_SESSION['pos_cart'])): ?>
                <div style="text-align:center; padding:32px 10px; color:var(--text-muted);">
                    <i class="fas fa-shopping-cart" style="font-size:32px; opacity:0.3; margin-bottom:8px;"></i>
                    <p style="margin:0; font-size:14px;">Чек пуст. Нажимайте на товары слева для добавления.</p>
                </div>
            <?php else: ?>
                <?php foreach ($_SESSION['pos_cart'] as $cItem): ?>
                    <div style="display:flex; justify-content:space-between; align-items:center; padding:10px 0; border-bottom:1px solid var(--border-color); gap:8px;">
                        <div style="flex:1;">
                            <strong style="font-size:14px;"><?= htmlspecialchars($cItem['name']) ?></strong><br>
                            <small class="text-muted"><?= number_format($cItem['price'], 0, '.', ' ') ?> ₸ / <?= htmlspecialchars($cItem['unit']) ?></small>
                        </div>
                        <!-- Управление количеством -->
                        <div style="display:flex; align-items:center; gap:4px;">
                            <form method="POST" style="margin:0;">
                                <?= Security::csrfField() ?>
                                <input type="hidden" name="action" value="update_qty">
                                <input type="hidden" name="item_id" value="<?= $cItem['id'] ?>">
                                <input type="hidden" name="qty" value="<?= $cItem['qty'] - 1 ?>">
                                <button type="submit" style="width:26px; height:26px; border-radius:4px; border:1px solid var(--border-color); background:var(--bg-secondary); color:inherit; cursor:pointer;">-</button>
                            </form>

                            <span style="font-weight:700; width:28px; text-align:center;"><?= $cItem['qty'] ?></span>

                            <form method="POST" style="margin:0;">
                                <?= Security::csrfField() ?>
                                <input type="hidden" name="action" value="update_qty">
                                <input type="hidden" name="item_id" value="<?= $cItem['id'] ?>">
                                <input type="hidden" name="qty" value="<?= $cItem['qty'] + 1 ?>">
                                <button type="submit" style="width:26px; height:26px; border-radius:4px; border:1px solid var(--border-color); background:var(--bg-secondary); color:inherit; cursor:pointer;">+</button>
                            </form>
                        </div>

                        <div style="text-align:right; min-width:70px;">
                            <strong style="color:var(--text-primary);"><?= number_format($cItem['price'] * $cItem['qty'], 0, '.', ' ') ?> ₸</strong>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Форма оплаты -->
        <form method="POST">
            <?= Security::csrfField() ?>
            <input type="hidden" name="action" value="checkout">

            <div class="form-group mb-2">
                <label class="form-label" style="font-size:12px;">Организация (Точка)</label>
                <select name="org_id" class="form-control" style="font-size:13px;">
                    <?php foreach ($organizations as $org): ?>
                        <option value="<?= $org['id'] ?>"><?= htmlspecialchars($org['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:8px;" class="mb-2">
                <div class="form-group mb-0">
                    <label class="form-label" style="font-size:12px;">Скидка (₸)</label>
                    <input type="number" step="10" min="0" name="discount" class="form-control" placeholder="0" style="font-size:13px;">
                </div>
                <div class="form-group mb-0">
                    <label class="form-label" style="font-size:12px;">Примечание</label>
                    <input type="text" name="customer_note" class="form-control" placeholder="Стол 4..." style="font-size:13px;">
                </div>
            </div>

            <!-- Способы оплаты -->
            <div class="form-group mb-3">
                <label class="form-label" style="font-size:12px;">Способ оплаты</label>
                <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:8px;">
                    <label style="border:1px solid var(--border-color); border-radius:8px; padding:10px 6px; text-align:center; cursor:pointer; font-size:12px;">
                        <input type="radio" name="payment_method" value="kaspi" checked> Kaspi QR
                    </label>
                    <label style="border:1px solid var(--border-color); border-radius:8px; padding:10px 6px; text-align:center; cursor:pointer; font-size:12px;">
                        <input type="radio" name="payment_method" value="card"> Карта
                    </label>
                    <label style="border:1px solid var(--border-color); border-radius:8px; padding:10px 6px; text-align:center; cursor:pointer; font-size:12px;">
                        <input type="radio" name="payment_method" value="cash"> Наличные
                    </label>
                </div>
            </div>

            <!-- Итоговая плашка -->
            <div style="background:var(--bg-secondary); border-radius:10px; padding:16px; margin-bottom:16px; display:flex; justify-content:space-between; align-items:center;">
                <span style="font-size:14px; color:var(--text-muted);">К ОПЛАТЕ:</span>
                <strong style="font-size:24px; color:#10b981;"><?= number_format($cartTotal, 0, '.', ' ') ?> ₸</strong>
            </div>

            <button type="submit" class="btn btn-success btn-block" style="font-size:16px; padding:14px;" <?= empty($_SESSION['pos_cart']) ? 'disabled' : '' ?>>
                <i class="fas fa-check-circle"></i> Пробить чек (Оплатить)
            </button>
        </form>
    </div>
</div>

<!-- Журнал последних чеков -->
<div class="card mt-4" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:24px;">
    <h3 class="mb-3"><i class="fas fa-history text-secondary"></i> Последние пробитые чеки</h3>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>№ Чека</th>
                    <th>Точка / Организация</th>
                    <th>Кассир</th>
                    <th>Оплата</th>
                    <th>Сумма (₸)</th>
                    <th>Статус</th>
                    <th>Дата и время</th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentOrders)): ?>
                    <tr><td colspan="8" class="text-center text-muted" style="padding:24px;">Чеков пока нет</td></tr>
                <?php else: ?>
                    <?php foreach ($recentOrders as $ro): ?>
                        <tr style="<?= $ro['status'] === 'refunded' ? 'opacity:0.75; background:rgba(239,68,68,0.05);' : '' ?>">
                            <td><code><?= htmlspecialchars($ro['order_number']) ?></code></td>
                            <td><?= htmlspecialchars($ro['org_name'] ?? 'Главная') ?></td>
                            <td><?= htmlspecialchars($ro['first_name'] . ' ' . $ro['last_name']) ?></td>
                            <td>
                                <span class="badge badge-primary"><?= strtoupper($ro['payment_method']) ?></span>
                            </td>
                            <td>
                                <?php if ($ro['status'] === 'refunded'): ?>
                                    <span style="text-decoration:line-through; color:var(--text-muted); font-size:13px;">
                                        <?= number_format((float)$ro['final_amount'], 0, '.', ' ') ?> ₸
                                    </span>
                                <?php else: ?>
                                    <strong style="color:#10b981;"><?= number_format((float)$ro['final_amount'], 0, '.', ' ') ?> ₸</strong>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($ro['status'] === 'paid'): ?>
                                    <span class="badge badge-success"><i class="fas fa-check"></i> Оплачен</span>
                                <?php elseif ($ro['status'] === 'refunded'): ?>
                                    <span class="badge badge-danger"><i class="fas fa-undo"></i> Возврат</span>
                                    <?php if (!empty($ro['refund_reason'])): ?>
                                        <br><small class="text-muted" style="font-size:11px;"><?= htmlspecialchars($ro['refund_reason']) ?></small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="badge badge-secondary"><?= htmlspecialchars($ro['status']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><small class="text-muted"><?= date('d.m.Y H:i', strtotime($ro['created_at'])) ?></small></td>
                            <td>
                                <div style="display:flex; gap:6px; flex-wrap:wrap;">
                                    <?php 
                                    $canManageOrders = in_array($activePosRole, ['manager', 'director', 'admin', 'vrio_owner', 'owner', 'supreme_owner', 'developer'], true); 
                                    ?>

                                    <!-- Кнопка ВОЗВРАТ (Менеджер и выше) -->
                                    <?php if ($ro['status'] === 'paid' && $canManageOrders): ?>
                                        <button type="button" class="btn btn-warning btn-sm" onclick="openRefundModal(<?= $ro['id'] ?>, '<?= htmlspecialchars(addslashes($ro['order_number'])) ?>', '<?= number_format((float)$ro['final_amount'], 0, '.', ' ') ?> ₸')" title="Провести возврат (Менеджер и выше)">
                                            <i class="fas fa-undo"></i> Возврат
                                        </button>
                                    <?php endif; ?>

                                    <!-- Кнопка УДАЛИТЬ ЧЕК (Менеджер и выше) -->
                                    <?php if ($canManageOrders): ?>
                                        <form method="POST" style="margin:0;" onsubmit="return confirm('Вы уверены, что хотите полностью удалить чек «<?= htmlspecialchars(addslashes($ro['order_number'])) ?>» из базы данных?')">
                                            <?= Security::csrfField() ?>
                                            <input type="hidden" name="action" value="delete_order">
                                            <input type="hidden" name="order_id" value="<?= $ro['id'] ?>">
                                            <button type="submit" class="btn btn-danger btn-sm" title="Удалить чек (Менеджер и выше)">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-muted" style="font-size:11px;" title="Удаление и возврат доступны роли Менеджер и выше">
                                            <i class="fas fa-lock"></i> Только просмотр
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Модальное окно проведения возврата -->
<div id="refundModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7); z-index:9999; align-items:center; justify-content:center; padding:20px;">
    <div style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); max-width:480px; width:100%; padding:28px; box-shadow:0 20px 40px rgba(0,0,0,0.5);">
        <h3 class="mb-2" style="color:#f59e0b;"><i class="fas fa-undo"></i> Оформление возврата средств</h3>
        <p class="text-muted mb-3" style="font-size:13px;">
            Чек: <strong id="modalRefundNumber" class="text-primary"></strong> на сумму <strong id="modalRefundAmount" class="text-success"></strong>.<br>
            <span style="color:#f59e0b;"><i class="fas fa-shield-alt"></i> Проведение возврата разрешено сотрудникам с ролью <strong>Менеджер и выше</strong>.</span>
        </p>

        <form method="POST">
            <?= Security::csrfField() ?>
            <input type="hidden" name="action" value="refund_order">
            <input type="hidden" name="order_id" id="modalRefundOrderId" value="">

            <div class="form-group mb-3">
                <label class="form-label">Причина возврата средств *</label>
                <input type="text" name="refund_reason" class="form-control" required placeholder="Например: Отказ гостя / Ошибочный ввод позиции / Брак">
            </div>

            <div style="display:flex; justify-content:flex-end; gap:12px;">
                <button type="button" class="btn btn-secondary" onclick="closeRefundModal()">Отмена</button>
                <button type="submit" class="btn btn-warning">
                    <i class="fas fa-check"></i> Подтвердить возврат средств
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Модалка установки / смены пароля директора -->
<div id="setDirectorPasswordModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.75); z-index:9999; align-items:center; justify-content:center; padding:20px;">
    <div style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); max-width:480px; width:100%; padding:28px; box-shadow:0 20px 40px rgba(0,0,0,0.6);">
        <h3 class="mb-2" style="color:#f59e0b;"><i class="fas fa-key"></i> Пароль безопасности директора</h3>
        <p class="text-muted mb-3" style="font-size:13px;">
            Этот пароль защищает переключение на роль <strong>Менеджер</strong> и <strong>Директор</strong>, а также удаление и возврат чеков кассы.
        </p>

        <form method="POST">
            <?= Security::csrfField() ?>
            <input type="hidden" name="action" value="set_director_password">

            <div class="form-group mb-3">
                <label class="form-label">Введите пароль директора (PIN или текст) *</label>
                <div style="position:relative;">
                    <input type="password" name="director_password" id="inputDirPasswordAdmin" class="form-control" required placeholder="Например: 1234 или секретное слово">
                    <button type="button" onclick="togglePasswordVisibility('inputDirPasswordAdmin', this)" style="position:absolute; right:10px; top:50%; transform:translateY(-50%); background:none; border:none; color:var(--text-muted); cursor:pointer;">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:12px;">
                <button type="button" class="btn btn-secondary" onclick="closeSetDirectorPasswordModal()">Отмена</button>
                <button type="submit" class="btn btn-warning">
                    <i class="fas fa-save"></i> Сохранить пароль директора
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Модалка подтверждения пароля директора при смене роли -->
<div id="rolePasswordModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.75); z-index:9999; align-items:center; justify-content:center; padding:20px;">
    <div style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); max-width:460px; width:100%; padding:28px; box-shadow:0 20px 40px rgba(0,0,0,0.6);">
        <h3 class="mb-2" style="color:var(--primary);"><i class="fas fa-lock"></i> Требуется пароль директора</h3>
        <p class="text-muted mb-3" style="font-size:13px;">
            Для перехода в режим <strong id="modalTargetRoleTitle" class="text-primary"></strong> требуется ввести пароль директора.
        </p>

        <form method="POST">
            <?= Security::csrfField() ?>
            <input type="hidden" name="action" value="switch_pos_role">
            <input type="hidden" name="target_role" id="modalTargetRole" value="">

            <div class="form-group mb-3">
                <label class="form-label">Пароль директора *</label>
                <div style="position:relative;">
                    <input type="password" name="director_password" id="inputRoleAuthPasswordAdmin" class="form-control" required placeholder="Введите пароль директора">
                    <button type="button" onclick="togglePasswordVisibility('inputRoleAuthPasswordAdmin', this)" style="position:absolute; right:10px; top:50%; transform:translateY(-50%); background:none; border:none; color:var(--text-muted); cursor:pointer;">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:12px;">
                <button type="button" class="btn btn-secondary" onclick="closeRolePasswordModal()">Отмена</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-unlock"></i> Подтвердить вход
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openRefundModal(orderId, orderNum, amount) {
    document.getElementById('modalRefundOrderId').value = orderId;
    document.getElementById('modalRefundNumber').innerText = orderNum;
    document.getElementById('modalRefundAmount').innerText = amount;
    document.getElementById('refundModal').style.display = 'flex';
}
function closeRefundModal() {
    document.getElementById('refundModal').style.display = 'none';
}

function openSetDirectorPasswordModal() {
    document.getElementById('setDirectorPasswordModal').style.display = 'flex';
    setTimeout(() => {
        const inp = document.getElementById('inputDirPasswordAdmin');
        if (inp) inp.focus();
    }, 100);
}
function closeSetDirectorPasswordModal() {
    document.getElementById('setDirectorPasswordModal').style.display = 'none';
}

function openRolePasswordModal(role, roleTitle) {
    const isSet = <?= Auth::isDirectorPosPasswordSet() ? 'true' : 'false' ?>;
    if (!isSet) {
        alert('Пароль директора ещё не установлен! Сначала задайте пароль директора.');
        openSetDirectorPasswordModal();
        return;
    }
    document.getElementById('modalTargetRole').value = role;
    document.getElementById('modalTargetRoleTitle').innerText = roleTitle;
    document.getElementById('inputRoleAuthPasswordAdmin').value = '';
    document.getElementById('rolePasswordModal').style.display = 'flex';
    setTimeout(() => {
        const inp = document.getElementById('inputRoleAuthPasswordAdmin');
        if (inp) inp.focus();
    }, 100);
}
function closeRolePasswordModal() {
    document.getElementById('rolePasswordModal').style.display = 'none';
}

function togglePasswordVisibility(fieldId, btn) {
    const field = document.getElementById(fieldId);
    if (!field) return;
    const isPass = field.type === 'password';
    field.type = isPass ? 'text' : 'password';
    btn.innerHTML = isPass ? '<i class="fas fa-eye-slash"></i>' : '<i class="fas fa-eye"></i>';
}
</script>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
