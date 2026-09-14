<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\Core\EventDispatcher\Kafka;

use Psr\Log\LoggerInterface;

use function get_object_vars;
use function is_int;

final class DeliveryReportRecorder
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly DeliveryFailures $failures,
    ) {
    }

    public function __invoke(mixed $kafka, object $report): void
    {
        $reportValues = get_object_vars($report);
        $errorCode = $reportValues['err'] ?? 0;

        if (! is_int($errorCode) || $errorCode === 0) {
            return;
        }

        $this->failures->record($errorCode);
        $this->logger->error('Kafka did not accept a message on topic "{topic}", partition {partition}. Code: {err}', [
            'topic' => $reportValues['topic_name'] ?? null,
            'partition' => $reportValues['partition'] ?? null,
            'err' => $errorCode,
        ]);
    }
}
