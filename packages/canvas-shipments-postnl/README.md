# PostNL Shipping Provider

A PostNL shipping provider for the Canvas framework.

## Installation

```bash
composer require quellabs/canvas-shipments-postnl
```

## Configuration

Publish `config/postnl.php` and fill in your credentials:

```php
return [
    'api_key'             => '',
    'api_key_test'        => '',
    'mode'                => 'live', // 'live' or 'sandbox'
    'customer_code'       => '',     // from your PostNL contract
    'customer_number'     => '',     // from your PostNL contract
    'collection_location' => '',     // from your PostNL contract
    'webhook_secret'      => '',     // HMAC-SHA256 secret, set in the Developer Portal
    'sender_address'      => [
        // 'company'     => 'My Webshop B.V.',
        // 'street'      => 'Keizersgracht',
        // 'houseNumber' => '123',
        // 'postalCode'  => '1015CJ',
        // 'city'        => 'Amsterdam',
        // 'country'     => 'NL',
        // 'email'       => 'logistics@example.com',
        // 'phone'       => '+31201234567',
    ],
];
```

`customer_code`, `customer_number`, and `collection_location` are provided by PostNL when your
account is set up. You can find them in your PostNL contract or business portal.

## Important behaviours

**Tracking code is available at creation time.** `ShipmentResult::$trackingCode` and `$trackingUrl`
are both populated immediately after `create()`. PostNL assigns the barcode inline in the creation
response.

**Labels are returned inline.** The label PDF is included in the creation response as a base64-encoded
string and stored as a `data:application/pdf;base64,...` URI in `ShipmentResult::$labelUrl`. Decode
and persist it to file or object storage before passing it to end users.

**Cancellation is supported before carrier scan.** `cancel()` calls the PostNL delete endpoint. If
the parcel has already been scanned by the carrier, the call returns a `CancelResult` with
`$accepted = false` rather than throwing. Any other API failure throws `ShipmentCancellationException`.

**Webhooks are HMAC-signed.** PostNL signs each request with a SHA256 HMAC sent in the
`X-PostNL-Signature` header. Set `webhook_secret` in your config to enable verification. Leaving it
empty disables verification — do not do this in production.

**Delivery options return one entry per available timeframe slot.** `getDeliveryOptions()` calls the
PostNL Timeframe API and returns all available windows (Daytime, Morning, Evening, Sunday) across a
5-day window starting tomorrow. Each slot is a separate `DeliveryOption`; the `methodId` encodes the
date, window, and option type (`dd-mm-yyyy|HH:MM:SS|HH:MM:SS|OptionType`) so it can be passed back
in `ShipmentRequest::$methodId` at order creation time. A recipient address is required; without it
an empty array is returned. Morning, Evening, and Sunday slots are only included when available at
the recipient address and enabled on your PostNL contract. Pickup point and locker modules return an
empty array — use `getPickupOptions()` instead.

**Pickup options require a recipient address.** Pass a `ShipmentAddress` as the second argument to
`getPickupOptions()`. The address is used as the search origin for nearby service points.

**Each product code must be contracted.** Not all modules listed below are available on every
PostNL account. Consult your PostNL account manager to enable the products you need.

## Supported modules

### Domestic NL — standard home delivery

| Module                          | Product code | Service                         |
|---------------------------------|--------------|---------------------------------|
| `postnl_standard`               | 3085         | Standard shipment               |
| `postnl_signature`              | 3087         | Signature on delivery           |
| `postnl_stated_address`         | 3090         | Delivery to stated address only |
| `postnl_age_check`              | 3094         | Age check 18+                   |
| `postnl_sunday`                 | 3096         | Sunday/holiday delivery         |
| `postnl_id_check`               | 3189         | ID check on delivery            |
| `postnl_sameday`                | 3385         | Same-day / Sunday delivery      |
| `postnl_sameday_stated_address` | 3390         | Same-day + stated address only  |

### Domestic NL — with insurance / COD

| Module                         | Product code | Service                                 |
|--------------------------------|--------------|-----------------------------------------|
| `postnl_insured`               | 3086         | Extra cover / COD                       |
| `postnl_signature_insured`     | 3091         | Signature + extra cover                 |
| `postnl_signature_age_insured` | 3093         | Signature + age check 18+ + extra cover |
| `postnl_sunday_insured`        | 3097         | Sunday/holiday + extra cover            |
| `postnl_sameday_insured`       | 3389         | Same-day + extra cover                  |

### Domestic NL — Extra@Home

