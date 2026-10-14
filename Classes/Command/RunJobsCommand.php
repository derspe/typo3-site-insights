<?php

declare(strict_types=1);

namespace Derspe\SiteInsights\Command;

use Derspe\SiteInsights\Service\Job\JobService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Continues pending background jobs (index scan, backfill) that were started in the backend
 * module, so they finish even when nobody keeps the module open.
 */
#[AsCommand(
    name: 'site-insights:run-jobs',
    description: 'Continue pending Site Insights background jobs (index scan, snapshot backfill).'
)]
final class RunJobsCommand extends Command
{
    private const STEP_SECONDS = 20.0;

    public function __construct(
        private readonly JobService $jobs,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('time-limit', null, InputOption::VALUE_REQUIRED, 'Maximum runtime in seconds', '50');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $deadline = microtime(true) + max(5, (int)$input->getOption('time-limit'));
        foreach ($this->jobs->findAllActive() as $job) {
            // Short steps, so the progress is saved regularly and visible in the module
            while (($remaining = $deadline - microtime(true)) > 2) {
                $result = $this->jobs->step((string)$job['site'], (int)$job['uid'], min(self::STEP_SECONDS, $remaining));
                if ($result === null) {
                    $output->writeln(sprintf('Job %d is processed by another process', $job['uid']));
                    break;
                }
                $output->writeln(sprintf('Job %d (%s): %s, %d/%d', $result['uid'], $result['type'], $result['status'], $result['done'], $result['total']), OutputInterface::VERBOSITY_VERBOSE);
                if (!in_array($result['status'], ['pending', 'running'], true)) {
                    $output->writeln(sprintf('Job %d (%s): %s', $result['uid'], $result['type'], $result['status']));
                    break;
                }
            }
        }
        return Command::SUCCESS;
    }
}
