<?php
declare(strict_types=1);

/**
 * AI Business Assistant - Security, Session, CSRF, XSS, Rate Limiting & Audit Log
 */

class Security {
    public static function startSession(): void {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            $cookieParams = [
                'lifetime' => 86400 * 7,
                'path' => '/',
                'domain' => '',
                'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
                'httponly' => true,
                'samesite' => 'Lax'
            ];
            session_set_cookie_params($cookieParams);
            @session_start();
        }
    }

    public static function csrfToken(): string {
        self::startSession();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function verifyCsrf(?string $token): bool {
        self::startSession();
        if (empty($_SESSION['csrf_token']) || empty($token)) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    public static function csrfField(): string {
        $token = self::csrfToken();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }

    public static function sanitize(mixed $data): mixed {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = self::sanitize($value);
            }
            return $data;
        }
        if (is_string($data)) {
            return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
        }
        return $data;
    }

    public static function getClientIp(): string {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            return $_SERVER['HTTP_CF_CONNECTING_IP'];
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($ips[0]);
        }
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    /**
     * Check brute-force login attempts
     * Max 5 attempts within 15 minutes per IP
     */
    public static function isRateLimited(PDO $pdo, string $ip, int $maxAttempts = 5, int $decaySeconds = 900): bool {
        $currentTime = time();
        $cutoff = $currentTime - $decaySeconds;

        // Cleanup old attempts occasionally
        if (mt_rand(1, 20) === 1) {
            $cleanStmt = $pdo->prepare("DELETE FROM login_attempts WHERE attempt_time < ?");
            $cleanStmt->execute([$cutoff]);
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND attempt_time >= ?");
        $stmt->execute([$ip, $cutoff]);
        $count = (int)$stmt->fetchColumn();

        return $count >= $maxAttempts;
    }

    public static function recordFailedAttempt(PDO $pdo, string $ip): void {
        $stmt = $pdo->prepare("INSERT INTO login_attempts (ip_address, attempt_time) VALUES (?, ?)");
        $stmt->execute([$ip, time()]);
    }

    public static function clearAttempts(PDO $pdo, string $ip): void {
        $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE ip_address = ?");
        $stmt->execute([$ip]);
    }

    /**
     * Audit log recording
     */
    public static function log(PDO $pdo, string $eventType, ?string $details = null, ?int $userId = null): void {
        try {
            $ip = self::getClientIp();
            $ua = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 255);
            $stmt = $pdo->prepare("INSERT INTO logs (user_id, event_type, ip_address, user_agent, details) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$userId, $eventType, $ip, $ua, $details]);
        } catch (Exception $e) {
            // Fail silently on logging error to prevent interrupting user actions
            error_log("Logging error: " . $e->getMessage());
        }
    }
}
