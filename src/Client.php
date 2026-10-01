<?php

declare(strict_types=1);

namespace ChargeAlong;

use ChargeAlong\Exception\ChargeAlongException;
use ChargeAlong\Exception\NotFound;
use ChargeAlong\Http\CurlTransport;
use ChargeAlong\Http\Response;
use ChargeAlong\Http\Transport;

/**
 * A client for the ChargeAlong API: public EV charging sites, trip planning
 * through charging stops, and how fast electric cars charge.
 *
 * ```php
 * $ca = new ChargeAlong\Client();
 * $near = $ca->nearby(-37.8136, 144.9631, minKw: 50);
 * ```
 *
 * Free, keyless and cached at the edge. Answers are arrays shaped exactly like
 * the API's JSON, field names as the service sends them. Nothing is kept on the
 * instance between calls, so one can be shared.
 *
 * Attribution travels with the data: see
 * chargealong.io/en/guides/where-the-data-comes-from/.
 *
 * @phpstan-type Source array{name: string, url: string, licence: string}
 */
final class Client
{
    public const VERSION = '0.1.0';
    public const DEFAULT_BASE_URL = 'https://api.chargealong.io';

    /** The service reads a search once it holds this many letters or digits. */
    public const MIN_QUERY = 3;
    private const MAX_QUERY = 100;

    /** The connector standards the service knows. */
    public const CONNECTORS = ['ccs2', 'chademo', 'type-2', 'nacs', 'ccs1', 'type-1', 'gbt'];

    private const LOOPBACK = ['localhost', '127.0.0.1', '[::1]'];

    private readonly string $baseUrl;
    private readonly Transport $transport;

    /**
     * @param string         $baseUrl   a proxy of your own, or a test server
     * @param Transport|null $transport how requests are sent; {@see CurlTransport} by default
     */
    public function __construct(string $baseUrl = self::DEFAULT_BASE_URL, ?Transport $transport = null)
    {
        $this->baseUrl = self::checkBaseUrl($baseUrl);
        $this->transport = $transport ?? new CurlTransport();
    }

    /**
     * Every country with charging sites, and how many each has.
     *
     * @return list<array<string, mixed>>
     */
    public function countries(): array
    {
        return self::rows($this->get('/v1/countries'));
    }

    /**
     * What the whole directory holds: countries with their busiest places.
     *
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        return $this->data('/v1/overview');
    }

    /**
     * One country: its regions and their counts.
     *
     * @return array<string, mixed>
     */
    public function country(string $country): array
    {
        return $this->data('/v1/countries/' . self::slug($country, 'country'));
    }

    /**
     * One region: the places in it that have charging sites.
     *
     * @return array<string, mixed>
     */
    public function region(string $country, string $region): array
    {
        return $this->data('/v1/countries/' . self::slug($country, 'country') . '/regions/' . self::slug($region, 'region'));
    }

    /**
     * Every charging network in a country, with its site and region counts.
     *
     * @return array<string, mixed>
     */
    public function networks(string $country): array
    {
        return $this->data('/v1/countries/' . self::slug($country, 'country') . '/networks');
    }

    /**
     * One network in a country: where it reaches, and a sample of its sites.
     *
     * @return array<string, mixed>
     */
    public function network(string $country, string $network): array
    {
        return $this->data('/v1/countries/' . self::slug($country, 'country') . '/networks/' . self::slug($network, 'network'));
    }

    /**
     * One suburb or town: every charging site in it, with facet counts.
     *
     * @return array<string, mixed>
     */
    public function locality(string $country, string $region, string $locality): array
    {
        return $this->data(
            '/v1/localities/' . self::slug($country, 'country') . '/' . self::slug($region, 'region')
            . '/' . self::slug($locality, 'locality'),
        );
    }

