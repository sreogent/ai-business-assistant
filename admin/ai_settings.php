<?php
declare(strict_types=1);

/**
 * AI Business Assistant - Admin AI Engine Configuration
 * API Key, Provider, Model, System Prompt, Temperature
 */

require_once dirname(__DIR__) . '/includes/Auth.php';

$admin = Auth::requireAdmin();
$pdo = Database::getConnection();

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!Security::verifyCsrf($token)) {
        $error = "Истек токен безопасности. Повторите попытку.";
    } else {
        $settingsToUpdate = [
            'ai_provider'      => trim($_POST['ai_provider']     ?? 'openai_compatible'),
            'ai_api_key'       => trim($_POST['ai_api_key']      ?? ''),
            'ai_api_endpoint'  => trim($_POST['ai_api_endpoint'] ?? 'https://api.openai.com/v1/chat/completions'),
            'ai_model'         => trim($_POST['ai_model']        ?? 'gpt-4o-mini'),
            'ai_temperature'   => trim($_POST['ai_temperature']  ?? '0.2'),
            'ai_system_prompt' => trim($_POST['ai_system_prompt']?? ''),
            'smtp_host'        => trim($_POST['smtp_host']       ?? ''),
            'smtp_port'        => trim($_POST['smtp_port']       ?? '587'),
            'smtp_user'        => trim($_POST['smtp_user']       ?? ''),
            'smtp_pass'        => trim($_POST['smtp_pass']       ?? ''),
            'smtp_encryption'  => trim($_POST['smtp_encryption'] ?? 'tls'),
            'mail_from_email'  => trim($_POST['mail_from_email'] ?? ''),
            'mail_from_name'   => trim($_POST['mail_from_name']  ?? 'AI Business Assistant'),
            'app_base_url'     => rtrim(trim($_POST['app_base_url'] ?? 'http://127.0.0.1:8000'), '/'),
        ];

        $stmt = $pdo->prepare("
            INSERT INTO settings (setting_key, setting_value)
            VALUES (:key, :val1)
            ON DUPLICATE KEY UPDATE setting_value = :val2
        ");

        foreach ($settingsToUpdate as $k => $v) {
            $stmt->execute([':key' => $k, ':val1' => $v, ':val2' => $v]);
        }

        Security::log($pdo, 'admin_update_ai_settings', "Администратор обновил параметры AI шлюза", (int)$admin['id']);
        $success = "Настройки подключения AI успешно сохранены!";
    }
}

// Fetch current settings
$settings = $pdo->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);

$pageTitle = 'Настройки AI | AI Business Assistant';
require dirname(__DIR__) . '/includes/header.php';
?>

