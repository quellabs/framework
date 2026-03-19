# Mollie Payment Provider

A Mollie payment provider for the Canvas framework. Part of the Canvas payments ecosystem.

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
PaymentInterface            (quellabs/canvas-payments-contracts)
      │
      ▼
Mollie                      (this package — implements the interface)
      │
      ▼
MollieGateway               (raw Mollie API calls)
```

Webhook processing is decoupled from your application via signals. When Mollie calls the webhook, the package emits
a `payment_exchange` signal carrying a `PaymentState`. Your application listens for that signal and handles it.

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

| Key                 | Required | Description                                                                                                |
|---------------------|----------|------------------------------------------------------------------------------------------------------------|
| `api_key`           | Yes      | Your Mollie API key (live or test)                                                                         |
| `test_mode`         | No       | Enable Mollie test mode. Defaults to `false`                                                               |
| `webhook_url`       | Yes      | Full URL Mollie POSTs status updates to. Registered as a route by the package.                             |
| `redirect_url`      | Yes      | Full URL Mollie redirects the customer to after successful checkout. Registered as a route by the package. |
| `cancel_url`        | Yes      | Full URL Mollie redirects the customer to when they cancel. Registered as a route by the package.          |
| `return_url`        | Yes      | URL the customer is redirected to after the package handles the return                                     |
| `cancel_return_url` | Yes      | URL the customer is redirected to after the package handles the cancel                                     |

## Usage

### Initiating a payment

Inject `PaymentInterface` via Canvas DI and call `initiate()`:

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
    public function checkout(): void {
        $request = new PaymentRequest(
            paymentModule: 'mollie_ideal',
            amount:        999,   // in minor units — €9.99
            currency:      'EUR',
            description:   'Order #12345',
            issuerId:      'ideal_INGBNL2A',
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

Unlike PayPal, Mollie does not use a separate capture ID. The `transactionId` from `PaymentState` is used
directly as `RefundRequest::$transactionId`, so no additional metadata needs to be persisted.

```php
use Quellabs\Payments\Contracts\RefundRequest;
use Quellabs\Payments\Contracts\PaymentRefundException;

// Full refund
$request = new RefundRequest(
    transactionId: $state->transactionId,  // from your payment_exchange listener
    paymentModule: 'mollie_ideal',
    amount:        null,   // null = full refund
    currency:      'EUR',
    description:   'Full refund for order #12345',
);

// Partial refund
$request = new RefundRequest(
    transactionId: $state->transactionId,  // from your payment_exchange listener
    paymentModule: 'mollie_ideal',
    amount:        500,   // in minor units — €5.00
    currency:      'EUR',
    description:   'Partial refund for order #12345',
);

try {
    $result = $this->router->refund($request);
    echo $result->refundId;
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
            PaymentStatus::Expired  => $this->markExpired($state->transactionId),
            PaymentStatus::Refunded => $this->handleRefund($state),
            default                 => null,
        };
    }
}
```

## License

MIT