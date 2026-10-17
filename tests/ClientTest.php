<?php

declare(strict_types=1);

namespace GoblinQ\Tests;

use DateTimeImmutable;
use GoblinQ\Client;
use GoblinQ\Exception\ApiException;
use GoblinQ\Exception\GoblinQException;
use GoblinQ\Exception\TransportException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private FakeTransport $transport;
    private Client $client;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->client = new Client(
            'https://queue.example.test/api/',
            'top-secret',
            $this->transport,
            12.5,
        );
    }

    public function testPublishSendsRequestAndParsesResponse(): void
    {
        $this->transport->enqueue(201, [
            'message_id' => '01J00000000000000000000001',
            'channel' => 'emails',
            'created_at' => '2026-10-17T10:00:00Z',
            'expires_at' => '2026-10-17T11:00:00Z',
            'deduplicated' => false,
        ]);

        $result = $this->client->publish(
            'emails',
            ['to' => 'user@example.com'],
            'order-123',
            120,
            5,
            10,
        );

        self::assertSame('01J00000000000000000000001', $result->messageId);
        self::assertSame('emails', $result->channel);
        self::assertSame('2026-10-17T10:00:00+00:00', $result->createdAt->format(DATE_ATOM));
        self::assertFalse($result->deduplicated);

        $request = $this->transport->requests[0];
        self::assertSame('POST', $request['method']);
        self::assertSame('https://queue.example.test/api/v1/messages', $request['url']);
        self::assertContains('Content-Type: application/json', $request['headers']);
        self::assertContains('Authorization: Bearer top-secret', $request['headers']);
        self::assertSame(12.5, $request['timeout']);
        self::assertSame([
            'channel' => 'emails',
            'payload' => ['to' => 'user@example.com'],
            'idempotency_key' => 'order-123',
            'ttl_seconds' => 120,
            'max_attempts' => 5,
            'retry_delay_seconds' => 10,
        ], $this->transport->decodedBody());
    }

    public function testPublishWithoutOptionalFields(): void
    {
        $this->transport->enqueue(201, [
            'message_id' => '01J00000000000000000000002',
            'channel' => 'payments',
            'created_at' => '2026-10-17T10:00:00Z',
            'expires_at' => '2026-10-17T11:00:00Z',
            'deduplicated' => false,
        ]);

        $this->client->publish('payments', ['amount' => 100]);

        self::assertSame(
            ['channel' => 'payments', 'payload' => ['amount' => 100]],
            $this->transport->decodedBody(),
        );
    }

    public function testPublishRejectsNullPayload(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->client->publish('emails', null);
    }

    public function testClaimReturnsNullOnNoContent(): void
    {
        $this->transport->enqueue(204);

        $claim = $this->client->claim('emails', 'worker-1', 'host-a:1', 30);

        self::assertNull($claim);
        $body = $this->transport->decodedBody();
        self::assertSame('worker-1', $body['worker_id']);
        self::assertSame('host-a:1', $body['instance_id']);
        self::assertSame(30, $body['wait_seconds']);
        self::assertGreaterThanOrEqual(40.0, $this->transport->requests[0]['timeout']);
    }

    public function testClaimParsesPayload(): void
    {
        $this->transport->enqueue(200, [
            'message_id' => '01J00000000000000000000003',
            'execution_id' => '01J00000000000000000000004',
            'lease_token' => 'secret-token',
            'attempt' => 2,
            'lease_until' => '2026-10-17T10:02:00Z',
            'expires_at' => '2026-10-17T11:00:00Z',
            'payload' => ['to' => 'user@example.com', 'n' => 1],
        ]);

        $claim = $this->client->claim('emails', 'worker-1', null, 5);

        self::assertNotNull($claim);
        self::assertSame('secret-token', $claim->leaseToken);
        self::assertSame(2, $claim->attempt);
        self::assertSame(['to' => 'user@example.com', 'n' => 1], $claim->payload);
        self::assertSame('2026-10-17T10:02:00+00:00', $claim->leaseUntil->format(DATE_ATOM));

        $body = $this->transport->decodedBody();
        self::assertNotEmpty($body['instance_id']);
        self::assertStringContainsString(':', (string) $body['instance_id']);
    }

    public function testCompleteSuccessAndIdEncoding(): void
    {
        $this->transport->enqueue(200, [
            'execution_id' => 'exec/1',
            'state' => 'success',
            'delivery_outcome' => 'success',
            'attempt' => 1,
            'idempotent' => true,
        ]);

        $result = $this->client->completeSuccess('exec/1', 'token', ['smtp_id' => 'abc']);

        self::assertSame('exec/1', $result->executionId);
        self::assertSame('success', $result->deliveryOutcome);
        self::assertTrue($result->idempotent);
        self::assertNull($result->nextAttemptAt);
        self::assertStringEndsWith(
            '/v1/executions/exec%2F1/complete',
            $this->transport->requests[0]['url'],
        );
        self::assertSame([
            'lease_token' => 'token',
            'status' => 'success',
            'result' => ['smtp_id' => 'abc'],
        ], $this->transport->decodedBody());
    }

    public function testCompleteErrorBuildsErrorObject(): void
    {
        $this->transport->enqueue(200, [
            'execution_id' => 'exec-1',
            'state' => 'error',
            'delivery_outcome' => 'retry_wait',
            'attempt' => 1,
            'next_attempt_at' => '2026-10-17T10:05:00Z',
        ]);

        $result = $this->client->completeError(
            'exec-1',
            'token',
            'SMTP_TIMEOUT',
            'Connection timed out',
            ['host' => 'smtp.example.com'],
        );

        self::assertSame('retry_wait', $result->deliveryOutcome);
        self::assertSame('2026-10-17T10:05:00+00:00', $result->nextAttemptAt?->format(DATE_ATOM));
        self::assertSame([
            'lease_token' => 'token',
            'status' => 'error',
            'error' => [
                'code' => 'SMTP_TIMEOUT',
                'message' => 'Connection timed out',
                'details' => ['host' => 'smtp.example.com'],
            ],
        ], $this->transport->decodedBody());
    }

    public function testHeartbeat(): void
    {
        $this->transport->enqueue(200, [
            'execution_id' => 'exec-1',
            'lease_until' => '2026-10-17T10:10:00Z',
        ]);

        $result = $this->client->heartbeat('exec-1', 'token', 120);

        self::assertSame('2026-10-17T10:10:00+00:00', $result->leaseUntil->format(DATE_ATOM));
        self::assertSame([
            'lease_token' => 'token',
            'extend_seconds' => 120,
        ], $this->transport->decodedBody());
    }

    public function testApiErrorThrowsApiException(): void
    {
        $this->transport->enqueue(409, [
            'error' => ['code' => 'stale_lease', 'message' => 'lease_token недействителен'],
        ]);

        try {
            $this->client->completeSuccess('exec-1', 'old-token');
            self::fail('Ожидалось ApiException');
        } catch (ApiException $e) {
            self::assertSame(409, $e->statusCode());
            self::assertSame('stale_lease', $e->errorCode());
            self::assertTrue($e->is('stale_lease'));
            self::assertSame('lease_token недействителен', $e->getMessage());
            self::assertIsArray($e->response());
        }
    }

    public function testInvalidJsonResponseThrowsGoblinQException(): void
    {
        $this->transport->enqueue(200, 'not-json');

        $this->expectException(GoblinQException::class);
        $this->expectExceptionMessage('Некорректный JSON');

        $this->client->channels();
    }

    public function testTransportErrorPropagates(): void
    {
        $this->transport->fail(new TransportException('network down'));

        $this->expectException(TransportException::class);

        $this->client->channels();
    }

    public function testMessagesBuildsQueryAndParsesPage(): void
    {
        $this->transport->enqueue(200, [
            'messages' => [[
                'message_id' => '01J00000000000000000000005',
                'channel' => 'emails',
                'idempotency_key' => 'order-1',
                'created_at' => '2026-10-17T10:00:00Z',
                'expires_at' => '2026-10-17T11:00:00Z',
                'retention_until' => '2026-11-16T11:00:00Z',
                'ttl_seconds' => 3600,
                'max_attempts' => 3,
                'retry_delay_seconds' => 30,
            ]],
            'next_cursor' => 'cursor-2',
        ]);

        $page = $this->client->messages(
            'emails',
            new DateTimeImmutable('2026-10-17T10:00:00+03:00'),
            new DateTimeImmutable('2026-10-18T00:00:00Z'),
            5,
            'cursor-1',
        );

        self::assertCount(1, $page->messages);
        self::assertSame('order-1', $page->messages[0]->idempotencyKey);
        self::assertSame(3600, $page->messages[0]->ttlSeconds);
        self::assertTrue($page->hasMore());
        self::assertSame('cursor-2', $page->nextCursor);

        $url = $this->transport->requests[0]['url'];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame('emails', $query['channel']);
        self::assertSame('2026-10-17T07:00:00Z', $query['from']);
        self::assertSame('2026-10-18T00:00:00Z', $query['to']);
        self::assertSame('5', $query['limit']);
        self::assertSame('cursor-1', $query['cursor']);
    }

    public function testMessageDetailParsesDeliveries(): void
    {
        $this->transport->enqueue(200, [
            'message_id' => '01J00000000000000000000006',
            'channel' => 'emails',
            'idempotency_key' => null,
            'created_at' => '2026-10-17T10:00:00Z',
            'expires_at' => '2026-10-17T11:00:00Z',
            'retention_until' => '2026-11-16T11:00:00Z',
            'ttl_seconds' => 3600,
            'max_attempts' => 3,
            'retry_delay_seconds' => 30,
            'payload' => ['to' => 'user@example.com'],
            'payload_hash' => 'deadbeef',
            'deliveries' => [[
                'worker_id' => 'worker-1',
                'outcome' => 'success',
                'attempt_count' => 1,
                'last_seen_at' => '2026-10-17T10:01:00Z',
                'last_execution' => [
                    'execution_id' => 'exec-1',
                    'message_id' => '01J00000000000000000000006',
                    'worker_id' => 'worker-1',
                    'instance_id' => 'host-a:1',
                    'attempt' => 1,
                    'state' => 'success',
                    'lease_until' => '2026-10-17T10:02:00Z',
                    'started_at' => '2026-10-17T10:01:00Z',
                    'finished_at' => '2026-10-17T10:01:10Z',
                    'result' => ['smtp_id' => 'abc'],
                ],
            ]],
        ]);

        $message = $this->client->message('01J00000000000000000000006', 'worker-1');

        self::assertSame(['to' => 'user@example.com'], $message->payload);
        self::assertSame('deadbeef', $message->payloadHash);
        self::assertCount(1, $message->deliveries);
        self::assertSame('success', $message->deliveries[0]->outcome);
        self::assertSame('host-a:1', $message->deliveries[0]->lastExecution?->instanceId);
        self::assertSame('abc', $message->deliveries[0]->lastExecution?->result['smtp_id']);
        self::assertSame(
            'https://queue.example.test/api/v1/messages/01J00000000000000000000006?worker_id=worker-1',
            $this->transport->requests[0]['url'],
        );
    }

    public function testAuditCollectionsAndStats(): void
    {
        $this->transport->enqueue(200, [
            'channels' => [[
                'name' => 'emails',
                'created_at' => '2026-10-17T10:00:00Z',
                'message_count' => 2,
                'delivery_count' => 1,
                'processing_count' => 0,
                'success_count' => 1,
                'exhausted_count' => 0,
                'open_lease_count' => 0,
            ]],
        ]);
        $this->transport->enqueue(200, [
            'executions' => [[
                'execution_id' => 'exec-1',
                'message_id' => 'msg-1',
                'worker_id' => 'worker-1',
                'attempt' => 1,
                'state' => 'success',
                'lease_until' => '2026-10-17T10:02:00Z',
                'started_at' => '2026-10-17T10:01:00Z',
                'result' => ['ok' => true],
            ]],
        ]);
        $this->transport->enqueue(200, [
            'workers' => [[
                'worker_id' => 'worker-1',
                'first_seen_at' => '2026-10-17T10:00:00Z',
                'last_seen_at' => '2026-10-17T10:01:00Z',
                'delivery_count' => 1,
                'success_count' => 1,
                'failure_count' => 0,
            ]],
        ]);
        $this->transport->enqueue(200, [
            'uptime_seconds' => 42,
            'metrics' => ['publish_total' => 2, 'claim_total' => 1, 'publish_avg_ms' => 0.5],
            'store' => ['messages' => 2, 'deliveries' => 1, 'files' => ['goblinq.db' => 4096]],
        ]);

        $channels = $this->client->channels();
        self::assertSame('emails', $channels[0]->name);
        self::assertSame(1, $channels[0]->successCount);

        $executions = $this->client->executions('msg-1', 'worker-1');
        self::assertSame('success', $executions[0]->state);
        self::assertTrue($executions[0]->result['ok']);

        $workers = $this->client->workers('emails');
        self::assertSame('worker-1', $workers[0]->workerId);
        self::assertSame(1, $workers[0]->successCount);

        $stats = $this->client->stats();
        self::assertSame(42, $stats->uptimeSeconds);
        self::assertSame(2, $stats->metrics['publish_total']);
        self::assertSame(2, $stats->store['messages']);
    }

    public function testHealthAndReady(): void
    {
        $this->transport->enqueue(200, ['status' => 'ok']);
        $this->transport->enqueue(200, ['status' => 'ok']);
        $this->transport->enqueue(503, [
            'status' => 'error',
            'message' => 'SQLite недоступен',
        ]);

        self::assertTrue($this->client->health());
        self::assertTrue($this->client->ready());
        self::assertFalse($this->client->ready());
    }

    public function testNoAuthorizationHeaderWithoutToken(): void
    {
        $transport = new FakeTransport();
        $client = new Client('http://127.0.0.1:42042', null, $transport);
        $transport->enqueue(200, ['status' => 'ok']);

        self::assertTrue($client->health());
        foreach ($transport->requests[0]['headers'] as $header) {
            self::assertStringStartsNotWith('Authorization:', $header);
        }
    }

    public function testFromEnvironment(): void
    {
        $oldUrl = getenv('GOBLINQ_URL');
        $oldToken = getenv('GOBLINQ_TOKEN');
        putenv('GOBLINQ_URL=https://goblinq.internal:9000');
        putenv('GOBLINQ_TOKEN=env-token');

        try {
            $transport = new FakeTransport();
            $client = Client::fromEnvironment($transport);
            self::assertSame('https://goblinq.internal:9000', $client->baseUrl());

            $transport->enqueue(200, ['status' => 'ok']);
            self::assertTrue($client->health());
            self::assertContains('Authorization: Bearer env-token', $transport->requests[0]['headers']);
        } finally {
            $oldUrl === false ? putenv('GOBLINQ_URL') : putenv('GOBLINQ_URL=' . $oldUrl);
            $oldToken === false ? putenv('GOBLINQ_TOKEN') : putenv('GOBLINQ_TOKEN=' . $oldToken);
        }
    }

    public function testRejectsInvalidBaseUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Client('ftp://example.com', null, new FakeTransport());
    }

    public function testRejectsNegativeWait(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->client->claim('emails', 'worker-1', null, -1);
    }
}
