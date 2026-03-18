# PayPal Express Checkout Payment Provider

A PayPal Express Checkout payment provider for the Canvas framework. Part of the Canvas payments ecosystem.

> **Note:** This package implements the legacy PayPal NVP/SOAP API (Express Checkout). This API is functional but
> no longer actively developed by PayPal. A separate package based on the modern PayPal REST API is planned.
> Consider this package for projects that require PayPal support today and are comfortable with the legacy API.

## Installation

```bash
composer require quellabs/canvas-payments-paypal-express
```

## Architecture

This package sits between the PayPal NVP API and your application. Your application only ever touches the contracts
layer — it never depends on this package directly. `PaymentRouter` (from `quellabs/canvas-payments`) discovers this
package automatically via composer metadata and routes payment calls to it.

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
PayPal Express              (this package — implements the interface)
      │
      ▼
PaypalGateway               (raw PayPal NVP API calls)
```

Webhook processing is decoupled from your application via signals. When PayPal sends an IPN notification, the package
emits a `payment_exchange` signal carrying a `PaymentState`. Your application listens for that signal and handles it.
The buyer return URL works the same way — both the return and cancel cases are handled by the same route and emit
the same signal, so your application does not need to distinguish between them at the routing level.

## Configuration

Create `config/paypal.php` in your Canvas application:

```php
return [
    'test_mode'         => true,
    'api_username'      => '',
    'api_password'      => '',
    'api_signature'     => '',
    'verify_ssl'        => true,
    'account_optional'  => true,
    'brand_name'        => '',
    'return_url'        => 'https://example.com/order/thankyou',
    'cancel_return_url' => 'https://example.com/order/cancelled',
    'ipn_url'           => 'https://example.com/webhooks/paypal',
];
```

| Key                 | Required | Description                                                                                                       |
|---------------------|----------|-------------------------------------------------------------------------------------------------------------------|
| `test_mode`         | Yes      | Set to `true` for sandbox, `false` for production                                                                 |
| `api_username`      | Yes      | NVP API username from PayPal account settings                                                                     |
| `api_password`      | Yes      | NVP API password from PayPal account settings                                                                     |
| `api_signature`     | Yes      | NVP API signature from PayPal account settings                                                                    |
| `verify_ssl`        | No       | Whether to verify PayPal's SSL certificate. Always `true` in production. Defaults to `true`                      |
| `account_optional`  | No       | When `true`, buyers can check out as a guest without a PayPal account. Defaults to `true`                        |
| `brand_name`        | No       | Your store name shown on the PayPal checkout page. Leave empty to use your PayPal account name                    |
| `return_url`        | Yes      | URL the customer is redirected to after a completed payment                                                       |
| `cancel_return_url` | Yes      | URL the customer is redirected to after cancelling at PayPal                                                      |
| `ipn_url`           | Yes      | Full URL PayPal POSTs IPN notifications to. Must be publicly accessible — localhost will not work                 |

## Usage

### Initiating a payment

Inject `PaymentRouter` via Canvas DI and call `initiate()`:

```php
use Quellabs\Payments\PaymentRouter;
use Quellabs\Canvas\Controllers\BaseController;
use Quellabs\Payments\Contracts\PaymentRequest;
use Quellabs\Payments\Contracts\PaymentInitiationException;

class CheckoutController extends BaseController {

    public function __construct(private PaymentRouter $router) {}

    /**
     * @Route("...")
     */
    public function checkout(): void {
        $request = new PaymentRequest(
            paymentModule: 'paypal',
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

Pass `amount: null` for a full refund, or a minor-unit integer for a partial refund:

```php
use Quellabs\Payments\Contracts\RefundRequest;
use Quellabs\Payments\Contracts\PaymentRefundException;

// Full refund
$request = new RefundRequest(
    transactionId: 'PAYMENTINFO_0_TRANSACTIONID value stored after exchange()',
    paymentModule: 'paypal',
    amount:        null,   // null = full refund
    currency:      'EUR',
    description:   'Full refund for order #12345',
);

// Partial refund
$request = new RefundRequest(
    transactionId: 'PAYMENTINFO_0_TRANSACTIONID value stored after exchange()',
    paymentModule: 'paypal',
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
            PaymentStatus::Paid      => $this->markPaid($state->transactionId),
            PaymentStatus::Canceled  => $this->markCanceled($state->transactionId),
            PaymentStatus::Failed    => $this->markFailed($state->transactionId),
            PaymentStatus::Redirect  => $this->redirectToPayPal($state->metadata['redirectUrl']),
            default                  => null,
        };
    }
}
```

## PayPal-specific quirks

### Two transaction identifiers

PayPal uses two different identifiers across the payment lifecycle, which is the most important quirk to understand:

- **Checkout token** (`EC-XXXXXXXXX`) — created by `SetExpressCheckout` and returned by `initiate()` as
  `InitiateResult::$transactionId`. Used to drive the checkout flow and passed to `exchange()`.
- **Payment transaction ID** (e.g. `9N123456789`) — created by `DoExpressCheckoutPayment` after the buyer
  completes payment. This is what PayPal's refund and transaction detail APIs operate on.

The payment transaction ID is available in `PaymentState::$metadata['paymentTransactionId']` after a successful
`exchange()` call. **Your application must persist this value** alongside the order — it is required as
`RefundRequest::$transactionId` when issuing refunds.

### IPN vs. return URL

PayPal notifies your application of payment state changes in two independent ways:

- **IPN (Instant Payment Notification)** — a server-to-server POST from PayPal to `ipn_url`. This is the
  authoritative source of truth and may arrive before or after the buyer returns to your site.
- **Return URL** — a browser redirect after the buyer completes or cancels at PayPal.

Both routes call `exchange()` and emit the `payment_exchange` signal. Your application should be idempotent when
handling this signal, as it may fire twice for the same transaction.

### Error 10486 — insufficient funds redirect

If the buyer selects a funding source with insufficient funds, `DoExpressCheckoutPayment` returns error code 10486.
The package handles this transparently by returning a `PaymentState` with `state: PaymentStatus::Redirect` and
`metadata['redirectUrl']` set to the PayPal checkout URL. Your `payment_exchange` listener should handle this case
by redirecting the buyer back to PayPal to choose a different payment method.

### Refund type determination

Pass `amount: null` to `RefundRequest` for a full refund. The driver uses this to call PayPal's `Full` refund type,
which lets PayPal calculate the refundable amount internally. Pass a minor-unit integer for a partial refund.
Do not attempt to calculate the refundable amount yourself and pass it as a full refund — use `null` instead.

## License

MIT