    /**
     * One charging site: connectors, power, price, access, network, sources,
     * and what is close by.
     *
     * The id is permanent: store it. A site that was removed still answers,
     * with `site.status` set to `removed`, so code walking stored ids can drop
     * the retired ones.
     *
     * @throws NotFound for an id that never existed
     * @return array<string, mixed>
     */
    public function site(string $publicId): array
    {
        return $this->data('/v1/sites/' . self::slug($publicId, 'site id'));
    }

    /**
     * Places, postcodes and sites matching a name, for a search box.
     *
     * Fewer than three letters or digits answers empty without a request, which
     * is what the service would answer anyway. Debounce anything a person types.
     *
     * @param string|null $prefer a two letter country code whose places rank first
     * @return array{localities: list<array<string, mixed>>, postcodes: list<array<string, mixed>>, sites: list<array<string, mixed>>}
     */
    public function search(string $term, ?string $prefer = null): array
    {
        $q = trim($term);
        // Characters, not bytes, without needing ext-mbstring.
        if ((int) preg_match_all('/./su', $q) > self::MAX_QUERY) {
            throw new \InvalidArgumentException('chargealong: a search is at most ' . self::MAX_QUERY . ' characters');
        }
        $empty = ['localities' => [], 'postcodes' => [], 'sites' => []];
        if ((int) preg_match_all('/[\p{L}\p{N}]/u', $q) < self::MIN_QUERY) {
            return $empty;
        }

        $params = [['q', $q]];
        if ($prefer !== null) {
            $code = strtoupper(trim($prefer));
            if (preg_match('/^[A-Z]{2}$/D', $code) !== 1) {
                throw new \InvalidArgumentException("chargealong: prefer $prefer is not a two letter country code");
            }
            $params[] = ['prefer', $code];
        }

        $data = self::object($this->get('/v1/search', $params));
        foreach ($empty as $key => $_) {
            $empty[$key] = self::list($data[$key] ?? null);
        }

        return $empty;
    }

    /**
     * The charging sites nearest a coordinate, nearest first.
     *
     * @param float|null               $minKw     only sites with a connector of at least this many kW, 0 to 400
     * @param float|null               $radiusKm  how far to look, 1 to 150 km; the service's default is 50
     * @param int|null                 $limit     how many sites, 1 to 50; the service's default is 30
     * @param string|list<string>|null $connector one of {@see CONNECTORS}, or several for any of them
     * @param string|null              $network   only sites on this network, by slug: evie-networks
     * @return array{near: array<string, mixed>|null, sites: list<array<string, mixed>>}
     */
    public function nearby(
        float $lat,
        float $lng,
        ?float $minKw = null,
        ?float $radiusKm = null,
        ?int $limit = null,
        string|array|null $connector = null,
        ?string $network = null,
    ): array {
        self::point($lat, $lng);
        $params = [
            ['lat', self::number($lat)],
            ['lng', self::number($lng)],
            ['min_kw', self::bounded($minKw, 'minKw', 0, 400)],
            ['radius_km', self::bounded($radiusKm, 'radiusKm', 1, 150)],
            ['limit', self::bounded($limit, 'limit', 1, 50)],
        ];
        if ($connector !== null) {
            // Repeated, which the service reads as "any of these".
            foreach (self::connectors(\is_array($connector) ? $connector : [$connector], 'connector') as $c) {
                $params[] = ['connector', $c];
            }
        }
        $network = trim($network ?? '');
        $params[] = ['network', $network === '' ? null : $network];

        $data = self::object($this->get('/v1/nearby', $params));
        $near = $data['near'] ?? null;

        return [
            'near' => \is_array($near) && !array_is_list($near) ? $near : null,
            'sites' => self::list($data['sites'] ?? null),
        ];
    }

