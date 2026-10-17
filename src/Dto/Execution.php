<?php

declare(strict_types=1);

namespace GoblinQ\Dto;

use DateTimeImmutable;
use GoblinQ\Internal\Hydrator;

final class Execution
{
    public function __construct(
        public readonly string $executionId,
        public readonly string $messageId,
        public readonly string $workerId,
        public readonly ?string $instanceId,
        public readonly int $attempt,
        public readonly string $state,
        public readonly DateTimeImmutable $leaseUntil,
        public readonly DateTimeImmutable $startedAt,
        public readonly ?DateTimeImmutable $finishedAt,
        public readonly mixed $result,
        public readonly mixed $error,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Hydrator::string($data, 'execution_id'),
            Hydrator::string($data, 'message_id'),
            Hydrator::string($data, 'worker_id'),
            Hydrator::nullableString($data, 'instance_id'),
            Hydrator::int($data, 'attempt'),
            Hydrator::string($data, 'state'),
            Hydrator::date($data, 'lease_until'),
            Hydrator::date($data, 'started_at'),
            Hydrator::nullableDate($data, 'finished_at'),
            Hydrator::value($data, 'result'),
            Hydrator::value($data, 'error'),
        );
    }
}
