<?php
declare(strict_types=1);

/**
 * AI Business Assistant - User POS Cashier & Checkout (Клиентская Касса)
 * Онлайн-касса для торговой точки с ролевым контролем удалений и возвратов
 */

require_once dirname(__DIR__) . '/includes/Auth.php';
require_once dirname(__DIR__) . '/includes/AutoMigrate.php';
AutoMigrate::run();

$user   = Auth::requireAuth('/login.php');
$userId = (int)$user['id'];
$pdo    = Database::getConnection();
$currentRole = $user['role_name'] ?? 'user';

// Переключатель роли на кассе (Кассир / Менеджер / Директор)
if (isset($_GET['as_role'])) {
    $reqRole = $_GET['as_role'];
    if ($reqRole === 'user') {
        $_SESSION['pos_user_role'] = 'user';
    }
}
$activeRole = $_SESSION['pos_user_role'] ?? $currentRole;

if (!isset($_SESSION['user_pos_cart'])) {
    $_SESSION['user_pos_cart'] = [];
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

        // Установка / смена пароля директора
        if ($action === 'set_director_password') {
            $newPassword = trim($_POST['director_password'] ?? '');
            if (empty($newPassword)) {
                $error = "Пароль директора не может быть пустым.";
            } else {
                Auth::setDirectorPosPassword($newPassword);
                Security::log($pdo, 'set_director_pos_password', "Пароль директора успешно сохранен пользователем #{$userId}", $userId);
                $success = "🔑 Пароль директора успешно установлен! Теперь переключение на роли «Менеджер» и «Директор» защищено паролем.";
            }
        }

        // Переключение роли на кассе с проверкой пароля директора
        elseif ($action === 'switch_pos_role') {
            $targetRole = trim($_POST['target_role'] ?? 'user');
            $enteredPassword = (string)($_POST['director_password'] ?? '');

            if ($targetRole === 'user') {
                $_SESSION['pos_user_role'] = 'user';
                $success = "Переключено в режим: Кассир.";
            } elseif (in_array($targetRole, ['manager', 'director', 'admin'], true)) {
                if (!Auth::isDirectorPosPasswordSet()) {
                    $error = "⚠️ Пароль директора ещё не установлен! Сначала задайте пароль директора.";
                } elseif (!Auth::verifyDirectorPosPassword($enteredPassword)) {
                    $error = "⛔ Неверный пароль директора! Доступ к роли «" . ($targetRole === 'director' ? 'Директор' : 'Менеджер') . "» отклонён.";
                    Security::log($pdo, 'failed_director_password', "Неверный ввод пароля директора при переходе в режим {$targetRole} пользователем #{$userId}", $userId);
                } else {
                    $_SESSION['pos_user_role'] = $targetRole;
                    $success = "🔓 Пароль директора подтверждён! Успешный вход под ролью: <strong>" . ($targetRole === 'director' ? 'Генеральный Директор' : 'Менеджер смены') . "</strong>.";
                    Security::log($pdo, 'director_role_activated', "Активирована роль {$targetRole} через пароль директора пользователем #{$userId}", $userId);
                }
            }
        }

        // 1. Добавить товар в чек
        if ($action === 'add_to_cart') {
            $itemId = (int)($_POST['item_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT id, name, price, unit FROM menu_items WHERE id = ? AND is_active = 1");
            $stmt->execute([$itemId]);
            $item = $stmt->fetch();

            if ($item) {
                if (isset($_SESSION['user_pos_cart'][$itemId])) {
                    $_SESSION['user_pos_cart'][$itemId]['qty'] += 1;
                } else {
                    $_SESSION['user_pos_cart'][$itemId] = [
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
            if (isset($_SESSION['user_pos_cart'][$itemId])) {
                if ($qty <= 0) {
                    unset($_SESSION['user_pos_cart'][$itemId]);
                } else {
                    $_SESSION['user_pos_cart'][$itemId]['qty'] = $qty;
                }
            }
        }

        // 3. Удалить позицию
        elseif ($action === 'remove_item') {
            $itemId = (int)($_POST['item_id'] ?? 0);
            unset($_SESSION['user_pos_cart'][$itemId]);
        }

        // 4. Очистить чек
        elseif ($action === 'clear_cart') {
            $_SESSION['user_pos_cart'] = [];
        }

        // 5. Пробить чек / Оплатить
        elseif ($action === 'checkout') {
            if (empty($_SESSION['user_pos_cart'])) {
                $error = "Чек пуст. Добавьте товары из меню.";
            } else {
                $orgId         = !empty($_POST['org_id']) ? (int)$_POST['org_id'] : null;
                $paymentMethod = $_POST['payment_method'] ?? 'kaspi';
                $discount      = max(0, (float)($_POST['discount'] ?? 0));
                $note          = trim($_POST['customer_note'] ?? '');

                $subtotal = 0.0;
                foreach ($_SESSION['user_pos_cart'] as $cItem) {
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
                    $orderStmt->execute([$orgId, $userId, $orderNumber, $subtotal, $discount, $finalAmount, $paymentMethod, $note]);
                    $orderId = (int)$pdo->lastInsertId();

                    $itemStmt = $pdo->prepare("
                        INSERT INTO cash_order_items (order_id, item_id, item_name, price, quantity, subtotal)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    foreach ($_SESSION['user_pos_cart'] as $cItem) {
                        $itemSub = $cItem['price'] * $cItem['qty'];
                        $itemStmt->execute([$orderId, $cItem['id'], $cItem['name'], $cItem['price'], $cItem['qty'], $itemSub]);
                    }

                    $pdo->commit();

                    Security::log($pdo, 'user_pos_sale', "Пробит чек {$orderNumber} на сумму {$finalAmount} ₸", $userId);

                    $printedOrder = [
                        'id'           => $orderId,
                        'order_number' => $orderNumber,
                        'total'        => $subtotal,
                        'discount'     => $discount,
                        'final_amount' => $finalAmount,
                        'payment'      => $paymentMethod,
                        'items'        => $_SESSION['user_pos_cart'],
                        'date'         => date('d.m.Y H:i:s'),
                    ];

                    $_SESSION['user_pos_cart'] = [];
                    $success = "🎉 Чек <strong>{$orderNumber}</strong> успешно пробит! Сумма: " . number_format($finalAmount, 0, '.', ' ') . " ₸";
                } catch (\Throwable $e) {
                    $pdo->rollBack();
                    $error = "Ошибка при проведении чека: " . $e->getMessage();
                }
            }
        }

        // 6. Удалить чек (Менеджер и выше: manager, director, admin, etc.)
        elseif ($action === 'delete_order') {
            $orderId = (int)($_POST['order_id'] ?? 0);
            $roleForCheck = $_SESSION['pos_user_role'] ?? $currentRole;

            $allowedToDelete = in_array($roleForCheck, ['manager', 'director', 'admin', 'vrio_owner', 'owner', 'supreme_owner', 'developer'], true);

            if (!$allowedToDelete) {
                $error = "⛔ Доступ запрещён: Удалять чеки имеют право ТОЛЬКО сотрудники с ролью Менеджер и выше (ваша активная роль: {$roleForCheck}).";
            } else {
                $oStmt = $pdo->prepare("SELECT order_number, final_amount FROM cash_orders WHERE id = ?");
                $oStmt->execute([$orderId]);
                $ord = $oStmt->fetch();

                if ($ord) {
                    $pdo->prepare("DELETE FROM cash_order_items WHERE order_id = ?")->execute([$orderId]);
                    $pdo->prepare("DELETE FROM cash_orders WHERE id = ?")->execute([$orderId]);
                    Security::log($pdo, 'user_pos_order_deleted', "Чек {$ord['order_number']} удалён пользователем #{$userId} (роль: {$roleForCheck})", $userId);
                    $success = "🗑️ Чек <strong>{$ord['order_number']}</strong> успешно удалён.";
                } else {
                    $error = "Чек не найден.";
                }
            }
        }

        // 7. Провести возврат (Менеджер и выше)
        elseif ($action === 'refund_order') {
            $orderId = (int)($_POST['order_id'] ?? 0);
            $reason  = trim($_POST['refund_reason'] ?? 'Возврат по запросу клиента');
            $roleForCheck = $_SESSION['pos_user_role'] ?? $currentRole;

            $allowedToRefund = in_array($roleForCheck, ['manager', 'director', 'admin', 'vrio_owner', 'owner', 'supreme_owner', 'developer'], true);

            if (!$allowedToRefund) {
                $error = "⛔ Доступ запрещён: Провести возврат средств по чеку имеют право ТОЛЬКО сотрудники с ролью Менеджер и выше (ваша активная роль: {$roleForCheck}).";
            } else {
                $oStmt = $pdo->prepare("SELECT order_number, final_amount, status FROM cash_orders WHERE id = ?");
                $oStmt->execute([$orderId]);
                $ord = $oStmt->fetch();

                if (!$ord) {
                    $error = "Чек не найден.";
                } elseif ($ord['status'] === 'refunded') {
                    $error = "По этому чеку возврат средств уже был проведён.";
                } else {
                    $refStmt = $pdo->prepare("
                        UPDATE cash_orders 
                        SET status = 'refunded', refund_by = ?, refund_at = NOW(), refund_reason = ?
                        WHERE id = ?
                    ");
                    $refStmt->execute([$userId, $reason, $orderId]);

                    Security::log($pdo, 'user_pos_order_refunded', "Возврат по чеку {$ord['order_number']} проведён пользователем #{$userId} (роль: {$roleForCheck})", $userId);
                    $success = "↩️ Возврат по чеку <strong>{$ord['order_number']}</strong> успешно проведён!";
                }
            }
        }
    }
}

// Данные
require_once dirname(__DIR__) . '/includes/OrganizationGenerator.php';

// Загружаем собственные организации и организации, к которым выдан доступ через organization_members
$orgsStmt = $pdo->prepare("
    SELECT DISTINCT o.* 
    FROM organizations o 
    LEFT JOIN organization_members om ON o.id = om.organization_id AND om.user_id = ?
    WHERE ((o.user_id = ? OR o.director_email = ?) OR om.id IS NOT NULL OR ? = 1) AND o.is_active = 1 
    ORDER BY o.name ASC
");
$orgsStmt->execute([$userId, $userId, $user['email'], Auth::isAdmin() ? 1 : 0]);
$organizations = $orgsStmt->fetchAll();
if (Auth::isAdmin() && empty($organizations)) {
    $organizations = $pdo->query("SELECT * FROM organizations WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
}

if (empty($organizations) && !Auth::isAdmin()) {
    header("Location: /user/request_organization.php");
    exit;
}

$selectedOrgId = !empty($_GET['org_id']) ? (int)$_GET['org_id'] : (!empty($organizations[0]['id']) ? (int)$organizations[0]['id'] : 0);

$currentOrg = null;
foreach ($organizations as $o) {
    if ((int)$o['id'] === $selectedOrgId) {
        $currentOrg = $o;
        break;
    }
}
if (!$currentOrg && !empty($organizations)) {
    $currentOrg = $organizations[0];
    $selectedOrgId = (int)$currentOrg['id'];
}

$bType = $currentOrg['business_type'] ?? 'general';
$bTypes = OrganizationGenerator::getBusinessTypes();
$meta = $bTypes[$bType] ?? $bTypes['general'];
$isService = ($meta['item_type'] === 'services');

if ($selectedOrgId > 0) {
    $catStmt = $pdo->prepare("SELECT * FROM menu_categories WHERE (organization_id = ? OR organization_id IS NULL) AND is_active = 1 ORDER BY sort_order ASC, name ASC");
    $catStmt->execute([$selectedOrgId]);
    $categories = $catStmt->fetchAll();
} else {
    $categories = $pdo->query("SELECT * FROM menu_categories WHERE is_active = 1 ORDER BY sort_order ASC, name ASC")->fetchAll();
}

$selectedCategory = !empty($_GET['category']) ? (int)$_GET['category'] : 0;
$itemSql = "SELECT * FROM menu_items WHERE is_active = 1";
$itemParams = [];
if ($selectedOrgId > 0) {
    $itemSql .= " AND (organization_id = ? OR (user_id = ? AND organization_id IS NULL))";
    $itemParams[] = $selectedOrgId;
    $itemParams[] = $userId;
}
if ($selectedCategory > 0) {
    $itemSql .= " AND category_id = ?";
    $itemParams[] = $selectedCategory;
}
$itemSql .= " ORDER BY name ASC";
$itemsStmt = $pdo->prepare($itemSql);
$itemsStmt->execute($itemParams);
$items = $itemsStmt->fetchAll();

// Выручка за сегодня
$todayStats = $pdo->query("
    SELECT COUNT(*) as total_orders, COALESCE(SUM(final_amount), 0) as total_revenue
    FROM cash_orders 
    WHERE DATE(created_at) = CURDATE() AND status = 'paid'
")->fetch();

// Последние чеки
$recentOrders = $pdo->query("
    SELECT co.*, u.first_name, u.last_name, o.name AS org_name
    FROM cash_orders co
    JOIN users u ON co.cashier_id = u.id
    LEFT JOIN organizations o ON co.organization_id = o.id
    ORDER BY co.id DESC 
    LIMIT 15
")->fetchAll();

$cartTotal = 0.0;
foreach ($_SESSION['user_pos_cart'] as $ci) {
    $cartTotal += ($ci['price'] * $ci['qty']);
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
                    Установите пароль директора для защиты переключения на роли «Менеджер» и «Директор», а также для удаления и возврата чеков.
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
        <h2><i class="fas fa-cash-register text-primary"></i> Рабочее место кассира (POS)</h2>
        <p class="text-muted" style="margin-bottom:8px;">Оформление продаж, чеки, приём оплаты через Kaspi QR / Карты / Наличные</p>

        <!-- Ролевой селектор с защитой паролем директора -->
        <div style="display:inline-flex; align-items:center; gap:8px; background:var(--bg-secondary); border:1px solid var(--border-color); border-radius:20px; padding:4px 12px; font-size:12px; flex-wrap:wrap;">
            <span class="text-muted"><i class="fas fa-user-shield"></i> Ваша роль на кассе:</span>
            <?php
            $roleBadges = [
                'admin'    => ['title' => 'Администратор',       'color' => '#8b5cf6', 'perms' => 'Удаление чеков и возврат'],
                'director' => ['title' => 'Генеральный Директор','color' => '#3b82f6', 'perms' => 'Удаление чеков и возврат'],
                'manager'  => ['title' => 'Менеджер смены',      'color' => '#f59e0b', 'perms' => 'Удаление чеков и возврат'],
                'user'     => ['title' => 'Кассир',              'color' => '#10b981', 'perms' => 'Только продажа']
            ];
            $cb = $roleBadges[$activeRole] ?? $roleBadges['user'];
            ?>
            <strong style="color:<?= $cb['color'] ?>;"><?= $cb['title'] ?></strong>
            <small style="color:var(--text-muted);">— <?= $cb['perms'] ?></small>

            <div style="display:inline-flex; gap:4px; margin-left:8px; align-items:center;">
                <!-- Кассир переключается свободно -->
                <form method="POST" style="margin:0; display:inline;">
                    <?= Security::csrfField() ?>
                    <input type="hidden" name="action" value="switch_pos_role">
                    <input type="hidden" name="target_role" value="user">
                    <button type="submit" class="btn btn-sm" style="padding:2px 8px; font-size:11px; font-weight:700; border-radius:12px; <?= $activeRole==='user' ? 'background:#10b981;color:#fff;' : 'background:transparent;border:1px solid var(--border-color);' ?>" title="Режим кассира">
                        Кассир
                    </button>
                </form>

                <!-- Менеджер защищен паролем директора -->
                <button type="button" onclick="openRolePasswordModal('manager', 'Менеджер смены')" class="btn btn-sm" style="padding:2px 8px; font-size:11px; font-weight:700; border-radius:12px; <?= $activeRole==='manager' ? 'background:#f59e0b;color:#000;' : 'background:transparent;border:1px solid var(--border-color);' ?>" title="Требуется пароль директора">
                    <i class="fas fa-lock" style="font-size:9px;"></i> Менеджер
                </button>

                <!-- Директор защищен паролем директора -->
                <button type="button" onclick="openRolePasswordModal('director', 'Генеральный Директор')" class="btn btn-sm" style="padding:2px 8px; font-size:11px; font-weight:700; border-radius:12px; <?= $activeRole==='director' ? 'background:#3b82f6;color:#fff;' : 'background:transparent;border:1px solid var(--border-color);' ?>" title="Требуется пароль директора">
                    <i class="fas fa-lock" style="font-size:9px;"></i> Директор
                </button>
            </div>
        </div>
    </div>
    <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
        <button type="button" class="btn btn-secondary btn-sm" onclick="openSetDirectorPasswordModal()" title="Настройка пароля директора для смены ролей и кассовых операций">
            <i class="fas fa-key text-warning"></i> <?= Auth::isDirectorPosPasswordSet() ? 'Пароль директора' : 'Задать пароль директора' ?>
        </button>
        <div style="background:var(--bg-card); border:1px solid var(--border-color); padding:10px 18px; border-radius:var(--radius-lg); text-align:right;">
            <small class="text-muted">Дневная выручка:</small><br>
            <strong style="color:#10b981; font-size:18px;"><?= number_format((float)$todayStats['total_revenue'], 0, '.', ' ') ?> ₸</strong>
        </div>
        <a href="/user/menu_items.php" class="btn btn-secondary">
            <i class="fas fa-utensils"></i> Меню
        </a>
    </div>
</div>

<?php if ($success): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $success ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Чек после пробития -->
<?php if ($printedOrder): ?>
<div class="card mb-4" style="background:#111827; border:2px dashed #10b981; border-radius:var(--radius-lg); padding:24px; max-width:440px; margin:0 auto 24px auto; font-family:monospace;">
    <div style="text-align:center; border-bottom:1px dashed #374151; padding-bottom:12px; margin-bottom:12px;">
        <h3 style="margin:0; font-size:18px; color:#f9fafb;">ТОВАРНЫЙ ЧЕК</h3>
        <p style="margin:4px 0 0; color:#9ca3af; font-size:12px;">№ <?= htmlspecialchars($printedOrder['order_number']) ?></p>
        <p style="margin:2px 0 0; color:#6b7280; font-size:11px;"><?= $printedOrder['date'] ?></p>
    </div>

    <table style="width:100%; font-size:12px; margin-bottom:12px;">
        <thead>
            <tr style="border-bottom:1px solid #374151; color:#9ca3af;">
                <th style="text-align:left; padding:4px 0;">Позиция</th>
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
        <div style="display:flex; justify-content:space-between; font-size:16px; font-weight:bold; color:#10b981;">
            <span>ИТОГО:</span>
            <span><?= number_format($printedOrder['final_amount'], 0, '.', ' ') ?> ₸</span>
        </div>
        <div style="display:flex; justify-content:space-between; color:#9ca3af; margin-top:6px; font-size:11px;">
            <span>Оплата:</span>
            <span><?= strtoupper($printedOrder['payment']) ?></span>
        </div>
    </div>

    <div style="text-align:center; margin-top:16px; border-top:1px dashed #374151; padding-top:12px;">
        <button onclick="window.print()" class="btn btn-success btn-sm">
            <i class="fas fa-print"></i> Печать чека
        </button>
    </div>
</div>
<?php endif; ?>

<div style="display:grid; grid-template-columns: 2fr 1.2fr; gap:24px; align-items:start;">

    <!-- Витрина -->
    <div>
        <div style="display:flex; gap:8px; overflow-x:auto; padding-bottom:12px; margin-bottom:16px;">
            <a href="/user/cashier.php" class="btn btn-sm <?= $selectedCategory === 0 ? 'btn-primary' : 'btn-secondary' ?>">
                Все (<?= count($items) ?>)
            </a>
            <?php foreach ($categories as $cat): ?>
                <a href="/user/cashier.php?category=<?= $cat['id'] ?>" class="btn btn-sm <?= $selectedCategory === (int)$cat['id'] ? 'btn-primary' : 'btn-secondary' ?>">
                    <?= htmlspecialchars($cat['name']) ?>
                </a>
            <?php endforeach; ?>
        </div>

        <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap:14px;">
            <?php if (empty($items)): ?>
                <div class="card" style="grid-column: 1/-1; padding:32px; text-align:center; background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg);">
                    <p class="text-muted" style="margin:0;">Позиций пока нет. <a href="/user/menu_items.php" style="color:var(--primary);">Добавить в меню</a></p>
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

    <!-- Корзина чека -->
    <div class="card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:24px; position:sticky; top:20px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; border-bottom:1px solid var(--border-color); padding-bottom:12px;">
            <h3 style="margin:0;"><i class="fas fa-shopping-basket text-primary"></i> Чек</h3>
            <?php if (!empty($_SESSION['user_pos_cart'])): ?>
                <form method="POST" style="margin:0;">
                    <?= Security::csrfField() ?>
                    <input type="hidden" name="action" value="clear_cart">
                    <button type="submit" class="btn btn-secondary btn-sm" style="font-size:11px;">
                        Очистить
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <div style="max-height:280px; overflow-y:auto; margin-bottom:16px;">
            <?php if (empty($_SESSION['user_pos_cart'])): ?>
                <p class="text-muted text-center" style="padding:28px 0; margin:0;">Чек пуст. Нажимайте на блюда слева.</p>
            <?php else: ?>
                <?php foreach ($_SESSION['user_pos_cart'] as $cItem): ?>
                    <div style="display:flex; justify-content:space-between; align-items:center; padding:8px 0; border-bottom:1px solid var(--border-color);">
                        <div style="flex:1;">
                            <strong><?= htmlspecialchars($cItem['name']) ?></strong><br>
                            <small class="text-muted"><?= number_format($cItem['price'], 0, '.', ' ') ?> ₸</small>
                        </div>
                        <div style="display:flex; align-items:center; gap:4px;">
                            <form method="POST" style="margin:0;">
                                <?= Security::csrfField() ?>
                                <input type="hidden" name="action" value="update_qty">
                                <input type="hidden" name="item_id" value="<?= $cItem['id'] ?>">
                                <input type="hidden" name="qty" value="<?= $cItem['qty'] - 1 ?>">
                                <button type="submit" style="width:24px; height:24px; border:1px solid var(--border-color); background:var(--bg-secondary); color:inherit; cursor:pointer;">-</button>
                            </form>
                            <span style="font-weight:700; width:24px; text-align:center;"><?= $cItem['qty'] ?></span>
                            <form method="POST" style="margin:0;">
                                <?= Security::csrfField() ?>
                                <input type="hidden" name="action" value="update_qty">
                                <input type="hidden" name="item_id" value="<?= $cItem['id'] ?>">
                                <input type="hidden" name="qty" value="<?= $cItem['qty'] + 1 ?>">
                                <button type="submit" style="width:24px; height:24px; border:1px solid var(--border-color); background:var(--bg-secondary); color:inherit; cursor:pointer;">+</button>
                            </form>
                        </div>
                        <div style="text-align:right; min-width:70px;">
                            <strong><?= number_format($cItem['price'] * $cItem['qty'], 0, '.', ' ') ?> ₸</strong>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <form method="POST">
            <?= Security::csrfField() ?>
            <input type="hidden" name="action" value="checkout">

            <?php if (!empty($organizations)): ?>
                <div class="form-group mb-2">
                    <label class="form-label" style="font-size:12px;">Организация</label>
                    <select name="org_id" class="form-control" style="font-size:13px;">
                        <?php foreach ($organizations as $org): ?>
                            <option value="<?= $org['id'] ?>"><?= htmlspecialchars($org['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <div class="form-group mb-2">
                <label class="form-label" style="font-size:12px;">Скидка (₸)</label>
                <input type="number" step="10" min="0" name="discount" class="form-control" placeholder="0" style="font-size:13px;">
            </div>

            <div class="form-group mb-3">
                <label class="form-label" style="font-size:12px;">Способ оплаты</label>
                <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:6px;">
                    <label style="border:1px solid var(--border-color); border-radius:6px; padding:8px 4px; text-align:center; font-size:11px; cursor:pointer;">
                        <input type="radio" name="payment_method" value="kaspi" checked> Kaspi QR
                    </label>
                    <label style="border:1px solid var(--border-color); border-radius:6px; padding:8px 4px; text-align:center; font-size:11px; cursor:pointer;">
                        <input type="radio" name="payment_method" value="card"> Карта
                    </label>
                    <label style="border:1px solid var(--border-color); border-radius:6px; padding:8px 4px; text-align:center; font-size:11px; cursor:pointer;">
                        <input type="radio" name="payment_method" value="cash"> Наличные
                    </label>
                </div>
            </div>

            <div style="background:var(--bg-secondary); border-radius:8px; padding:14px; margin-bottom:14px; display:flex; justify-content:space-between; align-items:center;">
                <span style="font-size:13px; color:var(--text-muted);">ИТОГО:</span>
                <strong style="font-size:22px; color:#10b981;"><?= number_format($cartTotal, 0, '.', ' ') ?> ₸</strong>
            </div>

            <button type="submit" class="btn btn-success btn-block" style="font-size:15px; padding:12px;" <?= empty($_SESSION['user_pos_cart']) ? 'disabled' : '' ?>>
                <i class="fas fa-check-circle"></i> Оплатить чек
            </button>
        </form>
    </div>
</div>

<!-- Журнал чеков -->
<div class="card mt-4" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:24px;">
    <h3 class="mb-3"><i class="fas fa-history text-secondary"></i> Чеки торговой точки</h3>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>№ Чека</th>
                    <th>Кассир</th>
                    <th>Оплата</th>
                    <th>Сумма (₸)</th>
                    <th>Статус</th>
                    <th>Дата</th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentOrders)): ?>
                    <tr><td colspan="7" class="text-center text-muted" style="padding:24px;">Чеков пока нет</td></tr>
                <?php else: ?>
                    <?php foreach ($recentOrders as $ro): ?>
                        <tr style="<?= $ro['status'] === 'refunded' ? 'opacity:0.75; background:rgba(239,68,68,0.05);' : '' ?>">
                            <td><code><?= htmlspecialchars($ro['order_number']) ?></code></td>
                            <td><?= htmlspecialchars($ro['first_name'] . ' ' . $ro['last_name']) ?></td>
                            <td><span class="badge badge-primary"><?= strtoupper($ro['payment_method']) ?></span></td>
                            <td>
                                <?php if ($ro['status'] === 'refunded'): ?>
                                    <span style="text-decoration:line-through; color:var(--text-muted);">
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
                                <?php endif; ?>
                            </td>
                            <td><small class="text-muted"><?= date('d.m.Y H:i', strtotime($ro['created_at'])) ?></small></td>
                            <td>
                                <div style="display:flex; gap:6px; flex-wrap:wrap;">
                                    <?php 
                                    $canManageOrders = in_array($activeRole, ['manager', 'director', 'admin', 'vrio_owner', 'owner', 'supreme_owner', 'developer'], true); 
                                    ?>

                                    <!-- Возврат (Менеджер и выше) -->
                                    <?php if ($ro['status'] === 'paid' && $canManageOrders): ?>
                                        <button type="button" class="btn btn-warning btn-sm" onclick="openUserRefundModal(<?= $ro['id'] ?>, '<?= htmlspecialchars(addslashes($ro['order_number'])) ?>', '<?= number_format((float)$ro['final_amount'], 0, '.', ' ') ?> ₸')">
                                            <i class="fas fa-undo"></i> Возврат
                                        </button>
                                    <?php endif; ?>

                                    <!-- Удалить (Менеджер и выше) -->
                                    <?php if ($canManageOrders): ?>
                                        <form method="POST" style="margin:0;" onsubmit="return confirm('Вы уверены, что хотите удалить чек «<?= htmlspecialchars(addslashes($ro['order_number'])) ?>»?')">
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

<!-- Модалка возврата чека -->
<div id="userRefundModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7); z-index:9999; align-items:center; justify-content:center; padding:20px;">
    <div style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); max-width:480px; width:100%; padding:28px; box-shadow:0 20px 40px rgba(0,0,0,0.5);">
        <h3 class="mb-2" style="color:#f59e0b;"><i class="fas fa-undo"></i> Оформление возврата по чеку</h3>
        <p class="text-muted mb-3" style="font-size:13px;">
            Чек: <strong id="uRefundNum" class="text-primary"></strong> на сумму <strong id="uRefundAmt" class="text-success"></strong>.<br>
            <span style="color:#f59e0b;"><i class="fas fa-shield-alt"></i> Проведение возврата разрешено сотрудникам с ролью <strong>Менеджер и выше</strong>.</span>
        </p>

        <form method="POST">
            <?= Security::csrfField() ?>
            <input type="hidden" name="action" value="refund_order">
            <input type="hidden" name="order_id" id="uRefundOrderId" value="">

            <div class="form-group mb-3">
                <label class="form-label">Причина возврата *</label>
                <input type="text" name="refund_reason" class="form-control" required placeholder="Отказ гостя / ошибка ввода">
            </div>

            <div style="display:flex; justify-content:flex-end; gap:12px;">
                <button type="button" class="btn btn-secondary" onclick="closeUserRefundModal()">Отмена</button>
                <button type="submit" class="btn btn-warning">
                    <i class="fas fa-check"></i> Оформить возврат
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
                <label class="form-label">Введите новый пароль директора (PIN или текст) *</label>
                <div style="position:relative;">
                    <input type="password" name="director_password" id="inputDirPassword" class="form-control" required placeholder="Например: 1234 или секретное слово">
                    <button type="button" onclick="togglePasswordVisibility('inputDirPassword', this)" style="position:absolute; right:10px; top:50%; transform:translateY(-50%); background:none; border:none; color:var(--text-muted); cursor:pointer;">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
                <small class="text-muted">Запомните этот пароль. Он будет запрашиваться у персонала при входе в режим Менеджера/Директора.</small>
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
                    <input type="password" name="director_password" id="inputRoleAuthPassword" class="form-control" required placeholder="Введите пароль директора">
                    <button type="button" onclick="togglePasswordVisibility('inputRoleAuthPassword', this)" style="position:absolute; right:10px; top:50%; transform:translateY(-50%); background:none; border:none; color:var(--text-muted); cursor:pointer;">
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
function openUserRefundModal(orderId, orderNum, amount) {
    document.getElementById('uRefundOrderId').value = orderId;
    document.getElementById('uRefundNum').innerText = orderNum;
    document.getElementById('uRefundAmt').innerText = amount;
    document.getElementById('userRefundModal').style.display = 'flex';
}
function closeUserRefundModal() {
    document.getElementById('userRefundModal').style.display = 'none';
}

function openSetDirectorPasswordModal() {
    document.getElementById('setDirectorPasswordModal').style.display = 'flex';
    setTimeout(() => {
        const inp = document.getElementById('inputDirPassword');
        if (inp) inp.focus();
    }, 100);
}
function closeSetDirectorPasswordModal() {
    document.getElementById('setDirectorPasswordModal').style.display = 'none';
}

function openRolePasswordModal(role, roleTitle) {
    // Проверяем, задан ли пароль директора
    const isSet = <?= Auth::isDirectorPosPasswordSet() ? 'true' : 'false' ?>;
    if (!isSet) {
        alert('Пароль директора ещё не установлен! Сначала задайте пароль директора.');
        openSetDirectorPasswordModal();
        return;
    }
    document.getElementById('modalTargetRole').value = role;
    document.getElementById('modalTargetRoleTitle').innerText = roleTitle;
    document.getElementById('inputRoleAuthPassword').value = '';
    document.getElementById('rolePasswordModal').style.display = 'flex';
    setTimeout(() => {
        const inp = document.getElementById('inputRoleAuthPassword');
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
