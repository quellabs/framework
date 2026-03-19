# Adyen Payment Provider

An Adyen payment provider for the Canvas framework. Part of the Canvas payments ecosystem.

## Installation

```bash
composer require quellabs/canvas-payments-adyen
```

## Architecture

This package sits between the Adyen API and your application. Your application only ever touches the contracts layer —
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
Adyen                       (this package — implements the interface)
      │
      ▼
AdyenGateway                (raw Adyen API calls)
```

Notification processing is decoupled from your application via signals. When Adyen sends a notification, the package
emits a `payment_exchange` signal carrying a `PaymentState`. Your application listens for that signal and handles it.

## Configuration

Create `config/adyen.php` in your Canvas application:

```php
return [
    'test_mode'            => true,
    'api_key'              => '',
    'merchant_account'     => '',
    'hmac_key'             => '',
    'live_endpoint_prefix' => '',   // required when test_mode is false
    'return_url'           => 'https://example.com/order/thankyou',
    'cancel_return_url'    => 'https://example.com/order/cancelled',
    'webhook_url'          => 'https://example.com/webhooks/adyen',
    'default_country'      => 'NL',
    'default_currency'     => 'EUR',
];
```

| Key                     | Required | Description                                                                                                                   |
|-------------------------|----------|-------------------------------------------------------------------------------------------------------------------------------|
| `test_mode`             | No       | Enable Adyen test environment. Defaults to `false`                                                                            |
| `api_key`               | Yes      | Your Adyen API key. Found in Customer Area under Developers → API credentials. Use a test credential when `test_mode` is `true` |
| `merchant_account`      | Yes      | The Adyen merchant account name (not the company account). Found next to the account switcher in Customer Area                |
| `hmac_key`              | Yes      | HMAC key for verifying incoming webhook signatures. Generated per webhook under Developers → Webhooks → Edit webhook          |
| `live_endpoint_prefix`  | No       | Required when `test_mode` is `false`. Found in Customer Area under Developers → API URLs. Format: `<random>-<merchantAccount>` |
| `return_url`            | Yes      | URL the customer is redirected to after the package handles the return                                                        |
| `cancel_return_url`     | Yes      | URL the customer is redirected to after the package handles the cancel                                                        |
| `webhook_url`           | Yes      | Full URL Adyen POSTs webhook notifications to. Must be publicly reachable. Configure under Developers → Webhooks              |
| `default_country`       | No       | ISO 3166-1 alpha-2 country code used when calling `getPaymentOptions()` without a transaction context (e.g. `'NL'`)          |
| `default_currency`      | No       | ISO 4217 currency code used when calling `getPaymentOptions()` without a transaction context (e.g. `'EUR'`)                   |

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
            paymentModule: 'adyen_ideal',
            amount:        999,   // in minor units — €9.99
            currency:      'EUR',
            description:   'Order #12345',
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

Adyen refunds reference the `pspReference` from the original payment, which is exposed as `transactionId` on
`PaymentState`. No additional metadata needs to be persisted — the `transactionId` from your `payment_exchange`
listener is sufficient.

```php
use Quellabs\Payments\Contracts\RefundRequest;
use Quellabs\Payments\Contracts\PaymentRefundException;

// Full refund
$request = new RefundRequest(
    transactionId: $state->transactionId,  // from your payment_exchange listener
    paymentModule: 'adyen_ideal',
    amount:        null,   // null = full refund
    currency:      'EUR',
    description:   'Full refund for order #12345',
);

// Partial refund
$request = new RefundRequest(
    transactionId: $state->transactionId,  // from your payment_exchange listener
    paymentModule: 'adyen_ideal',
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

Note: Adyen processes refunds asynchronously. The `refundId` in the result is a `pspReference` for the refund
request itself. The final `REFUND` notification arrives separately and triggers a `payment_exchange` signal
with `PaymentStatus::Refunded`.

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
            PaymentStatus::Paid      => $this->markPaid($state->transactionId, $state->valuePaid),
            PaymentStatus::Canceled  => $this->markCanceled($state->transactionId),
            PaymentStatus::Expired   => $this->markExpired($state->transactionId),
            PaymentStatus::Refunded  => $this->handleRefund($state),
            default                  => null,
        };
    }
}
```

## License

MIT