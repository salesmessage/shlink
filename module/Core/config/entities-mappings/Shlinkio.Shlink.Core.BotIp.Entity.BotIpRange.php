<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\Core;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Builder\ClassMetadataBuilder;
use Doctrine\ORM\Mapping\ClassMetadata;
use Shlinkio\Shlink\Common\Doctrine\Type\ChronosDateTimeType;

return static function (ClassMetadata $metadata, array $emConfig): void {
    $builder = new ClassMetadataBuilder($metadata);

    $builder->setTable(determineTableName('bot_ip_ranges', $emConfig))
            ->setCustomRepositoryClass(BotIp\Repository\BotIpRangeRepository::class);

    $builder->createField('id', Types::BIGINT)
            ->columnName('id')
            ->makePrimaryKey()
            ->generatedValue('IDENTITY')
            ->option('unsigned', true)
            ->build();

    $builder->createField('ip', Types::STRING)
            ->columnName('ip')
            ->length(64)
            ->build();

    $builder->createField('source', Types::STRING)
            ->columnName('source')
            ->length(32)
            ->build();

    $builder->createField('createdAt', ChronosDateTimeType::CHRONOS_DATETIME)
            ->columnName('created_at')
            ->build();

    $builder->createField('updatedAt', ChronosDateTimeType::CHRONOS_DATETIME)
            ->columnName('updated_at')
            ->build();

    $builder->addUniqueConstraint(['ip', 'source'], 'UQ_bot_ip_ranges_ip_source');
};
