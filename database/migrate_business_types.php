<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/Database.php';

$pdo = Database::getConnection();

// Check if business_type column exists in organizations
$cols = $pdo->query("SHOW COLUMNS FROM organizations")->fetchAll(PDO::FETCH_COLUMN);

if (!in_array('business_type', $cols, true)) {
    $pdo->exec("ALTER TABLE organizations ADD COLUMN business_type VARCHAR(50) NOT NULL DEFAULT 'general' AFTER name");
    echo "Added column business_type to organizations.\n";
} else {
    echo "Column business_type already exists in organizations.\n";
}

// Update existing organization if business_type is default
$pdo->exec("UPDATE organizations SET business_type = 'coffee_shop' WHERE business_type = 'general' AND (name LIKE '%кофе%' OR name LIKE '%coffee%')");
echo "Migration finished.\n";
