# Mock Payment Provider

A lightweight mock payment provider built with PHP 8.4 and Apache.

This project is an educational service designed to simulate an external payment provider for WooCommerce payment gateway development and testing.

## Tech Stack

- PHP 8.4
- Apache
- Docker Compose
- JSON file storage

## Requirements

- Docker Engine
- Docker Compose

## Getting Started

Clone the repository and start the service:

```bash
docker compose up -d --build
```

The provider will be available at:

```text
http://localhost:8081
```

To stop the service:

```bash
docker compose down
```

## API

### Create Payment

**Endpoint:**

```http
POST /api/payments
```

**Content-Type:**

```text
application/json
```

**Request example:**

```json
{
  "order_id": 42,
  "amount": "125.50",
  "currency": "USD",
  "return_url": "http://localhost:8080/checkout/order-received/42/"
}
```

**Successful response (201 Created):**

```json
{
  "payment_id": "payment_b619109050e781e9",
  "payment_url": "http://localhost:8081/checkout/payment_b619109050e781e9"
}
```

The `payment_url` is intended to be used as the customer's redirect destination during the payment process.

## Storage

Payment records are stored in:

```text
storage/payments.json
```

This file contains runtime data and is intentionally excluded from version control.

## Tests

Run the storage tests inside the Docker container:

```bash
docker compose exec provider php /var/www/html/test-storage.php
```