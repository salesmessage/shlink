<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\Core\EventDispatcher\Kafka;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Salesmessage\Streaming\Driver\Kafka\Message\Interceptor\EncodingInterceptor;
use Salesmessage\Streaming\Driver\Kafka\Producer\KafkaProducerConfig;
use Salesmessage\Streaming\Driver\Kafka\Producer\KafkaProducerDriver;
use Salesmessage\Streaming\Driver\Kafka\Producer\KafkaProducerOptions;
use Salesmessage\Streaming\Driver\Kafka\Serializer\JsonSerializer;
use Salesmessage\Streaming\Message\Interceptor\InterceptorStack;
use Salesmessage\Streaming\Producer\ProducerInterface;
use Salesmessage\Streaming\Producer\SimpleProducer;

/**
 * Builds the Kafka producer this service publishes clicks with, following `micro-workflows`'
 * `config/container/infrastructure-streaming.php`.
 */
class KafkaProducerFactory
{
    public function __invoke(ContainerInterface $container): ProducerInterface
    {
        $config = $container->get('config')['kafka'] ?? [];
        /** @var LoggerInterface $logger */
        $logger = $container->get('Logger_Shlink');

        if (! ($config['enabled'] ?? false)) {
            return new NullProducer();
        }

        $producerConfig = new KafkaProducerConfig($logger, [
            'metadata.broker.list' => (string) ($config['brokers'] ?? ''),
            'acks' => 'all',
            'enable.idempotence' => 'true',
            'message.send.max.retries' => '3',
            'retry.backoff.ms' => '150',
            'request.timeout.ms' => '10000',
            'message.timeout.ms' => '30000',
        ]);
        $producerConfig->setOnError(static function (mixed $kafka, int $err, string $reason) use ($logger): void {
            // Warning, not error: the client retries these itself, and a publish that actually fails surfaces as an
            // exception out of flush(), which the listener logs at error level
            $logger->warning('Kafka producer error {err}. Reason: {reason}', ['err' => $err, 'reason' => $reason]);
        });

        $interceptors = new InterceptorStack();
        $interceptors->add(new EncodingInterceptor(new JsonSerializer()));

        return new SimpleProducer(
            new KafkaProducerDriver($logger, $producerConfig, new KafkaProducerOptions(
                // Bounded on purpose: this runs in a task worker, so a broker outage must cost seconds per visit and
                // then be logged, not block the worker while it retries
                defaultFlushTimeoutMs: 5000,
                sleepingBetweenAttemptsMs: 150,
                maxAttempts: 3,
            )),
            $interceptors,
        );
    }
}
