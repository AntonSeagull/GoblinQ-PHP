<?php

declare(strict_types=1);

namespace GoblinQ\Exception;

/**
 * Ошибка, которую вернул сервер GoblinQ (HTTP >= 400).
 */
final class ApiException extends GoblinQException
{
    public function __construct(
        private readonly int $statusCode,
        private readonly string $errorCode,
        string $message,
        private readonly mixed $response = null,
    ) {
        parent::__construct($message, $statusCode);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Машиночитаемый код из тела ответа: invalid_channel, stale_lease,
     * unauthorized, server_busy и т. п.
     */
    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * Полное декодированное тело ответа (обычно {"error": {...}}).
     */
    public function response(): mixed
    {
        return $this->response;
    }

    public function is(string $errorCode): bool
    {
        return $this->errorCode === $errorCode;
    }
}
