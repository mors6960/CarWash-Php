<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

requireApiKey();

try {
    $pdo = getDB();

    $startDate = $_GET['start_date'] ?? null;
    $endDate   = $_GET['end_date']   ?? null;

    // Validate dates if provided
    if ($startDate !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid start_date format. Use YYYY-MM-DD']);
        exit;
    }
    if ($endDate !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid end_date format. Use YYYY-MM-DD']);
        exit;
    }

    // Build query — date filtering is optional
    if ($startDate !== null && $endDate !== null) {
        $stmt = $pdo->prepare("
            SELECT date, category, amount
            FROM expenses
            WHERE date BETWEEN :start AND :end
            ORDER BY date ASC, category ASC
        ");
        $stmt->execute([':start' => $startDate, ':end' => $endDate]);
    } elseif ($startDate !== null) {
        $stmt = $pdo->prepare("
            SELECT date, category, amount
            FROM expenses
            WHERE date >= :start
            ORDER BY date ASC, category ASC
        ");
        $stmt->execute([':start' => $startDate]);
    } else {
        $stmt = $pdo->query("
            SELECT date, category, amount
            FROM expenses
            ORDER BY date ASC, category ASC
        ");
    }

    $rows = $stmt->fetchAll();

    // Cast numeric fields
    $rows = array_map(function (array $row): array {
        return [
            'date'     => $row['date'],
            'category' => $row['category'],
            'amount'   => (float) $row['amount'],
        ];
    }, $rows);

    $total = array_sum(array_column($rows, 'amount'));

    echo json_encode([
        'status'       => 'success',
        'data'         => $rows,
        'total'        => count($rows),
        'total_amount' => round($total, 2),
        'last_updated' => date('Y-m-d H:i:s'),
    ]);

} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Internal server error']);
}
