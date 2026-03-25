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

| Key                 | Required | Description                                                                         |
|---------------------|----------|-------------------------------------------------------------------------------------|
| `api_key`           | Yes      | Your XPay API key. Generate in Back Office under Admin > APIKey > Add new APIKey.   |
| `return_url`        | Yes      | URL the shopper is redirected to after a completed or pending payment               |
| `return_url_cancel` | Yes      | URL the shopper is redirected to after cancelling payment                           |
| `return_url_error`  | No       | URL for technical errors during payment. Falls back to `return_url_cancel` if empty |
| `webhook_url`       | Yes      | Full URL XPay POSTs push notifications to. Must be publicly reachable               |
| `default_language`  | No       | 3-letter language code for the hosted page (ENG, ITA, DEU, FRA…). Defaults to `ENG` |

## Payment modules

| Module             | Description                                 |
|--------------------|---------------------------------------------|
| `xpay`             | All payment methods enabled on the terminal |
| `xpay_cards`       | Credit/debit cards only                     |
| `xpay_applepay`    | Apple Pay                                   |
| `xpay_googlepay`   | Google Pay                                  |
| `xpay_paypal`      | PayPal                                      |
| `xpay_mybank`      | MyBank (EU bank transfers)                  |
| `xpay_bancomatpay` | Bancomat Pay (Italian debit scheme)         |
| `xpay_klarna`      | Klarna                                      |
| `xpay_alipay`      | Alipay                                      |
| `xpay_wechatpay`   | WeChat Pay                                  |

Payment methods must be enabled on your terminal in the XPay Back Office before use.

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
            amount:        999,         // in minor units — €9.99
            currency:      'EUR',
            description:   'Order #12345',
            reference:     'ORDER-12345',
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
    paymentModule:    'xpay_cards',
    amount:           null,
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