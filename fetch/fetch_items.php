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
$page    = 1;

echo "[fetch_items] Starting\n";

$stmt = $pdo->prepare("
    INSERT INTO items (sku, name, department_name, price_at_site, cost_per_item, is_prompt_for_price, site_location, fetched_at)
    VALUES (:sku, :name, :department_name, :price_at_site, :cost_per_item, :is_prompt_for_price, :site_location, NOW())
    ON DUPLICATE KEY UPDATE
        name               = VALUES(name),
        department_name    = VALUES(department_name),
        price_at_site      = VALUES(price_at_site),
        cost_per_item      = VALUES(cost_per_item),
        site_location      = VALUES(site_location),
        fetched_at         = NOW()
");

while (true) {
    try {
        sonnysThrottle();

        $response = $client->get('item', ['query' => ['limit' => 100, 'offset' => $page]]);
        $body     = json_decode((string) $response->getBody(), true);
        $rows     = $body['data']['items'] ?? [];
        $total    = $body['data']['total'] ?? 0;

        if (empty($rows)) {
            break;
        }

        foreach ($rows as $item) {
            if (empty($item['sku'])) {
                continue;
            }
            $price = isset($item['priceAtSite']) ? (float) str_replace(',', '', $item['priceAtSite']) : null;
            $cost  = isset($item['costPerItem'])  ? (float) str_replace(',', '', $item['costPerItem'])  : null;

            $stmt->execute([
                ':sku'               => $item['sku'],
                ':name'              => $item['name']              ?? null,
                ':department_name'   => $item['departmentName']    ?? null,
                ':price_at_site'     => $price,
                ':cost_per_item'     => $cost,
                ':is_prompt_for_price' => isset($item['isPromptForPrice']) ? (int) $item['isPromptForPrice'] : 0,
                ':site_location'     => $item['siteLocation']      ?? null,
            ]);
            $fetched++;
        }

        echo "[fetch_items] Page {$page}: " . count($rows) . " rows | stored: {$fetched}/{$total}\n";

        if (count($rows) < 100) {
            break;
        }

        $page++;

    } catch (\GuzzleHttp\Exception\ClientException $e) {
        echo "[fetch_items] ERROR " . $e->getResponse()->getStatusCode() . ": "
            . (string) $e->getResponse()->getBody() . "\n";
        break;
    } catch (\Exception $e) {
        echo "[fetch_items] ERROR: " . $e->getMessage() . "\n";
        break;
    }
}

echo "[fetch_items] Done — {$fetched} items stored\n";
