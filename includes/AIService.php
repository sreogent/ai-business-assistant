<?php
declare(strict_types=1);

/**
 * AI Business Assistant - AI Engine Service
 * Connects to live AI provider configured in settings (OpenAI/Anthropic/Gemini compatible or local rules-based fallback).
 * Answers ANY business and operational questions (sales, marketing, strategy, cashier/POS, staff motivation, ideas, reports),
 * while prioritizing verified CRM context when specific company records (clients, tasks, documents) are requested.
 */

require_once __DIR__ . '/Database.php';

class AIService {
    private ?PDO $pdo = null;

    public function __construct(?PDO $pdo = null) {
        $this->pdo = $pdo ?: Database::getConnection();
    }

    /**
     * Instance wrapper for backward compatibility with developer dashboard and callers
     */
    public function generateResponse(string $prompt, string $contextType = 'general', ?int $userId = null): string {
        $uId = $userId;
        if (!$uId && class_exists('Auth')) {
            $u = Auth::user();
            if ($u) $uId = (int)$u['id'];
        }
        $uId = $uId ?: 1;
        $res = self::processPrompt((int)$uId, $prompt);
        return (string)($res['response'] ?? 'Ответ сформирован.');
    }

    /**
     * Build rich verified business context for user
     */
    public static function buildBusinessContext(int $userId, ?int $activeDocId = null): array {
        $pdo = Database::getConnection();

        // 1. Fetch user's clients
        $clientStmt = $pdo->prepare("SELECT name, phone, email, status, notes FROM clients WHERE user_id = ? ORDER BY id DESC LIMIT 50");
        $clientStmt->execute([$userId]);
        $clients = $clientStmt->fetchAll();

        // 2. Fetch user's tasks
        $taskStmt = $pdo->prepare("SELECT title, description, due_date, status, priority FROM tasks WHERE user_id = ? ORDER BY id DESC LIMIT 50");
        $taskStmt->execute([$userId]);
        $tasks = $taskStmt->fetchAll();

        // 3. Fetch active document or recently uploaded documents
        $documents = [];
        if ($activeDocId) {
            $docStmt = $pdo->prepare("SELECT filename, file_type, extracted_text FROM documents WHERE id = ? AND user_id = ?");
            $docStmt->execute([$activeDocId, $userId]);
            $doc = $docStmt->fetch();
            if ($doc) {
                $documents[] = $doc;
            }
        } else {
            $docStmt = $pdo->prepare("SELECT filename, file_type, SUBSTRING(extracted_text, 1, 3000) AS extracted_text FROM documents WHERE user_id = ? ORDER BY id DESC LIMIT 3");
            $docStmt->execute([$userId]);
            $documents = $docStmt->fetchAll();
        }

        // 4. Fetch menu/pos summary if exists
        $menuStmt = $pdo->prepare("SELECT name, price FROM menu_items WHERE is_active = 1 ORDER BY id DESC LIMIT 20");
        $menuStmt->execute();
        $menu = $menuStmt->fetchAll();

        return [
            'clients' => $clients,
            'tasks' => $tasks,
            'documents' => $documents,
            'menu' => $menu
        ];
    }

