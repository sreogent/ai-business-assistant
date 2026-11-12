-- ============================================================
-- Миграция: Добавление всех недостающих таблиц
-- AI Business Assistant — применить один раз
-- ============================================================

-- 1. Прямые сообщения (Direct Messages)
CREATE TABLE IF NOT EXISTS direct_messages (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Голосовые сообщения в DM
CREATE TABLE IF NOT EXISTS dm_voice_messages (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    dm_id       INT NOT NULL,
    file_path   VARCHAR(255) NOT NULL,
    duration_s  INT NOT NULL DEFAULT 0,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_dvm_dm FOREIGN KEY (dm_id) REFERENCES direct_messages (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Звонки между сотрудниками (DM Calls)
CREATE TABLE IF NOT EXISTS dm_calls (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    caller_id     INT NOT NULL,
    receiver_id   INT NOT NULL,
    status        ENUM('calling','active','ended','missed','rejected') NOT NULL DEFAULT 'calling',
    started_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ended_at      TIMESTAMP NULL,
    duration_s    INT NOT NULL DEFAULT 0,
    CONSTRAINT fk_dmc_caller   FOREIGN KEY (caller_id)   REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_dmc_receiver FOREIGN KEY (receiver_id) REFERENCES users (id) ON DELETE CASCADE,
    INDEX idx_call_receiver (receiver_id, status),
    INDEX idx_call_caller   (caller_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Ручная блокировка разделов (Section Locks)
CREATE TABLE IF NOT EXISTS section_locks (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Базовые разделы для блокировки
INSERT IGNORE INTO section_locks (section_key, section_name, is_locked) VALUES
('crm',      'CRM / Клиенты',         0),
('tasks',    'Задачи',                0),
('docs',     'Документы',             0),
('ai',       'AI-Ассистент',          0),
('analytics','Аналитика',             0),
('cash',     'Касса / Финансы',       0),
('hr',       'HR / Сотрудники',       0),
('settings', 'Настройки',             0);

-- 5. Организации (Organizations)
CREATE TABLE IF NOT EXISTS organizations (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    user_id          INT NOT NULL,
    business_type    VARCHAR(50) NOT NULL DEFAULT 'other',
    name             VARCHAR(150) NOT NULL,
    legal_name       VARCHAR(200) NULL,
    director_name    VARCHAR(150) NULL,
    director_email   VARCHAR(191) NULL,
    director_phone   VARCHAR(50) NULL,
    inn              VARCHAR(20) NULL,
    is_active        TINYINT(1) NOT NULL DEFAULT 1,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_org_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Позиции/должности (Positions)
CREATE TABLE IF NOT EXISTS positions (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NOT NULL,
    title           VARCHAR(100) NOT NULL,
    department      VARCHAR(100) NULL,
    salary          DECIMAL(12,2) NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pos_org FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Баг-репорты (Bug Reports)
CREATE TABLE IF NOT EXISTS bug_reports (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    reporter_id  INT NOT NULL,
    title        VARCHAR(255) NOT NULL,
    description  TEXT NOT NULL,
    severity     ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
    status       ENUM('open','in_progress','resolved','closed') NOT NULL DEFAULT 'open',
    assigned_to  INT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_bug_reporter  FOREIGN KEY (reporter_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_bug_assignee  FOREIGN KEY (assigned_to) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Заказы кассы (Cash Orders / POS)
CREATE TABLE IF NOT EXISTS cash_orders (
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
    CONSTRAINT fk_co_user   FOREIGN KEY (user_id)   REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_co_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Строки заказа кассы
CREATE TABLE IF NOT EXISTS cash_order_items (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    order_id     INT NOT NULL,
    name         VARCHAR(255) NOT NULL,
    qty          DECIMAL(10,3) NOT NULL DEFAULT 1,
    price        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    CONSTRAINT fk_coi_order FOREIGN KEY (order_id) REFERENCES cash_orders (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Уведомления (Notifications)
CREATE TABLE IF NOT EXISTS notifications (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. CRM история (crm_history если не было)
CREATE TABLE IF NOT EXISTS crm_history (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    client_id  INT NOT NULL,
    user_id    INT NOT NULL,
    action     VARCHAR(100) NOT NULL,
    note       TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_crm_h_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE,
    CONSTRAINT fk_crm_h_user   FOREIGN KEY (user_id)   REFERENCES users   (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Конец миграции
-- ============================================================
