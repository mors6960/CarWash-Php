<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;

function getSonnysClient(bool $sandbox = false): Client
{
    $baseUrl = $sandbox
        ? ($_ENV['SONNYS_SANDBOX_URL'] ?? 'https://sandboxapi.sonnyscontrols.com/v1')
        : $_ENV['SONNYS_API_URL'];

    return new Client([
        'base_uri' => rtrim($baseUrl, '/') . '/',
        'timeout'  => 30,
        'headers'  => [
            'X-Sonnys-API-Key'    => $_ENV['SONNYS_API_KEY'],
            'X-Sonnys-API-ID'     => $_ENV['SONNYS_API_ID'],
            'X-Sonnys-Site-Code'  => $_ENV['SONNYS_SITE_ID'],
            'Accept'              => 'application/json',
        ],
    ]);
}

// Sonny's throttle: max 20 requests per 15 seconds
function sonnysThrottle(): void
{
    usleep(800000); // 0.8s between requests — stays safely under 20/15s
}
