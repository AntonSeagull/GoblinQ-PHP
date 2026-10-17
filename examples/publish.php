<?php

declare(strict_types=1);

/**
 * Публикация сообщения.
 *
 *   GOBLINQ_URL=http://127.0.0.1:42042 GOBLINQ_TOKEN=... php examples/publish.php
 */

require __DIR__ . '/../vendor/autoload.php';

use GoblinQ\Client;
use GoblinQ\Exception\ApiException;

$client = Client::fromEnvironment();

try {
    $orderId = 'order-' . date('YmdHis');
    $published = $client->publish(
        channel: 'emails',
        payload: [
            'to' => 'user@example.com',
            'subject' => 'Ваш заказ',
            'html' => '<h1>Заказ готов</h1>',
        ],
        // Повторный вызов с тем же ключом вернёт тот же message_id.
        idempotencyKey: $orderId . '-welcome',
        ttlSeconds: 3600,
        maxAttempts: 3,
        retryDelaySeconds: 30,
    );

    printf(
        "Опубликовано %s в канале %s (deduplicated=%s, expires=%s)\n",
        $published->messageId,
        $published->channel,
        $published->deduplicated ? 'true' : 'false',
        $published->expiresAt->format(DATE_ATOM),
    );
} catch (ApiException $e) {
    fwrite(STDERR, sprintf(
        "Ошибка API %d (%s): %s\n",
        $e->statusCode(),
        $e->errorCode(),
        $e->getMessage(),
    ));
    exit(1);
}
