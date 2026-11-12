<?php
declare(strict_types=1);

/**
 * AI Business Assistant - Positions & Staff Roles
 * Управление штатным расписанием, должностями, привязкой к организациям и окладам
 */

require_once dirname(__DIR__) . '/includes/Auth.php';

$admin = Auth::requireAdmin();
$pdo = Database::getConnection();
$adminId = (int)$admin['id'];

// Таблица positions
$pdo->exec("CREATE TABLE IF NOT EXISTS positions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL,
    name VARCHAR(255) NOT NULL,
    department VARCHAR(100) NULL,
    description TEXT NULL,
    salary_min DECIMAL(12,2) NULL,
    salary_max DECIMAL(12,2) NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pos_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!Security::verifyCsrf($token)) {
        $error = "Истёк токен безопасности. Повторите попытку.";
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create') {
            $orgId      = !empty($_POST['organization_id']) ? (int)$_POST['organization_id'] : null;
            $name       = trim($_POST['name'] ?? '');
            $department = trim($_POST['department'] ?? '');
            $salaryMin  = !empty($_POST['salary_min']) ? (float)$_POST['salary_min'] : null;
            $salaryMax  = !empty($_POST['salary_max']) ? (float)$_POST['salary_max'] : null;
            $description= trim($_POST['description'] ?? '');

            if (empty($name)) {
                $error = "Укажите название должности.";
            } else {
                $ins = $pdo->prepare("
                    INSERT INTO positions (organization_id, name, department, salary_min, salary_max, description)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $ins->execute([$orgId, $name, $department, $salaryMin, $salaryMax, $description]);
                Security::log($pdo, 'admin_create_position', "Создана должность: {$name}", $adminId);
                $success = "✅ Должность «" . htmlspecialchars($name) . "» добавлена!";
            }
        } elseif ($action === 'update') {
            $posId      = (int)($_POST['pos_id'] ?? 0);
            $orgId      = !empty($_POST['organization_id']) ? (int)$_POST['organization_id'] : null;
            $name       = trim($_POST['name'] ?? '');
            $department = trim($_POST['department'] ?? '');
            $salaryMin  = !empty($_POST['salary_min']) ? (float)$_POST['salary_min'] : null;
            $salaryMax  = !empty($_POST['salary_max']) ? (float)$_POST['salary_max'] : null;
            $description= trim($_POST['description'] ?? '');

            if ($posId <= 0 || empty($name)) {
                $error = "Укажите название должности.";
            } else {
                $upd = $pdo->prepare("
                    UPDATE positions 
                    SET organization_id = ?, name = ?, department = ?, salary_min = ?, salary_max = ?, description = ?
                    WHERE id = ?
                ");
                $upd->execute([$orgId, $name, $department, $salaryMin, $salaryMax, $description, $posId]);
                Security::log($pdo, 'admin_update_position', "Обновлена должность #{$posId}", $adminId);
                $success = "Должность успешно обновлена.";
            }
        } elseif ($action === 'toggle_status') {
            $posId = (int)($_POST['pos_id'] ?? 0);
            $pdo->prepare("UPDATE positions SET is_active = IF(is_active=1, 0, 1) WHERE id = ?")->execute([$posId]);
            $success = "Статус должности изменён.";
        } elseif ($action === 'delete') {
            $posId = (int)($_POST['pos_id'] ?? 0);
            $pdo->prepare("DELETE FROM positions WHERE id = ?")->execute([$posId]);
            Security::log($pdo, 'admin_delete_position', "Удалена должность #{$posId}", $adminId);
            $success = "Должность удалена.";
        }
    }
}

// Загружаем организации для выпадающего списка
$organizations = $pdo->query("SELECT id, name FROM organizations WHERE is_active = 1 ORDER BY name ASC")->fetchAll();

