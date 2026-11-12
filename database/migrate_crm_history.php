<?php
declare(strict_types=1);

/**
 * Migration: CRM History & Extended Client Fields
 */

require_once dirname(__DIR__) . '/includes/Database.php';

try {
    $pdo = Database::getConnection();
    if (!$pdo) {
        die("Ошибка подключения к БД\n");
    }

    // 1. Изменение типа поля status в clients на VARCHAR(50), чтобы поддерживать НДЗ, РЗН, callback и другие
    $pdo->exec("ALTER TABLE clients MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'lead'");

    // 2. Добавление колонок в clients, если их нет
    $columns = $pdo->query("SHOW COLUMNS FROM clients")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('next_contact_at', $columns)) {
        $pdo->exec("ALTER TABLE clients ADD COLUMN next_contact_at DATETIME NULL AFTER status");
    }
    if (!in_array('last_contact_at', $columns)) {
        $pdo->exec("ALTER TABLE clients ADD COLUMN last_contact_at DATETIME NULL AFTER next_contact_at");
    }
    if (!in_array('last_result', $columns)) {
        $pdo->exec("ALTER TABLE clients ADD COLUMN last_result VARCHAR(100) NULL AFTER last_contact_at");
    }
    if (!in_array('deal_sum', $columns)) {
        $pdo->exec("ALTER TABLE clients ADD COLUMN deal_sum DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER last_result");
    }
    if (!in_array('company_name', $columns)) {
        $pdo->exec("ALTER TABLE clients ADD COLUMN company_name VARCHAR(150) NULL AFTER name");
    }
    if (!in_array('position', $columns)) {
        $pdo->exec("ALTER TABLE clients ADD COLUMN position VARCHAR(100) NULL AFTER company_name");
    }

    // 3. Создание таблицы client_history
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS client_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_id INT NOT NULL,
            user_id INT NOT NULL,
            result_type VARCHAR(50) NOT NULL,
            result_label VARCHAR(100) NOT NULL,
            comment TEXT NULL,
            next_contact_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ch_client (client_id),
            INDEX idx_ch_created (created_at),
            CONSTRAINT fk_ch_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE,
            CONSTRAINT fk_ch_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    echo "Migration completed successfully!\n";
} catch (Throwable $e) {
    echo "Migration error: " . $e->getMessage() . "\n";
}
