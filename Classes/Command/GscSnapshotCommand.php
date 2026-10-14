<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Command;

use Derspe\SiteInsights\Http\ApiException;
use Derspe\SiteInsights\Service\Gsc\GoogleOAuth;
use Derspe\SiteInsights\Service\Gsc\SnapshotService;
use Derspe\SiteInsights\Settings\SiteSettings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Daily Search Console snapshot for every connected site. Missing days of the last
 * --days are filled as well; complete days are skipped without API usage.
 */
#[AsCommand(
    name: 'site-insights:gsc-snapshot',
    description: 'Store the daily Search Console snapshot (top queries and pages) of all connected sites.'
)]
final class GscSnapshotCommand extends Command
{
    public function __construct(
        private readonly SiteFinder $siteFinder,
        private readonly SiteSettings $settings,
        private readonly GoogleOAuth $oauth,
        private readonly SnapshotService $snapshots,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Fill missing snapshots of the last N days (1-90)', '7')
            ->addOption('site', null, InputOption::VALUE_REQUIRED, 'Only this site identifier');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $onlySite = (string)$input->getOption('site');
        $days = (int)$input->getOption('days');
        $failed = false;

        foreach ($this->siteFinder->getAllSites() as $site) {
            $identifier = $site->getIdentifier();
            if ($onlySite !== '' && $onlySite !== $identifier) {
                continue;
            }
            if (!$this->oauth->isConnected($identifier) || !$this->settings->isSet($identifier, 'gsc_site_url')) {
                $io->writeln(sprintf('<comment>%s: not connected, skipped</comment>', $identifier));
                continue;
            }
            $fetched = 0;
            $skipped = 0;
            foreach (SnapshotService::backfillDates($days) as $date) {
                try {
                    $result = $this->snapshots->run($identifier, $date);
                    $result['skipped'] ? $skipped++ : $fetched++;
                } catch (ApiException $e) {
                    $io->error(sprintf('%s %s: %s', $identifier, $date, $e->getMessage()));
                    $failed = true;
                    if (in_array($e->status, [429, 503], true)) {
                        break;
                    }
                }
            }
            $io->writeln(sprintf('%s: %d days fetched, %d already complete', $identifier, $fetched, $skipped));
        }
        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
