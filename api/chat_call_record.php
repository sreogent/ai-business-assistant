<?php
declare(strict_types=1);

/**
 * AI Business Assistant - Call Recording Endpoint
 * Call recording has been disabled per user request.
 */

header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    'success' => true,
    'message' => 'Запись звонков отключена администратором'
]);
