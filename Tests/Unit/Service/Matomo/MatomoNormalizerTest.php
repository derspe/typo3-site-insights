<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Tests\Unit\Service\Matomo;

use Derspe\SiteInsights\Service\Matomo\MatomoNormalizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MatomoNormalizerTest extends TestCase
{
    #[Test]
    public function singleDaySummaryBecomesOnePoint(): void
    {
        self::assertSame([['date' => 'day', 'value' => 45]], MatomoNormalizer::visitorSeries(['nb_visits' => 45]));
    }

    #[Test]
    public function dayKeyedSeriesTreatsEmptyDaysAsZero(): void
    {
        self::assertSame(
            [['date' => '2026-10-01', 'value' => 10], ['date' => '2026-10-02', 'value' => 0]],
            MatomoNormalizer::visitorSeries(['2026-10-01' => ['nb_visits' => 10], '2026-10-02' => []])
        );
    }

    #[Test]
    public function hourlyListIsFormattedAsHours(): void
    {
        self::assertSame(
            [['date' => '00:00', 'value' => 3], ['date' => '13:00', 'value' => 8]],
            MatomoNormalizer::visitorSeries([['label' => '0h', 'nb_visits' => 3], ['label' => '13h', 'nb_visits' => 8]])
        );
    }

    #[Test]
    public function invalidInputGivesEmptySeries(): void
    {
        self::assertSame([], MatomoNormalizer::visitorSeries(null));
        self::assertSame([], MatomoNormalizer::visitorSeries([]));
        self::assertSame([], MatomoNormalizer::goalSeries('x'));
    }

    #[Test]
    public function goalSeriesReadsConversions(): void
    {
        self::assertSame([['date' => 'day', 'value' => 3]], MatomoNormalizer::goalSeries(['nb_conversions' => 3]));
        self::assertSame([['date' => '2026-10-01', 'value' => 2]], MatomoNormalizer::goalSeries(['2026-10-01' => ['nb_conversions' => 2]]));
    }

    #[Test]
    public function topPagesAreMapped(): void
    {
        self::assertSame(
            [['url' => '/foo', 'pageviews' => 10, 'uniquePageviews' => 7]],
            MatomoNormalizer::topPages([['label' => '/foo', 'nb_hits' => 10, 'nb_uniq_visitors' => 7]])
        );
        self::assertSame([], MatomoNormalizer::topPages(['label' => '/foo']));
    }

    #[Test]
    public function trafficSourceShareIsCalculated(): void
    {
        self::assertSame(
            [['label' => 'Search', 'visits' => 75, 'percent' => '75.0%'], ['label' => 'Direct', 'visits' => 25, 'percent' => '25.0%']],
            MatomoNormalizer::trafficSources([['label' => 'Search', 'nb_visits' => 75], ['label' => 'Direct', 'nb_visits' => 25]])
        );
    }

    #[Test]
    public function kpisFallBackToActions(): void
    {
        $kpis = MatomoNormalizer::kpis(['nb_visits' => 5, 'nb_actions' => 9, 'bounce_rate' => '40%', 'avg_time_on_site' => 61]);
        self::assertSame(['visits' => 5, 'uniqueVisitors' => 0, 'pageviews' => 9, 'bounceRate' => '40%', 'avgTimeOnSite' => 61], $kpis);
    }

    #[Test]
    public function duplicateGoalNamesGetTheIdAppended(): void
    {
        $goals = MatomoNormalizer::uniqueGoalLabels([
            ['id' => '1', 'name' => 'Click'],
            ['id' => '2', 'name' => 'Click'],
            ['id' => '3', 'name' => 'Signup'],
        ]);
        self::assertSame(['Click (#1)', 'Click (#2)', 'Signup'], array_column($goals, 'key'));
    }

    #[Test]
    public function multiGoalSeriesMergesByDateAndComparesByIndex(): void
    {
        $rows = MatomoNormalizer::multiGoalSeries([
            ['key' => 'A', 'series' => [['date' => 'd1', 'value' => 1], ['date' => 'd2', 'value' => 2]], 'compareKey' => 'A|compare', 'compareSeries' => [['date' => 'x1', 'value' => 5]]],
            ['key' => 'B', 'series' => [['date' => 'd2', 'value' => 7]]],
        ]);
        self::assertSame([['date' => 'd1', 'A' => 1, 'A|compare' => 5], ['date' => 'd2', 'A' => 2, 'B' => 7]], $rows);
    }

    #[Test]
    public function pagesDeltaIsCalculated(): void
    {
        $pages = MatomoNormalizer::addPagesDelta([['url' => '/a', 'pageviews' => 50]], [['url' => '/a', 'pageviews' => 100]]);
        self::assertSame(100, $pages[0]['pageviewsCompare']);
        self::assertSame('-50.0%', $pages[0]['pageviewsDelta']);
        self::assertFalse($pages[0]['pageviewsDeltaPos']);
    }

    #[Test]
    public function sourcesDeltaIgnoresZeroCompare(): void
    {
        $sources = MatomoNormalizer::addSourcesDelta([['label' => 'X', 'visits' => 3]], [['label' => 'X', 'visits' => 0]]);
        self::assertArrayNotHasKey('visitsDelta', $sources[0]);
    }

    #[Test]
    public function pageMetricsSumAllRowsOfThePage(): void
    {
        self::assertSame(['pageviews' => 15, 'entries' => 4], MatomoNormalizer::pageMetrics([
            ['label' => '/a', 'nb_hits' => 10, 'entry_nb_visits' => 3],
            ['label' => '/a?x=1', 'nb_hits' => 5, 'entry_nb_visits' => 1],
        ]));
    }

    #[Test]
    public function pageMetricsOfAnUnknownPageAreZero(): void
    {
        self::assertSame(['pageviews' => 0, 'entries' => 0], MatomoNormalizer::pageMetrics([]));
        self::assertSame(['pageviews' => 0, 'entries' => 0], MatomoNormalizer::pageMetrics(['result' => 'x']));
        // Rows of pages without entries carry no entry metrics
        self::assertSame(['pageviews' => 2, 'entries' => 0], MatomoNormalizer::pageMetrics([['nb_hits' => 2]]));
    }
}
