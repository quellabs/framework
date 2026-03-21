# Rabo Smart Pay Payment Provider

A Rabo Smart Pay (OmniKassa v2) payment provider for the Canvas framework. Part of the Canvas payments ecosystem.

## Installation

```bash
composer require quellabs/canvas-payments-rabosmartpay
```

## Architecture

This package sits between the Rabo Smart Pay API and your application. Your application only ever touches the contracts
layer — it never depends on this package directly. `PaymentRouter` (from `quellabs/canvas-payments`) discovers this
package automatically via composer metadata and routes payment calls to it.

```
Your Application
      │
      ▼
PaymentRouter                   (quellabs/canvas-payments — discovery + routing)
      │
      ▼
PaymentInterface                (quellabs/canvas-payments-contracts)
      │
      ▼
Rabo Smart Pay                  (this package — implements the interface)
      │
      ▼
RaboSmartPayGateway             (raw Rabo Smart Pay OmniKassa API calls)
```

Exchange processing is decoupled from your application via signals. When Rabo Smart Pay calls the webhook URL,
the package emits a `payment_exchange` signal carrying a `PaymentState`. Your application listens for that signal
and handles it.

### Payment flow

```
1. Refresh call     GET  /gatekeeper/refresh          → access token
2. Order announce   POST /order/server/api/v2/order   → redirectUrl + omnikassaOrderId
3. Shopper redirected to Rabo Smart Pay hosted checkout
4. Shopper completes payment and is redirected to merchantReturnURL
5. Rabo Smart Pay POSTs webhook notification → your handleWebhook endpoint
6. Status Pull      GET  /order/server/api/events/results/merchant.order.status.changed
```

## Configuration

Create `config/rabosmartpay.php` in your Canvas application:

```php
return [
    'refresh_token'     => '',      // Long-lived token from Rabo Smart Pay dashboard
    'signing_key'       => '',      // Base64-encoded signing key from dashboard
    'test_mode'         => false,   // true → sandbox, false → production
    'return_url'        => 'https://example.com/order/thankyou',
    'cancel_return_url' => 'https://example.com/order/cancelled',
    'default_currency'  => 'EUR',
    'language'          => 'NL',
    'skip_result_page'  => true,
];
```

| Key                 | Required | Description                                                                                  |
|---------------------|----------|----------------------------------------------------------------------------------------------|
| `refresh_token`     | Yes      | Long-lived token from the Rabo Smart Pay dashboard (webshop settings)                        |
| `signing_key`       | Yes      | Base64-encoded HMAC signing key from the Rabo Smart Pay dashboard                           |
| `test_mode`         | No       | Routes to sandbox (`betalen.rabobank.nl`) when `true`. Defaults to `false`                  |
| `return_url`        | Yes      | Shopper is redirected here after a completed or pending payment                              |
| `cancel_return_url` | Yes      | Shopper is redirected here after a cancelled or failed payment                               |
| `default_currency`  | No       | ISO 4217 currency code. Only `EUR` is supported. Defaults to `EUR`                          |
| `language`          | No       | Language for the hosted checkout page. `NL`, `EN`, `FR`, `DE`. Defaults to `NL`            |
| `skip_result_page`  | No       | Skip Rabo Smart Pay's own confirmation page, redirect directly to `return_url`. Default `true` |

## Important: storing the paymentReference

When Rabo Smart Pay redirects the shopper back to your `return_url`, the `?order_id=` parameter contains
your **merchantOrderId** (the `reference` field from `PaymentRequest`), not Rabo Smart Pay's internal UUID.

Rabo Smart Pay's UUID is returned in `InitiateResult::$transactionId` and stored in `metadata['paymentReference']`
at initiation time. You must store this against your order so that you can use it for refund calls and
reconciliation.

The webhook handler receives the UUID via the Status Pull response and uses it as the canonical
`transactionId` in the emitted `PaymentState`.

## Supported payment methods

| Module name        | Brand string   | Method                           |
|--------------------|----------------|----------------------------------|
| `rabo_ideal`       | `IDEAL`        | iDEAL 2.0 (NL)                  |
| `rabo_bancontact`  | `BANCONTACT`   | Bancontact (BE)                  |
| `rabo_mastercard`  | `MASTERCARD`   | Mastercard                       |
| `rabo_visa`        | `VISA`         | Visa                             |
| `rabo_maestro`     | `MAESTRO`      | Maestro                          |
| `rabo_vpay`        | `V_PAY`        | V PAY                            |
| `rabo_cards`       | `CARDS`        | All card methods combined        |
| `rabo_applepay`    | `APPLE_PAY`    | Apple Pay                        |
| `rabo_paypal`      | `PAYPAL`       | PayPal (contract-dependent)      |

Payment methods must be activated for your webshop in the Rabo Smart Pay dashboard before use.

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
            paymentModule: 'rabo_ideal',
            amount:        999,   // in minor units — €9.99
            currency:      'EUR',
            description:   'Order #12345',
            metadata:      ['order_id' => 'order-12345'],  // becomes merchantOrderId; max 24 alphanumeric chars
        );

        try {
            $result = $this->router->initiate($request);

            // Store $result->transactionId (Rabo Smart Pay's UUID) as the paymentReference.
            // You need it for refunds and reconciliation.
            $this->orderService->setPaymentReference($orderId, $result->transactionId);

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
    paymentReference: $state->transactionId,   // omnikassaOrderId from payment_exchange signal
    paymentModule:    'rabo_ideal',
    amount:           null,                    // null = full refund
    currency:         'EUR',
    description:      'Full refund for order #12345',
);

// Partial refund — provide amount in minor units (cents)
$request = new RefundRequest(
    paymentReference: $state->transactionId,
    paymentModule:    'rabo_ideal',
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
            PaymentStatus::Failed   => $this->markFailed($state->transactionId),
            PaymentStatus::Pending  => null, // iDEAL confirmation still in progress — wait for webhook
            default                 => null,
        };
    }
}
```

## Webhook setup

Configure your webhook URL in the Rabo Smart Pay dashboard under your webshop settings:

```
https://example.com/webhooks/rabosmartpay
```

The endpoint must be publicly reachable. Rabo Smart Pay does **not** retry failed webhook deliveries —
return HTTP 200 to acknowledge receipt. Errors are logged server-side.

### Webhook flow

1. Rabo Smart Pay POSTs a notification JSON containing an `authentication` token.
2. This package verifies the HMAC-SHA512 signature on the notification body.
3. The `authentication` token is used to perform a Status Pull call.
4. The Status Pull may return multiple order results and indicate `moreOrderResultsAvailable`.
5. Each order result is mapped to a `PaymentState` and emitted via the `payment_exchange` signal.

## Return URL parameters

Rabo Smart Pay appends the following to your `return_url`:

| Parameter   | Description                                                      |
|-------------|------------------------------------------------------------------|
| `order_id`  | Your `merchantOrderId` (the `reference` from `PaymentRequest`)   |
| `status`    | `IN_PROGRESS`, `COMPLETED`, `CANCELLED`, `EXPIRED`, or `FAILURE` |
| `signature` | HMAC-SHA512 hex signature of `"{order_id},{status}"`             |

The return URL handler verifies the signature and emits a `payment_exchange` signal. For `IN_PROGRESS`
statuses (common with iDEAL 2.0), the shopper is redirected to the success page and the final status
arrives via the webhook.

## License

MIT