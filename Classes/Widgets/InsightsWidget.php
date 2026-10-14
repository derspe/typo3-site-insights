<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Widgets;

use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Settings\SettingDefinition;
use Derspe\SiteInsights\Security\SiteAccess;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Dashboard\Widgets\JavaScriptInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetContext;
use TYPO3\CMS\Dashboard\Widgets\WidgetRendererInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetResult;

/**
 * Dashboard widget that renders one view of the Site Insights UI (KPIs, chart or list)
 * as web component. Which view is configured via the "view" option in Services.yaml.
 */
final readonly class InsightsWidget implements WidgetRendererInterface, JavaScriptInterface
{
    /**
     * @param array{view?: string} $options
     */
    public function __construct(
        private WidgetConfigurationInterface $configuration,
        private SiteAccess $siteAccess,
        private array $options = [],
    ) {}

    public function getSettingsDefinitions(): array
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        if (!$user instanceof BackendUserAuthentication) {
            return [];
        }
        $sites = [];
        // Only sites the user may see
        foreach ($this->siteAccess->allowedSites($user) as $site) {
            $sites[$site->getIdentifier()] = (string)($site->getConfiguration()['websiteTitle'] ?? '') ?: $site->getIdentifier();
        }
        if (count($sites) < 2) {
            return [];
        }
        return [
            new SettingDefinition(
                key: 'site',
                type: 'string',
                default: (string)array_key_first($sites),
                label: 'LLL:EXT:derspe_site_insights/Resources/Private/Language/locallang.xlf:widget.settings.site',
                enum: $sites,
            ),
        ];
    }

    public function renderWidget(WidgetContext $context): WidgetResult
    {
        $site = $context->settings->has('site') ? (string)$context->settings->get('site') : '';
        $content = sprintf(
            '<derspe-site-insights-widget view="%s" site="%s"></derspe-site-insights-widget>',
            htmlspecialchars((string)($this->options['view'] ?? ''), ENT_QUOTES),
            htmlspecialchars($site, ENT_QUOTES)
        );
        return new WidgetResult(content: $content, refreshable: true);
    }

    public function getJavaScriptModuleInstructions(): array
    {
        return [
            JavaScriptModuleInstruction::create('@derspe/site-insights/widget.js'),
        ];
    }

    public function getOptions(): array
    {
        return $this->options;
    }
}
