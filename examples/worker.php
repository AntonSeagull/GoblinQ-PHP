<?php

declare(strict_types=1);

/**
 * Worker: long polling → обработка → complete.
 *
 *   GOBLINQ_URL=http://127.0.0.1:42042 \
 *   GOBLINQ_TOKEN=... \
 *   GOBLINQ_WORKER_ID=mailer-1 \
 *   WORKER_MAX_JOBS=10 \
 *   php examples/worker.php
 *
 * worker_id должен быть стабильным между запусками процесса: от него зависит,
 * какие сообщения уже успешно обработаны.
 */

require __DIR__ . '/../vendor/autoload.php';

use GoblinQ\Client;
use GoblinQ\Dto\Claim;
use GoblinQ\Exception\ApiException;
use GoblinQ\Exception\TransportException;

$client = Client::fromEnvironment();
$workerId = getenv('GOBLINQ_WORKER_ID') ?: 'php-worker';
$instanceId = sprintf('%s:%d', gethostname() ?: 'php', getmypid());
$maxJobs = (int) (getenv('WORKER_MAX_JOBS') ?: 0); // 0 — без ограничения
$processed = 0;
$running = true;

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $stop = static function () use (&$running): void {
        $running = false;
        fwrite(STDOUT, "Получен сигнал, завершаемся после текущего задания\n");
    };
    pcntl_signal(SIGINT, $stop);
    pcntl_signal(SIGTERM, $stop);
}

while ($running && ($maxJobs === 0 || $processed < $maxJobs)) {
    try {
        $job = $client->claim('emails', $workerId, $instanceId, 30);
    } catch (TransportException $e) {
        fwrite(STDERR, 'Сеть: ' . $e->getMessage() . "\n");
        sleep(2);
        continue;
    } catch (ApiException $e) {
        fwrite(STDERR, sprintf("API %d (%s): %s\n", $e->statusCode(), $e->errorCode(), $e->getMessage()));
        sleep(2);
        continue;
    }

    if ($job === null) {
        fwrite(STDOUT, "Нет сообщений, повторяем long polling\n");
        continue;
    }

    fwrite(STDOUT, sprintf(
        "Получено %s, попытка %d, payload=%s\n",
        $job->messageId,
        $job->attempt,
        json_encode($job->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ));

    try {
        // Здесь выполняется реальное действие: SMTP, платёж, экспорт и т. п.
        process($job);
        $client->completeSuccess(
            $job->executionId,
            $job->leaseToken,
            ['handled_by' => $workerId, 'finished_at' => gmdate(DATE_ATOM)],
        );
        $processed++;
        fwrite(STDOUT, "Успешно завершено\n");
    } catch (Throwable $e) {
        // Ошибка будет сохранена, и сервер запланирует повтор в пределах TTL
        // и max_attempts. Для долгих задач перед обработкой вызывайте heartbeat.
        try {
            $client->completeError(
                $job->executionId,
                $job->leaseToken,
                'WORKER_ERROR',
                $e->getMessage(),
                ['worker_id' => $workerId],
            );
        } catch (ApiException | TransportException $completeError) {
            // Аренда могла истечь: сообщение будет выдано снова.
            fwrite(STDERR, 'Не удалось завершить попытку: ' . $completeError->getMessage() . "\n");
        }
        fwrite(STDERR, 'Ошибка обработки: ' . $e->getMessage() . "\n");
    }
}

fwrite(STDOUT, sprintf("Обработано заданий: %d\n", $processed));

function process(Claim $job): void
{
    if (!is_array($job->payload)) {
        throw new RuntimeException('Ожидался JSON-объект payload');
    }
    usleep(50_000); // имитация внешнего действия
}
