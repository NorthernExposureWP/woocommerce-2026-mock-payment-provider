<?php

declare(strict_types=1);

class PaymentController
{
    public function __construct(
        private readonly PaymentStorage $storage,
        private readonly string $baseUrl
    ) {
    }

    /**
     * Handles POST /api/payments.
     *
     * @return array{0: int, 1: array} HTTP status code and response body
     */
    public function create(string $rawBody): array
    {
        try {
            $data = json_decode($rawBody, false, 512, JSON_THROW_ON_ERROR);
            if (!$data instanceof stdClass) {
                return [400, ['error' => 'Request body must be a JSON object']];
            }
            $data = get_object_vars($data);
        } catch (JsonException $e) {
            return [400, ['error' => 'Invalid JSON: ' . $e->getMessage()]];
        }

        [$fields, $errors] = $this->validate($data);
        if ($errors !== []) {
            return [422, ['error' => 'Validation failed', 'details' => $errors]];
        }

        $payments = $this->storage->read();

        do {
            $paymentId = 'payment_' . bin2hex(random_bytes(8));
        } while (isset($payments[$paymentId]));

        $payments[$paymentId] = [
            'order_id'     => $fields['order_id'],
            'amount'       => $fields['amount'],
            'currency'     => $fields['currency'],
            'status'       => 'pending',
            'return_url'   => $fields['return_url'],
            'callback_url' => $fields['callback_url'],
        ];

        $this->storage->write($payments);

        return [201, [
            'payment_id'  => $paymentId,
            'payment_url' => rtrim($this->baseUrl, '/') . '/checkout/' . $paymentId,
        ]];
    }

    /**
     * @return array{0: array, 1: array<string, string>} normalized fields and validation errors
     */
    private function validate(array $data): array
    {
        $errors = [];

        $orderId = $data['order_id'] ?? null;
        if (!is_int($orderId) || $orderId <= 0) {
            $errors['order_id'] = 'required, must be a positive integer';
        }

        $amount = $data['amount'] ?? null;
        if (
            !is_string($amount)
            || preg_match('/^\d+(?:\.\d+)?$/', $amount) !== 1
            || preg_match('/[1-9]/', $amount) !== 1
        ) {
            $errors['amount'] = 'required, must be a positive decimal string';
        }

        $currency = $data['currency'] ?? null;
        if (!is_string($currency) || preg_match('/^[A-Za-z]{3}$/', $currency) !== 1) {
            $errors['currency'] = 'required, must be a 3-letter code, e.g. USD';
        }

        $returnUrl = $data['return_url'] ?? null;
        if (!is_string($returnUrl) || !$this->isHttpUrl($returnUrl)) {
            $errors['return_url'] = 'required, must be a valid http or https URL';
        }

        $callbackUrl = $data['callback_url'] ?? null;
        if (!is_string($callbackUrl) || !$this->isHttpUrl($callbackUrl)) {
            $errors['callback_url'] = 'required, must be a valid http or https URL';
        }

        if ($errors !== []) {
            return [[], $errors];
        }

        return [[
            'order_id'     => $orderId,
            'amount'       => (string) $amount,
            'currency'     => strtoupper($currency),
            'return_url'   => $returnUrl,
            'callback_url' => $callbackUrl,
        ], []];
    }

    private function isHttpUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);

        return $parts !== false
            && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && ($parts['host'] ?? '') !== '';
    }
}