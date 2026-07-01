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

    // ── Raw transactions ─────────────────────────────────────────────────────
    $txStmt = $pdo->prepare("
        SELECT
            t.trans_id,
            t.trans_number,
            t.site_code,
            t.total,
            t.type,
            t.complete_date,
            t.customer_id,
            t.customer_name,
            t.cashier_name,
            t.is_recurring_payment,
            t.is_recurring_sale
        FROM transactions t
        WHERE t.complete_date BETWEEN :start AND :end
        ORDER BY t.complete_date DESC
    ");
    $txStmt->execute([':start' => $start, ':end' => $end]);
    $transactions = $txStmt->fetchAll();

    $totalRevenue    = array_sum(array_column($transactions, 'total'));
    $membershipSales = array_filter($transactions, fn($r) => $r['is_recurring_sale']);

    // ── Daily revenue ────────────────────────────────────────────────────────
    $dailyStmt = $pdo->prepare("
        SELECT
            DATE(complete_date)   AS sale_date,
            COUNT(*)              AS transaction_count,
            SUM(total)            AS daily_revenue,
            SUM(is_recurring_sale) AS membership_sales
        FROM transactions
        WHERE complete_date BETWEEN :start AND :end
        GROUP BY DATE(complete_date)
        ORDER BY sale_date ASC
    ");
    $dailyStmt->execute([':start' => $start, ':end' => $end]);
    $dailyRevenue = $dailyStmt->fetchAll();

    // ── Hourly revenue (for today / single-day view) ─────────────────────────
    $hourlyStmt = $pdo->prepare("
        SELECT
            HOUR(complete_date)   AS hour_of_day,
            COUNT(*)              AS transaction_count,
            SUM(total)            AS hourly_revenue
        FROM transactions
        WHERE complete_date BETWEEN :start AND :end
        GROUP BY HOUR(complete_date)
        ORDER BY hour_of_day ASC
    ");
    $hourlyStmt->execute([':start' => $start, ':end' => $end]);
    $hourlyRevenue = $hourlyStmt->fetchAll();

    // ── Revenue by day of week ───────────────────────────────────────────────
    $dowStmt = $pdo->prepare("
        SELECT
            DAYOFWEEK(complete_date)                                AS dow_num,
            DAYNAME(complete_date)                                  AS day_name,
            COUNT(*)                                                AS transaction_count,
            ROUND(AVG(total), 2)                                    AS avg_ticket,
            SUM(total)                                              AS total_revenue
        FROM transactions
        WHERE complete_date BETWEEN :start AND :end
        GROUP BY DAYOFWEEK(complete_date), DAYNAME(complete_date)
        ORDER BY dow_num ASC
    ");
    $dowStmt->execute([':start' => $start, ':end' => $end]);
    $dayOfWeek = $dowStmt->fetchAll();

    // ── Item / department breakdown ──────────────────────────────────────────
    $itemStmt = $pdo->prepare("
        SELECT
            ti.department,
            ti.item_name,
            SUM(ti.quantity)    AS units_sold,
            SUM(ti.gross)       AS gross_revenue,
            SUM(ti.net)         AS net_revenue,
            SUM(ti.discount)    AS total_discount
        FROM transaction_items ti
        JOIN transactions t ON ti.trans_id = t.trans_id
        WHERE t.complete_date BETWEEN :start AND :end
        GROUP BY ti.department, ti.item_name
        ORDER BY gross_revenue DESC
    ");
    $itemStmt->execute([':start' => $start, ':end' => $end]);
    $itemBreakdown = $itemStmt->fetchAll();

    $totalDiscount = array_sum(array_column($itemBreakdown, 'total_discount'));
    $totalGross    = array_sum(array_column($itemBreakdown, 'gross_revenue'));
    $totalNet      = array_sum(array_column($itemBreakdown, 'net_revenue'));

    // ── Tender / payment method breakdown ────────────────────────────────────
    $tenderStmt = $pdo->prepare("
        SELECT
            tt.tender,
            COUNT(DISTINCT tt.trans_id) AS transaction_count,
            SUM(tt.total)               AS tender_total
        FROM transaction_tenders tt
        JOIN transactions t ON tt.trans_id = t.trans_id
        WHERE t.complete_date BETWEEN :start AND :end
        GROUP BY tt.tender
        ORDER BY tender_total DESC
    ");
    $tenderStmt->execute([':start' => $start, ':end' => $end]);
    $tenderBreakdown = $tenderStmt->fetchAll();

    echo json_encode([
        'status'             => 'success',
        'start_date'         => $startDate,
        'end_date'           => $endDate,
        'last_updated'       => date('Y-m-d H:i:s'),
        'summary' => [
            'total_transactions'  => count($transactions),
            'total_revenue'       => round((float) $totalRevenue, 2),
            'total_gross'         => round((float) $totalGross, 2),
            'total_net'           => round((float) $totalNet, 2),
            'total_discount'      => round((float) $totalDiscount, 2),
            'membership_sales'    => count($membershipSales),
            'avg_ticket'          => count($transactions) > 0
                                     ? round((float) $totalRevenue / count($transactions), 2) : 0,
        ],
        'transactions'       => $transactions,
        'daily_revenue'      => $dailyRevenue,
        'hourly_revenue'     => $hourlyRevenue,
        'day_of_week'        => $dayOfWeek,
        'item_breakdown'     => $itemBreakdown,
        'tender_breakdown'   => $tenderBreakdown,
    ]);

} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Internal server error']);
}
