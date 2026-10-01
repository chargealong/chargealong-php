<?php

declare(strict_types=1);

namespace ChargeAlong\Tests;

use ChargeAlong\Client;
use ChargeAlong\Exception\BadRequest;
use ChargeAlong\Exception\ChargeAlongException;
use ChargeAlong\Exception\NoRoute;
use ChargeAlong\Exception\NotFound;
use ChargeAlong\Exception\RateLimited;
use ChargeAlong\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private static function client(FakeTransport $transport): Client
    {
        return new Client(transport: $transport);
    }

    // The directory.

    public function testListsEveryCountryWithChargingSites(): void
    {
        $t = FakeTransport::fixture('countries');
        $countries = self::client($t)->countries();

        self::assertSame('/v1/countries', $t->path());
        self::assertSame('australia', $countries[0]['slug']);
        self::assertSame(5076, $countries[0]['site_count']);
    }

    public function testReadsTheOverview(): void
    {
        $t = FakeTransport::fixture('overview');
        $overview = self::client($t)->overview();

        self::assertSame('/v1/overview', $t->path());
        self::assertSame(132525, $overview['sites']);
        self::assertSame('randwick', $overview['countries'][0]['busiest'][0]['slug']);
    }

    public function testReadsACountryARegionAndALocalityByTheirSlugs(): void
    {
        $t = FakeTransport::fixture('country');
        $ca = self::client($t);

        self::assertSame('australia', $ca->country('australia')['country']['slug']);
        self::assertSame('/v1/countries/australia', $t->path());

        $ca->region('australia', 'victoria');
        self::assertSame('/v1/countries/australia/regions/victoria', $t->path());

        $ca->locality('australia', 'victoria', 'coburg');
        self::assertSame('/v1/localities/australia/victoria/coburg', $t->path());
    }

    public function testReadsACountrysNetworksAndOneNetwork(): void
    {
        $t = FakeTransport::fixture('networks');
        $ca = self::client($t);

        $ca->networks('australia');
        self::assertSame('/v1/countries/australia/networks', $t->path());

        $ca->network('australia', 'evie-networks');
        self::assertSame('/v1/countries/australia/networks/evie-networks', $t->path());
    }

    // One site.

    public function testReadsOneSiteWithItsConnectorsAndSources(): void
    {
        $t = FakeTransport::fixture('site');
        $site = self::client($t)->site('hwqb4abf');

        self::assertSame('/v1/sites/hwqb4abf', $t->path());
        self::assertNotEmpty($site['connectors']);
        self::assertNotEmpty($site['sources']);
        self::assertArrayHasKey('alternatives', $site);
    }

    public function testThrowsNotFoundForAnIdWithNothingFiledUnderIt(): void
    {
        $t = FakeTransport::fixture('notfound', 404);
        try {
            self::client($t)->site('zzzzzzzz');
            self::fail('expected NotFound');
        } catch (NotFound $e) {
            self::assertSame(404, $e->status);
        }
    }

    // A removed site keeps answering, so code walking stored ids can retire it.
    public function testAnswersARemovedSiteLikeAnyOtherMarkedRemoved(): void
    {
        $body = FakeTransport::load('site');
        $body['data']['site']['status'] = 'removed';
        $site = self::client(FakeTransport::json($body))->site('hwqb4abf');

        self::assertSame('removed', $site['site']['status']);
    }

    // Search.

    public function testSearchAsksForPlacesPostcodesAndSites(): void
    {
        $t = FakeTransport::fixture('search');
        $found = self::client($t)->search('coburg');

        self::assertSame('/v1/search', $t->path());
        self::assertSame(['q' => 'coburg'], $t->query());
        self::assertSame(['localities', 'postcodes', 'sites'], array_keys($found));
        self::assertNotEmpty($found['localities']);
    }

    public function testSearchSendsThePreferredCountryUpperCased(): void
    {
        $t = FakeTransport::fixture('search');
        self::client($t)->search('coburg', prefer: ' au ');

        self::assertSame(['q' => 'coburg', 'prefer' => 'AU'], $t->query());
    }

    public function testSearchRefusesAPreferredCountryThatIsNotATwoLetterCode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::client(FakeTransport::fixture('search'))->search('coburg', prefer: 'AUS');
    }

    public function testSearchAnswersEmptyWithoutARequestBelowThreeLettersOrDigits(): void
    {
        $t = FakeTransport::fixture('search');
        $ca = self::client($t);

        foreach (['', 'ab', ' a b ', '!!a?', 'é1'] as $term) {
            self::assertSame(['localities' => [], 'postcodes' => [], 'sites' => []], $ca->search($term));
        }
        self::assertSame([], $t->calls);

        // Letters, not bytes: three accented letters are three.
        $ca->search('éèê');
        self::assertCount(1, $t->calls);
    }

    public function testSearchRefusesATermLongerThanTheServiceReads(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::client(FakeTransport::fixture('search'))->search(str_repeat('a', 101));
    }

    public function testSearchFillsInListsTheServiceLeftOut(): void
    {
        $found = self::client(FakeTransport::json(['data' => ['sites' => []]]))->search('coburg');

        self::assertSame(['localities' => [], 'postcodes' => [], 'sites' => []], $found);
    }

    // Nearby.

    public function testNearbySendsThePointAndTheFiltersByTheirWireNames(): void
    {
        $t = FakeTransport::fixture('nearby');
        $near = self::client($t)->nearby(
            -37.8136,
            144.9631,
            minKw: 50,
            radiusKm: 20,
            limit: 5,
            connector: 'ccs2',
            network: ' evie-networks ',
        );

        self::assertSame('/v1/nearby', $t->path());
        self::assertSame([
            ['lat', '-37.8136'],
            ['lng', '144.9631'],
            ['min_kw', '50'],
            ['radius_km', '20'],
            ['limit', '5'],
            ['connector', 'ccs2'],
            ['network', 'evie-networks'],
        ], $t->pairs());
        self::assertArrayHasKey('near', $near);
        self::assertNotEmpty($near['sites']);
    }

    // The service reads connector=ccs2&connector=type-2 as "either of these".
    public function testNearbyRepeatsConnectorForMoreThanOne(): void
    {
        $t = FakeTransport::fixture('nearby');
        self::client($t)->nearby(-37.8136, 144.9631, connector: ['ccs2', 'type-2']);

        self::assertSame([['lat', '-37.8136'], ['lng', '144.9631'], ['connector', 'ccs2'], ['connector', 'type-2']], $t->pairs());
    }

    public function testNearbyLeavesOffEveryFilterNotAskedFor(): void
    {
        $t = FakeTransport::fixture('nearby');
        self::client($t)->nearby(-37.8136, 144.9631);

        self::assertSame(['lat' => '-37.8136', 'lng' => '144.9631'], $t->query());
    }

    public function testNearbyFillsInWhatTheServiceLeftOut(): void
    {
        self::assertSame(
            ['near' => null, 'sites' => []],
            self::client(FakeTransport::json(['data' => []]))->nearby(-37.8136, 144.9631),
        );
    }

    public function testNearbyRefusesAConnectorTheServiceDoesNotKnow(): void
    {
        $t = FakeTransport::fixture('nearby');
        try {
            self::client($t)->nearby(-37.8136, 144.9631, connector: ['ccs2', 'tesla']);
            self::fail('expected the connector to be refused');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('tesla', $e->getMessage());
        }
        self::assertSame([], $t->calls);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function nearbyOutOfBounds(): iterable
    {
        yield 'minKw' => [['minKw' => 401]];
        yield 'radiusKm' => [['radiusKm' => 0.5]];
        yield 'limit' => [['limit' => 51]];
        yield 'no connectors' => [['connector' => []]];
    }

    /** @param array<string, mixed> $args */
    #[\PHPUnit\Framework\Attributes\DataProvider('nearbyOutOfBounds')]
    public function testNearbyRefusesFiltersOutsideTheServicesBoundsBeforeAsking(array $args): void
    {
        $t = FakeTransport::fixture('nearby');
        try {
            self::client($t)->nearby(-37.8136, 144.9631, ...$args);
            self::fail('expected the filter to be refused');
        } catch (\InvalidArgumentException) {
        }
        self::assertSame([], $t->calls);
    }

    // Trips.

    public function testPlanTripSendsEachEndAsLatitudeCommaLongitudeAndTheCarsFigures(): void
    {
        $t = FakeTransport::fixture('trip');
        $plan = self::client($t)->planTrip(
            [-37.8136, 144.9631],
            ['lat' => -33.8688, 'lng' => 151.2093],
            rangeKm: 400,
            reserve: 15,
            start: 90,
            chargeTo: 85,
            batteryKwh: 77.4,
            maxDcKw: 220,
            maxAcKw: 11,
            minKw: 75,
            connectors: ['ccs2', 'type-2'],
        );

        self::assertSame('/v1/trips/plan', $t->path());
        self::assertSame([
            'from' => '-37.8136,144.9631',
            'to' => '-33.8688,151.2093',
            'range_km' => '400',
            'reserve' => '15',
            'start' => '90',
            'charge_to' => '85',
            'battery_kwh' => '77.4',
            'max_dc_kw' => '220',
            'max_ac_kw' => '11',
            'min_kw' => '75',
            // One parameter, comma separated: the planner reads it that way.
            'connectors' => 'ccs2,type-2',
        ], $t->query());
        self::assertTrue($plan['feasible']);
        self::assertNotEmpty($plan['route']['line']);
    }

    public function testPlanTripSendsOnlyWhatWasGivenSoTheServicesDefaultsApply(): void
    {
        $t = FakeTransport::fixture('trip');
        self::client($t)->planTrip([-37.8136, 144.9631], [-33.8688, 151.2093], rangeKm: 400);

        self::assertSame(['from', 'to', 'range_km'], array_keys($t->query()));
    }

    public function testPlanTripSaysWhereAnInfeasibleTripRunsOut(): void
    {
        $body = FakeTransport::load('trip');
        $body['data']['feasible'] = false;
        $body['data']['gap'] = ['from_km' => 210.5, 'to_km' => 640.2];
        unset($body['data']['arrival_pct']);

        $plan = self::client(FakeTransport::json($body))->planTrip([-37.8, 144.9], [-33.8, 151.2], rangeKm: 200);

        self::assertFalse($plan['feasible']);
        self::assertSame(210.5, $plan['gap']['from_km']);
    }

    public function testPlanTripRefusesATripThatStartsWhereItEnds(): void
    {
        $t = FakeTransport::fixture('trip');
        try {
            self::client($t)->planTrip([-37.8136, 144.9631], [-37.8136, 144.9631], rangeKm: 400);
            self::fail('expected the trip to be refused');
        } catch (\InvalidArgumentException) {
        }
        self::assertSame([], $t->calls);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function tripOutOfBounds(): iterable
    {
        yield 'range too short' => [['rangeKm' => 49]];
        yield 'range too long' => [['rangeKm' => 1501]];
        yield 'reserve' => [['rangeKm' => 400, 'reserve' => 51]];
        yield 'chargeTo' => [['rangeKm' => 400, 'chargeTo' => 49]];
        yield 'batteryKwh' => [['rangeKm' => 400, 'batteryKwh' => 251]];
        yield 'maxDcKw' => [['rangeKm' => 400, 'maxDcKw' => 19]];
        yield 'maxAcKw' => [['rangeKm' => 400, 'maxAcKw' => 23]];
        yield 'minKw' => [['rangeKm' => 400, 'minKw' => 351]];
        yield 'connectors' => [['rangeKm' => 400, 'connectors' => ['ccs3']]];
    }

    /** @param array<string, mixed> $args */
    #[\PHPUnit\Framework\Attributes\DataProvider('tripOutOfBounds')]
    public function testPlanTripRefusesFiguresOutsideTheServicesBoundsBeforeAsking(array $args): void
    {
        $t = FakeTransport::fixture('trip');
        try {
            self::client($t)->planTrip([-37.8, 144.9], [-33.8, 151.2], ...$args);
            self::fail('expected the figure to be refused');
        } catch (\InvalidArgumentException) {
        }
        self::assertSame([], $t->calls);
    }

    /** @return iterable<string, array{mixed}> */
    public static function notPoints(): iterable
    {
        yield 'one number' => [[-37.8]];
        yield 'three numbers' => [[-37.8, 144.9, 0]];
        yield 'off the globe' => [[-91, 144.9]];
        yield 'NAN' => [[NAN, 144.9]];
        yield 'words' => [['lat' => 'south', 'lng' => 'east']];
        yield 'empty' => [[]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('notPoints')]
    public function testPlanTripRefusesAnEndThatIsNotAPoint(mixed $from): void
    {
        $t = FakeTransport::fixture('trip');
        try {
            self::client($t)->planTrip($from, [-33.8, 151.2], rangeKm: 400);
            self::fail('expected the point to be refused');
        } catch (\InvalidArgumentException) {
        }
        self::assertSame([], $t->calls);
    }

    // No road at all is an answer to show, not a parameter to fix.
    public function testNamesARouteThatDoesNotExistAsNoRouteWithTheServicesWords(): void
    {
        $t = FakeTransport::json(['title' => 'No route', 'detail' => 'no road connects these points', 'status' => 422], 422);
        try {
            self::client($t)->planTrip([-33.8688, 151.2093], [-36.8485, 174.7633], rangeKm: 400);
            self::fail('expected NoRoute');
        } catch (NoRoute $e) {
            self::assertInstanceOf(BadRequest::class, $e);
            self::assertSame('no road connects these points', $e->detail);
        }
    }

    // Vehicles.

    public function testFindsCarsAndCarriesWhereTheFiguresCameFrom(): void
    {
        $t = FakeTransport::fixture('vehicles');
        $found = self::client($t)->vehicles(q: ' ioniq ', limit: 5);

        self::assertSame('/v1/vehicles', $t->path());
        self::assertSame(['q' => 'ioniq', 'limit' => '5'], $t->query());
        self::assertNotEmpty($found['vehicles']);
        self::assertNotSame('', $found['source']['name']);
        self::assertNotSame('', $found['source']['licence']);
    }

    public function testAsksForEveryCarOnlyWhenTold(): void
    {
        $t = FakeTransport::fixture('vehicles');
        $ca = self::client($t);

        $ca->vehicles();
        self::assertSame([], $t->query());

        $ca->vehicles(all: true);
        self::assertSame(['all' => 'true'], $t->query());
    }

    public function testReadsOneCarWithHowLongItTakesToCharge(): void
    {
        $t = FakeTransport::fixture('vehicle');
        $found = self::client($t)->vehicle('hyundai-ioniq-5');

        self::assertSame('/v1/vehicles/hyundai-ioniq-5', $t->path());
        self::assertNotEmpty($found['vehicle']['charging']);
        self::assertNotSame('', $found['source']['name']);
    }

    public function testRefusesAVehicleLimitOutsideOneToAHundred(): void
    {
        $t = FakeTransport::fixture('vehicles');
        foreach ([0, 101] as $limit) {
            try {
                self::client($t)->vehicles(limit: $limit);
                self::fail("expected limit $limit to be refused");
            } catch (\InvalidArgumentException) {
            }
        }
        self::assertSame([], $t->calls);
    }

    // Redirects.

    public function testSaysWhereAPageWent(): void
    {
        $moved = ['from' => '/en/australia/victoria/coburg/evie/old-abcd2345/', 'to' => '/en/australia/victoria/coburg/evie/new-abcd2345/', 'status' => 301];
        $t = FakeTransport::json(['data' => $moved]);

        self::assertSame($moved, self::client($t)->redirect($moved['from']));
        self::assertSame(['path' => $moved['from']], $t->query());
    }

    public function testAnswersNullForAPageThatNeverMoved(): void
    {
        self::assertNull(self::client(FakeTransport::fixture('notfound', 404))->redirect('/en/australia/'));
    }

    // Errors.

    public function testKeepsTheServicesOwnWords(): void
    {
        try {
            self::client(FakeTransport::fixture('badnearby', 400))->nearby(-37.8, 144.9);
            self::fail('expected BadRequest');
        } catch (BadRequest $e) {
            $problem = FakeTransport::load('badnearby');
            self::assertSame(400, $e->status);
            self::assertSame($problem['title'], $e->title);
            self::assertSame($problem['detail'], $e->detail);
            self::assertStringContainsString($problem['detail'], $e->getMessage());
        }
    }

    public function testSaysHowLongToWaitWhenRateLimited(): void
    {
        try {
            self::client(new FakeTransport('{"title":"Too many requests"}', 429, ['retry-after' => '30']))->countries();
            self::fail('expected RateLimited');
        } catch (RateLimited $e) {
            self::assertSame(30, $e->retryAfter);
        }
    }

    public function testUsesTheStatusWhenTheBodyIsNotAProblemDocument(): void
    {
        try {
            self::client(new FakeTransport('<html>bad gateway</html>', 502))->countries();
            self::fail('expected an exception');
        } catch (ChargeAlongException $e) {
            self::assertSame(502, $e->status);
            self::assertSame('HTTP 502', $e->getMessage());
        }
    }

    // What every request carries.

    public function testAsksForJsonAndSaysWhoIsAsking(): void
    {
        $t = FakeTransport::fixture('countries');
        self::client($t)->countries();

        self::assertSame('application/json', $t->headers()['Accept']);
        self::assertSame('chargealong-php/' . Client::VERSION, $t->headers()['User-Agent']);
    }

    public function testListsTheConnectorStandardsTheServiceKnows(): void
    {
        self::assertSame(['ccs2', 'chademo', 'type-2', 'nacs', 'ccs1', 'type-1', 'gbt'], Client::CONNECTORS);
    }
}
