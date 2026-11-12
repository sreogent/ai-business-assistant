<?php
declare(strict_types=1);

/**
 * AI Business Assistant - User Organization Registration Request
 * Подача и отслеживание статуса заявок на создание и подключение организаций
 */

require_once dirname(__DIR__) . '/includes/Auth.php';
require_once dirname(__DIR__) . '/includes/Mailer.php';
require_once dirname(__DIR__) . '/includes/OrganizationGenerator.php';

$user   = Auth::requireAuth('/login.php');
$userId = (int)$user['id'];
$pdo    = Database::getConnection();

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
        $name          = trim($_POST['name'] ?? '');
        $businessType  = trim($_POST['business_type'] ?? 'general');
        $legalName     = trim($_POST['legal_name'] ?? '');
        $directorName  = trim($_POST['director_name'] ?? '');
        $directorEmail = trim($_POST['director_email'] ?? $user['email']);
        $directorPhone = trim($_POST['director_phone'] ?? '');
        $inn           = trim($_POST['inn'] ?? '');
        $kpp           = trim($_POST['kpp'] ?? '');
        $address       = trim($_POST['address'] ?? '');
        $bankName      = trim($_POST['bank_name'] ?? '');
        $bankAccount   = trim($_POST['bank_account'] ?? '');
        $description   = trim($_POST['description'] ?? '');

        if (empty($name) || empty($directorName) || empty($directorEmail)) {
            $error = "Пожалуйста, заполните обязательные поля: Название организации, ФИО директора и Email.";
        } elseif (!filter_var($directorEmail, FILTER_VALIDATE_EMAIL)) {
            $error = "Укажите корректный email адрес директора.";
        } else {
            // Генерация уникального номера заявки
            $requestNumber = 'ORG-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));

            $ins = $pdo->prepare("
                INSERT INTO organization_requests 
                (request_number, user_id, name, business_type, legal_name, director_name, director_email, director_phone, inn, kpp, address, bank_name, bank_account, description, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
            ");
            $ins->execute([
                $requestNumber, $userId, $name, $businessType, $legalName,
                $directorName, $directorEmail, $directorPhone, $inn, $kpp,
                $address, $bankName, $bankAccount, $description
            ]);

            Security::log($pdo, 'user_create_org_request', "Пользователь #{$userId} подал заявку #{$requestNumber} на создание компании «{$name}»", $userId);

            // Отправка уведомления на почту с присвоенным уникальным номером
            $bTypeTitle = $businessTypes[$businessType]['title'] ?? $businessType;
            $emailSent = Mailer::sendOrganizationRequestCreated($directorEmail, $directorName, $requestNumber, $name, $bTypeTitle, $baseUrl);

            $mailNotice = $emailSent 
                ? "<br>✉️ На почту <strong>" . htmlspecialchars($directorEmail) . "</strong> отправлено официальное подтверждение с номером заявки."
                : "<br>ℹ️ Подтверждение сохранено в журнале отправки писем.";

            $success = "✅ <strong>Заявка успешно принята в обработку!</strong><br>"
                     . "Присвоен уникальный номер: <strong style='font-family:monospace; font-size:16px; color:#818cf8;'>#{$requestNumber}</strong>"
                     . $mailNotice;
        }
    }
}

// Загружаем заявки текущего пользователя
$reqStmt = $pdo->prepare("SELECT * FROM organization_requests WHERE user_id = ? ORDER BY id DESC");
$reqStmt->execute([$userId]);
$myRequests = $reqStmt->fetchAll();

// Проверяем, есть ли уже созданная организация
$checkOrgStmt = $pdo->prepare("SELECT COUNT(*) FROM organizations WHERE user_id = ? OR director_email = ?");
$checkOrgStmt->execute([$userId, $user['email']]);
$hasApprovedOrg = ((int)$checkOrgStmt->fetchColumn()) > 0;

$pageTitle = 'Заявка на организацию | AI Business Assistant';
require dirname(__DIR__) . '/includes/header.php';
?>

