<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Service\Gsc;

/**
 * Pure helpers around Search Analytics rows, brand split, visibility and Matomo join.
 */
final class GscNormalizer
{
    /**
     * Generic organic CTR per position, used as position weight of the visibility index.
     */
    private const CTR_CURVE = [
        1 => 0.275, 2 => 0.155, 3 => 0.100, 4 => 0.070, 5 => 0.050,
        6 => 0.040, 7 => 0.030, 8 => 0.025, 9 => 0.020, 10 => 0.018,
    ];

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{clicks: int, impressions: int, ctr: float, position: float}
     */
    public static function kpisFromRows(array $rows): array
    {
        $clicks = 0;
        $impressions = 0;
        $weightedPosition = 0.0;
        foreach ($rows as $row) {
            $clicks += (int)($row['clicks'] ?? 0);
            $impressions += (int)($row['impressions'] ?? 0);
            $weightedPosition += (float)($row['position'] ?? 0) * (int)($row['impressions'] ?? 0);
        }
        return [
            'clicks' => $clicks,
            'impressions' => $impressions,
            'ctr' => $impressions > 0 ? $clicks / $impressions : 0.0,
            'position' => $impressions > 0 ? $weightedPosition / $impressions : 0.0,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{date: string, clicks: int, impressions: int}>
     */
    public static function dateSeries(array $rows): array
    {
        return array_map(static fn(array $r): array => [
            'date' => (string)($r['keys'][0] ?? ''),
            'clicks' => (int)($r['clicks'] ?? 0),
            'impressions' => (int)($r['impressions'] ?? 0),
        ], $rows);
    }

    /**
     * Aligns the compare period by position (day offset), not by calendar date.
     *
     * @param list<array<string, mixed>> $main
     * @param list<array<string, mixed>> $compare
     * @return list<array<string, mixed>>
     */
    public static function mergeCompareSeries(array $main, array $compare): array
    {
        foreach ($main as $i => $point) {
            if (isset($compare[$i])) {
                $main[$i]['clicksCompare'] = $compare[$i]['clicks'];
                $main[$i]['impressionsCompare'] = $compare[$i]['impressions'];
                $main[$i]['compareDate'] = $compare[$i]['date'];
            }
        }
        return $main;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function topRows(array $rows, string $key): array
    {
        return array_map(static fn(array $r): array => [
            $key => (string)($r['keys'][0] ?? ''),
            'clicks' => (int)($r['clicks'] ?? 0),
            'impressions' => (int)($r['impressions'] ?? 0),
            'ctr' => (float)($r['ctr'] ?? 0),
            'position' => (float)($r['position'] ?? 0),
        ], $rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<array<string, mixed>> $compareRows
     * @return list<array<string, mixed>>
     */
    public static function addRowsDelta(array $rows, array $compareRows, string $key, string $metric): array
    {
        if ($compareRows === []) {
            return $rows;
        }
        $compareMap = [];
        foreach ($compareRows as $r) {
            $compareMap[$r[$key]] = $r[$metric];
        }
        return array_map(static function (array $r) use ($compareMap, $key, $metric): array {
            $compare = $compareMap[$r[$key]] ?? null;
            if ($compare === null) {
                return $r;
            }
            if ($compare == 0) {
                return $r + [$metric . 'Compare' => 0, $metric . 'Delta' => null, $metric . 'DeltaPos' => null];
            }
            $pct = (($r[$metric] - $compare) / $compare) * 100;
            return $r + [
                $metric . 'Compare' => $compare,
                $metric . 'Delta' => ($pct >= 0 ? '+' : '') . number_format($pct, 1, '.', '') . '%',
                $metric . 'DeltaPos' => $pct >= 0,
            ];
        }, $rows);
    }

    /**
     * @param list<string> $terms
     */
    public static function brandRegex(array $terms): ?string
    {
        $terms = array_values(array_filter(array_map('trim', $terms), static fn(string $t): bool => $t !== ''));
        if ($terms === []) {
            return null;
        }
        // Unicode aware "whole word": no letter, digit or underscore directly before/after the term
        return '/(?<![\p{L}\p{N}_])(?:' . implode('|', array_map(static fn(string $t): string => preg_quote($t, '/'), $terms)) . ')(?![\p{L}\p{N}_])/iu';
    }

    /**
     * @param list<array<string, mixed>> $rows Search Analytics rows with query dimension
     * @return array{brand: int, nonBrand: int}
     */
    public static function splitBrand(array $rows, ?string $brandRegex): array
    {
        $brand = 0;
        $nonBrand = 0;
        foreach ($rows as $row) {
            $query = (string)($row['keys'][0] ?? '');
            $clicks = (int)($row['clicks'] ?? 0);
            if ($brandRegex !== null && preg_match($brandRegex, $query) === 1) {
                $brand += $clicks;
            } else {
                $nonBrand += $clicks;
            }
        }
        return ['brand' => $brand, 'nonBrand' => $nonBrand];
    }

    public static function ctrWeight(float $position): float
    {
        $p = max(1, (int)round($position));
        if ($p <= 10) {
            return self::CTR_CURVE[$p];
        }
        return $p <= 20 ? 0.012 : 0.005;
    }

    /**
     * Visibility = sum of impressions x CTR weight of the position.
     *
     * @param list<array<string, mixed>> $rows
     */
    public static function visibilityScore(array $rows): float
    {
        $raw = 0.0;
        foreach ($rows as $row) {
            $raw += (int)($row['impressions'] ?? 0) * self::ctrWeight((float)($row['position'] ?? 0));
        }
        return round($raw, 2);
    }

    public static function extractPath(string $urlOrPath): string
    {
        $path = $urlOrPath;
        if (str_starts_with($urlOrPath, 'http')) {
            $path = (string)(parse_url($urlOrPath, PHP_URL_PATH) ?? '/');
        }
        if (strlen($path) > 1 && str_ends_with($path, '/')) {
            $path = substr($path, 0, -1);
        }
        return $path;
    }

    /**
     * Top rows by clicks, ties broken by impressions (Search Console sorts ties alphabetically).
     *
     * @param list<array<string, mixed>> $rows raw rows
     * @return list<array<string, mixed>>
     */
    public static function topRowsByClicks(array $rows, string $key, int $limit): array
    {
        usort($rows, static fn(array $a, array $b): int => [(int)($b['clicks'] ?? 0), (int)($b['impressions'] ?? 0)]
            <=> [(int)($a['clicks'] ?? 0), (int)($a['impressions'] ?? 0)]);
        return self::topRows(array_slice($rows, 0, $limit), $key);
    }

    /**
     * Clicks and impressions of anonymized queries: the total minus the sum of all reported queries.
     *
     * @param array{clicks: int, impressions: int} $total
     * @param list<array<string, mixed>> $queryRows raw rows with dimension "query"
     * @return array{clicks: int, impressions: int}
     */
    public static function anonymizedShare(array $total, array $queryRows): array
    {
        $clicks = 0;
        $impressions = 0;
        foreach ($queryRows as $row) {
            $clicks += (int)($row['clicks'] ?? 0);
            $impressions += (int)($row['impressions'] ?? 0);
        }
        return [
            'clicks' => max(0, $total['clicks'] - $clicks),
            'impressions' => max(0, $total['impressions'] - $impressions),
        ];
    }

    /**
     * RE2 pattern for the "page" dimension that matches the path of a URL with and without
     * trailing slash on any host. Search Console reports the canonical URL, TYPO3 may generate
     * the other variant – or another host, e.g. in a development environment.
     */
    public static function pageRegex(string $url): string
    {
        $path = (string)(parse_url($url, PHP_URL_PATH) ?? '');
        return '^https?://[^/]+' . preg_quote(rtrim($path, '/')) . '/?$';
    }

    /**
     * Adds Matomo pageviews to Search Console page rows (joined by path).
     *
     * @param list<array<string, mixed>> $gscPages
     * @param list<array<string, mixed>> $matomoPages
     * @return list<array<string, mixed>>
     */
    public static function joinMatomoVisits(array $gscPages, array $matomoPages): array
    {
        if ($matomoPages === []) {
            return $gscPages;
        }
        $map = [];
        foreach ($matomoPages as $m) {
            $map[self::extractPath((string)$m['url'])] = $m['pageviews'];
        }
        return array_map(static function (array $g) use ($map): array {
            $visits = $map[self::extractPath((string)$g['page'])] ?? null;
            return $visits === null ? $g : $g + ['matomoVisits' => $visits];
        }, $gscPages);
    }
}
