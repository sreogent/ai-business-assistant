<?php
declare(strict_types=1);

/**
 * AI Business Assistant - User Catalog & Services / Menu Management
 * Управление каталогом товаров/услуг и прайс-листом (адаптивно под сферу: Тендеры, Кофейня, IT, Услуги, Ритейл)
 */

require_once dirname(__DIR__) . '/includes/Auth.php';
require_once dirname(__DIR__) . '/includes/OrganizationGenerator.php';

$user   = Auth::requireAuth('/login.php');
$userId = (int)$user['id'];
$pdo    = Database::getConnection();

$error = null;
$success = null;

// Загружаем закрепленные организации пользователя
$orgsStmt = $pdo->prepare("
    SELECT id, name, business_type 
    FROM organizations 
    WHERE user_id = ? OR director_email = ? 
    ORDER BY id ASC
");
$orgsStmt->execute([$userId, $user['email']]);
$organizations = $orgsStmt->fetchAll();

// Если админ - загрузим все организации
if (Auth::isAdmin() && empty($organizations)) {
    $organizations = $pdo->query("SELECT id, name, business_type FROM organizations ORDER BY id ASC")->fetchAll();
}

if (empty($organizations) && !Auth::isAdmin()) {
    header("Location: /user/request_organization.php");
    exit;
}

$userOrgIds = array_column($organizations, 'id');

// Выбранная текущая организация (по GET или первая из списка)
$selectedOrgId = !empty($_GET['org_id']) ? (int)$_GET['org_id'] : (!empty($organizations[0]['id']) ? (int)$organizations[0]['id'] : null);
$currentOrg = null;
if ($selectedOrgId) {
    foreach ($organizations as $o) {
        if ((int)$o['id'] === $selectedOrgId) {
            $currentOrg = $o;
            break;
        }
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

// Обработка POST действий
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!Security::verifyCsrf($token)) {
        $error = "Истёк токен безопасности. Повторите попытку.";
    } else {
        $action = $_POST['action'] ?? '';

        // 1. Создать категорию
        if ($action === 'create_category') {
            $catName = trim($_POST['category_name'] ?? '');
            $orgId   = !empty($_POST['org_id']) ? (int)$_POST['org_id'] : $selectedOrgId;

            if (empty($catName)) {
                $error = "Введите название категории.";
            } else {
                $pdo->prepare("INSERT INTO menu_categories (user_id, organization_id, name) VALUES (?, ?, ?)")
                    ->execute([$userId, $orgId, $catName]);
                $success = "✅ Категория «" . htmlspecialchars($catName) . "» добавлена!";
            }
        }

        // 2. Удалить категорию
        elseif ($action === 'delete_category') {
            $catId = (int)($_POST['category_id'] ?? 0);
            if ($catId > 0) {
                $pdo->prepare("DELETE FROM menu_categories WHERE id = ?")->execute([$catId]);
                $success = "Категория удалена.";
            }
        }

        // 3. Создать позицию (товар или услугу)
        elseif ($action === 'create_item') {
            $name        = trim($_POST['name'] ?? '');
            $categoryId  = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
            $orgId       = !empty($_POST['organization_id']) ? (int)$_POST['organization_id'] : $selectedOrgId;
            $price       = max(0, (float)($_POST['price'] ?? 0));
            $unit        = trim($_POST['unit'] ?? ($isService ? 'услуга' : 'шт'));
            $barcode     = trim($_POST['barcode'] ?? '');
            $description = trim($_POST['description'] ?? '');

            if (empty($name) || $price <= 0) {
                $error = "Укажите наименование и стоимость в тенге.";
            } else {
                $ins = $pdo->prepare("
                    INSERT INTO menu_items (user_id, category_id, organization_id, name, description, price, unit, barcode, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
                ");
                $ins->execute([$userId, $categoryId, $orgId, $name, $description, $price, $unit, $barcode]);
                $success = "✅ Позиция «" . htmlspecialchars($name) . "» успешно добавлена (" . number_format($price, 0, '.', ' ') . " ₸)!";
            }
        }

        // 4. Обновить позицию
        elseif ($action === 'update_item') {
            $itemId      = (int)($_POST['item_id'] ?? 0);
            $name        = trim($_POST['name'] ?? '');
            $categoryId  = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
            $orgId       = !empty($_POST['organization_id']) ? (int)$_POST['organization_id'] : $selectedOrgId;
            $price       = max(0, (float)($_POST['price'] ?? 0));
            $unit        = trim($_POST['unit'] ?? ($isService ? 'услуга' : 'шт'));
            $barcode     = trim($_POST['barcode'] ?? '');
            $description = trim($_POST['description'] ?? '');

            if ($itemId <= 0 || empty($name) || $price <= 0) {
                $error = "Укажите наименование и стоимость.";
            } else {
                $upd = $pdo->prepare("
                    UPDATE menu_items 
                    SET category_id = ?, organization_id = ?, name = ?, description = ?, price = ?, unit = ?, barcode = ?
                    WHERE id = ?
                ");
                $upd->execute([$categoryId, $orgId, $name, $description, $price, $unit, $barcode, $itemId]);
                $success = "Позиция каталога успешно обновлена.";
            }
        }

        // 5. Переключить активность (В наличии / Приостановлено)
        elseif ($action === 'toggle_item') {
            $itemId = (int)($_POST['item_id'] ?? 0);
            if ($itemId > 0) {
                $pdo->prepare("UPDATE menu_items SET is_active = IF(is_active=1, 0, 1) WHERE id = ?")->execute([$itemId]);
                $success = "Статус позиции изменён.";
            }
        }

        // 6. Удалить позицию
        elseif ($action === 'delete_item') {
            $itemId = (int)($_POST['item_id'] ?? 0);
            if ($itemId > 0) {
                $pdo->prepare("DELETE FROM menu_items WHERE id = ?")->execute([$itemId]);
                $success = "Позиция удалена из каталога.";
            }
        }
    }
}

// Загрузка категорий (для выбранной организации или общих)
if ($selectedOrgId) {
    $catsStmt = $pdo->prepare("SELECT * FROM menu_categories WHERE organization_id = ? OR organization_id IS NULL ORDER BY sort_order ASC, name ASC");
    $catsStmt->execute([$selectedOrgId]);
} else {
    $catsStmt = $pdo->query("SELECT * FROM menu_categories ORDER BY sort_order ASC, name ASC");
}
$categories = $catsStmt->fetchAll();

// Загрузка позиций каталога
if ($selectedOrgId) {
    $itemsStmt = $pdo->prepare("
        SELECT mi.*, mc.name AS category_name, o.name AS org_name, o.business_type
        FROM menu_items mi
        LEFT JOIN menu_categories mc ON mi.category_id = mc.id
        LEFT JOIN organizations o ON mi.organization_id = o.id
        WHERE mi.organization_id = ? OR (mi.user_id = ? AND mi.organization_id IS NULL)
        ORDER BY mi.id DESC
    ");
    $itemsStmt->execute([$selectedOrgId, $userId]);
} else {
    $itemsStmt = $pdo->prepare("
        SELECT mi.*, mc.name AS category_name, o.name AS org_name, o.business_type
        FROM menu_items mi
        LEFT JOIN menu_categories mc ON mi.category_id = mc.id
        LEFT JOIN organizations o ON mi.organization_id = o.id
        WHERE mi.user_id = ? OR mi.user_id IS NULL
        ORDER BY mi.id DESC
    ");
    $itemsStmt->execute([$userId]);
}
$items = $itemsStmt->fetchAll();

$pageTitle = ($isService ? 'Прайс-лист и Услуги' : 'Меню и Товары') . ' | AI Business Assistant';
require dirname(__DIR__) . '/includes/header.php';
?>

<div style="margin-bottom:24px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
    <div>
        <h2>
            <i class="fas <?= $meta['icon'] ?> text-primary"></i> 
            <?= $isService ? 'Прайс-лист и Каталог услуг' : 'Меню и Каталог товаров' ?>
        </h2>
        <p class="text-muted">
            Сфера деятельности: <strong style="color:#818cf8;"><?= htmlspecialchars($meta['title']) ?></strong>
            <?php if ($currentOrg): ?>
                (Организация: <strong><?= htmlspecialchars($currentOrg['name']) ?></strong>)
            <?php endif; ?>
        </p>
    </div>
    <div style="display:flex; gap:10px; align-items:center;">
        <?php if (count($organizations) > 1): ?>
            <form method="GET" style="margin:0;">
                <select name="org_id" class="form-control" onchange="this.form.submit()" style="height:40px; font-weight:600;">
                    <?php foreach ($organizations as $org): ?>
                        <option value="<?= $org['id'] ?>" <?= (int)$org['id'] === $selectedOrgId ? 'selected' : '' ?>>
                            <?= htmlspecialchars($org['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php endif; ?>

        <a href="/user/cashier.php<?= $selectedOrgId ? '?org_id='.$selectedOrgId : '' ?>" class="btn btn-primary">
            <i class="fas fa-cash-register"></i> Открыть Кассу (POS)
        </a>
    </div>
</div>

<?php if ($success): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $success ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div style="display:grid; grid-template-columns: 1fr 2fr; gap:24px; align-items:start;">

    <!-- ЛЕВАЯ КОЛОНКА: Категории и Добавление позиции -->
    <div>
        <!-- Блок категорий -->
        <div class="card mb-4" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:20px;">
            <h4 class="mb-3">
                <i class="fas fa-folder text-warning"></i> 
                <?= $isService ? 'Категории услуг' : 'Категории меню' ?>
            </h4>

            <form method="POST" style="margin-bottom:14px;">
                <?= Security::csrfField() ?>
                <input type="hidden" name="action" value="create_category">
                <input type="hidden" name="org_id" value="<?= $selectedOrgId ?>">
                <div style="display:flex; gap:8px;">
                    <input 
                        type="text" 
                        name="category_name" 
                        class="form-control" 
                        required 
                        placeholder="<?= $isService ? 'Например: Сопровождение, Аудит, Экспертиза' : 'Например: Кофе, Десерты, Завтраки' ?>"
                    >
                    <button type="submit" class="btn btn-warning btn-sm" style="white-space:nowrap;">
                        <i class="fas fa-plus"></i>
                    </button>
                </div>
            </form>

            <div style="display:flex; flex-wrap:wrap; gap:6px;">
                <?php if (empty($categories)): ?>
                    <small class="text-muted">Категорий пока нет</small>
                <?php else: ?>
                    <?php foreach ($categories as $cat): ?>
                        <div style="display:flex; align-items:center; gap:6px; background:var(--bg-secondary); border:1px solid var(--border-color); padding:4px 10px; border-radius:20px; font-size:12px;">
                            <span><?= htmlspecialchars($cat['name']) ?></span>
                            <form method="POST" style="margin:0;" onsubmit="return confirm('Удалить категорию?')">
                                <?= Security::csrfField() ?>
                                <input type="hidden" name="action" value="delete_category">
                                <input type="hidden" name="category_id" value="<?= $cat['id'] ?>">
                                <button type="submit" style="background:none; border:none; color:#ef4444; cursor:pointer; padding:0; font-size:11px;" title="Удалить">
                                    <i class="fas fa-times"></i>
                                </button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Добавить / редактировать позицию -->
        <div class="card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:24px;">
            <h4 id="itemFormTitle" class="mb-3">
                <i class="fas fa-plus-circle text-primary"></i> 
                <?= $isService ? 'Добавить услугу / тариф' : 'Добавить позицию меню' ?>
            </h4>

            <form method="POST" id="itemForm">
                <?= Security::csrfField() ?>
                <input type="hidden" name="action" id="itemFormAction" value="create_item">
                <input type="hidden" name="item_id" id="itemFormId" value="">
                <input type="hidden" name="organization_id" value="<?= $selectedOrgId ?>">

                <div class="form-group">
                    <label class="form-label"><?= $isService ? 'Наименование услуги / пакета *' : 'Наименование блюда / товара *' ?></label>
                    <input 
                        type="text" 
                        name="name" 
                        id="f_item_name" 
                        class="form-control" 
                        required 
                        placeholder="<?= $isService ? 'Подготовка тендерной заявки / Аудит договора' : 'Капучино / Круассан / Сэндвич' ?>"
                    >
                </div>

                <div class="form-group">
                    <label class="form-label">Категория</label>
                    <select name="category_id" id="f_item_category" class="form-control">
                        <option value="">— Без категории —</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="display:grid; grid-template-columns: 2fr 1fr; gap:10px;">
                    <div class="form-group">
                        <label class="form-label">Стоимость (₸) *</label>
                        <input type="number" step="10" min="0" name="price" id="f_item_price" class="form-control" required placeholder="50 000">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Единица</label>
                        <select name="unit" id="f_item_unit" class="form-control">
                            <?php if ($isService): ?>
                                <option value="услуга">услуга</option>
                                <option value="заявка">заявка</option>
                                <option value="час">час</option>
                                <option value="проект">проект</option>
                                <option value="месяц">месяц</option>
                                <option value="аудит">аудит</option>
                            <?php else: ?>
                                <option value="шт">шт</option>
                                <option value="порция">порция</option>
                                <option value="л">л</option>
                                <option value="кг">кг</option>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Артикул / Код услуги</label>
                    <input type="text" name="barcode" id="f_item_barcode" class="form-control" placeholder="TND-01">
                </div>

                <div class="form-group">
                    <label class="form-label">Описание / Состав работ</label>
                    <textarea 
                        name="description" 
                        id="f_item_desc" 
                        class="form-control" 
                        rows="2" 
                        placeholder="<?= $isService ? 'Что входит в услугу, сроки выполнения...' : 'Состав, вес, аллергены...' ?>"
                    ></textarea>
                </div>

                <div style="display:flex; gap:10px;">
                    <button type="submit" id="itemSubmitBtn" class="btn btn-primary" style="flex:1;">
                        <i class="fas fa-save"></i> Сохранить
                    </button>
                    <button type="button" id="itemCancelBtn" class="btn btn-secondary" style="display:none;" onclick="resetItemForm()">
                        Отмена
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ПРАВАЯ КОЛОНКА: Таблица всех позиций -->
    <div class="card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:24px;">
        <h3 class="mb-3">
            <i class="fas fa-list text-secondary"></i> 
            <?= $isService ? 'Прайс-лист услуг' : 'Все позиции меню' ?> (<?= count($items) ?>)
        </h3>

        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th><?= $isService ? 'Услуга / Пакет' : 'Блюдо / Товар' ?></th>
                        <th>Категория</th>
                        <th>Стоимость</th>
                        <th>Статус</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($items)): ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted" style="padding:36px;">
                                <?= $isService ? 'В прайс-листе пока нет услуг. Добавьте первую услугу слева.' : 'В меню пока нет товаров. Добавьте первую позицию слева.' ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($items as $it): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($it['name']) ?></strong>
                                    <?php if (!empty($it['barcode'])): ?>
                                        <small class="text-muted"> [<?= htmlspecialchars($it['barcode']) ?>]</small>
                                    <?php endif; ?>
                                    <?php if (!empty($it['description'])): ?>
                                        <br><small class="text-muted" style="font-size:12px;"><?= htmlspecialchars($it['description']) ?></small>
                                    <?php endif; ?>
                                    <?php if (!empty($it['org_name'])): ?>
                                        <br><small class="text-primary"><i class="fas fa-building" style="font-size:10px;"></i> <?= htmlspecialchars($it['org_name']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($it['category_name'])): ?>
                                        <span class="badge badge-primary"><?= htmlspecialchars($it['category_name']) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong style="color:var(--text-primary); font-size:15px;">
                                        <?= number_format((float)$it['price'], 0, '.', ' ') ?> ₸
                                    </strong>
                                    <small class="text-muted">/ <?= htmlspecialchars($it['unit']) ?></small>
                                </td>
                                <td>
                                    <?php if ($it['is_active']): ?>
                                        <span class="badge badge-success"><?= $isService ? 'Доступна к заказу' : 'В наличии' ?></span>
                                    <?php else: ?>
                                        <span class="badge badge-danger"><?= $isService ? 'Приостановлена' : 'Стоп-лист' ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="display:flex; gap:6px;">
                                        <!-- Редактировать -->
                                        <button 
                                            type="button" 
                                            class="btn btn-secondary btn-sm" 
                                            title="Редактировать"
                                            onclick="editItem(<?= htmlspecialchars(json_encode($it, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>)"
                                        >
                                            <i class="fas fa-edit"></i>
                                        </button>

                                        <!-- Вкл/Выкл активность -->
                                        <form method="POST" style="margin:0; display:inline-block;">
                                            <?= Security::csrfField() ?>
                                            <input type="hidden" name="action" value="toggle_item">
                                            <input type="hidden" name="item_id" value="<?= $it['id'] ?>">
                                            <button 
                                                type="submit" 
                                                class="btn <?= $it['is_active'] ? 'btn-warning' : 'btn-success' ?> btn-sm" 
                                                title="<?= $it['is_active'] ? 'Отключить (в стоп-лист / приостановить)' : 'Включить (активировать)' ?>"
                                            >
                                                <i class="fas fa-power-off"></i>
                                            </button>
                                        </form>

                                        <!-- Удалить -->
                                        <form method="POST" style="margin:0; display:inline-block;" onsubmit="return confirm('Удалить позицию «<?= htmlspecialchars(addslashes($it['name'])) ?>»?')">
                                            <?= Security::csrfField() ?>
                                            <input type="hidden" name="action" value="delete_item">
                                            <input type="hidden" name="item_id" value="<?= $it['id'] ?>">
                                            <button type="submit" class="btn btn-danger btn-sm" title="Удалить навсегда">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function editItem(it) {
    document.getElementById('itemFormTitle').innerHTML = '<i class="fas fa-edit text-warning"></i> Редактировать позицию';
    document.getElementById('itemFormAction').value = 'update_item';
    document.getElementById('itemFormId').value = it.id;

    document.getElementById('f_item_name').value = it.name || '';
    document.getElementById('f_item_category').value = it.category_id || '';
    document.getElementById('f_item_price').value = it.price || '';
    document.getElementById('f_item_unit').value = it.unit || '<?= $isService ? "услуга" : "шт" ?>';
    document.getElementById('f_item_barcode').value = it.barcode || '';
    document.getElementById('f_item_desc').value = it.description || '';

    document.getElementById('itemSubmitBtn').innerHTML = '<i class="fas fa-check"></i> Обновить';
    document.getElementById('itemSubmitBtn').className = 'btn btn-warning';
    document.getElementById('itemCancelBtn').style.display = 'inline-block';
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function resetItemForm() {
    document.getElementById('itemFormTitle').innerHTML = '<i class="fas fa-plus-circle text-primary"></i> <?= $isService ? "Добавить услугу / тариф" : "Добавить позицию меню" ?>';
    document.getElementById('itemFormAction').value = 'create_item';
    document.getElementById('itemFormId').value = '';
    document.getElementById('itemForm').reset();
    document.getElementById('itemSubmitBtn').innerHTML = '<i class="fas fa-save"></i> Сохранить';
    document.getElementById('itemSubmitBtn').className = 'btn btn-primary';
    document.getElementById('itemCancelBtn').style.display = 'none';
}
</script>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
