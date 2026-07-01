<?php

declare(strict_types=1);

// Allow CLI or internal web calls via router only
if (php_sapi_name() !== 'cli' && ($_SERVER['REQUEST_URI'] ?? '') !== '/cron/sync') {
    http_response_code(403);
    exit('Forbidden');
}

$start = microtime(true);
echo "[sync] Starting full sync — " . date('Y-m-d H:i:s') . "\n";

$fetchers = [
    'Sites'             => __DIR__ . '/../fetch/fetch_sites.php',
    'Items'             => __DIR__ . '/../fetch/fetch_items.php',
    'Transactions'      => __DIR__ . '/../fetch/fetch_transactions.php',
    'Transaction Items' => __DIR__ . '/../fetch/fetch_transaction_items.php',
    'Memberships'       => __DIR__ . '/../fetch/fetch_memberships.php',
    'Employees'         => __DIR__ . '/../fetch/fetch_employees.php',
    'Customers'         => __DIR__ . '/../fetch/fetch_customers.php',
    'Weather'           => __DIR__ . '/../fetch/fetch_weather.php',
    'Rippling'          => __DIR__ . '/../fetch/fetch_rippling.php',
];

foreach ($fetchers as $name => $file) {
    echo "\n[sync] ── Running {$name} ──\n";
    require $file;
}

$elapsed = round(microtime(true) - $start, 2);
echo "\n[sync] Complete in {$elapsed}s — " . date('Y-m-d H:i:s') . "\n";
