<?php

declare(strict_types=1);

namespace GoblinQ\Dto;

use GoblinQ\Internal\Hydrator;

final class MessagePage
{
    /**
     * @param list<MessageSummary> $messages
     */
    public function __construct(
        public readonly array $messages,
        public readonly ?string $nextCursor,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $messages = [];
        foreach (Hydrator::objectList($data, 'messages') as $item) {
            $messages[] = MessageSummary::fromArray($item);
        }

        return new self($messages, Hydrator::nullableString($data, 'next_cursor'));
    }

    public function hasMore(): bool
    {
        return $this->nextCursor !== null;
    }
}
