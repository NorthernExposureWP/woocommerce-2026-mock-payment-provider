<?php

declare(strict_types=1);

// Minimal autoloader: class Foo lives in src/Foo.php.
spl_autoload_register(static function (string $class): void {
    $file = __DIR__ . '/src/' . $class . '.php';
    if (is_file($file)) {
        require $file;
    }
});

function respond(int $status, array $body, array $headers = []): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    foreach ($headers as $name => $value) {
        header($name . ': ' . $value);
    }
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function createPaymentController(): PaymentController
{
    $storageDir = '/var/www/storage';
    if (!is_dir($storageDir) && !mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
        throw new RuntimeException("Cannot create storage directory {$storageDir}");
    }

    $baseUrl = getenv('APP_BASE_URL');
    if ($baseUrl === false || $baseUrl === '') {
        throw new RuntimeException('APP_BASE_URL is not configured');
    }

    return new PaymentController(new PaymentStorage($storageDir . '/payments.json'), $baseUrl);
}

// path => [HTTP method => handler returning [status, body]]
$routes = [
    '/api/payments' => [
        'POST' => static fn(): array => createPaymentController()
            ->create((string) file_get_contents('php://input')),
    ],
];

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path   = rtrim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/') ?: '/';

    if (!isset($routes[$path])) {
        respond(404, ['error' => 'Not found']);
    }

    if (!isset($routes[$path][$method])) {
        respond(405, ['error' => 'Method not allowed'], ['Allow' => implode(', ', array_keys($routes[$path]))]);
    }

    [$status, $body] = $routes[$path][$method]();
    respond($status, $body);
} catch (Throwable $e) {
    error_log(sprintf('%s: %s in %s:%d', $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));
    respond(500, ['error' => 'Internal server error']);
}