# SendCloud Shipping Provider

A SendCloud shipping provider for the Canvas framework. Part of the Canvas shipments ecosystem.

## Installation

```bash
composer require quellabs/canvas-shipments-sendcloud
```

## Architecture

This package sits between the SendCloud API and your application. Your application only ever touches the contracts
layer — it never depends on this package directly. `ShipmentRouter` (from `quellabs/canvas-shipments`) discovers
this package automatically via composer metadata and routes shipment calls to it.

```
Your Application
      │
      ▼
ShipmentRouter              (quellabs/canvas-shipments — discovery + routing)
      │
      ▼
ShipmentInterface           (quellabs/canvas-shipments-contracts)
      │
      ▼
SendCloud Driver            (this package — implements the interface)
      │
      ▼
SendCloudGateway            (raw SendCloud API calls)
```

Status updates are decoupled from your application via signals. When SendCloud sends a webhook, the package emits
a `shipment_exchange` signal carrying a `ShipmentState`. Your application listens for that signal and handles it.

## Configuration

Create `config/sendcloud.php` in your Canvas application:

```php
return [
    'public_key'     => '',
    'secret_key'     => '',
    'partner_id'     => '',
    'webhook_secret' => '',
    'webhook_url'    => 'https://example.com/webhooks/sendcloud',
    'from_country'   => 'NL',
    'sender_address' => [
        'name'         => 'My Webshop',
        'company_name' => 'My Webshop B.V.',
        'address'      => 'Keizersgracht 123',
        'city'         => 'Amsterdam',
        'postal_code'  => '1015 CJ',
        'country'      => ['iso_2' => 'NL'],
        'email'        => 'logistics@example.com',
        'phone'        => '+31201234567',
    ],
];
```

| Key              | Required | Description                                                                                                                    |
|------------------|----------|--------------------------------------------------------------------------------------------------------------------------------|
| `public_key`     | Yes      | Your SendCloud API public key. Found in your SendCloud panel under Settings → Integrations → API                               |
| `secret_key`     | Yes      | Your SendCloud API secret key. Found alongside the public key in your integration settings                                     |
| `partner_id`     | No       | SendCloud Partner ID for partner analytics. Leave empty if you are not a registered SendCloud partner                          |
| `webhook_secret` | Yes      | Signing secret for verifying incoming webhook signatures. Set in your SendCloud panel under Settings → Integrations → Webhooks |
| `webhook_url`    | Yes      | Full URL SendCloud POSTs parcel status events to. Must be publicly reachable. Configure in your SendCloud panel                |
| `from_country`   | No       | ISO 3166-1 alpha-2 sender country code used to filter available shipping methods. Defaults to `'NL'`                           |
| `sender_address` | No       | Default sender address pre-filled on every parcel. Can be overridden per-request via `ShipmentRequest::$extraData`             |

## Usage

### Creating a shipment

Inject `ShipmentInterface` via Canvas DI and call `create()`:

```php
use Quellabs\Shipments\Contracts\ShipmentInterface;
use Quellabs\Shipments\Contracts\ShipmentAddress;
use Quellabs\Shipments\Contracts\ShipmentRequest;
use Quellabs\Shipments\Contracts\ShipmentCreationException;
use Quellabs\Canvas\Controllers\BaseController;

class OrderController extends BaseController {

    public function __construct(private ShipmentInterface $router) {}

    /**
     * @Route("...")
     */
    public function ship(): void {
        $address = new ShipmentAddress(
            name:        'Jan de Vries',
            street:      'Keizersgracht',
            houseNumber: '123',
            houseNumberSuffix: null,
            postalCode:  '1015 CJ',
            city:        'Amsterdam',
            country:     'NL',
            email:       'jan@example.com',
        );

        $request = new ShipmentRequest(
            shippingModule: 'sendcloud_postnl',
            reference:      'order-12345',
            deliveryAddress: $address,
            weightGrams:    1200,
            methodId:       8,   // SendCloud shipping method ID from getShippingOptions()
        );

        try {
            $result = $this->router->create($request);
            // Persist $result->parcelId and $result->provider — needed for exchange() and cancel()
            // $result->trackingUrl is ready to embed in your confirmation email
        } catch (ShipmentCreationException $e) {
            // handle error
        }
    }
}
```

### Cancelling a shipment

```php
use Quellabs\Shipments\Contracts\CancelRequest;
use Quellabs\Shipments\Contracts\ShipmentCancellationException;

$request = new CancelRequest(
    shippingModule: 'sendcloud_postnl',
    parcelId:       $parcelId,   // ShipmentResult::$parcelId persisted at creation time
    reference:      'order-12345',
);

try {
    $result = $this->router->cancel($request);

    if (!$result->accepted) {
        // Carrier already has the parcel — cancellation rejected
        echo $result->message;
    }
} catch (ShipmentCancellationException $e) {
    // handle error
}
```

### Fetching available shipping methods

Call `getShippingOptions()` to retrieve the SendCloud shipping methods available for a given module.
Store the method ID on your shipping module configuration — it is required when creating a shipment.

```php
$methods = $this->router->getShippingOptions('sendcloud_postnl');

foreach ($methods as $method) {
    echo $method['id'] . ' — ' . $method['name'];
}
```

### Listening for shipment state changes

```php
use Quellabs\Canvas\Annotations\ListenTo;
use Quellabs\Shipments\Contracts\ShipmentState;
use Quellabs\Shipments\Contracts\ShipmentStatus;

class OrderService {

    /**
     * @ListenTo("shipment_exchange")
     */
    public function onShipmentExchange(ShipmentState $state): void {
        match ($state->state) {
            ShipmentStatus::LabelPrinted    => $this->markLabelPrinted($state->reference),
            ShipmentStatus::InTransit       => $this->markShipped($state->reference, $state->trackingUrl),
            ShipmentStatus::Delivered       => $this->markDelivered($state->reference),
            ShipmentStatus::ReturnedToSender => $this->handleReturn($state->reference),
            default                         => null,
        };
    }
}
```

### Reconciling missed webhooks

If a webhook was missed, call `exchange()` with the driver name and parcel ID to re-fetch the current state.
This emits the same `shipment_exchange` signal as a real webhook.

```php
use Quellabs\Shipments\Contracts\ShipmentExchangeException;

try {
    $state = $this->router->exchange('sendcloud', $parcelId);
} catch (ShipmentExchangeException $e) {
    // handle error
}
```

## Supported modules

| Module              | Carrier       |
|---------------------|---------------|
| `sendcloud_postnl`  | PostNL        |
| `sendcloud_dhl`     | DHL           |
| `sendcloud_dpd`     | DPD           |
| `sendcloud_ups`     | UPS           |
| `sendcloud_bpost`   | bpost         |
| `sendcloud_mondial` | Mondial Relay |
| `sendcloud_multi`   | All carriers  |

## License

MIT