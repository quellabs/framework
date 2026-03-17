# Canvas Payments

Payment router for the Canvas framework. Discovers installed payment provider packages automatically via composer metadata and routes payment operations to the correct provider.

## Installation

```bash
composer require quellabs/canvas-payments
```

## How it works

`PaymentRouter` scans installed packages for composer metadata declaring a `payment_provider` class. Any package that declares one and implements `PaymentProviderInterface` is registered automatically — no manual configuration required.

```json
"extra": {
    "discover": {
        "payments": {
            "payment_provider": "Quellabs\\Payments\\Mollie\\Driver"
        }
    }
}
```

At runtime, `PaymentRouter` uses the `paymentModule` field on the request to route calls to the correct provider.

## Usage

Inject `PaymentRouter` via Canvas DI:

```php
use Quellabs\Payments\PaymentRouter;
use Quellabs\Payments\Contracts\PaymentRequest;
use Quellabs\Payments\Contracts\RefundRequest;

class CheckoutService {
    public function __construct(private PaymentRouter $router) {}

    public function pay(): string {
        $response = $this->router->initiate(new PaymentRequest(
            paymentModule: 'mollie_ideal',
            amount:        999,   // in minor units — €9.99
            currency:      'EUR',
            description:   'Order #12345',
        ));

        if (!$response->success) {
            throw new \RuntimeException($response->errorMessage);
        }

        return $response->response->redirectUrl;
    }
}
```

## Available methods

| Method                              | Description                                              |
|-------------------------------------|----------------------------------------------------------|
| `initiate(PaymentRequest)`          | Start a payment session, returns a redirect URL          |
| `refund(RefundRequest)`             | Issue a refund for a completed payment                   |
| `getPaymentOptions(string $module)` | Fetch available issuers or options for a payment module  |
| `getRegisteredModules()`            | Returns all discovered module identifiers                |

## Requirements

- PHP 8.1+
- Quellabs Canvas framework
- `quellabs/canvas-payments-contracts`

## License

MIT