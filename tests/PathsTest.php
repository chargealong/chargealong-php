<?php

declare(strict_types=1);

namespace ChargeAlong\Tests;

use ChargeAlong\Paths;
use PHPUnit\Framework\TestCase;

final class PathsTest extends TestCase
{
    public function testTurnsARowsPathIntoThePagesAddress(): void
    {
        $path = '/en/australia/victoria/coburg/evie-networks/coburg-hwqb4abf/';
        self::assertSame('https://chargealong.io' . $path, Paths::pageUrl(['path' => $path]));
        self::assertSame('https://chargealong.io' . $path, Paths::pageUrl($path));
    }

    public function testGivesNothingForARowWithoutAPath(): void
    {
        self::assertSame('', Paths::pageUrl([]));
        self::assertSame('', Paths::pageUrl(['path' => null]));
        self::assertSame('', Paths::pageUrl(''));
    }

    public function testBuildsThePagesRowsWithoutAPathOfTheirOwnLinkTo(): void
    {
        self::assertSame('/en/australia/', Paths::country('australia'));
        self::assertSame('/en/australia/victoria/', Paths::region('australia', 'victoria'));
        self::assertSame('/en/australia/victoria/coburg/', Paths::locality('australia', 'victoria', 'coburg'));
        self::assertSame('/en/australia/networks/', Paths::networks('australia'));
        self::assertSame('/en/australia/networks/chargefox/', Paths::network('australia', 'chargefox'));
        self::assertSame('/en/vehicles/tesla-model-3/', Paths::vehicle('tesla-model-3'));
    }

    // A path from half a row is a page that does not exist.
    public function testGivesNothingWhenAPartIsMissing(): void
    {
        self::assertSame('', Paths::country(''));
        self::assertSame('', Paths::region('australia', ''));
        self::assertSame('', Paths::locality('australia', '', 'coburg'));
        self::assertSame('', Paths::network('', 'chargefox'));
        self::assertSame('', Paths::vehicle(''));
    }

    public function testRoundsADistanceToMetres(): void
    {
        self::assertSame(560, Paths::metres(0.5604));
        self::assertSame(1200, Paths::metres(1.2));
    }
}
