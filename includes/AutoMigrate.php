<?php
declare(strict_types=1);

/**
 * AI Business Assistant — Авто-миграция базы данных
 * Запускается автоматически при каждом старте приложения.
 * Безопасно применяет IF NOT EXISTS — не трогает существующие данные.
 */

require_once __DIR__ . '/Database.php';

class AutoMigrate {

    private static bool $ran = false;

    public static function run(): void {
        if (self::$ran) return;
        self::$ran = true;

        try {
            $pdo = Database::getConnection();
            self::applyMigrations($pdo);
        } catch (\Throwable $e) {
            // Тихо игнорируем — чтобы не сломать страницу если БД недоступна
            error_log('[AutoMigrate] Error: ' . $e->getMessage());
        }
    }

    private static function applyMigrations(\PDO $pdo): void {

        // 1. Прямые сообщения
        $pdo->exec("CREATE TABLE IF NOT EXISTS direct_messages (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            sender_id     INT NOT NULL,
            receiver_id   INT NOT NULL,
            message       TEXT NOT NULL,
            is_read       TINYINT(1) NOT NULL DEFAULT 0,
            created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_dm_sender   FOREIGN KEY (sender_id)   REFERENCES users (id) ON DELETE CASCADE,
            CONSTRAINT fk_dm_receiver FOREIGN KEY (receiver_id) REFERENCES users (id) ON DELETE CASCADE,
            INDEX idx_dm_receiver (receiver_id, is_read),
            INDEX idx_dm_sender   (sender_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 2. Голосовые сообщения в DM
        $pdo->exec("CREATE TABLE IF NOT EXISTS dm_voice_messages (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            dm_id      INT NOT NULL,
            file_path  VARCHAR(255) NOT NULL,
            duration_s INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_dvm_dm FOREIGN KEY (dm_id) REFERENCES direct_messages (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 3. Звонки между сотрудниками
        $pdo->exec("CREATE TABLE IF NOT EXISTS dm_calls (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            caller_id   INT NOT NULL,
            receiver_id INT NOT NULL,
            status      ENUM('calling','active','ended','missed','rejected') NOT NULL DEFAULT 'calling',
            started_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            ended_at    TIMESTAMP NULL,
            duration_s  INT NOT NULL DEFAULT 0,
            CONSTRAINT fk_dmc_caller   FOREIGN KEY (caller_id)   REFERENCES users (id) ON DELETE CASCADE,
            CONSTRAINT fk_dmc_receiver FOREIGN KEY (receiver_id) REFERENCES users (id) ON DELETE CASCADE,
            INDEX idx_call_receiver (receiver_id, status),
            INDEX idx_call_caller   (caller_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 4. Ручная блокировка разделов
        $pdo->exec("CREATE TABLE IF NOT EXISTS section_locks (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            section_key  VARCHAR(100) NOT NULL UNIQUE,
            section_name VARCHAR(150) NOT NULL,
            is_locked    TINYINT(1) NOT NULL DEFAULT 0,
            locked_by    INT NULL,
            locked_until DATETIME NULL,
            reason       VARCHAR(255) NULL,
            created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_sl_user FOREIGN KEY (locked_by) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Заполняем базовые разделы для блокировки
        $sections = [
            ['crm',       'CRM / Клиенты'],
            ['tasks',     'Задачи'],
            ['docs',      'Документы'],
            ['ai',        'AI-Ассистент'],
            ['analytics', 'Аналитика'],
            ['cash',      'Касса / Финансы'],
            ['hr',        'HR / Сотрудники'],
            ['settings',  'Настройки'],
            ['direct',    'Личные сообщения'],
            ['chat',      'Общий чат'],
        ];
        $ins = $pdo->prepare("INSERT IGNORE INTO section_locks (section_key, section_name, is_locked) VALUES (?, ?, 0)");
        foreach ($sections as [$key, $name]) {
            $ins->execute([$key, $name]);
        }

        // 5. Организации
        $pdo->exec("CREATE TABLE IF NOT EXISTS organizations (
            id             INT AUTO_INCREMENT PRIMARY KEY,
            user_id        INT NOT NULL,
            business_type  VARCHAR(50) NOT NULL DEFAULT 'other',
            name           VARCHAR(150) NOT NULL,
            legal_name     VARCHAR(200) NULL,
            director_name  VARCHAR(150) NULL,
            director_email VARCHAR(191) NULL,
            director_phone VARCHAR(50) NULL,
            inn            VARCHAR(20) NULL,
            is_active      TINYINT(1) NOT NULL DEFAULT 1,
            created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_org_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 6. Должности/позиции
        $pdo->exec("CREATE TABLE IF NOT EXISTS positions (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            organization_id INT NOT NULL,
            title           VARCHAR(100) NOT NULL,
            department      VARCHAR(100) NULL,
            salary          DECIMAL(12,2) NULL,
            created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_pos_org FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 7. Баг-репорты
        $pdo->exec("CREATE TABLE IF NOT EXISTS bug_reports (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            reporter_id INT NOT NULL,
            title       VARCHAR(255) NOT NULL,
            description TEXT NOT NULL,
            severity    ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
            status      ENUM('open','in_progress','resolved','closed') NOT NULL DEFAULT 'open',
            assigned_to INT NULL,
            created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_bug_reporter FOREIGN KEY (reporter_id) REFERENCES users (id) ON DELETE CASCADE,
            CONSTRAINT fk_bug_assignee FOREIGN KEY (assigned_to) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 8. Заказы кассы / POS
        $pdo->exec("CREATE TABLE IF NOT EXISTS cash_orders (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            user_id      INT NOT NULL,
            client_id    INT NULL,
            order_number VARCHAR(50) NOT NULL UNIQUE,
            total        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            paid         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            status       ENUM('pending','paid','partial','refunded','cancelled') NOT NULL DEFAULT 'pending',
            note         TEXT NULL,
            created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_co_user   FOREIGN KEY (user_id)   REFERENCES users   (id) ON DELETE CASCADE,
            CONSTRAINT fk_co_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 9. Строки заказа кассы
        $pdo->exec("CREATE TABLE IF NOT EXISTS cash_order_items (
            id       INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            name     VARCHAR(255) NOT NULL,
            qty      DECIMAL(10,3) NOT NULL DEFAULT 1,
            price    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            total    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            CONSTRAINT fk_coi_order FOREIGN KEY (order_id) REFERENCES cash_orders (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 10. Уведомления
        $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            user_id    INT NOT NULL,
            type       VARCHAR(50) NOT NULL DEFAULT 'info',
            title      VARCHAR(255) NOT NULL,
            body       TEXT NULL,
            is_read    TINYINT(1) NOT NULL DEFAULT 0,
            link       VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
            INDEX idx_notif_user (user_id, is_read)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 11. CRM история
        $pdo->exec("CREATE TABLE IF NOT EXISTS crm_history (
            id        INT AUTO_INCREMENT PRIMARY KEY,
            client_id INT NOT NULL,
            user_id   INT NOT NULL,
            action    VARCHAR(100) NOT NULL,
            note      TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_crm_h_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE,
            CONSTRAINT fk_crm_h_user   FOREIGN KEY (user_id)   REFERENCES users   (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 12. Удаляем устаревший столбец activation_key если есть (он был в старой схеме)
        self::dropColumnIfExists($pdo, 'users', 'activation_key');

        // 13. Таблица subscription_keys
        $pdo->exec("CREATE TABLE IF NOT EXISTS subscription_keys (
            id INT AUTO_INCREMENT PRIMARY KEY,
            key_code VARCHAR(64) NOT NULL UNIQUE,
            tariff_id INT NOT NULL,
            created_by INT NULL,
            recipient_email VARCHAR(191) NULL,
            status ENUM('active', 'used', 'revoked') NOT NULL DEFAULT 'active',
            used_by INT NULL,
            used_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_key_code (key_code),
            INDEX idx_key_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 14. Таблица news (Новости платформы)
        $pdo->exec("CREATE TABLE IF NOT EXISTS news (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            category VARCHAR(60) NOT NULL DEFAULT 'Обновление',
            summary TEXT NULL,
            content MEDIUMTEXT NOT NULL,
            image_url VARCHAR(255) NULL,
            author_name VARCHAR(100) NOT NULL DEFAULT 'Команда AI Business Assistant',
            is_published TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_created (created_at DESC)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 15. Добавляем two_fa_enabled и доверенные устройства в users
        self::addColumnIfNotExists($pdo, 'users', 'two_fa_enabled', 'TINYINT(1) NOT NULL DEFAULT 0');
        self::addColumnIfNotExists($pdo, 'users', 'two_fa_trusted_hash', 'VARCHAR(64) NULL');
        self::addColumnIfNotExists($pdo, 'users', 'two_fa_trusted_expires', 'DATETIME NULL');

        // 16. Поддержка гостевых тикетов в support_tickets
        self::addColumnIfNotExists($pdo, 'support_tickets', 'guest_name', 'VARCHAR(150) NULL');
        self::addColumnIfNotExists($pdo, 'support_tickets', 'guest_email', 'VARCHAR(191) NULL');
        self::addColumnIfNotExists($pdo, 'support_tickets', 'guest_token', 'VARCHAR(64) NULL');
        try {
            $pdo->exec("ALTER TABLE support_tickets MODIFY user_id INT NULL");
        } catch (\Throwable $e) {}

        // 17. Таблица organization_members (Сотрудники с доступом к организациям и должности)
        $pdo->exec("CREATE TABLE IF NOT EXISTS organization_members (
            id INT AUTO_INCREMENT PRIMARY KEY,
            organization_id INT NOT NULL,
            user_id INT NOT NULL,
            position_id INT NULL,
            position_title VARCHAR(150) NULL,
            role_in_org ENUM('director', 'manager', 'cashier', 'employee') NOT NULL DEFAULT 'employee',
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_org_user (organization_id, user_id),
            INDEX idx_org (organization_id),
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 18. Колонки в cash_orders и positions
        self::addColumnIfNotExists($pdo, 'cash_orders', 'refund_by', 'INT NULL AFTER status');
        self::addColumnIfNotExists($pdo, 'cash_orders', 'refund_at', 'DATETIME NULL AFTER refund_by');
        self::addColumnIfNotExists($pdo, 'cash_orders', 'refund_reason', 'VARCHAR(255) NULL AFTER refund_at');
        self::addColumnIfNotExists($pdo, 'cash_orders', 'organization_id', 'INT NULL AFTER id');
        self::addColumnIfNotExists($pdo, 'cash_orders', 'cashier_id', 'INT NULL AFTER organization_id');

        self::addColumnIfNotExists($pdo, 'positions', 'organization_id', 'INT NULL AFTER id');
        self::addColumnIfNotExists($pdo, 'positions', 'title', "VARCHAR(150) NOT NULL DEFAULT '' AFTER organization_id");

        // 19. Тариф Free (200 запросов/день, 1 раз) и колонка amount в payment_requests
        self::addColumnIfNotExists($pdo, 'payment_requests', 'amount', 'DECIMAL(10,2) NULL AFTER custom_days');

        // Актуализируем тарифы: Free, Start, Pro, Enterprise, Custom
        try {
            // Тариф Free (7 дней, 200 запросов ИИ в день, бесплатно, 1 раз)
            $freeExists = (int)$pdo->query("SELECT COUNT(*) FROM tariffs WHERE code = 'FREE'")->fetchColumn();
            if ($freeExists === 0) {
                $pdo->prepare("INSERT INTO tariffs (code, name, price, duration_days, ai_requests_limit, is_active) VALUES ('FREE', 'Free (Бесплатный)', 0.00, 7, 200, 1)")->execute();
            } else {
                $pdo->exec("UPDATE tariffs SET name = 'Free (Бесплатный)', price = 0.00, duration_days = 7, ai_requests_limit = 200, is_active = 1 WHERE code = 'FREE'");
            }

            // Актуализируем цены на реальные (2 990 ₸ / 14 990 ₸ / 24 990 ₸)
            $pdo->exec("UPDATE tariffs SET price = 2990.00 WHERE code = 'MONTH' AND price > 2990.00");
            $pdo->exec("UPDATE tariffs SET price = 14990.00 WHERE code = 'HALF_YEAR' AND price > 14990.00");
            $pdo->exec("UPDATE tariffs SET price = 24990.00 WHERE code = 'YEAR' AND price > 24990.00");
            $pdo->exec("UPDATE tariffs SET name = 'Свой тариф (Индивидуальный)', price = 100.00 WHERE code = 'CUSTOM'");

            // Строгий корпоративный стиль: отключаем детские частицы и ставим строгую темную тему
            $pdo->exec("UPDATE settings SET setting_value = 'dark' WHERE setting_key = 'sys_active_theme'");
            $pdo->exec("UPDATE settings SET setting_value = '0' WHERE setting_key = 'sys_theme_particles'");
        } catch (\Throwable $e) {}
    }

    private static function addColumnIfNotExists(\PDO $pdo, string $table, string $column, string $definition): void {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
            $stmt->execute([$table, $column]);
            if ((int)$stmt->fetchColumn() === 0) {
                $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
            }
        } catch (\Throwable $e) {}
    }

    private static function dropColumnIfExists(\PDO $pdo, string $table, string $column): void {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->execute([$table, $column]);
        if ($stmt->fetchColumn() > 0) {
            $pdo->exec("ALTER TABLE `{$table}` DROP COLUMN `{$column}`");
        }
    }
}
