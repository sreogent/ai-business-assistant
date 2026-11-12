<?php
declare(strict_types=1);

/**
 * AI Business Assistant - User Tasks Management
 */

require_once dirname(__DIR__) . '/includes/Auth.php';

$user = Auth::requireAuth('/login.php');
$userId = (int)$user['id'];
$pdo = Database::getConnection();

$error = null;
$success = null;

// Handle Task actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!Security::verifyCsrf($token)) {
        $error = "Истек токен безопасности. Повторите попытку.";
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create') {
            $title = trim($_POST['title'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $dueDate = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
            $priority = $_POST['priority'] ?? 'medium';
            $clientId = !empty($_POST['client_id']) ? (int)$_POST['client_id'] : null;

            if (empty($title)) {
                $error = "Укажите название задачи.";
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO tasks (user_id, client_id, title, description, due_date, status, priority)
                    VALUES (?, ?, ?, ?, ?, 'pending', ?)
                ");
                $stmt->execute([$userId, $clientId, $title, $description, $dueDate, $priority]);
                $success = "Задача успешно создана.";
            }
        } elseif ($action === 'status') {
            $taskId = (int)($_POST['task_id'] ?? 0);
            $newStatus = $_POST['new_status'] ?? 'completed';
            $upd = $pdo->prepare("UPDATE tasks SET status = ? WHERE id = ? AND user_id = ?");
            $upd->execute([$newStatus, $taskId, $userId]);
            $success = "Статус задачи обновлен.";
        } elseif ($action === 'delete') {
            $taskId = (int)($_POST['task_id'] ?? 0);
            $del = $pdo->prepare("DELETE FROM tasks WHERE id = ? AND user_id = ?");
            $del->execute([$taskId, $userId]);
            $success = "Задача удалена.";
        }
    }
}

