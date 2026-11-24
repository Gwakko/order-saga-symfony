<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

header('Content-Type: application/json');

if ($path === '/health') {
    echo '{"status":"ok"}';

    return;
}

http_response_code(404);
echo '{"error":"not_found"}';
