<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\CLI\Command\Visit;

use Shlinkio\Shlink\CLI\Util\ExitCodes;
use Shlinkio\Shlink\Core\BotIp\BotIpRangesUpdaterInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function sprintf;

class DownloadBotIpsCommand extends Command
{
    public const NAME = 'visit:download-bot-ips';

    public function __construct(private readonly BotIpRangesUpdaterInterface $updater)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName(self::NAME)
            ->setDescription(
                'Downloads the IP ranges Google publishes for its crawlers and fetchers. Visits from them are '
                . 'tracked as potential bots. Visits refresh the list on their own once it is a week old.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $result = $this->updater->update();

        if (! $result->updated) {
            $io->warning('Another process is downloading the bot IP ranges right now.');
            return ExitCodes::EXIT_WARNING;
        }

        foreach ($result->failedSources as $source) {
            $io->warning(sprintf('Could not download %s', $source));
        }

        if ($result->allSourcesFailed()) {
            $io->error('No bot IP ranges were downloaded.');
            return ExitCodes::EXIT_FAILURE;
        }

        $io->success(sprintf('Bot IP ranges: %d added, %d refreshed.', $result->added, $result->refreshed));
        return empty($result->failedSources) ? ExitCodes::EXIT_SUCCESS : ExitCodes::EXIT_WARNING;
    }
}
