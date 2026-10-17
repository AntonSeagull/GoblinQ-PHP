# GoblinQ PHP Client

PHP-клиент для сервиса доставки заданий [GoblinQ](../GoblinQ/README.md).
Без внешних runtime-зависимостей: только `ext-curl` и `ext-json`, PHP 8.1+.

Библиотека предоставляет:

- публикацию с идемпотентным ключом;
- long polling (`claim`) с возвратом `null` при `204 No Content`;
- успешное и ошибочное завершение попытки, `heartbeat`;
- аудит: каналы, сообщения, попытки, воркеры, статистика;
- типизированные DTO с `DateTimeImmutable` вместо «сырых» массивов;
- `ApiException` с HTTP-статусом и машинным кодом ошибки;
- подменяемый транспорт для unit-тестов без сети.

## Установка

```bash
composer require goblinq/client
```

Для разработки самой библиотеки:

```bash
composer install
composer test
```

## Быстрый старт

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use GoblinQ\Client;

$client = new Client('http://127.0.0.1:42042', getenv('GOBLINQ_TOKEN') ?: null);

// Публикация: ответ означает, что сообщение уже зафиксировано в SQLite.
$published = $client->publish(
    channel: 'emails',
    payload: ['to' => 'user@example.com', 'subject' => 'Ваш заказ'],
    idempotencyKey: 'order-123-welcome',
    ttlSeconds: 3600,
    maxAttempts: 3,
    retryDelaySeconds: 30,
);

echo $published->messageId, PHP_EOL;

// Long polling до 30 секунд; null означает, что сообщений нет.
$job = $client->claim('emails', 'mailer-1', waitSeconds: 30);
if ($job !== null) {
    try {
        // ... внешнее действие: SMTP, платёж, экспорт ...
        $client->completeSuccess($job->executionId, $job->leaseToken, [
            'smtp_id' => 'abc123',
        ]);
    } catch (Throwable $e) {
        $client->completeError(
            $job->executionId,
            $job->leaseToken,
            'MAIL_FAILED',
            $e->getMessage(),
        );
    }
}
```

`Client::fromEnvironment()` читает `GOBLINQ_URL` и `GOBLINQ_TOKEN`:

```php
$client = Client::fromEnvironment();
```

Конструктор:

```php
new Client(
    string $baseUrl = 'http://127.0.0.1:42042',
    ?string $apiToken = null,
    ?Transport $transport = null,   // по умолчанию GoblinQ\Transport\CurlTransport
    float $timeout = 15.0,          // секунды
);
```

`baseUrl` может содержать префикс пути (`https://example.com/goblinq`), но не
query/fragment. Токен передаётся в `Authorization: Bearer ...` для всех
`/v1/*`.

## Публикация

```php
$result = $client->publish(
    channel: 'emails',
    payload: ['to' => 'user@example.com'],
    idempotencyKey: 'order-123-welcome',
    ttlSeconds: 3600,          // null — значение сервера
    maxAttempts: 3,            // общее число попыток, включая первую
    retryDelaySeconds: 30,     // фиксированная задержка повтора
);

$result->messageId;     // ULID
$result->channel;
$result->createdAt;     // DateTimeImmutable
$result->expiresAt;     // DateTimeImmutable
$result->deduplicated;  // true при повторе с тем же ключом и данными
```

Повтор с тем же `channel + idempotencyKey` и эквивалентным JSON вернёт тот же
`messageId` и `deduplicated=true`. Другие данные → `409 idempotency_conflict`.
Без ключа каждый вызов создаёт новое сообщение.

`payload` — произвольное JSON-значение, кроме `null`. Кодирование выполняется с
`JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`.

## Получение и завершение заданий

```php
$job = $client->claim(
    channel: 'emails',
    workerId: 'mailer-1',              // стабильный логический ID
    instanceId: 'host-a:pid-123',      // null → hostname:pid текущего процесса
    waitSeconds: 30,                    // 0 — без ожидания
);

if ($job === null) {
    // 204 No Content: подходящих сообщений нет
}

$job->messageId;
$job->executionId;
$job->leaseToken;   // секрет аренды, в БД хранится только хеш
$job->attempt;
$job->leaseUntil;
$job->expiresAt;
$job->payload;      // декодированный JSON
```

`worker_id` должен быть **стабильным**: от него зависит, какие сообщения уже
успешно обработаны. Не генерируйте его случайно при каждом запуске.
`instance_id` описывает конкретный процесс и используется только в аудите.

Завершение:

```php
// Успех. result — произвольный JSON, можно опустить.
$client->completeSuccess($job->executionId, $job->leaseToken, ['smtp_id' => 'abc']);

// Ошибка: сервер сохранит error и запланирует retry, если позволяют TTL и max_attempts.
$client->completeError(
    $job->executionId,
    $job->leaseToken,
    code: 'SMTP_TIMEOUT',
    message: 'Connection timed out',
    details: ['host' => 'smtp.example.com'],
);

// Общий вариант.
$client->complete($executionId, $leaseToken, 'success', ['ok' => true]);
```

`CompleteResult` содержит `state` (`success`/`error`), `deliveryOutcome`
(`success`, `retry_wait`, `exhausted`, `expired`), `attempt`,
`nextAttemptAt` и `idempotent`.

