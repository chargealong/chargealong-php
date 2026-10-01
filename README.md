# chargealong/chargealong

**EV chargers, road trips and charging speeds, as an API.** Find the public
charging sites nearest a point, plan a trip through charging stops, and look up
how fast an electric car charges.

- Free and keyless. No account, nothing to configure
- Australia, New Zealand, the United States, the United Kingdom and Canada
- Answers are arrays shaped like the API's JSON, field names as the service
  sends them
- PHP 8.1 or later, no dependencies

```sh
composer require chargealong/chargealong
```

## Quick start

```php
use ChargeAlong\Client;
use ChargeAlong\Paths;

$ca = new Client();

$found = $ca->nearby(-37.8136, 144.9631, minKw: 50, connector: 'ccs2', limit: 5);

echo "Near {$found['near']['name']}\n";
foreach ($found['sites'] as $site) {
    printf(
        "%s, %s, %d kW, %d m: %s\n",
        $site['name'],
        $site['network_name'] ?? $site['network'],
        $site['max_kw'],
        Paths::metres($site['distance_km']),
        Paths::pageUrl($site),
    );
}
```

Nearest first, within 50 km unless you say otherwise. Only `active` sites are
worth sending a driver to; `status` also says `unavailable`, `planned` or
`removed`. Pass several connectors as an array for any of them.

## Plan a road trip

```php
$plan = $ca->planTrip(
    [-37.8136, 144.9631],   // Melbourne
    [-33.8688, 151.2093],   // Sydney
    rangeKm: 400,           // the real range, not the rated one
    connectors: ['ccs2'],
);

if ($plan['feasible']) {
    foreach ($plan['stops'] as $stop) {
        echo "{$stop['site']['name']}: {$stop['arrive_pct']}% to {$stop['depart_pct']}%, {$stop['charge_min']} min\n";
    }
    echo "{$plan['total_min']} min in all, arriving with {$plan['arrival_pct']}%\n";
} else {
    echo "No charger between km {$plan['gap']['from_km']} and {$plan['gap']['to_km']}\n";
}
```

Each end is `[lat, lng]` or `['lat' => .., 'lng' => ..]`. `route.line` is the
road as `[lng, lat]` pairs, ready for a map. Reserve, start charge, charge
limit, battery size and the car's AC and DC limits are all named arguments,
with the service's defaults when left out.

Two different failures: no road at all between the points throws `NoRoute`; a
road without enough chargers for this car answers with `feasible` false and the
`gap` it cannot cross.

## Electric cars

```php
$found = $ca->vehicles(q: 'ioniq 5');
$car = $ca->vehicle($found['vehicles'][0]['slug']);

foreach ($car['vehicle']['charging'] as $row) {
    if ($row['charger_kw'] === 150) {
        echo "10 to 80% in {$row['minutes_10_80']} min on a 150 kW charger\n";
    }
}
echo "Figures from {$car['source']['name']} ({$car['source']['licence']})\n";
```

## One site, and stored ids

```php
$detail = $ca->site('hwqb4abf');
$detail['connectors'];    // standard, power, AC or DC, how many
$detail['sources'];       // where the record came from, and when it was last seen
$detail['alternatives'];  // other sites close by
```

A site's `public_id` is permanent: minted once, never derived from a source
record. Store it. `site` throws `NotFound` for an id that never existed. A site
that was removed still answers, with `site.status` of `removed`, so code walking
stored ids can drop the retired ones.

## The rest of the directory

| Call | Answers |
|---|---|
| `search($q, prefer: 'AU')` | places, postcodes and sites matching a name, for a search box |
| `countries()`, `overview()` | what the directory holds |
| `country`, `region`, `locality` | a place and its charging sites |
| `networks($country)`, `network($country, $slug)` | charging networks and where they reach |
| `redirect($path)` | where a page on chargealong.io went, or null |

`search` answers empty without a request below three letters or digits.
Debounce typing before you ask.

## Links to the site

Rows carry their page's `path`; `Paths::pageUrl($row)` makes it absolute. For
rows that only carry slugs, `Paths::country`, `region`, `locality`, `networks`,
`network` and `vehicle` build the same paths the site serves.

## Errors

Every error is a `ChargeAlong\Exception\ChargeAlongException` with the
service's own `title` and `detail`, which name the problem:
`lat: must be between -90 and 90`. The others are `NotFound`, `BadRequest`,
`NoRoute` (a kind of `BadRequest`) and `RateLimited`, with `retryAfter` in
seconds. Arguments outside the service's bounds throw
`InvalidArgumentException` before a request goes out, and a connection that
fails throws `ChargeAlong\Http\TransportException`.

## Your own transport

cURL is the default. To send requests through WordPress, Laravel or a PSR-18
client instead, implement `ChargeAlong\Http\Transport`: one `get` method that
returns the status, headers and body, follows no redirects, and throws only
when there is no answer at all.

```php
$ca = new Client(transport: new MyTransport());
```

## Being a good citizen

The API is free and asks for care in return: keep answers rather than fetching
them again, and debounce anything a person types. Every charger the API returns
also has a page of its own on the website, [chargealong.io](https://chargealong.io/).
Building something that needs more than the free API gives? Say what at
[chargealong.io/en/contact/](https://chargealong.io/en/contact/).

## Attribution

Charging site data from Open Charge Map contributors, Transport for NSW and the
Queensland Government, and places from GeoNames, all under CC BY 4.0. Vehicle
figures from Open EV Data, MIT. Publishing what you get back means carrying
those credits; [where the data comes from](https://chargealong.io/en/guides/where-the-data-comes-from/)
lists them in full, and the [terms](https://chargealong.io/en/terms/) cover use
of the API.

## Also available

| | |
|---|---|
| JavaScript and TypeScript | [`@chargealong/client`](https://www.npmjs.com/package/@chargealong/client) |
| Python | [`chargealong`](https://pypi.org/project/chargealong/) |

API reference: [chargealong.io/docs](https://chargealong.io/docs/).

## Licence

MIT.
