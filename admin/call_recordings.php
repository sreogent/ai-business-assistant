<?php
declare(strict_types=1);

/**
 * AI Business Assistant - Admin Call Recordings
 * Archive of all recorded calls with playback and download actions.
 */

require_once dirname(__DIR__) . '/includes/Auth.php';

$admin = Auth::requireAdmin();
$pdo = Database::getConnection();

// -----------------------------------------------------------------
// Fetch recordings (gracefully handle missing table)
// -----------------------------------------------------------------
$recordings = [];
try {
    $sql = "
        SELECT
            r.id,
            r.call_id,
            r.file_path,
            r.file_size,
            r.duration_sec,
            r.created_at,
            c.name  AS channel_name,
            u.first_name,
            u.last_name,
            u.email,
            tc.status AS call_status
        FROM team_chat_call_recordings r
        LEFT JOIN team_chat_channels c  ON r.channel_id = c.id
        LEFT JOIN users              u  ON r.user_id    = u.id
        LEFT JOIN team_chat_calls    tc ON r.call_id    = tc.id
        ORDER BY r.id DESC
        LIMIT 200
    ";
    $stmt = $pdo->query($sql);
    $recordings = $stmt->fetchAll();
} catch (\PDOException $e) {
    // Table may not exist yet — silently show empty state
    $recordings = [];
}

// -----------------------------------------------------------------
// Apply GET filters (channel name, date range)
// -----------------------------------------------------------------
$filterChannel  = trim($_GET['channel']   ?? '');
$filterDateFrom = trim($_GET['date_from'] ?? '');
$filterDateTo   = trim($_GET['date_to']   ?? '');

if ($filterChannel !== '' || $filterDateFrom !== '' || $filterDateTo !== '') {
    $recordings = array_filter($recordings, static function (array $r) use ($filterChannel, $filterDateFrom, $filterDateTo): bool {
        if ($filterChannel !== '' && stripos((string)($r['channel_name'] ?? ''), $filterChannel) === false) {
            return false;
        }
        if ($filterDateFrom !== '') {
            $recDate = date('Y-m-d', strtotime((string)$r['created_at']));
            if ($recDate < $filterDateFrom) {
                return false;
            }
        }
        if ($filterDateTo !== '') {
            $recDate = date('Y-m-d', strtotime((string)$r['created_at']));
            if ($recDate > $filterDateTo) {
                return false;
            }
        }
        return true;
    });
    $recordings = array_values($recordings);
}

// -----------------------------------------------------------------
// Summary stats
// -----------------------------------------------------------------
$totalCount    = count($recordings);
$totalDuration = (int) array_sum(array_column($recordings, 'duration_sec'));
$totalSize     = (int) array_sum(array_column($recordings, 'file_size'));

// Format total duration as HH:MM:SS
$durationHours   = (int) floor($totalDuration / 3600);
$durationMinutes = (int) floor(($totalDuration % 3600) / 60);
$durationSeconds = $totalDuration % 60;
$totalDurationFormatted = sprintf('%02d:%02d:%02d', $durationHours, $durationMinutes, $durationSeconds);

// Format total size
if ($totalSize >= 1048576) {
    $totalSizeFormatted = number_format($totalSize / 1048576, 2) . ' MB';
} elseif ($totalSize >= 1024) {
    $totalSizeFormatted = number_format($totalSize / 1024, 2) . ' KB';
} else {
    $totalSizeFormatted = $totalSize . ' B';
}

// -----------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------
function formatDuration(int $sec): string
{
    if ($sec >= 3600) {
        return sprintf('%02d:%02d:%02d', (int)floor($sec / 3600), (int)floor(($sec % 3600) / 60), $sec % 60);
    }
    return sprintf('%02d:%02d', (int)floor($sec / 60), $sec % 60);
}

function formatFileSize(int $bytes): string
{
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    }
    return $bytes . ' B';
}

$pageTitle = 'Записи созвонов | AI Business Assistant';
require dirname(__DIR__) . '/includes/header.php';
?>

<div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:24px; flex-wrap:wrap; gap:16px;">
    <div>
        <h2>🎙️ Архив записей созвонов</h2>
        <p class="text-muted">Все записи аудио-звонков в командных каналах. Воспроизведение и скачивание доступны сразу.</p>
    </div>
</div>

