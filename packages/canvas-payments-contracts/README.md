# Canvas Payments Contracts

Shared contracts for the Canvas payments ecosystem. Contains the interfaces and value objects that connect payment
provider packages to your application.

## Installation

```bash
composer require quellabs/canvas-payments-contracts
```

## What's in this package

| Class / Interface            | Description                                            |
|------------------------------|--------------------------------------------------------|
| `PaymentProviderInterface`   | Interface all payment provider packages must implement |
| `PaymentRequest`             | Input for initiating a payment                         |
| `InitiateResult`             | Result of a successful payment initiation              |
| `PaymentState`               | Current state of a payment, returned by `exchange()`   |
| `PaymentStatus`              | Enum of possible payment states                        |
| `RefundRequest`              | Input for issuing a refund                             |
| `RefundResult`               | Result of a successful refund                          |
| `PaymentAddress`             | Billing or shipping address attached to a payment      |
| `PaymentException`           | Base exception for all payment failures                |
| `PaymentInitiationException` | Thrown when `initiate()` fails                         |
| `PaymentRefundException`     | Thrown when `refund()` or `getRefunds()` fails         |
| `PaymentExchangeException`   | Thrown when `exchange()` fails                         |

All amounts are in minor units (e.g. `999` for €9.99). All classes are in the `Quellabs\Payments\Contracts` namespace.

## Usage

```php
use Quellabs\Payments\Contracts\PaymentProviderInterface;
use Quellabs\Payments\Contracts\PaymentRequest;
use Quellabs\Payments\Contracts\PaymentInitiationException;

class CheckoutService {
    public function __construct(private PaymentProviderInterface $payment) {}

    public function pay(): string {
        try {
            $result = $this->payment->initiate(new PaymentRequest(
                paymentModule: 'mollie_ideal',
                amount:        999,
                currency:      'EUR',
                description:   'Order #12345',
            ));

            return $result->redirectUrl;
        } catch (PaymentInitiationException $e) {
            // handle error
        }
    }
}
```

## Exceptions

All exceptions extend `PaymentException`, which exposes `getProvider(): string` and `getErrorId(): int`. Catch the base
class to handle any payment failure, or catch a specific subclass to handle a particular operation.

```php
use Quellabs\Payments\Contracts\PaymentException;
use Quellabs\Payments\Contracts\PaymentInitiationException;
use Quellabs\Payments\Contracts\PaymentRefundException;

// Catch all payment failures
} catch (PaymentException $e) {
    $e->getProvider();  // e.g. 'mollie'
    $e->getErrorId();   // provider error ID
    $e->getMessage();   // human-readable message
}

// Or catch specific failures
} catch (PaymentInitiationException $e) { ... }
} catch (PaymentRefundException $e) { ... }
```

## PaymentStatus values

| Case                      | Description                                         |
|---------------------------|-----------------------------------------------------|
| `PaymentStatus::Pending`  | Payment is open or pending                          |
| `PaymentStatus::Paid`     | Payment completed successfully                      |
| `PaymentStatus::Canceled` | Customer canceled — definitive                      |
| `PaymentStatus::Expired`  | Customer abandoned, or bank transfer timed out      |
| `PaymentStatus::Failed`   | Payment failed and cannot be retried                |
| `PaymentStatus::Refunded` | Payment was refunded                                |
| `PaymentStatus::Redirect` | Redirect user back to provider. Internally handled. |
| `PaymentStatus::Unknown`  | Unrecognised status from provider                   |

## License

MIT