<div style="margin-bottom:24px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
    <div>
        <h2><i class="fas fa-building-circle-arrow-right text-primary"></i> Открытие организации & Подключение к POS</h2>
        <p class="text-muted">Подача заявки на регистрацию компании и привязку торговых точек к единой платформе</p>
    </div>
    <?php if ($hasApprovedOrg): ?>
        <a href="/user/organizations.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> В кабинет организации
        </a>
    <?php endif; ?>
</div>

<?php if ($success): ?>
    <div class="alert alert-success" style="font-size:14px; margin-bottom:24px;"><i class="fas fa-check-circle"></i> <?= $success ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger" style="font-size:14px; margin-bottom:24px;"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Информационная плашка для новых пользователей без организации -->
<?php if (!$hasApprovedOrg): ?>
    <div class="card" style="background:rgba(99,102,241,0.08); border:1px solid rgba(99,102,241,0.25); border-radius:var(--radius-lg); padding:20px 24px; margin-bottom:24px;">
        <div style="display:flex; gap:16px; align-items:center;">
            <div style="width:52px; height:52px; border-radius:14px; background:linear-gradient(135deg,#6366f1,#8b5cf6); display:flex; align-items:center; justify-content:center; font-size:24px; color:#fff; flex-shrink:0;">
                <i class="fas fa-store"></i>
            </div>
            <div>
                <h4 style="margin:0 0 4px; color:#818cf8;">У вас пока нет зарегистрированной организации</h4>
                <p style="margin:0; font-size:13px; color:var(--text-muted); line-height:1.6;">
                    Заполните форму ниже для подачи заявки на открытие компании. После подтверждения администратором на вашу почту придёт уведомление, а в системе мгновенно разблокируются: <strong>Онлайн-касса (POS), Каталог меню/товаров, управление должностями сотрудников и аналитика продаж</strong>.
                </p>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Блок существующих заявок пользователя -->
