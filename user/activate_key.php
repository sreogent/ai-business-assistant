<?php
declare(strict_types=1);

/**
 * AI Business Assistant - User: Activate Subscription Key
 * Validates and activates a one-time key issued by the administrator.
 */

require_once dirname(__DIR__) . '/includes/Auth.php';
require_once dirname(__DIR__) . '/includes/Subscription.php';
require_once dirname(__DIR__) . '/includes/AutoMigrate.php';
AutoMigrate::run();

// Если пользователь не залогинен, сохраняем переданный ключ в сессию и перенаправляем на логин
if (!Auth::check()) {
    $incomingKey = trim($_GET['key'] ?? '');
    if (!empty($incomingKey)) {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $_SESSION['pending_activation_key'] = $incomingKey;
    }
    header("Location: /login.php?redirect=" . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$user   = Auth::user();
$userId = (int)$user['id'];
$pdo    = Database::getConnection();

$error   = null;
$success = null;

// Функция нормализации ключа (удаление мусора, замена схожих русских букв на латиницу)
function normalizeSubscriptionKey(string $key): array {
    // Карта замены визуально неотличимых русских букв на латинские
    $homoglyphs = [
        'А' => 'A', 'В' => 'B', 'С' => 'C', 'Е' => 'E', 'Н' => 'H', 'К' => 'K',
        'М' => 'M', 'О' => 'O', 'Р' => 'P', 'Т' => 'T', 'Х' => 'X', 'У' => 'Y',
        'а' => 'A', 'в' => 'B', 'с' => 'C', 'е' => 'E', 'н' => 'H', 'к' => 'K',
        'м' => 'M', 'о' => 'O', 'р' => 'P', 'т' => 'T', 'х' => 'X', 'у' => 'Y',
        '–' => '-', '—' => '-', '−' => '-', '‐' => '-'
    ];
    $translit = strtr($key, $homoglyphs);
    $upper = mb_strtoupper($translit, 'UTF-8');
    // Оставляем только латинские символы и цифры
    $clean = preg_replace('/[^A-Z0-9]/', '', $upper) ?? '';
    $hyphen = '';
    if (strlen($clean) > 0) {
        $parts = str_split($clean, 4);
        $hyphen = implode('-', $parts);
    }
    return [
        'clean'  => $clean,
        'hyphen' => $hyphen,
        'raw'    => trim($key)
    ];
}

// Pre-fill key from URL parameter or session
$rawPreKey = trim($_GET['key'] ?? ($_SESSION['pending_activation_key'] ?? ''));
$normPre = normalizeSubscriptionKey($rawPreKey);
$preKey = $normPre['hyphen'] ?: $rawPreKey;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!Security::verifyCsrf($token)) {
        $error = "Истек токен безопасности. Повторите попытку.";
    } else {
        $rawInput = trim($_POST['key_code'] ?? '');
        $norm = normalizeSubscriptionKey($rawInput);
        $cleanCode = $norm['clean'];
        $hyphenCode = $norm['hyphen'];

        if (empty($cleanCode)) {
            $error = "Введите ключ активации.";
        } else {
            // Ищем ключ по чистому коду, по коду с дефисами или по исходному
            $stmt = $pdo->prepare("
                SELECT sk.*, 
                       COALESCE(t.duration_days, 30) AS duration_days, 
                       COALESCE(t.name, 'Премиум подписка') AS tariff_name, 
                       COALESCE(t.ai_requests_limit, 50) AS ai_requests_limit
                FROM subscription_keys sk
                LEFT JOIN tariffs t ON sk.tariff_id = t.id
                WHERE UPPER(REPLACE(REPLACE(sk.key_code, '-', ''), ' ', '')) = :clean1
                   OR UPPER(TRIM(sk.key_code)) = :clean2
                   OR UPPER(TRIM(sk.key_code)) = :clean3
                LIMIT 1
            ");
            $stmt->execute([
                ':clean1' => $cleanCode,
                ':clean2' => $hyphenCode,
                ':clean3' => $norm['raw']
            ]);
            $keyRow = $stmt->fetch();

            if (!$keyRow) {
                $error = "Ключ «{$rawInput}» не найден. Проверьте правильность ввода символов.";
                Security::log($pdo, 'key_activation_failed', "Неверный ключ: {$rawInput}", $userId);
            } elseif ($keyRow['status'] === 'used') {
                $error = "Этот ключ уже был использован ранее и не может быть активирован повторно.";
            } elseif ($keyRow['status'] === 'revoked') {
                $error = "Этот ключ был аннулирован администратором.";
            } else {
                // Activate subscription
                $tariffId = (int)$keyRow['tariff_id'];
                if (Subscription::subscribe($userId, $tariffId, null, true)) {
                    // Mark key as used
                    $update = $pdo->prepare("
                        UPDATE subscription_keys 
                        SET status = 'used', used_by = ?, used_at = NOW() 
                        WHERE id = ?
                    ");
                    $update->execute([$userId, $keyRow['id']]);

                    unset($_SESSION['pending_activation_key']);

                    Security::log($pdo, 'key_activated', "Ключ {$keyRow['key_code']} (тариф: {$keyRow['tariff_name']}) активирован пользователем #{$userId}", $userId);

                    $days = max(1, (int)$keyRow['duration_days']);
                    $endDate = date('d.m.Y', strtotime("+{$days} days"));
                    $success = "🎉 Подписка «{$keyRow['tariff_name']}» успешно активирована! Действует до <strong>{$endDate}</strong>.";
                } else {
                    $error = "Не удалось активировать подписку. Обратитесь к администратору.";
                }
            }
        }
    }
}

$pageTitle = 'Активация ключа подписки | AI Business Assistant';
require dirname(__DIR__) . '/includes/header.php';
?>

<div style="max-width: 540px; margin: 0 auto;">
    <div style="text-align:center; margin-bottom:28px;">
        <div style="width:64px;height:64px;background:linear-gradient(135deg,var(--primary),var(--accent));border-radius:16px;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:28px;">
            🗝️
        </div>
        <h2>Активация ключа подписки</h2>
        <p class="text-muted">Введите ключ, полученный от администратора, чтобы разблокировать все возможности AI Business Assistant</p>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success" style="font-size:15px;">
            <i class="fas fa-check-circle"></i> <?= $success ?>
        </div>
        <div style="text-align:center; margin-top:20px;">
            <a href="/user/dashboard.php" class="btn btn-primary">
                <i class="fas fa-rocket"></i> Перейти в личный кабинет
            </a>
        </div>
    <?php else: ?>

        <?php if ($error): ?>
            <div class="alert alert-danger"><i class="fas fa-times-circle"></i> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:32px;">
            <form method="POST">
                <?= Security::csrfField() ?>

                <div class="form-group">
                    <label class="form-label" style="font-size:14px; font-weight:600;">Ключ активации</label>
                    <input
                        type="text"
                        name="key_code"
                        id="activationKeyInput"
                        class="form-control"
                        style="text-align:center; font-size:20px; font-weight:700; letter-spacing:4px; font-family:monospace;"
                        placeholder="XXXX-XXXX-XXXX-XXXX"
                        value="<?= htmlspecialchars($preKey) ?>"
                        maxlength="40"
                        required
                        autocomplete="off"
                        oninput="formatKeyInput(this)"
                        onpaste="setTimeout(() => formatKeyInput(this), 10)"
                    >
                    <small class="text-muted" style="display:block; text-align:center; margin-top:8px;">
                        Ключ состоит из 16 символов. Поддерживается вставка из почты с дефисами или без, а также автоматическая замена русской раскладки.
                    </small>
                </div>

                <script>
                function formatKeyInput(el) {
                    const map = {
                        'А':'A','В':'B','С':'C','Е':'E','Н':'H','К':'K','М':'M','О':'O','Р':'P','Т':'T','Х':'X','У':'Y',
                        'а':'A','в':'B','с':'C','е':'E','н':'H','к':'K','м':'M','о':'O','р':'P','т':'T','х':'X','у':'Y',
                        '–':'-','—':'-','−':'-'
                    };
                    let str = el.value;
                    let out = '';
                    for (let ch of str) {
                        out += (map[ch] !== undefined) ? map[ch] : ch;
                    }
                    let clean = out.toUpperCase().replace(/[^A-Z0-9]/g, '');
                    if (clean.length > 16) clean = clean.substring(0, 16);
                    let parts = clean.match(/.{1,4}/g);
                    el.value = parts ? parts.join('-') : clean;
                }
                </script>

                <button type="submit" class="btn btn-primary btn-block" style="font-size:15px; padding:13px;">
                    <i class="fas fa-unlock-alt"></i> Активировать подписку
                </button>
            </form>
        </div>

        <div style="text-align:center; margin-top:20px;">
            <a href="/user/subscription.php" class="text-muted" style="font-size:13px;">
                <i class="fas fa-arrow-left"></i> Посмотреть тарифы
            </a>
        </div>
    <?php endif; ?>
</div>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
