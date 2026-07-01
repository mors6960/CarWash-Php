<?php

declare(strict_types=1);

function requireApiKey(): void
{
    $headers = getallheaders();
    $provided = $headers['X-Api-Key'] ?? $headers['x-api-key'] ?? null;

    if ($provided === null || !hash_equals($_ENV['API_KEY'], $provided)) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
        exit;
    }
}
