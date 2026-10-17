<?php

declare(strict_types=1);

namespace GoblinQ\Dto;

use GoblinQ\Internal\Hydrator;

final class MessageDetail extends MessageSummary
{
    /**
     * @param list<Delivery> $deliveries
     */
    public function __construct(
        string $messageId,
        string $channel,
        ?string $idempotencyKey,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $expiresAt,
        \DateTimeImmutable $retentionUntil,
        int $ttlSeconds,
        int $maxAttempts,
        int $retryDelaySeconds,
        public readonly mixed $payload,
        public readonly string $payloadHash,
        public readonly array $deliveries,
    ) {
        parent::__construct(
            $messageId,
            $channel,
            $idempotencyKey,
            $createdAt,
            $expiresAt,
            $retentionUntil,
            $ttlSeconds,
            $maxAttempts,
            $retryDelaySeconds,
        );
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        $deliveries = [];
        foreach (Hydrator::objectList($data, 'deliveries') as $item) {
            $deliveries[] = Delivery::fromArray($item);
        }

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
            Hydrator::value($data, 'payload'),
            Hydrator::string($data, 'payload_hash'),
            $deliveries,
        );
    }
}
