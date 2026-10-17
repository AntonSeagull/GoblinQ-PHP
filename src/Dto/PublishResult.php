<?php

declare(strict_types=1);

namespace GoblinQ\Dto;

use DateTimeImmutable;
use GoblinQ\Internal\Hydrator;

final class PublishResult
{
    public function __construct(
        public readonly string $messageId,
        public readonly string $channel,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $expiresAt,
        public readonly bool $deduplicated,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Hydrator::string($data, 'message_id'),
            Hydrator::string($data, 'channel'),
            Hydrator::date($data, 'created_at'),
            Hydrator::date($data, 'expires_at'),
            Hydrator::bool($data, 'deduplicated'),
        );
    }
}
