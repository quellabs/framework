# MyParcel Shipping Provider

A MyParcel shipping provider for the Canvas framework. Supports both `api.myparcel.nl` (NL) and `api.sendmyparcel.be` (
BE).

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
    'test_mode'      => false,
    'sender_address' => [
        'company'     => 'My Webshop B.V.',
        'person'      => 'Logistics Dept',
        'street'      => 'Keizersgracht',
        'number'      => '123',
        'postal_code' => '1015CJ',
        'city'        => 'Amsterdam',
        'cc'          => 'NL',
        'email'       => 'logistics@example.com',
        'phone'       => '+31201234567',
    ],
];
```

## Important behaviours

**Tracking code is not available at creation time.** `ShipmentResult::$trackingCode` is always `null` after
`create()`. MyParcel assigns the carrier barcode asynchronously. Use the webhook or call `exchange()` later.

**Cancellation is not supported.** Calling `cancel()` always throws `ShipmentCancellationException`.
Parcels must be deleted manually in the MyParcel panel before carrier pickup.

**Package type defaults to `parcel`.** Set `ShipmentRequest::$packageType` to control the MyParcel
product type per shipment:

| `$packageType`    | MyParcel code | Description                |
|-------------------|---------------|----------------------------|
| `'parcel'`        | 1             | Standard package (default) |
| `'mailbox'`       | 2             | Mailbox package            |
| `'letter'`        | 3             | Letter                     |
| `'digital_stamp'` | 4             | Unstamped letter           |

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