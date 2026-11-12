<?php
declare(strict_types=1);

/**
 * AI Business Assistant - Admin Organization Requests Management
 * Модерация, одобрение и автоматическое развертывание организаций по заявкам пользователей
 */

require_once dirname(__DIR__) . '/includes/Auth.php';
require_once dirname(__DIR__) . '/includes/Mailer.php';
require_once dirname(__DIR__) . '/includes/OrganizationGenerator.php';

$admin = Auth::requireRole(['support_agent', 'admin', 'vrio_owner']);
$adminId = (int)$admin['id'];
$pdo = Database::getConnection();

$cfg = $pdo->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$baseUrl = rtrim($cfg['app_base_url'] ?? 'http://127.0.0.1:8000', '/');

$businessTypes = OrganizationGenerator::getBusinessTypes();

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!Security::verifyCsrf($token)) {
        $error = "Истёк токен безопасности. Повторите попытку.";
    } else {
        $action = $_POST['action'] ?? '';
        $reqId = (int)($_POST['request_id'] ?? 0);

        $stmt = $pdo->prepare("SELECT * FROM organization_requests WHERE id = ? LIMIT 1");
        $stmt->execute([$reqId]);
        $req = $stmt->fetch();

        if (!$req) {
            $error = "Заявка #{$reqId} не найдена.";
        } else {
            // 1. Одобрение заявки и автоматическое создание компании с каталогом
            if ($action === 'approve') {
                if ($req['status'] === 'approved') {
                    $error = "Эта заявка уже была одобрена ранее.";
                } else {
                    $pdo->beginTransaction();
                    try {
                        // Создание организации
                        $insOrg = $pdo->prepare("
                            INSERT INTO organizations 
                            (user_id, business_type, name, legal_name, director_name, director_email, director_phone, inn, kpp, address, bank_name, bank_account, description, is_active)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
                        ");
                        $insOrg->execute([
                            (int)$req['user_id'],
                            $req['business_type'],
                            $req['name'],
                            $req['legal_name'],
                            $req['director_name'],
                            $req['director_email'],
                            $req['director_phone'],
                            $req['inn'],
                            $req['kpp'],
                            $req['address'],
                            $req['bank_name'],
                            $req['bank_account'],
                            $req['description']
                        ]);
                        $newOrgId = (int)$pdo->lastInsertId();

                        // Генерация каталога и должностей под сферу бизнеса
                        $genStats = OrganizationGenerator::setupOrganization($newOrgId, $req['business_type'], (int)$req['user_id']);

                        // Обновляем статус заявки
                        $updReq = $pdo->prepare("UPDATE organization_requests SET status = 'approved', admin_comment = 'Одобрено администратором' WHERE id = ?");
                        $updReq->execute([$reqId]);

                        $pdo->commit();

                        Security::log(
                            $pdo, 
                            'admin_approve_org_request', 
                            "Одобрена заявка #{$req['request_number']} на создание «{$req['name']}» (Org #{$newOrgId}, Категорий: {$genStats['categories']}, Товаров: {$genStats['items']}, Должностей: {$genStats['positions']})", 
                            $adminId
                        );

                        // Отправка уведомления на email
                        $mailSent = Mailer::sendOrganizationRequestApproved(
                            $req['director_email'],
                            $req['director_name'],
                            $req['request_number'],
                            $req['name'],
                            $baseUrl
                        );

                        $mailNotice = $mailSent ? " На почту <strong>{$req['director_email']}</strong> отправлено письмо-поздравление." : "";

                        $bTitle = $businessTypes[$req['business_type']]['title'] ?? $req['business_type'];
                        $success = "🎉 <strong>Заявка #{$req['request_number']} успешно одобрена!</strong><br>"
                                 . "Организация «<strong>" . htmlspecialchars($req['name']) . "</strong>» создана (ID: #{$newOrgId}).<br>"
                                 . "Автоматически сгенерированы категории ({$genStats['categories']}), позиции/услуги ({$genStats['items']}) и должности ({$genStats['positions']}) под сферу «{$bTitle}»."
                                 . $mailNotice;
                    } catch (\Throwable $e) {
                        $pdo->rollBack();
                        $error = "Ошибка при создании организации: " . $e->getMessage();
                    }
                }
            }

            // 2. Отклонение заявки
            elseif ($action === 'reject') {
                $reason = trim($_POST['admin_comment'] ?? 'Несоответствие указанных реквизитов или отказ оператора.');
                $pdo->prepare("UPDATE organization_requests SET status = 'rejected', admin_comment = ? WHERE id = ?")->execute([$reason, $reqId]);

                Security::log($pdo, 'admin_reject_org_request', "Отклонена заявка #{$req['request_number']} («{$req['name']}»). Причина: {$reason}", $adminId);

                // Отправка уведомления об отклонении
                Mailer::sendOrganizationRequestRejected(
                    $req['director_email'],
                    $req['director_name'],
                    $req['request_number'],
                    $req['name'],
                    $reason,
                    $baseUrl
                );

                $success = "Заявка #{$req['request_number']} отклонена. Уведомление с причиной направлено пользователю.";
            }

            // 3. Перевод в статус "В обработке"
            elseif ($action === 'set_in_review') {
                $pdo->prepare("UPDATE organization_requests SET status = 'in_review' WHERE id = ?")->execute([$reqId]);
                $success = "Статус заявки #{$req['request_number']} изменён на «В обработке».";
            }
        }
    }
}

