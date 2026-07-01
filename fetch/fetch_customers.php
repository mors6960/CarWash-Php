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

echo "[fetch_customers] Starting\n";

$stmt = $pdo->prepare("
    INSERT INTO customers
        (customer_id, customer_number, first_name, last_name,
         phone, email, city, state, postal_code,
         is_active, allow_sms, birth_date, modify_date, fetched_at)
    VALUES
        (:customer_id, :customer_number, :first_name, :last_name,
         :phone, :email, :city, :state, :postal_code,
         :is_active, :allow_sms, :birth_date, :modify_date, NOW())
    ON DUPLICATE KEY UPDATE
        first_name   = VALUES(first_name),
        last_name    = VALUES(last_name),
        phone        = VALUES(phone),
        email        = VALUES(email),
        city         = VALUES(city),
        state        = VALUES(state),
        postal_code  = VALUES(postal_code),
        is_active    = VALUES(is_active),
        modify_date  = VALUES(modify_date),
        fetched_at   = NOW()
");

$page    = 1; // API uses 1-based page numbers as the "offset" param
$fetched = 0;

while (true) {
    try {
        sonnysThrottle();

        $response = $client->get('customer', ['query' => ['limit' => 100, 'offset' => $page]]);
        $body     = json_decode((string) $response->getBody(), true);
        $rows     = $body['data']['customers'] ?? [];
        $total    = $body['data']['total']     ?? 0;

        if (empty($rows)) {
            break;
        }

        foreach ($rows as $row) {
            $customerId = $row['customerId'] ?? null;
            if ($customerId === null) {
                continue;
            }

            $stmt->execute([
                ':customer_id'     => $customerId,
                ':customer_number' => $row['customerNumber'] ?? null,
                ':first_name'      => $row['firstName']      ?? null,
                ':last_name'       => $row['lastName']       ?? null,
                ':phone'           => $row['phoneNumber']    ?? $row['phone'] ?? null,
                ':email'           => $row['email']          ?? null,
                ':city'            => $row['city']           ?? null,
                ':state'           => $row['state']          ?? null,
                ':postal_code'     => $row['postalCode']     ?? null,
                ':is_active'       => isset($row['isActive']) ? (int) $row['isActive'] : 1,
                ':allow_sms'       => isset($row['allowSms']) ? (int) $row['allowSms'] : 0,
                ':birth_date'      => !empty($row['birthDate'])
                                       ? date('Y-m-d', strtotime($row['birthDate'])) : null,
                ':modify_date'     => !empty($row['modifiedDate'])
                                       ? date('Y-m-d H:i:s', strtotime($row['modifiedDate'])) : null,
            ]);
            $fetched++;
        }

        echo "[fetch_customers] Page {$page}: " . count($rows) . " rows | stored: {$fetched}/{$total}\n";

        if (count($rows) < 100) {
            break; // Last page
        }

        $page++;

    } catch (\GuzzleHttp\Exception\ClientException $e) {
        echo "[fetch_customers] ERROR " . $e->getResponse()->getStatusCode() . ": "
            . (string) $e->getResponse()->getBody() . "\n";
        break;
    } catch (\Exception $e) {
        echo "[fetch_customers] ERROR: " . $e->getMessage() . "\n";
        break;
    }
}

echo "[fetch_customers] Done — {$fetched} customers stored\n";
