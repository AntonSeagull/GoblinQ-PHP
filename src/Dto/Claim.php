<?php

declare(strict_types=1);

namespace GoblinQ\Dto;

use DateTimeImmutable;
use GoblinQ\Internal\Hydrator;

final class Claim
{
    public function __construct(
        public readonly string $messageId,
        public readonly string $executionId,
        public readonly string $leaseToken,
        public readonly int $attempt,
        public readonly DateTimeImmutable $leaseUntil,
        public readonly DateTimeImmutable $expiresAt,
        public readonly mixed $payload,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Hydrator::string($data, 'message_id'),
            Hydrator::string($data, 'execution_id'),
            Hydrator::string($data, 'lease_token'),
            Hydrator::int($data, 'attempt'),
            Hydrator::date($data, 'lease_until'),
            Hydrator::date($data, 'expires_at'),
            Hydrator::value($data, 'payload'),
        );
    }
}
