<?php
require __DIR__ . '/../includes/Database.php';
$pdo = Database::getConnection();
$stmt = $pdo->query("SELECT id, key_code, tariff_id, recipient_email, status FROM subscription_keys ORDER BY id DESC LIMIT 10");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

$tariffs = $pdo->query("SELECT id, name, code, duration_days, is_active FROM tariffs")->fetchAll(PDO::FETCH_ASSOC);
echo "TARIFFS:\n" . json_encode($tariffs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