// Фильтрация
$filter = trim($_GET['status'] ?? 'all');
$where = "1=1";
$params = [];
if (in_array($filter, ['pending', 'in_review', 'approved', 'rejected'], true)) {
    $where = "r.status = ?";
    $params[] = $filter;
}

// Загрузка статистики заявок
$counts = $pdo->query("
    SELECT 
        COUNT(*) AS total,
        SUM(status = 'pending') AS pending_count,
        SUM(status = 'in_review') AS in_review_count,
        SUM(status = 'approved') AS approved_count,
        SUM(status = 'rejected') AS rejected_count
    FROM organization_requests
")->fetch();

// Выборка заявок с данными о пользователе
$sql = "
    SELECT r.*, u.email AS user_email, CONCAT(u.first_name, ' ', u.last_name) AS user_fullname
    FROM organization_requests r
    JOIN users u ON r.user_id = u.id
    WHERE {$where}
    ORDER BY r.id DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();

$pageTitle = 'Заявки на организации | AI Business Assistant';
require dirname(__DIR__) . '/includes/header.php';
?>

<div style="margin-bottom:24px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
    <div>
        <h2><i class="fas fa-clipboard-check text-primary"></i> Заявки на создание организаций</h2>
        <p class="text-muted">Модерация поступающих от пользователей запросов на открытие компаний и автоматическое развертывание касс/меню</p>
    </div>
    <div style="display:flex; gap:10px;">
        <a href="/admin/organizations.php" class="btn btn-secondary">
            <i class="fas fa-building"></i> Действующие организации
        </a>
    </div>
</div>

<?php if ($success): ?>
    <div class="alert alert-success" style="font-size:14px; margin-bottom:24px;"><i class="fas fa-check-circle"></i> <?= $success ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger" style="font-size:14px; margin-bottom:24px;"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Статистические карточки -->
<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:16px; margin-bottom:24px;">
    <a href="/admin/org_requests.php?status=all" style="text-decoration:none;">
        <div class="card" style="background:var(--bg-card); border:1px solid <?= $filter === 'all' ? 'var(--primary)' : 'var(--border-color)' ?>; border-radius:var(--radius-lg); padding:18px;">
            <div class="text-muted" style="font-size:12px; text-transform:uppercase; font-weight:700;">Все заявки</div>
            <div style="font-size:26px; font-weight:800; color:var(--text-primary); margin-top:4px;"><?= (int)($counts['total'] ?? 0) ?></div>
        </div>
    </a>
    <a href="/admin/org_requests.php?status=pending" style="text-decoration:none;">
        <div class="card" style="background:var(--bg-card); border:1px solid <?= $filter === 'pending' ? '#f59e0b' : 'var(--border-color)' ?>; border-radius:var(--radius-lg); padding:18px;">
            <div style="font-size:12px; text-transform:uppercase; font-weight:700; color:#f59e0b;">Ожидают проверки</div>
            <div style="font-size:26px; font-weight:800; color:#f59e0b; margin-top:4px;"><?= (int)($counts['pending_count'] ?? 0) ?></div>
        </div>
    </a>
    <a href="/admin/org_requests.php?status=in_review" style="text-decoration:none;">
        <div class="card" style="background:var(--bg-card); border:1px solid <?= $filter === 'in_review' ? '#3b82f6' : 'var(--border-color)' ?>; border-radius:var(--radius-lg); padding:18px;">
            <div style="font-size:12px; text-transform:uppercase; font-weight:700; color:#3b82f6;">В обработке</div>
            <div style="font-size:26px; font-weight:800; color:#3b82f6; margin-top:4px;"><?= (int)($counts['in_review_count'] ?? 0) ?></div>
        </div>
    </a>
    <a href="/admin/org_requests.php?status=approved" style="text-decoration:none;">
        <div class="card" style="background:var(--bg-card); border:1px solid <?= $filter === 'approved' ? '#10b981' : 'var(--border-color)' ?>; border-radius:var(--radius-lg); padding:18px;">
            <div style="font-size:12px; text-transform:uppercase; font-weight:700; color:#10b981;">Одобрены</div>
            <div style="font-size:26px; font-weight:800; color:#10b981; margin-top:4px;"><?= (int)($counts['approved_count'] ?? 0) ?></div>
        </div>
    </a>
    <a href="/admin/org_requests.php?status=rejected" style="text-decoration:none;">
        <div class="card" style="background:var(--bg-card); border:1px solid <?= $filter === 'rejected' ? '#ef4444' : 'var(--border-color)' ?>; border-radius:var(--radius-lg); padding:18px;">
            <div style="font-size:12px; text-transform:uppercase; font-weight:700; color:#ef4444;">Отклонены</div>
            <div style="font-size:26px; font-weight:800; color:#ef4444; margin-top:4px;"><?= (int)($counts['rejected_count'] ?? 0) ?></div>
        </div>
    </a>
</div>

<!-- Таблица заявок -->
<div class="card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:24px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px; flex-wrap:wrap; gap:12px;">
        <h3 style="font-size:17px; margin:0;"><i class="fas fa-list text-secondary"></i> Список заявок (<?= count($requests) ?>)</h3>
        <div style="display:flex; gap:8px;">
            <a href="/admin/org_requests.php?status=all" class="btn btn-sm <?= $filter === 'all' ? 'btn-primary' : 'btn-secondary' ?>">Все</a>
            <a href="/admin/org_requests.php?status=pending" class="btn btn-sm <?= $filter === 'pending' ? 'btn-primary' : 'btn-secondary' ?>">Ожидание</a>
            <a href="/admin/org_requests.php?status=in_review" class="btn btn-sm <?= $filter === 'in_review' ? 'btn-primary' : 'btn-secondary' ?>">В обработке</a>
            <a href="/admin/org_requests.php?status=approved" class="btn btn-sm <?= $filter === 'approved' ? 'btn-primary' : 'btn-secondary' ?>">Одобрены</a>
            <a href="/admin/org_requests.php?status=rejected" class="btn btn-sm <?= $filter === 'rejected' ? 'btn-primary' : 'btn-secondary' ?>">Отклонены</a>
        </div>
    </div>

    <?php if (empty($requests)): ?>
        <p class="text-muted" style="text-align:center; margin:32px 0;">Заявок с выбранным фильтром не найдено.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table" style="font-size:13px;">
                <thead>
                    <tr>
                        <th>Трек-номер</th>
                        <th>Аккаунт заявителя</th>
                        <th>Название и сфера</th>
                        <th>Генеральный директор</th>
                        <th>Реквизиты и адрес</th>
                        <th>Статус</th>
                        <th style="min-width:180px;">Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requests as $r): 
                        $bTypeTitle = $businessTypes[$r['business_type']]['title'] ?? $r['business_type'];
                        $bTypeIcon = $businessTypes[$r['business_type']]['icon'] ?? 'fa-building';
                    ?>
                        <tr>
                            <td>
                                <strong style="font-family:monospace; color:#818cf8; font-size:14px;">#<?= htmlspecialchars($r['request_number']) ?></strong>
                                <br><small class="text-muted"><?= date('d.m.Y H:i', strtotime($r['created_at'])) ?></small>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($r['user_fullname']) ?></strong>
                                <br><small class="text-muted"><?= htmlspecialchars($r['user_email']) ?></small>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($r['name']) ?></strong>
                                <?php if (!empty($r['legal_name'])): ?>
                                    <br><small class="text-muted"><?= htmlspecialchars($r['legal_name']) ?></small>
                                <?php endif; ?>
                                <br><span class="badge" style="background:#1e1e38; color:#818cf8; font-size:11px; margin-top:4px;">
                                    <i class="fas <?= $bTypeIcon ?>"></i> <?= htmlspecialchars($bTypeTitle) ?>
                                </span>
                            </td>
                            <td>
                                <?= htmlspecialchars($r['director_name']) ?>
                                <br><small class="text-muted"><?= htmlspecialchars($r['director_email']) ?></small>
                                <?php if (!empty($r['director_phone'])): ?>
                                    <br><small class="text-muted"><?= htmlspecialchars($r['director_phone']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($r['inn'])): ?><div>ИНН: <?= htmlspecialchars($r['inn']) ?></div><?php endif; ?>
                                <?php if (!empty($r['address'])): ?><small class="text-muted"><?= htmlspecialchars($r['address']) ?></small><?php endif; ?>
                                <?php if (!empty($r['bank_name'])): ?><br><small class="text-muted"><?= htmlspecialchars($r['bank_name']) ?></small><?php endif; ?>
                            </td>
                            <td>
                                <?php if ($r['status'] === 'pending'): ?>
                                    <span class="badge badge-warning"><i class="fas fa-clock"></i> Ожидает</span>
                                <?php elseif ($r['status'] === 'in_review'): ?>
                                    <span class="badge badge-info"><i class="fas fa-spinner fa-spin"></i> В обработке</span>
                                <?php elseif ($r['status'] === 'approved'): ?>
                                    <span class="badge badge-success"><i class="fas fa-check-circle"></i> Одобрено</span>
                                <?php elseif ($r['status'] === 'rejected'): ?>
                                    <span class="badge badge-danger"><i class="fas fa-times-circle"></i> Отклонено</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display:flex; gap:6px; flex-wrap:wrap;">
                                    <?php if ($r['status'] !== 'approved'): ?>
                                        <!-- Кнопка одобрения -->
                                        <form method="POST" style="margin:0;" onsubmit="return confirm('Одобрить заявку #<?= $r['request_number'] ?>? Будет автоматически создана организация, развернуто меню/касса и отправлено письмо директору.')">
                                            <?= Security::csrfField() ?>
                                            <input type="hidden" name="action" value="approve">
                                            <input type="hidden" name="request_id" value="<?= $r['id'] ?>">
                                            <button type="submit" class="btn btn-success btn-sm" title="Одобрить и создать организацию">
                                                <i class="fas fa-check"></i> Одобрить
                                            </button>
                                        </form>

                                        <?php if ($r['status'] === 'pending'): ?>
                                            <form method="POST" style="margin:0;">
                                                <?= Security::csrfField() ?>
                                                <input type="hidden" name="action" value="set_in_review">
                                                <input type="hidden" name="request_id" value="<?= $r['id'] ?>">
                                                <button type="submit" class="btn btn-secondary btn-sm" title="Взять в работу">
                                                    <i class="fas fa-eye"></i> В работу
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <!-- Кнопка отклонения с комментарием -->
                                        <button type="button" class="btn btn-danger btn-sm" onclick="openRejectModal(<?= $r['id'] ?>, '<?= htmlspecialchars(addslashes($r['request_number'])) ?>', '<?= htmlspecialchars(addslashes($r['name'])) ?>')" title="Отклонить заявку">
                                            <i class="fas fa-ban"></i>
                                        </button>
                                    <?php else: ?>
                                        <a href="/admin/organizations.php" class="btn btn-secondary btn-sm">
                                            <i class="fas fa-external-link-alt"></i> В список
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Модальное окно отклонения заявки -->
<div id="rejectModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7); z-index:9999; align-items:center; justify-content:center; padding:20px;">
    <div class="card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:28px; max-width:480px; width:100%; box-shadow:0 20px 40px rgba(0,0,0,0.5);">
        <h3 id="rejectModalTitle" style="margin-bottom:12px; font-size:18px; color:#ef4444;">
            <i class="fas fa-ban"></i> Отклонение заявки
        </h3>
        <p class="text-muted" style="font-size:13px; margin-bottom:18px;">
            Укажите причину отказа. Данное пояснение будет отправлено заявителю на email.
        </p>

        <form method="POST">
            <?= Security::csrfField() ?>
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="request_id" id="rejectReqId" value="">

            <div class="form-group mb-3">
                <label class="form-label">Причина отклонения *</label>
                <textarea name="admin_comment" class="form-control" rows="3" required placeholder="Укажите, каких данных не хватает (например: некорректный ИНН, неверные реквизиты)...">Несоответствие указанных реквизитов организации или неполные контактные данные.</textarea>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="btn btn-secondary" onclick="closeRejectModal()">Отмена</button>
                <button type="submit" class="btn btn-danger">Отклонить и уведомить</button>
            </div>
        </form>
    </div>
</div>

<script>
function openRejectModal(id, num, name) {
    document.getElementById('rejectReqId').value = id;
    document.getElementById('rejectModalTitle').innerHTML = '<i class="fas fa-ban"></i> Отклонить заявку #' + num + ' (' + name + ')';
    document.getElementById('rejectModal').style.display = 'flex';
}
function closeRejectModal() {
    document.getElementById('rejectModal').style.display = 'none';
}
</script>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
