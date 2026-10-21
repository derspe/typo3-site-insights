<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Tests\Unit\Service\Gsc;

use Derspe\SiteInsights\Service\Gsc\GscNormalizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GscNormalizerTest extends TestCase
{
    #[Test]
    public function kpisAggregateAndWeightPositionByImpressions(): void
    {
        $kpis = GscNormalizer::kpisFromRows([
            ['clicks' => 100, 'impressions' => 1000, 'position' => 5],
            ['clicks' => 50, 'impressions' => 500, 'position' => 10],
        ]);
        self::assertSame(150, $kpis['clicks']);
        self::assertSame(1500, $kpis['impressions']);
        self::assertEqualsWithDelta(0.1, $kpis['ctr'], 0.0001);
        self::assertEqualsWithDelta(6.6667, $kpis['position'], 0.001);
    }

    #[Test]
    public function kpisOfEmptyRowsAreZero(): void
    {
        self::assertSame(['clicks' => 0, 'impressions' => 0, 'ctr' => 0.0, 'position' => 0.0], GscNormalizer::kpisFromRows([]));
    }

    #[Test]
    public function topRowsAreMappedByKey(): void
    {
        $rows = GscNormalizer::topRows([['keys' => ['home assistant'], 'clicks' => 3, 'impressions' => 40, 'ctr' => 0.075, 'position' => 4.2]], 'query');
        self::assertSame([['query' => 'home assistant', 'clicks' => 3, 'impressions' => 40, 'ctr' => 0.075, 'position' => 4.2]], $rows);
    }

    #[Test]
    public function deltaIsAddedForMatchingRows(): void
    {
        $rows = GscNormalizer::addRowsDelta(
            [['query' => 'a', 'clicks' => 110], ['query' => 'b', 'clicks' => 5]],
            [['query' => 'a', 'clicks' => 100]],
            'query',
            'clicks'
        );
        self::assertSame(100, $rows[0]['clicksCompare']);
        self::assertSame('+10.0%', $rows[0]['clicksDelta']);
        self::assertTrue($rows[0]['clicksDeltaPos']);
        self::assertArrayNotHasKey('clicksCompare', $rows[1]);
    }

    #[Test]
    public function deltaIsNullWhenCompareValueIsZero(): void
    {
        $rows = GscNormalizer::addRowsDelta([['query' => 'a', 'clicks' => 3]], [['query' => 'a', 'clicks' => 0]], 'query', 'clicks');
        self::assertSame(0, $rows[0]['clicksCompare']);
        self::assertNull($rows[0]['clicksDelta']);
    }

    #[Test]
    public function compareSeriesIsAlignedByPosition(): void
    {
        $merged = GscNormalizer::mergeCompareSeries(
            [['date' => '2026-10-01', 'clicks' => 1, 'impressions' => 10], ['date' => '2026-10-02', 'clicks' => 2, 'impressions' => 20]],
            [['date' => '2026-09-01', 'clicks' => 3, 'impressions' => 30]]
        );
        self::assertSame('2026-10-01', $merged[0]['date']);
        self::assertSame(3, $merged[0]['clicksCompare']);
        self::assertArrayNotHasKey('clicksCompare', $merged[1]);
    }

    #[Test]
    public function brandSplitMatchesWholeWordsCaseInsensitive(): void
    {
        $regex = GscNormalizer::brandRegex(['acme', 'acme shop']);
        $split = GscNormalizer::splitBrand([
            ['keys' => ['ACME login'], 'clicks' => 5],
            ['keys' => ['acmeville'], 'clicks' => 2],
            ['keys' => ['buy shoes'], 'clicks' => 7],
        ], $regex);
        self::assertSame(['brand' => 5, 'nonBrand' => 9], $split);
    }

    #[Test]
    public function brandRegexIsNullWithoutTermsAndEverythingIsNonBrand(): void
    {
        self::assertNull(GscNormalizer::brandRegex(['', ' ']));
        self::assertSame(['brand' => 0, 'nonBrand' => 4], GscNormalizer::splitBrand([['keys' => ['x'], 'clicks' => 4]], null));
    }

    #[Test]
    public function brandTermsAreEscaped(): void
    {
        $regex = GscNormalizer::brandRegex(['c++']);
        self::assertSame(1, preg_match((string)$regex, 'learn c++ today'));
    }

    #[Test]
    public function ctrWeightFollowsTheCurve(): void
    {
        self::assertSame(0.275, GscNormalizer::ctrWeight(1.2));
        self::assertSame(0.155, GscNormalizer::ctrWeight(1.6));
        self::assertSame(0.018, GscNormalizer::ctrWeight(10));
        self::assertSame(0.012, GscNormalizer::ctrWeight(15));
        self::assertSame(0.005, GscNormalizer::ctrWeight(42));
        self::assertSame(0.275, GscNormalizer::ctrWeight(0));
    }

    #[Test]
    public function visibilityScoreSumsWeightedImpressions(): void
    {
        self::assertSame(37.5, GscNormalizer::visibilityScore([
            ['impressions' => 100, 'position' => 1],
            ['impressions' => 100, 'position' => 3],
        ]));
    }

    #[Test]
    public function matomoVisitsAreJoinedByPath(): void
    {
        $joined = GscNormalizer::joinMatomoVisits(
            [['page' => 'https://example.com/foo/'], ['page' => 'https://example.com/bar']],
            [['url' => '/foo', 'pageviews' => 12]]
        );
        self::assertSame(12, $joined[0]['matomoVisits']);
        self::assertArrayNotHasKey('matomoVisits', $joined[1]);
    }

    #[Test]
    public function extractPathKeepsRootAndStripsTrailingSlash(): void
    {
        self::assertSame('/', GscNormalizer::extractPath('https://example.com/'));
        self::assertSame('/a/b', GscNormalizer::extractPath('https://example.com/a/b/'));
        self::assertSame('/x', GscNormalizer::extractPath('/x/'));
    }

    #[Test]
    public function pageRegexMatchesWithAndWithoutTrailingSlash(): void
    {
        $regex = GscNormalizer::pageRegex('https://www.example.com/smart-home/');
        self::assertSame('^https\\://www\\.example\\.com/smart\\-home/?$', $regex);
        $php = '#' . $regex . '#';
        self::assertMatchesRegularExpression($php, 'https://www.example.com/smart-home');
        self::assertMatchesRegularExpression($php, 'https://www.example.com/smart-home/');
        self::assertDoesNotMatchRegularExpression($php, 'https://www.example.com/smart-home/lights');
        self::assertDoesNotMatchRegularExpression($php, 'https://www.example.com/smart-homes');
    }

    #[Test]
    public function pageRegexOfTheRootPage(): void
    {
        $php = '#' . GscNormalizer::pageRegex('https://example.com/') . '#';
        self::assertMatchesRegularExpression($php, 'https://example.com/');
        self::assertMatchesRegularExpression($php, 'https://example.com');
        self::assertDoesNotMatchRegularExpression($php, 'https://example.com/about');
    }

    #[Test]
    public function anonymizedShareIsTheTotalMinusAllReportedQueries(): void
    {
        $share = GscNormalizer::anonymizedShare(['clicks' => 17, 'impressions' => 3491], [
            ['keys' => ['typo3 agentur'], 'clicks' => 2, 'impressions' => 271],
            ['keys' => ['typo3 agentur heidelberg'], 'clicks' => 2, 'impressions' => 120],
        ]);
        self::assertSame(['clicks' => 13, 'impressions' => 3100], $share);
    }

    #[Test]
    public function anonymizedShareIsNeverNegative(): void
    {
        // Totals and query rows are aggregated differently and may not add up exactly
        self::assertSame(['clicks' => 0, 'impressions' => 0], GscNormalizer::anonymizedShare(['clicks' => 3, 'impressions' => 10], [['clicks' => 4, 'impressions' => 12]]));
    }
}
