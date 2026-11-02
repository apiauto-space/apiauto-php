# apiauto — PHP client for apiauto.space

The PHP client of the [apiauto.space](https://apiauto.space/en/) REST API: used-car listings from **Encar**
(South Korea), **Che168** (China) and the **Copart** and **IAAI** auctions (USA) in one JSON record format.
PHP 8.2+, only the `curl` and `json` extensions.

Encar and Che168 publish no public API for their listings that we could find; Copart and IAAI have private
partner integrations, not a public self-serve API for lots. apiauto.space is an independent service.

The API is paid from $59 a month for 100,000 cars. A free demo key works for 1 day: 100 requests, up to 20 cars
per request, no card, from the Telegram bot [@apiautospace_bot](https://t.me/apiautospace_bot?start=demo).

```php
$client = new Apiauto\Client();  // reads APIAUTO_KEY
$page = $client->cars('kr', ['brand' => 'Kia', 'model' => 'Sorento', 'year_min' => 2021, 'limit' => 5]);
foreach ($page->items as $car) {
    echo $car->id, '  ', $car->title, '  ', $car->price->amount, ' ', $car->price->currency, "\n";
}
```

A real output line:

```text
encar:43078256  2023 Kia Sorento 4 Gen HEV 1.6 2WD Noblesse  28500000.00 KRW
```

Last verified: 2026-11-02, unit and live tests run on PHP 8.2 and 8.5.

## Quick start

```bash
composer require apiauto/apiauto
export APIAUTO_KEY=your-key
```

Keep the key on your server: the API does not accept browser requests. Documentation:
[apiauto.space/en/docs](https://apiauto.space/en/docs/), [OpenAPI](https://api.apiauto.space/openapi.json).

```php
foreach ($client->iterCars('cn', ['brand' => 'BYD', 'limit' => 100], maxItems: 1000) as $car) { /* ... */ }

$car = $client->car('encar:43020893');

$since = new DateTimeImmutable('-1 hour');
foreach ($client->iterChanges('kr', $since, ['limit' => 100]) as $event) {
    echo $event->type, ' ', $event->carId, ' ', $event->newPrice, "\n";
}

$client->brands('cn');           // free: also models(), dictionaries(), markets(), usage()
$client->lastResponse();         // quotaRemaining, rateRemaining, dataAgeSeconds...
```

`maxItems` asks only for what is still needed, so the last page spends no extra quota. Iteration stops with an
exception if the API repeats a cursor. `usLive()` searches Copart and IAAI live at the data provider (Pro and
Max, a separate monthly counter). Runnable scripts are in
[examples/search.php](https://github.com/apiauto-space/apiauto-php/blob/main/examples/search.php),
[us-lots.php](https://github.com/apiauto-space/apiauto-php/blob/main/examples/us-lots.php) and
[change-feed.php](https://github.com/apiauto-space/apiauto-php/blob/main/examples/change-feed.php).

## Fields

`Apiauto\Model\Car` has readonly properties: `id`, `market`, `source`, `sourceId`, `title`, `brand`, `model`,
`generation`, `trim`, `year`, `mileageKm`, `fuel`, `transmission`, `engineCc`, `bodyType`, `color`, `vin`,
`price`, `priceUsd`, `location`, `photos`, `photoCount`, `listingUrl`, `isActive`, `firstSeenAt`, `lastSeenAt`,
`disappearedAt`, `updatedAt`, `sourceMode`, `raw`. What the source does not give is `null`. Filters:
`brand`, `model`, `generation`, `trim`, `year_min`, `year_max`, `price_min`, `price_max`, `price_usd_min`,
`price_usd_max`, `mileage_max`, `fuel`, `transmission`, `body_type`, `has_photos`, `is_active`, `q`, `sort`,
`limit`, `cursor`.

## Units and gotchas

- Prices are decimal strings in the market's currency, so no precision is lost. `priceUsd` uses the ECB daily rate.
- Mileage is kilometres. Photo links are signed and live about a day: store the `id`.
- **Copart or IAAI only:** `us` holds both and the API has no filter by auction, so keep records by `$car->source`.
  The quota counts every lot returned. Lots come in loading order: on 2 November 2026 `sort=first_seen` put Copart
  first and the default sort put IAAI first. That is no contract.
- A `disappeared` event does not mean sold. Listing data is untrusted: escape it in HTML.

## Data freshness

The refresh target is hourly. That is a target, not a promise: the real age is in `$client->markets()` and in
`lastResponse()->dataAgeSeconds`.

## Limits

Up to 100 cars per request (20 on the demo). Paid plans count cars returned; the demo counts data requests.

| HTTP | Exception | Retried |
| --- | --- | --- |
| 400, 401, 402, 403, 404 | `BadRequestException`, `AuthenticationException`, `QuotaExceededException`, `PermissionDeniedException`, `NotFoundException` | no |
| 429, 503 | `RateLimitException`, `ServiceUnavailableException` | yes, `Retry-After` up to 60 s |
| other 5xx, network | `ServerException`, `ConnectionException` | yes, backoff |

All extend `Apiauto\Exception\ApiautoException`. Two retries by default. Security behaviour and its limits are in
[SECURITY.md](https://github.com/apiauto-space/apiauto-php/blob/main/SECURITY.md).

## Pricing

Demo free for 1 day (100 requests); Start $59 a month (100,000 cars, 1 market); Pro $149 (500,000 cars, 2 markets,
change feed); Max $299 (2,000,000 cars, 3 markets). Current prices: [apiauto.space/en/#pricing](https://apiauto.space/en/#pricing).

## Other languages

| Language | Package | Repository |
| --- | --- | --- |
| Python | `pip install apiauto-space` | [apiauto-python](https://github.com/apiauto-space/apiauto-python) |
| JavaScript, TypeScript | `npm install apiauto` | [apiauto-js](https://github.com/apiauto-space/apiauto-js) |
| Go | `go get github.com/apiauto-space/apiauto-go` | [apiauto-go](https://github.com/apiauto-space/apiauto-go) |
| curl and examples | — | [Encar](https://github.com/apiauto-space/encar-api-examples), [Che168](https://github.com/apiauto-space/che168-api-examples), [Copart](https://github.com/apiauto-space/copart-api-examples), [IAAI](https://github.com/apiauto-space/iaai-api-examples) |

## Not affiliated

This client and apiauto.space are not affiliated with, endorsed by or sponsored by Encar, Che168, Autohome, Copart
or IAAI; the names only say where the listings come from. Use of the data is subject to the
[apiauto.space terms](https://apiauto.space/en/offer/). Code: [MIT](https://github.com/apiauto-space/apiauto-php/blob/main/LICENSE).
