<?php

declare(strict_types=1);

namespace GoblinQ\Tests;

use GoblinQ\Client;
use PHPUnit\Framework\TestCase;

/**
 * Интеграционный тест против реального сервера.
 *
 * Запуск:
 *   GOBLINQ_URL=http://127.0.0.1:42042 GOBLINQ_TOKEN=... vendor/bin/phpunit
 *
 * Без GOBLINQ_URL тест пропускается.
 */
final class IntegrationTest extends TestCase
{
    private Client $client;
    private string $channel;

    protected function setUp(): void
    {
        $url = getenv('GOBLINQ_URL');
        if (!is_string($url) || $url === '') {
            self::markTestSkipped('GOBLINQ_URL не задан');
        }

        $this->client = Client::fromEnvironment();
        $this->channel = 'phpunit-' . bin2hex(random_bytes(6));

        if (!$this->client->ready()) {
            self::markTestSkipped('GoblinQ не готов: /readyz вернул ошибку');
        }
    }

    public function testPublishClaimCompleteAndAudit(): void
    {
        $payload = ['to' => 'phpunit@example.com', 'n' => 1];
        $key = 'phpunit-' . bin2hex(random_bytes(8));

        $published = $this->client->publish($this->channel, $payload, $key, 300, 3, 1);
        self::assertFalse($published->deduplicated);

        // Повтор с теми же данными должен вернуть исходный ID.
        $duplicate = $this->client->publish($this->channel, $payload, $key, 300, 3, 1);
        self::assertTrue($duplicate->deduplicated);
        self::assertSame($published->messageId, $duplicate->messageId);

        $claim = $this->client->claim($this->channel, 'phpunit-worker', 'phpunit:' . getmypid(), 5);
        self::assertNotNull($claim);
        self::assertSame($published->messageId, $claim->messageId);
        self::assertEqualsCanonicalizing($payload, $claim->payload);
        self::assertSame(1, $claim->attempt);

        $completed = $this->client->completeSuccess(
            $claim->executionId,
            $claim->leaseToken,
            ['smtp_id' => 'abc123'],
        );
        self::assertSame('success', $completed->state);
        self::assertSame('success', $completed->deliveryOutcome);

        // Success исключает повторную выдачу тому же worker_id.
        self::assertNull($this->client->claim($this->channel, 'phpunit-worker', null, 0));

        $detail = $this->client->message($published->messageId, 'phpunit-worker');
        self::assertEqualsCanonicalizing($payload, $detail->payload);
        self::assertCount(1, $detail->deliveries);
        self::assertSame('success', $detail->deliveries[0]->outcome);
        self::assertSame('abc123', $detail->deliveries[0]->lastExecution?->result['smtp_id']);

        $executions = $this->client->executions($published->messageId, 'phpunit-worker');
        self::assertCount(1, $executions);
        self::assertSame('success', $executions[0]->state);

        $page = $this->client->messages($this->channel, null, null, 10);
        self::assertCount(1, $page->messages);
        self::assertSame($published->messageId, $page->messages[0]->messageId);

        $channels = $this->client->channels();
        $found = array_values(array_filter(
            $channels,
            fn ($channel): bool => $channel->name === $this->channel,
        ));
        self::assertCount(1, $found);
        self::assertSame(1, $found[0]->messageCount);
        self::assertSame(1, $found[0]->successCount);

        $workers = $this->client->workers($this->channel);
        self::assertCount(1, $workers);
        self::assertSame('phpunit-worker', $workers[0]->workerId);
        self::assertSame(1, $workers[0]->successCount);

        $stats = $this->client->stats();
        self::assertGreaterThanOrEqual(1, $stats->metrics['publish_total']);
        self::assertTrue($this->client->health());
        self::assertTrue($this->client->ready());
    }

    public function testErrorRetryFlow(): void
    {
        $key = 'phpunit-error-' . bin2hex(random_bytes(8));
        $published = $this->client->publish(
            $this->channel,
            ['fail' => true],
            $key,
            300,
            2,
            0,
        );

        $claim = $this->client->claim($this->channel, 'phpunit-error-worker', null, 5);
        self::assertNotNull($claim);
        self::assertSame($published->messageId, $claim->messageId);

        $completed = $this->client->completeError(
            $claim->executionId,
            $claim->leaseToken,
            'TEMP_FAILURE',
            'Временная ошибка',
            ['retryable' => true],
        );
        self::assertSame('error', $completed->state);
        self::assertSame('retry_wait', $completed->deliveryOutcome);
        self::assertNotNull($completed->nextAttemptAt);

        // retry_delay_seconds=0 — сообщение снова доступно сразу.
        $retry = $this->client->claim($this->channel, 'phpunit-error-worker', null, 5);
        self::assertNotNull($retry);
        self::assertSame(2, $retry->attempt);

        $final = $this->client->completeError(
            $retry->executionId,
            $retry->leaseToken,
            'FINAL_FAILURE',
            'Лимит попыток',
        );
        self::assertSame('exhausted', $final->deliveryOutcome);
        self::assertNull($this->client->claim($this->channel, 'phpunit-error-worker', null, 0));

        $executions = $this->client->executions($published->messageId, 'phpunit-error-worker');
        self::assertCount(2, $executions);
        self::assertSame('TEMP_FAILURE', $executions[0]->error['code']);
        self::assertSame('FINAL_FAILURE', $executions[1]->error['code']);
    }
}
