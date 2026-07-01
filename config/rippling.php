<?php

declare(strict_types=1);

use GuzzleHttp\Client;

function getRipplingClient(): Client
{
    return new Client([
        'base_uri' => rtrim($_ENV['RIPPLING_API_URL'], '/') . '/',
        'timeout'  => 30,
        'headers'  => [
            'Authorization' => 'Bearer ' . $_ENV['RIPPLING_API_TOKEN'],
            'Accept'        => 'application/json',
        ],
    ]);
}
