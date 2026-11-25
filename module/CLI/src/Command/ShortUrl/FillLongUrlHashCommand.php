<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\CLI\Command\ShortUrl;

use Shlinkio\Shlink\CLI\Command\Util\CommandUtils;
use Shlinkio\Shlink\CLI\Command\Util\LockConfig;
use Shlinkio\Shlink\Core\ShortUrl\LongUrlHashFillerInterface;
use Shlinkio\Shlink\Core\ShortUrl\Model\LongUrlHashFillResult;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

use function ctype_digit;
use function sprintf;

/**
 * A one-off pass after the upgrade to 5.x (SWR-25201, D-009). Not scheduled.
 */
#[AsCommand(
    name: FillLongUrlHashCommand::NAME,
    description: 'Fills the long URL hash of the short URLs created before the upgrade to Shlink 5.x, which is empty '
    . 'for them. Only rows with an empty hash change, so it can be stopped and run again; --from-id skips the rows '
    . 'already read.',
)]
class FillLongUrlHashCommand extends Command
{
    public const string NAME = 'short-url:fill-long-url-hash';

    private const string DEFAULT_BATCH_SIZE = '1000';

    public function __construct(
        private readonly LongUrlHashFillerInterface $filler,
        private readonly LockFactory $locker,
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Resume after this short URL id', name: 'from-id')] string $fromId = '0',
        #[Option('Short URLs read per query', name: 'batch-size')] string $batchSize = self::DEFAULT_BATCH_SIZE,
    ): int {
        if (!ctype_digit($fromId) || !ctype_digit($batchSize) || (int) $batchSize < 1) {
            $io->error('Provide numeric --from-id and --batch-size.');
            return Command::FAILURE;
        }

        return CommandUtils::executeWithLock(
            $this->locker,
            LockConfig::nonBlocking(self::NAME),
            $io,
            fn () => $this->fill($io, (int) $fromId, (int) $batchSize),
        );
    }

    private function fill(SymfonyStyle $io, int $fromId, int $batchSize): int
    {
        $result = $this->filler->fill(
            $fromId,
            $batchSize,
            static fn (LongUrlHashFillResult $r) => $io->writeln(
                sprintf('Up to short URL %d: %d filled', $r->lastId, $r->filled),
                OutputInterface::VERBOSITY_VERBOSE,
            ),
        );

        $io->table(['Short URLs', 'Count'], [
            ['Filled', $result->filled],
            ['Left', $this->filler->countLeft()],
        ]);
        $io->success(sprintf(
            'Last short URL id: %d (pass it as --from-id to resume).',
            $result->lastId,
        ));

        return Command::SUCCESS;
    }
}
