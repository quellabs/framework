# Klarna Payment Provider

A Klarna payment provider for the Canvas framework. Part of the Canvas payments ecosystem.

## Installation

```bash
composer require quellabs/canvas-payments-klarna
```

## Architecture

This package sits between the Klarna API and your application. Your application only ever touches the contracts
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
Klarna                          (this package — implements the interface)
      │
      ▼
KlarnaGateway                   (raw Klarna API calls)
```

Exchange processing is decoupled from your application via signals. When the buyer returns from the Klarna hosted
page, the package emits a `payment_exchange` signal carrying a `PaymentState`. Your application listens for that
signal and handles it.

## Configuration

Create `config/klarna.php` in your Canvas application:

```php
return [
    'api_username'      => '',               // Klarna API username from merchant portal
    'api_password'      => '',               // Klarna API password from merchant portal
    'test_mode'         => false,            // true → Playground, false → production
    'return_url'        => 'https://example.com/order/thankyou',
    'cancel_return_url' => 'https://example.com/order/cancelled',
    'default_currency'  => 'EUR',
    'default_country'   => 'NL',
    'locale'            => 'nl-NL',
    'place_order_mode'  => 'CAPTURE_ORDER',
];
```

| Key                 | Required | Description                                                                                       |
|---------------------|----------|---------------------------------------------------------------------------------------------------|
| `api_username`      | Yes      | Username from Klarna merchant portal under Settings > API Credentials                            |
| `api_password`      | Yes      | Password from Klarna merchant portal under Settings > API Credentials                            |
| `test_mode`         | No       | Routes to the Klarna Playground environment when `true`. Defaults to `false`                     |
| `return_url`        | Yes      | Buyer is redirected here after a completed payment                                                |
| `cancel_return_url` | Yes      | Buyer is redirected here after a cancelled, failed, or errored payment                           |
| `default_currency`  | No       | ISO 4217 currency code used when none is specified on the request. Defaults to `EUR`             |
| `default_country`   | No       | ISO 3166-1 alpha-2 country code used when no billing address is provided. Defaults to `NL`      |
| `locale`            | No       | BCP 47 locale for the Klarna checkout UI. Defaults to `nl-NL`                                    |
| `place_order_mode`  | No       | `CAPTURE_ORDER` for digital goods, `PLACE_ORDER` for physical goods. Defaults to `CAPTURE_ORDER` |

`CAPTURE_ORDER` means Klarna captures payment automatically on authorisation — no further action needed.
`PLACE_ORDER` means you must manually capture via the Klarna Order Management API after shipping.

## Important: storing the order reference

The Klarna `order_id` is delivered via the `payment_exchange` signal in `PaymentState::$metadata['paymentReference']`.
**Store this against your order** — it is required for refund calls and for reconciliation.

## Supported payment modules

| Module name       | Description                                                   |
|-------------------|---------------------------------------------------------------|
| `klarna`          | All Klarna payment methods — Klarna selects based on context  |
| `klarna_paynow`   | Pay now — immediate payment                                   |
| `klarna_paylater` | Pay later — invoice, pay after delivery                       |
| `klarna_sliceit`  | Slice it — installment financing                              |

Available payment options are determined by Klarna at checkout based on the buyer's country, order amount,
and risk assessment. There is no issuer pre-selection.

## Usage

### Initiating a payment

```php
use Quellabs\Payments\Contracts\PaymentInterface;
use Quellabs\Canvas\Controllers\BaseController;
use Quellabs\Payments\Contracts\PaymentRequest;
use Quellabs\Payments\Contracts\PaymentAddress;
use Quellabs\Payments\Contracts\PaymentInitiationException;

class CheckoutController extends BaseController {

    public function __construct(private PaymentInterface $router) {}

    /**
     * @Route("...")
     */
    public function checkout(): Response {
        $request = new PaymentRequest(
            paymentModule: 'klarna',
            amount:        999,   // in minor units — €9.99
            currency:      'EUR',
            description:   'Order #12345',
            billingAddress: new PaymentAddress(
                street:      'Damrak',
                houseNumber: '1',
                postalCode:  '1012LG',
                city:        'Amsterdam',
                country:     'NL',
                givenName:   'Jan',
                familyName:  'de Vries',
                email:       'jan@example.com',
            ),
        );

        try {
            $result = $this->router->initiate($request);
            $this->orderService->setTransactionId($orderId, $result->transactionId);
            return $this->redirect($result->redirectUrl);
        } catch (PaymentInitiationException $e) {
            // handle error
        }
    }
}
```

Providing a billing address is strongly recommended — Klarna uses it to pre-fill the checkout page
and improve the authorisation rate.

### Handling refunds

```php
use Quellabs\Payments\Contracts\RefundRequest;
use Quellabs\Payments\Contracts\PaymentRefundException;

// Full refund — pass null as amount
$request = new RefundRequest(
    paymentReference: $state->metadata['paymentReference'],
    paymentModule:    'klarna',
    amount:           null,                    // null = full refund
    currency:         'EUR',
    description:      'Full refund for order #12345',
);

// Partial refund — provide amount in minor units (cents)
$request = new RefundRequest(
    paymentReference: $state->transactionId,
    paymentModule:    'klarna',
    amount:           500,                     // €5.00
    currency:         'EUR',
    description:      'Partial refund for order #12345',
);

try {
    $result = $this->router->refund($request);
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
            PaymentStatus::Expired  => $this->markExpired($state->transactionId),
            default                 => null,
        };
    }
}
```

## Reconciliation

If the buyer closes the browser before being redirected back, your order may remain in a pending state.
Implement a reconciliation job for orders stuck in pending beyond a reasonable threshold (e.g. 15 minutes):

```php
// Pass the order_id stored from metadata['paymentReference'] on the first successful exchange
$state = $this->router->exchange('klarna', $order->paymentReference);
$this->onPaymentExchange($state);
```

## License

MIT