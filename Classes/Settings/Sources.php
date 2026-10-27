<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Settings;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Which data sources are enabled in the extension configuration ("sources": both, gsc or matomo).
 * Customers have Matomo, Search Console or both; a disabled source disappears from the module,
 * the page module, the API and the dashboard widgets.
 */
final readonly class Sources
{
    public const MATOMO = 'matomo';
    public const GSC = 'gsc';

    public function __construct(
        private ExtensionConfiguration $extensionConfiguration,
    ) {}

    public function matomo(): bool
    {
        return self::isEnabled($this->value(), self::MATOMO);
    }

    public function gsc(): bool
    {
        return self::isEnabled($this->value(), self::GSC);
    }

    /**
     * @return array{matomo: bool, gsc: bool}
     */
    public function toArray(): array
    {
        return ['matomo' => $this->matomo(), 'gsc' => $this->gsc()];
    }

    /**
     * Unknown or missing values enable both sources.
     */
    public static function isEnabled(mixed $value, string $source): bool
    {
        return !in_array($value, [self::MATOMO, self::GSC], true) || $value === $source;
    }

    private function value(): mixed
    {
        try {
            return $this->extensionConfiguration->get('derspe_site_insights', 'sources');
        } catch (\Throwable) {
            return null;
        }
    }
}