<?php if (!empty($myRequests)): ?>
    <div class="card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:24px; margin-bottom:24px;">
        <h3 class="mb-3" style="font-size:17px;"><i class="fas fa-history text-secondary"></i> Мои поданные заявки (<?= count($myRequests) ?>)</h3>
        <div class="table-responsive">
            <table class="table" style="font-size:13px;">
                <thead>
                    <tr>
                        <th>Номер заявки</th>
                        <th>Организация</th>
                        <th>Сфера бизнеса</th>
                        <th>Директор / Контакты</th>
                        <th>Дата подачи</th>
                        <th>Статус заявки</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($myRequests as $req): 
                        $bTypeTitle = $businessTypes[$req['business_type']]['title'] ?? $req['business_type'];
                        $bTypeIcon = $businessTypes[$req['business_type']]['icon'] ?? 'fa-building';
                    ?>
                        <tr>
                            <td>
                                <strong style="font-family:monospace; color:#818cf8; font-size:14px;">#<?= htmlspecialchars($req['request_number']) ?></strong>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($req['name']) ?></strong>
                                <?php if (!empty($req['legal_name'])): ?>
                                    <br><small class="text-muted"><?= htmlspecialchars($req['legal_name']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span><i class="fas <?= $bTypeIcon ?> text-muted" style="margin-right:4px;"></i> <?= htmlspecialchars($bTypeTitle) ?></span>
                            </td>
                            <td>
                                <?= htmlspecialchars($req['director_name']) ?><br>
                                <small class="text-muted"><?= htmlspecialchars($req['director_email']) ?></small>
                            </td>
                            <td>
                                <small class="text-muted"><?= date('d.m.Y H:i', strtotime($req['created_at'])) ?></small>
                            </td>
                            <td>
                                <?php if ($req['status'] === 'pending'): ?>
                                    <span class="badge badge-warning"><i class="fas fa-clock"></i> Ожидает рассмотрения</span>
                                <?php elseif ($req['status'] === 'in_review'): ?>
                                    <span class="badge badge-info"><i class="fas fa-spinner fa-spin"></i> В обработке</span>
                                <?php elseif ($req['status'] === 'approved'): ?>
                                    <span class="badge badge-success"><i class="fas fa-check-circle"></i> Одобрено и создано</span>
                                <?php elseif ($req['status'] === 'rejected'): ?>
                                    <span class="badge badge-danger"><i class="fas fa-times-circle"></i> Отклонено</span>
                                    <?php if (!empty($req['admin_comment'])): ?>
                                        <div style="font-size:11px; color:#f87171; margin-top:4px;">
                                            Причина: <?= htmlspecialchars($req['admin_comment']) ?>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<!-- Форма подачи новой заявки -->
<div class="card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:32px;">
    <h3 style="margin-bottom:8px;"><i class="fas fa-file-signature text-primary"></i> Подать заявку на регистрацию организации</h3>
    <p class="text-muted mb-4" style="font-size:13px;">Укажите данные предприятия. Заявке будет автоматически присвоен трек-номер и направлено подтверждение на почту.</p>

    <form method="POST">
        <?= Security::csrfField() ?>

        <div class="form-group mb-4">
            <label class="form-label">1. Выберите сферу деятельности предприятия *</label>
            <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap:12px; margin-top:8px;">
                <?php foreach ($businessTypes as $key => $bt): ?>
                    <label style="border:1px solid var(--border-color); border-radius:10px; padding:14px; cursor:pointer; background:var(--bg-secondary); display:flex; align-items:center; gap:10px; transition:border-color 0.2s;">
                        <input type="radio" name="business_type" value="<?= $key ?>" <?= $key === 'coffee_shop' ? 'checked' : '' ?> style="accent-color:#6366f1;">
                        <div>
                            <div style="font-weight:600; font-size:13px;"><i class="fas <?= $bt['icon'] ?> text-primary" style="margin-right:6px;"></i> <?= $bt['title'] ?></div>
                            <small class="text-muted" style="font-size:11px;"><?= $bt['catalog_name'] ?></small>
                        </div>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px;">
            <div class="form-group">
                <label class="form-label">2. Название организации / бренда *</label>
                <input type="text" name="name" class="form-control" placeholder="Например: Coffee Break / Тендер Эксперт" required>
            </div>
            <div class="form-group">
                <label class="form-label">3. Юридическое наименование (ООО / ИП / ТОО)</label>
                <input type="text" name="legal_name" class="form-control" placeholder="ТОО «Кофе Брейк» / ИП Иванов">
            </div>
        </div>

        <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:16px;">
            <div class="form-group">
                <label class="form-label">4. ФИО Генерального Директора *</label>
                <input type="text" name="director_name" class="form-control" value="<?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">5. Email для уведомлений и директора *</label>
                <input type="email" name="director_email" class="form-control" value="<?= htmlspecialchars($user['email']) ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">6. Контактный телефон директора</label>
                <input type="text" name="director_phone" class="form-control" value="<?= htmlspecialchars($user['phone'] ?? '') ?>" placeholder="+7 700 000-00-00">
            </div>
        </div>

        <div style="display:grid; grid-template-columns: 1fr 1fr 2fr; gap:16px;">
            <div class="form-group">
                <label class="form-label">7. ИНН / БИН</label>
                <input type="text" name="inn" class="form-control" placeholder="12 цифр">
            </div>
            <div class="form-group">
                <label class="form-label">8. КПП</label>
                <input type="text" name="kpp" class="form-control" placeholder="9 цифр (если применимо)">
            </div>
            <div class="form-group">
                <label class="form-label">9. Фактический адрес точки / офиса</label>
                <input type="text" name="address" class="form-control" placeholder="г. Астана, пр. Туран 15">
            </div>
        </div>

        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px;">
            <div class="form-group">
                <label class="form-label">10. Наименование банка</label>
                <input type="text" name="bank_name" class="form-control" placeholder="АО Kaspi Bank / Halyk Bank">
            </div>
            <div class="form-group">
                <label class="form-label">11. Расчетный счет (IBAN)</label>
                <input type="text" name="bank_account" class="form-control" placeholder="KZ...">
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">12. Дополнительные пожелания / Описание торговой точки</label>
            <textarea name="description" class="form-control" rows="3" placeholder="Укажите специфику, пожелания по позициям меню или необходимому персоналу..."></textarea>
        </div>

        <div style="margin-top:24px;">
            <button type="submit" class="btn btn-primary" style="padding:14px 32px; font-size:15px; font-weight:700;">
                <i class="fas fa-paper-plane"></i> Отправить заявку администратору
            </button>
        </div>
    </form>
</div>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
