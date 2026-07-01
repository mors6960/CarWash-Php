<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/db.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

use GuzzleHttp\Client;

$pdo    = getDB();
$client = new Client(['timeout' => 10]);

$apiKey  = $_ENV['WEATHER_API_KEY'] ?? '';
$baseUrl = rtrim($_ENV['WEATHER_API_URL'] ?? 'https://api.openweathermap.org/data/2.5', '/');

// Only Stockton in scope — Lodi and Manteca to be added when client enables those sites
$locations = [
    'STOCK' => 'Stockton,US',
];

if (empty($apiKey)) {
    echo "[fetch_weather] ERROR: WEATHER_API_KEY is not set in .env\n";
    exit(1);
}

$stmt = $pdo->prepare("
    INSERT INTO weather_data
        (site_code, recorded_at, weather_condition, temp_fahrenheit,
         humidity_pct, wind_mph, precipitation_mm, is_rain, fetched_at)
    VALUES
        (:site_code, NOW(), :weather_condition, :temp_fahrenheit,
         :humidity_pct, :wind_mph, :precipitation_mm, :is_rain, NOW())
");

foreach ($locations as $siteCode => $city) {
    echo "[fetch_weather] Fetching {$city} ({$siteCode})\n";

    try {
        $response = $client->get($baseUrl . '/weather', [
            'query' => [
                'q'     => $city,
                'appid' => $apiKey,
                'units' => 'imperial',
            ],
        ]);

        $data      = json_decode((string) $response->getBody(), true);
        $condition = $data['weather'][0]['main'] ?? null;
        $isRain    = in_array(strtolower((string) $condition), ['rain', 'drizzle', 'thunderstorm'], true) ? 1 : 0;
        $rainMm    = ($data['rain']['1h'] ?? 0) * 25.4; // inches → mm

        $stmt->execute([
            ':site_code'         => $siteCode,
            ':weather_condition' => $condition,
            ':temp_fahrenheit'   => $data['main']['temp']     ?? null,
            ':humidity_pct'      => $data['main']['humidity'] ?? null,
            ':wind_mph'          => $data['wind']['speed']    ?? null,
            ':precipitation_mm'  => $rainMm,
            ':is_rain'           => $isRain,
        ]);

        echo "[fetch_weather] {$siteCode}: {$condition}, " . ($data['main']['temp'] ?? '?') . "°F\n";

    } catch (\Exception $e) {
        echo "[fetch_weather] ERROR {$siteCode}: " . $e->getMessage() . "\n";
    }
}

echo "[fetch_weather] Done\n";
