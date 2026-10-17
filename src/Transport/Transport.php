<?php

declare(strict_types=1);

namespace GoblinQ\Transport;

use GoblinQ\Exception\TransportException;

/**
 * Транспорт HTTP. Позволяет заменить cURL в тестах или встроить клиент
 * в приложение с собственным HTTP-стеком.
 */
interface Transport
{
    /**
     * @param list<string>      $headers
     * @param float             $timeout общий таймаут в секундах
     *
     * @throws TransportException
     */
    public function send(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        float $timeout,
    ): Response;
}
