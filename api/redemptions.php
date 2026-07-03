<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/sonnys.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

requireApiKey();

// ── Investigation result ──────────────────────────────────────────────────────
// Sonny's BackOffice API (trigonapi.sonnyscontrols.com/v1) does NOT expose
// a dedicated redemptions, loyalty, rewards, reviews, or ratings endpoint.
//
// Checked endpoints available under /v1:
//   transaction, transaction/{id}, recurring/account/list,
//   recurring/account/status-list, employee, employee/{id}/clock-entries,
//   customer, item, site/list
//
// Prepaid + recurring redemptions are flagged on individual transactions via:
//   isRecurringRedemption (bool)  → stored in transactions.is_recurring_redemption
//   isPrepaidRedemption   (bool)  → stored in transactions.is_prepaid_redemption
//
// Ratings / reviews: not available in Sonny's BackOffice API v1.
//
// ACTION: If redemption history is needed, query the transactions table
// filtered by is_recurring_redemption = 1 or is_prepaid_redemption = 1.
// See the /api/transactions.php endpoint for this data.
// ─────────────────────────────────────────────────────────────────────────────

try {
    require_once __DIR__ . '/../config/db.php';
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

    // Return redemption transactions (recurring + prepaid) as best available proxy
    $stmt = $pdo->prepare("
        SELECT
            t.customer_id,
            COALESCE(
                t.customer_name,
                NULLIF(TRIM(CONCAT_WS(' ', c.first_name, c.last_name)), '')
            )                   AS customer_name,
            DATE(t.complete_date) AS redemption_date,
            ti.item_name,
            NULL                AS rating
        FROM transactions t
        LEFT JOIN customers c ON t.customer_id = c.customer_id
        LEFT JOIN transaction_items ti ON t.trans_id = ti.trans_id
        WHERE t.complete_date BETWEEN :start AND :end
          AND (t.is_recurring_redemption = 1 OR t.is_prepaid_redemption = 1)
        ORDER BY t.complete_date DESC
    ");
    $stmt->execute([':start' => $start, ':end' => $end]);
    $rows = $stmt->fetchAll();

    $rows = array_map(function (array $row): array {
        return [
            'customer_id'     => $row['customer_id'],
            'customer_name'   => $row['customer_name'],
            'redemption_date' => $row['redemption_date'],
            'item_name'       => $row['item_name'],
            'rating'          => null, // Not available from Sonny's API
        ];
    }, $rows);

    echo json_encode([
        'status'       => 'success',
        'note'         => 'Ratings unavailable — Sonny\'s API v1 does not expose ratings or loyalty data. Redemption rows sourced from is_recurring_redemption / is_prepaid_redemption flags on transactions.',
        'data'         => $rows,
        'total'        => count($rows),
        'last_updated' => date('Y-m-d H:i:s'),
    ]);

} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Internal server error']);
}