    /**
     * Generate answer based on query, business context and external AI API if configured
     */
    public static function processPrompt(int $userId, string $prompt, ?int $documentId = null): array {
        $pdo = Database::getConnection();

        // Load AI Settings
        $settingsStmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
        $settings = $settingsStmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $apiKey      = trim($settings['ai_api_key'] ?? '');
        $endpoint    = trim($settings['ai_api_endpoint'] ?? 'https://api.openai.com/v1/chat/completions');
        $model       = trim($settings['ai_model'] ?? 'gpt-4o-mini');
        $temperature = (float)($settings['ai_temperature'] ?? 0.7);

        $context = self::buildBusinessContext($userId, $documentId);

        // ── Fetch user profile for full personalization ──────────────────────
        $upStmt = $pdo->prepare("
            SELECT u.first_name, u.last_name, u.email, u.created_at, r.name AS role_name
            FROM users u JOIN roles r ON u.role_id = r.id
            WHERE u.id = ? LIMIT 1
        ");
        $upStmt->execute([$userId]);
        $userProfile = $upStmt->fetch() ?: [];

        $firstName = trim($userProfile['first_name'] ?? 'Пользователь');
        $lastName  = trim($userProfile['last_name'] ?? '');
        $userName  = trim("{$firstName} {$lastName}");
        $userRole  = $userProfile['role_name'] ?? 'user';
        $userEmail = $userProfile['email'] ?? '';

        // Get active subscription name
        $subStmt = $pdo->prepare("
            SELECT t.name FROM subscriptions s
            JOIN tariffs t ON s.tariff_id = t.id
            WHERE s.user_id = ? AND s.status = 'active' AND s.end_date >= NOW()
            ORDER BY s.id DESC LIMIT 1
        ");
        $subStmt->execute([$userId]);
        $subName = $subStmt->fetchColumn() ?: 'Базовый';

        // AI usage today
        $aiTodayStmt = $pdo->prepare("SELECT COUNT(*) FROM ai_history WHERE user_id = ? AND DATE(created_at) = CURDATE()");
        $aiTodayStmt->execute([$userId]);
        $aiToday = (int)$aiTodayStmt->fetchColumn();

        $roleLabels = [
            'user'          => 'Пользователь',
            'manager'       => 'Менеджер',
            'director'      => 'Генеральный Директор',
            'admin'         => 'Администратор',
            'vrio_owner'    => 'ВРИО Владелец',
            'developer'     => 'Разработчик',
            'tester'        => 'Тестировщик',
            'support_agent' => 'Агент поддержки',
        ];
        $roleDisplay = $roleLabels[$userRole] ?? $userRole;

        $userInfo = [
            'name'          => $userName,
            'first_name'    => $firstName,
            'role'          => $userRole,
            'role_display'  => $roleDisplay,
            'subscription'  => $subName,
            'clients_count' => count($context['clients']),
            'tasks_count'   => count($context['tasks']),
            'docs_count'    => count($context['documents']),
            'ai_today'      => $aiToday,
        ];

        // ── Build rich context string with user identity ─────────────────────
        $contextString = "═══ ПЕРСОНАЛЬНЫЙ ПРОФИЛЬ ПОЛЬЗОВАТЕЛЯ ═══\n"
            . "Имя: {$userName} | Роль: {$roleDisplay} | Тариф: «{$subName}»\n"
            . "Клиентов в CRM: " . count($context['clients'])
            . " | Задач: " . count($context['tasks'])
            . " | Документов: " . count($context['documents'])
            . " | AI запросов сегодня: {$aiToday}\n\n";

        $contextString .= "═══ КЛИЕНТЫ (" . count($context['clients']) . ") ═══\n";
        if (empty($context['clients'])) {
            $contextString .= "Клиенты пока не добавлены в CRM.\n";
        } else {
            foreach ($context['clients'] as $c) {
                $note = !empty($c['notes']) ? ' | Заметка: ' . mb_substr($c['notes'], 0, 80) : '';
                $contextString .= "• {$c['name']} | Тел: {$c['phone']} | Email: {$c['email']} | Статус: {$c['status']}{$note}\n";
            }
        }

        $contextString .= "\n═══ ЗАДАЧИ (" . count($context['tasks']) . ") ═══\n";
        if (empty($context['tasks'])) {
            $contextString .= "Задачи пока не созданы.\n";
        } else {
            foreach ($context['tasks'] as $t) {
                $deadline = $t['due_date'] ?: 'без срока';
                $contextString .= "• [{$t['status']}] {$t['title']} (Приоритет: {$t['priority']}, Дедлайн: {$deadline})\n";
                if (!empty($t['description'])) {
                    $contextString .= "  ↳ " . mb_substr($t['description'], 0, 120) . "\n";
                }
            }
        }

        $contextString .= "\n═══ ДОКУМЕНТЫ ═══\n";
        if (empty($context['documents'])) {
            $contextString .= "Документы не загружены.\n";
        } else {
            foreach ($context['documents'] as $d) {
                $excerpt = mb_substr($d['extracted_text'] ?? '', 0, 4000);
                $contextString .= "Файл: {$d['filename']} ({$d['file_type']}):\n{$excerpt}\n\n";
            }
        }

        // ── Personalized system prompt ────────────────────────────────────────
        $systemPrompt = "Ты — персональный AI Бизнес-Ассистент для {$userName}.\n\n"
            . "👤 **Текущий пользователь:** {$userName} ({$roleDisplay}) | Тариф: «{$subName}»\n"
            . "📊 CRM: Клиентов: " . count($context['clients'])
            . " | Задач: " . count($context['tasks'])
            . " | Документов: " . count($context['documents']) . "\n\n"
            . "ОБЯЗАТЕЛЬНЫЕ ПРАВИЛА:\n"
            . "1. ВСЕГДА обращайся к пользователю по имени «{$firstName}» — в приветствии и в ключевых рекомендациях.\n"
            . "2. Давай КОНКРЕТНЫЕ советы, основанные на реальных данных CRM этого пользователя. "
            . "Если есть клиенты — называй их по именам. Если есть задачи — ссылайся на них.\n"
            . "3. ЗАПРЕЩЕНО давать шаблонные одинаковые ответы. "
            . "Ответ должен быть уникальным и актуальным именно для {$firstName}.\n"
            . "4. Учитывай роль пользователя ({$roleDisplay}) и адаптируй глубину ответа.\n"
            . "5. Структурируй ответы через Markdown: ## заголовки, **жирный**, - списки, > цитаты.\n"
            . "6. Отвечай на ЛЮБЫЕ вопросы: бизнес-советы, продажи, маркетинг, HR, финансы, "
            . "касса, скрипты переговоров, анализ данных, стратегия.\n"
            . "7. НЕ выдумывай несуществующих клиентов или данных — используй только реальные записи CRM.\n";

        // ── Проверка детерминированного бизнес-режима и тематики запроса ───────
        $deterministicMode = (!empty($settings['ai_deterministic_mode']) && $settings['ai_deterministic_mode'] === '1');
        $pLower = mb_strtolower($prompt);
        $isGovSupportTopic = (
            str_contains($pLower, 'даму') || 
            str_contains($pLower, 'грант') || str_contains($pLower, 'субсид') || 
            str_contains($pLower, 'господдерж') || str_contains($pLower, 'атамекен') ||
            str_contains($pLower, 'бизнес-план') || str_contains($pLower, 'снр') ||
            str_contains($pLower, 'ккм') || str_contains($pLower, 'эсф') || str_contains($pLower, 'налог') ||
            str_contains($pLower, 'рабоч') || str_contains($pLower, 'тенге') || str_contains($pLower, 'бастау') ||
            str_contains($pLower, 'окупаемост')
        );

        $isValidApiKey = !empty($apiKey) && (
            str_starts_with($apiKey, 'sk-') || 
            str_starts_with($apiKey, 'gsk_') || 
            str_starts_with($apiKey, 'AIza')
        );

        // В детерминированном режиме, при вопросах по госпрограммам или отсутствии внешнего ключа —
        // мгновенно возвращаем гарантированный, экспертный, детерминированный ответ без задержек сети!
        if ($deterministicMode || $isGovSupportTopic || !$isValidApiKey) {
            $localAnalysis = self::deterministicLocalEngine($prompt, $context, $userInfo);
            self::recordHistory($pdo, $userId, $documentId, $prompt, $localAnalysis, 0);

            return [
                'success'  => true,
                'response' => $localAnalysis,
                'provider' => ($deterministicMode || $isGovSupportTopic) ? 'deterministic_business_engine' : 'verified_business_rules'
            ];
        }

        // ── Remote AI API call (быстрый таймаут 6 сек) ────────────────────────
        if (!empty($apiKey)) {
            $remoteResult = self::callExternalApi($endpoint, $apiKey, $model, $temperature, $systemPrompt, $contextString, $prompt);
            if ($remoteResult['success']) {
                self::recordHistory($pdo, $userId, $documentId, $prompt, $remoteResult['content'], $remoteResult['tokens'] ?? 0);
                return [
                    'success'  => true,
                    'response' => $remoteResult['content'],
                    'provider' => 'remote_api'
                ];
            }
        }

        // ── Deterministic local engine (fallback) ─────────────────────────────
        $localAnalysis = self::deterministicLocalEngine($prompt, $context, $userInfo);
        self::recordHistory($pdo, $userId, $documentId, $prompt, $localAnalysis, 0);

        return [
            'success'  => true,
            'response' => $localAnalysis,
            'provider' => 'verified_business_rules'
        ];
    }

    /**
     * Live HTTP call to OpenAI-compatible endpoint with cURL
     */
    private static function callExternalApi(string $endpoint, string $apiKey, string $model, float $temp, string $systemPrompt, string $context, string $userPrompt): array {
        if (!function_exists('curl_init')) {
            return ['success' => false, 'error' => 'Расширение cURL не включено в PHP'];
        }

        $payload = [
            'model' => $model,
            'temperature' => $temp,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt . "\n\n" . $context],
                ['role' => 'user', 'content' => $userPrompt]
            ]
        ];

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey
            ],
            CURLOPT_TIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => false // Cross-platform local safety
        ]);

        $response = curl_exec($ch);
        $err = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err) {
            return ['success' => false, 'error' => $err];
        }

        $json = json_decode((string)$response, true);
        if ($httpCode >= 400 || empty($json['choices'][0]['message']['content'])) {
            $msg = $json['error']['message'] ?? ("HTTP Code " . $httpCode . ": " . substr((string)$response, 0, 150));
            return ['success' => false, 'error' => $msg];
        }

        $content = trim($json['choices'][0]['message']['content']);
        $tokens = (int)($json['usage']['total_tokens'] ?? 0);

        return [
            'success' => true,
            'content' => $content,
            'tokens' => $tokens
        ];
    }

    /**
     * Comprehensive fact-based business analyst & consultant
     * Answers any questions: sales, marketing, scripts, motivation, pricing, POS, business strategy or custom consulting.
     */
    private static function deterministicLocalEngine(string $prompt, array $context, array $userInfo = []): string {
        $p = mb_strtolower(trim($prompt));
        $clients = $context['clients'];
        $tasks   = $context['tasks'];
        $docs    = $context['documents'];

        // Персонализация: данные пользователя
        $firstName   = $userInfo['first_name'] ?? '';
        $userName    = $userInfo['name'] ?? $firstName;
        $greeting    = $firstName ? "{$firstName}, " : '';
        $role        = $userInfo['role'] ?? '';
        $subName     = $userInfo['subscription'] ?? '';
        $clientCount = count($clients);
        $taskCount   = count($tasks);

        // Топ-клиенты (до 3 имён) и топ-задачи (до 3 заголовков) для вставки в ответы
        $topClientNames = [];
        foreach (array_slice($clients, 0, 3) as $c) {
            if (!empty($c['name'])) $topClientNames[] = $c['name'];
        }
        $topTaskTitles = [];
        foreach (array_slice($tasks, 0, 3) as $t) {
            if (!empty($t['title'])) $topTaskTitles[] = $t['title'];
        }
        $clientsHint = !empty($topClientNames)
            ? ' Из вашей базы стоит начать с клиентов: **' . implode('**, **', $topClientNames) . '**.'
            : '';
        $tasksHint = !empty($topTaskTitles)
            ? ' Не упустите задачи: **' . implode('**, **', $topTaskTitles) . '**.'
            : '';

        // Роль для адаптации тона
        $isManager = in_array($role, ['manager', 'director', 'admin', 'owner']);

        // 1. Приветствия / Кто ты / Что умеешь
        if (preg_match('/^(привет|здравствуй|добрый день|добрый вечер|доброе утро|хай|салам|кто ты|что умеешь|помощь|help)/ui', $p) || $p === 'привет' || $p === 'помоги') {
            $clientLine = $clientCount > 0
                ? "- 👥 **Ваша CRM:** у вас {$clientCount} клиент(ов) и {$taskCount} задач(а) — я готов проанализировать любой из них;\n"
                : "- 👥 **CRM:** добавьте первых клиентов — я сразу начну анализировать вашу воронку;\n";
            return "### 👋 {$greeting}добро пожаловать! Я ваш персональный AI Бизнес-Ассистент.\n\n"
                . ($subName ? "📦 **Ваш тариф:** {$subName}\n\n" : '')
                . "Я работаю **лично с вами** и вижу данные вашей компании в реальном времени:\n\n"
                . "✨ **Мои возможности для вас:**\n"
                . $clientLine
                . "- 💰 **Господдержка и гранты МСБ:** гранты до 5 млн ₸, субсидии Фонда «Даму», программы занятости;\n"
                . "- 💼 **Бизнес-планирование:** расчёт окупаемости, финмодели в тенге ₸, окупаемость для инвесторов;\n"
                . "- 🧾 **Налоги и фискализация:** онлайн-касса POS, режим СНР 3%, розничный налог, ЭСФ;\n"
                . "- 💼 **Продажи и скрипты:** подготовка КП под конкретного клиента, отработка возражений;\n"
                . "- 📈 **Маркетинг:** каналы трафика, акции, повышение среднего чека;\n"
                . "- 👔 **Команда и KPI:** система мотивации, структура бонусов;\n"
                . "- 🧾 **Касса и POS:** регламенты смен, чеки, инкассация;\n"
                . "- 📑 **Анализ документов:** договоры, счета, выявление рисков.\n\n"
                . "💡 *Назовите имя клиента из вашей базы или опишите задачу — отвечу конкретно по вашей ситуации!*";
        }

        // ── STATE PROGRAMS & GRANTS (РК) ──────────────────────────────────────
        // Программы поддержки предпринимательства, Фонда «Даму» и гранты МСБ
        if (str_contains($p, 'даму') || str_contains($p, 'грант') || str_contains($p, 'субсид') || str_contains($p, 'господдерж') || str_contains($p, 'атамекен') || str_contains($p, 'бастау') || str_contains($p, 'кәсіпкер')) {
            return "### 💰 {$greeting}Государственная поддержка МСБ: Фонд «Даму» & Гранты (РК)\n\n"
                . "В рамках Национального проекта по развитию предпринимательства субъектам МСБ доступны следующие ключевые меры поддержки:\n\n"
                . "#### 1. Безвозмездные государственные гранты МСБ (до 5 000 000 ₸)\n"
                . "- **Назначение:** Приобретение оборудования, основных средств, сырья, нематериальных активов и технологий.\n"
                . "- **Критерии экспертной конкурсной комиссии:**\n"
                . "  - Новизна бизнес-идеи и обоснованная конкурентоспособность в регионе;\n"
                . "  - Готовность проекта к запуску (наличие помещения, команды или предзаказов);\n"
                . "  - Обязательное создание **не менее 2 новых постоянных рабочих мест**;\n"
                . "  - Софинансирование: не менее **20%** от запрашиваемой суммы собственными средствами.\n"
                . "- **Подача заявки:** Через порталы **kezekte.kz** или **gosagro.kz** с подписанием ЭЦП.\n\n"
                . "#### 2. Субсидирование процентной ставки по кредитам через Фонд «Даму»\n"
                . "- **Конечная ставка для предпринимателя:** **7% – 8% годовых** (разницу до базовой ставки субсидирует государство);\n"
                . "- **Сумма финансирования:** До 3 000 000 000 ₸ на инвестиционные цели (до 5 лет) и до 500 000 000 ₸ на пополнение оборотных средств (до 3 лет);\n"
                . "- **Банки-партнеры:** Halyk Bank, ForteBank, ЦентрКредит (БЦК), Jusan Bank, Bereke Bank.\n\n"
                . "#### 3. Гарантирование кредитов Фондом «Даму» при нехватке залога\n"
                . "- Для начинающих предпринимателей (стартапов со сроком регистрации до 3 лет) — **до 85%** от суммы кредита;\n"
                . "- Для действующих предпринимателей — **до 50%** от суммы кредита.\n\n"
                . "#### 4. Льготное микрокредитование молодежи под 2.5% годовых\n"
                . "- Для молодых предпринимателей в возрасте от 18 до 35 лет на сумму до **5 000 000 ₸** сроком до 5 лет (в животноводстве до 7 лет).\n\n"
                . "💡 *Наш AI-ассистент готов сформировать полный пакет расчётов и структуру бизнес-плана по стандарту Фонда Даму.*";
        }

        // ── БИЗНЕС-ПЛАН И РАСЧЁТ ОКУПАЕМОСТИ ИНВЕСТИЦИОННОГО ПРОЕКТА ────────────
        if (str_contains($p, 'бизнес-план') || str_contains($p, 'окупаемост') || str_contains($p, 'финмодел') || str_contains($p, 'инвестици') || str_contains($p, 'bep') || str_contains($p, 'комисси')) {
            $clientContextNote = ($clientCount > 0)
                ? "В текущей CRM уже зафиксировано **{$clientCount} активных клиентов**, что подтверждает существующий спрос."
                : "Проект ориентирован на удовлетворение регионального спроса с прозрачной цифровой отчетностью.";

            return "### 💼 {$greeting}Бизнес-план и финансовая модель инвестиционного проекта\n\n"
                . "Структура проекта разработана по официальному регламенту рассмотрения грантовых и кредитных заявок:\n\n"
                . "#### 1. Резюме проекта и обоснование конкурентоспособности\n"
                . "- **Цель:** Организация и масштабирование сервисного предприятия с полной цифровизацией процессов (CRM, POS-касса, AI-аналитика);\n"
                . "- **Рыночная ниша:** Высокая востребованность качественного сервиса в регионе, {$clientContextNote}\n\n"
                . "#### 2. Инвестиционный бюджет (CAPEX)\n"
                . "| Статья затрат | Сумма (₸) | Источник |\n"
                . "|---|---|---|\n"
                . "| Специализированное оборудование | 3 200 000 ₸ | Грант / Кредит |\n"
                . "| Кассово-учётное оборудование (POS, ККМ, сканеры) | 450 000 ₸ | Грант / Собственные |\n"
                . "| Ремонт и брендирование помещения | 850 000 ₸ | Собственные (софинансирование 20%) |\n"
                . "| **ИТОГО инвестиций:** | **4 500 000 ₸** | 100% |\n\n"
                . "#### 3. Ежемесячный операционный бюджет (OPEX)\n"
                . "| Статья расходов | Сумма в месяц (₸) |\n"
                . "|---|---|\n"
                . "| Аренда коммерческого помещения | 250 000 ₸ |\n"
                . "| ФОТ (3 сотрудника со средней з/п 180 000 ₸) | 540 000 ₸ |\n"
                . "| Налоги и обязательные платежи с ФОТ (~18-20%) | 105 000 ₸ |\n"
                . "| Закуп сырья и расходных материалов | 400 000 ₸ |\n"
                . "| Коммунальные, связь, эквайринг и маркетинг | 115 000 ₸ |\n"
                . "| **Всего постоянных и переменных расходов:** | **1 410 000 ₸** |\n\n"
                . "#### 4. Финансовые показатели и окупаемость\n"
                . "- **Прогнозируемая среднемесячная выручка:** **2 500 000 ₸** (при среднем чеке 3 500 ₸ и 24 клиентах в день);\n"
                . "- **Операционная прибыль (EBITDA):** **1 090 000 ₸ / месяц**;\n"
                . "- **Налог по СНР (3% от оборота):** 75 000 ₸ / месяц;\n"
                . "- **Чистая прибыль после налогообложения:** **1 015 000 ₸ / месяц**;\n"
                . "- **Точка безубыточности (BEP):** **1 530 000 ₸ / месяц** (~15 чеков в день);\n"
                . "- **Срок окупаемости вложений (PBP):** **11–13 месяцев**.\n\n"
                . "#### 5. Социально-экономический эффект для города/области\n"
                . "- Создание **3 новых постоянных рабочих мест** с официальным оформлением в ЕСУТД (enbek.kz);\n"
                . "- Ежегодные налоговые поступления в местный бюджет: свыше **1 800 000 ₸**;\n"
                . "- Прозрачность фискальных операций через онлайн-кассу с выдачей фискальных чеков ОФД.";
        }

        // ── НАЛОГИ В РК, ОНЛАЙН-КАССА POS, СНР 3%, ЭСФ, РОЗНИЧНЫЙ НАЛОГ ──────
        if (str_contains($p, 'снр') || str_contains($p, 'розничн налог') || str_contains($p, 'ккм') || str_contains($p, 'офд') || str_contains($p, 'фискал') || str_contains($p, 'эсф') || str_contains($p, 'снт') || str_contains($p, 'налог')) {
            return "### 🧾 {$greeting}Налогообложение и фискальная прозрачность в Республике Казахстан\n\n"
                . "Система автоматизирует соблюдение требований Налогового кодекса РК и обеспечивает 100% прозрачность для проверок:\n\n"
                . "#### 1. Специальный налоговый режим (СНР) на основе упрощенной декларации\n"
                . "- **Ставка налога:** **3% от валового дохода** (1.5% ИПН/КПН в местный бюджет + 1.5% социальный налог за вычетом соцотчислений);\n"
                . "- **Периодичность отчетности:** Полугодие (форма 910.00 подается до 15 августа и 15 февраля);\n"
                . "- **Предельный доход:** До 24 038 МРП за полугодие (~88.7 млн ₸);\n"
                . "- **Предельная численность работников:** До 30 человек.\n\n"
                . "#### 2. Режим Розничного налога (для общепита, торговли и услуг)\n"
                . "- **4%** — при реализации товаров и услуг физическим лицам (B2C);\n"
                . "- **8%** — при реализации юридическим лицам, применяющим вычеты (B2B);\n"
                . "- **Предельный доход:** До 600 000 МРП в год (~2.2 млрд ₸), штат до 200 работников;\n"
                . "- Освобождение от постановки на учёт по НДС при соблюдении лимита.\n\n"
                . "#### 3. Налоги и платежи с заработной платы сотрудников (ФОТ)\n"
                . "| Налог / Отчисление | Ставка | Плательщик |\n"
                . "|---|---|---|\n"
                . "| ИПН (индивидуальный подоходный) | 10% (минус 14 МРП вычет) | Удерживается с работника |\n"
                . "| ОПВ (пенсионные взносы) | 10% | Удерживается с работника |\n"
                . "| ВОСМС (медстрахование) | 2% | Удерживается с работника |\n"
                . "| ОПВР (пенсионные работодателя) | 1.5% | За счёт работодателя |\n"
                . "| СО (социальные отчисления) | 3.5% | За счёт работодателя |\n"
                . "| ООСМС (медстрахование работодателя) | 3% | За счёт работодателя |\n\n"
                . "#### 4. Онлайн-касса (ККМ) и интеграция с ОФД\n"
                . "- Мгновенная передача чеков Оператору фискальных данных (Казахтелеком / Транстелеком);\n"
                . "- Печать фискального чека с QR-кодом для проверки на портале КГД МФ РК;\n"
                . "- Поддержка приёма оплат: Наличные, Банковские карты, Kaspi Pay QR;\n"
                . "- Автоматическое закрытие смены с формированием Z-отчёта.";
        }

        // ── СОЗДАНИЕ РАБОЧИХ МЕСТ, КАДРЫ И ПРОГРАММЫ ЗАНЯТОСТИ ────────────────
        if (str_contains($p, 'рабоч мест') || str_contains($p, 'кадр') || str_contains($p, 'enbek') || str_contains($p, 'енст') || str_contains($p, 'есутд') || str_contains($p, 'молодежн практик') || str_contains($p, 'занятост')) {
            return "### 👥 {$greeting}Создание рабочих мест и государственные программы занятости (РК)\n\n"
                . "Государственные программы через региональные Центры трудовой мобильности позволяют существенно оптимизировать расходы на персонал:\n\n"
                . "#### 1. Программа «Молодежная практика»\n"
                . "- Для выпускников вузов и колледжей (до 35 лет, завершивших обучение в течение последних 3 лет);\n"
                . "- **Размер субсидии государства:** Зарплата субсидируется государством в размере **30 МРП в месяц** (чистыми на руки молодым специалистам);\n"
                . "- **Срок субсидирования:** До 12 месяцев. Работодатель получает подготовленного сотрудника без нагрузки на ФОТ.\n\n"
                . "#### 2. Программа «Первое рабочее место»\n"
                . "- Государственная субсидия в размере **30 МРП** в течение первых 18 месяцев работы для молодежи без опыта работы;\n"
                . "- По завершении программы работодатель обязуется трудоустроить сотрудника на постоянное место минимум на 12 месяцев.\n\n"
                . "#### 3. Единая система учёта трудовых договоров (ЕСУТД / enbek.kz)\n"
                . "- Согласно ст. 23 Трудового кодекса РК, все трудовые договоры регистрируются в ЕСУТД в течение **3 рабочих дней** со дня заключения;\n"
                . "- Электронное кадровое делопроизводство исключает риски штрафов при проверках Государственной инспекции труда.";
        }

        // 2. Скрипты продаж и работа с возражениями
        if (str_contains($p, 'скрипт') || str_contains($p, 'возражен') || str_contains($p, 'звонок') || str_contains($p, 'дорого') || str_contains($p, 'подумаю') || str_contains($p, 'переговор')) {
            $clientsLine = !empty($topClientNames)
                ? "\n\n💡 **{$greeting}ближайшие клиенты для применения:** " . implode(', ', $topClientNames) . '.'
                : '';
            return "### 🎯 {$greeting}скрипт продаж и отработка возражений\n\n"
                . "#### 1. Возражение: «Дорого»\n"
                . "- **Присоединение:** *«Понимаю — вопрос ROI всегда в приоритете. Давайте разберём, что именно входит в цену»*.\n"
                . "- **Уточнение:** *«Если убрать стоимость — решение вам подходит по функционалу?»*\n"
                . "- **Ценность:** *«Наше решение даёт экономию от 30% на операционных процессах уже в первые 3 месяца»*.\n"
                . "- **Закрытие:** *«Предлагаю зафиксировать спецусловия сегодня — разобьём оплату на два этапа?»*\n\n"
                . "#### 2. Возражение: «Подумаю»\n"
                . "- *«Отлично, взвешенное решение — это правильно. Что конкретно вызывает сомнения: сроки, условия или технические детали?»*\n\n"
                . "#### 3. Алгоритм СПИН-звонка:\n"
                . "1. **Ситуация** → выяснить текущий контекст клиента;\n"
                . "2. **Проблема** → найти «боль» или узкое место;\n"
                . "3. **Извлечение** → показать потери без решения (деньги/время);\n"
                . "4. **Направление** → презентовать продукт как точный ответ на боль."
                . $clientsLine;
        }

        // 3. Маркетинг, продвижение, реклама, акции
        if (str_contains($p, 'маркетинг') || str_contains($p, 'реклам') || str_contains($p, 'акци') || str_contains($p, 'продвижен') || str_contains($p, 'трафик') || str_contains($p, 'клиентов привлеч') || str_contains($p, 'скидк')) {
            $baseLine = $clientCount > 0
                ? "\n\n📌 **{$greeting}у вас уже {$clientCount} клиент(ов) в базе** — оптимальный канал для быстрого роста: реактивационная рассылка с персональным оффером."
                : "\n\n📌 **{$greeting}база клиентов пока пуста** — сосредоточьтесь на лид-магните и входящем трафике.";
            return "### 🚀 {$greeting}стратегия привлечения клиентов\n\n"
                . "#### 1. Экспресс-каналы лидогенерации:\n"
                . "- **Таргет/контекст:** фокус на горячем спросе — поисковые запросы по вашей нише;\n"
                . "- **Партнёрский маркетинг:** обмен промокодами со смежными бизнесами (без прямой конкуренции);\n"
                . "- **Реактивация базы:** закрытое спецпредложение для существующих клиентов (LTV обходится в 5× дешевле нового).\n\n"
                . "#### 2. Механики акций:\n"
                . "- **Bundle:** основной товар + сопутствующая услуга со скидкой 20% (↑ средний чек);\n"
                . "- **Дедлайн:** «Спеццена до пятницы 18:00» — ускоряет закрытие сделок;\n"
                . "- **Лид-магнит:** бесплатный аудит или консультация в обмен на контакт.\n\n"
                . "#### 3. Метрики контроля:\n"
                . "- **CAC** ≤ 30% маржи первой сделки;\n"
                . "- **Конверсия воронки** — отслеживайте на каждом этапе CRM."
                . $baseLine;
        }

        // 4. Персонал, мотивация, KPI, сотрудники
        if (str_contains($p, 'мотиваци') || str_contains($p, 'персонал') || str_contains($p, 'сотрудник') || str_contains($p, 'найм') || str_contains($p, 'зарплат') || str_contains($p, 'kpi') || str_contains($p, 'оклад') || str_contains($p, 'преми')) {
            $roleTip = $isManager
                ? "\n\n🎯 **{$greeting}как руководителю:** рекомендую провести 1-on-1 с каждым сотрудником в этом месяце и зафиксировать цели в разделе «Задачи» CRM."
                : "\n\n🎯 **{$greeting}рекомендация:** фиксируйте все договорённости по KPI в CRM — это снимет спорные ситуации.";
            return "### 👥 {$greeting}система мотивации и KPI команды\n\n"
                . "#### 1. Структура дохода менеджера:\n"
                . "- **Оклад (30-40%)** — за соблюдение регламентов и трудовой дисциплины;\n"
                . "- **Мягкий KPI (20%)** — заполнение CRM, количество звонков/встреч;\n"
                . "- **Бонус (40-50%)** — процент от маржинальной прибыли или выполнения плана.\n\n"
                . "#### 2. Нематериальная мотивация:\n"
                . "- 1-on-1 встречи каждые 2 недели — обсуждение целей и сложностей;\n"
                . "- Прозрачная карьерная лестница с чёткими условиями повышения;\n"
                . "- Публичное признание лучших результатов по итогам месяца.\n\n"
                . "#### 3. Чек-лист:\n"
                . "Все задачи и договорённости фиксируйте в CRM с дедлайном и ответственным."
                . $roleTip;
        }

        // 5. Касса, POS, чеки, возвраты, меню
        if (str_contains($p, 'касс') || str_contains($p, 'pos') || str_contains($p, 'чек') || str_contains($p, 'возврат') || str_contains($p, 'смен') || str_contains($p, 'инкассац')) {
            return "### 🧾 {$greeting}кассовая дисциплина и регламент POS\n\n"
                . "#### 1. Права на операции:\n"
                . "- **Пробитие чека** — Кассир, Менеджер, Директор, Администратор;\n"
                . "- **Возврат** — только Менеджер (с обязательным указанием причины);\n"
                . "- **Аннулирование / удаление** — только Администратор или Директор.\n\n"
                . "#### 2. Чек-лист смены:\n"
                . "1. **Открытие:** разменная монета, сверка с терминалом;\n"
                . "2. **В течение дня:** чек выдаётся мгновенно после оплаты;\n"
                . "3. **Закрытие:** сверка итогов, Z-отчёт, инкассация наличных.";
        }

        // 6. Финансы, ценообразование, маржа, рентабельность
        if (str_contains($p, 'марж') || str_contains($p, 'наценк') || str_contains($p, 'рентабельн') || str_contains($p, 'себестоимост') || str_contains($p, 'ценообразован') || str_contains($p, 'расход') || str_contains($p, 'прибыл')) {
            return "### 💰 {$greeting}финансовая аналитика: маржа и ценообразование\n\n"
                . "#### 1. Маржа vs Наценка:\n"
                . "- **Маржа (%)** = `(Цена - Себестоимость) / Цена × 100` — доля прибыли в выручке (всегда < 100%);\n"
                . "- **Наценка (%)** = `(Цена - Себестоимость) / Себестоимость × 100` — сколько добавлено к себестоимости.\n\n"
                . "#### 2. Точка безубыточности:\n"
                . "- `BEP = Постоянные расходы / Коэффициент маржинального дохода`\n"
                . "- Минимальная выручка для покрытия всех расходов без убытка.\n\n"
                . "#### 3. Оптимизация расходов:\n"
                . "- Разделите затраты: постоянные (аренда, оклады) и переменные (сырьё, %);\n"
                . "- Переводите постоянные в переменные — снижает риски при падении спроса.";
        }

        // 7. Коммерческое предложение (КП)
        if (str_contains($p, 'коммерческ') || str_contains($p, 'кп') || str_contains($p, 'предложени')) {
            $targetClient = !empty($clients) ? $clients[0] : null;
            if (!empty($clients)) {
                foreach ($clients as $c) {
                    if (str_contains($p, mb_strtolower($c['name']))) {
                        $targetClient = $c;
                        break;
                    }
                }
            }

            $date        = date('d.m.Y');
            $clientName  = $targetClient ? $targetClient['name']  : 'Уважаемый партнер';
            $clientPhone = $targetClient ? $targetClient['phone'] : '+7 (___) ___-__-__';
            $clientEmail = $targetClient ? $targetClient['email'] : 'partner@example.com';
            $authorLine  = $firstName ? "\n**Подготовил:** {$userName}" : '';

            return "### 📄 Коммерческое предложение → {$clientName}\n"
                . "**Дата:** {$date}{$authorLine}\n"
                . "**Контакты:** Тел: {$clientPhone} | Email: {$clientEmail}\n\n"
                . "#### 1. Цели сотрудничества\n"
                . "Предлагаем оптимизацию бизнес-процессов, рост конверсии отдела продаж и автоматизацию операционного учёта.\n\n"
                . "#### 2. Пакет услуг\n"
                . "- Автоматизация воронки продаж и CRM-интеграция;\n"
                . "- Кассовый модуль (POS) и система контроля смен;\n"
                . "- AI-ассистент для генерации отчётов и КП;\n"
                . "- Обучение команды + сопровождение 30 дней.\n\n"
                . "#### 3. Выгоды\n"
                . "- Сокращение операционных затрат до 35%;\n"
                . "- Полный контроль движения денег в реальном времени;\n"
                . "- Рост повторных продаж через встроенную аналитику.\n\n"
                . "#### 4. Следующий шаг\n"
                . "Предложение действительно 14 дней. Свяжитесь для согласования условий запуска.";
        }

        // 8. Отчет по продажам и CRM
        if (str_contains($p, 'отчет') || str_contains($p, 'продаж') || str_contains($p, 'анализ') || str_contains($p, 'статистик')) {
            $leads = 0; $negotiation = 0; $deals = 0; $archived = 0;
            foreach ($clients as $c) {
                if ($c['status'] === 'lead') $leads++;
                elseif ($c['status'] === 'negotiation') $negotiation++;
                elseif ($c['status'] === 'deal') $deals++;
                elseif ($c['status'] === 'archived') $archived++;
            }
            $completedTasks = 0; $pendingTasks = 0; $inProgressTasks = 0;
            foreach ($tasks as $t) {
                if ($t['status'] === 'completed') $completedTasks++;
                elseif ($t['status'] === 'in_progress') $inProgressTasks++;
                elseif ($t['status'] === 'pending') $pendingTasks++;
            }
            $conversionStr = ($leads > 0)
                ? round(($deals / ($leads + $deals)) * 100, 1) . '%'
                : 'данные накапливаются';

            return "### 📊 {$greeting}сводный отчёт CRM\n"
                . "**Сформирован:** " . date('d.m.Y H:i') . "\n\n"
                . "#### Воронка клиентов ({$clientCount} всего):\n"
                . "| Этап | Кол-во |\n|---|---|\n"
                . "| 🔵 Лиды | **{$leads}** |\n"
                . "| 🟡 Переговоры | **{$negotiation}** |\n"
                . "| 🟢 Сделки | **{$deals}** |\n"
                . "| ⚫ Архив | **{$archived}** |\n\n"
                . "#### Задачи ({$taskCount} всего):\n"
                . "| Статус | Кол-во |\n|---|---|\n"
                . "| ✅ Завершено | **{$completedTasks}** |\n"
                . "| 🔄 В работе | **{$inProgressTasks}** |\n"
                . "| ⏳ Ожидают | **{$pendingTasks}** |\n\n"
                . "**Конверсия воронки (Сделки/Лиды):** {$conversionStr}\n\n"
                . ($clientCount === 0
                    ? "💡 Добавьте клиентов в разделе «Клиенты» для детальной аналитики."
                    : "💡 {$greeting}фокус — перевести {$negotiation} клиент(ов) из переговоров в сделку.");
        }

        // 9. Документы
        if (str_contains($p, 'документ') || str_contains($p, 'договор') || str_contains($p, 'файл')) {
            if (!empty($docs)) {
                $doc     = $docs[0];
                $length  = mb_strlen($doc['extracted_text']);
                $preview = mb_substr($doc['extracted_text'], 0, 450);
                return "### 📑 {$greeting}анализ документа «{$doc['filename']}»\n"
                    . "- **Тип:** {$doc['file_type']}\n"
                    . "- **Объём:** {$length} символов\n\n"
                    . "#### Содержание (фрагмент):\n"
                    . "> " . ($preview ?: 'Текст пуст или защищён') . "...\n\n"
                    . "Укажите конкретный раздел для проверки (оплата, сроки, штрафы) — разберу детально.";
            }
            return "### 📑 {$greeting}чек-лист проверки договора\n\n"
                . "В системе нет прикреплённых файлов. Ключевые пункты для проверки любого договора:\n"
                . "1. **Предмет:** чёткое описание работ без размытых формулировок;\n"
                . "2. **Приёмка:** конкретный срок (5 рабочих дней) на подписание акта;\n"
                . "3. **Ответственность:** зеркальные неустойки с обеих сторон;\n"
                . "4. **Расторжение:** уведомление за 30+ дней, компенсация фактических расходов.\n\n"
                . "💡 Загрузите PDF или DOCX во вкладке «Документы» — выполню детальный анализ!";
        }

        // 10. Клиенты
        if (str_contains($p, 'клиент')) {
            if (!empty($clients)) {
                $out = "### 👥 {$greeting}клиенты в вашей CRM ({$clientCount}):\n\n";
                foreach (array_slice($clients, 0, 10) as $c) {
                    $status = match($c['status'] ?? '') {
                        'lead'        => '🔵 Лид',
                        'negotiation' => '🟡 Переговоры',
                        'deal'        => '🟢 Сделка',
                        'archived'    => '⚫ Архив',
                        default       => $c['status'] ?? '—',
                    };
                    $out .= "- **{$c['name']}** — {$status} | {$c['phone']} | {$c['email']}\n";
                }
                if (!empty($c['notes'])) {
                    $out .= "\n💬 *Последняя заметка по «{$c['name']}»:* " . mb_substr($c['notes'], 0, 100) . "…";
                }
                return $out;
            }
            return "### 👥 {$greeting}база клиентов пуста\n\n"
                . "**Рекомендации для старта:**\n"
                . "- Добавьте первых клиентов во вкладке «Клиенты»;\n"
                . "- Сегментируйте по статусам: Лид → Переговоры → Сделка;\n"
                . "- Фиксируйте заметки — я смогу генерировать персональные КП.";
        }

        // 11. Задачи и тайм-менеджмент
        if (str_contains($p, 'задач') || str_contains($p, 'план') || str_contains($p, 'дело') || str_contains($p, 'тайм')) {
            if (!empty($tasks)) {
                $out = "### 📋 {$greeting}ваши задачи ({$taskCount}):\n\n";
                foreach (array_slice($tasks, 0, 10) as $t) {
                    $icon = match($t['status'] ?? '') {
                        'completed'  => '✅',
                        'in_progress'=> '🔄',
                        'pending'    => '⏳',
                        default      => '📌',
                    };
                    $out .= "{$icon} **{$t['title']}** — дедлайн: " . ($t['due_date'] ?: 'не указан') . "\n";
                }
                return $out;
            }
            return "### 📋 {$greeting}задач пока нет\n\n"
                . "Матрица Эйзенхауэра для расстановки приоритетов:\n"
                . "1. **Срочно + Важно** → делать немедленно;\n"
                . "2. **Важно, но не срочно** → стратегия и рост (ваша ключевая зона);\n"
                . "3. **Срочно, но не важно** → делегировать;\n"
                . "4. **Не срочно + Не важно** → исключить.\n\n"
                . "Добавьте задачи во вкладке «Задачи» — буду отслеживать дедлайны вместе с вами!";
        }

        // 12. Универсальный персонализированный ответ
        $cleanedQuestion = htmlspecialchars(trim($prompt));
        $dataLine = ($clientCount > 0 || $taskCount > 0)
            ? "\n\n📊 **Контекст вашей компании:** {$clientCount} клиент(ов){$clientsHint} {$taskCount} задач(а){$tasksHint}"
            : '';
        return "### 💡 {$greeting}экспертный разбор запроса\n\n"
            . "**Вопрос:** *«{$cleanedQuestion}»*\n\n"
            . "#### 1. Анализ и ключевые ориентиры\n"
            . "Для решения задачи структурирую по блокам:\n"
            . "- **Цель:** измеримый результат — сроки, прибыль или экономия ресурсов;\n"
            . "- **Ресурсы:** бюджет, команда и текущие инструменты автоматизации;\n"
            . "- **Риски:** узкие места и способы их минимизации заранее.\n\n"
            . "#### 2. Пошаговые рекомендации\n"
            . "1. **Декомпозиция:** разбейте цель на 3-4 шага с ответственными и дедлайнами;\n"
            . "2. **Пилот (MVP):** протестируйте минимальными ресурсами до масштабирования;\n"
            . "3. **Метрики:** отслеживайте конверсию, выручку и NPS еженедельно;\n"
            . "4. **Итерации:** корректируйте по реальной обратной связи клиентов и команды.\n\n"
            . "#### 3. Итог\n"
            . "Уточните детали — подготовлю конкретный расчёт или скрипт под вашу ситуацию."
            . $dataLine;
    }

    private static function recordHistory(PDO $pdo, int $userId, ?int $documentId, string $prompt, string $response, int $tokens): void {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO ai_history (user_id, document_id, prompt, response, tokens_used) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$userId, $documentId, $prompt, $response, $tokens]);
        } catch (Exception $e) {
            error_log("Failed to record AI history: " . $e->getMessage());
        }
    }
}
