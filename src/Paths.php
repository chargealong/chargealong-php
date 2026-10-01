<?php

declare(strict_types=1);

namespace ChargeAlong;

/**
 * Where a row lives on chargealong.io.
 *
 * Sites, places and trip stops carry their page's `path`, so linking to the
 * site needs no second request. The builders cover the rows that carry only
 * slugs, building the path the site itself serves.
 */
final class Paths
{
    public const SITE_URL = 'https://chargealong.io';

    /** The site serves one language today and its paths carry the prefix. */
    private const LANG = 'en';

    /**
     * A row's page as an absolute URL, or '' for a row without one.
     *
     * Only a path on this site becomes a link: a row claiming `//elsewhere`, a
     * scheme or a backslash gives nothing rather than a link off the site.
     *
     * @param array{path?: string|null}|string $row
     */
    public static function pageUrl(array|string $row): string
    {
        $path = \is_string($row) ? $row : ($row['path'] ?? '');
        if (
            !\is_string($path)
            || !str_starts_with($path, '/')
            || str_starts_with($path, '//')
            || str_contains($path, '\\')
            || str_contains($path, '://')
        ) {
            return '';
        }

        return self::SITE_URL . $path;
    }

    /** `/en/australia/` */
    public static function country(string $country): string
    {
        return self::join($country);
    }

    /** `/en/australia/victoria/` */
    public static function region(string $country, string $region): string
    {
        return self::join($country, $region);
    }

    /** `/en/australia/victoria/coburg/` */
    public static function locality(string $country, string $region, string $locality): string
    {
        return self::join($country, $region, $locality);
    }

    /** `/en/australia/networks/` */
    public static function networks(string $country): string
    {
        return $country === '' ? '' : self::join($country, 'networks');
    }

    /** `/en/australia/networks/chargefox/` */
    public static function network(string $country, string $network): string
    {
        return $country === '' || $network === '' ? '' : self::join($country, 'networks', $network);
    }

    /** `/en/vehicles/tesla-model-3/` */
    public static function vehicle(string $slug): string
    {
        return $slug === '' ? '' : self::join('vehicles', $slug);
    }

    /** Metres, rounded. What a page prints: "560 m", not "0.56 km". */
    public static function metres(float $distanceKm): int
    {
        return (int) round($distanceKm * 1000);
    }

    /** A path from every part, or '' when one is missing. */
    private static function join(string ...$parts): string
    {
        foreach ($parts as $part) {
            if ($part === '') {
                return '';
            }
        }

        return '/' . self::LANG . '/' . implode('/', $parts) . '/';
    }
}