| Module             | Product code | Service                          |
|--------------------|--------------|----------------------------------|
| `postnl_extrahome` | 3089         | Extra@Home (large/heavy parcels) |

### Domestic NL — mailbox parcel

| Module           | Product code | Service                          |
|------------------|--------------|----------------------------------|
| `postnl_mailbox` | 2928         | Mailbox parcel (brievenbuspakje) |

### Domestic NL — age & ID check variants

| Module                          | Product code | Service                                 |
|---------------------------------|--------------|-----------------------------------------|
| `postnl_age_check_home`         | 3437         | Age check 18+, home delivery            |
| `postnl_age_check_home_insured` | 3438         | Age check 18+ + extra cover             |
| `postnl_id_check_home`          | 3440         | ID check, home delivery                 |
| `postnl_id_age_check_home`      | 3442         | ID check + age check 18+, home delivery |
| `postnl_id_check_pickup`        | 3444         | ID check, pickup point                  |
| `postnl_id_age_check_pickup`    | 3446         | ID check + age check 18+, pickup point  |

### Domestic NL — pick-up at PostNL location

| Module                                     | Product code | Service                         |
|--------------------------------------------|--------------|---------------------------------|
| `postnl_pickup`                            | 3533         | Standard                        |
| `postnl_pickup_insured`                    | 3534         | Extra cover                     |
| `postnl_pickup_cod`                        | 3535         | COD                             |
| `postnl_pickup_cod_insured`                | 3536         | COD + extra cover               |
| `postnl_pickup_signature`                  | 3543         | Signature                       |
| `postnl_pickup_signature_insured`          | 3544         | Signature + extra cover         |
| `postnl_pickup_signature_cod`              | 3545         | Signature + COD                 |
| `postnl_pickup_signature_cod_insured`      | 3546         | Signature + COD + extra cover   |
| `postnl_pickup_consumer`                   | 3571         | Standard (consumer-facing)      |
| `postnl_pickup_consumer_signature`         | 3572         | Signature (consumer-facing)     |
| `postnl_pickup_consumer_id`                | 3573         | ID check (consumer-facing)      |
| `postnl_pickup_consumer_age`               | 3574         | Age check 18+ (consumer-facing) |
| `postnl_pickup_consumer_insured`           | 3575         | Extra cover (consumer-facing)   |
| `postnl_pickup_consumer_insured_signature` | 3576         | Extra cover + signature         |

### Domestic NL — returns

| Module                        | Product code | Service                                        |
|-------------------------------|--------------|------------------------------------------------|
| `postnl_return`               | 2828         | Return label (label-in-the-box / smart return) |
| `postnl_return_international` | 4910         | ERS international return label                 |

### NL → Belgium

| Module                | Product code | Service               |
|-----------------------|--------------|-----------------------|
| `postnl_be_standard`  | 4946         | Standard shipment     |
| `postnl_be_signature` | 4912         | Signature on delivery |
| `postnl_be_insured`   | 4914         | Extra cover           |
| `postnl_be_age_check` | 4941         | Age check 18+         |

### Domestic Belgium (BE → BE)

| Module                         | Product code | Service               |
|--------------------------------|--------------|-----------------------|
| `postnl_be_domestic`           | 4960         | Standard              |
| `postnl_be_domestic_signature` | 4961         | Signature on delivery |
| `postnl_be_domestic_insured`   | 4962         | Extra cover           |
| `postnl_be_domestic_age_check` | 4963         | Age check 18+         |
| `postnl_be_domestic_id_check`  | 4965         | ID check              |

### Belgium — pick-up at PostNL location

| Module                 | Product code | Service  |
|------------------------|--------------|----------|
| `postnl_be_pickup`     | 4878         | Standard |
| `postnl_be_pickup_cod` | 4880         | COD      |

### EU (EPS / EU Pack Special)

| Module                | Product code | Service                                |
|-----------------------|--------------|----------------------------------------|
| `postnl_eu`           | 4907         | EU Pack Special, standard              |
| `postnl_eu_be`        | 4936         | EU Pack Special, BE → EU               |
| `postnl_eu_consumer`  | 4952         | EU Pack Special, consumer (combilabel) |
| `postnl_eu_documents` | 4999         | EU Pack Special, documents             |

### GlobalPack (world outside EU)

| Module          | Product code | Service             |
|-----------------|--------------|---------------------|
| `postnl_global` | 4909         | GlobalPack standard |

### Miscellaneous

| Module          | Product code | Service                            |
|-----------------|--------------|------------------------------------|
| `postnl_locker` | 6350         | Parcel dispenser / locker delivery |

## License

MIT