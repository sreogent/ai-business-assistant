<?php
declare(strict_types=1);

/**
 * AI Business Assistant - Authentication & Authorization Manager
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Security.php';

class Auth {
    /** Cached user data (null = not loaded yet, false = no user) */
    private static $cachedUser = null;

    /**
     * Get currently logged-in user or null
     * Result is cached per-request to avoid repeated DB queries.
     */
    public static function user(): ?array {
        // Return cached result if already loaded this request
        if (self::$cachedUser !== null) {
            return self::$cachedUser === false ? null : self::$cachedUser;
        }

        Security::startSession();
        if (empty($_SESSION['user_id'])) {
            self::$cachedUser = false;
            return null;
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT u.*, r.name AS role_name 
            FROM users u
            JOIN roles r ON u.role_id = r.id
            WHERE u.id = ? AND u.status = 'active'
            LIMIT 1
        ");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();

        if (!$user) {
            // User was blocked or deleted
            self::$cachedUser = false;
            self::logout();
            return null;
        }

        // Throttle updating last_activity to once every 60 seconds
        if (empty($_SESSION['last_activity_update']) || (time() - (int)$_SESSION['last_activity_update']) > 60) {
            try {
                $pdo->exec("UPDATE users SET last_activity = NOW() WHERE id = " . (int)$user['id']);
                $_SESSION['last_activity_update'] = time();
            } catch (\Throwable $e) {
                // Column might not exist yet, attempt to add it
                try {
                    $pdo->exec("ALTER TABLE users ADD COLUMN last_activity DATETIME NULL");
                    $pdo->exec("UPDATE users SET last_activity = NOW() WHERE id = " . (int)$user['id']);
                    $_SESSION['last_activity_update'] = time();
                } catch (\Throwable $e2) {}
            }
        }

        self::$cachedUser = $user;
        return $user;
    }

    /**
     * Clear cached user (call after logout or role change)
     */
    public static function clearCache(): void {
        self::$cachedUser = null;
    }

    public static function check(): bool {
        return self::user() !== null;
    }

    public static function id(): ?int {
        return $_SESSION['user_id'] ?? null;
    }

    public static function role(): ?string {
        $u = self::user();
        return $u['role_name'] ?? null;
    }

    public static function isAdmin(): bool {
        $r = self::role();
        return in_array($r, ['admin', 'vrio_owner'], true);
    }

    public static function isOwner(): bool {
        $r = self::role();
        return in_array($r, ['vrio_owner', 'admin'], true);
    }

    public static function isDeveloper(): bool {
        $r = self::role();
        return in_array($r, ['developer', 'vrio_owner', 'admin'], true);
    }

    public static function isTester(): bool {
        $r = self::role();
        return in_array($r, ['tester', 'vrio_owner', 'admin'], true);
    }

    public static function isSupport(): bool {
        $r = self::role();
        return in_array($r, ['support_agent', 'admin', 'vrio_owner'], true);
    }

    public static function isStaff(): bool {
        $r = self::role();
        return in_array($r, ['vrio_owner', 'admin', 'developer', 'tester', 'support_agent'], true);
    }

    /**
     * Верховный Владелец Платформы (только этот аккаунт обладает абсолютной неприкасаемостью
     * и эксклюзивным правом назначать и снимать роли администратора)
     */
    public const SUPREME_OWNER_EMAIL = 'matvejibadlaev@gmail.com';
    public const SUPREME_OWNER_ID = 3;

    /**
     * Проверяет, является ли текущий (или указанный) пользователь Верховным Владельцем
     */
    public static function isSupremeOwner(?array $user = null): bool {
        if ($user === null) {
            $user = self::user();
        }
        if (!$user) {
            return false;
        }
        $email = mb_strtolower(trim($user['email'] ?? ''));
        $id = (int)($user['id'] ?? 0);
        return ($email === self::SUPREME_OWNER_EMAIL || $id === self::SUPREME_OWNER_ID);
    }

    /**
     * Проверяет, является ли целевой пользователь неприкасаемым Верховным Администратором
     */
    public static function isProtectedAdmin(int|string $userIdOrEmail): bool {
        if (is_numeric($userIdOrEmail)) {
            $uId = (int)$userIdOrEmail;
            if ($uId === self::SUPREME_OWNER_ID) {
                return true;
            }
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
            $stmt->execute([$uId]);
            $email = $stmt->fetchColumn() ?: '';
            return mb_strtolower(trim((string)$email)) === self::SUPREME_OWNER_EMAIL;
        }
        return mb_strtolower(trim((string)$userIdOrEmail)) === self::SUPREME_OWNER_EMAIL;
    }

    /**
     * Имеет ли право пользователь назначать или отзывать права администратора.
     * ТОЛЬКО Верховный Владелец (Матвей Ибадлаев) имеет это право.
     */
    public static function canManageAdminRoles(?array $user = null): bool {
        return self::isSupremeOwner($user);
    }

    /**
     * Имеет ли право пользователь менять тему/стиль сайта и приложения.
     * ТОЛЬКО Верховный Владелец (Матвей Ибадлаев) и Разработчик (роль 'developer').
     */
    public static function canManageSiteTheme(?array $user = null): bool {
        return false; // Смена тем отключена
    }

    /**
     * Получить установленный пароль директора для кассы и смены ролей
     */
    public static function getDirectorPosPassword(): string {
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'director_pos_password' LIMIT 1");
            $stmt->execute();
            return (string)($stmt->fetchColumn() ?: '');
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Проверить, установлен ли пароль директора
     */
    public static function isDirectorPosPasswordSet(): bool {
        return self::getDirectorPosPassword() !== '';
    }

    /**
     * Установить/обновить пароль директора
     */
    public static function setDirectorPosPassword(string $newPassword): bool {
        $pwd = trim($newPassword);
        try {
            $pdo = Database::getConnection();
            $check = $pdo->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = 'director_pos_password'");
            $check->execute();
            if ((int)$check->fetchColumn() > 0) {
                $stmt = $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'director_pos_password'");
                return $stmt->execute([$pwd]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value, description) VALUES ('director_pos_password', ?, 'Пароль директора для кассы, возвратов, удаления чеков и смены ролей')");
                return $stmt->execute([$pwd]);
            }
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Проверить введенный пароль директора
     */
    public static function verifyDirectorPosPassword(string $inputPassword): bool {
        $stored = self::getDirectorPosPassword();
        if ($stored === '') {
            return false;
        }
        $input = trim($inputPassword);
        if ($input === $stored) {
            return true;
        }
        if (password_verify($input, $stored)) {
            return true;
        }
        return false;
    }

    /**
     * Возвращает активную глобальную тему оформления сайта/приложения
     */
    public static function getActiveTheme(): string {
        static $activeTheme = null;
        if ($activeTheme !== null) {
            return $activeTheme;
        }
        $validThemes = ['dark', 'winter', 'new_year', 'spring', 'women_day', 'summer', 'autumn', 'light'];
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'sys_active_theme' LIMIT 1");
            $stmt->execute();
            $val = $stmt->fetchColumn();
            if ($val && in_array($val, $validThemes, true)) {
                $activeTheme = $val;
            } else {
                $activeTheme = 'dark';
            }
        } catch (\Throwable $e) {
            $activeTheme = 'dark';
        }
        return $activeTheme;
    }

    /**
     * Включены ли сезонные анимированные частицы (по умолчанию выключены для строгого корпоративного стиля)
     */
    public static function isThemeParticlesEnabled(): bool {
        static $particles = null;
        if ($particles !== null) {
            return $particles;
        }
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'sys_theme_particles' LIMIT 1");
            $stmt->execute();
            $val = $stmt->fetchColumn();
            $particles = ($val === '1' || $val === 'true');
        } catch (\Throwable $e) {
            $particles = false;
        }
        return $particles;
    }

    /**
     * Каталог всех красивых сезонных и праздничных тем
     */
    public static function getThemeCatalog(): array {
        return [
            'winter' => [
                'id' => 'winter',
                'name' => 'Зима — Ледяное Сияние',
                'category' => 'Время года',
                'icon' => 'fa-snowflake',
                'badge' => '❄️ Зимний сезон',
                'accent_color' => '#38bdf8',
                'secondary_color' => '#818cf8',
                'bg_preview' => 'linear-gradient(135deg, #080f1e 0%, #132238 50%, #0d172a 100%)',
                'particles' => 'snow',
                'particles_name' => 'Падающие снежинки ❄️',
                'desc' => 'Морозная сапфировая ночь, кристальный неон, мерцающий ледяной блеск и лёгкий падающий снег.'
            ],
            'new_year' => [
                'id' => 'new_year',
                'name' => 'Новый Год — Праздничная Сказка',
                'category' => 'Праздник',
                'icon' => 'fa-gifts',
                'badge' => '🎄 Главный праздник',
                'accent_color' => '#ef4444',
                'secondary_color' => '#f59e0b',
                'bg_preview' => 'linear-gradient(135deg, #0a140f 0%, #163024 50%, #1c1012 100%)',
                'particles' => 'confetti',
                'particles_name' => 'Золотые искры и гирлянды ✨',
                'desc' => 'Изумрудная хвоя, рубиновые праздничные огни, мерцающие золотые звёзды и новогоднее настроение.'
            ],
            'spring' => [
                'id' => 'spring',
                'name' => 'Весна — Пробуждение Природы',
                'category' => 'Время года',
                'icon' => 'fa-seedling',
                'badge' => '🌱 Весеннее обновление',
                'accent_color' => '#10b981',
                'secondary_color' => '#facc15',
                'bg_preview' => 'linear-gradient(135deg, #07150e 0%, #132d20 50%, #0d2117 100%)',
                'particles' => 'leaves_green',
                'particles_name' => 'Весенние лепестки и ростки 🍃',
                'desc' => 'Свежая изумрудная зелень, тёплые солнечные лучи, энергия обновления и вдохновения.'
            ],
            'women_day' => [
                'id' => 'women_day',
                'name' => '8 Марта — День Женщин & Сакура',
                'category' => 'Праздник',
                'icon' => 'fa-spa',
                'badge' => '🌸 Международный женский день',
                'accent_color' => '#f43f5e',
                'secondary_color' => '#c084fc',
                'bg_preview' => 'linear-gradient(135deg, #140914 0%, #2a142b 50%, #1f0b1a 100%)',
                'particles' => 'sakura',
                'particles_name' => 'Падающие лепестки сакуры 🌸',
                'desc' => 'Бархатный розан, нежная цветущая сакура, королевский аметист и кружащие розовые лепестки.'
            ],
            'summer' => [
                'id' => 'summer',
                'name' => 'Лето & Отдых — Лазурный Океан',
                'category' => 'Время года',
                'icon' => 'fa-sun',
                'badge' => '☀️ Летний курорт',
                'accent_color' => '#0284c7',
                'secondary_color' => '#f59e0b',
                'bg_preview' => 'linear-gradient(135deg, #041525 0%, #0e304f 50%, #092036 100%)',
                'particles' => 'sun_sparkles',
                'particles_name' => 'Морские блики и лучи ☀️',
                'desc' => 'Глубокий лазурный океан, теплое золотое солнце, бирюзовый риф и курортная лёгкость.'
            ],
            'autumn' => [
                'id' => 'autumn',
                'name' => 'Золотая Осень — Уютный Янтарь',
                'category' => 'Время года',
                'icon' => 'fa-leaf',
                'badge' => '🍂 Золотой листопад',
                'accent_color' => '#ea580c',
                'secondary_color' => '#d97706',
                'bg_preview' => 'linear-gradient(135deg, #150d08 0%, #2e1e12 50%, #1f140c 100%)',
                'particles' => 'autumn_leaves',
                'particles_name' => 'Кружащиеся кленовые листья 🍁',
                'desc' => 'Тёплый янтарный шоколад, багряный клён, медный закат и плавный листопад золотых листьев.'
            ],
            'dark' => [
                'id' => 'dark',
                'name' => 'Классический Dark (Hi-Tech)',
                'category' => 'Классика',
                'icon' => 'fa-moon',
                'badge' => '🚀 Строгий стиль',
                'accent_color' => '#3b82f6',
                'secondary_color' => '#8b5cf6',
                'bg_preview' => 'linear-gradient(135deg, #0a0e17 0%, #1f2937 50%, #111827 100%)',
                'particles' => 'none',
                'particles_name' => 'Без частиц',
                'desc' => 'Фирменный строгий тёмный дизайн AI Business Assistant с неоновыми синими акцентами.'
            ],
            'light' => [
                'id' => 'light',
                'name' => 'Светлый Бизнес (Clean Light)',
                'category' => 'Классика',
                'icon' => 'fa-sun',
                'badge' => '💡 Дневной офис',
                'accent_color' => '#2563eb',
                'secondary_color' => '#0f172a',
                'bg_preview' => 'linear-gradient(135deg, #f8fafc 0%, #ffffff 50%, #e2e8f0 100%)',
                'particles' => 'none',
                'particles_name' => 'Без частиц',
                'desc' => 'Максимально чистый и контрастный светлый стиль для дневной офисной работы.'
            ],
        ];
    }

    /**
     * Установка активной темы (только Матвей и Разработчик)
     */
    public static function setActiveTheme(string $themeId, bool $particles = true, ?array $user = null): array {
        if (!self::canManageSiteTheme($user)) {
            return [
                'success' => false,
                'message' => 'Доступ запрещён! Менять стиль сайта и приложения могут исключительно Матвей Ибадлаев и Разработчик.'
            ];
        }

        $catalog = self::getThemeCatalog();
        if (!isset($catalog[$themeId])) {
            return [
                'success' => false,
                'message' => 'Неизвестный идентификатор темы.'
            ];
        }

        try {
            $pdo = Database::getConnection(true);

            // Сохраняем тему
            $chk = $pdo->prepare("SELECT id FROM settings WHERE setting_key = 'sys_active_theme' LIMIT 1");
            $chk->execute();
            if ($chk->fetch()) {
                $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'sys_active_theme'")->execute([$themeId]);
            } else {
                $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('sys_active_theme', ?)")->execute([$themeId]);
            }

            // Сохраняем частицы
            $pVal = $particles ? '1' : '0';
            $chkP = $pdo->prepare("SELECT id FROM settings WHERE setting_key = 'sys_theme_particles' LIMIT 1");
            $chkP->execute();
            if ($chkP->fetch()) {
                $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'sys_theme_particles'")->execute([$pVal]);
            } else {
                $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('sys_theme_particles', ?)")->execute([$pVal]);
            }

            $u = $user ?? self::user();
            Security::log($pdo, 'theme_updated', "Стиль оформления платформы переключён на '{$catalog[$themeId]['name']}' (Частицы: " . ($particles ? 'вкл' : 'выкл') . ")", (int)($u['id'] ?? 0));

            return [
                'success' => true,
                'theme' => $themeId,
                'theme_name' => $catalog[$themeId]['name'],
                'particles' => $particles,
                'message' => "Стиль платформы успешно изменён на «{$catalog[$themeId]['name']}»!"
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Ошибка базы данных при смене темы: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Проверка, включен ли режим технического обслуживания платформы
     */
    public static function isMaintenanceMode(): bool {
        static $isMaintenance = null;
        if ($isMaintenance !== null) {
            return $isMaintenance;
        }
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'sys_maintenance_mode' LIMIT 1");
            $stmt->execute();
            $val = $stmt->fetchColumn();
            $isMaintenance = ($val === '1');
        } catch (\Throwable $e) {
            $isMaintenance = false;
        }
        return $isMaintenance;
    }

    /**
     * Конфигурация всех системных ролей для ручного управления доступом при ТО
     */
    public static function getMaintenanceRoleConfig(): array {
        return [
            'vrio_owner' => [
                'name' => 'ВРИО Владелец',
                'icon' => 'fa-crown',
                'color' => '#d97706',
                'desc' => 'Высшее верховное руководство платформой',
                'locked' => true,
            ],
            'admin' => [
                'name' => 'Администратор',
                'icon' => 'fa-shield-alt',
                'color' => '#6366f1',
                'desc' => 'Администрация платформы и базы данных',
                'locked' => true,
            ],
            'developer' => [
                'name' => 'Разработчик',
                'icon' => 'fa-code',
                'color' => '#06b6d4',
                'desc' => 'Инженерная команда разработки',
                'locked' => false,
            ],
            'tester' => [
                'name' => 'Тестировщик (QA)',
                'icon' => 'fa-vial',
                'color' => '#10b981',
                'desc' => 'Контроль качества и проверка систем',
                'locked' => false,
            ],
            'support_agent' => [
                'name' => 'Агент поддержки',
                'icon' => 'fa-headset',
                'color' => '#3b82f6',
                'desc' => 'Служба поддержки и тикеты клиентов',
                'locked' => false,
            ],
            'director' => [
                'name' => 'Директор бизнеса',
                'icon' => 'fa-user-tie',
                'color' => '#8b5cf6',
                'desc' => 'Руководители подключенных компаний',
                'locked' => false,
            ],
            'manager' => [
                'name' => 'Менеджер компании',
                'icon' => 'fa-briefcase',
                'color' => '#ec4899',
                'desc' => 'Сотрудники и менеджеры организаций',
                'locked' => false,
            ],
            'user' => [
                'name' => 'Клиент / Пользователь',
                'icon' => 'fa-user',
                'color' => '#94a3b8',
                'desc' => 'Обычные зарегистрированные пользователи',
                'locked' => false,
            ],
        ];
    }

    /**
     * Список ролей, которым открыт ручной доступ во время тех. работ
     */
    public static function getMaintenanceAllowedRoles(): array {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'sys_maintenance_allowed_roles' LIMIT 1");
            $stmt->execute();
            $raw = $stmt->fetchColumn();
            if ($raw) {
                $decoded = json_decode((string)$raw, true);
                if (is_array($decoded)) {
                    $cached = $decoded;
                    return $cached;
                }
            }
        } catch (\Throwable $e) {}
        // По умолчанию базовый состав
        $cached = ['vrio_owner', 'admin', 'developer', 'tester', 'support_agent'];
        return $cached;
    }

    /**
     * Проверка, открыт ли доступ роли пользователя во время тех. работ
     */
    public static function isUserRoleAllowedInMaintenance(?array $user = null): bool {
        $user = $user ?? self::user();
        if (!$user) {
            return false;
        }
        if (self::isSupremeOwner($user)) {
            return true;
        }
        $role = $user['role_name'] ?? 'user';
        if (in_array($role, ['vrio_owner', 'admin'], true)) {
            return true;
        }
        $allowed = self::getMaintenanceAllowedRoles();
        return in_array($role, $allowed, true);
    }

    /**
     * Карта разделов платформы, которые Матвей или ВРИО могут открывать/закрывать вручную во время тех. работ
     */
    public static function getMaintenanceSectionMap(): array {
        return [
            'clients' => [
                'name' => 'Клиенты (CRM)',
                'icon' => 'fa-users-cog',
                'description' => 'Воронка клиентов, звонки, НДЗ, РЗН, отказы и история',
                'patterns' => ['/user/clients.php']
            ],
            'ai' => [
                'name' => 'AI Ассистент',
                'icon' => 'fa-robot',
                'description' => 'Генерация КП, договоров и ответы нейросети',
                'patterns' => ['/user/ai_assistant.php']
            ],
            'pos' => [
                'name' => 'Касса и Меню (POS)',
                'icon' => 'fa-cash-register',
                'description' => 'Онлайн-касса, пробитие чеков и номенклатура меню',
                'patterns' => ['/user/cashier.php', '/user/menu_items.php']
            ],
            'tasks' => [
                'name' => 'Задачи & Поручения',
                'icon' => 'fa-check-square',
                'description' => 'Канбан-доска задач и назначение ответственных',
                'patterns' => ['/user/tasks.php']
            ],
            'documents' => [
                'name' => 'Документы',
                'icon' => 'fa-file-contract',
                'description' => 'Генерация счетов, актов и договоров',
                'patterns' => ['/user/documents.php']
            ],
            'analytics' => [
                'name' => 'Аналитика',
                'icon' => 'fa-chart-line',
                'description' => 'Графики продаж, средний чек и показатели',
                'patterns' => ['/user/analytics.php']
            ],
            'chat' => [
                'name' => 'Корпоративные чаты',
                'icon' => 'fa-comments',
                'description' => 'Общий чат команды и чат руководства организации',
                'patterns' => ['/chat.php', '/user/org_chat.php']
            ],
            'support' => [
                'name' => 'Техподдержка',
                'icon' => 'fa-headset',
                'description' => 'Отправка тикетов и связь с агентами поддержки',
                'patterns' => ['/user/support.php', '/user/support_chat.php']
            ],
            'tariffs' => [
                'name' => 'Тарифы & Ключи',
                'icon' => 'fa-gem',
                'description' => 'Просмотр подписки и ввод лицензионных ключей',
                'patterns' => ['/user/subscription.php', '/user/activate_key.php']
            ],
            'org' => [
                'name' => 'Моя Организация',
                'icon' => 'fa-building',
                'description' => 'Профиль компании, должности и заявки',
                'patterns' => ['/user/organizations.php', '/user/positions.php', '/user/request_organization.php']
            ],
        ];
    }

    /**
     * Получение списка разделов, открытых вручную руководством во время тех. работ
     */
    public static function getMaintenanceAllowedSections(): array {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'sys_maintenance_allowed_sections' LIMIT 1");
            $stmt->execute();
            $raw = $stmt->fetchColumn();
            if ($raw) {
                $decoded = json_decode((string)$raw, true);
                if (is_array($decoded)) {
                    $cached = $decoded;
                    return $cached;
                }
            }
        } catch (\Throwable $e) {}
        $cached = [];
        return $cached;
    }

    /**
     * Проверка, разрешен ли конкретный URI во время тех. работ для обычных пользователей
     */
    public static function isUriAllowedInMaintenance(string $uri): bool {
        $allowedKeys = self::getMaintenanceAllowedSections();
        if (empty($allowedKeys)) {
            return false;
        }
        $map = self::getMaintenanceSectionMap();
        foreach ($allowedKeys as $secKey) {
            if (isset($map[$secKey])) {
                foreach ($map[$secKey]['patterns'] as $pattern) {
                    if (str_starts_with($uri, $pattern) || $uri === $pattern) {
                        return true;
                    }
                }
            }
        }
        // Дашборд открываем, если есть хотя бы 1 доступный модуль, чтобы было меню
        if (($uri === '/user/dashboard.php' || $uri === '/user/' || $uri === '/') && !empty($allowedKeys)) {
            return true;
        }
        return false;
    }

    /**
     * Принудительный контроль доступа в режиме технического обслуживания:
     * - Полный доступ: Матвей Ибадлаев (Владелец/Админ), Разработчик (developer), Тестировщик (tester)
     * - Агент поддержки (support_agent): доступ ИСКЛЮЧИТЕЛЬНО к тикетам и поддержке
     * - Обычные пользователи: доступ только к разделам, явно разрешенным Владельцем/ВРИО вручную
     */
    public static function enforceMaintenanceMode(): void {
        if (!self::isMaintenanceMode()) {
            return;
        }

        $rawUri = $_SERVER['REQUEST_URI'] ?? '';
        $uri = parse_url($rawUri, PHP_URL_PATH) ?: '';
        
        // Разрешенные системные страницы для всех (вход, выход, экран техобслуживания)
        $publicAllowed = [
            '/maintenance.php',
            '/login.php',
            '/logout.php',
            '/verify_2fa.php',
            '/reset_password.php',
        ];

        // Разрешаем статические ресурсы (css, js, картинки, шрифты)
        if (
            str_starts_with($uri, '/assets/') ||
            preg_match('/\.(css|js|png|jpg|jpeg|gif|svg|ico|woff|woff2|ttf)$/i', $uri)
        ) {
            return;
        }

        if (in_array($uri, $publicAllowed, true)) {
            return;
        }

        $user = self::user();

        // 1. Неавторизованный гость -> на экран техобслуживания
        if (!$user) {
            header("Location: /maintenance.php");
            exit;
        }

        $role = $user['role_name'] ?? 'user';

        // 2. Верховный Владелец (Матвей Ибадлаев) или Администратор / ВРИО -> полный доступ
        if (self::isSupremeOwner($user) || in_array($role, ['vrio_owner', 'admin'], true)) {
            return;
        }

        // 3. Разработчик -> полный доступ
        if ($role === 'developer') {
            return;
        }

        // 4. Тестировщик -> полный доступ
        if ($role === 'tester') {
            return;
        }

        // 5. Агент поддержки -> доступ ИСКЛЮЧИТЕЛЬНО к тикетам и поддержке
        if ($role === 'support_agent') {
            $supportAllowedPages = [
                '/support/dashboard.php',
                '/admin/support.php',
                '/admin/support_chat.php',
                '/user/support.php',
                '/user/support_chat.php',
                '/chat.php', // чат поддержки support_team
            ];

            if (in_array($uri, $supportAllowedPages, true) || str_starts_with($uri, '/support/')) {
                return;
            }

            // Если агент поддержки пытается пойти в другой закрытый раздел во время ТО -> перенаправляем в тикеты
            header("Location: /support/dashboard.php?maintenance_restricted=1");
            exit;
        }

        // 6. Все остальные роли (user, manager, director и прочие) — ПОЛНАЯ блокировка.
        //    Никаких исключений по разделам — только страница тех. обслуживания.
        header("Location: /maintenance.php");
        exit;
    }

    /**
     * Получить активные блокировки разделов (с автоматическим снятием просроченных по таймеру)
     */
    public static function getSectionLocks(): array {
        static $locks = null;
        if ($locks !== null) {
            return $locks;
        }
        try {
            $pdo = Database::getConnection();
            if ($pdo) {
                // Автоматически снимаем блокировки, у которых истёк срок
                $pdo->exec("UPDATE section_locks SET is_locked = 0 WHERE is_locked = 1 AND locked_until IS NOT NULL AND locked_until <= NOW()");
                $stmt = $pdo->query("SELECT * FROM section_locks ORDER BY id ASC");
                $locks = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (\Throwable $e) {
            $locks = [];
        }
        return $locks ?: [];
    }

    /**
     * Проверить, заблокирован ли текущий раздел для данного пользователя
     */
    public static function checkSectionLockForCurrentUri(?array $user = null): void {
        $user = $user ?? self::user();
        if (!$user) return;

        $rawUri = $_SERVER['REQUEST_URI'] ?? '';
        $uri = parse_url($rawUri, PHP_URL_PATH) ?: '';

        // Сопоставление путей с разделами
        $patterns = [
            'crm'       => ['/user/clients.php', '/user/client_add.php', '/user/client_view.php', '/user/client_edit.php'],
            'chat'      => ['/chat.php'],
            'direct'    => ['/direct_messages.php'],
            'ai'        => ['/user/ai_assistant.php'],
            'pos'       => ['/user/cashier.php', '/admin/cashier.php', '/user/menu_items.php'],
            'tasks'     => ['/user/tasks.php'],
            'docs'      => ['/user/documents.php'],
            'analytics' => ['/user/analytics.php'],
            'webmail'   => ['/webmail.php'],
        ];

        $matchedKey = null;
        foreach ($patterns as $secKey => $pats) {
            foreach ($pats as $p) {
                if (str_starts_with($uri, $p) || $uri === $p) {
                    $matchedKey = $secKey;
                    break 2;
                }
            }
        }

        if (!$matchedKey) return;

        $locks = self::getSectionLocks();
        $activeLock = null;
        foreach ($locks as $lock) {
            if ($lock['section_key'] === $matchedKey && (int)$lock['is_locked'] === 1) {
                $activeLock = $lock;
                break;
            }
        }

        if (!$activeLock) return;

        $roleName = $user['role_name'] ?? '';
        $isPrivileged = in_array($roleName, ['vrio_owner', 'admin'], true);

        // Проверяем, нажал ли Владелец/Админ кнопку "Обойти блокировку"
        if (isset($_GET['bypass_lock'])) {
            if ($isPrivileged) {
                $_SESSION['bypass_lock_' . $matchedKey] = true;
                return;
            }
        }
        if (!empty($_SESSION['bypass_lock_' . $matchedKey]) && $isPrivileged) {
            return;
        }

        self::renderSectionLockedPage($activeLock, $user, $isPrivileged);
        exit;
    }

    /**
     * Рендеринг страницы заблокированного раздела с таймером обратного отсчета
     */
    public static function renderSectionLockedPage(array $lock, ?array $user = null, bool $isPrivileged = false): void {
        http_response_code(403);
        $secName = htmlspecialchars($lock['section_name'] ?? 'Раздел');
        $reason = htmlspecialchars($lock['lock_reason'] ?: 'Плановые технические работы или временное ограничение доступа');
        $lockedUntil = $lock['locked_until'];
        $untilJs = $lockedUntil ? (new DateTime($lockedUntil))->format('c') : null;
        ?>
        <!DOCTYPE html>
        <html lang="ru">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Раздел временно заблокирован | AI Business Assistant</title>
            <link rel="stylesheet" href="/assets/css/style.css">
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
            <style>
                body {
                    background: #0b0f19;
                    color: #f1f5f9;
                    font-family: system-ui, -apple-system, sans-serif;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    min-height: 100vh;
                    margin: 0;
                    padding: 20px;
                }
                .lock-card {
                    background: linear-gradient(145deg, #0f172a, #1e293b);
                    border: 1px solid rgba(239, 68, 68, 0.4);
                    border-radius: 24px;
                    padding: 40px;
                    max-width: 520px;
                    width: 100%;
                    text-align: center;
                    box-shadow: 0 25px 60px rgba(0,0,0,0.8), 0 0 35px rgba(239, 68, 68, 0.2);
                }
                .lock-icon-wrap {
                    width: 80px; height: 80px; border-radius: 50%;
                    background: rgba(239, 68, 68, 0.15); border: 2px solid rgba(239, 68, 68, 0.5);
                    display: flex; align-items: center; justify-content: center;
                    font-size: 36px; color: #ef4444; margin: 0 auto 24px;
                    box-shadow: 0 0 25px rgba(239, 68, 68, 0.4);
                }
                .countdown-box {
                    background: rgba(0,0,0,0.3); border: 1px solid rgba(255,255,255,0.08);
                    border-radius: 14px; padding: 16px; margin: 20px 0;
                    display: flex; justify-content: center; gap: 20px;
                }
                .cd-item { display: flex; flex-direction: column; align-items: center; }
                .cd-val { font-size: 28px; font-weight: 800; color: #38bdf8; font-family: monospace; }
                .cd-lbl { font-size: 11px; color: #94a3b8; text-transform: uppercase; margin-top: 2px; }
            </style>
        </head>
        <body>
            <div class="lock-card">
                <div class="lock-icon-wrap">
                    <i class="fas fa-lock"></i>
                </div>
                <h2 style="margin:0 0 8px; font-size:24px; font-weight:800;">
                    Раздел временно недоступен
                </h2>
                <div style="font-size:16px; color:#93c5fd; font-weight:700; margin-bottom:14px;">
                    «<?= $secName ?>»
                </div>
                <p style="font-size:14px; color:#cbd5e1; line-height:1.5; margin:0 0 16px;">
                    Доступ к данному модулю временно ограничен администрацией платформы.<br>
                    <strong>Причина:</strong> <?= $reason ?>
                </p>

                <?php if ($untilJs): ?>
                    <div style="font-size:12px; color:#94a3b8; text-transform:uppercase; letter-spacing:1px; font-weight:700;">
                        До окончания блокировки:
                    </div>
                    <div class="countdown-box">
                        <div class="cd-item"><span id="cdHours" class="cd-val">00</span><span class="cd-lbl">Часов</span></div>
                        <div class="cd-item"><span id="cdMins" class="cd-val">00</span><span class="cd-lbl">Минут</span></div>
                        <div class="cd-item"><span id="cdSecs" class="cd-val">00</span><span class="cd-lbl">Секунд</span></div>
                    </div>
                    <script>
                        const targetTime = new Date(<?= json_encode($untilJs) ?>).getTime();
                        function updateTimer() {
                            const now = new Date().getTime();
                            const diff = Math.max(0, targetTime - now);
                            if (diff <= 0) {
                                document.getElementById('cdHours').textContent = '00';
                                document.getElementById('cdMins').textContent = '00';
                                document.getElementById('cdSecs').textContent = '00';
                                setTimeout(() => window.location.reload(), 1500);
                                return;
                            }
                            const h = Math.floor(diff / (1000 * 60 * 60));
                            const m = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
                            const s = Math.floor((diff % (1000 * 60)) / 1000);
                            document.getElementById('cdHours').textContent = String(h).padStart(2, '0');
                            document.getElementById('cdMins').textContent = String(m).padStart(2, '0');
                            document.getElementById('cdSecs').textContent = String(s).padStart(2, '0');
                        }
                        updateTimer();
                        setInterval(updateTimer, 1000);
                    </script>
                <?php else: ?>
                    <div style="font-size:12px; color:#f59e0b; background:rgba(245,158,11,0.1); border:1px solid rgba(245,158,11,0.3); border-radius:10px; padding:10px; margin-bottom:20px;">
                        <i class="fas fa-clock"></i> Блокировка установлена до ручного снятия администратором.
                    </div>
                <?php endif; ?>

                <?php if ($isPrivileged): ?>
                    <div style="margin-top:20px; padding:14px; background:rgba(217,119,6,0.15); border:1px solid rgba(217,119,6,0.4); border-radius:12px; text-align:center;">
                        <div style="font-size:12px; font-weight:800; color:#f59e0b; margin-bottom:4px;">
                            👑 ПАНЕЛЬ ВЛАДЕЛЬЦА / АДМИНИСТРАТОРА
                        </div>
                        <div style="font-size:11px; color:#cbd5e1; margin-bottom:12px; line-height:1.4;">
                            Раздел сейчас заблокирован по таймеру для всех пользователей платформы.<br>
                            Вы можете проверить его отображение или войти в режиме суперадминистратора:
                        </div>
                        <div style="display:flex; justify-content:center; gap:8px; flex-wrap:wrap;">
                            <a href="?bypass_lock=1" class="btn btn-warning btn-sm" style="font-weight:700; font-size:11px; padding:6px 14px; border-radius:8px; text-decoration:none; color:#000;">
                                <i class="fas fa-unlock"></i> Войти (Обойти блокировку)
                            </a>
                            <form method="POST" action="/owner/dashboard.php" style="margin:0;">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Security::csrfToken()) ?>">
                                <input type="hidden" name="action" value="toggle_section_lock">
                                <input type="hidden" name="section_key" value="<?= htmlspecialchars($lock['section_key']) ?>">
                                <input type="hidden" name="is_locked" value="0">
                                <button type="submit" class="btn btn-success btn-sm" style="font-weight:700; font-size:11px; padding:6px 14px; border-radius:8px; background:#10b981; border:none; color:#fff; cursor:pointer;">
                                    <i class="fas fa-lock-open"></i> Снять блокировку
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>

                <div style="display:flex; justify-content:center; gap:12px; margin-top:20px;">
                    <a href="/user/dashboard.php" class="btn btn-primary" style="padding:12px 28px; border-radius:12px; font-weight:700; text-decoration:none;">
                        <i class="fas fa-arrow-left"></i> В Главное меню
                    </a>
                </div>
            </div>
        </body>
        </html>
        <?php
    }

    /**
     * Require authenticated user, redirect if not logged in
     */
    public static function requireAuth(string $redirect = '/login.php'): array {
        self::enforceMaintenanceMode();
        $user = self::user();
        if (!$user) {
            header("Location: " . $redirect);
            exit;
        }
        self::checkSubscriptionExpiryForCurrentUri($user);
        self::checkSectionLockForCurrentUri($user);
        return $user;
    }

    /**
     * Проверка срока действия подписки: если закончилась — блокировать все разделы кроме страницы подписки
     */
    public static function checkSubscriptionExpiryForCurrentUri(?array $user = null): void {
        $user = $user ?? self::user();
        if (!$user) return;

        $roleName = $user['role_name'] ?? 'user';
        // Администраторы, владельцы платформы, разработчики, тестировщики и поддержка освобождены
        if (in_array($roleName, ['vrio_owner', 'admin', 'developer', 'tester', 'support_agent'], true)) {
            return;
        }

        $rawUri = $_SERVER['REQUEST_URI'] ?? '';
        $uri = parse_url($rawUri, PHP_URL_PATH) ?: '';

        // Разрешенные страницы даже при истекшей подписке: страница подписок, активация ключа, выход, логин
        $allowedPages = [
            '/user/subscription.php',
            '/user/activate_key.php',
            '/logout.php',
            '/login.php'
        ];
        foreach ($allowedPages as $allowed) {
            if ($uri === $allowed || str_starts_with($uri, $allowed)) {
                return;
            }
        }

        require_once __DIR__ . '/Subscription.php';
        $sub = Subscription::getUserSubscription((int)$user['id']);

        $isExpired = false;
        if (!$sub) {
            $isExpired = true;
        } elseif ($sub['status'] !== 'active' || $sub['end_date'] < date('Y-m-d H:i:s')) {
            $isExpired = true;
        }

        if ($isExpired) {
            self::renderSubscriptionExpiredPage($sub, $user);
            exit;
        }
    }

    /**
     * Экран полной блокировки разделов при истекшей подписке
     */
    public static function renderSubscriptionExpiredPage(?array $sub, array $user): void {
        http_response_code(403);
        $tariffName = $sub['tariff_name'] ?? 'Базовый';
        $endDate = !empty($sub['end_date']) ? date('d.m.Y H:i', strtotime($sub['end_date'])) : null;
        ?>
        <!DOCTYPE html>
        <html lang="ru">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Подписка истекла | AI Business Assistant</title>
            <link rel="stylesheet" href="/assets/css/style.css">
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
            <style>
                body {
                    background: #0b0f19;
                    color: #f1f5f9;
                    font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    min-height: 100vh;
                    margin: 0;
                    padding: 20px;
                }
                .sub-expired-card {
                    background: linear-gradient(145deg, #0f172a, #1e293b);
                    border: 1px solid rgba(239, 68, 68, 0.4);
                    border-radius: 24px;
                    padding: 40px;
                    max-width: 520px;
                    width: 100%;
                    text-align: center;
                    box-shadow: 0 25px 60px rgba(0,0,0,0.8), 0 0 35px rgba(239, 68, 68, 0.2);
                }
                .sub-icon-wrap {
                    width: 80px; height: 80px; border-radius: 50%;
                    background: rgba(239, 68, 68, 0.15); border: 2px solid rgba(239, 68, 68, 0.5);
                    display: flex; align-items: center; justify-content: center;
                    font-size: 36px; color: #ef4444; margin: 0 auto 20px;
                    box-shadow: 0 0 30px rgba(239, 68, 68, 0.3);
                }
            </style>
        </head>
        <body>
            <div class="sub-expired-card">
                <div class="sub-icon-wrap">
                    <i class="fas fa-lock"></i>
                </div>
                <div style="font-size:12px; text-transform:uppercase; letter-spacing:1.5px; color:#f87171; font-weight:700; margin-bottom:8px;">
                    Доступ заблокирован
                </div>
                <h2 style="font-size:24px; font-weight:800; color:#fff; margin:0 0 12px;">
                    Срок вашей подписки истёк
                </h2>
                <p style="font-size:14px; color:#94a3b8; line-height:1.6; margin:0 0 24px;">
                    Все разделы платформы (CRM, Чаты, Задачи, Документы, AI-ассистент, Аналитика) временно закрыты.
                    <?= $endDate ? "<br><span style='color:#f87171; font-weight:600;'>Подписка закончилась: {$endDate}</span>" : '' ?>
                </p>

                <div style="background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.08); border-radius:14px; padding:16px; margin-bottom:24px; text-align:left;">
                    <div style="font-size:13px; font-weight:700; color:#e2e8f0; margin-bottom:6px;">
                        <i class="fas fa-key" style="color:#6366f1; margin-right:6px;"></i> Как восстановить доступ?
                    </div>
                    <div style="font-size:12px; color:#94a3b8; line-height:1.5;">
                        1. Активируйте ключ подписки от администратора.<br>
                        2. Или запросите продление тарифа у вашего руководителя.
                    </div>
                </div>

                <div style="display:flex; justify-content:center; gap:12px; flex-wrap:wrap;">
                    <a href="/user/activate_key.php" class="btn btn-primary" style="padding:12px 24px; border-radius:12px; font-weight:700; text-decoration:none; display:inline-flex; align-items:center; gap:8px;">
                        <i class="fas fa-key"></i> Активировать ключ
                    </a>
                    <a href="/user/subscription.php" class="btn btn-secondary" style="padding:12px 24px; border-radius:12px; font-weight:600; text-decoration:none; display:inline-flex; align-items:center; gap:8px;">
                        <i class="fas fa-tags"></i> Продлить подписку
                    </a>
                    <a href="/logout.php" style="padding:12px 18px; border-radius:12px; color:#94a3b8; text-decoration:none; font-size:13px; display:inline-flex; align-items:center; gap:6px;">
                        <i class="fas fa-sign-out-alt"></i> Выйти
                    </a>
                </div>
            </div>
        </body>
        </html>
        <?php
    }

    /**
     * Require admin or vrio_owner role
     */
    public static function requireAdmin(): array {
        $user = self::requireAuth('/login.php');
        if (!in_array($user['role_name'], ['admin', 'vrio_owner'], true)) {
            http_response_code(403);
            require dirname(__DIR__) . '/includes/403.php';
            exit;
        }
        return $user;
    }

    /**
     * Require one of the specified roles
     */
    public static function requireRole($allowedRoles): array {
        $user = self::requireAuth('/login.php');
        $roles = is_array($allowedRoles) ? $allowedRoles : [$allowedRoles];
        
        // Верховный владелец, разработчик, ВРИО и Админ имеют полный доступ ко всем разделам
        if (self::isSupremeOwner($user) || in_array($user['role_name'], ['supreme_owner', 'developer', 'admin', 'vrio_owner'], true)) {
            return $user;
        }

        if (!in_array($user['role_name'], $roles, true) && !in_array($user['role_name'], ['admin', 'vrio_owner'], true)) {
            http_response_code(403);
            require dirname(__DIR__) . '/includes/403.php';
            exit;
        }
        return $user;
    }

    /**
     * Get target dashboard URL based on user role
     */
    public static function getRedirectForRole(?string $role): string {
        switch ($role) {
            case 'vrio_owner':
                return '/owner/dashboard.php';
            case 'admin':
                return '/admin/dashboard.php';
            case 'developer':
                return '/developer/dashboard.php';
            case 'tester':
                return '/tester/dashboard.php';
            case 'support_agent':
                return '/support/dashboard.php';
            default:
                return '/user/dashboard.php';
        }
    }

    /**
     * Login user with rate limiting check and password verification
     */
    public static function attempt(string $login, string $password): array {
        $pdo = Database::getConnection();
        $ip = Security::getClientIp();

        if (Security::isRateLimited($pdo, $ip)) {
            Security::log($pdo, 'login_rate_limited', "IP locked: {$login}");
            return [
                'success' => false,
                'message' => 'Слишком много неудачных попыток входа. Пожалуйста, подождите 15 минут.'
            ];
        }

        $login = trim(mb_strtolower($login));

        // Auto-migrate: add 2FA trust columns if they don't exist
        try {
            $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'two_fa_trusted_hash'")->fetch();
            if (!$col) {
                $pdo->exec("ALTER TABLE users
                    ADD COLUMN two_fa_trusted_hash VARCHAR(64) NULL,
                    ADD COLUMN two_fa_trusted_expires DATETIME NULL");
            }
            $col2fa = $pdo->query("SHOW COLUMNS FROM users LIKE 'two_fa_enabled'")->fetch();
            if (!$col2fa) {
                $pdo->exec("ALTER TABLE users ADD COLUMN two_fa_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
            }
        } catch (\Throwable $e) {}

        // Support login by email OR by username (part before @ in email)
        if (str_contains($login, '@')) {
            // Full email provided
            $stmt = $pdo->prepare("
                SELECT u.*, r.name AS role_name
                FROM users u
                JOIN roles r ON u.role_id = r.id
                WHERE LOWER(u.email) = ?
                LIMIT 1
            ");
            $stmt->execute([$login]);
        } else {
            // Username login: match the prefix before @ in email
            $stmt = $pdo->prepare("
                SELECT u.*, r.name AS role_name
                FROM users u
                JOIN roles r ON u.role_id = r.id
                WHERE LOWER(SUBSTRING_INDEX(u.email, '@', 1)) = ?
                LIMIT 1
            ");
            $stmt->execute([$login]);
        }
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            Security::recordFailedAttempt($pdo, $ip);
            Security::log($pdo, 'login_failed', "Invalid credentials for: {$login}");
            return [
                'success' => false,
                'message' => 'Неверный email/логин или пароль.'
            ];
        }

        if ($user['status'] === 'blocked') {
            Security::log($pdo, 'login_blocked_user', "Attempt by blocked user: {$login}", (int)$user['id']);
            return [
                'success' => false,
                'message' => 'Ваш аккаунт заблокирован администратором.'
            ];
        }

        // Success: clear rate limiting and generate secure session
        Security::clearAttempts($pdo, $ip);
        Security::startSession();

        // 4-значный код безопасности для привилегированных ролей или при включенном 2FA в профиле
        $privilegedRoles = ['admin', 'vrio_owner', 'developer', 'tester', 'support_agent', 'director', 'manager'];
        $is2faRequired = in_array($user['role_name'], $privilegedRoles, true) || !empty($user['two_fa_enabled']);
        if ($is2faRequired) {

            // ── 2FA Trust: пропускаем 2FA если устройство проверено в последние 2 часа ──
            $trustCookie = $_COOKIE['2fa_trust'] ?? '';
            if (self::check2FATrust($pdo, (int)$user['id'], $trustCookie)) {
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_regenerate_id(true);
                }
                $_SESSION['user_id']    = (int)$user['id'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_role']  = $user['role_name'];
                $_SESSION['user_name']  = $user['first_name'] . ' ' . $user['last_name'];
                $_SESSION['2fa_trusted_until'] = time() + 7200;
                $_SESSION['2fa_trusted_user_id'] = (int)$user['id'];

                Security::log($pdo, 'login_success_2fa_trusted', "Вход без повторного 2FA (активен кулдаун 2 часа)", (int)$user['id']);
                return ['success' => true, 'user' => $user];
            }

            $code = str_pad((string)random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
            $_SESSION['2fa_pending_user_id'] = (int)$user['id'];
            $_SESSION['2fa_code']            = $code;
            $_SESSION['2fa_expires']         = time() + 600; // 10 минут
            $_SESSION['2fa_role']            = $user['role_name'];
            $_SESSION['2fa_email']           = $user['email'];
            $_SESSION['2fa_name']            = $user['first_name'] . ' ' . $user['last_name'];

            $roleTitles = [
                'vrio_owner'    => 'ВРИО Владелец платформы',
                'admin'         => 'Администратор платформы',
                'developer'     => 'Разработчик платформы',
                'tester'        => 'Тестировщик (QA)',
                'support_agent' => 'Агент технической поддержки',
                'director'      => 'Генеральный Директор',
                'manager'       => 'Менеджер'
            ];
            $roleTitle = $roleTitles[$user['role_name']] ?? $user['role_name'];

            require_once __DIR__ . '/Mailer.php';
            Mailer::send2FACode($user['email'], $user['first_name'], $code, $roleTitle);
            Security::log($pdo, 'login_2fa_code_generated', "2FA код отправлен для {$user['email']} (роль: {$user['role_name']})", (int)$user['id']);

            return [
                'success'      => true,
                'requires_2fa' => true,
                'user'         => $user,
                'code'         => $code
            ];
        }

        // Обычный пользователь входит сразу
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $_SESSION['user_id']    = (int)$user['id'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_role']  = $user['role_name'];
        $_SESSION['user_name']  = $user['first_name'] . ' ' . $user['last_name'];

        Security::log($pdo, 'login_success', "Успешный вход в систему", (int)$user['id']);

        return ['success' => true, 'user' => $user];
    }

    /**
     * Проверяет кулдаун 2FA (< 2 часов) по сессии или доверенному cookie + БД
     */
    public static function check2FATrust(\PDO $pdo, int $userId, string $rawToken): bool {
        try {
            // 1. Проверка по сессии (если кулдаун ещё активен)
            if (!empty($_SESSION['2fa_trusted_until']) && (int)$_SESSION['2fa_trusted_until'] > time() && !empty($_SESSION['2fa_trusted_user_id']) && (int)$_SESSION['2fa_trusted_user_id'] === $userId) {
                return true;
            }

            // 2. Проверка по защищённому токену в cookie и БД (DATE_ADD(NOW(), INTERVAL 2 HOUR))
            if (!empty($rawToken)) {
                $hash = hash('sha256', $rawToken);
                $stmt = $pdo->prepare("
                    SELECT id FROM users
                    WHERE id = ?
                      AND two_fa_trusted_hash = ?
                      AND two_fa_trusted_expires > NOW()
                    LIMIT 1
                ");
                $stmt->execute([$userId, $hash]);
                if ($stmt->fetch()) {
                    $_SESSION['2fa_trusted_until'] = time() + 7200;
                    $_SESSION['2fa_trusted_user_id'] = $userId;
                    return true;
                }
            }
            return false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Устанавливает кулдаун 2FA на 2 часа после успешного ввода кода
     */
    public static function set2FATrustCookie(\PDO $pdo, int $userId): void {
        try {
            $token = bin2hex(random_bytes(32));
            $hash  = hash('sha256', $token);

            // Используем DATE_ADD(NOW(), INTERVAL 2 HOUR) для абсолютной точности вне зависимости от таймзон
            $pdo->prepare("
                UPDATE users
                SET two_fa_trusted_hash = ?, two_fa_trusted_expires = DATE_ADD(NOW(), INTERVAL 2 HOUR)
                WHERE id = ?
            ")->execute([$hash, $userId]);

            // Запоминаем в сессии браузера
            $_SESSION['2fa_trusted_until'] = time() + 7200;
            $_SESSION['2fa_trusted_user_id'] = $userId;

            // Устанавливаем cookie на 2 часа с samesite=Lax
            setcookie('2fa_trust', $token, [
                'expires'  => time() + 7200,
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            $_COOKIE['2fa_trust'] = $token;
        } catch (\Throwable $e) {
            $_SESSION['2fa_trusted_until'] = time() + 7200;
            $_SESSION['2fa_trusted_user_id'] = $userId;
        }
    }

    /**
     * Подтверждение 4-значного кода безопасности (2FA)
     */
    public static function verify2FA(string $inputCode): array {
        Security::startSession();
        $pdo = Database::getConnection();

        $pendingId = $_SESSION['2fa_pending_user_id'] ?? null;
        $savedCode = $_SESSION['2fa_code'] ?? null;
        $expires   = $_SESSION['2fa_expires'] ?? 0;

        if (!$pendingId || !$savedCode) {
            return ['success' => false, 'message' => 'Сессия подтверждения не найдена. Пожалуйста, выполните вход заново.'];
        }

        if (time() > $expires) {
            unset($_SESSION['2fa_pending_user_id'], $_SESSION['2fa_code'], $_SESSION['2fa_expires']);
            return ['success' => false, 'message' => 'Срок действия 4-значного кода истёк (10 минут). Войдите заново для получения нового кода.'];
        }

        $inputCode = preg_replace('/\D/', '', trim($inputCode));
        if ($inputCode !== $savedCode) {
            Security::log($pdo, 'login_2fa_failed', "Неверный 4-значный код для user_id #{$pendingId}");
            return ['success' => false, 'message' => 'Введён неверный 4-значный код безопасности. Проверьте почту или повторите попытку.'];
        }

        // Код верный! Загружаем пользователя и активируем постоянную сессию
        $stmt = $pdo->prepare("
            SELECT u.*, r.name AS role_name 
            FROM users u
            JOIN roles r ON u.role_id = r.id
            WHERE u.id = ? AND u.status = 'active'
            LIMIT 1
        ");
        $stmt->execute([$pendingId]);
        $user = $stmt->fetch();

        if (!$user) {
            return ['success' => false, 'message' => 'Пользователь не найден или заблокирован.'];
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $_SESSION['user_id']    = (int)$user['id'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_role']  = $user['role_name'];
        $_SESSION['user_name']  = $user['first_name'] . ' ' . $user['last_name'];

        // Очищаем временные 2FA данные
        unset($_SESSION['2fa_pending_user_id'], $_SESSION['2fa_code'], $_SESSION['2fa_expires'], $_SESSION['2fa_role'], $_SESSION['2fa_email'], $_SESSION['2fa_name']);

        // Устанавливаем cookie доверия для этого устройства на 2 часа
        self::set2FATrustCookie($pdo, (int)$user['id']);

        Security::log($pdo, 'login_success_2fa', "Успешный вход с подтверждением 4-значного кода", (int)$user['id']);

        return ['success' => true, 'user' => $user];
    }

    /**
     * Register a new user ensuring unique email and phone
     */
    public static function register(string $firstName, string $lastName, string $email, string $phone, string $password, bool $activateTrial = false): array {
        $pdo = Database::getConnection();

        $firstName = trim($firstName);
        $lastName = trim($lastName);
        $email = trim(mb_strtolower($email));
        $phone = preg_replace('/[^\d+]/', '', trim($phone));

        if (empty($firstName) || empty($lastName) || empty($email) || empty($phone) || empty($password)) {
            return ['success' => false, 'message' => 'Все поля обязательны для заполнения.'];
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Указан некорректный адрес электронной почты.'];
        }

        if (mb_strlen($password) < 8) {
            return ['success' => false, 'message' => 'Пароль должен содержать минимум 8 символов.'];
        }

        // Check uniqueness of email
        $stmt = $pdo->prepare("SELECT id FROM users WHERE LOWER(email) = ? LIMIT 1");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            return ['success' => false, 'message' => 'Пользователь с таким email уже зарегистрирован.'];
        }

        // Check uniqueness of phone
        $stmt = $pdo->prepare("SELECT id FROM users WHERE phone = ? LIMIT 1");
        $stmt->execute([$phone]);
        if ($stmt->fetch()) {
            return ['success' => false, 'message' => 'Пользователь с таким номером телефона уже зарегистрирован.'];
        }

        // Get default user role ID
        $roleStmt = $pdo->prepare("SELECT id FROM roles WHERE name = 'user' LIMIT 1");
        $roleStmt->execute();
        $roleId = $roleStmt->fetchColumn();

        if (!$roleId) {
            return ['success' => false, 'message' => 'Ошибка конфигурации системы: роль пользователя не найдена.'];
        }

        $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        $insert = $pdo->prepare("
            INSERT INTO users (role_id, first_name, last_name, email, phone, password_hash, status)
            VALUES (?, ?, ?, ?, ?, ?, 'active')
        ");
        $insert->execute([$roleId, $firstName, $lastName, $email, $phone, $passwordHash]);
        $newUserId = (int)$pdo->lastInsertId();

        // Активация тестового периода только при явном запросе
        if ($activateTrial) {
            require_once __DIR__ . '/Subscription.php';
            Subscription::activateTrial($newUserId, 7);
        }

        Security::log($pdo, 'user_registered', "Новый пользователь зарегистрирован: {$email}", $newUserId);

        return [
            'success' => true,
            'user_id' => $newUserId,
            'message' => 'Регистрация успешно завершена! Теперь вы можете войти.'
        ];
    }

    public static function logout(): void {
        self::$cachedUser = null;
        Security::startSession();
        $pdo = Database::getConnection(true);
        if ($pdo && !empty($_SESSION['user_id'])) {
            Security::log($pdo, 'logout', "Выход из системы", (int)$_SESSION['user_id']);
        }
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();
    }
}
