<?php

declare(strict_types=1);

namespace GoblinQ;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use GoblinQ\Dto\Channel;
use GoblinQ\Dto\Claim;
use GoblinQ\Dto\CompleteResult;
use GoblinQ\Dto\Execution;
use GoblinQ\Dto\HeartbeatResult;
use GoblinQ\Dto\MessageDetail;
use GoblinQ\Dto\MessagePage;
use GoblinQ\Dto\PublishResult;
use GoblinQ\Dto\Stats;
use GoblinQ\Dto\Worker;
use GoblinQ\Exception\ApiException;
use GoblinQ\Exception\GoblinQException;
use GoblinQ\Internal\Hydrator;
use GoblinQ\Transport\CurlTransport;
use GoblinQ\Transport\Transport;
use InvalidArgumentException;
use JsonException;

/**
 * Клиент HTTP API GoblinQ.
 *
 * Пример:
 *
 *     $client = new Client('http://127.0.0.1:42042', getenv('GOBLINQ_TOKEN') ?: null);
 *     $published = $client->publish('emails', ['to' => 'user@example.com']);
 *     $job = $client->claim('emails', 'mailer-1', waitSeconds: 30);
 *     if ($job !== null) {
 *         $client->completeSuccess($job->executionId, $job->leaseToken, ['smtp_id' => 'abc']);
 *     }
 */
final class Client
{
    public const VERSION = '1.0.0';
    public const DEFAULT_URL = 'http://127.0.0.1:42042';

    private readonly string $baseUrl;
    private readonly ?string $apiToken;
    private readonly Transport $transport;
    private readonly float $timeout;

    public function __construct(
        string $baseUrl = self::DEFAULT_URL,
        ?string $apiToken = null,
        ?Transport $transport = null,
        float $timeout = 15.0,
    ) {
        $baseUrl = rtrim(trim($baseUrl), '/');
        $parts = parse_url($baseUrl);
        if (
            $parts === false
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || $parts['host'] === ''
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new InvalidArgumentException('baseUrl должен быть абсолютным http(s)-URL без query/fragment');
        }
        if ($timeout <= 0) {
            throw new InvalidArgumentException('timeout должен быть больше нуля');
        }
        if ($apiToken !== null && preg_match('/[\r\n]/', $apiToken) === 1) {
            throw new InvalidArgumentException('API-токен содержит недопустимые символы перевода строки');
        }

        $this->baseUrl = $baseUrl;
        $this->apiToken = $apiToken === '' ? null : $apiToken;
        $this->transport = $transport ?? new CurlTransport();
        $this->timeout = $timeout;
    }

