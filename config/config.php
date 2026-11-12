<?php
declare(strict_types=1);

/**
 * AI Business Assistant - Configuration Loader
 * Loads parameters from .env without exposing credentials in code
 */

if (!function_exists('loadEnv')) {
    function loadEnv(string $path): void {
        if (!file_exists($path)) {
            return;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }
            if (strpos($line, '=') !== false) {
                list($name, $value) = explode('=', $line, 2);
                $name = trim($name);
                $value = trim($value);
                if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
                    putenv(sprintf('%s=%s', $name, $value));
                    $_ENV[$name] = $value;
                    $_SERVER[$name] = $value;
                }
            }
        }
    }
}

// Load .env from project root
$envPath = dirname(__DIR__) . '/.env';
loadEnv($envPath);

return [
    'db' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => getenv('DB_PORT') ?: '3306',
        'name' => getenv('DB_NAME') ?: 'ai_business_assistant',
        'user' => getenv('DB_USER') ?: 'root',
        'pass' => getenv('DB_PASS') !== false ? getenv('DB_PASS') : '',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'name' => 'AI Business Assistant',
        'version' => '1.0.0',
        'secret' => getenv('APP_SECRET') ?: 'default_secret_key_change_in_production',
        'base_url' => getenv('APP_URL') ?: '',
        'upload_dir' => dirname(__DIR__) . '/uploads/',
        'max_upload_size' => 10 * 1024 * 1024, // 10MB
        'allowed_types' => [
            'text/plain' => 'txt',
            'application/pdf' => 'pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        ],
    ]
];