<!-- Summary cards -->
<div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:16px; margin-bottom:28px;">
    <div style="background:var(--card-bg,#1e1e2e); border:1px solid var(--border-color,#333); border-radius:10px; padding:20px; text-align:center;">
        <div style="font-size:2rem; font-weight:700; color:#7c6bff;"><?= $totalCount ?></div>
        <div style="color:#aaa; margin-top:4px;">Всего записей</div>
    </div>
    <div style="background:var(--card-bg,#1e1e2e); border:1px solid var(--border-color,#333); border-radius:10px; padding:20px; text-align:center;">
        <div style="font-size:2rem; font-weight:700; color:#2ecc71;"><?= htmlspecialchars($totalDurationFormatted) ?></div>
        <div style="color:#aaa; margin-top:4px;">Суммарная длительность</div>
    </div>
    <div style="background:var(--card-bg,#1e1e2e); border:1px solid var(--border-color,#333); border-radius:10px; padding:20px; text-align:center;">
        <div style="font-size:2rem; font-weight:700; color:#e67e22;"><?= htmlspecialchars($totalSizeFormatted) ?></div>
        <div style="color:#aaa; margin-top:4px;">Общий размер файлов</div>
    </div>
</div>

<!-- Filter form -->
<form method="GET" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; margin-bottom:24px;
    background:var(--card-bg,#1e1e2e); border:1px solid var(--border-color,#333); border-radius:10px; padding:16px;">
    <div style="display:flex; flex-direction:column; gap:4px;">
        <label style="font-size:.8rem; color:#aaa;">Канал</label>
        <input type="text" name="channel" class="form-control" placeholder="Название канала…"
               value="<?= htmlspecialchars($filterChannel) ?>" style="width:200px;">
    </div>
    <div style="display:flex; flex-direction:column; gap:4px;">
        <label style="font-size:.8rem; color:#aaa;">Дата от</label>
        <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($filterDateFrom) ?>">
    </div>
    <div style="display:flex; flex-direction:column; gap:4px;">
        <label style="font-size:.8rem; color:#aaa;">Дата до</label>
        <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($filterDateTo) ?>">
    </div>
    <div style="display:flex; gap:8px; padding-bottom:1px;">
        <button type="submit" class="btn btn-primary">🔍 Найти</button>
        <?php if ($filterChannel !== '' || $filterDateFrom !== '' || $filterDateTo !== ''): ?>
            <a href="/admin/call_recordings.php" class="btn btn-secondary">Сброс</a>
        <?php endif; ?>
    </div>
</form>

<!-- Table -->
<div class="table-responsive">
    <table class="table">
        <thead>
            <tr>
                <th>#</th>
                <th>Канал</th>
                <th>Инициатор</th>
                <th>Продолжительность</th>
                <th>Размер</th>
                <th>Дата записи</th>
                <th>Действия</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($recordings)): ?>
                <tr>
                    <td colspan="7" class="text-center text-muted" style="padding:40px; font-size:1.05rem;">
                        🎙️ Записей созвонов ещё нет
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($recordings as $r): ?>
                    <tr>
                        <td><small class="text-muted">#<?= (int)$r['id'] ?></small></td>
                        <td>
                            <?php if (!empty($r['channel_name'])): ?>
                                <span style="color:#7c6bff; font-weight:600;"># <?= htmlspecialchars((string)$r['channel_name']) ?></span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($r['email'])): ?>
                                <div><?= htmlspecialchars(trim((string)$r['first_name'] . ' ' . (string)$r['last_name'])) ?></div>
                                <small class="text-muted"><?= htmlspecialchars((string)$r['email']) ?></small>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span style="font-family:monospace;">
                                <?= htmlspecialchars(formatDuration((int)($r['duration_sec'] ?? 0))) ?>
                            </span>
                        </td>
                        <td>
                            <small><?= htmlspecialchars(formatFileSize((int)($r['file_size'] ?? 0))) ?></small>
                        </td>
                        <td>
                            <small><?= date('d.m.Y H:i', strtotime((string)$r['created_at'])) ?></small>
                            <?php if (!empty($r['call_status'])): ?>
                                <br><span class="badge badge-secondary" style="font-size:.7rem;"><?= htmlspecialchars((string)$r['call_status']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td style="min-width:240px;">
                            <?php $filePath = htmlspecialchars((string)($r['file_path'] ?? '')); ?>
                            <?php if ($filePath !== ''): ?>
                                <audio controls style="height:28px; width:200px; display:block; margin-bottom:6px;">
                                    <source src="<?= $filePath ?>" type="audio/mpeg">
                                    Ваш браузер не поддерживает аудио.
                                </audio>
                                <a href="<?= $filePath ?>" download style="font-size:.85rem;">⬇ Скачать</a>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