<div style="max-width: 800px; margin: 0 auto;">
    <div style="margin-bottom:24px;">
        <h2>Настройки искусственного интеллекта (AI Gateway)</h2>
        <p class="text-muted">Конфигурация провайдера нейросети, секретного API ключа и системных директив</p>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:28px;">
        <form method="POST">
            <?= Security::csrfField() ?>

            <div class="form-group">
                <label class="form-label">Провайдер AI</label>
                <select name="ai_provider" class="form-control">
                    <option value="openai_compatible" <?= ($settings['ai_provider'] ?? '') === 'openai_compatible' ? 'selected' : '' ?>>OpenAI Compatible (OpenAI, DeepSeek, OpenRouter, Groq)</option>
                    <option value="gemini" <?= ($settings['ai_provider'] ?? '') === 'gemini' ? 'selected' : '' ?>>Google Gemini API</option>
                    <option value="mock" <?= ($settings['ai_provider'] ?? '') === 'mock' ? 'selected' : '' ?>>Локальный фактологический анализатор (без внешнего ключа)</option>
                </select>
                <small class="text-muted">Если API ключ не указан, система использует локальный фактологический движок с проверкой реальных записей базы.</small>
            </div>

            <div class="form-group">
                <label class="form-label">Секретный API ключ (API Key)</label>
                <input type="password" name="ai_api_key" class="form-control" value="<?= htmlspecialchars($settings['ai_api_key'] ?? '') ?>" placeholder="sk-...">
                <small class="text-muted">Ключ надежно хранится в таблице settings и используется для обращения к внешним языковым моделям.</small>
            </div>

            <div class="form-group">
                <label class="form-label">API Endpoint URL</label>
                <input type="url" name="ai_api_endpoint" class="form-control" value="<?= htmlspecialchars($settings['ai_api_endpoint'] ?? 'https://api.openai.com/v1/chat/completions') ?>">
            </div>

            <div style="display:grid; grid-template-columns: 2fr 1fr; gap:12px;">
                <div class="form-group">
                    <label class="form-label">Идентификатор модели (Model Name)</label>
                    <input type="text" name="ai_model" class="form-control" value="<?= htmlspecialchars($settings['ai_model'] ?? 'gpt-4o-mini') ?>" placeholder="gpt-4o-mini, gpt-4o, claude-3-5-sonnet">
                </div>
                <div class="form-group">
                    <label class="form-label">Температура (0.0 — 1.0)</label>
                    <input type="number" step="0.1" min="0" max="1" name="ai_temperature" class="form-control" value="<?= htmlspecialchars($settings['ai_temperature'] ?? '0.2') ?>">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Системная инструкция (System Prompt)</label>
                <textarea name="ai_system_prompt" class="form-control" style="min-height:120px;"><?= htmlspecialchars($settings['ai_system_prompt'] ?? 'Ты — профессиональный AI Business Assistant. Отвечай только на основе подтвержденных фактов и предоставленных данных. Не придумывай информацию. Если данных нет или недостаточно, обязательно отвечай: "Недостаточно данных для точного ответа".') ?></textarea>
                <small class="text-muted">Строгая директива: запрещает выдумывать факты и обязывает возвращать фразу «Недостаточно данных для точного ответа».</small>
            </div>

            <hr style="border:0;border-top:1px solid var(--border-color);margin:28px 0;">

            <h4 style="font-size:15px;margin-bottom:4px;"><i class="fas fa-server text-primary"></i> SMTP — настройки для отправки почты</h4>
            <p class="text-muted mb-3" style="font-size:13px;">
                Письма с ключами подписки отправляются через ваш SMTP-сервер.
                Если SMTP не заполнен — письма только сохраняются локально в журнале.
            </p>

            <div style="display:grid; grid-template-columns: 2fr 1fr 1fr; gap:12px;">
                <div class="form-group">
                    <label class="form-label">SMTP Хост</label>
                    <input type="text" name="smtp_host" class="form-control"
                           value="<?= htmlspecialchars($settings['smtp_host'] ?? '') ?>"
                           placeholder="smtp.gmail.com / smtp.mail.ru / smtp.yandex.ru">
                </div>
                <div class="form-group">
                    <label class="form-label">SMTP Порт</label>
                    <input type="number" name="smtp_port" class="form-control"
                           value="<?= htmlspecialchars($settings['smtp_port'] ?? '587') ?>"
                           placeholder="587">
                </div>
                <div class="form-group">
                    <label class="form-label">Шифрование</label>
                    <select name="smtp_encryption" class="form-control">
                        <option value="tls"  <?= ($settings['smtp_encryption'] ?? 'tls') === 'tls'  ? 'selected' : '' ?>>STARTTLS (порт 587)</option>
                        <option value="ssl"  <?= ($settings['smtp_encryption'] ?? '') === 'ssl'  ? 'selected' : '' ?>>SSL/TLS  (порт 465)</option>
                        <option value="none" <?= ($settings['smtp_encryption'] ?? '') === 'none' ? 'selected' : '' ?>>Без шифрования (порт 25)</option>
                    </select>
                </div>
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                <div class="form-group">
                    <label class="form-label">SMTP Логин (Email)</label>
                    <input type="text" name="smtp_user" class="form-control"
                           value="<?= htmlspecialchars($settings['smtp_user'] ?? '') ?>"
                           placeholder="your@gmail.com">
                </div>
                <div class="form-group">
                    <label class="form-label">SMTP Пароль</label>
                    <input type="password" name="smtp_pass" class="form-control"
                           value="<?= htmlspecialchars($settings['smtp_pass'] ?? '') ?>"
                           placeholder="App Password / пароль">
                    <small class="text-muted">Для Gmail: включите 2FA и создайте «Пароль приложения»</small>
                </div>
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                <div class="form-group">
                    <label class="form-label">Email отправителя (From)</label>
                    <input type="email" name="mail_from_email" class="form-control"
                           value="<?= htmlspecialchars($settings['mail_from_email'] ?? '') ?>"
                           placeholder="noreply@yourdomain.com">
                </div>
                <div class="form-group">
                    <label class="form-label">Имя отправителя</label>
                    <input type="text" name="mail_from_name" class="form-control"
                           value="<?= htmlspecialchars($settings['mail_from_name'] ?? 'AI Business Assistant') ?>">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Базовый URL приложения (для ссылок в письмах)</label>
                <input type="url" name="app_base_url" class="form-control"
                       value="<?= htmlspecialchars($settings['app_base_url'] ?? 'http://127.0.0.1:8000') ?>"
                       placeholder="https://yourdomain.com">
                <small class="text-muted">
                    На реальном домене укажите <code>https://yourdomain.com</code>. При разработке — <code>http://127.0.0.1:8000</code>.<br>
                    📂 Все отправленные письма также сохраняются в <code>uploads/mail_log/</code> и видны в
                    <a href="/admin/subscription_keys.php" style="color:var(--primary);">Ключи подписок → Журнал писем</a>.
                </small>
            </div>

            <div style="display:flex; gap:12px; margin-top:20px;">
                <button type="submit" class="btn btn-primary" style="flex:1;">
                    <i class="fas fa-save"></i> Сохранить все настройки
                </button>
                <a href="/admin/test_smtp.php" class="btn btn-secondary" target="_blank">
                    <i class="fas fa-vial"></i> Тест SMTP
                </a>
            </div>
        </form>
    </div>
</div>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
