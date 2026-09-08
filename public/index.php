<?php

date_default_timezone_set('UTC');

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$path = '/' . trim($path, '/');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$routes = [
    'POST /v1/register-push' => 'register_push.php',
    'POST /v1/wake'          => 'wake.php',
    'POST /v1/deregister'    => 'deregister.php',
];

$key = $method . ' ' . $path;
if (!isset($routes[$key])) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'not_found']);
    exit;
}

require __DIR__ . '/../src/handlers/' . $routes[$key];
