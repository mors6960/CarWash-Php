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

    $start = $startDate . ' 00:00:00';
    $end   = $endDate   . ' 23:59:59';

    // ── All memberships (for Power BI filtering) ─────────────────────────────
    // city + zip joined from customers table (Sonny's fields: city, postal_code).
    // Defaults to 'Stockton' / null when customer address is not on file.
    $allStmt = $pdo->query("
        SELECT
            ra.account_id,
            ra.plan_name,
            ra.customer_id,
            ra.billing_site_code,
            ra.status,
            ra.status_name,
            ra.signup_date,
            ra.cancel_date,
            ra.next_bill_date,
            ra.billing_amount,
            COALESCE(NULLIF(c.city, ''), 'Stockton') AS city,
            NULLIF(c.postal_code, '')                AS zip
        FROM recurring_accounts ra
        LEFT JOIN customers c ON ra.customer_id = c.customer_id
        ORDER BY ra.signup_date DESC
    ");
    $all = $allStmt->fetchAll();

    // ── Summary counts ────────────────────────────────────────────────────────
    $active    = array_filter($all, fn($r) => strtoupper((string) $r['status_name']) === 'ACTIVE');
    $cancelled = array_filter($all, fn($r) => strtoupper((string) $r['status_name']) === 'CANCELLED');
    $suspended = array_filter($all, fn($r) => strtoupper((string) $r['status_name']) === 'SUSPENDED');

    // ── Plan breakdown ────────────────────────────────────────────────────────
    $plans = [];
    foreach ($all as $r) {
        $plan = $r['plan_name'] ?? 'Unknown';
        if (!isset($plans[$plan])) {
            $plans[$plan] = ['plan_name' => $plan, 'total' => 0, 'active' => 0, 'cancelled' => 0];
        }
        $plans[$plan]['total']++;
        $status = strtoupper((string) $r['status_name']);
        if ($status === 'ACTIVE')    $plans[$plan]['active']++;
        if ($status === 'CANCELLED') $plans[$plan]['cancelled']++;
    }

    // ── New signups in date range ─────────────────────────────────────────────
    $newStmt = $pdo->prepare("
        SELECT
            DATE(signup_date)  AS signup_day,
            plan_name,
            COUNT(*)           AS new_signups
        FROM recurring_accounts
        WHERE signup_date BETWEEN :start AND :end
        GROUP BY DATE(signup_date), plan_name
        ORDER BY signup_day ASC
    ");
    $newStmt->execute([':start' => $start, ':end' => $end]);
    $dailySignups = $newStmt->fetchAll();

    $newInRange = array_sum(array_column($dailySignups, 'new_signups'));

    // ── Cancellations in date range ───────────────────────────────────────────
    $cancelStmt = $pdo->prepare("
        SELECT
            DATE(cancel_date) AS cancel_day,
            plan_name,
            COUNT(*)          AS cancellations
        FROM recurring_accounts
        WHERE cancel_date BETWEEN :start AND :end
          AND cancel_date IS NOT NULL
        GROUP BY DATE(cancel_date), plan_name
        ORDER BY cancel_day ASC
    ");
    $cancelStmt->execute([':start' => $start, ':end' => $end]);
    $dailyCancels = $cancelStmt->fetchAll();

    $cancelsInRange = array_sum(array_column($dailyCancels, 'cancellations'));

    // ── Monthly trend ─────────────────────────────────────────────────────────
    $trendStmt = $pdo->prepare("
        SELECT
            DATE_FORMAT(signup_date, '%Y-%m') AS month,
            COUNT(*)                          AS new_signups,
            SUM(CASE WHEN status_name = 'CANCELLED' THEN 1 ELSE 0 END) AS cancellations,
            COUNT(*) - SUM(CASE WHEN status_name = 'CANCELLED' THEN 1 ELSE 0 END) AS net_growth
        FROM recurring_accounts
        WHERE signup_date BETWEEN :start AND :end
        GROUP BY DATE_FORMAT(signup_date, '%Y-%m')
        ORDER BY month ASC
    ");
    $trendStmt->execute([':start' => $start, ':end' => $end]);
    $monthlyTrend = $trendStmt->fetchAll();

    echo json_encode([
        'status'          => 'success',
        'start_date'      => $startDate,
        'end_date'        => $endDate,
        'last_updated'    => date('Y-m-d H:i:s'),
        'summary' => [
            'total'            => count($all),
            'active'           => count($active),
            'cancelled'        => count($cancelled),
            'suspended'        => count($suspended),
            'new_in_range'     => $newInRange,
            'cancelled_in_range' => $cancelsInRange,
            'net_growth'       => $newInRange - $cancelsInRange,
        ],
        'all_memberships'  => $all,
        'plan_breakdown'   => array_values($plans),
        'daily_signups'    => $dailySignups,
        'daily_cancels'    => $dailyCancels,
        'monthly_trend'    => $monthlyTrend,
    ]);

} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Internal server error']);
}
