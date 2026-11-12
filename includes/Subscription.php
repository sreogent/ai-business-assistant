<?php
declare(strict_types=1);

/**
 * AI Business Assistant - Subscription & AI Quotas Manager
 */

require_once __DIR__ . '/Database.php';

class Subscription {
    /**
     * Get active subscription for user
     */
    public static function getUserSubscription(int $userId): ?array {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT s.*, t.name AS tariff_name, t.code AS tariff_code, t.ai_requests_limit, t.price
            FROM subscriptions s
            JOIN tariffs t ON s.tariff_id = t.id
            WHERE s.user_id = ? 
            ORDER BY s.id DESC
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $sub = $stmt->fetch();

        if (!$sub) {
            return null;
        }

        // Check if expired
        $now = date('Y-m-d H:i:s');
        if ($sub['end_date'] < $now && $sub['status'] === 'active') {
            $update = $pdo->prepare("UPDATE subscriptions SET status = 'expired' WHERE id = ?");
            $update->execute([$sub['id']]);
            $sub['status'] = 'expired';
        }

        return $sub;
    }

    /**
     * Check if user subscription is active and has remaining AI requests
     */
    public static function canUseAi(int $userId): array {
        $sub = self::getUserSubscription($userId);

        if (!$sub) {
            return [
                'allowed' => false,
                'reason' => 'У вас нет активной подписки. Перейдите в раздел тарифов для активации.'
            ];
        }

        if ($sub['status'] !== 'active' || $sub['end_date'] < date('Y-m-d H:i:s')) {
            return [
                'allowed' => false,
                'reason' => 'Срок действия вашей подписки истек. Продлите подписку для использования AI ассистента.'
            ];
        }

        // Ежедневный лимит: запросы к ИИ восстанавливаются каждый день в 00:00!
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM ai_history 
            WHERE user_id = ? AND DATE(created_at) = CURDATE()
        ");
        $stmt->execute([$userId]);
        $usedToday = (int)$stmt->fetchColumn();

        // Также подсчитываем общий расход за весь период подписки для статистики
        $totalStmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM ai_history 
            WHERE user_id = ? AND created_at >= ? AND created_at <= ?
        ");
        $totalStmt->execute([$userId, $sub['start_date'], $sub['end_date']]);
        $usedTotal = (int)$totalStmt->fetchColumn();

        $dailyLimit = (int)$sub['ai_requests_limit'];

        if ($dailyLimit > 0 && $usedToday >= $dailyLimit) {
            return [
                'allowed' => false,
                'used' => $usedToday,
                'limit' => $dailyLimit,
                'remaining' => 0,
                'used_total' => $usedTotal,
                'is_daily' => true,
                'reason' => "Исчерпан ежедневный лимит запросов к ИИ ({$usedToday}/{$dailyLimit}). Лимит автоматически восстановится завтра в 00:00!"
            ];
        }

        return [
            'allowed' => true,
            'used' => $usedToday,
            'limit' => $dailyLimit,
            'remaining' => $dailyLimit > 0 ? max(0, $dailyLimit - $usedToday) : 999999,
            'used_total' => $usedTotal,
            'is_daily' => true,
            'subscription' => $sub
        ];
    }

