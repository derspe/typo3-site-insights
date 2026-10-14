<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Service\Matomo;

/**
 * Turns raw Matomo API responses into chart and table friendly structures.
 * Series values use neutral keys (visitors, conversions); labels are added in the UI.
 */
final class MatomoNormalizer
{
    private static function formatHour(string|int $key): string
    {
        $key = (string)$key;
        if (preg_match('/^\d{1,2}$/', $key) === 1 && (int)$key <= 23) {
            return str_pad((string)(int)$key, 2, '0', STR_PAD_LEFT) . ':00';
        }
        return $key;
    }

    /**
     * @return list<array{date: string, value: int}>
     */
    public static function visitorSeries(mixed $data): array
    {
        if (!is_array($data)) {
            return [];
        }
        // VisitTime.getVisitInformationPerLocalTime returns a list of hours
        if (array_is_list($data)) {
            return array_map(static function (mixed $entry): array {
                $hour = (int)explode(':', (string)($entry['label'] ?? '0'))[0];
                return ['date' => str_pad((string)$hour, 2, '0', STR_PAD_LEFT) . ':00', 'value' => (int)($entry['nb_visits'] ?? 0)];
            }, $data);
        }
        if (array_key_exists('nb_visits', $data)) {
            return [['date' => 'day', 'value' => (int)($data['nb_visits'] ?? 0)]];
        }
        $series = [];
        foreach ($data as $date => $value) {
            $series[] = ['date' => self::formatHour($date), 'value' => is_array($value) ? (int)($value['nb_visits'] ?? 0) : 0];
        }
        return $series;
    }

    /**
     * @return list<array{date: string, value: int}>
     */
    public static function goalSeries(mixed $data): array
    {
        if (!is_array($data)) {
            return [];
        }
        if (array_key_exists('nb_conversions', $data)) {
            return [['date' => 'day', 'value' => (int)($data['nb_conversions'] ?? 0)]];
        }
        $series = [];
        foreach ($data as $date => $value) {
            $series[] = ['date' => self::formatHour($date), 'value' => is_array($value) ? (int)($value['nb_conversions'] ?? 0) : 0];
        }
        return $series;
    }

    /**
     * @return list<array{url: string, pageviews: int, uniquePageviews: int}>
     */
    public static function topPages(mixed $data): array
    {
        if (!is_array($data) || !array_is_list($data)) {
            return [];
        }
        return array_map(static fn(array $p): array => [
            'url' => (string)($p['label'] ?? ''),
            'pageviews' => (int)($p['nb_hits'] ?? 0),
            'uniquePageviews' => (int)($p['nb_uniq_pageviews'] ?? $p['nb_uniq_visitors'] ?? 0),
        ], $data);
    }

    /**
     * @return list<array{label: string, visits: int, percent: string}>
     */
    public static function trafficSources(mixed $data): array
    {
        if (!is_array($data) || !array_is_list($data)) {
            return [];
        }
        $total = array_sum(array_map(static fn(array $r): int => (int)($r['nb_visits'] ?? 0), $data));
        return array_map(static fn(array $r): array => [
            'label' => (string)($r['label'] ?? ''),
            'visits' => (int)($r['nb_visits'] ?? 0),
            'percent' => $total > 0 ? number_format(((int)($r['nb_visits'] ?? 0) / $total) * 100, 1, '.', '') . '%' : '0%',
        ], $data);
    }

    /**
     * @return array{visits: int, uniqueVisitors: int, pageviews: int, bounceRate: string, avgTimeOnSite: int}
     */
    public static function kpis(mixed $summary): array
    {
        $summary = is_array($summary) ? $summary : [];
        return [
            'visits' => (int)($summary['nb_visits'] ?? 0),
            'uniqueVisitors' => (int)($summary['nb_uniq_visitors'] ?? 0),
            'pageviews' => (int)($summary['nb_pageviews'] ?? $summary['nb_actions'] ?? 0),
            'bounceRate' => (string)($summary['bounce_rate'] ?? '–'),
            'avgTimeOnSite' => (int)($summary['avg_time_on_site'] ?? 0),
        ];
    }

