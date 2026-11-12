<?php
declare(strict_types=1);

/**
 * AI Business Assistant - Database Connection & Initialization
 * PDO Prepared Statements, Error Handling & Automatic Migrations
 */

class Database {
    private static $instance = null;
    private static $lastError = null;

    /**
     * Get or create PDO connection
     */
    public static function getConnection(bool $allowFailure = false): ?PDO {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $config = require dirname(__DIR__) . '/config/config.php';
        $dbCfg = $config['db'];

        $dsn = "mysql:host={$dbCfg['host']};port={$dbCfg['port']};dbname={$dbCfg['name']};charset={$dbCfg['charset']}";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
        ];

        try {
            self::$instance = new PDO($dsn, $dbCfg['user'], $dbCfg['pass'], $options);
            self::$lastError = null;
            return self::$instance;
        } catch (PDOException $e) {
            self::$lastError = $e->getMessage();
            
            // If the database does not exist, attempt to connect to server and create database
            if ($e->getCode() == 1049) {
                try {
                    $serverDsn = "mysql:host={$dbCfg['host']};port={$dbCfg['port']};charset={$dbCfg['charset']}";
                    $serverPdo = new PDO($serverDsn, $dbCfg['user'], $dbCfg['pass'], $options);
                    $serverPdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbCfg['name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                    
                    self::$instance = new PDO($dsn, $dbCfg['user'], $dbCfg['pass'], $options);
                    self::$lastError = null;
                    return self::$instance;
                } catch (PDOException $createEx) {
                    self::$lastError = $createEx->getMessage();
                }
            }

            if ($allowFailure) {
                return null;
            }
            throw new Exception("Ошибка подключения к базе данных: " . self::$lastError);
        }
    }

    public static function getLastError(): ?string {
        return self::$lastError;
    }

