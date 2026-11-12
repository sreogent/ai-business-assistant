# -*- coding: utf-8 -*-
with open(r"C:\Users\matve\.gemini\antigravity\scratch\ai-business-assistant\includes\Auth.php", "r", encoding="utf-8") as f:
    code = f.read()

target = """            // ── 2FA Trust: пропускаем 2FA если устройство проверено в последние 2 часа ──
            $trustCookie = $_COOKIE['2fa_trust'] ?? '';
            if (!empty($trustCookie) && self::check2FATrust($pdo, (int)$user['id'], $trustCookie)) {
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_regenerate_id(true);
                }
                $_SESSION['user_id']    = (int)$user['id'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_role']  = $user['role_name'];
                $_SESSION['user_name']  = $user['first_name'] . ' ' . $user['last_name'];

                Security::log($pdo, 'login_success_2fa_trusted', "Вход без повторного 2FA (доверенное устройство < 2ч)", (int)$user['id']);
                return ['success' => true, 'user' => $user];
            }"""

replacement = """            // ── 2FA Trust: пропускаем 2FA если устройство проверено в последние 2 часа ──
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
            }"""

target_methods = """    /**
     * Проверяет cookie доверенного устройства для 2FA (< 2 часов)
     */
    public static function check2FATrust(\\PDO $pdo, int $userId, string $rawToken): bool {
        try {
            $hash = hash('sha256', $rawToken);
            $stmt = $pdo->prepare("
                SELECT id FROM users
                WHERE id = ?
                  AND two_fa_trusted_hash = ?
                  AND two_fa_trusted_expires > NOW()
                LIMIT 1
            ");
            $stmt->execute([$userId, $hash]);
            return (bool)$stmt->fetch();
        } catch (\\Throwable $e) {
            return false;
        }
    }

    /**
     * Устанавливает cookie доверенного устройства на 2 часа после успешного 2FA
     */
    public static function set2FATrustCookie(\\PDO $pdo, int $userId): void {
        try {
            $token   = bin2hex(random_bytes(32));
            $hash    = hash('sha256', $token);
            $expires = date('Y-m-d H:i:s', time() + 7200); // 2 часа

            $pdo->prepare("
                UPDATE users
                SET two_fa_trusted_hash = ?, two_fa_trusted_expires = ?
                WHERE id = ?
            ")->execute([$hash, $expires, $userId]);

            setcookie('2fa_trust', $token, [
                'expires'  => time() + 7200,
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
        } catch (\\Throwable $e) {
            // Не критично — 2FA просто будет запрашиваться при каждом входе
        }
    }"""

replacement_methods = """    /**
     * Проверяет кулдаун 2FA (< 2 часов) по сессии или доверенному cookie + БД
     */
    public static function check2FATrust(\\PDO $pdo, int $userId, string $rawToken): bool {
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
        } catch (\\Throwable $e) {
            return false;
        }
    }

    /**
     * Устанавливает кулдаун 2FA на 2 часа после успешного ввода кода
     */
    public static function set2FATrustCookie(\\PDO $pdo, int $userId): void {
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
        } catch (\\Throwable $e) {
            $_SESSION['2fa_trusted_until'] = time() + 7200;
            $_SESSION['2fa_trusted_user_id'] = $userId;
        }
    }"""

code_norm = code.replace("\r\n", "\n")
t1_norm = target.replace("\r\n", "\n")
r1_norm = replacement.replace("\r\n", "\n")
t2_norm = target_methods.replace("\r\n", "\n")
r2_norm = replacement_methods.replace("\r\n", "\n")

if t1_norm in code_norm:
    code_norm = code_norm.replace(t1_norm, r1_norm)
    print("attempt replaced!")
else:
    print("t1_norm not found")

if t2_norm in code_norm:
    code_norm = code_norm.replace(t2_norm, r2_norm)
    print("methods replaced!")
else:
    print("t2_norm not found")

with open(r"C:\Users\matve\.gemini\antigravity\scratch\ai-business-assistant\includes\Auth.php", "w", encoding="utf-8") as f:
    f.write(code_norm)
