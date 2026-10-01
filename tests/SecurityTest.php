<?php

declare(strict_types=1);

namespace ChargeAlong\Tests;

use ChargeAlong\Client;
use ChargeAlong\Exception\ChargeAlongException;
use ChargeAlong\Paths;
use ChargeAlong\Tests\Support\FakeTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The controls that stop this client being turned against its caller.
 *
 * There is no key to leak: the API is keyless. What passes through instead is
 * where somebody is and where they are driving, and the URLs this client builds
 * out of whatever a caller hands it. So: the destination cannot be moved off
 * https, a slug cannot walk out of its path, nothing reaches a query unencoded,
 * no NaN or point off the globe is sent, and no row can link off the site.
 */
final class SecurityTest extends TestCase
{
    // Where requests may go.

    public function testDefaultsToHttps(): void
    {
        self::assertStringStartsWith('https://', Client::DEFAULT_BASE_URL);
    }

    public function testRefusesPlaintextHttpToAPublicHost(): void
    {
        $this->expectExceptionMessageMatches('/clear/');
        new Client(baseUrl: 'http://api.chargealong.io');
    }

    /** @return iterable<array{string}> */
    public static function loopback(): iterable
    {
        yield ['http://localhost:8080'];
        yield ['http://127.0.0.1:8080'];
        yield ['http://[::1]:8080'];
    }

    #[DataProvider('loopback')]
    public function testAllowsLoopbackOverHttpForATestServer(string $baseUrl): void
    {
        $t = FakeTransport::fixture('countries');
        (new Client(baseUrl: $baseUrl, transport: $t))->countries();
        self::assertStringStartsWith($baseUrl . '/v1/countries', $t->url());
    }

    /** @return iterable<string, array{string}> */
    public static function refusedBaseUrls(): iterable
    {
        yield 'a host that only starts like localhost' => ['http://localhost.evil.example'];
        yield 'a host that only starts like 127.0.0.1' => ['http://127.0.0.1.evil.example'];
        yield 'ftp' => ['ftp://api.chargealong.io'];
        yield 'file' => ['file:///etc/passwd'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'empty' => [''];
        yield 'no scheme' => ['api.chargealong.io'];
        yield 'relative' => ['/v1'];
        yield 'credentials' => ['https://api.chargealong.io:pass@evil.example'];
        yield 'a query' => ['https://api.chargealong.io?x=1'];
        yield 'a fragment' => ['https://api.chargealong.io#x'];
    }

    #[DataProvider('refusedBaseUrls')]
    public function testRefusesABaseUrlThatWouldSendRequestsSomewhereElse(string $baseUrl): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Client(baseUrl: $baseUrl);
    }

    public function testNamesCredentialsWhenItRefusesThem(): void
    {
        $this->expectExceptionMessageMatches('/credentials/');
        new Client(baseUrl: 'https://api.chargealong.io:pass@evil.example');
    }

    public function testKeepsEveryRequestOnTheHostAndPrefixItWasGiven(): void
    {
        $t = FakeTransport::json(['data' => []]);
        $ca = new Client(baseUrl: 'https://proxy.example.com/ev/', transport: $t);
        $ca->countries();
        $ca->site('hwqb4abf');
        $ca->nearby(1, 1);
        $ca->vehicle('tesla-model-3');

        foreach ($t->calls as $call) {
            self::assertSame('proxy.example.com', parse_url($call['url'], PHP_URL_HOST));
            self::assertStringStartsWith('/ev/v1/', (string) parse_url($call['url'], PHP_URL_PATH));
        }
    }

    // What reaches the wire.

    public function testDoesNotLetAnIdOrASlugWalkOutOfItsPath(): void
    {
        $t = FakeTransport::json(['data' => []]);
        $ca = new Client(transport: $t);
        foreach (['../admin/queue', '..%2Fadmin', 'a/b', 'x?all=true', 'x#frag'] as $id) {
            $ca->site($id);
            self::assertCount(4, explode('/', $t->path()), $id);
            self::assertStringStartsWith('/v1/sites/', $t->path());
            self::assertNull(parse_url($t->url(), PHP_URL_QUERY));
            self::assertNull(parse_url($t->url(), PHP_URL_FRAGMENT));

            $ca->locality('australia', $id, 'coburg');
            self::assertCount(6, explode('/', $t->path()), $id);
        }
    }

    public function testRefusesAnEmptySlugAndOneNamingTheCollectionOrItsParent(): void
    {
        $t = FakeTransport::json(['data' => []]);
        $ca = new Client(transport: $t);
        foreach (['', '  ', '.', '..'] as $bad) {
            foreach ([fn () => $ca->site($bad), fn () => $ca->country($bad), fn () => $ca->vehicle($bad)] as $call) {
                try {
                    $call();
                    self::fail("expected '$bad' to be refused");
                } catch (\InvalidArgumentException) {
                }
            }
        }
        self::assertSame([], $t->calls);
    }

