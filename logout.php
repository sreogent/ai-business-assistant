<?php
declare(strict_types=1);

/**
 * AI Business Assistant - Logout
 */

require_once __DIR__ . '/includes/Auth.php';

Auth::logout();
header("Location: /login.php");
exit;
