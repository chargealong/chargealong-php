<?php

declare(strict_types=1);

namespace ChargeAlong\Tests;

use ChargeAlong\Client;
use PHPUnit\Framework\TestCase;

/**
 * What Packagist shows. It reads the version from the git tag, so the changelog
 * and the User-Agent are the two places that have to agree with it.
 */
final class PackageTest extends TestCase
{
    private static function read(string $file): string
    {
        return (string) file_get_contents(__DIR__ . '/../' . $file);
    }

    public function testTheChangelogLeadsWithTheVersionTheClientSays(): void
    {
        preg_match('/^## (\S+)/m', self::read('CHANGELOG.md'), $m);
        self::assertSame(Client::VERSION, $m[1] ?? null);
    }

    // Packagist and the README send people to the site, not the raw spec.
    public function testDocumentationPointsAtChargealongIo(): void
    {
        $composer = json_decode(self::read('composer.json'), true, 64, JSON_THROW_ON_ERROR);
        self::assertSame('https://chargealong.io/docs/', $composer['support']['docs']);
        self::assertSame('https://chargealong.io', $composer['homepage']);

        $readme = self::read('README.md');
        self::assertStringContainsString('(https://chargealong.io/docs/)', $readme);
        self::assertStringContainsString('[chargealong.io](https://chargealong.io/)', $readme);
        self::assertStringContainsString('(https://chargealong.io/en/contact/)', $readme);
    }

    public function testComposerHasNoVersionFieldToDriftFromTheTag(): void
    {
        self::assertArrayNotHasKey('version', json_decode(self::read('composer.json'), true, 64, JSON_THROW_ON_ERROR));
    }
}