    public function testDoesNotLetASearchTermInjectAnotherParameter(): void
    {
        $t = FakeTransport::fixture('search');
        (new Client(transport: $t))->search('coburg&prefer=US');

        self::assertSame(['q' => 'coburg&prefer=US'], $t->query());
    }

    public function testDoesNotLetANetworkNameInjectAnotherParameter(): void
    {
        $t = FakeTransport::fixture('nearby');
        (new Client(transport: $t))->nearby(1, 1, network: 'evie&limit=50');

        self::assertSame(['lat' => '1', 'lng' => '1', 'network' => 'evie&limit=50'], $t->query());
    }

    public function testDoesNotLetASearchCarryANewlineIntoTheRequest(): void
    {
        $t = FakeTransport::fixture('search');
        (new Client(transport: $t))->search("coburg\r\nX-Injected: 1");

        self::assertDoesNotMatchRegularExpression('/[\r\n]/', $t->url());
    }

    /** @return iterable<array{float, float}> */
    public static function offTheGlobe(): iterable
    {
        yield [NAN, 0];
        yield [0, INF];
        yield [91, 0];
        yield [0, -181];
    }

    #[DataProvider('offTheGlobe')]
    public function testNeverSendsNanInfinityOrAPointOffTheGlobe(float $lat, float $lng): void
    {
        $t = FakeTransport::json(['data' => []]);
        $ca = new Client(transport: $t);
        foreach ([fn () => $ca->nearby($lat, $lng), fn () => $ca->planTrip([$lat, $lng], [1, 1], rangeKm: 400)] as $call) {
            try {
                $call();
                self::fail('expected the point to be refused');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('globe', $e->getMessage());
            }
        }
        self::assertSame([], $t->calls);
    }

    public function testNeverSendsANanFigure(): void
    {
        $t = FakeTransport::json(['data' => []]);
        $ca = new Client(transport: $t);
        foreach ([fn () => $ca->nearby(1, 1, minKw: NAN), fn () => $ca->planTrip([1, 1], [2, 2], rangeKm: NAN)] as $call) {
            try {
                $call();
                self::fail('expected NAN to be refused');
            } catch (\InvalidArgumentException) {
            }
        }
        self::assertSame([], $t->calls);
    }

    public function testRefusesARedirectPathThatWouldPointOffTheSite(): void
    {
        $t = FakeTransport::json(['data' => null]);
        $ca = new Client(transport: $t);
        foreach (['//evil.example/', '/x?y=https://evil.example', 'http://evil.example/', '/\\evil.example', 'en/australia/'] as $path) {
            try {
                $ca->redirect($path);
                self::fail("expected $path to be refused");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('path', $e->getMessage());
            }
        }
        self::assertSame([], $t->calls);
    }

    public function testSendsNoCredentialHeaderAndNoCookies(): void
    {
        $t = FakeTransport::fixture('countries');
        (new Client(transport: $t))->countries();

        $names = array_map('strtolower', array_keys($t->headers()));
        self::assertNotContains('authorization', $names);
        self::assertNotContains('cookie', $names);
    }

    // What comes back.

    /** @return iterable<array{string}> */
    public static function oddBodies(): iterable
    {
        yield ['[1,2,3]'];
        yield ['"gotcha"'];
        yield ['null'];
        yield ['42'];
        yield [''];
        yield ['{'];
        yield ['{"data":"not a list"}'];
        yield ['{"data":[1,"two",null]}'];
    }

    #[DataProvider('oddBodies')]
    public function testSurvivesABodyThatIsNotWhatItShouldBe(string $body): void
    {
        self::assertSame([], (new Client(transport: new FakeTransport($body)))->countries());
    }

    public function testDoesNotLetAProblemDocumentDecideWhetherItFailed(): void
    {
        $ca = new Client(transport: FakeTransport::json(['data' => [], 'title' => 'error', 'status' => 500]));
        self::assertSame([], $ca->countries());

        try {
            (new Client(transport: FakeTransport::json(['data' => [['slug' => 'surprise']]], 500)))->countries();
            self::fail('expected the 500 to raise');
        } catch (ChargeAlongException $e) {
            self::assertSame(500, $e->status);
        }
    }

    public function testTreatsARedirectAsAFailureRatherThanAnAnswer(): void
    {
        $t = new FakeTransport('', 302, ['location' => 'https://evil.example/']);
        try {
            (new Client(transport: $t))->countries();
            self::fail('expected the redirect to raise');
        } catch (ChargeAlongException $e) {
            self::assertSame(302, $e->status);
        }
    }

    public function testDoesNotLetAnApiRowSendALinkOffTheSite(): void
    {
        self::assertSame('', Paths::pageUrl(['path' => '/\\evil.example']));
        self::assertSame('', Paths::pageUrl(['path' => '//evil.example/']));
        self::assertSame('', Paths::pageUrl(['path' => 'javascript:alert(1)']));
        self::assertSame('', Paths::pageUrl(['path' => '/x?next=https://evil.example']));
    }
}
