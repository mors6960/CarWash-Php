<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}
foreach (getenv() ?: [] as $k => $v) { if (empty($_ENV[$k])) $_ENV[$k] = $v; }

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

echo json_encode([
    'status'  => 'ok',
    'service' => 'CarWash BI API',
    'env'     => $_ENV['APP_ENV'] ?? 'unknown',
]);
