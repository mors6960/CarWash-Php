<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/sonnys.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$client = getSonnysClient();
$pdo    = getDB();

echo "[fetch_sites] Starting\n";

try {
    sonnysThrottle();
    $response = $client->get('site/list');
    $sites    = json_decode((string) $response->getBody(), true)['data']['sites'] ?? [];

    $stmt = $pdo->prepare("
        INSERT INTO sites (site_id, code, name, timezone)
        VALUES (:site_id, :code, :name, :timezone)
        ON DUPLICATE KEY UPDATE
            name     = VALUES(name),
            timezone = VALUES(timezone)
    ");

    foreach ($sites as $site) {
        $stmt->execute([
            ':site_id'  => $site['siteID']   ?? null,
            ':code'     => $site['code']     ?? null,
            ':name'     => $site['name']     ?? null,
            ':timezone' => $site['timezone'] ?? null,
        ]);
        echo "[fetch_sites] Stored site: {$site['code']} — {$site['name']}\n";
    }

    echo "[fetch_sites] Done — " . count($sites) . " sites\n";

} catch (\Exception $e) {
    echo "[fetch_sites] ERROR: " . $e->getMessage() . "\n";
}
