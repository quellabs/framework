# Pay.nl Payment Provider

A Pay.nl payment provider for the Canvas framework. Part of the Canvas payments ecosystem.

## Installation

```bash
composer require quellabs/canvas-payments-paynl
```

## Architecture

This package sits between the Pay.nl API and your application. Your application only ever touches the contracts
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
Pay.nl                      (this package — implements the interface)
      │
      ▼
PayNLGateway                (raw Pay.nl TGU API calls)
```

Exchange processing is decoupled from your application via signals. When Pay.nl calls the exchange URL, the package
emits a `payment_exchange` signal carrying a `PaymentState`. Your application listens for that signal and handles it.

## Configuration

Create `config/paynl.php` in your Canvas application:

```php
return [
    'token_code'        => '',     // AT-xxxx-xxxx
    'api_token'         => '',     // 40-character hash
    'service_id'        => '',     // SL-xxxx-xxxx
    'test_mode'         => false,
    'return_url'        => 'https://example.com/order/thankyou',
    'cancel_return_url' => 'https://example.com/order/cancelled',
    'exchange_url'      => 'https://example.com/webhooks/paynl',
    'default_currency'  => 'EUR',
];
```

| Key                 | Required | Description                                                                             |
|---------------------|----------|-----------------------------------------------------------------------------------------|
| `token_code`        | Yes      | Your Pay.nl token code (`AT-xxxx-xxxx`), found under Merchant → Company information     |
| `api_token`         | Yes      | Your 40-character API token, found under Merchant → Company information                 |
| `service_id`        | Yes      | Your sales location ID (`SL-xxxx-xxxx`), found under Settings → Sales locations         |
| `test_mode`         | No       | Enables Pay.nl test mode per-order (`integration.test: true`). Defaults to `false`      |
| `return_url`        | Yes      | URL the customer is redirected to after the package handles a completed/pending payment |
| `cancel_return_url` | Yes      | URL the customer is redirected to after the package handles a cancelled payment         |
| `exchange_url`      | Yes      | Full URL Pay.nl POSTs exchange notifications to. Must be publicly reachable             |
| `default_currency`  | No       | ISO 4217 currency code. Defaults to `EUR`                                               |

## Supported payment methods

| Module name        | Pay.nl ID | Method                          |
|--------------------|-----------|---------------------------------|
| `paynl_ideal`      | 10        | iDEAL (NL)                      |
| `paynl_bancontact` | 436       | Bancontact (BE)                 |
| `paynl_creditcard` | 706       | Visa + Mastercard (combined)    |
| `paynl_visa`       | 3141      | Visa                            |
| `paynl_mastercard` | 3138      | Mastercard                      |
| `paynl_amex`       | 1705      | American Express                |
| `paynl_applepay`   | 2277      | Apple Pay                       |
| `paynl_googlepay`  | 2558      | Google Pay                      |
| `paynl_klarna`     | 1717      | Klarna                          |
| `paynl_in3`        | 1813      | In3 (NL, EUR only)              |
| `paynl_riverty`    | 2561      | Riverty / AfterPay (NL, BE, DE) |
| `paynl_eps`        | 2062      | EPS (AT)                        |
| `paynl_trustly`    | 2718      | Trustly (EU)                    |
| `paynl_paybybank`  | 2970      | Pay By Bank (PSD2)              |

Payment methods must be activated for your sales location in the Pay.nl admin panel before use.

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
            paymentModule: 'paynl_ideal',
            amount:        999,   // in minor units — €9.99
            currency:      'EUR',
            description:   'Order #12345',
            reference:     'order-12345',
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

// Full refund — omit amount
$request = new RefundRequest(
    paymentReference: $state->transactionId,   // UUID from payment_exchange signal
    paymentModule:    'paynl_ideal',
    amount:           null,                    // null = full refund
    currency:         'EUR',
    description:      'Full refund for order #12345',
);

// Partial refund — provide amount in minor units
$request = new RefundRequest(
    paymentReference: $state->transactionId,
    paymentModule:    'paynl_ideal',
    amount:           500,                     // €5.00
    currency:         'EUR',
    description:      'Partial refund for order #12345',
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