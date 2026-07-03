<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/auth.php';

use GuzzleHttp\Client;

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

requireApiKey();

try {
    $apiKey  = $_ENV['WEATHER_API_KEY'] ?? '';
    $baseUrl = rtrim($_ENV['WEATHER_API_URL'] ?? 'https://api.openweathermap.org/data/2.5', '/');
    $lat     = $_ENV['WEATHER_LAT'] ?? '37.9577';
    $lon     = $_ENV['WEATHER_LON'] ?? '-121.2908';

    if (empty($apiKey)) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'WEATHER_API_KEY not configured']);
        exit;
    }

    $client   = new Client(['timeout' => 10]);
    $response = $client->get($baseUrl . '/forecast', [
        'query' => [
            'lat'   => $lat,
            'lon'   => $lon,
            'appid' => $apiKey,
            'units' => 'imperial',
        ],
    ]);

    $body = json_decode((string) $response->getBody(), true);
    $list = $body['list'] ?? [];

    $rainConditions = ['rain', 'drizzle', 'thunderstorm'];

    $data = array_map(function (array $slot) use ($rainConditions): array {
        $condition = $slot['weather'][0]['main'] ?? null;
        $isRain    = in_array(strtolower((string) $condition), $rainConditions, true) ? 1 : 0;

        return [
            'forecast_time'     => $slot['dt_txt'] ?? null,
            'weather_condition' => $condition,
            'temp_fahrenheit'   => isset($slot['main']['temp'])
                                   ? round((float) $slot['main']['temp'], 1)
                                   : null,
            'humidity_pct'      => isset($slot['main']['humidity'])
                                   ? (int) $slot['main']['humidity']
                                   : null,
            'wind_mph'          => isset($slot['wind']['speed'])
                                   ? round((float) $slot['wind']['speed'], 1)
                                   : null,
            'is_rain'           => $isRain,
        ];
    }, $list);

    echo json_encode([
        'status'       => 'success',
        'data'         => $data,
        'total'        => count($data),
        'last_updated' => date('Y-m-d H:i:s'),
    ]);

} catch (\GuzzleHttp\Exception\ClientException $e) {
    http_response_code(502);
    echo json_encode(['status' => 'error', 'message' => 'OpenWeather API error: ' . $e->getMessage()]);
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Internal server error']);
}