    /**
     * Adds a unique key per goal; duplicate names get the id appended so chart series stay unique.
     *
     * @param list<array{id: string, name: string}> $goals
     * @return list<array{id: string, name: string, key: string}>
     */
    public static function uniqueGoalLabels(array $goals): array
    {
        $counts = [];
        foreach ($goals as $goal) {
            $counts[$goal['name']] = ($counts[$goal['name']] ?? 0) + 1;
        }
        return array_map(static fn(array $g): array => $g + [
            'key' => $counts[$g['name']] > 1 ? $g['name'] . ' (#' . $g['id'] . ')' : $g['name'],
        ], $goals);
    }

    /**
     * Merges per-goal series into rows {date, <key>: n, <compareKey>: n}.
     * Compare values are aligned by index (they belong to a different period).
     *
     * @param list<array{key: string, series: list<array{date: string, value: int}>, compareKey?: string, compareSeries?: list<array{date: string, value: int}>}> $entries
     * @return list<array<string, int|string>>
     */
    public static function multiGoalSeries(array $entries): array
    {
        $rows = [];
        $dateIndex = [];
        foreach ($entries as $entry) {
            foreach ($entry['series'] as $point) {
                if (!isset($dateIndex[$point['date']])) {
                    $dateIndex[$point['date']] = count($rows);
                    $rows[] = ['date' => $point['date']];
                }
            }
        }
        foreach ($entries as $entry) {
            foreach ($entry['series'] as $i => $point) {
                $index = $dateIndex[$point['date']];
                $rows[$index][$entry['key']] = $point['value'];
                if (isset($entry['compareKey'], $entry['compareSeries'][$i])) {
                    $rows[$index][$entry['compareKey']] = $entry['compareSeries'][$i]['value'];
                }
            }
        }
        return $rows;
    }

    /**
     * Adds compare value and delta per URL.
     *
     * @param list<array<string, mixed>> $pages
     * @param list<array<string, mixed>> $comparePages
     * @return list<array<string, mixed>>
     */
    public static function addPagesDelta(array $pages, array $comparePages): array
    {
        if ($comparePages === []) {
            return $pages;
        }
        $compareMap = [];
        foreach ($comparePages as $p) {
            $compareMap[$p['url']] = $p['pageviews'];
        }
        return array_map(static function (array $p) use ($compareMap): array {
            $compare = $compareMap[$p['url']] ?? null;
            if ($compare === null) {
                return $p;
            }
            $pct = $compare === 0 ? null : (($p['pageviews'] - $compare) / $compare) * 100;
            return $p + [
                'pageviewsCompare' => $compare,
                'pageviewsDelta' => $pct !== null ? self::formatDelta($pct) : null,
                'pageviewsDeltaPos' => $pct !== null ? $pct >= 0 : null,
            ];
        }, $pages);
    }

    /**
     * @param list<array<string, mixed>> $sources
     * @param list<array<string, mixed>> $compareSources
     * @return list<array<string, mixed>>
     */
    public static function addSourcesDelta(array $sources, array $compareSources): array
    {
        if ($compareSources === []) {
            return $sources;
        }
        $compareMap = [];
        foreach ($compareSources as $s) {
            $compareMap[$s['label']] = $s['visits'];
        }
        return array_map(static function (array $s) use ($compareMap): array {
            $compare = $compareMap[$s['label']] ?? null;
            if ($compare === null || $compare === 0) {
                return $s;
            }
            $pct = (($s['visits'] - $compare) / $compare) * 100;
            return $s + ['visitsDelta' => self::formatDelta($pct), 'visitsDeltaPos' => $pct >= 0];
        }, $sources);
    }

    public static function formatDelta(float $pct): string
    {
        return ($pct >= 0 ? '+' : '') . number_format($pct, 1, '.', '') . '%';
    }
}
