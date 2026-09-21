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

        /** @var DeliveryFailures $failures */
        $failures = $container->get(DeliveryFailures::class);
        $brokers = (string) ($config['brokers'] ?? '');

        return new LazyProducer(static function () use ($logger, $failures, $brokers): ProducerInterface {
            $producerConfig = new KafkaProducerConfig($logger, [
                'metadata.broker.list' => $brokers,
                'acks' => 'all',
                'enable.idempotence' => 'true',
                'message.send.max.retries' => '3',
                'retry.backoff.ms' => '150',
                'request.timeout.ms' => '3000',
                'message.timeout.ms' => '4000',
            ]);
            $producerConfig->setOnError(static function (mixed $kafka, int $err, string $reason) use ($logger): void {
                $logger->error('Kafka producer error {err}. Reason: {reason}', ['err' => $err, 'reason' => $reason]);
            });
            $producerConfig->setOnDeliveryReport(new DeliveryReportRecorder($logger, $failures));

            $interceptors = new InterceptorStack();
            $interceptors->add(new EncodingInterceptor(new JsonSerializer()));

            return new SimpleProducer(
                new KafkaProducerDriver($logger, $producerConfig, new KafkaProducerOptions(
                    defaultFlushTimeoutMs: 5000,
                    maxAttempts: 1,
                )),
                $interceptors,
            );
        });
    }
}
