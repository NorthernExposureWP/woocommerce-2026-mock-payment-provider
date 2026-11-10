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

function respondHtml(int $status, string $html, array $headers = []): never
{
    http_response_code($status);
    foreach ($headers as $name => $value) {
        header($name . ': ' . $value);
    }
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
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

function createCheckoutController(): CheckoutController
{
    $storageDir = '/var/www/storage';
    if (!is_dir($storageDir) && !mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
        throw new RuntimeException("Cannot create storage directory {$storageDir}");
    }

    $callbackSecret = getenv('CALLBACK_SECRET');
    if ($callbackSecret === false || $callbackSecret === '') {
        throw new RuntimeException('CALLBACK_SECRET is not configured');
    }

    return new CheckoutController(
        new PaymentStorage($storageDir . '/payments.json'),
        new CallbackSender($callbackSecret)
    );
}

// path => [HTTP method => handler returning [status, body]]
$routes = [
    '/api/payments' => [
        'POST' => static fn(): array => createPaymentController()
            ->create((string) file_get_contents('php://input')),
    ],
];

// HTML pages with URL parameters:
// regex => [HTTP method => handler(string ...$params) returning [status, html]]
$pageRoutes = [
    '#^/checkout/([^/]+)$#' => [
        'GET' => static fn(string $paymentId): array => createCheckoutController()
            ->show($paymentId),
    ],
    '#^/checkout/([^/]+)/pay$#' => [
        'POST' => static fn(string $paymentId): array => createCheckoutController()
            ->pay($paymentId),
    ],
    '#^/checkout/([^/]+)/decline$#' => [
        'POST' => static fn(string $paymentId): array => createCheckoutController()
            ->decline($paymentId),
    ],
];

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path   = rtrim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/') ?: '/';

    foreach ($pageRoutes as $pattern => $handlers) {
        if (preg_match($pattern, $path, $matches) !== 1) {
            continue;
        }

        if (!isset($handlers[$method])) {
            respond(405, ['error' => 'Method not allowed'], ['Allow' => implode(', ', array_keys($handlers))]);
        }

        $result = $handlers[$method](...array_map('rawurldecode', array_slice($matches, 1)));
        respondHtml($result[0], $result[1], $result[2] ?? []);
    }

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