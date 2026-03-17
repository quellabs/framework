# Mollie Payment Provider

A Mollie payment provider for the Canvas framework. Part of the Canvas payments ecosystem.

## Requirements

- PHP 8.1+
- Quellabs Canvas framework
- `quellabs/canvas-payments-contracts`
- `quellabs/signal-hub`
- `symfony/http-client`
- `symfony/http-foundation`

## Installation

```bash
composer require quellabs/canvas-payments-mollie
```

## Architecture

This package sits between the Mollie API and your application. Your application only ever touches the contracts layer —
it never depends on this package directly. `PaymentRouter` (from `quellabs/canvas-payments`) discovers this package
automatically via composer metadata and routes payment calls to it.

```
Your Application
      │
      ▼
PaymentRouter               (quellabs/canvas-payments — discovery + routing)
      │
      ▼
PaymentProviderInterface    (quellabs/canvas-payments-contracts)
      │
      ▼
Mollie                      (this package — implements the interface)
      │
      ▼
MollieGateway               (raw Mollie API calls)
```

Webhook processing is decoupled from your application via signals. When Mollie calls your webhook, the controller emits
a `payment_exchange` signal carrying a `PaymentState`. Your application listens for that signal and handles it — the
payment module never touches your database.

## Configuration

Create `config/mollie.php` in your Canvas application:

```php
return [
    'api_key'           => 'live_xxxxxxxxxxxxxxxxxxxxxx',
    'test_mode'         => false,
    'webhook_url'       => 'https://example.com/webhooks/mollie',
    'redirect_url'      => 'https://example.com/payment/return/mollie',
    'cancel_url'        => 'https://example.com/payment/cancel/mollie',
    'return_url'        => 'https://example.com/order/thankyou',
    'cancel_return_url' => 'https://example.com/order/cancelled',
];
```

| Key                 | Required | Description                                                                |
|---------------------|----------|----------------------------------------------------------------------------|
| `api_key`           | Yes      | Your Mollie API key (live or test)                                         |
| `test_mode`         | No       | Enable Mollie test mode. Defaults to `false`                               |
| `webhook_url`       | No       | URL Mollie POSTs status updates to. Defaults to `/webhooks/mollie`         |
| `redirect_url`      | Yes      | URL Mollie redirects the customer to after successful checkout             |
| `cancel_url`        | Yes      | URL Mollie redirects the customer to when they cancel checkout             |
| `return_url`        | Yes      | URL your return controller redirects the customer onward to                |
| `cancel_return_url` | Yes      | URL your cancel controller redirects the customer onward to                |

## Usage

### Initiating a payment

Inject `Quellabs\Payments\Mollie\Driver` via Canvas DI and call `initiate()`:

```php
use Quellabs\Payments\Mollie\Driver;
use Quellabs\Payments\Contracts\PaymentRequest;

$request = new PaymentRequest(
    paymentModule: 'mollie_ideal',
    amount:        999,   // in minor units — €9.99
    currency:      'EUR',
    description:   'Order #12345',
    issuerId:      'ideal_INGBNL2A',
);

public function __construct(private Driver $mollie) {}

// ...

$response = $mollie->initiate($request);

if (!$response->success) {
    // handle error
}

header('Location: ' . $response->response->redirectUrl);
```

### Handling refunds

```php
use Quellabs\Payments\Contracts\RefundRequest;

$request = new RefundRequest(
    transactionId: 'tr_7UhSN1zuXS',
    paymentModule: 'mollie_ideal',
    amount:        500,   // in minor units — €5.00
    currency:      'EUR',
    description:   'Partial refund for order #12345',
);

$response = $mollie->refund($request);

if (!$response->success) {
    // handle error
}

echo $response->response->refundId;
```

### Fetching refunds for a transaction

```php
$response = $mollie->getRefunds('tr_7UhSN1zuXS');

if ($response->success) {
    foreach ($response->response as $refund) {
        echo $refund->refundId . ': ' . $refund->value . ' ' . $refund->currency;
    }
}
```

### Listening for webhook events

Canvas automatically connects listeners annotated with `@ListenTo` to the signal system. Add the annotation to any
method in a Canvas-managed class:

```php
use Quellabs\Canvas\Annotations\ListenTo;
use Quellabs\Payments\Contracts\PaymentState;
use Quellabs\Payments\Contracts\PaymentStatus;

class OrderService {

    /**
     * @ListenTo("payment_exchange")
     */
    public function onPaymentExchange(PaymentState $state): void {
        match ($state->state) {
            PaymentStatus::Paid     => $this->markPaid($state->transactionId, $state->valueRequested),
            PaymentStatus::Canceled => $this->markCanceled($state->transactionId),
            PaymentStatus::Expired  => $this->markExpired($state->transactionId),
            PaymentStatus::Refunded => $this->handleRefund($state),
            default                 => null,
        };
    }
}
```

## Payment state

`PaymentState` is returned by `exchange()` and emitted via the signal on every webhook hit.

| Property          | Type            | Description                                 |
|-------------------|-----------------|---------------------------------------------|
| `provider`        | `string`        | Always `"mollie"`                           |
| `transactionId`   | `string`        | Mollie transaction ID                       |
| `state`           | `PaymentStatus` | Current payment state                       |
| `internalState`   | `string`        | Raw Mollie status string                    |
| `valueRequested`  | `int`           | Original charged amount in minor units      |
| `valueRefunded`   | `int`           | Total amount refunded so far in minor units |
| `valueRefundable` | `int`           | Remaining refundable amount in minor units  |
| `currency`        | `string`        | ISO 4217 currency code                      |
| `metadata`        | `array`         | Metadata passed through from the request    |

## Payment statuses

| Status                    | Description                                    |
|---------------------------|------------------------------------------------|
| `PaymentStatus::Pending`  | Payment is open or pending                     |
| `PaymentStatus::Paid`     | Payment was successfully completed             |
| `PaymentStatus::Canceled` | Customer canceled — definitive                 |
| `PaymentStatus::Expired`  | Customer abandoned, or bank transfer timed out |
| `PaymentStatus::Failed`   | Payment failed and cannot be retried           |
| `PaymentStatus::Refunded` | Payment was refunded                           |
| `PaymentStatus::Unknown`  | Unrecognised status from Mollie                |

## Supported payment methods

| Module                  | Method               |
|-------------------------|----------------------|
| `mollie`                | Any (Mollie decides) |
| `mollie_ideal`          | iDEAL                |
| `mollie_creditcard`     | Credit card          |
| `mollie_paypal`         | PayPal               |
| `mollie_bancontact`     | Bancontact           |
| `mollie_applepay`       | Apple Pay            |
| `mollie_eps`            | EPS                  |
| `mollie_giftcard`       | Gift card            |
| `mollie_giropay`        | Giropay              |
| `mollie_kbc`            | KBC/CBC              |
| `mollie_mybank`         | MyBank               |
| `mollie_paysafecard`    | Paysafecard          |
| `mollie_przelewy24`     | Przelewy24           |
| `mollie_sofort`         | SOFORT Banking       |
| `mollie_belfius`        | Belfius              |
| `mollie_billie`         | Billie               |
| `mollie_in3`            | in3                  |
| `mollie_klarna`         | Klarna               |
| `mollie_riverty`        | Riverty              |

Payment methods with issuer selection (iDEAL, KBC, gift cards) can fetch available issuers via `getPaymentOptions($paymentModule)`.

## Webhook security

Mollie's webhook sends only a single POST parameter `id` — no payment data. Your application fetches the current
state from the Mollie API using that ID. This means a forged webhook call cannot cause your application to process a
payment that was never made.

## License

MIT