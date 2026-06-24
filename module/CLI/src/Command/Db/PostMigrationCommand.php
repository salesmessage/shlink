<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\CLI\Command\Db;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Shlinkio\Shlink\CLI\Util\ExitCodes;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function microtime;
use function round;
use function sprintf;

class PostMigrationCommand extends Command
{
    public const NAME = 'db:postgres:post-migration';

    public function __construct(private readonly Connection $conn)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName(self::NAME)
            ->setDescription(
                'Post MySQL-to-Postgres (AWS DMS) migration cleanup: reset id sequences and run ANALYZE.',
            )
            ->addOption('skip-sequences', null, InputOption::VALUE_NONE, 'Do not reset Postgres id sequences')
            ->addOption('skip-analyze', null, InputOption::VALUE_NONE, 'Do not run ANALYZE');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (! $this->conn->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $io->error('This command targets a Postgres connection. Aborting.');
            return ExitCodes::EXIT_FAILURE;
        }

        $startTime = microtime(true);

        if (! $input->getOption('skip-sequences')) {
            $this->resetSequences($io);
        }

        if (! $input->getOption('skip-analyze')) {
            $this->analyze($io);
        }

        $executionTime = round(microtime(true) - $startTime, 2);
        $io->success(sprintf('Post-migration finished. Time - %ss', $executionTime));

        return ExitCodes::EXIT_SUCCESS;
    }

    /**
     * DMS inserts rows with explicit primary keys and never advances Postgres sequences,
     * so the first application insert would collide. Re-sync every serial-backed sequence
     * to its table's current MAX(id).
     */
    private function resetSequences(SymfonyStyle $io): void
    {
        $sequences = $this->conn->executeQuery(<<<'SQL'
            SELECT
                c.relname AS table_name,
                a.attname AS column_name,
                pg_get_serial_sequence(
                    quote_ident(n.nspname) || '.' || quote_ident(c.relname),
                    a.attname
                ) AS sequence_name
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            JOIN pg_attribute a ON a.attrelid = c.oid
            WHERE c.relkind = 'r'
              AND n.nspname = current_schema()
              AND a.attnum > 0
              AND NOT a.attisdropped
              AND pg_get_serial_sequence(
                    quote_ident(n.nspname) || '.' || quote_ident(c.relname),
                    a.attname
                ) IS NOT NULL
        SQL)->fetchAllAssociative();

        foreach ($sequences as $sequence) {
            $sequenceName = $sequence['sequence_name'];
            $table = $this->conn->quoteIdentifier($sequence['table_name']);
            $column = $this->conn->quoteIdentifier($sequence['column_name']);

            $max = $this->conn->fetchOne(sprintf('SELECT MAX(%s) FROM %s', $column, $table));

            if ($max === null) {
                // Empty table: next value should be 1.
                $this->conn->executeStatement('SELECT setval(?, 1, false)', [$sequenceName]);
            } else {
                // is_called = true => next value is max + 1.
                $this->conn->executeStatement('SELECT setval(?, ?, true)', [$sequenceName, $max]);
            }

            $io->writeln(sprintf('Sequence <info>%s</info> set to %s.', $sequenceName, $max ?? 1));
        }
    }

    /**
     * DMS bulk loads bypass autovacuum, leaving the planner without statistics.
     */
    private function analyze(SymfonyStyle $io): void
    {
        $this->conn->executeStatement('ANALYZE');
        $io->writeln('<info>ANALYZE</info> completed.');
    }
}
