<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/sonnys.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$client = getSonnysClient();
$pdo    = getDB();

// Fetch items+tenders for transactions that don't have detail yet
// Scope to the last 24 hours to keep runtime fast (cron runs every 6h)
$since = date('Y-m-d H:i:s', strtotime('-24 hours'));

$transIds = $pdo->prepare("
    SELECT t.trans_id
    FROM transactions t
    LEFT JOIN transaction_items ti ON t.trans_id = ti.trans_id
    WHERE t.complete_date >= ?
      AND ti.id IS NULL
    ORDER BY t.complete_date DESC
");
$transIds->execute([$since]);
$ids = $transIds->fetchAll(\PDO::FETCH_COLUMN);

if (empty($ids)) {
    echo "[fetch_transaction_items] No new transactions to detail — done\n";
    exit(0);
}

echo "[fetch_transaction_items] Fetching detail for " . count($ids) . " transactions\n";

$itemStmt = $pdo->prepare("
    INSERT IGNORE INTO transaction_items
        (trans_id, item_name, sku, department, quantity, gross, net, discount, tax, additional_fee, is_voided)
    VALUES
        (:trans_id, :item_name, :sku, :department, :quantity, :gross, :net, :discount, :tax, :additional_fee, :is_voided)
");

$tenderStmt = $pdo->prepare("
    INSERT IGNORE INTO transaction_tenders
        (trans_id, tender, tender_sub_type, amount, change_amount, total, reference_number, cc_last_four)
    VALUES
        (:trans_id, :tender, :tender_sub_type, :amount, :change_amount, :total, :reference_number, :cc_last_four)
");

$fetched = 0;
$errors  = 0;

foreach ($ids as $transId) {
    try {
        sonnysThrottle();

        $response = $client->get('transaction/' . rawurlencode($transId));
        $tx       = json_decode((string) $response->getBody(), true)['data'] ?? [];

        // Enrich the transaction record with detail fields
        $pdo->prepare("
            UPDATE transactions SET
                type          = :type,
                customer_name = :customer_name,
                cashier_name  = :cashier_name,
                greeter_name  = :greeter_name,
                vehicle_plate = :vehicle_plate,
                is_recurring_payment    = :is_recurring_payment,
                is_recurring_redemption = :is_recurring_redemption,
                is_recurring_sale       = :is_recurring_sale,
                is_prepaid_redemption   = :is_prepaid_redemption,
                is_prepaid_sale         = :is_prepaid_sale
            WHERE trans_id = :trans_id
        ")->execute([
            ':trans_id'               => $transId,
            ':type'                   => $tx['type']                    ?? null,
            ':customer_name'          => $tx['customerName']            ?? null,
            ':cashier_name'           => $tx['employeeCashier']         ?? null,
            ':greeter_name'           => $tx['employeeGreeter']         ?? null,
            ':vehicle_plate'          => $tx['vehicleLicensePlate']     ?? null,
            ':is_recurring_payment'   => !empty($tx['isRecurringPayment'])   ? 1 : 0,
            ':is_recurring_redemption'=> !empty($tx['isRecurringRedemption'])? 1 : 0,
            ':is_recurring_sale'      => !empty($tx['isRecurringSale'])      ? 1 : 0,
            ':is_prepaid_redemption'  => !empty($tx['isPrepaidRedemption'])  ? 1 : 0,
            ':is_prepaid_sale'        => !empty($tx['isPrepaidSale'])        ? 1 : 0,
        ]);

        foreach ($tx['items'] ?? [] as $item) {
            $itemStmt->execute([
                ':trans_id'       => $transId,
                ':item_name'      => $item['name']          ?? null,
                ':sku'            => $item['sku']           ?? null,
                ':department'     => $item['department']    ?? null,
                ':quantity'       => $item['quantity']      ?? 1,
                ':gross'          => $item['gross']         ?? null,
                ':net'            => $item['net']           ?? null,
                ':discount'       => $item['discount']      ?? 0,
                ':tax'            => $item['tax']           ?? 0,
                ':additional_fee' => $item['additionalFee'] ?? 0,
                ':is_voided'      => !empty($item['isVoided']) ? 1 : 0,
            ]);
        }

        foreach ($tx['tenders'] ?? [] as $tender) {
            $tenderStmt->execute([
                ':trans_id'        => $transId,
                ':tender'          => $tender['tender']              ?? null,
                ':tender_sub_type' => $tender['tenderSubType']       ?? null,
                ':amount'          => $tender['amount']              ?? null,
                ':change_amount'   => $tender['change']              ?? 0,
                ':total'           => $tender['total']               ?? null,
                ':reference_number'=> $tender['referenceNumber']     ?? null,
                ':cc_last_four'    => $tender['creditCardLastFour']  ?? null,
            ]);
        }

        $fetched++;

    } catch (\GuzzleHttp\Exception\ClientException $e) {
        echo "[fetch_transaction_items] SKIP {$transId}: " . $e->getResponse()->getStatusCode() . "\n";
        $errors++;
    } catch (\Exception $e) {
        echo "[fetch_transaction_items] ERROR {$transId}: " . $e->getMessage() . "\n";
        $errors++;
    }
}

echo "[fetch_transaction_items] Done — {$fetched} transactions detailed, {$errors} errors\n";
