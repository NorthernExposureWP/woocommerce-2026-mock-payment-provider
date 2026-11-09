<?php

declare(strict_types=1);

/**
 * Sends a JSON POST request (a "callback") to the merchant's URL.
 *
 * Every request is signed so the merchant can verify that it comes from this provider:
 * X-Payment-Signature = HMAC-SHA256("{X-Payment-Timestamp}.{raw body}", shared secret).
 */
class CallbackSender
{
    public function __construct(
        private readonly string $secret,
        private readonly int $connectTimeout = 5,
        private readonly int $timeout = 10
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws RuntimeException|JsonException if the request fails or the response status is not 2xx
     */
    public function send(string $url, array $payload): void
    {
        $body      = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, $this->secret);

        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Cannot initialize cURL');
        }

        curl_setopt_array($curl, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-Payment-Timestamp: ' . $timestamp,
                'X-Payment-Signature: ' . $signature,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT        => $this->timeout,
        ]);

        $response = curl_exec($curl);

        if ($response === false) {
            throw new RuntimeException('Request failed: ' . curl_error($curl));
        }

        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException(
                sprintf('Unexpected HTTP status %d: %s', $status, substr((string) $response, 0, 200))
            );
        }
    }
}