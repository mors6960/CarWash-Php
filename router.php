<?php

// Built-in PHP server router — maps clean URLs to files
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($uri === '/' || $uri === '/health') {
    require __DIR__ . '/public/index.php';
} elseif ($uri === '/cron/sync') {
    require_once __DIR__ . '/vendor/autoload.php';
    foreach (getenv() ?: [] as $k => $v) { if (empty($_ENV[$k])) $_ENV[$k] = $v; }
    require_once __DIR__ . '/config/auth.php';
    requireApiKey();
    header('Content-Type: application/json');
    ob_start();
    require __DIR__ . '/cron/sync.php';
    $output = ob_get_clean();
    echo json_encode(['status' => 'ok', 'output' => $output]);
} elseif (preg_match('/^\/api\/(transactions|memberships|employees|weather|sites)(\.php)?$/', $uri, $m)) {
    require __DIR__ . '/api/' . $m[1] . '.php';
} else {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Not found']);
}
