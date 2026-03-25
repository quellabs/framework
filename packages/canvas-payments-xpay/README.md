# Nexi XPay Global Payment Provider

A Nexi XPay Global payment provider for the Canvas framework. Part of the Canvas payments ecosystem.

## Installation

```bash
composer require quellabs/canvas-payments-xpay
```

## Architecture

This package sits between the Nexi XPay Global JSON API and your application. Your application only ever touches the
contracts layer — it never depends on this package directly. `PaymentRouter` (from `quellabs/canvas-payments`)
discovers this package automatically via composer metadata and routes payment calls to it.

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
XPay Driver                 (this package — implements the interface)
      │
      ▼
XPayGateway                 (raw XPay JSON API calls)
```

Push processing is decoupled from your application via signals. When XPay posts to the push URL, the package emits a
`payment_exchange` signal carrying a `PaymentState`. Your application listens for that signal and handles it.

## Configuration

Create `config/xpay.php` in your Canvas application (done automatically on first install):

```php
return [
    'api_key'           => '',
    'return_url'        => 'https://example.com/order/thankyou',
    'return_url_cancel' => 'https://example.com/order/cancelled',
    'return_url_error'  => 'https://example.com/order/error',
    'webhook_url'       => 'https://example.com/webhooks/xpay',
    'default_language'  => 'ENG',
];
```

| Key                 | Required | Description                                                                                   |
|---------------------|----------|-----------------------------------------------------------------------------------------------|
| `api_key`           | Yes      | Your XPay API key. Generate in Back Office under Admin > APIKey. Separate keys for test/live. |
| `return_url`        | Yes      | URL the shopper is redirected to after a completed or pending payment                         |
| `return_url_cancel` | Yes      | URL the shopper is redirected to after cancelling payment                                     |
| `return_url_error`  | No       | URL for technical errors during payment. Falls back to `return_url_cancel` if empty           |
| `webhook_url`       | Yes      | Full URL XPay POSTs push notifications to. Must be publicly reachable                         |
| `default_language`  | No       | 3-letter language code for the hosted page (ENG, ITA, DEU, FRA…). Defaults to `ENG`          |

### XPay Back Office configuration

In addition to `config/xpay.php`, configure the following in the XPay Back Office:

- **API Key**: Admin > APIKey > Add new APIKey — select the correct terminal and generate a key
- **Return URLs** are sent per-request in the `paymentSession` block; no Back Office configuration required
- **Push URL** is sent per-request via `notificationUrl`; ensure the URL is publicly reachable

## Key differences from Buckaroo

### Authentication

XPay uses a simple API key in the `X-API-KEY` header — no HMAC signing. Every request also carries a
`Correlation-Id` UUID v4 header for tracing.

```
X-API-KEY: <your-api-key>
Correlation-Id: <uuid-v4>
```

### Amounts

XPay amounts are in the **smallest currency unit** (minor units): `5000` = €50.00, same as the contract convention.
No conversion is needed.

### Transaction identity

XPay uses your `orderId` (our `PaymentRequest::$reference`) as the primary order reference. There is no separate
Buckaroo-style `Key` — the orderId IS the identifier carried through return URLs, push notifications, and the
status API. We store `request->reference` as `transactionId` in `InitiateResult`.

Each payment action (CAPTURE, REFUND, etc.) gets its own XPay-assigned `operationId`. Refunds require the
`operationId` of the original CAPTURE operation, not the orderId — the Driver resolves this automatically.

### Hosted Payment Page

XPay returns a `hostedPage` URL from `POST /orders/hpp`. The `securityToken` also returned should ideally be
stored per-order and verified against the token in the return URL query parameters and push notification body.
This package surfaces the token in `InitiateResult` metadata for application-layer verification.

### Push vs webhook

XPay push sends a **JSON body**:

```json
{
  "eventId":      "554ccc00-28fb-4344-a3fa-4bb8d1999bd5",
  "eventTime":    "2022-09-01T01:20:00.001Z",
  "securityToken": "2f0ea5059b41414ca3744fe672327d85",
  "operation": {
    "orderId":          "ORDER-12345",
    "operationId":      "3470744",
    "operationType":    "CAPTURE",
    "operationResult":  "AUTHORIZED",
    "operationAmount":  "3545",
    "operationCurrency":"EUR"
  }
}
```

The controller reads `operation.orderId` and calls `GET /orders/{orderId}` for the authoritative state.

### Return URL parameters

XPay appends these to the configured `resultUrl`:

| Parameter      | Value                                             |
|----------------|---------------------------------------------------|
| `orderId`      | Your order reference (our `transactionId`)        |
| `operationId`  | XPay-assigned operation identifier                |
| `channel`      | Channel string (e.g. `ECOMMERCE`)                 |
| `securityToken`| Token from the createOrder response               |
| `esito`        | Informational outcome string — **do not trust**   |

We always call the API for the authoritative status and do not rely on the return URL params.

### operationResult to PaymentStatus mapping

| operationResult    | Meaning                                  | Maps to                   |
|--------------------|------------------------------------------|---------------------------|
| `AUTHORIZED`       | Payment captured successfully            | `PaymentStatus::Paid`     |
| `PENDING`          | Awaiting outcome (async methods)         | `PaymentStatus::Pending`  |
| `VOIDED`           | Authorisation reversed before capture    | `PaymentStatus::Canceled` |
| `CANCELLED`        | Order cancelled before payment           | `PaymentStatus::Canceled` |
| `DENIED_BY_RISK`   | Rejected by risk engine                  | `PaymentStatus::Failed`   |
| `THREEDS_VALIDATED`| 3DS passed, awaiting capture             | `PaymentStatus::Pending`  |
| `THREEDS_FAILED`   | 3DS challenge failed                     | `PaymentStatus::Failed`   |
| `FAILED`           | Payment failed at acquirer               | `PaymentStatus::Failed`   |
| `REVERSED`         | Refund processed successfully            | `PaymentStatus::Refunded` |

### Payment modules

| Module            | XPay paymentService | Description                                  |
|-------------------|---------------------|----------------------------------------------|
| `xpay`            | *(omitted)*         | All payment methods enabled on the terminal  |
| `xpay_cards`      | `CARDS`             | Credit/debit cards only                      |
| `xpay_applepay`   | `APPLEPAY`          | Apple Pay                                    |
| `xpay_googlepay`  | `GOOGLEPAY`         | Google Pay                                   |
| `xpay_paypal`     | `PAYPAL`            | PayPal                                       |
| `xpay_mybank`     | `MYBANK`            | MyBank (EU bank transfers)                   |
| `xpay_bancomatpay`| `BANCOMATPAY`       | Bancomat Pay (Italian debit scheme)          |
| `xpay_klarna`     | `KLARNA`            | Klarna                                       |
| `xpay_alipay`     | `ALIPAY`            | Alipay                                       |
| `xpay_wechatpay`  | `WECHATPAY`         | WeChat Pay                                   |

Payment methods must be enabled on your XPay terminal in the Back Office before use.

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
            paymentModule: 'xpay_cards',
            amount:        999,   // in minor units — €9.99
            currency:      'EUR',
            description:   'Order #12345',
            reference:     'ORDER-12345', // up to 27 chars; alphanumeric + # * + - . : ; = ? [ ] _ { | }
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
    paymentReference: $state->transactionId, // the orderId
    paymentModule:    'xpay_cards',
    amount:           null, // null = full refund
    currency:         'EUR',
    description:      'Full refund for order #12345',
);

// Partial refund
$request = new RefundRequest(
    paymentReference: $state->transactionId,
    paymentModule:    'xpay_cards',
    amount:           500, // in minor units — €5.00
    currency:         'EUR',
    description:      'Partial refund for order #12345',
);

try {
    $result = $this->router->refund($request);
    echo $result->refundId; // XPay refund operationId
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