<?php

declare(strict_types=1);

namespace GoblinQ\Dto;

use DateTimeImmutable;
use GoblinQ\Internal\Hydrator;

/**
 * Метаданные сообщения в списке истории (без payload).
 */
class MessageSummary
{
    public function __construct(
        public readonly string $messageId,
        public readonly string $channel,
        public readonly ?string $idempotencyKey,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $expiresAt,
        public readonly DateTimeImmutable $retentionUntil,
        public readonly int $ttlSeconds,
        public readonly int $maxAttempts,
        public readonly int $retryDelaySeconds,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Hydrator::string($data, 'message_id'),
            Hydrator::string($data, 'channel'),
            Hydrator::nullableString($data, 'idempotency_key'),
            Hydrator::date($data, 'created_at'),
            Hydrator::date($data, 'expires_at'),
            Hydrator::date($data, 'retention_until'),
            Hydrator::int($data, 'ttl_seconds'),
            Hydrator::int($data, 'max_attempts'),
            Hydrator::int($data, 'retry_delay_seconds'),
        );
    }
}
