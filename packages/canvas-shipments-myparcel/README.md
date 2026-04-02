# MyParcel Shipping Provider

A MyParcel shipping provider for the Canvas framework. Supports both `api.myparcel.nl` (NL) and `api.sendmyparcel.be` (BE).

## Installation

```bash
composer require quellabs/canvas-shipments-myparcel
```

## Configuration

Publish `config/myparcel.php` and fill in your credentials:

```php
return [
    'api_key'        => '',
    'api_key_test'   => '',
    'region'         => 'nl',   // 'nl' or 'be'
    'mode'           => 'live', // 'live' or 'test'
    'package_type'   => 1,      // 1=Package, 2=Mailbox, 3=Letter, 4=Digital stamp
    'sender_address' => [
        // 'company'     => 'My Webshop B.V.',
        // 'person'      => 'Logistics Dept',
        // 'street'      => 'Keizersgracht',
        // 'number'      => '123',
        // 'postal_code' => '1015CJ',
        // 'city'        => 'Amsterdam',
        // 'cc'          => 'NL',
        // 'email'       => 'logistics@example.com',
        // 'phone'       => '+31201234567',
    ],
];
```

## Important behaviours

**Tracking code is not available at creation time.** `ShipmentResult::$trackingCode` is always `null` after
`create()`. MyParcel assigns the carrier barcode asynchronously. Use the webhook or call `exchange()` later.

**Cancellation is not supported.** Calling `cancel()` always throws `ShipmentCancellationException`.
Parcels must be deleted manually in the MyParcel panel before carrier pickup.

**Webhooks carry only the shipment ID.** The controller makes one API call per webhook event to fetch the
current state. MyParcel does not sign webhook requests — restrict the endpoint by IP or use a secret path.

**Delivery options require a recipient address.** Pass a `ShipmentAddress` as the second argument to
`getShippingOptions()`. Without it, an empty array is returned. MyParcel computes available timeframes
and pickup points per postal code, so the address is mandatory for meaningful results.

## Supported modules

| Module                | Carrier     | Region |
|-----------------------|-------------|--------|
| `myparcel_postnl`     | PostNL      | NL     |
| `myparcel_cheapcargo` | CheapCargo  | NL     |
| `myparcel_dhl`        | DHL         | NL     |
| `myparcel_dhlforyou`  | DHL For You | NL     |
| `myparcel_dpd`        | DPD         | NL     |
| `myparcel_bpost`      | bpost       | BE     |

## License

MIT
