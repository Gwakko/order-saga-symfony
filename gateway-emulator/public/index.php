<?php

declare(strict_types=1);

use GatewayEmulator\Handler;
use GatewayEmulator\Request;
use GatewayEmulator\Store;

require_once __DIR__ . '/../autoload.php';

$store = new Store(getenv('GATEWAY_DB') ?: sys_get_temp_dir() . '/gateway-emulator.sqlite');
$handler = new Handler($store, static fn(): int => (int) (microtime(true) * 1000));

$key = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null;
$response = $handler->handle(new Request(
    $_SERVER['REQUEST_METHOD'] ?? 'GET',
    (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH),
    is_string($key) ? $key : null,
    (string) file_get_contents('php://input'),
));

if ($response->delayMs > 0) {
    usleep($response->delayMs * 1000);
}

http_response_code($response->status);
header('Content-Type: ' . $response->contentType);
header('Content-Length: ' . (strlen($response->body) + ($response->abort ? 100 : 0)));
echo $response->body;

if ($response->abort) {
    // Заголовки и часть тела отправлены, дальше соединение обрывается.
    flush();
    exit;
}