Повторный `complete` с тем же токеном и эквивалентным телом безопасен и
помечается `idempotent=true`. Устаревшая аренда даёт
`ApiException` с кодом `stale_lease`.

### Долгие задачи и heartbeat

Аренда по умолчанию — 60 секунд. Если внешнее действие может выполняться
дольше, периодически продлевайте аренду:

```php
$client->heartbeat($job->executionId, $job->leaseToken, extendSeconds: 120);
```

Продление отсчитывается от `max(now, lease_until)` и ограничено настройкой
сервера `MAX_LEASE_EXTEND_SECONDS`.

## Аудит

```php
$channels = $client->channels();          // list<Channel>

$page = $client->messages(                // без payload, новые первыми
    channel: 'emails',
    from: new DateTimeImmutable('-1 hour'),
    to: null,
    limit: 50,
    cursor: null,
);
foreach ($page->messages as $message) {
    $message->messageId;
    $message->createdAt;
}
$page->nextCursor;                        // null, если страниц больше нет

$detail = $client->message($messageId, workerId: 'mailer-1');
$detail->payload;
$detail->deliveries;                      // list<Delivery>
$detail->deliveries[0]->outcome;          // never_claimed|processing|retry_wait|success|exhausted|expired
$detail->deliveries[0]->lastExecution;    // ?Execution

$executions = $client->executions($messageId, workerId: 'mailer-1');
$executions[0]->result;
$executions[0]->error;

$workers = $client->workers('emails');    // list<Worker>
$stats = $client->stats();                // uptimeSeconds, metrics, store
```

`health(): bool` и `ready(): bool` возвращают `false` при сетевой ошибке или
неготовности вместо исключения.

## Ошибки

```php
use GoblinQ\Exception\ApiException;
use GoblinQ\Exception\GoblinQException;
use GoblinQ\Exception\TransportException;

try {
    $client->publish('emails', ['to' => 'user@example.com']);
} catch (ApiException $e) {
    $e->statusCode();   // 400, 401, 409, 413, 503...
    $e->errorCode();    // invalid_channel, stale_lease, server_busy...
    $e->getMessage();   // сообщение сервера
    $e->response();     // полное декодированное тело ответа
    $e->is('stale_lease');
} catch (TransportException $e) {
    // сервер недоступен, таймаут, обрыв соединения
} catch (GoblinQException $e) {
    // некорректный JSON ответа и другие ошибки клиента
}
```

Логика повторных сетевых попыток не навязывается: worker-цикл сам решает,
когда повторить `claim`. Ошибки валидации (`InvalidArgumentException`)
возникают до HTTP-запроса — например, пустой `workerId` или `payload=null`.

## Гарантии и важные ограничения

Сервер работает по модели **at-least-once**. Если процесс упал после внешнего
действия, но до `complete`, сообщение будет выдано снова после истечения
аренды. Делайте обработчик идемпотентным; `complete` можно безопасно
повторять.

Новый `worker_id` получает старейшее актуальное сообщение, даже если другой
воркер уже его обработал. Это не ошибка: так устроена независимая доставка на
пару `(message, worker_id)`.

После истечения TTL новые попытки не выдаются; уже начатая аренда может быть
завершена. Очистка истории после retention удаляет сообщение и прекращает
гарантию дедупликации по его `idempotency_key`.

Клиент не реализует exactly-once, кластеризацию и фоновые задания — это
ответственность сервера и вашего обработчика.

## Тесты

```bash
composer install

# unit-тесты без сети (интеграционные пропускаются)
composer test

# вместе с реальным сервером
GOBLINQ_URL=http://127.0.0.1:42042 composer test

# с токеном
GOBLINQ_URL=http://127.0.0.1:42042 GOBLINQ_TOKEN=secret composer test

# статический анализ (PHPStan, level 8)
composer analyse
```

Unit-тесты используют `GoblinQ\Tests\FakeTransport` и проверяют тела запросов,
заголовки, таймауты, разбор DTO и ошибок. Интеграционные тесты выполняют
полный цикл publish → claim → complete, аудит, retry/exhausted и идемпотентную
публикацию.

## Свой транспорт

Для интеграции с PSR-18, моков или нестандартного HTTP-стека реализуйте
`GoblinQ\Transport\Transport` и передайте объект в конструктор:

```php
use GoblinQ\Transport\Response;
use GoblinQ\Transport\Transport;

final class MyTransport implements Transport
{
    public function send(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        float $timeout,
    ): Response {
        // ... выполнить запрос ...
        return new Response($status, $responseBody);
    }
}

$client = new Client('http://127.0.0.1:42042', null, new MyTransport());
```

## Примеры

- [examples/publish.php](examples/publish.php) — публикация с идемпотентным ключом.
- [examples/worker.php](examples/worker.php) — long polling, обработка,
  `completeSuccess`/`completeError`, остановка по SIGINT/SIGTERM.

```bash
GOBLINQ_URL=http://127.0.0.1:42042 php examples/publish.php

GOBLINQ_URL=http://127.0.0.1:42042 \
GOBLINQ_WORKER_ID=mailer-1 \
WORKER_MAX_JOBS=10 \
php examples/worker.php
```
