# DHL Parcel NL Shipping Provider

A DHL Parcel NL shipping provider for the Canvas framework.

## Installation

```bash
composer require quellabs/canvas-shipments-dhl
```

## Configuration

`config/dhl.php` is published automatically when you install the package. Fill in your credentials:

```php
return [
    'user_id'        => '',      // UUID from My DHL Parcel portal → Settings → API Keys
    'api_key'        => '',      // UUID paired with user_id
    'user_id_test'   => '',      // acceptance environment credentials (optional)
    'api_key_test'   => '',
    'account_id'     => '',      // DHL account number from your contract
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
        'phone'       => '+31201234567'
    ],
];
```

`user_id` and `api_key` are generated in the My DHL Parcel portal under **Settings → API Keys**.
The key pair is shown only once — generating a new key invalidates the previous one.
`account_id` is the DHL account number from your contract.

## Important behaviours

**Tracking code is available at creation time.** `ShipmentResult::$parcelId`, `$trackingCode`, and
`$trackingUrl` are all populated immediately after `create()`. DHL assigns the barcode (tracker code)
inline in the creation response.

**Label retrieval requires a second API call.** DHL does not include the label in the creation
response. When `ShipmentRequest::$requestLabel` is `true`, the driver makes a follow-up call to
retrieve the label ID and returns its API endpoint URL in `ShipmentResult::$labelUrl`. This URL
requires a valid Bearer token to access — proxy it server-side rather than exposing it to clients.
If label retrieval fails at creation time it is treated as non-fatal; call `getLabelUrl()` on the
driver to retry.

**Parcel type is auto-selected from weight.** The driver picks the smallest DHL parcel type that
fits `ShipmentRequest::$weightGrams`:

| Parcel type | Max weight | Max dimensions   |
|-------------|------------|------------------|
| `SMALL`     | 2 kg       | 38 × 26 × 10 cm  |
| `MEDIUM`    | 10 kg      | 58 × 38 × 37 cm  |
| `LARGE`     | 20 kg      | 100 × 50 × 50 cm |
| `XL`        | 31.5 kg    | —                |

Shipments exceeding 31.5 kg throw `ShipmentCreationException`. To override for a specific shipment,
pass `'parcelType'` in `ShipmentRequest::$extraData` — this bypasses auto-selection entirely.

**Cancellation is not supported via the public API.** `cancel()` always throws
`ShipmentCancellationException`. Cancel shipments manually via the My DHL Parcel portal, or use
the Interventions endpoint if it is enabled on your account.

**Delivery options are not available.** `getDeliveryOptions()` always returns an empty array. DHL
does not expose a consumer-facing timeslot picker equivalent to the delivery options API.

**Pickup options require a recipient address.** Pass a `ShipmentAddress` as the second argument to
`getPickupOptions()`. The address is used as the search origin for nearby DHL ServicePoints. Without
an address an empty array is returned.

**Test mode targets the DHL acceptance environment.** Setting `test_mode => true` switches both
the base URL (`https://api-gw-accept.dhlparcel.nl`) and credentials to the test pair. If
`user_id_test` / `api_key_test` are not configured, the live credentials are used against the
acceptance environment.

**Webhooks carry only the barcode.** DHL's Track & Trace Pusher sends `{ "barcode": "..." }` in
the webhook payload. The driver calls `exchange()` on every webhook event to fetch the full event
history and build a consistent `ShipmentState`. Restrict the webhook endpoint by IP or secret path
— DHL does not sign webhook requests.

**Authentication tokens are managed transparently.** DHL uses short-lived JWT access tokens
(~15 minutes) refreshed automatically using a longer-lived refresh token (~7 days). No token
management is required from the caller.

## Supported modules

| Module        | DHL product key   | Service                                  |
|---------------|-------------------|------------------------------------------|
| `dhl_parcel`  | `PARCEL_CONNECT`  | Standard domestic NL and cross-border EU |
| `dhl_mailbox` | `MAILBOX_PACKAGE` | Mailbox parcel (brievenbuspakje)         |
| `dhl_express` | `EXPRESS`         | Express delivery (before 11:00)          |

Not all products are available on every DHL account. Consult your DHL account manager to enable
the products you need, and use the DHL capabilities endpoint to verify availability per destination.

## License

MIT