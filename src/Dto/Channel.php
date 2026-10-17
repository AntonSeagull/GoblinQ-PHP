<?php

declare(strict_types=1);

namespace GoblinQ\Dto;

use DateTimeImmutable;
use GoblinQ\Internal\Hydrator;

final class Channel
{
    public function __construct(
        public readonly string $name,
        public readonly DateTimeImmutable $createdAt,
        public readonly int $messageCount,
        public readonly int $deliveryCount,
        public readonly int $processingCount,
        public readonly int $successCount,
        public readonly int $exhaustedCount,
        public readonly int $openLeaseCount,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Hydrator::string($data, 'name'),
            Hydrator::date($data, 'created_at'),
            Hydrator::int($data, 'message_count'),
            Hydrator::int($data, 'delivery_count'),
            Hydrator::int($data, 'processing_count'),
            Hydrator::int($data, 'success_count'),
            Hydrator::int($data, 'exhausted_count'),
            Hydrator::int($data, 'open_lease_count'),
        );
    }
}