    /**
     * Activate or purchase a subscription
     */
    public static function subscribe(int $userId, int $tariffId, ?int $customDays = null, bool $allowInactive = false): bool {
        $pdo = Database::getConnection();
        $sql = $allowInactive ? "SELECT * FROM tariffs WHERE id = ?" : "SELECT * FROM tariffs WHERE id = ? AND is_active = 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$tariffId]);
        $tariff = $stmt->fetch();

        if (!$tariff) {
            return false;
        }

        $days = (int)$tariff['duration_days'];
        if ($tariff['code'] === 'CUSTOM') {
            $days = $customDays ?: 30;
        }

        $startDate = date('Y-m-d H:i:s');
        $endDate = date('Y-m-d H:i:s', strtotime("+{$days} days"));

        // Deactivate previous active subscriptions
        $deact = $pdo->prepare("UPDATE subscriptions SET status = 'cancelled' WHERE user_id = ? AND status = 'active'");
        $deact->execute([$userId]);

        // Insert new subscription
        $insert = $pdo->prepare("
            INSERT INTO subscriptions (user_id, tariff_id, start_date, end_date, status)
            VALUES (?, ?, ?, ?, 'active')
        ");
        return $insert->execute([$userId, $tariffId, $startDate, $endDate]);
    }

    /**
     * Проверка: использовал ли пользователь бесплатный тариф Free / Trial ранее (доступен только 1 раз)
     */
    public static function hasUsedFree(int $userId): bool {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM subscriptions s
            JOIN tariffs t ON s.tariff_id = t.id
            WHERE s.user_id = ? AND t.code IN ('FREE', 'TRIAL')
        ");
        $stmt->execute([$userId]);
        return ((int)$stmt->fetchColumn()) > 0;
    }

    /**
     * Расчёт правильной суммы для индивидуального периода (Свой тариф)
     * Базовые тарифы: Месяц (30 дн.) = 2 990 ₸ (~100 ₸/дн)
     *                 Полгода (180 дн.) = 14 990 ₸ (~83 ₸/дн)
     *                 Год (365 дн.) = 24 990 ₸ (~68 ₸/дн)
     */
    public static function calculateCustomPrice(int $days): float {
        if ($days <= 0) return 0.00;

        if ($days < 30) {
            return (float)($days * 100);
        } elseif ($days < 180) {
            return (float)round($days * (2990 / 30));
        } elseif ($days < 365) {
            return (float)round($days * (14990 / 180));
        } else {
            return (float)round($days * (24990 / 365));
        }
    }

    /**
     * Активация бесплатного тарифа Free (7 дней, 200 AI-запросов в день, 1 раз)
     */
    public static function activateTrial(int $userId, int $days = 7): array {
        return self::activateFree($userId, $days);
    }

    public static function activateFree(int $userId, int $days = 7): array {
        $pdo = Database::getConnection();

        // Проверяем, есть ли уже активная подписка
        $currentSub = self::getUserSubscription($userId);
        if ($currentSub && $currentSub['status'] === 'active') {
            return [
                'success' => false,
                'message' => "У вас уже подключена активная подписка на тариф «{$currentSub['tariff_name']}» (до " . date('d.m.Y', strtotime($currentSub['end_date'])) . ")."
            ];
        }

        // Проверяем, активировал ли пользователь бесплатный тариф Free ранее (строго 1 раз!)
        if (self::hasUsedFree($userId)) {
            return [
                'success' => false,
                'message' => 'Вы уже использовали бесплатный тариф Free ранее. Он доступен только 1 раз. Пожалуйста, выберите подходящий платный тариф для продолжения.'
            ];
        }

        // Находим или создаем тариф FREE с лимитом 200 запросов/день
        $tStmt = $pdo->prepare("SELECT id FROM tariffs WHERE code = 'FREE' LIMIT 1");
        $tStmt->execute();
        $freeTariffId = $tStmt->fetchColumn();

        if (!$freeTariffId) {
            // Проверяем, может есть старый TRIAL
            $trialStmt = $pdo->prepare("SELECT id FROM tariffs WHERE code = 'TRIAL' LIMIT 1");
            $trialStmt->execute();
            $freeTariffId = $trialStmt->fetchColumn();

            if ($freeTariffId) {
                $pdo->prepare("UPDATE tariffs SET code = 'FREE', name = 'Free (Бесплатный)', duration_days = ?, ai_requests_limit = 200, price = 0.00 WHERE id = ?")
                    ->execute([$days, $freeTariffId]);
            } else {
                $pdo->prepare("
                    INSERT INTO tariffs (code, name, price, duration_days, ai_requests_limit, is_active)
                    VALUES ('FREE', 'Free (Бесплатный)', 0.00, ?, 200, 1)
                ")->execute([$days]);
                $freeTariffId = (int)$pdo->lastInsertId();
            }
        } else {
            // Гарантируем, что лимит 200 запросов в день
            $pdo->prepare("UPDATE tariffs SET ai_requests_limit = 200, duration_days = ?, is_active = 1 WHERE id = ?")
                ->execute([$days, $freeTariffId]);
        }

        $startDate = date('Y-m-d H:i:s');
        $endDate   = date('Y-m-d H:i:s', strtotime("+{$days} days"));

        $pdo->prepare("
            INSERT INTO subscriptions (user_id, tariff_id, start_date, end_date, status)
            VALUES (?, ?, ?, ?, 'active')
        ")->execute([$userId, $freeTariffId, $startDate, $endDate]);

        Security::log($pdo, 'free_tariff_activated', "Пользователь активировал бесплатный тариф Free на {$days} дней (200 запросов/день)", $userId);

        return [
            'success' => true,
            'message' => "🎉 Бесплатный тариф Free на {$days} дней успешно активирован! Вам начислено 200 AI-запросов в день и открыт полный доступ ко всем функциям платформы."
        ];
    }
}
