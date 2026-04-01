# Canvas Shipments

Shipment router for the Canvas framework. Discovers installed shipping provider packages automatically via composer
metadata and routes shipment operations to the correct provider.

## Installation

```bash
composer require quellabs/canvas-shipments
```

## How it works

`ShipmentRouter` scans installed packages for composer metadata declaring a `provider` class under the `shipments`
discovery key. Any package that declares one and implements `ShipmentProviderInterface` is registered automatically — no
manual configuration required.

The provider class must implement a static `getMetadata()` method returning a `modules` array. Each entry becomes a
routable module identifier.

```json
"extra": {
"discover": {
"canvas": {
"controller": "Quellabs\\Shipments\\SendCloud\\SendCloudController"
},
"shipments": {
"provider": "Quellabs\\Shipments\\SendCloud\\Driver",
"config": "config/sendcloud.php"
}
}
}
```

At runtime, `ShipmentRouter` uses the `shippingModule` field on the request to route calls to the correct provider.

## Usage

Inject `ShipmentRouter` via Canvas DI:

```php
use Quellabs\Shipments\ShipmentRouter;
use Quellabs\Shipments\Contracts\ShipmentRequest;
use Quellabs\Shipments\Contracts\ShipmentAddress;
use Quellabs\Shipments\Contracts\CancelRequest;

class FulfillmentService {
    public function __construct(private ShipmentRouter $router) {}

    public function ship(): ShipmentResult {
        return $this->router->create(new ShipmentRequest(
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
    }
}
```

## Available methods

| Method                                       | Description                                                      |
|----------------------------------------------|------------------------------------------------------------------|
| `create(ShipmentRequest)`                    | Create a parcel, returns tracking code and label URL             |
| `cancel(CancelRequest)`                      | Cancel a parcel before carrier pickup                            |
| `exchange(string $driver, string $parcelId)` | Fetch current shipment state (call to reconcile missed webhooks) |
| `getShippingOptions(string $module)`         | Fetch available shipping methods for a module                    |
| `getRegisteredModules()`                     | Returns all discovered module identifiers                        |

## Requirements

- PHP 8.2+
- Quellabs Canvas framework

## License

MIT