// Fetch tasks and clients
$tasksStmt = $pdo->prepare("
    SELECT t.*, c.name AS client_name 
    FROM tasks t
    LEFT JOIN clients c ON t.client_id = c.id
    WHERE t.user_id = ? 
    ORDER BY t.status ASC, t.id DESC
");
$tasksStmt->execute([$userId]);
$tasks = $tasksStmt->fetchAll();

$clientsList = $pdo->prepare("SELECT id, name FROM clients WHERE user_id = ? ORDER BY name ASC");
$clientsList->execute([$userId]);
$clients = $clientsList->fetchAll();

$pageTitle = 'Управление задачами | AI Business Assistant';
require dirname(__DIR__) . '/includes/header.php';
?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:24px; flex-wrap:wrap; gap:16px;">
    <div>
        <h2>Управление задачами</h2>
        <p class="text-muted">Планирование, контроль сроков и поручений для сотрудников</p>
    </div>
    <div style="display:flex; gap:12px;">
        <a href="/user/ai_assistant.php?prompt=<?= urlencode('Проанализируй все мои открытые задачи и предложи план выполнения на сегодня') ?>" class="btn btn-secondary">
            <i class="fas fa-brain text-primary"></i> AI Оптимизация плана
        </a>
        <button class="btn btn-primary" onclick="document.getElementById('new-task-modal').style.display='block'">
            <i class="fas fa-plus"></i> Новая задача
        </button>
    </div>
</div>

<?php if ($success): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Tasks Table -->
<div class="table-responsive">
    <table class="table">
        <thead>
            <tr>
                <th>Статус</th>
                <th>Название задачи</th>
                <th>Связанный клиент</th>
                <th>Приоритет</th>
                <th>Дедлайн</th>
                <th>Действия</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($tasks)): ?>
                <tr>
                    <td colspan="6" class="text-center text-muted" style="padding: 30px;">
                        Задачи отсутствуют. Создайте первую задачу для организации работы!
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($tasks as $t): ?>
                    <tr>
                        <td>
                            <form method="POST" style="margin:0;">
                                <?= Security::csrfField() ?>
                                <input type="hidden" name="action" value="status">
                                <input type="hidden" name="task_id" value="<?= $t['id'] ?>">
                                <select name="new_status" onchange="this.form.submit()" class="form-control" style="padding:4px 8px; font-size:12px; width:auto; display:inline-block;">
                                    <option value="pending" <?= $t['status'] === 'pending' ? 'selected' : '' ?>>Ожидает</option>
                                    <option value="in_progress" <?= $t['status'] === 'in_progress' ? 'selected' : '' ?>>В работе</option>
                                    <option value="completed" <?= $t['status'] === 'completed' ? 'selected' : '' ?>>Завершена</option>
                                    <option value="cancelled" <?= $t['status'] === 'cancelled' ? 'selected' : '' ?>>Отменена</option>
                                </select>
                            </form>
                        </td>
                        <td>
                            <strong style="<?= $t['status'] === 'completed' ? 'text-decoration:line-through; color:var(--text-muted);' : '' ?>">
                                <?= htmlspecialchars($t['title']) ?>
                            </strong>
                            <?php if (!empty($t['description'])): ?>
                                <div style="font-size:12px; color:var(--text-secondary); margin-top:2px;">
                                    <?= htmlspecialchars($t['description']) ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= htmlspecialchars($t['client_name'] ?? '—') ?>
                        </td>
                        <td>
                            <?php
                            $pClass = match ($t['priority']) {
                                'high' => 'badge-danger',
                                'medium' => 'badge-warning',
                                'low' => 'badge-primary',
                                default => 'badge-secondary'
                            };
                            $pText = match ($t['priority']) {
                                'high' => 'Высокий',
                                'medium' => 'Средний',
                                'low' => 'Низкий',
                                default => $t['priority']
                            };
                            ?>
                            <span class="badge <?= $pClass ?>"><?= $pText ?></span>
                        </td>
                        <td>
                            <?= $t['due_date'] ? date('d.m.Y H:i', strtotime($t['due_date'])) : '<span class="text-muted">—</span>' ?>
                        </td>
                        <td>
                            <form method="POST" onsubmit="return confirm('Удалить эту задачу?')" style="margin:0;">
                                <?= Security::csrfField() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="task_id" value="<?= $t['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm" title="Удалить задачу">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Modal Add Task -->
<div id="new-task-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.7); z-index:999; padding:20px;">
    <div class="auth-card" style="margin: 50px auto; max-width: 500px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
            <h3 style="margin:0;">Создать задачу</h3>
            <button type="button" onclick="document.getElementById('new-task-modal').style.display='none'" style="background:none; border:none; font-size:20px; color:var(--text-muted); cursor:pointer;">&times;</button>
        </div>

        <form method="POST">
            <?= Security::csrfField() ?>
            <input type="hidden" name="action" value="create">

            <div class="form-group">
                <label class="form-label">Название задачи *</label>
                <input type="text" name="title" class="form-control" required placeholder="Подготовить договор поставки">
            </div>

            <div class="form-group">
                <label class="form-label">Связать с клиентом</label>
                <select name="client_id" class="form-control">
                    <option value="">-- Без привязки к клиенту --</option>
                    <?php foreach ($clients as $cl): ?>
                        <option value="<?= $cl['id'] ?>"><?= htmlspecialchars($cl['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                <div class="form-group">
                    <label class="form-label">Приоритет</label>
                    <select name="priority" class="form-control">
                        <option value="low">Низкий</option>
                        <option value="medium" selected>Средний</option>
                        <option value="high">Высокий (Срочно)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Срок выполнения (Дедлайн)</label>
                    <input type="datetime-local" name="due_date" class="form-control">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Подробное описание задачи</label>
                <textarea name="description" class="form-control" placeholder="Указать пункты по срокам оплаты и неустойке..."></textarea>
            </div>

            <button type="submit" class="btn btn-primary btn-block">Создать задачу</button>
        </form>
    </div>
</div>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
