<?php

declare(strict_types=1);

namespace GoblinQ\Tests;

use GoblinQ\Transport\Response;
use GoblinQ\Transport\Transport;
use JsonException;
use LogicException;
use Throwable;

/**
 * Транспорт для unit-тестов: записывает запросы и отдаёт заранее заданные
 * ответы или исключения.
 */
final class FakeTransport implements Transport
{
    /** @var list<array{method: string, url: string, headers: list<string>, body: ?string, timeout: float}> */
    public array $requests = [];

    /** @var list<Response|Throwable> */
    private array $queue = [];

    /** @param array<string, mixed>|string|null $body */
    public function enqueue(int $status, array|string|null $body = null): void
    {
        if (is_array($body)) {
            try {
                $body = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            } catch (JsonException $e) {
                throw new LogicException('Не удалось закодировать ответ: ' . $e->getMessage(), 0, $e);
            }
        }
        $this->queue[] = new Response($status, $body ?? '');
    }

    public function fail(Throwable $error): void
    {
        $this->queue[] = $error;
    }

    public function send(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        float $timeout,
    ): Response {
        $this->requests[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
            'timeout' => $timeout,
        ];

        if ($this->queue === []) {
            throw new LogicException('FakeTransport: нет заранее заданного ответа');
        }
        $next = array_shift($this->queue);
        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next;
    }

    /** @return array<string, mixed> */
    public function decodedBody(int $index = 0): array
    {
        $body = $this->requests[$index]['body'] ?? null;
        if ($body === null) {
            return [];
        }
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
