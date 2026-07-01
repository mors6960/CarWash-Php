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

    $startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-30 days'));
    $endDate   = isset($_GET['end_date'])   ? $_GET['end_date']   : date('Y-m-d');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) ||
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid date format. Use YYYY-MM-DD']);
        exit;
    }

    // Rippling employees as primary source — hours from rippling_time_entries
    $stmt = $pdo->prepare("
        SELECT
            r.rippling_id,
            r.first_name,
            r.last_name,
            r.is_active                                             AS active,
            r.role_name                                             AS role,
            r.department,
            r.hourly_rate                                           AS pay_rate,
            COALESCE(SUM(t.hours_worked), 0)                       AS regular_hours,
            COALESCE(SUM(t.overtime_hours), 0)                     AS overtime_hours,
            COALESCE(SUM(t.hours_worked + COALESCE(t.overtime_hours, 0)), 0) AS total_hours,
            COALESCE(
                SUM((t.hours_worked * COALESCE(r.hourly_rate, 0))
                    + (COALESCE(t.overtime_hours, 0) * COALESCE(r.hourly_rate, 0) * 1.5)),
                0
            )                                                       AS total_labor_cost
        FROM employees_rippling r
        LEFT JOIN rippling_time_entries t
            ON r.rippling_id = t.rippling_id
            AND t.date BETWEEN :start AND :end
        WHERE r.is_active = 1
        GROUP BY r.rippling_id, r.first_name, r.last_name, r.is_active,
                 r.role_name, r.department, r.hourly_rate
        ORDER BY total_hours DESC
    ");

    $stmt->execute([':start' => $startDate, ':end' => $endDate]);
    $rows = $stmt->fetchAll();

    // Summary stats
    $totalHours     = array_sum(array_column($rows, 'total_hours'));
    $totalLaborCost = array_sum(array_column($rows, 'total_labor_cost'));

    echo json_encode([
        'status'           => 'success',
        'data'             => $rows,
        'total'            => count($rows),
        'total_hours'      => round((float) $totalHours, 2),
        'total_labor_cost' => round((float) $totalLaborCost, 2),
        'start_date'       => $startDate,
        'end_date'         => $endDate,
        'last_updated'     => date('Y-m-d H:i:s'),
    ]);

} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Internal server error']);
}
