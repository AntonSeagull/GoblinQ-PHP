<?php

declare(strict_types=1);

namespace GoblinQ\Dto;

use DateTimeImmutable;
use GoblinQ\Internal\Hydrator;

final class Worker
{
    public function __construct(
        public readonly string $workerId,
        public readonly DateTimeImmutable $firstSeenAt,
        public readonly DateTimeImmutable $lastSeenAt,
        public readonly int $deliveryCount,
        public readonly int $successCount,
        public readonly int $failureCount,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Hydrator::string($data, 'worker_id'),
            Hydrator::date($data, 'first_seen_at'),
            Hydrator::date($data, 'last_seen_at'),
            Hydrator::int($data, 'delivery_count'),
            Hydrator::int($data, 'success_count'),
            Hydrator::int($data, 'failure_count'),
        );
    }
}