    /**
     * Check connection and perform automated verification/migration
     * Returns array with status and details
     */
    public static function checkAndMigrate(): array {
        $pdo = self::getConnection(true);
        if (!$pdo) {
            return [
                'success' => false,
                'message' => 'Не удалось подключиться к базе данных. Проверьте .env или доступ к MySQL. Ошибка: ' . self::$lastError,
                'tables_created' => false
            ];
        }

        // Required tables list
        $requiredTables = [
            'roles', 'users', 'tariffs', 'subscriptions', 
            'clients', 'tasks', 'documents', 'ai_history', 
            'logs', 'settings', 'login_attempts', 'subscription_keys'
        ];

        try {
            // Check existing tables
            $stmt = $pdo->query("SHOW TABLES");
            $existingTables = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $missingTables = array_diff($requiredTables, $existingTables);
            $createdAny = false;

            if (!empty($missingTables)) {
                // Execute schema
                $schemaFile = dirname(__DIR__) . '/database/schema.sql';
                if (!file_exists($schemaFile)) {
                    return [
                        'success' => false,
                        'message' => 'Файл схемы database/schema.sql не найден',
                        'tables_created' => false
                    ];
                }

                $sql = file_get_contents($schemaFile);
                $pdo->exec($sql);
                $createdAny = true;
            }

            // Ensure roles table has default roles 'admin' and 'user' if not present
            $rolesCount = (int)$pdo->query("SELECT COUNT(*) FROM roles")->fetchColumn();
            if ($rolesCount === 0) {
                $roleStmt = $pdo->prepare("INSERT INTO roles (name, description) VALUES (:name, :desc)");
                $roleStmt->execute(['name' => 'admin', 'desc' => 'Администратор платформы с полным доступом']);
                $roleStmt->execute(['name' => 'user', 'desc' => 'Стандартный бизнес-пользователь']);
            }

            // Ensure standard tariffs exist (FREE, MONTH, HALF_YEAR, YEAR, CUSTOM)
            $tariffsCount = (int)$pdo->query("SELECT COUNT(*) FROM tariffs")->fetchColumn();
            if ($tariffsCount === 0) {
                $tariffStmt = $pdo->prepare("INSERT INTO tariffs (code, name, price, duration_days, ai_requests_limit, is_active) VALUES (?, ?, ?, ?, ?, ?)");
                $tariffStmt->execute(['FREE', 'Free (Бесплатный)', 0.00, 7, 200, 1]);
                $tariffStmt->execute(['MONTH', 'Месяц (Start)', 2990.00, 30, 300, 1]);
                $tariffStmt->execute(['HALF_YEAR', 'Полгода (Pro)', 14990.00, 180, 2000, 1]);
                $tariffStmt->execute(['YEAR', 'Год (Enterprise)', 24990.00, 365, 5000, 1]);
                $tariffStmt->execute(['CUSTOM', 'Свой тариф (Индивидуальный)', 100.00, 0, 500, 1]);
            }

            // Ensure default settings exist (AI Configuration)
            $settingsCount = (int)$pdo->query("SELECT COUNT(*) FROM settings")->fetchColumn();
            if ($settingsCount === 0) {
                $settingStmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value, description) VALUES (?, ?, ?)");
                $settingStmt->execute(['ai_provider', 'openai_compatible', 'AI Provider (openai_compatible / gemini / mock)']);
                $settingStmt->execute(['ai_api_key', '', 'Секретный API ключ нейросети']);
                $settingStmt->execute(['ai_api_endpoint', 'https://api.openai.com/v1/chat/completions', 'API Endpoint URL']);
                $settingStmt->execute(['ai_model', 'gpt-4o-mini', 'Название модели']);
                $settingStmt->execute(['ai_system_prompt', 'Ты — профессиональный AI Business Assistant. Отвечай только на основе подтвержденных фактов и предоставленных данных. Не придумывай информацию. Если данных нет или недостаточно, обязательно отвечай: "Недостаточно данных для точного ответа".', 'Системный промпт']);
                $settingStmt->execute(['ai_temperature', '0.2', 'Температура генерации (0.0 - 1.0)']);
                $settingStmt->execute(['company_name', 'AI Business Assistant', 'Название компании']);
            }

            // Auto-create organization requests and support ticket tables if not exist
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS organization_requests (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    request_number VARCHAR(50) NOT NULL UNIQUE,
                    user_id INT NOT NULL,
                    name VARCHAR(255) NOT NULL,
                    business_type VARCHAR(50) NOT NULL DEFAULT 'general',
                    legal_name VARCHAR(255) NULL,
                    director_name VARCHAR(255) NOT NULL,
                    director_email VARCHAR(191) NOT NULL,
                    director_phone VARCHAR(50) NULL,
                    inn VARCHAR(50) NULL,
                    kpp VARCHAR(50) NULL,
                    address TEXT NULL,
                    bank_name VARCHAR(255) NULL,
                    bank_account VARCHAR(50) NULL,
                    description TEXT NULL,
                    status ENUM('pending', 'in_review', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
                    admin_comment TEXT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_user_id (user_id),
                    INDEX idx_status (status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

                CREATE TABLE IF NOT EXISTS support_tickets (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    ticket_number VARCHAR(50) NOT NULL UNIQUE,
                    user_id INT NOT NULL,
                    subject VARCHAR(255) NOT NULL,
                    category VARCHAR(100) NOT NULL DEFAULT 'Общий вопрос',
                    priority ENUM('low', 'medium', 'high', 'urgent') NOT NULL DEFAULT 'medium',
                    status ENUM('pending', 'in_review', 'transferred_admin', 'transferred_management', 'ignored', 'archive', 'closed') NOT NULL DEFAULT 'pending',
                    assigned_to INT NULL,
                    admin_notes TEXT NULL,
                    last_reply_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_user_id (user_id),
                    INDEX idx_status (status),
                    INDEX idx_assigned_to (assigned_to)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

                CREATE TABLE IF NOT EXISTS support_messages (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    ticket_id INT NOT NULL,
                    sender_id INT NOT NULL,
                    sender_type ENUM('user', 'admin') NOT NULL DEFAULT 'user',
                    message TEXT NOT NULL,
                    is_read TINYINT(1) NOT NULL DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_ticket_id (ticket_id),
                    CONSTRAINT fk_supp_ticket FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

                CREATE TABLE IF NOT EXISTS team_chat_channels (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    channel_key VARCHAR(100) UNIQUE NOT NULL,
                    type ENUM('site_management', 'org_management', 'direct') NOT NULL,
                    organization_id INT NULL,
                    name VARCHAR(255) NOT NULL,
                    description VARCHAR(255) NULL,
                    created_by INT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

                CREATE TABLE IF NOT EXISTS team_chat_messages (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    channel_id INT NOT NULL,
                    sender_id INT NOT NULL,
                    message TEXT NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_channel (channel_id),
                    CONSTRAINT fk_tcm_chan FOREIGN KEY (channel_id) REFERENCES team_chat_channels(id) ON DELETE CASCADE,
                    CONSTRAINT fk_tcm_user FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

                CREATE TABLE IF NOT EXISTS bug_reports (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    tester_id INT NOT NULL,
                    title VARCHAR(255) NOT NULL,
                    severity ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
                    status ENUM('new', 'in_progress', 'fixed', 'rejected') DEFAULT 'new',
                    description TEXT NOT NULL,
                    steps_to_reproduce TEXT NULL,
                    developer_comment TEXT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    CONSTRAINT fk_bug_tester FOREIGN KEY (tester_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");

            // Проверяем и дополняем структуру таблицы clients
            try {
                $pdo->exec("ALTER TABLE clients MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'lead'");
                $cCols = $pdo->query("SHOW COLUMNS FROM clients")->fetchAll(PDO::FETCH_COLUMN);
                if (!in_array('next_contact_at', $cCols)) $pdo->exec("ALTER TABLE clients ADD COLUMN next_contact_at DATETIME NULL AFTER status");
                if (!in_array('last_contact_at', $cCols)) $pdo->exec("ALTER TABLE clients ADD COLUMN last_contact_at DATETIME NULL AFTER next_contact_at");
                if (!in_array('last_result', $cCols)) $pdo->exec("ALTER TABLE clients ADD COLUMN last_result VARCHAR(100) NULL AFTER last_contact_at");
                if (!in_array('deal_sum', $cCols)) $pdo->exec("ALTER TABLE clients ADD COLUMN deal_sum DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER last_result");
                if (!in_array('company_name', $cCols)) $pdo->exec("ALTER TABLE clients ADD COLUMN company_name VARCHAR(150) NULL AFTER name");
                if (!in_array('position', $cCols)) $pdo->exec("ALTER TABLE clients ADD COLUMN position VARCHAR(100) NULL AFTER company_name");
            } catch (\Throwable $e) {}

            // Заполняем и актуализируем роли платформы
            $roleDefs = [
                'admin'         => 'Администратор платформы с полным доступом',
                'user'          => 'Стандартный бизнес-пользователь',
                'director'      => 'Генеральный директор / Руководитель бизнеса',
                'manager'       => 'Менеджер с правом проведения возвратов чеков',
                'vrio_owner'    => 'ВРИО Владелец платформы (Полный суверенный доступ)',
                'developer'     => 'Разработчик (Техническая часть, логи, ИИ и сервисы)',
                'tester'        => 'Тестировщик (QA-инженер, песочница и аудит багов)',
                'support_agent' => 'Агент технической поддержки клиентов',
            ];
            foreach ($roleDefs as $rName => $rDesc) {
                $checkR = $pdo->prepare("SELECT id FROM roles WHERE name = ? LIMIT 1");
                $checkR->execute([$rName]);
                if ($rId = $checkR->fetchColumn()) {
                    $pdo->prepare("UPDATE roles SET description = ? WHERE id = ?")->execute([$rDesc, $rId]);
                } else {
                    $pdo->prepare("INSERT INTO roles (name, description) VALUES (?, ?)")->execute([$rName, $rDesc]);
                }
            }

            // Канал руководства сайта по умолчанию
            $checkChan = $pdo->query("SELECT id FROM team_chat_channels WHERE channel_key = 'site_hq' LIMIT 1")->fetchColumn();
            if (!$checkChan) {
                $pdo->prepare("
                    INSERT INTO team_chat_channels (channel_key, type, name, description)
                    VALUES ('site_hq', 'site_management', 'Руководство сайта & Команда', 'Закрытый канал координации руководства сайта, разработчиков, тестировщиков и поддержки')
                ")->execute();
            } else {
                $pdo->prepare("
                    UPDATE team_chat_channels 
                    SET name = 'Руководство сайта & Команда', 
                        description = 'Закрытый канал координации руководства сайта, разработчиков, тестировщиков и поддержки'
                    WHERE channel_key = 'site_hq'
                ")->execute();
            }

            // Таблица сотрудников и доступа к организациям (organization_members)
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS organization_members (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");

            // Проверка колонок в cash_orders (refund_by, refund_at, refund_reason, organization_id, cashier_id)
            try {
                $coCols = $pdo->query("SHOW COLUMNS FROM cash_orders")->fetchAll(PDO::FETCH_COLUMN);
                if (!in_array('refund_by', $coCols, true)) {
                    $pdo->exec("ALTER TABLE cash_orders ADD COLUMN refund_by INT NULL AFTER status");
                }
                if (!in_array('refund_at', $coCols, true)) {
                    $pdo->exec("ALTER TABLE cash_orders ADD COLUMN refund_at DATETIME NULL AFTER refund_by");
                }
                if (!in_array('refund_reason', $coCols, true)) {
                    $pdo->exec("ALTER TABLE cash_orders ADD COLUMN refund_reason VARCHAR(255) NULL AFTER refund_at");
                }
                if (!in_array('organization_id', $coCols, true)) {
                    $pdo->exec("ALTER TABLE cash_orders ADD COLUMN organization_id INT NULL AFTER id");
                }
                if (!in_array('cashier_id', $coCols, true)) {
                    $pdo->exec("ALTER TABLE cash_orders ADD COLUMN cashier_id INT NULL AFTER organization_id");
                }
            } catch (\Throwable $e) {}

            // Проверка колонок в positions (organization_id, title)
            try {
                $posCols = $pdo->query("SHOW COLUMNS FROM positions")->fetchAll(PDO::FETCH_COLUMN);
                if (!in_array('organization_id', $posCols, true)) {
                    $pdo->exec("ALTER TABLE positions ADD COLUMN organization_id INT NULL AFTER id");
                }
                if (!in_array('title', $posCols, true) && in_array('name', $posCols, true)) {
                    $pdo->exec("ALTER TABLE positions ADD COLUMN title VARCHAR(150) NOT NULL DEFAULT '' AFTER organization_id");
                    $pdo->exec("UPDATE positions SET title = name WHERE title = '' OR title IS NULL");
                } elseif (!in_array('title', $posCols, true)) {
                    $pdo->exec("ALTER TABLE positions ADD COLUMN title VARCHAR(150) NOT NULL DEFAULT '' AFTER organization_id");
                }
            } catch (\Throwable $e) {}

            return [
                'success' => true,
                'message' => 'Соединение с базой данных успешно',
                'tables_created' => $createdAny,
                'missing_tables_found' => !empty($missingTables)
            ];

        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => 'Ошибка при проверке таблиц: ' . $e->getMessage(),
                'tables_created' => false
            ];
        }
    }
}
