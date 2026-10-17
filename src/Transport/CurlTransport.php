<?php

declare(strict_types=1);

namespace GoblinQ\Transport;

use GoblinQ\Exception\TransportException;

/**
 * Транспорт на ext-curl. Не требует внешних зависимостей.
 */
final class CurlTransport implements Transport
{
    public function send(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        float $timeout,
    ): Response {
        if ($method === '') {
            throw new TransportException('HTTP-метод не может быть пустым');
        }
        $ch = curl_init($url);
        if ($ch === false) {
            throw new TransportException('Не удалось инициализировать cURL');
        }

        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT_MS => 5000,
            CURLOPT_TIMEOUT_MS => max(1, (int) round($timeout * 1000)),
            CURLOPT_ENCODING => '',
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $options);

        /** @var string|false $raw */
        $raw = curl_exec($ch);
        if ($raw === false) {
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            curl_close($ch);

            throw new TransportException(
                sprintf('GoblinQ недоступен (cURL %d): %s', $errno, $error),
                $errno,
            );
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return new Response($status, $raw);
    }
}
