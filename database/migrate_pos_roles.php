<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/Database.php';

$pdo = Database::getConnection();

// Добавляем роли director и manager
$pdo->exec("
    INSERT IGNORE INTO roles (name, description) VALUES 
    ('director', 'Генеральный директор / Руководитель'),
    ('manager', 'Менеджер с правом проведения возвратов чеков')
");

// Добавляем user_id в organizations если нет
$cols = $pdo->query("SHOW COLUMNS FROM organizations LIKE 'user_id'")->fetchAll();
if (empty($cols)) {
    $pdo->exec("ALTER TABLE organizations ADD COLUMN user_id INT NULL AFTER id");
    $pdo->exec("ALTER TABLE organizations ADD CONSTRAINT fk_org_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL");
}

// Добавляем user_id в menu_categories если нет
$cols = $pdo->query("SHOW COLUMNS FROM menu_categories LIKE 'user_id'")->fetchAll();
if (empty($cols)) {
    $pdo->exec("ALTER TABLE menu_categories ADD COLUMN user_id INT NULL AFTER id");
    $pdo->exec("ALTER TABLE menu_categories ADD CONSTRAINT fk_mc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL");
}

// Добавляем user_id в menu_items если нет
$cols = $pdo->query("SHOW COLUMNS FROM menu_items LIKE 'user_id'")->fetchAll();
if (empty($cols)) {
    $pdo->exec("ALTER TABLE menu_items ADD COLUMN user_id INT NULL AFTER id");
    $pdo->exec("ALTER TABLE menu_items ADD CONSTRAINT fk_mi_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL");
}

// Добавляем user_id в positions если нет
$cols = $pdo->query("SHOW COLUMNS FROM positions LIKE 'user_id'")->fetchAll();
if (empty($cols)) {
    $pdo->exec("ALTER TABLE positions ADD COLUMN user_id INT NULL AFTER id");
    $pdo->exec("ALTER TABLE positions ADD CONSTRAINT fk_pos_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL");
}

// Добавляем refund_by и refund_at и refund_reason в cash_orders
$cols = $pdo->query("SHOW COLUMNS FROM cash_orders LIKE 'refund_by'")->fetchAll();
if (empty($cols)) {
    $pdo->exec("ALTER TABLE cash_orders ADD COLUMN refund_by INT NULL AFTER status");
    $pdo->exec("ALTER TABLE cash_orders ADD COLUMN refund_at DATETIME NULL AFTER refund_by");
    $pdo->exec("ALTER TABLE cash_orders ADD COLUMN refund_reason VARCHAR(255) NULL AFTER refund_at");
}

echo "Migration completed successfully.\n";
