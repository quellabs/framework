# Mollie Payment Provider

A Mollie payment provider. Part of the Canvas ecosystem.

## Requirements

- PHP 8.1+
- Quellabs Canvas framework
- `quellabs/payment-contracts`
- `quellabs/signal-hub`
- `symfony/http-client`
- `symfony/http-foundation`

## Installation

```bash
composer require quellabs/payments-mollie
```

## Architecture

This package sits between the Mollie API and your application. Your application only ever touches the contracts layer —
it never depends on this package directly.

```
Your Application
      │
      ▼
PaymentProviderInterface    (quellabs/payment-contracts)
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
    'api_key'    => 'live_xxxxxxxxxxxxxxxxxxxxxx',
    'webhookUrl' => 'https://example.com/webhooks/mollie',
    'redirectUrl' => 'https://example.com/payment/return/mollie',
    'returnUrl'  => 'https://example.com/order/thankyou',
];
```

| Key           | Description                                                   |
|---------------|---------------------------------------------------------------|
| `api_key`     | Your Mollie API key (live or test)                            |
| `webhookUrl`  | URL Mollie POSTs payment status updates to                    |
| `redirectUrl` | URL Mollie redirects the customer's browser to after checkout |
| `returnUrl`   | URL your return controller redirects the customer onward to   |

## Usage

### Initiating a payment

```php
use Quellabs\Contracts\Payment\PaymentRequest;

$request = new PaymentRequest(
    amount:        10.00,
    currency:      'EUR',
    description:   'Order #12345',
    reference:     '12345',
    paymentModule: 'mollie_ideal',
    options:       ['issuer' => 'ideal_INGBNL2A'],
);

$response = $provider->initiate($request);

if (!$response->success) {
    // handle error
}

// redirect customer to Mollie checkout
header('Location: ' . $response->data->redirectUrl);
```

### Handling refunds

```php
use Quellabs\Contracts\Payment\RefundRequest;

$request = new RefundRequest(
    transactionId: 'tr_7UhSN1zuXS',
    amount:        5.00,
    currency:      'EUR',
    description:   'Partial refund for order #12345',
);

$response = $provider->refund($request);

if (!$response->success) {
    // handle error
}

// $response->data is a RefundResult
echo $response->data->refundId;
```

### Fetching refunds for a transaction

```php
$response = $provider->getRefunds('tr_7UhSN1zuXS');

if ($response->success) {
    foreach ($response->data as $refund) {
        echo $refund->refundId . ': ' . $refund->value . ' ' . $refund->currency;
    }
}
```

### Listening for webhook events

Register a listener for the `mollie_exchange` signal in your application:

```php
use Quellabs\Contracts\Payment\PaymentState;
use Quellabs\Contracts\Payment\PaymentStatus;
use Quellabs\SignalHub\Signal;

$signal = new Signal('mollie_exchange');

$signal->connect(function(PaymentState $state) {
    match ($state->state) {
        PaymentStatus::Paid     => $this->orders->markPaid($state->transactionId, $state->valuePaid),
        PaymentStatus::Canceled => $this->orders->markCanceled($state->transactionId),
        PaymentStatus::Expired  => $this->orders->markExpired($state->transactionId),
        PaymentStatus::Refunded => $this->handleRefund($state),
        default                 => null,
    };
});
```

## Payment state

`PaymentState` is returned by `exchange()` and emitted via the signal on every webhook hit.

| Property          | Type            | Description                                 |
|-------------------|-----------------|---------------------------------------------|
| `provider`        | `string`        | Always `"mollie"`                           |
| `transactionId`   | `string`        | Mollie transaction ID                       |
| `state`           | `PaymentStatus` | Current payment state                       |
| `internalState`   | `string`        | Raw Mollie status string                    |
| `valuePaid`       | `float`         | Original charged amount                     |
| `valueRefunded`   | `float`         | Total amount refunded so far                |
| `valueRefundable` | `float`         | Remaining amount that can still be refunded |
| `currency`        | `string`        | ISO 4217 currency code                      |

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

| Module                | Method               |
|-----------------------|----------------------|
| `mollie`              | Any (Mollie decides) |
| `mollie_ideal`        | iDEAL                |
| `mollie_creditcard`   | Credit card          |
| `mollie_paypal`       | PayPal               |
| `mollie_banktransfer` | Bank transfer        |
| `mollie_bancontact`   | Bancontact           |
| `mollie_applepay`     | Apple Pay            |
| `mollie_eps`          | EPS                  |
| `mollie_giftcard`     | Gift card            |
| `mollie_giropay`      | Giropay              |
| `mollie_kbc`          | KBC/CBC              |
| `mollie_mybank`       | MyBank               |
| `mollie_paysafecard`  | Paysafecard          |
| `mollie_przelewy24`   | Przelewy24           |
| `mollie_sofort`       | SOFORT Banking       |
| `mollie_belfius`      | Belfius              |

Payment methods with issuer selection (iDEAL, KBC, gift cards) can fetch available issuers via
`getPaymentOptions($paymentModule)`.

## Webhook security

Mollie's legacy webhook sends only a single POST parameter `id` — no payment data. Your application fetches the current
state from the Mollie API using that ID. This means a forged webhook call cannot cause your application to process a
payment that was never made.

## License

MIT