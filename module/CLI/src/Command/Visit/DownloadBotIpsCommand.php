<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\CLI\Command\Visit;

use Shlinkio\Shlink\Core\BotIp\BotIpRangesUpdaterInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

use function sprintf;

#[AsCommand(
    name: DownloadBotIpsCommand::NAME,
    description: 'Downloads the IP ranges Google publishes for its crawlers and fetchers. Visits from them are '
    . 'tracked as potential bots. Visits refresh the list on their own once it is a week old.',
)]
class DownloadBotIpsCommand extends Command
{
    public const string NAME = 'visit:download-bot-ips';

    public function __construct(private readonly BotIpRangesUpdaterInterface $updater)
    {
        parent::__construct();
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $result = $this->updater->update();
        if (!$result->updated) {
            $io->warning('Another process is downloading the bot IP ranges right now.');
            return Command::INVALID;
        }

        foreach ($result->failedSources as $source) {
            $io->warning(sprintf('Could not download %s', $source));
        }

        if ($result->allSourcesFailed()) {
            $io->error('No bot IP ranges were downloaded.');
            return Command::FAILURE;
        }

        $io->success(sprintf('Bot IP ranges: %d added, %d refreshed.', $result->added, $result->refreshed));
        return $result->failedSources === [] ? Command::SUCCESS : Command::INVALID;
    }
}
