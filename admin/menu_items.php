<?php
declare(strict_types=1);

/**
 * AI Business Assistant - Menu & Catalog Management (Меню и Товары)
 * Управление категориями и товарами/блюдами для кассового терминала
 */

require_once dirname(__DIR__) . '/includes/Auth.php';

$admin = Auth::requireAdmin();
$pdo = Database::getConnection();
$adminId = (int)$admin['id'];

// Таблицы меню
$pdo->exec("CREATE TABLE IF NOT EXISTS menu_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL,
    name VARCHAR(100) NOT NULL,
    sort_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_mc_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS menu_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NULL,
    organization_id INT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    price DECIMAL(12,2) NOT NULL DEFAULT 0,
    unit VARCHAR(50) NULL DEFAULT 'шт',
    barcode VARCHAR(100) NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_mi_cat FOREIGN KEY (category_id) REFERENCES menu_categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_mi_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!Security::verifyCsrf($token)) {
        $error = "Истёк токен безопасности. Повторите попытку.";
    } else {
        $action = $_POST['action'] ?? '';

        // 1. Создать категорию
        if ($action === 'create_category') {
            $catName = trim($_POST['category_name'] ?? '');
            $orgId   = !empty($_POST['org_id']) ? (int)$_POST['org_id'] : null;
            $sort    = (int)($_POST['sort_order'] ?? 0);

            if (empty($catName)) {
                $error = "Введите название категории.";
            } else {
                $pdo->prepare("INSERT INTO menu_categories (organization_id, name, sort_order) VALUES (?, ?, ?)")
                    ->execute([$orgId, $catName, $sort]);
                $success = "✅ Категория «" . htmlspecialchars($catName) . "» создана!";
            }
        }

        // 2. Удалить категорию
        elseif ($action === 'delete_category') {
            $catId = (int)($_POST['category_id'] ?? 0);
            $pdo->prepare("DELETE FROM menu_categories WHERE id = ?")->execute([$catId]);
            $success = "Категория удалена.";
        }

        // 3. Создать позицию меню / товар
        elseif ($action === 'create_item') {
            $name        = trim($_POST['name'] ?? '');
            $categoryId  = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
            $orgId       = !empty($_POST['organization_id']) ? (int)$_POST['organization_id'] : null;
            $price       = max(0, (float)($_POST['price'] ?? 0));
            $unit        = trim($_POST['unit'] ?? 'шт');
            $barcode     = trim($_POST['barcode'] ?? '');
            $description = trim($_POST['description'] ?? '');

            if (empty($name) || $price <= 0) {
                $error = "Укажите название позиции и корректную цену в тенге.";
            } else {
                $ins = $pdo->prepare("
                    INSERT INTO menu_items (category_id, organization_id, name, description, price, unit, barcode)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $ins->execute([$categoryId, $orgId, $name, $description, $price, $unit, $barcode]);
                $success = "✅ Позиция «" . htmlspecialchars($name) . "» добавлена в меню (" . number_format($price, 0, '.', ' ') . " ₸)!";
            }
        }

        // 4. Обновить позицию
        elseif ($action === 'update_item') {
            $itemId      = (int)($_POST['item_id'] ?? 0);
            $name        = trim($_POST['name'] ?? '');
            $categoryId  = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
            $orgId       = !empty($_POST['organization_id']) ? (int)$_POST['organization_id'] : null;
            $price       = max(0, (float)($_POST['price'] ?? 0));
            $unit        = trim($_POST['unit'] ?? 'шт');
            $barcode     = trim($_POST['barcode'] ?? '');
            $description = trim($_POST['description'] ?? '');

            if ($itemId <= 0 || empty($name) || $price <= 0) {
                $error = "Укажите название и цену.";
            } else {
                $upd = $pdo->prepare("
                    UPDATE menu_items 
                    SET category_id = ?, organization_id = ?, name = ?, description = ?, price = ?, unit = ?, barcode = ?
                    WHERE id = ?
                ");
                $upd->execute([$categoryId, $orgId, $name, $description, $price, $unit, $barcode, $itemId]);
                $success = "Позиция меню обновлена.";
            }
        }

        // 5. Переключить активность товара
        elseif ($action === 'toggle_item') {
            $itemId = (int)($_POST['item_id'] ?? 0);
            $pdo->prepare("UPDATE menu_items SET is_active = IF(is_active=1, 0, 1) WHERE id = ?")->execute([$itemId]);
            $success = "Статус товара изменён.";
        }

        // 6. Удалить товар
        elseif ($action === 'delete_item') {
            $itemId = (int)($_POST['item_id'] ?? 0);
            $pdo->prepare("DELETE FROM menu_items WHERE id = ?")->execute([$itemId]);
            $success = "Позиция меню удалена.";
        }
    }
}

require_once dirname(__DIR__) . '/includes/OrganizationGenerator.php';

// Данные
$filterOrgId = !empty($_GET['org_id']) ? (int)$_GET['org_id'] : 0;
$organizations = $pdo->query("SELECT id, name, business_type FROM organizations WHERE is_active = 1 ORDER BY name ASC")->fetchAll();

$bTypes = OrganizationGenerator::getBusinessTypes();

// Фильтрация категорий
if ($filterOrgId > 0) {
    $catStmt = $pdo->prepare("SELECT mc.*, o.name AS org_name, o.business_type FROM menu_categories mc LEFT JOIN organizations o ON mc.organization_id = o.id WHERE mc.organization_id = ? OR mc.organization_id IS NULL ORDER BY mc.sort_order ASC, mc.name ASC");
    $catStmt->execute([$filterOrgId]);
    $categories = $catStmt->fetchAll();
} else {
    $categories = $pdo->query("SELECT mc.*, o.name AS org_name, o.business_type FROM menu_categories mc LEFT JOIN organizations o ON mc.organization_id = o.id ORDER BY mc.sort_order ASC, mc.name ASC")->fetchAll();
}

// Фильтрация позиций
if ($filterOrgId > 0) {
    $itemsStmt = $pdo->prepare("
        SELECT mi.*, mc.name AS category_name, o.name AS org_name, o.business_type
        FROM menu_items mi
        LEFT JOIN menu_categories mc ON mi.category_id = mc.id
        LEFT JOIN organizations o ON mi.organization_id = o.id
        WHERE mi.organization_id = ?
        ORDER BY mi.id DESC
    ");
    $itemsStmt->execute([$filterOrgId]);
    $items = $itemsStmt->fetchAll();
} else {
    $items = $pdo->query("
        SELECT mi.*, mc.name AS category_name, o.name AS org_name, o.business_type
        FROM menu_items mi
        LEFT JOIN menu_categories mc ON mi.category_id = mc.id
        LEFT JOIN organizations o ON mi.organization_id = o.id
        ORDER BY mi.id DESC
    ")->fetchAll();
}

$pageTitle = 'Каталог товаров и Услуг (Прайс-лист) | AI Business Assistant';
require dirname(__DIR__) . '/includes/header.php';
?>

<div style="margin-bottom:24px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
    <div>
        <h2><i class="fas fa-boxes-stacked text-primary"></i> Каталог товаров и Услуг (Прайс-лист)</h2>
        <p class="text-muted">Управление меню кафе/ресторана, каталогом товаров ритейла и прайс-листами услуг тендерных/IT компаний</p>
    </div>
    <div style="display:flex; gap:10px; align-items:center;">
        <?php if (!empty($organizations)): ?>
            <form method="GET" style="margin:0;">
                <select name="org_id" class="form-control" onchange="this.form.submit()" style="height:40px; font-weight:600;">
                    <option value="0">Все организации (общий каталог)</option>
                    <?php foreach ($organizations as $org): ?>
                        <?php 
                            $b = $org['business_type'] ?? 'general';
                            $title = $bTypes[$b]['title'] ?? $b;
                        ?>
                        <option value="<?= $org['id'] ?>" <?= $filterOrgId === (int)$org['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($org['name']) ?> [<?= htmlspecialchars($title) ?>]
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php endif; ?>

        <a href="/admin/cashier.php<?= $filterOrgId ? '?org_id='.$filterOrgId : '' ?>" class="btn btn-primary">
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

    <!-- ЛЕВАЯ КОЛОНКА: Категории и Добавление товара -->
    <div>
        <!-- Блок категорий -->
        <div class="card mb-4" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:20px;">
            <h4 class="mb-3"><i class="fas fa-folder text-warning"></i> Категории меню</h4>
            
            <form method="POST" style="margin-bottom:16px;">
                <?= Security::csrfField() ?>
                <input type="hidden" name="action" value="create_category">
                <div class="form-group mb-2">
                    <input type="text" name="category_name" class="form-control" required placeholder="Например: Кофе, Десерты, Завтраки">
                </div>
                <div style="display:grid; grid-template-columns: 2fr 1fr; gap:8px;">
                    <select name="org_id" class="form-control" style="font-size:12px;">
                        <option value="">Все организации</option>
                        <?php foreach ($organizations as $org): ?>
                            <option value="<?= $org['id'] ?>"><?= htmlspecialchars($org['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-warning btn-sm">
                        <i class="fas fa-plus"></i> Добавить
                    </button>
                </div>
            </form>

            <div style="display:flex; flex-wrap:wrap; gap:8px;">
                <?php if (empty($categories)): ?>
                    <small class="text-muted">Категорий пока нет</small>
                <?php else: ?>
                    <?php foreach ($categories as $cat): ?>
                        <div style="display:flex; align-items:center; gap:6px; background:var(--bg-secondary); border:1px solid var(--border-color); padding:4px 10px; border-radius:20px; font-size:13px;">
                            <span><?= htmlspecialchars($cat['name']) ?></span>
                            <form method="POST" style="margin:0;" onsubmit="return confirm('Удалить категорию?')">
                                <?= Security::csrfField() ?>
                                <input type="hidden" name="action" value="delete_category">
                                <input type="hidden" name="category_id" value="<?= $cat['id'] ?>">
                                <button type="submit" style="background:none; border:none; color:#ef4444; cursor:pointer; padding:0; font-size:11px;">
                                    <i class="fas fa-times"></i>
                                </button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Форма добавления/редактирования товара -->
        <div class="card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:24px;">
            <h4 id="itemFormTitle" class="mb-3"><i class="fas fa-plus-circle text-primary"></i> Добавить в меню</h4>

            <form method="POST" id="itemForm">
                <?= Security::csrfField() ?>
                <input type="hidden" name="action" id="itemFormAction" value="create_item">
                <input type="hidden" name="item_id" id="itemFormId" value="">

                <div class="form-group">
                    <label class="form-label">Название позиции *</label>
                    <input type="text" name="name" id="f_item_name" class="form-control" required placeholder="Капучино 300мл / Чизкейк Нью-Йорк">
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

                <div class="form-group">
                    <label class="form-label">Организация / Точка</label>
                    <select name="organization_id" id="f_item_org" class="form-control">
                        <option value="">— Все точки —</option>
                        <?php foreach ($organizations as $org): ?>
                            <option value="<?= $org['id'] ?>"><?= htmlspecialchars($org['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="display:grid; grid-template-columns: 2fr 1fr; gap:10px;">
                    <div class="form-group">
                        <label class="form-label">Цена в тенге (₸) *</label>
                        <input type="number" step="10" min="0" name="price" id="f_item_price" class="form-control" required placeholder="1 200">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Единица</label>
                        <select name="unit" id="f_item_unit" class="form-control">
                            <option value="шт">шт</option>
                            <option value="порция">порция</option>
                            <option value="л">л</option>
                            <option value="кг">кг</option>
                            <option value="г">г</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Штрихкод / Артикул</label>
                    <input type="text" name="barcode" id="f_item_barcode" class="form-control" placeholder="487123456789">
                </div>

                <div class="form-group">
                    <label class="form-label">Описание / Состав</label>
                    <textarea name="description" id="f_item_desc" class="form-control" rows="2" placeholder="Эспрессо, пастеризованное молоко..."></textarea>
                </div>

                <div style="display:flex; gap:10px;">
                    <button type="submit" id="itemSubmitBtn" class="btn btn-primary" style="flex:1;">
                        <i class="fas fa-save"></i> Сохранить позицию
                    </button>
                    <button type="button" id="itemCancelBtn" class="btn btn-secondary" style="display:none;" onclick="resetItemForm()">
                        Отмена
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ПРАВАЯ КОЛОНКА: Список всех позиций меню -->
    <div class="card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:24px;">
        <h3 class="mb-3"><i class="fas fa-receipt text-secondary"></i> Все позиции меню (<?= count($items) ?>)</h3>

        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Блюдо / Товар</th>
                        <th>Категория</th>
                        <th>Цена</th>
                        <th>Статус</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($items)): ?>
                        <tr><td colspan="5" class="text-center text-muted" style="padding:36px;">В меню пока нет товаров. Добавьте первую позицию слева.</td></tr>
                    <?php else: ?>
                        <?php foreach ($items as $it): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($it['name']) ?></strong>
                                    <?php if (!empty($it['barcode'])): ?>
                                        <small class="text-muted"> [<?= htmlspecialchars($it['barcode']) ?>]</small>
                                    <?php endif; ?>
                                    <?php if (!empty($it['description'])): ?>
                                        <br><small class="text-muted" style="font-size:11px;"><?= htmlspecialchars($it['description']) ?></small>
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
                                        <span class="badge badge-success">В наличии</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger">Стоп-лист</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                        <button 
                                            type="button" 
                                            class="btn btn-secondary btn-sm" 
                                            title="Редактировать"
                                            onclick="editItem(<?= htmlspecialchars(json_encode($it, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>)"
                                        >
                                            <i class="fas fa-edit"></i>
                                        </button>

                                        <form method="POST" style="margin:0; display:inline-block;">
                                            <?= Security::csrfField() ?>
                                            <input type="hidden" name="action" value="toggle_item">
                                            <input type="hidden" name="item_id" value="<?= $it['id'] ?>">
                                            <button type="submit" class="btn <?= $it['is_active'] ? 'btn-warning' : 'btn-success' ?> btn-sm" title="Вкл/Выкл">
                                                <i class="fas fa-power-off"></i>
                                            </button>
                                        </form>

                                        <form method="POST" style="margin:0; display:inline-block;" onsubmit="return confirm('Удалить позицию «<?= htmlspecialchars(addslashes($it['name'])) ?>»?')">
                                            <?= Security::csrfField() ?>
                                            <input type="hidden" name="action" value="delete_item">
                                            <input type="hidden" name="item_id" value="<?= $it['id'] ?>">
                                            <button type="submit" class="btn btn-danger btn-sm" title="Удалить">
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
    document.getElementById('f_item_org').value = it.organization_id || '';
    document.getElementById('f_item_price').value = it.price || '';
    document.getElementById('f_item_unit').value = it.unit || 'шт';
    document.getElementById('f_item_barcode').value = it.barcode || '';
    document.getElementById('f_item_desc').value = it.description || '';

    document.getElementById('itemSubmitBtn').innerHTML = '<i class="fas fa-check"></i> Обновить';
    document.getElementById('itemSubmitBtn').className = 'btn btn-warning';
    document.getElementById('itemCancelBtn').style.display = 'inline-block';

    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function resetItemForm() {
    document.getElementById('itemFormTitle').innerHTML = '<i class="fas fa-plus-circle text-primary"></i> Добавить в меню';
    document.getElementById('itemFormAction').value = 'create_item';
    document.getElementById('itemFormId').value = '';
    document.getElementById('itemForm').reset();
    document.getElementById('itemSubmitBtn').innerHTML = '<i class="fas fa-save"></i> Сохранить позицию';
    document.getElementById('itemSubmitBtn').className = 'btn btn-primary';
    document.getElementById('itemCancelBtn').style.display = 'none';
}
</script>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
