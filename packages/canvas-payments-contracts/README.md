# Canvas Payments Contracts

Shared contracts for the Canvas payments ecosystem. Contains the interfaces and value objects that connect payment provider packages to your application.

## Installation

```bash
composer require quellabs/canvas-payments-contracts
```

## What's in this package

| Class / Interface            | Description                                                  |
|------------------------------|--------------------------------------------------------------|
| `PaymentProviderInterface`   | Interface all payment provider packages must implement       |
| `PaymentRequest`             | Input for initiating a payment                               |
| `PaymentResponse`            | Wrapper returned by every provider method                    |
| `InitiateResponse`           | Payload inside `PaymentResponse` after a successful initiate |
| `PaymentState`               | Current state of a payment, returned by `exchange()`         |
| `PaymentStatus`              | Enum of possible payment states                              |
| `RefundRequest`              | Input for issuing a refund                                   |
| `RefundResult`               | Payload inside `PaymentResponse` after a successful refund   |
| `PaymentAddress`             | Billing or shipping address attached to a payment            |

All amounts are in minor units (e.g. `999` for €9.99). All classes are in the `Quellabs\Payments\Contracts` namespace.

## Usage

Your application depends only on this package — never on a specific provider package directly:

```php
use Quellabs\Payments\Contracts\PaymentProviderInterface;
use Quellabs\Payments\Contracts\PaymentRequest;

class CheckoutService {
    public function __construct(private PaymentProviderInterface $payment) {}

    public function pay(): string {
        $response = $this->payment->initiate(new PaymentRequest(
            paymentModule: 'mollie_ideal',
            amount:        999,
            currency:      'EUR',
            description:   'Order #12345',
        ));

        return $response->response->redirectUrl;
    }
}
```

## PaymentStatus values

| Case                      | Description                                    |
|---------------------------|------------------------------------------------|
| `PaymentStatus::Pending`  | Payment is open or pending                     |
| `PaymentStatus::Paid`     | Payment completed successfully                 |
| `PaymentStatus::Canceled` | Customer canceled — definitive                 |
| `PaymentStatus::Expired`  | Customer abandoned, or bank transfer timed out |
| `PaymentStatus::Failed`   | Payment failed and cannot be retried           |
| `PaymentStatus::Refunded` | Payment was refunded                           |
| `PaymentStatus::Unknown`  | Unrecognised status from provider              |

## License

MIT