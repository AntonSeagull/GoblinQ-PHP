<?php

declare(strict_types=1);

namespace GoblinQ\Dto;

use DateTimeImmutable;
use GoblinQ\Internal\Hydrator;

final class HeartbeatResult
{
    public function __construct(
        public readonly string $executionId,
        public readonly DateTimeImmutable $leaseUntil,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Hydrator::string($data, 'execution_id'),
            Hydrator::date($data, 'lease_until'),
        );
    }
}
