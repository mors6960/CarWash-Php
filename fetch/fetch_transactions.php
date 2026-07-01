<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/sonnys.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$client  = getSonnysClient();
$pdo     = getDB();
$fetched = 0;
$seen    = [];

// Pull last 30 days in daily chunks to avoid hitting the 100-row cap
$endTs     = time();
$startTs   = strtotime('-30 days');
$daySecs   = 86400;

echo "[fetch_transactions] Starting — " . date('Y-m-d', $startTs) . " to " . date('Y-m-d', $endTs) . " (daily chunks)\n";

$stmt = $pdo->prepare("
    INSERT INTO transactions
        (trans_id, trans_number, site_code, total, type, complete_date,
         customer_id, is_recurring_payment, is_recurring_sale, fetched_at)
    VALUES
        (:trans_id, :trans_number, :site_code, :total, :type, :complete_date,
         :customer_id, :is_recurring_payment, :is_recurring_sale, NOW())
    ON DUPLICATE KEY UPDATE
        total      = VALUES(total),
        type       = VALUES(type),
        fetched_at = NOW()
");

$chunkStart = $startTs;

while ($chunkStart < $endTs) {
    $chunkEnd  = min($chunkStart + $daySecs, $endTs);
    $chunkNew  = 0;
    $offset    = null;

    while (true) {
        try {
            sonnysThrottle();

            $query = [
                'startDate' => $chunkStart,
                'endDate'   => $chunkEnd,
                'limit'     => 100,
            ];
            if ($offset !== null) {
                $query['offset'] = $offset;
            }

            $response = $client->get('transaction', ['query' => $query]);
            $body     = json_decode((string) $response->getBody(), true);
            $rows     = $body['data']['transactions'] ?? [];
            $total    = $body['data']['total']        ?? 0;

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $id = $row['transId'] ?? null;
                if ($id === null || isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;

                $stmt->execute([
                    ':trans_id'             => $id,
                    ':trans_number'         => $row['transNumber']  ?? null,
                    ':site_code'            => 'STOCK',
                    ':total'                => $row['total']        ?? 0,
                    ':type'                 => 'Completed',
                    ':complete_date'        => isset($row['date'])
                                               ? date('Y-m-d H:i:s', strtotime($row['date']))
                                               : null,
                    ':customer_id'          => null,
                    ':is_recurring_payment' => 0,
                    ':is_recurring_sale'    => 0,
                ]);
                $fetched++;
                $chunkNew++;
            }

            if ($chunkNew >= $total || count($rows) < 100) {
                break;
            }

            $offset = ($offset ?? 0) + 100;
            if ($offset === 0) {
                $offset = 100;
            }

        } catch (\GuzzleHttp\Exception\ClientException $e) {
            echo "[fetch_transactions] ERROR " . $e->getResponse()->getStatusCode() . ": "
                . (string) $e->getResponse()->getBody() . "\n";
            break;
        } catch (\Exception $e) {
            echo "[fetch_transactions] ERROR: " . $e->getMessage() . "\n";
            break;
        }
    }

    echo "[fetch_transactions] " . date('Y-m-d', $chunkStart) . ": {$chunkNew} new | total: {$fetched}\n";
    $chunkStart = $chunkEnd + 1;
}

echo "[fetch_transactions] Done — {$fetched} total transactions stored\n";
