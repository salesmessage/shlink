<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\CLI\Command\Visit;

use Cake\Chronos\Chronos;
use Shlinkio\Shlink\CLI\Command\Util\CommandUtils;
use Shlinkio\Shlink\CLI\Command\Util\LockConfig;
use Shlinkio\Shlink\Core\BotIp\Model\ReclassificationResult;
use Shlinkio\Shlink\Core\BotIp\VisitBotReclassifierInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;
use Throwable;

use function ctype_digit;
use function sprintf;

/**
 * A one-off pass that flags past visits as potential bots with today's rules (SWR-26047). Not scheduled.
 */
#[AsCommand(
    name: ReclassifyBotVisitsCommand::NAME,
    description: 'Flags past visits to short URLs as potential bots when the current user agent rules or the bot IP '
    . 'ranges match them. The flag is only set, never cleared, and the visit counts of short URLs '
    . '(visitsSummary) are not recalculated. An empty stored user agent counts as a bot: if DISABLE_UA_TRACKING '
    . 'was on during the period, every visit matches, so run --dry-run first and compare the "User agent" row '
    . 'with "Scanned".',
)]
class ReclassifyBotVisitsCommand extends Command
{
    public const string NAME = 'visit:reclassify-bots';

    private const string DEFAULT_BATCH_SIZE = '1000';

    public function __construct(
        private readonly VisitBotReclassifierInterface $reclassifier,
        private readonly LockFactory $locker,
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Only visits on or after this date (Y-m-d)')] string|null $since = null,
        #[Option('Resume after this visit id', name: 'from-id')] string $fromId = '0',
        #[Option('Visits read per query', name: 'batch-size')] string $batchSize = self::DEFAULT_BATCH_SIZE,
        #[Option('Count the visits that would be flagged only', name: 'dry-run')] bool $dryRun = false,
    ): int {
        $sinceDate = $this->parseSince($since);
        if ($sinceDate === null || !ctype_digit($fromId) || !ctype_digit($batchSize) || (int) $batchSize < 1) {
            $io->error('Provide --since as Y-m-d, and numeric --from-id and --batch-size.');
            return Command::FAILURE;
        }

        return CommandUtils::executeWithLock(
            $this->locker,
            LockConfig::nonBlocking(self::NAME),
            $io,
            fn () => $this->reclassify($io, $sinceDate, (int) $fromId, (int) $batchSize, $dryRun),
        );
    }

    private function reclassify(SymfonyStyle $io, Chronos $since, int $fromId, int $batchSize, bool $dryRun): int
    {
        $result = $this->reclassifier->reclassify(
            $since,
            $fromId,
            $batchSize,
            $dryRun,
            static fn (ReclassificationResult $r) => $io->writeln(
                sprintf('Up to visit %d: %d scanned, %d flagged', $r->lastId, $r->scanned, $r->marked()),
                OutputInterface::VERBOSITY_VERBOSE,
            ),
        );

        $io->table(['Rule', 'Visits'], [
            ['User agent', $result->byUserAgent],
            ['Google network', $result->byGoogleNetwork],
            ['Total flagged', $result->marked()],
            ['Scanned', $result->scanned],
        ]);
        $io->success(sprintf(
            '%s Last visit id: %d (pass it as --from-id to resume).',
            $dryRun ? 'Dry run, nothing was changed.' : 'Visits flagged.',
            $result->lastId,
        ));

        return Command::SUCCESS;
    }

    private function parseSince(string|null $since): Chronos|null
    {
        if ($since === null) {
            return null;
        }

        try {
            return Chronos::createFromFormat('!Y-m-d', $since);
        } catch (Throwable) {
            return null;
        }
    }
}
