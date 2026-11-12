<?php
declare(strict_types=1);

/**
 * AI Business Assistant - User Analytics & Charts
 */

require_once dirname(__DIR__) . '/includes/Auth.php';

$user = Auth::requireAuth('/login.php');
$userId = (int)$user['id'];
$pdo = Database::getConnection();

// Clients analytics by status
$clientStats = [
    'lead' => 0,
    'negotiation' => 0,
    'deal' => 0,
    'archived' => 0
];
$cStmt = $pdo->prepare("SELECT status, COUNT(*) as cnt FROM clients WHERE user_id = ? GROUP BY status");
$cStmt->execute([$userId]);
while ($row = $cStmt->fetch()) {
    if (isset($clientStats[$row['status']])) {
        $clientStats[$row['status']] = (int)$row['cnt'];
    }
}

// Tasks analytics by status
$taskStats = [
    'pending' => 0,
    'in_progress' => 0,
    'completed' => 0,
    'cancelled' => 0
];
$tStmt = $pdo->prepare("SELECT status, COUNT(*) as cnt FROM tasks WHERE user_id = ? GROUP BY status");
$tStmt->execute([$userId]);
while ($row = $tStmt->fetch()) {
    if (isset($taskStats[$row['status']])) {
        $taskStats[$row['status']] = (int)$row['cnt'];
    }
}

// AI Activity over last 7 days
$activityStats = [];
for ($i = 6; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-{$i} days"));
    $activityStats[$day] = 0;
}
$aStmt = $pdo->prepare("
    SELECT DATE(created_at) as dt, COUNT(*) as cnt 
    FROM ai_history 
    WHERE user_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    GROUP BY DATE(created_at)
");
$aStmt->execute([$userId]);
while ($row = $aStmt->fetch()) {
    if (isset($activityStats[$row['dt']])) {
        $activityStats[$row['dt']] = (int)$row['cnt'];
    }
}

$pageTitle = 'Аналитика бизнеса | AI Business Assistant';
require dirname(__DIR__) . '/includes/header.php';
?>

<div style="margin-bottom:24px;">
    <h2>Бизнес-аналитика</h2>
    <p class="text-muted">Наглядные графики воронки клиентов, статуса выполнения задач и динамики использования AI</p>
</div>

<!-- Charts Row -->
<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap:24px; margin-bottom:28px;">
    <!-- CRM Funnel Chart -->
    <div class="card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:24px;">
        <h3 class="mb-3"><i class="fas fa-filter text-primary"></i> Воронка клиентов</h3>
        <canvas id="clientsChart" height="220"></canvas>
    </div>

    <!-- Tasks Distribution Chart -->
    <div class="card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:24px;">
        <h3 class="mb-3"><i class="fas fa-tasks text-success"></i> Статусы задач</h3>
        <canvas id="tasksChart" height="220"></canvas>
    </div>

    <!-- AI Invocations Activity Chart -->
    <div class="card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:24px;">
        <h3 class="mb-3"><i class="fas fa-bolt text-warning"></i> Активность AI (7 дней)</h3>
        <canvas id="activityChart" height="220"></canvas>
    </div>
</div>

<!-- AI Synthesis block -->
<div class="card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:24px;">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
        <div>
            <h3 class="mb-1"><i class="fas fa-magic text-accent"></i> Запросить глубокий анализ у AI</h3>
            <p class="text-muted" style="margin:0;">Сгенерируйте автоматический аналитический отчет по текущим показателям воронки и задачам</p>
        </div>
        <a href="/user/ai_assistant.php?prompt=<?= urlencode('Сделай подробный отчет по текущим показателям воронки клиентов и выполнению задач с рекомендациями по улучшению конверсии') ?>" class="btn btn-primary">
            <i class="fas fa-file-invoice"></i> Сформировать AI отчет
        </a>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    // Clients Funnel
    new Chart(document.getElementById('clientsChart'), {
        type: 'doughnut',
        data: {
            labels: ['Лиды', 'Переговоры', 'Сделки', 'В архиве'],
            datasets: [{
                data: [<?= $clientStats['lead'] ?>, <?= $clientStats['negotiation'] ?>, <?= $clientStats['deal'] ?>, <?= $clientStats['archived'] ?>],
                backgroundColor: ['#3b82f6', '#f59e0b', '#10b981', '#6b7280'],
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { position: 'bottom', labels: { color: '#9ca3af' } } }
        }
    });

    // Tasks Bar
    new Chart(document.getElementById('tasksChart'), {
        type: 'bar',
        data: {
            labels: ['Ожидают', 'В работе', 'Завершены', 'Отменены'],
            datasets: [{
                label: 'Задачи',
                data: [<?= $taskStats['pending'] ?>, <?= $taskStats['in_progress'] ?>, <?= $taskStats['completed'] ?>, <?= $taskStats['cancelled'] ?>],
                backgroundColor: ['#6366f1', '#3b82f6', '#10b981', '#ef4444'],
                borderRadius: 6
            }]
        },
        options: {
            responsive: true,
            scales: {
                y: { beginAtZero: true, ticks: { precision: 0, color: '#9ca3af' } },
                x: { ticks: { color: '#9ca3af' } }
            },
            plugins: { legend: { display: false } }
        }
    });

    // Activity Line
    new Chart(document.getElementById('activityChart'), {
        type: 'line',
        data: {
            labels: [<?= "'" . implode("','", array_map(fn($d) => date('d.m', strtotime($d)), array_keys($activityStats))) . "'" ?>],
            datasets: [{
                label: 'Запросов к AI',
                data: [<?= implode(',', array_values($activityStats)) ?>],
                borderColor: '#8b5cf6',
                backgroundColor: 'rgba(139, 92, 246, 0.15)',
                fill: true,
                tension: 0.3
            }]
        },
        options: {
            responsive: true,
            scales: {
                y: { beginAtZero: true, ticks: { precision: 0, color: '#9ca3af' } },
                x: { ticks: { color: '#9ca3af' } }
            },
            plugins: { legend: { labels: { color: '#9ca3af' } } }
        }
    });
});
</script>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