    /**
     * A road trip from A to B through charging stops: the route, where to stop,
     * how long to charge at each, and the battery on arrival.
     *
     * Each end is `[lat, lng]` or `['lat' => .., 'lng' => ..]`. `$rangeKm` is how
     * far the car really goes on a full battery, 50 to 1500. Everything else
     * takes the service's default when left out: reserve 20, start 100,
     * chargeTo 80, maxDcKw 150, maxAcKw 11, minKw 50, connectors CCS2 and Type 2.
     *
     * Two different ways a trip can fail. No road route at all between the
     * points throws {@see Exception\NoRoute}. A road without enough chargers
     * for this car answers with `feasible` false and a `gap` saying which
     * stretch the car cannot cross.
     *
     * @param array<array-key, mixed> $from
     * @param array<array-key, mixed> $to
     * @param list<string>|null       $connectors what the car takes, from {@see CONNECTORS}
     * @return array<string, mixed>
     */
    public function planTrip(
        array $from,
        array $to,
        float $rangeKm,
        ?float $reserve = null,
        ?float $start = null,
        ?float $chargeTo = null,
        ?float $batteryKwh = null,
        ?float $maxDcKw = null,
        ?float $maxAcKw = null,
        ?float $minKw = null,
        ?array $connectors = null,
    ): array {
        [$fromLat, $fromLng] = self::end($from);
        [$toLat, $toLng] = self::end($to);
        if ($fromLat === $toLat && $fromLng === $toLng) {
            throw new \InvalidArgumentException('chargealong: a trip must end somewhere other than where it starts');
        }

        return $this->data('/v1/trips/plan', [
            ['from', self::number($fromLat) . ',' . self::number($fromLng)],
            ['to', self::number($toLat) . ',' . self::number($toLng)],
            ['range_km', self::bounded($rangeKm, 'rangeKm', 50, 1500)],
            ['reserve', self::bounded($reserve, 'reserve', 5, 50)],
            ['start', self::bounded($start, 'start', 5, 100)],
            ['charge_to', self::bounded($chargeTo, 'chargeTo', 50, 100)],
            ['battery_kwh', self::bounded($batteryKwh, 'batteryKwh', 10, 250)],
            ['max_dc_kw', self::bounded($maxDcKw, 'maxDcKw', 20, 400)],
            ['max_ac_kw', self::bounded($maxAcKw, 'maxAcKw', 3, 22)],
            ['min_kw', self::bounded($minKw, 'minKw', 0, 350)],
            // One parameter, comma separated: the planner reads it that way.
            ['connectors', $connectors === null ? null : implode(',', self::connectors($connectors, 'connectors'))],
        ]);
    }

    /**
     * Electric cars: battery, range and how they charge.
     *
     * @param string|null $q     any part of the brand, model or variant
     * @param int|null    $limit 1 to 100; the service's default is 20
     * @param bool        $all   every car rather than the first page; ignored with $q
     * @return array{vehicles: list<array<string, mixed>>, source: Source}
     */
    public function vehicles(?string $q = null, ?int $limit = null, bool $all = false): array
    {
        $q = trim($q ?? '');
        $body = $this->get('/v1/vehicles', [
            ['q', $q === '' ? null : $q],
            ['limit', self::bounded($limit, 'limit', 1, 100)],
            ['all', $all ? 'true' : null],
        ]);

        return ['vehicles' => self::rows($body), 'source' => self::source($body)];
    }

    /**
     * One electric car, with how long it takes to charge on chargers of each size.
     *
     * @return array{vehicle: array<string, mixed>, source: Source}
     */
    public function vehicle(string $slug): array
    {
        $path = '/v1/vehicles/' . self::slug($slug, 'vehicle');
        $body = $this->get($path);

        return ['vehicle' => self::dataOf($body, $path), 'source' => self::source($body)];
    }

    /**
     * Where a page on chargealong.io went, if it moved. Null for a page that
     * never did, which is most of them.
     *
     * @return array<string, mixed>|null
     */
    public function redirect(string $path): ?array
    {
        $p = trim($path);
        if (
            !str_starts_with($p, '/')
            || str_starts_with($p, '//')
            || str_contains($p, '://')
            || str_contains($p, '\\')
        ) {
            throw new \InvalidArgumentException("chargealong: $path is not a path on chargealong.io");
        }

        try {
            $data = $this->get('/v1/redirect', [['path', $p]])['data'] ?? null;
        } catch (NotFound) {
            return null;
        }

        /** @var array<string, mixed>|null */
        return \is_array($data) && $data !== [] && !array_is_list($data) ? $data : null;
    }

