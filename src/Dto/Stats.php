<?php

declare(strict_types=1);

namespace GoblinQ\Dto;

use GoblinQ\Internal\Hydrator;

final class Stats
{
    /**
     * @param array<string, int|float> $metrics
     * @param array<string, mixed>     $store
     */
    public function __construct(
        public readonly int $uptimeSeconds,
        public readonly array $metrics,
        public readonly array $store,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        /** @var array<string, int|float> $metrics */
        $metrics = Hydrator::array($data, 'metrics');
        /** @var array<string, mixed> $store */
        $store = Hydrator::array($data, 'store');

        return new self(
            Hydrator::int($data, 'uptime_seconds'),
            $metrics,
            $store,
        );
    }
}
