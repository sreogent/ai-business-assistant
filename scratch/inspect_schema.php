<?php
require_once __DIR__ . '/../includes/Database.php';
$pdo = Database::getConnection();

echo "=== TABLES ===\n";
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $t) {
    if (preg_match('/org|pos|role|user|cash|receipt|check|sale/i', $t)) {
        echo "- $t\n";
    }
}

foreach (['organizations', 'positions', 'organization_members', 'users', 'roles', 'receipts', 'sales', 'cashier_shifts', 'cashier_receipts'] as $tbl) {
    if (in_array($tbl, $tables)) {
        echo "\n=== SCHEMA: $tbl ===\n";
        $cols = $pdo->query("DESCRIBE `$tbl`")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cols as $c) {
            echo "  {$c['Field']} ({$c['Type']}) " . ($c['Null'] === 'YES' ? 'NULL' : 'NOT NULL') . " " . ($c['Default'] !== null ? "DEFAULT '{$c['Default']}'" : "") . "\n";
        }
    }
}
