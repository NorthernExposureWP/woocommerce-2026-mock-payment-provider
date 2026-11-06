<?php

declare(strict_types=1);

require __DIR__ . '/src/PaymentStorage.php';

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}

$failures = 0;

$check = function (string $name, bool $passed) use (&$failures): void {
    if (!$passed) {
        $failures++;
    }
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL;
};

// A throwaway file, so the real payments.json is never touched.
$file = sys_get_temp_dir() . '/payments-test-' . bin2hex(random_bytes(4)) . '.json';

try {
    $storage = new PaymentStorage($file);

    // ------------------------------------------------------------------
    echo PHP_EOL . '== 1) Read empty storage ==' . PHP_EOL;
    // ------------------------------------------------------------------
    $check('read() returns an empty array when file does not exist', $storage->read() === []);
    touch($file);

    $check('file exists and is empty', is_file($file) && filesize($file) === 0);
    $check('read() returns an empty array', $storage->read() === []);

    // ------------------------------------------------------------------
    echo PHP_EOL . '== 2) Write payments ==' . PHP_EOL;
    // ------------------------------------------------------------------
    $payments = [
        'payment_001' => [
            'order_id'   => 123,
            'amount'     => '60',
            'currency'   => 'USD',
            'status'     => 'pending',
            'return_url' => 'http://localhost:8080/checkout/order-received/123',
        ],
        'payment_002' => [
            'order_id'   => 124,
            'amount'     => '120',
            'currency'   => 'EUR',
            'status'     => 'pending',
            'return_url' => 'http://localhost:8080/checkout/order-received/124',
        ],
    ];

    $storage->write($payments);

    $check('file is not empty after write()', filesize($file) > 0);
    $check(
        'file contains valid JSON equal to the written data',
        json_decode((string) file_get_contents($file), true) === $payments
    );
    $check('no temporary files left behind', glob($file . '.*.tmp') === []);

    // ------------------------------------------------------------------
    echo PHP_EOL . '== 3) Read payments ==' . PHP_EOL;
    // ------------------------------------------------------------------
    $result = $storage->read();

    $check('read() returns both payments', count($result) === 2);
    $check('read() returns exactly the data that was written', $result === $payments);
    $check(
        'value types are preserved (order_id is int, amount is string)',
        $result['payment_001']['order_id'] === 123 && $result['payment_001']['amount'] === '60'
    );

    // ------------------------------------------------------------------
    echo PHP_EOL . '== 4) Overwrite payments ==' . PHP_EOL;
    // ------------------------------------------------------------------
    $updatedPayment = [
        'order_id'   => 124,
        'amount'     => '85',
        'currency'   => 'EUR',
        'status'     => 'completed',
        'return_url' => 'http://localhost:8080/checkout/order-received/124',
    ];
    $newPayment = [
        'order_id'   => 125,
        'amount'     => '35',
        'currency'   => 'USD',
        'status'     => 'pending',
        'return_url' => 'http://localhost:8080/checkout/order-received/125',
    ];

    // payment_002 already exists and must be replaced, payment_003 is new.
    $storage->write(array_replace($storage->read(), [
        'payment_002' => $updatedPayment,
        'payment_003' => $newPayment,
    ]));

    $result = $storage->read();

    $check('storage now holds 3 payments', count($result) === 3);
    $check('payment_001 is unchanged', $result['payment_001'] === $payments['payment_001']);
    $check('payment_002 was replaced with the new data', $result['payment_002'] === $updatedPayment);
    $check('payment_003 was added', $result['payment_003'] === $newPayment);
    $check('no temporary files left behind', glob($file . '.*.tmp') === []);


    // ------------------------------------------------------------------
    echo PHP_EOL . '== 5) Invalid JSON =='. PHP_EOL;
    // ------------------------------------------------------------------
    file_put_contents($file, '{invalid json');
    $exceptionThrown = false;
    try {
        $storage->read();
    } catch (RuntimeException $e) {
        $exceptionThrown = true;
    }
    $check('read() throws RuntimeException for invalid JSON', $exceptionThrown);


} catch (Throwable $e) {
    $failures++;
    echo PHP_EOL . '[FAIL] Unexpected ' . $e::class . ': ' . $e->getMessage() . PHP_EOL;
} finally {
    @unlink($file);
    @unlink($file . '.lock');
    foreach (glob($file . '.*.tmp') ?: [] as $leftover) {
        @unlink($leftover);
    }
}

echo PHP_EOL . ($failures === 0 ? 'All checks passed.' : "$failures check(s) failed.") . PHP_EOL;

exit($failures === 0 ? 0 : 1);