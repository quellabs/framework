# Canvas Payments

Payment router for the Canvas framework. Discovers installed payment provider packages automatically via composer metadata and routes payment operations to the correct provider.

## Installation

```bash
composer require quellabs/canvas-payments
```

## How it works

`PaymentRouter` scans installed packages for composer metadata declaring a `provider` class under the `payments` discovery key. Any package that declares one and implements `PaymentProviderInterface` is registered automatically — no manual configuration required.

The provider class must implement a static `getMetadata()` method returning a `modules` array. Each entry becomes a routable module identifier.

```json
"extra": {
    "discover": {
        "canvas": {
            "controller": "Quellabs\\Payments\\Mollie\\MollieController"
        },
        "payments": {
            "provider": "Quellabs\\Payments\\Mollie\\Driver",
            "config": "config/mollie.php"
        }
    }
}
```

At runtime, `PaymentRouter` uses the `paymentModule` field on the request to route calls to the correct provider.

## Configuration

After `composer install`, the package publishes a default config file to `config/mollie.php` via a `post-autoload-dump` script. Edit this file to set your API key, webhook URL, redirect URL, and cancel URL.

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

        return $response->redirectUrl;
    }
}
```

## Available methods

| Method                              | Description                                                        |
|-------------------------------------|--------------------------------------------------------------------|
| `initiate(PaymentRequest)`          | Start a payment session, returns redirect URL and transaction ID   |
| `refund(RefundRequest)`             | Issue a refund for a completed payment                             |
| `exchange(string $transactionId)`   | Fetch current payment state (call from webhook handler)            |
| `getRefunds(string $transactionId)` | Returns all refunds for a given transaction                        |
| `getPaymentOptions(string $module)` | Fetch available issuers or options for a payment module            |
| `getRegisteredModules()`            | Returns all discovered module identifiers                          |

## Requirements

- PHP 8.2+
- Quellabs Canvas framework

## License

MIT