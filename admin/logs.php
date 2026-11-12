<?php
declare(strict_types=1);

/**
 * AI Business Assistant - Admin Audit Logs
 * Logins, Errors, Admin Actions, Rate Limiting Triggers
 */

require_once dirname(__DIR__) . '/includes/Auth.php';

$admin = Auth::requireAdmin();
$pdo = Database::getConnection();

// Filter by event type if provided
$filterType = trim($_GET['type'] ?? '');

$sql = "
    SELECT l.*, u.email as user_email 
    FROM logs l 
    LEFT JOIN users u ON l.user_id = u.id
";
$params = [];
if (!empty($filterType)) {
    $sql .= " WHERE l.event_type = ?";
    $params[] = $filterType;
}
$sql .= " ORDER BY l.id DESC LIMIT 100";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Get unique event types for filtering
$typesStmt = $pdo->query("SELECT DISTINCT event_type FROM logs ORDER BY event_type ASC");
$eventTypes = $typesStmt->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = 'Журнал аудита | AI Business Assistant';
require dirname(__DIR__) . '/includes/header.php';
?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:24px; flex-wrap:wrap; gap:16px;">
    <div>
        <h2>Журнал безопасности и событий (Logs)</h2>
        <p class="text-muted">Фиксация попыток входа, действий администратора, блокировок и системных изменений</p>
    </div>
    <!-- Filter -->
    <form method="GET" style="display:flex; gap:8px;">
        <select name="type" class="form-control" onchange="this.form.submit()" style="width:auto;">
            <option value="">Все типы событий</option>
            <?php foreach ($eventTypes as $et): ?>
                <option value="<?= htmlspecialchars($et) ?>" <?= $filterType === $et ? 'selected' : '' ?>>
                    <?= htmlspecialchars($et) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if (!empty($filterType)): ?>
            <a href="/admin/logs.php" class="btn btn-secondary btn-sm">Сброс</a>
        <?php endif; ?>
    </form>
</div>

<div class="table-responsive">
    <table class="table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Событие</th>
                <th>Пользователь</th>
                <th>Детали события</th>
                <th>IP адрес</th>
                <th>User Agent</th>
                <th>Время (МСК)</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($logs)): ?>
                <tr><td colspan="7" class="text-center text-muted" style="padding:30px;">Журнал логов пока пуст</td></tr>
            <?php else: ?>
                <?php foreach ($logs as $l): ?>
                    <tr>
                        <td>#<?= $l['id'] ?></td>
                        <td>
                            <?php
                            $badgeClass = match (true) {
                                str_contains($l['event_type'], 'admin') => 'badge-warning',
                                str_contains($l['event_type'], 'failed') || str_contains($l['event_type'], 'blocked') => 'badge-danger',
                                str_contains($l['event_type'], 'success') => 'badge-success',
                                default => 'badge-primary'
                            };
                            ?>
                            <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($l['event_type']) ?></span>
                        </td>
                        <td>
                            <?= $l['user_email'] ? htmlspecialchars($l['user_email']) : '<span class="text-muted">Гость / Системный</span>' ?>
                        </td>
                        <td style="max-width:320px;"><?= htmlspecialchars($l['details'] ?? '—') ?></td>
                        <td><small class="text-muted"><?= htmlspecialchars($l['ip_address']) ?></small></td>
                        <td style="max-width:180px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                            <small class="text-muted" title="<?= htmlspecialchars($l['user_agent'] ?? '') ?>">
                                <?= htmlspecialchars($l['user_agent'] ?: '—') ?>
                            </small>
                        </td>
                        <td><small><?= date('d.m.Y H:i:s', strtotime($l['created_at'])) ?></small></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
