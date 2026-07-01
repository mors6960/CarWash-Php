<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

requireApiKey();

try {
    $pdo = getDB();

    $stmt = $pdo->query("
        SELECT *
        FROM weather_data
        ORDER BY fetched_at DESC
        LIMIT 24
    ");
    $rows = $stmt->fetchAll();

    echo json_encode([
        'status'       => 'success',
        'data'         => $rows,
        'total'        => count($rows),
        'last_updated' => date('Y-m-d H:i:s'),
    ]);

} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Internal server error']);
}