// Загружаем список должностей
$positions = $pdo->query("
    SELECT p.*, o.name AS organization_name
    FROM positions p
    LEFT JOIN organizations o ON p.organization_id = o.id
    ORDER BY p.id DESC
")->fetchAll();

$pageTitle = 'Должности и роли | AI Business Assistant';
require dirname(__DIR__) . '/includes/header.php';
?>

<div style="margin-bottom:24px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
    <div>
        <h2><i class="fas fa-user-tag text-primary"></i> Должности и Штатное расписание</h2>
        <p class="text-muted">Создание должностей компании, отделов, вилок зарплат в тенге (₸)</p>
    </div>
    <div style="display:flex; gap:10px;">
        <a href="/admin/organizations.php" class="btn btn-secondary">
            <i class="fas fa-building"></i> Организации
        </a>
        <a href="/admin/cashier.php" class="btn btn-primary">
            <i class="fas fa-cash-register"></i> Касса
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

    <!-- Форма создания/редактирования должности -->
    <div class="card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:24px;">
        <h3 id="formTitle" class="mb-3"><i class="fas fa-plus-circle text-primary"></i> Новая должность</h3>

        <form method="POST" id="posForm">
            <?= Security::csrfField() ?>
            <input type="hidden" name="action" id="formAction" value="create">
            <input type="hidden" name="pos_id" id="formPosId" value="">

            <div class="form-group">
                <label class="form-label">Организация</label>
                <select name="organization_id" id="f_org_id" class="form-control">
                    <option value="">— Общая должность (без привязки) —</option>
                    <?php foreach ($organizations as $org): ?>
                        <option value="<?= $org['id'] ?>"><?= htmlspecialchars($org['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted">Если нужной организации нет — добавьте в разделе <a href="/admin/organizations.php" style="color:var(--primary);">«Организации»</a></small>
            </div>

            <div class="form-group">
                <label class="form-label">Название должности *</label>
                <input type="text" name="name" id="f_name" class="form-control" required placeholder="Старший кассир / Бариста / Главный бухгалтер">
            </div>

            <div class="form-group">
                <label class="form-label">Отдел / Департамент</label>
                <input type="text" name="department" id="f_department" class="form-control" placeholder="Отдел продаж / Кухня / Бухгалтерия">
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                <div class="form-group">
                    <label class="form-label">Оклад от (₸)</label>
                    <input type="number" step="1000" min="0" name="salary_min" id="f_salary_min" class="form-control" placeholder="150 000">
                </div>
                <div class="form-group">
                    <label class="form-label">Оклад до (₸)</label>
                    <input type="number" step="1000" min="0" name="salary_max" id="f_salary_max" class="form-control" placeholder="300 000">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Обязанности и требования</label>
                <textarea name="description" id="f_description" class="form-control" rows="3" placeholder="Работа на кассе, обслуживание гостей, составление кассовых отчётов..."></textarea>
            </div>

            <div style="display:flex; gap:10px;">
                <button type="submit" id="submitBtn" class="btn btn-primary" style="flex:1;">
                    <i class="fas fa-save"></i> Сохранить должность
                </button>
                <button type="button" id="cancelBtn" class="btn btn-secondary" style="display:none;" onclick="resetForm()">
                    Отмена
                </button>
            </div>
        </form>
    </div>

    <!-- Таблица должностей -->
    <div class="card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:24px;">
        <h3 class="mb-3"><i class="fas fa-list text-secondary"></i> Список должностей (<?= count($positions) ?>)</h3>

        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Должность / Организация</th>
                        <th>Отдел</th>
                        <th>Оклад (₸)</th>
                        <th>Статус</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($positions)): ?>
                        <tr><td colspan="5" class="text-center text-muted" style="padding:32px;">Должностей пока нет</td></tr>
                    <?php else: ?>
                        <?php foreach ($positions as $pos): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($pos['name']) ?></strong>
                                    <?php if (!empty($pos['organization_name'])): ?>
                                        <br><small class="text-primary"><i class="fas fa-building"></i> <?= htmlspecialchars($pos['organization_name']) ?></small>
                                    <?php else: ?>
                                        <br><small class="text-muted">Общая</small>
                                    <?php endif; ?>
                                    <?php if (!empty($pos['description'])): ?>
                                        <br><small class="text-muted" style="font-size:11px;"><?= htmlspecialchars($pos['description']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($pos['department'] ?: '—') ?></td>
                                <td>
                                    <?php if ($pos['salary_min'] || $pos['salary_max']): ?>
                                        <strong style="color:var(--text-primary);">
                                            <?= $pos['salary_min'] ? number_format((float)$pos['salary_min'], 0, '.', ' ') : '0' ?> 
                                            <?= $pos['salary_max'] ? '— ' . number_format((float)$pos['salary_max'], 0, '.', ' ') : '' ?> ₸
                                        </strong>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($pos['is_active']): ?>
                                        <span class="badge badge-success">Активна</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger">Скрыта</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="display:flex; gap:6px;">
                                        <button type="button" class="btn btn-secondary btn-sm" onclick='editPos(<?= json_encode($pos, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>)'>
                                            <i class="fas fa-edit"></i>
                                        </button>

                                        <form method="POST" style="margin:0;">
                                            <?= Security::csrfField() ?>
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="pos_id" value="<?= $pos['id'] ?>">
                                            <button type="submit" class="btn btn-warning btn-sm" title="Переключить статус">
                                                <i class="fas fa-power-off"></i>
                                            </button>
                                        </form>

                                        <form method="POST" style="margin:0;" onsubmit="return confirm('Удалить должность «<?= htmlspecialchars(addslashes($pos['name'])) ?>»?')">
                                            <?= Security::csrfField() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="pos_id" value="<?= $pos['id'] ?>">
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
function editPos(pos) {
    document.getElementById('formTitle').innerHTML = '<i class="fas fa-edit text-warning"></i> Редактировать должность';
    document.getElementById('formAction').value = 'update';
    document.getElementById('formPosId').value = pos.id;

    document.getElementById('f_org_id').value = pos.organization_id || '';
    document.getElementById('f_name').value = pos.name || '';
    document.getElementById('f_department').value = pos.department || '';
    document.getElementById('f_salary_min').value = pos.salary_min || '';
    document.getElementById('f_salary_max').value = pos.salary_max || '';
    document.getElementById('f_description').value = pos.description || '';

    document.getElementById('submitBtn').innerHTML = '<i class="fas fa-check"></i> Обновить должность';
    document.getElementById('submitBtn').className = 'btn btn-warning';
    document.getElementById('cancelBtn').style.display = 'inline-block';

    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function resetForm() {
    document.getElementById('formTitle').innerHTML = '<i class="fas fa-plus-circle text-primary"></i> Новая должность';
    document.getElementById('formAction').value = 'create';
    document.getElementById('formPosId').value = '';
    document.getElementById('posForm').reset();
    document.getElementById('submitBtn').innerHTML = '<i class="fas fa-save"></i> Сохранить должность';
    document.getElementById('submitBtn').className = 'btn btn-primary';
    document.getElementById('cancelBtn').style.display = 'none';
}
</script>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
