# Buckaroo Payment Provider

A Buckaroo payment provider for the Canvas framework. Part of the Canvas payments ecosystem.

## Installation

```bash
composer require quellabs/canvas-payments-buckaroo
```

## Architecture

This package sits between the Buckaroo JSON API and your application. Your application only ever touches the contracts
layer — it never depends on this package directly. `PaymentRouter` (from `quellabs/canvas-payments`) discovers this
package automatically via composer metadata and routes payment calls to it.

```
Your Application
      │
      ▼
PaymentRouter               (quellabs/canvas-payments — discovery + routing)
      │
      ▼
PaymentInterface            (quellabs/canvas-payments-contracts)
      │
      ▼
Buckaroo Driver             (this package — implements the interface)
      │
      ▼
BuckarooGateway             (raw Buckaroo JSON API calls)
```

Push processing is decoupled from your application via signals. When Buckaroo posts to the push URL, the package emits a
`payment_exchange` signal carrying a `PaymentState`. Your application listens for that signal and handles it.

## Configuration

Create `config/buckaroo.php` in your Canvas application (this is done automatically on first install):

```php
return [
    'website_key'       => '',
    'secret_key'        => '',
    'test_mode'         => false,
    'return_url'        => 'https://example.com/order/thankyou',
    'return_url_cancel' => 'https://example.com/order/cancelled',
    'return_url_error'  => 'https://example.com/order/error',
    'return_url_reject' => 'https://example.com/order/rejected',
    'push_url'          => 'https://example.com/webhooks/buckaroo',
    'default_culture'   => 'nl-NL',
];
```

| Key                 | Required | Description                                                                          |
|---------------------|----------|--------------------------------------------------------------------------------------|
| `website_key`       | Yes      | Your Buckaroo website key, found in Plaza under My Buckaroo > Websites > {your site} |
| `secret_key`        | Yes      | Your Buckaroo secret key, generated in Plaza under Configuration > Secret Key        |
| `test_mode`         | No       | Use the Buckaroo test environment (`testcheckout.buckaroo.nl`). Defaults to `false`  |
| `return_url`        | Yes      | URL the shopper is redirected to after a completed or pending payment                |
| `return_url_cancel` | Yes      | URL the shopper is redirected to after cancelling payment                            |
| `return_url_error`  | No       | URL for technical errors during payment. Falls back to `return_url_cancel` if empty  |
| `return_url_reject` | No       | URL for acquirer-rejected payments. Falls back to `return_url_cancel` if empty       |
| `push_url`          | Yes      | Full URL Buckaroo POSTs push notifications to. Must be publicly reachable            |
| `default_culture`   | No       | BCP 47 culture tag for hosted page language and email templates. Defaults to `nl-NL` |

### Buckaroo Plaza configuration

In addition to `config/buckaroo.php`, configure the following in [Buckaroo Plaza](https://plaza.buckaroo.nl):

- **Push URL**: My Buckaroo > Websites > {your site} > Push Settings — set to your `push_url` value
- **Return URLs** are per-request (sent in each transaction); Plaza values serve as fallback only

## Key differences from MultiSafepay

### Authentication

Buckaroo uses HMAC-SHA256 request signing. Every request carries an `Authorization` header:

```
hmac <websiteKey>:<base64(HMAC-SHA256(signingString, secretKey))>:<nonce>:<timestamp>
```

The signing string is: `websiteKey + METHOD + urlencode(host+path, lowercase) + timestamp + nonce + base64(md5(body))`

### Amounts

Buckaroo amounts are **decimal floats** (`10.00` = €10.00), not minor units. The driver converts automatically —
`PaymentRequest::$amount` stays in minor units (e.g. `1000` for €10.00).

### Transaction identity

Buckaroo issues its own transaction key (`Key` in the response, 32-char hex). This key is stored as `transactionId` in
`PaymentState` and `InitiateResult`. Your order reference is passed as `Invoice` and returned as `BRQ_INVOICENUMBER` in
the return URL.

### Push vs webhook

Buckaroo's push sends a **JSON body** (not form-encoded like MultiSafepay):

```json
{
  "Transaction": {
    "Key": "<32-char key>",
    "Status": {
      ...
    },
    ...
  }
}
```

The controller reads `Transaction.Key` from the JSON body and falls back to `brq_transactions` query param for legacy
configurations.

### Return URL parameters

Buckaroo appends these to the configured `ReturnURL`:

| Parameter           | Value                                          |
|---------------------|------------------------------------------------|
| `BRQ_TRANSACTIONS`  | Buckaroo transaction key (our `transactionId`) |
| `BRQ_INVOICENUMBER` | Your `Invoice` reference                       |
| `BRQ_STATUSCODE`    | Numeric status code (informational only)       |

We always call the API for the authoritative status and do not rely on the return URL params.

### Status codes

| Code    | Meaning                               | Maps to                   |
|---------|---------------------------------------|---------------------------|
| 190     | Success — payment completed           | `PaymentStatus::Paid`     |
| 790–793 | Pending — awaiting processor/consumer | `PaymentStatus::Pending`  |
| 890     | Cancelled by consumer                 | `PaymentStatus::Canceled` |
| 490–492 | Failure                               | `PaymentStatus::Failed`   |
| 690     | Rejected by Buckaroo or acquirer      | `PaymentStatus::Failed`   |

## Usage

### Initiating a payment

```php
use Quellabs\Payments\Contracts\PaymentInterface;
use Quellabs\Canvas\Controllers\BaseController;
use Quellabs\Payments\Contracts\PaymentRequest;
use Quellabs\Payments\Contracts\PaymentInitiationException;

class CheckoutController extends BaseController {

    public function __construct(private PaymentInterface $router) {}

    /**
     * @Route("...")
     */
    public function checkout(): Response {
        $request = new PaymentRequest(
            paymentModule: 'bkr_ideal',
            amount:        999,   // in minor units — €9.99
            currency:      'EUR',
            description:   'Order #12345',
            reference:     'ORDER-12345',
            issuerId:      'INGBNL2A',
        );

        try {
            $result = $this->router->initiate($request);
            return $this->redirect($result->redirectUrl);
        } catch (PaymentInitiationException $e) {
            // handle error
        }
    }
}
```

### Handling refunds

```php
use Quellabs\Payments\Contracts\RefundRequest;
use Quellabs\Payments\Contracts\PaymentRefundException;

// Full refund (omit amount)
$request = new RefundRequest(
    paymentReference: $state->transactionId,
    paymentModule: 'bkr_ideal',
    amount:        null, // null = full refund
    currency:      'EUR',
    description:   'Full refund for order #12345',
);

// Partial refund
$request = new RefundRequest(
    paymentReference: $state->transactionId,
    paymentModule:    'bkr_ideal',
    amount:           500, // in minor units — €5.00
    currency:         'EUR',
    description:      'Partial refund for order #12345',
);

try {
    $result = $this->router->refund($request);
    echo $result->refundId;   // Buckaroo refund transaction Key
} catch (PaymentRefundException $e) {
    // handle error
}
```

### Listening for payment state changes

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
            PaymentStatus::Paid     => $this->markPaid($state->transactionId, $state->valuePaid),
            PaymentStatus::Canceled => $this->markCanceled($state->transactionId),
            PaymentStatus::Refunded => $this->handleRefund($state),
            PaymentStatus::Failed   => $this->markFailed($state->transactionId),
            default                 => null,
        };
    }
}
```

## License

MIT