<?php

declare(strict_types=1);

namespace GoblinQ\Dto;

use DateTimeImmutable;
use GoblinQ\Internal\Hydrator;

final class CompleteResult
{
    public function __construct(
        public readonly string $executionId,
        public readonly string $state,
        public readonly string $deliveryOutcome,
        public readonly int $attempt,
        public readonly ?DateTimeImmutable $nextAttemptAt,
        public readonly bool $idempotent,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Hydrator::string($data, 'execution_id'),
            Hydrator::string($data, 'state'),
            Hydrator::string($data, 'delivery_outcome'),
            Hydrator::int($data, 'attempt'),
            Hydrator::nullableDate($data, 'next_attempt_at'),
            Hydrator::bool($data, 'idempotent', false),
        );
    }
}
