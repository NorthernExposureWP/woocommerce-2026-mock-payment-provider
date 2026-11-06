<?php

declare(strict_types=1);

class CheckoutController
{
    public function __construct(private readonly PaymentStorage $storage)
    {
    }

    /**
     * Handles GET /checkout/{payment_id}. Read-only: nothing is written to storage.
     * The pay/decline forms are shown only while the payment is pending.
     *
     * @return array{0: int, 1: string} HTTP status code and HTML body
     */
    public function show(string $paymentId): array
    {
        $payment = $this->storage->read()[$paymentId] ?? null;

        if (!is_array($payment)) {
            return [404, $this->page('<p class="error">Payment not found</p>')];
        }

        $amount = $this->formatAmount((string) ($payment['amount'] ?? '0'))
            . ' ' . ($payment['currency'] ?? '');

        $content = sprintf(
            <<<'HTML'
            <h2>Payment Details</h2>
            <dl>
                <dt>Payment ID:</dt><dd>%s</dd>
                <dt>Order ID:</dt><dd>%s</dd>
                <dt>Amount:</dt><dd>%s</dd>
                <dt>Status:</dt><dd>%s</dd>
            </dl>
            HTML,
            $this->e($paymentId),
            $this->e($payment['order_id'] ?? ''),
            $this->e($amount),
            $this->e(ucfirst((string) ($payment['status'] ?? '')))
        );

        if (($payment['status'] ?? '') === 'pending') {
            $content .= $this->actionForms($paymentId);
        }

        return [200, $this->page($content)];
    }

    /**
     * Handles POST /checkout/{payment_id}/pay: pending => succeeded.
     *
     * @return array{0: int, 1: string} HTTP status code and HTML body
     */
    public function pay(string $paymentId): array
    {
        return $this->process($paymentId, 'succeeded');
    }

    /**
     * Handles POST /checkout/{payment_id}/decline: pending => declined.
     *
     * @return array{0: int, 1: string} HTTP status code and HTML body
     */
    public function decline(string $paymentId): array
    {
        return $this->process($paymentId, 'declined');
    }

    /**
     * Moves a pending payment to a final status. Any other current status
     * (succeeded, declined, ...) is rejected with 409 and storage is not touched.
     *
     * @return array{0: int, 1: string} HTTP status code and HTML body
     */
    private function process(string $paymentId, string $newStatus): array
    {
        $payments = $this->storage->read();
        $payment  = $payments[$paymentId] ?? null;

        if (!is_array($payment)) {
            return [404, $this->page('<p class="error">Payment not found</p>')];
        }

        $currentStatus = (string) ($payment['status'] ?? '');
        if ($currentStatus !== 'pending') {
            return [409, $this->page(sprintf(
                '<p class="error">Payment %s has already been processed (current status: %s) and cannot be changed.</p>',
                $this->e($paymentId),
                $this->e(ucfirst($currentStatus))
            ))];
        }

        $payments[$paymentId]['status'] = $newStatus;
        $this->storage->write($payments);

        return [200, $this->page(sprintf(
            <<<'HTML'
            <h2>Payment Result</h2>
            <dl>
                <dt>Payment ID:</dt><dd>%s</dd>
                <dt>Status:</dt><dd>%s</dd>
            </dl>
            HTML,
            $this->e($paymentId),
            $this->e(ucfirst($newStatus))
        ))];
    }

    private function actionForms(string $paymentId): string
    {
        $base = $this->e('/checkout/' . rawurlencode($paymentId));

        return <<<HTML
        <div class="actions">
            <form method="post" action="{$base}/pay">
                <button type="submit" class="pay">Pay successfully</button>
            </form>
            <form method="post" action="{$base}/decline">
                <button type="submit" class="decline">Decline payment</button>
            </form>
        </div>
        HTML;
    }

    /**
     * Pads a decimal string to at least 2 decimals without converting it to float
     * ("125.5" => "125.50", "60" => "60.00"); longer fractions are kept as they are.
     */
    private function formatAmount(string $amount): string
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return $whole . '.' . str_pad($fraction, 2, '0');
    }

    private function page(string $content): string
    {
        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>Mock Payment Provider</title>
            <style>
                body { font-family: system-ui, sans-serif; background: #f4f5f7; margin: 0; }
                main { max-width: 420px; margin: 48px auto; padding: 24px 32px; background: #fff;
                       border-radius: 8px; box-shadow: 0 1px 4px rgba(0, 0, 0, .15); }
                h1, h2 { text-align: center; margin: 0 0 16px; }
                h2 { font-size: 1.1rem; font-weight: 600; }
                dl { display: grid; grid-template-columns: max-content 1fr; gap: 6px 16px; margin: 24px 0; }
                dt { font-weight: 600; }
                dd { margin: 0; word-break: break-all; }
                .actions { display: flex; flex-direction: column; align-items: center; gap: 12px; }
                button { min-width: 200px; padding: 10px 16px; border: 0; border-radius: 4px;
                         font-size: 1rem; color: #fff; cursor: pointer; }
                .pay { background: #2e7d32; }
                .decline { background: #c62828; }
                .error { text-align: center; color: #c62828; font-weight: 600; }
            </style>
        </head>
        <body>
            <main>
                <h1>Mock Payment Provider</h1>
                {$content}
            </main>
        </body>
        </html>
        HTML;
    }

    private function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}