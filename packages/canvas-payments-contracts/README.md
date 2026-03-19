# Canvas Payments Contracts

Shared contracts for the Canvas payments ecosystem. Contains the interfaces and value objects that connect payment  provider packages to your application.

## Installation

```bash
composer require quellabs/canvas-payments-contracts
```

## What's in this package

| Class / Interface            | Description                                                  |
|------------------------------|--------------------------------------------------------------|
| `PaymentInterface`           | Interface for application code — exposes `initiate`, `refund`, `getRefunds`, `getPaymentOptions` |
| `PaymentProviderInterface`   | Interface all driver packages must implement — extends `PaymentInterface`, adds discovery methods |
| `PaymentRequest`             | Input for initiating a payment                               |
| `InitiateResult`             | Result of a successful payment initiation                    |
| `PaymentState`               | Payment snapshot delivered via the `payment_exchange` signal |
| `PaymentStatus`              | Enum of possible payment states                              |
| `RefundRequest`              | Input for issuing a refund                                   |
| `RefundResult`               | Result of a successful refund                                |
| `PaymentAddress`             | Billing or shipping address attached to a payment            |
| `PaymentException`           | Base exception for all payment failures                      |
| `PaymentInitiationException` | Thrown when `initiate()` fails                               |
| `PaymentRefundException`     | Thrown when `refund()` or `getRefunds()` fails               |
| `PaymentExchangeException`   | Thrown internally by the controller layer                    |

All amounts are in minor units (e.g. `999` for €9.99). All classes are in the `Quellabs\Payments\Contracts` namespace.

## Usage

```php
use Quellabs\Payments\Contracts\PaymentInterface;
use Quellabs\Payments\Contracts\PaymentRequest;
use Quellabs\Payments\Contracts\PaymentInitiationException;

class CheckoutService {
    public function __construct(private PaymentInterface $payment) {}

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

## PaymentState

`PaymentState` is an immutable snapshot of a payment's current status. Your application receives it by subscribing
to the `payment_exchange` signal, which is emitted by the controller layer after a return URL visit or webhook.

| Property         | Type            | Description                                                   |
|------------------|-----------------|---------------------------------------------------------------|
| `$provider`      | `string`        | Provider identifier, e.g. `'mollie'`, `'paypal'`              |
| `$transactionId` | `string`        | Provider's unique identifier for this payment                 |
| `$state`         | `PaymentStatus` | Normalised payment status                                     |
| `$currency`      | `string`        | ISO 4217 currency code, e.g. `'EUR'`                          |
| `$valuePaid`     | `int`           | Amount actually captured, in minor units. `0` if not yet paid |
| `$valueRefunded` | `int`           | Total amount refunded so far, in minor units                  |
| `$internalState` | `?string`       | Raw status string from the provider, before normalisation     |
| `$metadata`      | `array`         | Provider-specific data, e.g. `captureId` required for refunds |

`$valuePaid` is only non-zero when `$state` is `PaymentStatus::Paid` or `PaymentStatus::Refunded`. For all other
states — including `Pending` — it is `0`. Use `$state` to determine whether funds have actually moved, and
`$valuePaid` to know how much.

To issue a refund after a successful payment, persist `$metadata['captureId']` from the `PaymentState` — it is
required as `RefundRequest::$transactionId`.

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
} catch (PaymentInitiationException $e) { ... }  // initiate() failed
} catch (PaymentRefundException $e) { ... }      // refund() or getRefunds() failed
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