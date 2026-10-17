<?php

declare(strict_types=1);

namespace GoblinQ\Dto;

use DateTimeImmutable;
use GoblinQ\Exception\GoblinQException;
use GoblinQ\Internal\Hydrator;

final class Delivery
{
    public function __construct(
        public readonly string $workerId,
        public readonly string $outcome,
        public readonly int $attemptCount,
        public readonly ?DateTimeImmutable $nextAttemptAt,
        public readonly ?DateTimeImmutable $lastSeenAt,
        public readonly ?string $activeExecutionId,
        public readonly ?Execution $lastExecution,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $lastExecution = $data['last_execution'] ?? null;
        if ($lastExecution !== null && !is_array($lastExecution)) {
            throw new GoblinQException(
                'Некорректный ответ GoblinQ: last_execution должен быть объектом',
            );
        }

        return new self(
            Hydrator::string($data, 'worker_id'),
            Hydrator::string($data, 'outcome'),
            Hydrator::int($data, 'attempt_count'),
            Hydrator::nullableDate($data, 'next_attempt_at'),
            Hydrator::nullableDate($data, 'last_seen_at'),
            Hydrator::nullableString($data, 'active_execution_id'),
            is_array($lastExecution) ? Execution::fromArray($lastExecution) : null,
        );
    }
}