    /**
     * Создаёт клиент из переменных окружения GOBLINQ_URL и GOBLINQ_TOKEN.
     */
    public static function fromEnvironment(
        ?Transport $transport = null,
        float $timeout = 15.0,
    ): self {
        $url = getenv('GOBLINQ_URL');
        $token = getenv('GOBLINQ_TOKEN');

        return new self(
            is_string($url) && $url !== '' ? $url : self::DEFAULT_URL,
            is_string($token) && $token !== '' ? $token : null,
            $transport,
            $timeout,
        );
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    // --- Публикация -----------------------------------------------------

    /**
     * Публикует сообщение. Успешный ответ означает, что запись уже
     * зафиксирована в SQLite.
     *
     * @param mixed $payload произвольное JSON-значение, кроме null
     */
    public function publish(
        string $channel,
        mixed $payload,
        ?string $idempotencyKey = null,
        ?int $ttlSeconds = null,
        ?int $maxAttempts = null,
        ?int $retryDelaySeconds = null,
    ): PublishResult {
        $this->assertName($channel, 'channel');
        if ($payload === null) {
            throw new InvalidArgumentException('payload не может быть null');
        }

        $body = ['channel' => $channel, 'payload' => $payload];
        if ($idempotencyKey !== null) {
            $body['idempotency_key'] = $idempotencyKey;
        }
        if ($ttlSeconds !== null) {
            $body['ttl_seconds'] = $ttlSeconds;
        }
        if ($maxAttempts !== null) {
            $body['max_attempts'] = $maxAttempts;
        }
        if ($retryDelaySeconds !== null) {
            $body['retry_delay_seconds'] = $retryDelaySeconds;
        }

        [, $data] = $this->request('POST', '/v1/messages', $body);

        return PublishResult::fromArray($this->requireObject($data));
    }

    // --- Получение задания ---------------------------------------------

    /**
     * Запрашивает сообщение с long polling. null означает 204 No Content.
     *
     * worker_id — стабильный логический идентификатор обработчика. Не
     * генерируйте его случайно при каждом запуске: от него зависит семантика
     * доставки. instance_id описывает конкретный процесс и нужен только для
     * аудита.
     */
    public function claim(
        string $channel,
        string $workerId,
        ?string $instanceId = null,
        int $waitSeconds = 30,
    ): ?Claim {
        $this->assertName($channel, 'channel');
        $this->assertName($workerId, 'workerId');
        if ($waitSeconds < 0) {
            throw new InvalidArgumentException('waitSeconds не может быть отрицательным');
        }

        $body = [
            'channel' => $channel,
            'worker_id' => $workerId,
            'instance_id' => $instanceId ?? self::defaultInstanceId(),
            'wait_seconds' => $waitSeconds,
        ];
        $timeout = max($this->timeout, $waitSeconds + 10.0);
        [$status, $data] = $this->request('POST', '/v1/claim', $body, [], $timeout);
        if ($status === 204) {
            return null;
        }

        return Claim::fromArray($this->requireObject($data));
    }

    // --- Завершение и heartbeat ----------------------------------------

    /**
     * Завершает попытку. Обычно используются completeSuccess/completeError.
     * $status — success или error.
     */
    public function complete(
        string $executionId,
        string $leaseToken,
        string $status,
        mixed $result = null,
        mixed $error = null,
    ): CompleteResult {
        $this->assertName($executionId, 'executionId');
        $this->assertName($leaseToken, 'leaseToken');
        if ($status !== 'success' && $status !== 'error') {
            throw new InvalidArgumentException('status должен быть success или error');
        }
        if ($status === 'error' && $error === null) {
            throw new InvalidArgumentException('для status=error требуется error');
        }
        if ($status === 'success' && $error !== null) {
            throw new InvalidArgumentException('нельзя передавать error при status=success');
        }

        $body = ['lease_token' => $leaseToken, 'status' => $status];
        if ($result !== null) {
            $body['result'] = $result;
        }
        if ($error !== null) {
            $body['error'] = $error;
        }

        [, $data] = $this->request(
            'POST',
            '/v1/executions/' . rawurlencode($executionId) . '/complete',
            $body,
        );

        return CompleteResult::fromArray($this->requireObject($data));
    }

    /** @param mixed $result произвольный JSON-результат */
    public function completeSuccess(
        string $executionId,
        string $leaseToken,
        mixed $result = null,
    ): CompleteResult {
        return $this->complete($executionId, $leaseToken, 'success', $result);
    }

    /**
     * Завершает попытку ошибкой. Сервер сохранит error и запланирует retry,
     * если не исчерпаны max_attempts и TTL.
     */
    public function completeError(
        string $executionId,
        string $leaseToken,
        string $code,
        ?string $message = null,
        mixed $details = null,
    ): CompleteResult {
        if (trim($code) === '') {
            throw new InvalidArgumentException('code ошибки не может быть пустым');
        }
        $error = ['code' => $code];
        if ($message !== null) {
            $error['message'] = $message;
        }
        if ($details !== null) {
            $error['details'] = $details;
        }

        return $this->complete($executionId, $leaseToken, 'error', null, $error);
    }

    /**
     * Продлевает активную аренду. Для долгих задач вызывайте периодически,
     * пока выполняется внешнее действие.
     */
    public function heartbeat(
        string $executionId,
        string $leaseToken,
        int $extendSeconds = 60,
    ): HeartbeatResult {
        $this->assertName($executionId, 'executionId');
        $this->assertName($leaseToken, 'leaseToken');
        if ($extendSeconds < 0) {
            throw new InvalidArgumentException('extendSeconds не может быть отрицательным');
        }

        [, $data] = $this->request(
            'POST',
            '/v1/executions/' . rawurlencode($executionId) . '/heartbeat',
            ['lease_token' => $leaseToken, 'extend_seconds' => $extendSeconds],
        );

        return HeartbeatResult::fromArray($this->requireObject($data));
    }

    // --- Аудит ----------------------------------------------------------

    /** @return list<Channel> */
    public function channels(): array
    {
        [, $data] = $this->request('GET', '/v1/channels');
        $data = $this->requireObject($data);

        $result = [];
        foreach (Hydrator::objectList($data, 'channels') as $item) {
            $result[] = Channel::fromArray($item);
        }

        return $result;
    }

    /**
     * Постраничный список сообщений (без payload), новые первыми.
     * Для следующей страницы передайте $page->nextCursor.
     */
    public function messages(
        ?string $channel = null,
        ?DateTimeInterface $from = null,
        ?DateTimeInterface $to = null,
        int $limit = 50,
        ?string $cursor = null,
    ): MessagePage {
        if ($channel !== null) {
            $this->assertName($channel, 'channel');
        }
        if ($limit < 1) {
            throw new InvalidArgumentException('limit должен быть >= 1');
        }

        [, $data] = $this->request('GET', '/v1/messages', null, [
            'channel' => $channel,
            'from' => $from === null ? null : self::formatTime($from),
            'to' => $to === null ? null : self::formatTime($to),
            'limit' => $limit,
            'cursor' => $cursor,
        ]);

        return MessagePage::fromArray($this->requireObject($data));
    }

    /**
     * Карточка сообщения с payload и доставками по воркерам.
     * Если передан workerId, для пары без claim будет вычислен never_claimed.
     */
    public function message(string $messageId, ?string $workerId = null): MessageDetail
    {
        $this->assertName($messageId, 'messageId');
        if ($workerId !== null) {
            $this->assertName($workerId, 'workerId');
        }

        [, $data] = $this->request('GET', '/v1/messages/' . rawurlencode($messageId), null, [
            'worker_id' => $workerId,
        ]);

        return MessageDetail::fromArray($this->requireObject($data));
    }

    /** @return list<Execution> */
    public function executions(
        string $messageId,
        ?string $workerId = null,
        int $limit = 1000,
    ): array {
        $this->assertName($messageId, 'messageId');
        if ($workerId !== null) {
            $this->assertName($workerId, 'workerId');
        }
        if ($limit < 1) {
            throw new InvalidArgumentException('limit должен быть >= 1');
        }

        [, $data] = $this->request(
            'GET',
            '/v1/messages/' . rawurlencode($messageId) . '/executions',
            null,
            ['worker_id' => $workerId, 'limit' => $limit],
        );
        $data = $this->requireObject($data);

        $result = [];
        foreach (Hydrator::objectList($data, 'executions') as $item) {
            $result[] = Execution::fromArray($item);
        }

        return $result;
    }

    /** @return list<Worker> */
    public function workers(?string $channel = null): array
    {
        if ($channel !== null) {
            $this->assertName($channel, 'channel');
        }

        [, $data] = $this->request('GET', '/v1/workers', null, ['channel' => $channel]);
        $data = $this->requireObject($data);

        $result = [];
        foreach (Hydrator::objectList($data, 'workers') as $item) {
            $result[] = Worker::fromArray($item);
        }

        return $result;
    }

    public function stats(): Stats
    {
        [, $data] = $this->request('GET', '/v1/stats');

        return Stats::fromArray($this->requireObject($data));
    }

    /** Жив ли процесс. false также при сетевой ошибке. */
    public function health(): bool
    {
        return $this->statusOk('/healthz');
    }

    /** Доступно ли хранилище SQLite. false также при сетевой ошибке. */
    public function ready(): bool
    {
        return $this->statusOk('/readyz');
    }

    // --- Внутреннее -----------------------------------------------------

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, mixed>      $query
     *
     * @return array{0: int, 1: mixed}
     */
    private function request(
        string $method,
        string $path,
        ?array $body = null,
        array $query = [],
        ?float $timeout = null,
    ): array {
        $url = $this->baseUrl . $path;
        $query = array_filter(
            $query,
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $headers = ['Accept: application/json', 'User-Agent: goblinq-php/' . self::VERSION];
        $encoded = null;
        if ($body !== null) {
            try {
                $encoded = json_encode(
                    $body,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                );
            } catch (JsonException $e) {
                throw new InvalidArgumentException(
                    'Не удалось сериализовать тело запроса в JSON: ' . $e->getMessage(),
                    0,
                    $e,
                );
            }
            $headers[] = 'Content-Type: application/json';
        }
        if ($this->apiToken !== null) {
            $headers[] = 'Authorization: Bearer ' . $this->apiToken;
        }

        $response = $this->transport->send(
            $method,
            $url,
            $headers,
            $encoded,
            $timeout ?? $this->timeout,
        );

        $decoded = null;
        if (trim($response->body) !== '') {
            try {
                $decoded = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new GoblinQException(
                    sprintf(
                        'Некорректный JSON в ответе GoblinQ (HTTP %d): %s',
                        $response->status,
                        $e->getMessage(),
                    ),
                    0,
                    $e,
                );
            }
        }

        if ($response->status >= 400) {
            $error = is_array($decoded) && is_array($decoded['error'] ?? null)
                ? $decoded['error']
                : [];
            $code = is_string($error['code'] ?? null) && $error['code'] !== ''
                ? $error['code']
                : 'http_error';
            $message = is_string($error['message'] ?? null) && $error['message'] !== ''
                ? $error['message']
                : sprintf('GoblinQ вернул HTTP %d', $response->status);

            throw new ApiException($response->status, $code, $message, $decoded);
        }

        return [$response->status, $decoded];
    }

    /** @return array<string, mixed> */
    private function requireObject(mixed $data): array
    {
        if (!is_array($data)) {
            throw new GoblinQException('Некорректный ответ GoblinQ: ожидался JSON-объект');
        }

        return $data;
    }

    private function statusOk(string $path): bool
    {
        try {
            [, $data] = $this->request('GET', $path);
        } catch (GoblinQException) {
            return false;
        }

        return is_array($data) && ($data['status'] ?? null) === 'ok';
    }

    private function assertName(string $value, string $field): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException($field . ' не может быть пустым');
        }
    }

    private static function formatTime(DateTimeInterface $date): string
    {
        return DateTimeImmutable::createFromInterface($date)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }

    private static function defaultInstanceId(): string
    {
        $host = gethostname();
        $pid = getmypid();

        return sprintf('%s:%d', $host === false || $host === '' ? 'php' : $host, $pid === false ? 0 : $pid);
    }
}
