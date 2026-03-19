# MultiSafepay Payment Provider

A MultiSafepay payment provider for the Canvas framework. Part of the Canvas payments ecosystem.

## Installation

```bash
composer require quellabs/canvas-payments-multisafepay
```

## Architecture

This package sits between the MultiSafepay API and your application. Your application only ever touches the contracts
layer —
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
MultiSafepay                (this package — implements the interface)
      │
      ▼
MultiSafepayGateway         (raw MultiSafepay API calls)
```

Webhook processing is decoupled from your application via signals. When MultiSafepay calls the notification URL,
the package emits a `payment_exchange` signal carrying a `PaymentState`. Your application listens for that signal
and handles it.

## Configuration

Create `config/multisafepay.php` in your Canvas application:

```php
return [
    'api_key'           => '',
    'test_mode'         => false,
    'notification_url'  => 'https://example.com/webhooks/multisafepay',
    'return_url'        => 'https://example.com/order/thankyou',
    'cancel_return_url' => 'https://example.com/order/cancelled',
    'default_country'   => 'NL',
    'default_currency'  => 'EUR',
    'default_locale'    => 'nl_NL',
];
```

| Key                 | Required | Description                                                                                     |
|---------------------|----------|-------------------------------------------------------------------------------------------------|
| `api_key`           | Yes      | Your MultiSafepay API key (test or live), found in your MSP dashboard under Settings > API keys |
| `test_mode`         | No       | Use the MSP test environment (`testapi.multisafepay.com`). Defaults to `false`                  |
| `notification_url`  | Yes      | Full URL MultiSafepay POSTs status updates to. Registered as a route by the package.            |
| `return_url`        | Yes      | URL the customer is redirected to after the package handles a completed or pending payment      |
| `cancel_return_url` | Yes      | URL the customer is redirected to after the package handles a cancelled payment                 |
| `default_country`   | No       | ISO 3166-1 alpha-2 country code used when fetching payment options. Defaults to `NL`            |
| `default_currency`  | No       | ISO 4217 currency code used when fetching payment options. Defaults to `EUR`                    |
| `default_locale`    | No       | Locale passed to the hosted payment page for language and formatting. Defaults to `nl_NL`       |

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
            paymentModule: 'msp_ideal',
            amount:        999,   // in minor units — €9.99
            currency:      'EUR',
            description:   'Order #12345',
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

The `transactionId` from `PaymentState` is used directly as `RefundRequest::$transactionId`. Omitting `amount`
triggers a full refund; passing an amount triggers a partial refund.

```php
use Quellabs\Payments\Contracts\RefundRequest;
use Quellabs\Payments\Contracts\PaymentRefundException;

// Full refund
$request = new RefundRequest(
    transactionId: $state->transactionId,  // from your payment_exchange listener
    paymentModule: 'msp_ideal',
    amount:        null,   // null = full refund
    currency:      'EUR',
    note:          'Full refund for order #12345',
);

// Partial refund
$request = new RefundRequest(
    transactionId: $state->transactionId,  // from your payment_exchange listener
    paymentModule: 'msp_ideal',
    amount:        500,   // in minor units — €5.00
    currency:      'EUR',
    note:          'Partial refund for order #12345',
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
            PaymentStatus::Refunded => $this->handleRefund($state),
            PaymentStatus::Failed   => $this->markFailed($state->transactionId),
            default                 => null,
        };
    }
}
```

## License

MIT