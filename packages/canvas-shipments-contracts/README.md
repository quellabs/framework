# Canvas Shipments Contracts

Shared contracts for the Canvas shipments ecosystem. Contains the interfaces and value objects that connect shipping
provider packages to your application.

## Installation

```bash
composer require quellabs/canvas-shipments-contracts
```

## What's in this package

| Class / Interface               | Description                                                                                                       |
|---------------------------------|-------------------------------------------------------------------------------------------------------------------|
| `ShipmentInterface`             | Interface for application code — exposes `create`, `cancel`, `getShippingOptions`                                 |
| `ShipmentProviderInterface`     | Interface all driver packages must implement — extends `ShipmentInterface`, adds `exchange` and discovery methods |
| `ShipmentRequest`               | Input for creating a shipment                                                                                     |
| `ShipmentAddress`               | Recipient address attached to a shipment request                                                                  |
| `ShipmentResult`                | Result of a successful parcel creation                                                                            |
| `ShipmentState`                 | Shipment snapshot delivered via the `shipment_exchange` signal                                                    |
| `ShipmentStatus`                | Enum of possible shipment states                                                                                  |
| `CancelRequest`                 | Input for cancelling a shipment                                                                                   |
| `CancelResult`                  | Result of a cancellation attempt                                                                                  |
| `ShipmentException`             | Base exception for all shipment failures                                                                          |
| `ShipmentCreationException`     | Thrown when `create()` fails                                                                                      |
| `ShipmentCancellationException` | Thrown when `cancel()` fails                                                                                      |
| `ShipmentExchangeException`     | Thrown when `exchange()` fails                                                                                    |

All classes are in the `Quellabs\Shipments\Contracts` namespace.

## Usage

```php
use Quellabs\Shipments\Contracts\ShipmentInterface;
use Quellabs\Shipments\Contracts\ShipmentRequest;
use Quellabs\Shipments\Contracts\ShipmentAddress;
use Quellabs\Shipments\Contracts\ShipmentCreationException;

class FulfillmentService {
    public function __construct(private ShipmentInterface $shipments) {}

    public function ship(): void {
        try {
            $result = $this->shipments->create(new ShipmentRequest(
                shippingModule:  'sendcloud_postnl',
                reference:       'order-12345',
                deliveryAddress: new ShipmentAddress(
                    name:              'Jan de Vries',
                    street:            'Keizersgracht',
                    houseNumber:       '123',
                    houseNumberSuffix: null,
                    postalCode:        '1015 CJ',
                    city:              'Amsterdam',
                    country:           'NL',
                    email:             'jan@example.com',
                ),
                weightGrams: 1200,
                methodId:    8,
            ));

            // Persist $result->parcelId and $result->provider — needed for exchange() and cancel()
            // $result->trackingUrl is ready to embed in your confirmation email
        } catch (ShipmentCreationException $e) {
            // handle error
        }
    }
}
```

## ShipmentState

`ShipmentState` is an immutable snapshot of a shipment's current status. Your application receives it by subscribing
to the `shipment_exchange` signal, which is emitted by the controller layer after a webhook or manual refresh.

| Property         | Type             | Description                                                  |
|------------------|------------------|--------------------------------------------------------------|
| `$provider`      | `string`         | Provider identifier, e.g. `'sendcloud'`                      |
| `$parcelId`      | `string`         | Provider-assigned parcel ID                                  |
| `$reference`     | `string`         | Your own order reference, echoed back from `ShipmentRequest` |
| `$state`         | `ShipmentStatus` | Normalised shipment status                                   |
| `$trackingCode`  | `?string`        | Carrier-assigned tracking code                               |
| `$trackingUrl`   | `?string`        | Public tracking URL, ready to embed in customer emails       |
| `$statusMessage` | `?string`        | Human-readable status message from the provider              |
| `$internalState` | `string`         | Raw status from the provider, before normalisation           |
| `$metadata`      | `array`          | Provider-specific data, e.g. `carrierId`, `labelUrl`         |

Persist `$parcelId` and `$provider` from `ShipmentResult` at creation time — they are required when calling
`ShipmentRouter::exchange()` or `ShipmentRouter::cancel()` later.

## Exceptions

All exceptions extend `ShipmentException`, which exposes `getDriver(): string` and `getErrorId(): int|string`.
Catch the base class to handle any shipment failure, or catch a specific subclass to handle a particular operation.

```php
use Quellabs\Shipments\Contracts\ShipmentException;
use Quellabs\Shipments\Contracts\ShipmentCreationException;
use Quellabs\Shipments\Contracts\ShipmentCancellationException;
use Quellabs\Shipments\Contracts\ShipmentExchangeException;

// Catch all shipment failures
} catch (ShipmentException $e) {
    $e->getDriver();   // e.g. 'sendcloud'
    $e->getErrorId();  // provider error ID
    $e->getMessage();  // human-readable message
}

// Or catch specific failures
} catch (ShipmentCreationException $e) { ... }     // create() failed
} catch (ShipmentCancellationException $e) { ... } // cancel() failed
} catch (ShipmentExchangeException $e) { ... }     // exchange() failed
```

## ShipmentStatus values

| Case                               | Description                                                            |
|------------------------------------|------------------------------------------------------------------------|
| `ShipmentStatus::Created`          | Parcel record created; label not yet printed                           |
| `ShipmentStatus::LabelPrinted`     | Label generated; ready for carrier pickup or drop-off                  |
| `ShipmentStatus::InTransit`        | Carrier has accepted and is transporting the parcel                    |
| `ShipmentStatus::OutForDelivery`   | Parcel is out for final delivery                                       |
| `ShipmentStatus::Delivered`        | Parcel successfully delivered to the recipient                         |
| `ShipmentStatus::DeliveryFailed`   | Delivery attempt failed; carrier will retry                            |
| `ShipmentStatus::AwaitingPickup`   | Parcel held at a service point for recipient pickup                    |
| `ShipmentStatus::ReturnedToSender` | Parcel returned after failed delivery attempts or explicit return      |
| `ShipmentStatus::Cancelled`        | Parcel cancelled before handover to the carrier                        |
| `ShipmentStatus::Unknown`          | Unrecognised status from provider; see `ShipmentState::$internalState` |

## License

MIT