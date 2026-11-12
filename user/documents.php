<?php
declare(strict_types=1);

/**
 * AI Business Assistant - User Documents Management & Uploads
 * Supports PDF, DOCX, TXT with security validation and parsing
 */

require_once dirname(__DIR__) . '/includes/Auth.php';
require_once dirname(__DIR__) . '/includes/DocumentParser.php';

$user = Auth::requireAuth('/login.php');
$userId = (int)$user['id'];
$pdo = Database::getConnection();

$error = null;
$success = null;

$uploadDir = dirname(__DIR__) . '/uploads/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Upload Handling
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!Security::verifyCsrf($token)) {
        $error = "Истек токен безопасности. Повторите попытку.";
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'upload') {
            if (empty($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
                $error = "Ошибка при загрузке файла. Проверьте размер файла.";
            } else {
                $file = $_FILES['document'];
                $maxSize = 10 * 1024 * 1024; // 10MB limit

                if ($file['size'] > $maxSize) {
                    $error = "Размер файла превышает допустимый лимит (10 МБ).";
                } else {
                    $origName = basename($file['name']);
                    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                    $allowedExts = ['pdf', 'docx', 'txt'];

                    if (!in_array($ext, $allowedExts, true)) {
                        $error = "Недопустимый формат файла. Разрешены только PDF, DOCX и TXT.";
                    } else {
                        // Generate safe unique filename
                        $storedName = 'doc_' . $userId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                        $destination = $uploadDir . $storedName;

                        if (move_uploaded_file($file['tmp_name'], $destination)) {
                            // Extract text using DocumentParser
                            $extractedText = DocumentParser::extractText($destination, $ext);

                            $stmt = $pdo->prepare("
                                INSERT INTO documents (user_id, filename, stored_name, file_path, file_type, file_size, extracted_text)
                                VALUES (?, ?, ?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([
                                $userId,
                                $origName,
                                $storedName,
                                $destination,
                                $ext,
                                $file['size'],
                                $extractedText
                            ]);
                            $newDocId = (int)$pdo->lastInsertId();

                            Security::log($pdo, 'document_uploaded', "Загружен документ: {$origName} ({$ext})", $userId);

                            $success = "Документ «{$origName}» успешно загружен и проанализирован! Извлечено " . mb_strlen($extractedText) . " символов.";
                        } else {
                            $error = "Не удалось сохранить файл на сервере.";
                        }
                    }
                }
            }
        } elseif ($action === 'delete') {
            $docId = (int)($_POST['doc_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT stored_name FROM documents WHERE id = ? AND user_id = ?");
            $stmt->execute([$docId, $userId]);
            $doc = $stmt->fetch();

            if ($doc) {
                $target = $uploadDir . $doc['stored_name'];
                if (file_exists($target)) {
                    @unlink($target);
                }
                $del = $pdo->prepare("DELETE FROM documents WHERE id = ? AND user_id = ?");
                $del->execute([$docId, $userId]);
                $success = "Документ удален.";
            }
        }
    }
}

// Fetch user documents
$docsStmt = $pdo->prepare("SELECT * FROM documents WHERE user_id = ? ORDER BY id DESC");
$docsStmt->execute([$userId]);
$documents = $docsStmt->fetchAll();

$pageTitle = 'Анализ документов | AI Business Assistant';
require dirname(__DIR__) . '/includes/header.php';
?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:24px; flex-wrap:wrap; gap:16px;">
    <div>
        <h2>Анализ документов</h2>
        <p class="text-muted">Загрузка и мгновенный смысловой разбор договоров, отчетов и актов в форматах PDF, DOCX и TXT</p>
    </div>
</div>

<?php if ($success): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Upload Card -->
<div class="card mb-4" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:24px;">
    <h3 class="mb-3"><i class="fas fa-cloud-upload-alt text-primary"></i> Загрузить новый документ</h3>
    <form method="POST" enctype="multipart/form-data">
        <?= Security::csrfField() ?>
        <input type="hidden" name="action" value="upload">

        <div style="display:flex; gap:16px; align-items:flex-end; flex-wrap:wrap;">
            <div style="flex:1; min-width:260px;">
                <label class="form-label">Выберите файл (PDF, DOCX, TXT до 10 МБ)</label>
                <input type="file" name="document" class="form-control" accept=".pdf,.docx,.txt" required>
            </div>
            <button type="submit" class="btn btn-primary" style="height:42px;">
                <i class="fas fa-microchip"></i> Загрузить и распознать текст
            </button>
        </div>
    </form>
</div>

<!-- Documents List -->
<div class="table-responsive">
    <table class="table">
        <thead>
            <tr>
                <th>Файл</th>
                <th>Формат</th>
                <th>Размер</th>
                <th>Объем текста</th>
                <th>Дата загрузки</th>
                <th>Действия</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($documents)): ?>
                <tr>
                    <td colspan="6" class="text-center text-muted" style="padding: 30px;">
                        Документы пока не загружены. Загрузите PDF, DOCX или TXT для анализа нейросетью.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($documents as $d): ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($d['filename']) ?></strong>
                            <div style="font-size:12px; color:var(--text-secondary); max-width:400px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                <?= htmlspecialchars(mb_substr($d['extracted_text'] ?: 'Текст не распознан', 0, 100)) ?>...
                            </div>
                        </td>
                        <td><span class="badge badge-primary"><?= strtoupper($d['file_type']) ?></span></td>
                        <td><?= round($d['file_size'] / 1024, 1) ?> КБ</td>
                        <td><?= mb_strlen($d['extracted_text'] ?? '') ?> симв.</td>
                        <td><small class="text-muted"><?= date('d.m.Y H:i', strtotime($d['created_at'])) ?></small></td>
                        <td>
                            <div style="display:flex; gap:8px;">
                                <a href="/user/ai_assistant.php?doc_id=<?= $d['id'] ?>&prompt=<?= urlencode("Сделай краткое резюме и выдели ключевые пункты документа: {$d['filename']}") ?>" class="btn btn-secondary btn-sm" title="Задать вопрос AI по документу">
                                    <i class="fas fa-robot text-primary"></i> Анализ ИИ
                                </a>
                                <form method="POST" onsubmit="return confirm('Удалить этот документ?')" style="margin:0;">
                                    <?= Security::csrfField() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="doc_id" value="<?= $d['id'] ?>">
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

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
