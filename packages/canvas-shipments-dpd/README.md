# DPD Shipping Provider

A DPD NL/BE shipping provider for the Canvas framework.

## Installation

```bash
composer require quellabs/canvas-shipments-dpd
```

## Configuration

`config/dpd.php` is published automatically when you install the package. Fill in your credentials:

```php
return [
    'delis_id'      => '',      // 6–10 character ID provided by DPD
    'password'      => '',      // password provided by DPD
    'sending_depot' => '',      // depot code from your DPD contract (e.g. '0522')
    'test_mode'     => false,
    'sender_address' => [
        'company'     => 'My Webshop B.V.',
        'name'        => 'Logistics Dept',
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

`delis_id`, `password`, and `sending_depot` are provided by DPD when your shipper account is set up.
Contact DPD customer IT or your account manager to request access. Each new integration must be
validated and approved by DPD before production use.

## Important behaviours

**The API is SOAP-based, not REST.** The DPD Shipper Webservice uses SOAP 1.1 over HTTPS with XML
request/response bodies. The gateway handles all XML serialisation and parsing internally — no SOAP
extension or WSDL tooling is required from the caller.

**Auth tokens are valid for 24 hours and must be cached.** DPD enforces a maximum of 10 login calls
per day. The gateway caches the token in-memory and re-authenticates only when it has expired, with
a 5-minute safety margin. For long-running processes or queue workers that may outlive a single
request lifecycle, implement persistent token caching and inject a pre-warmed token via the config
if needed.

**Labels are not returned by default.** `ShipmentResult` does not include a label. The label PDF is
embedded as base64 in the creation response and stored in `ShipmentResult::$rawResponse['labelContent']`.
Persist this value to storage immediately after creation — DPD does not offer a label re-fetch
endpoint. `getLabelUrl()` will throw a `ShipmentLabelException` with instructions to use the cached
raw response value.

**Tracking code equals the parcel label number.** DPD uses a 14-digit parcel label number as the
stable identifier across creation, tracking, and label generation. This is stored as both
`ShipmentResult::$parcelId` and `$trackingCode`.

**No webhook support.** DPD's Shipper Webservice does not offer push notifications. Use the
`handleRefresh` endpoint (or call `exchange()` directly) from a scheduled job to poll parcel status
via the ParcelLifeCycle Service.

**Cancellation is not supported via the API.** `cancel()` always throws `ShipmentCancellationException`.
Contact DPD customer service before 22:00 on the day the order was placed to cancel manually.

**Delivery options are not available.** `getDeliveryOptions()` always returns an empty array. DPD
does not expose a consumer-facing timeslot picker.

**Pickup options require a recipient address.** Pass a `ShipmentAddress` as the second argument to
`getPickupOptions()`. The address postal code, city, and country are used to search nearby DPD
parcel shops. Without an address an empty array is returned.

**Test mode uses the DPD stage environment.** Setting `test_mode => true` switches to
`shipperadmintest.dpd.nl`. Stage and live credentials are separate — request stage credentials
from DPD customer IT. Labels generated on stage are not valid and must not be used for real shipments.
DPD requires that all integrations are tested and approved on stage before production access is granted.

**Sequential requests only.** DPD prohibits concurrent shipment service calls per account. The
driver sends requests sequentially by design; do not parallelize shipment creation across the same
account credentials.

## Supported modules

| Module              | DPD product | Service                         |
|---------------------|-------------|---------------------------------|
| `dpd_b2b`           | `B2B`       | DPD Business (standard)         |
| `dpd_b2c`           | `B2C`       | DPD Home (consumer delivery)    |
| `dpd_parcel_letter` | `PL`        | DPD ParcelLetter                |
| `dpd_b2b_saturday`  | `B2B`       | DPD Business Saturday delivery  |
| `dpd_b2c_saturday`  | `B2C`       | DPD Home Saturday delivery      |
| `dpd_b2b_age_check` | `B2B`       | DPD Business + age check 18+    |
| `dpd_b2c_age_check` | `B2C`       | DPD Home + age check 18+        |
| `dpd_shop`          | `B2C`       | DPD Shop (parcel shop delivery) |
| `dpd_shop_return`   | `B2C`       | DPD Shop Return                 |
| `dpd_express_830`   | `E830`      | DPD Express before 08:30        |
| `dpd_express_10`    | `E10`       | DPD Express before 10:00        |
| `dpd_express_12`    | `E12`       | DPD Express before 12:00        |
| `dpd_guarantee`     | `PM2`       | DPD Guarantee                   |

Not all products are available on every DPD account. Confirm which services are enabled on your
contract with your DPD account manager before using a module in production.

## License

MIT