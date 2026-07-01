<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/sonnys.php';


$client  = getSonnysClient();
$pdo     = getDB();
$fetched = 0;
$seen    = [];

echo "[fetch_memberships] Starting (weekly chunked)\n";

$stmt = $pdo->prepare("
    INSERT INTO recurring_accounts
        (account_id, plan_name, customer_id, billing_site_code, billing_site_id,
         status, status_name, signup_date, cancel_date, fetched_at)
    VALUES
        (:account_id, :plan_name, :customer_id, :billing_site_code, :billing_site_id,
         :status, :status_name, :signup_date, :cancel_date, NOW())
    ON DUPLICATE KEY UPDATE
        status      = VALUES(status),
        status_name = VALUES(status_name),
        cancel_date = VALUES(cancel_date),
        fetched_at  = NOW()
");

// Business is ~2 months old — loop weekly from 3 months ago to today
$endTs     = time();
$startTs   = strtotime('-3 months');
$weekSecs  = 7 * 24 * 3600;

$chunkStart = $startTs;

while ($chunkStart < $endTs) {
    $chunkEnd = min($chunkStart + $weekSecs, $endTs);

    try {
        sonnysThrottle();

        $response = $client->get('recurring/account/list', [
            'query' => [
                'startDate' => $chunkStart,
                'endDate'   => $chunkEnd,
                'limit'     => 100,
            ],
        ]);

        $body  = json_decode((string) $response->getBody(), true);
        $rows  = $body['data']['accounts'] ?? [];
        $total = $body['data']['total']    ?? 0;

        $chunkNew = 0;
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            if ($id === null || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            $stmt->execute([
                ':account_id'        => $id,
                ':plan_name'         => $row['name']            ?? null,
                ':customer_id'       => $row['customerId']      ?? null,
                ':billing_site_code' => $row['billingSiteCode'] ?? 'STOCK',
                ':billing_site_id'   => $row['billingSiteId']   ?? 1,
                ':status'            => $row['status']          ?? null,
                ':status_name'       => $row['statusName']      ?? null,
                ':signup_date'       => $row['signUpDate']      ?? null,
                ':cancel_date'       => !empty($row['cancelDate']) ? $row['cancelDate'] : null,
            ]);
            $fetched++;
            $chunkNew++;
        }

        $fromDate = date('Y-m-d', $chunkStart);
        $toDate   = date('Y-m-d', $chunkEnd);
        echo "[fetch_memberships] {$fromDate} → {$toDate}: {$chunkNew} new ({$total} in window) | total stored: {$fetched}\n";

    } catch (\GuzzleHttp\Exception\ClientException $e) {
        echo "[fetch_memberships] ERROR " . $e->getResponse()->getStatusCode() . ": "
            . (string) $e->getResponse()->getBody() . "\n";
    } catch (\Exception $e) {
        echo "[fetch_memberships] ERROR: " . $e->getMessage() . "\n";
    }

    $chunkStart = $chunkEnd + 1;
}

echo "[fetch_memberships] Done — {$fetched} unique accounts stored\n";
