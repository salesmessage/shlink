<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\CLI\Command\Visit;

use Cake\Chronos\Chronos;
use Shlinkio\Shlink\CLI\Command\Util\AbstractLockedCommand;
use Shlinkio\Shlink\CLI\Command\Util\LockedCommandConfig;
use Shlinkio\Shlink\CLI\Util\ExitCodes;
use Shlinkio\Shlink\Core\BotIp\Model\ReclassificationResult;
use Shlinkio\Shlink\Core\BotIp\VisitBotReclassifier;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;
use Throwable;

use function is_numeric;
use function is_string;
use function sprintf;

/**
 * SWR-26047: a one-off pass that flags past visits as potential bots with today's rules. Not scheduled.
 */
class ReclassifyBotVisitsCommand extends AbstractLockedCommand
{
    public const NAME = 'visit:reclassify-bots';

    private const DEFAULT_BATCH_SIZE = 1000;

    public function __construct(private readonly VisitBotReclassifier $reclassifier, LockFactory $locker)
    {
        parent::__construct($locker);
    }

    protected function configure(): void
    {
        $this
            ->setName(self::NAME)
            ->setDescription(
                'Flags past visits to short URLs as potential bots when the current user agent rules or the bot IP '
                . 'ranges match them. The flag is only set, never cleared. An empty stored user agent counts as a '
                . 'bot: if DISABLE_UA_TRACKING was on during the period, every visit matches, so run --dry-run first '
                . 'and compare the "User agent" row with "Scanned".',
            )
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Only visits on or after this date (Y-m-d)')
            ->addOption('from-id', null, InputOption::VALUE_REQUIRED, 'Resume after this visit id', '0')
            ->addOption(
                'batch-size',
                null,
                InputOption::VALUE_REQUIRED,
                'Visits read per query',
                (string) self::DEFAULT_BATCH_SIZE,
            )
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count the visits that would be flagged only');
    }

    protected function lockedExecute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $since = $this->parseSince($input->getOption('since'));
        $fromId = $input->getOption('from-id');
        $batchSize = $input->getOption('batch-size');
        if ($since === null || ! is_numeric($fromId) || ! is_numeric($batchSize) || (int) $batchSize < 1) {
            $io->error('Provide --since as Y-m-d, and numeric --from-id and --batch-size.');
            return ExitCodes::EXIT_FAILURE;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $result = $this->reclassifier->reclassify(
            $since,
            (int) $fromId,
            (int) $batchSize,
            $dryRun,
            static fn (ReclassificationResult $r) => $io->writeln(
                sprintf('Up to visit %d: %d scanned, %d flagged', $r->lastId, $r->scanned, $r->marked()),
                OutputInterface::VERBOSITY_VERBOSE,
            ),
        );

        $io->table(['Rule', 'Visits'], [
            ['User agent', $result->byUserAgent],
            ['Google network', $result->byGoogleNetwork],
            ['Scanner network and user agent', $result->byScanner],
            ['Total flagged', $result->marked()],
            ['Scanned', $result->scanned],
        ]);
        $io->success(sprintf(
            '%s Last visit id: %d (pass it as --from-id to resume).',
            $dryRun ? 'Dry run, nothing was changed.' : 'Visits flagged.',
            $result->lastId,
        ));

        return ExitCodes::EXIT_SUCCESS;
    }

    private function parseSince(mixed $since): ?Chronos
    {
        if (! is_string($since)) {
            return null;
        }

        try {
            return Chronos::createFromFormat('!Y-m-d', $since);
        } catch (Throwable) {
            return null;
        }
    }

    protected function getLockConfig(): LockedCommandConfig
    {
        return LockedCommandConfig::nonBlocking(self::NAME);
    }
}
