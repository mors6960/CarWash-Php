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

    $startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
    $endDate   = $_GET['end_date']   ?? date('Y-m-d');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) ||
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid date format. Use YYYY-MM-DD']);
        exit;
    }

    // One row per employee per day — enables daily/weekly/monthly labor slicing in Power BI.
    // regular_hours * pay_rate + overtime_hours * pay_rate * 1.5 = total_labor_cost
    $stmt = $pdo->prepare("
        SELECT
            t.date                                                          AS work_date,
            r.first_name,
            r.role_name                                                     AS role,
            r.department,
            COALESCE(r.hourly_rate, 0)                                      AS pay_rate,
            COALESCE(t.hours_worked, 0)                                     AS regular_hours,
            COALESCE(t.overtime_hours, 0)                                   AS overtime_hours,
            COALESCE(t.hours_worked, 0) + COALESCE(t.overtime_hours, 0)    AS total_hours,
            ROUND(
                (COALESCE(t.hours_worked, 0) * COALESCE(r.hourly_rate, 0))
                + (COALESCE(t.overtime_hours, 0) * COALESCE(r.hourly_rate, 0) * 1.5),
                2
            )                                                               AS total_labor_cost
        FROM rippling_time_entries t
        JOIN employees_rippling r ON r.rippling_id = t.rippling_id
        WHERE t.date BETWEEN :start AND :end
          AND r.is_active = 1
        ORDER BY t.date ASC, r.first_name ASC
    ");

    $stmt->execute([':start' => $startDate, ':end' => $endDate]);
    $rows = $stmt->fetchAll();

    // Cast numeric fields so Power BI receives numbers not strings
    $rows = array_map(function (array $row): array {
        return [
            'work_date'        => $row['work_date'],
            'first_name'       => $row['first_name'],
            'role'             => $row['role'],
            'department'       => $row['department'],
            'pay_rate'         => (float) $row['pay_rate'],
            'regular_hours'    => (float) $row['regular_hours'],
            'overtime_hours'   => (float) $row['overtime_hours'],
            'total_hours'      => (float) $row['total_hours'],
            'total_labor_cost' => (float) $row['total_labor_cost'],
        ];
    }, $rows);

    $totalHours     = array_sum(array_column($rows, 'total_hours'));
    $totalLaborCost = array_sum(array_column($rows, 'total_labor_cost'));

    echo json_encode([
        'status'           => 'success',
        'data'             => $rows,
        'total'            => count($rows),
        'total_hours'      => round($totalHours, 2),
        'total_labor_cost' => round($totalLaborCost, 2),
        'start_date'       => $startDate,
        'end_date'         => $endDate,
        'last_updated'     => date('Y-m-d H:i:s'),
    ]);

} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Internal server error']);
}