    /**
     * The envelope's `data` as an object, for the calls that answer one.
     *
     * @param list<array{string, string|null}> $params
     * @return array<string, mixed>
     */
    private function data(string $path, array $params = []): array
    {
        return self::dataOf($this->get($path, $params), $path);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private static function dataOf(array $body, string $path): array
    {
        $data = $body['data'] ?? null;
        if (!\is_array($data) || array_is_list($data) && $data !== []) {
            throw new ChargeAlongException(200, 'unexpected response', "$path answered without a data object");
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @param list<array{string, string|null}> $params name and value, in order; a name may repeat
     * @return array<string, mixed>
     */
    private function get(string $path, array $params = []): array
    {
        $pairs = [];
        foreach ($params as [$name, $value]) {
            if ($value !== null && $value !== '') {
                $pairs[] = rawurlencode($name) . '=' . rawurlencode($value);
            }
        }
        $url = $this->baseUrl . $path . ($pairs === [] ? '' : '?' . implode('&', $pairs));

        $response = $this->transport->get($url, [
            'Accept' => 'application/json',
            'User-Agent' => 'chargealong-php/' . self::VERSION,
        ]);

        // A problem document is JSON; a proxy having a bad day is not. Either way
        // the status is the part a caller can act on, so parsing never decides
        // whether an error is raised.
        $body = self::decode($response);

        if ($response->status < 200 || $response->status >= 300) {
            $retry = $response->header('Retry-After');
            throw ChargeAlongException::for(
                $response->status,
                \is_string($body['title'] ?? null) ? $body['title'] : '',
                \is_string($body['detail'] ?? null) ? $body['detail'] : '',
                $retry !== null && ctype_digit($retry) && (int) $retry > 0 ? (int) $retry : null,
            );
        }

        return $body;
    }

    /** @return array<string, mixed> */
    private static function decode(Response $response): array
    {
        try {
            $body = json_decode($response->body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!\is_array($body) || array_is_list($body)) {
            return [];
        }

        /** @var array<string, mixed> $body */
        return $body;
    }

    /**
     * The rows of a list answer, skipping anything that is not a row.
     *
     * @param array<string, mixed> $body
     * @return list<array<string, mixed>>
     */
    private static function rows(array $body): array
    {
        return self::list($body['data'] ?? null);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function list(mixed $value): array
    {
        if (!\is_array($value) || !array_is_list($value)) {
            return [];
        }

        /** @var list<array<string, mixed>> */
        return array_values(array_filter($value, static fn ($row) => \is_array($row) && !array_is_list($row)));
    }

    /**
     * The envelope's `data` when it is an object, or nothing.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private static function object(array $body): array
    {
        $data = $body['data'] ?? null;

        /** @var array<string, mixed> */
        return \is_array($data) && !array_is_list($data) ? $data : [];
    }

    /**
     * Where vehicle figures came from, which a page showing them should say.
     *
     * @param array<string, mixed> $body
     * @return Source
     */
    private static function source(array $body): array
    {
        $s = \is_array($body['source'] ?? null) ? $body['source'] : [];
        $text = static fn (string $key): string => \is_string($s[$key] ?? null) ? $s[$key] : '';

        return ['name' => $text('name'), 'url' => $text('url'), 'licence' => $text('licence')];
    }

    /**
     * One path segment, encoded so it cannot leave its place in the path.
     */
    private static function slug(string $value, string $what): string
    {
        $text = trim($value);
        // rawurlencode leaves dots alone, and "." or ".." as a segment is the
        // collection or its parent, not a name.
        if ($text === '' || $text === '.' || $text === '..') {
            throw new \InvalidArgumentException("chargealong: no $what");
        }

        return rawurlencode($text);
    }

    private static function point(float $lat, float $lng): void
    {
        // Written as a range check NAN cannot pass, rather than as a NAN test.
        if (!($lat >= -90 && $lat <= 90) || !($lng >= -180 && $lng <= 180)) {
            throw new \InvalidArgumentException(
                "chargealong: ($lat, $lng) is not a point on the globe; lat -90..90, lng -180..180",
            );
        }
    }

    /**
     * One end of a trip: `[lat, lng]` or `['lat' => .., 'lng' => ..]`.
     *
     * @param array<array-key, mixed> $end
     * @return array{float, float}
     */
    private static function end(array $end): array
    {
        $pair = array_is_list($end) ? $end : [$end['lat'] ?? null, $end['lng'] ?? null];
        if (\count($pair) !== 2 || !\is_int($pair[0]) && !\is_float($pair[0]) || !\is_int($pair[1]) && !\is_float($pair[1])) {
            throw new \InvalidArgumentException('chargealong: each end of a trip is [lat, lng], two numbers');
        }
        self::point((float) $pair[0], (float) $pair[1]);

        return [(float) $pair[0], (float) $pair[1]];
    }

    /**
     * A figure the service bounds, checked before a request goes out so NAN and
     * out of range values never reach a URL.
     */
    private static function bounded(int|float|null $value, string $name, int $lo, int $hi): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!($value >= $lo && $value <= $hi)) {
            throw new \InvalidArgumentException("chargealong: $name must be a number from $lo to $hi, not $value");
        }

        return \is_int($value) ? (string) $value : self::number($value);
    }

    /**
     * @param array<array-key, mixed> $list
     * @return list<string>
     */
    private static function connectors(array $list, string $name): array
    {
        if ($list === []) {
            throw new \InvalidArgumentException("chargealong: $name is empty; leave it out for any");
        }
        $out = [];
        foreach ($list as $c) {
            if (!\is_string($c) || !\in_array($c, self::CONNECTORS, true)) {
                $shown = \is_scalar($c) ? (string) $c : get_debug_type($c);
                throw new \InvalidArgumentException(
                    "chargealong: $shown is not a connector the service knows: " . implode(', ', self::CONNECTORS),
                );
            }
            $out[] = $c;
        }

        return $out;
    }

    /** A number as the shortest decimal that reads back the same: 50, 77.4, -37.8136. */
    private static function number(float $value): string
    {
        $text = var_export($value, true);
        if (str_contains($text, 'E')) {
            return rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
        }

        return str_ends_with($text, '.0') ? substr($text, 0, -2) : $text;
    }

    /**
     * Refuse a base URL that would send what people ask about somewhere else:
     * https always, and plaintext http only to loopback, for a test server.
     */
    private static function checkBaseUrl(string $raw): string
    {
        $parts = parse_url($raw);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            throw new \InvalidArgumentException("chargealong: base URL $raw is not absolute");
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException(
                "chargealong: base URL $raw carries credentials, and the host it would reach is not the one it reads as",
            );
        }
        if (strpbrk($raw, '?#') !== false) {
            throw new \InvalidArgumentException("chargealong: base URL $raw has a query or a fragment, which would swallow the path");
        }

        $scheme = strtolower($parts['scheme']);
        $loopback = \in_array(strtolower($parts['host']), self::LOOPBACK, true);
        if ($scheme === 'https' || ($scheme === 'http' && $loopback)) {
            return rtrim($raw, '/');
        }
        if ($scheme === 'http') {
            throw new \InvalidArgumentException(
                "chargealong: base URL $raw is plaintext http to a public host, which would send where people are in the clear",
            );
        }

        throw new \InvalidArgumentException("chargealong: base URL $raw has scheme $scheme, want https");
    }
}
