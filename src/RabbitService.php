<?php

namespace Exxtensio\RabbitExtension;

use Illuminate\Support\Collection;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Ramsey\Uuid\Uuid;
use Random\RandomException;

class RabbitService
{
    private const array DEFAULT = [
        'event' => ['type' => null, 'data' => null],
        'session' => ['id' => null, 'uniqueId' => null],
        'location' => ['hash' => null, 'host' => null, 'hostname' => null, 'href' => null, 'origin' => null, 'pathname' => null, 'port' => null, 'protocol' => null, 'search' => null, 'referrer' => null],
        'network' => ['ipAddress' => null],
        'geo' => ['latitude' => null, 'longitude' => null, 'accuracy' => null],
        'os' => ['name' => null, 'version' => null],
        'device' => ['type' => null],
        'screen' => [
            'width' => null, 'height' => null,
            'availableWidth' => null, 'availableHeight' => null,
            'innerWidth' => null, 'innerHeight' => null,
            'outerWidth' => null, 'outerHeight' => null,
            'colorDepth' => null,
            'orientationAngle' => null, 'orientationType' => null,
            'pixelDepth' => null, 'dpi' => null, 'isTouch' => null,
        ],
        'browser' => ['userAgent' => null, 'name' => null, 'version' => null, 'cookieEnabled' => null, 'language' => null, 'languages' => []],
        'connection' => ['downlink' => null, 'effectiveType' => null, 'rtt' => null, 'saveData' => null],
        'datetime' => ['locale' => null, 'calendar' => null, 'day' => null, 'month' => null, 'year' => null, 'numberingSystem' => null, 'timezone' => null],
        'payload' => [],
        'options' => ['parsePhone' => null],
    ];

    protected function connect(): array
    {
        $connection = new AMQPStreamConnection(
            config('rabbit-extension.host'),
            config('rabbit-extension.port'),
            config('rabbit-extension.user'),
            config('rabbit-extension.password'),
            config('rabbit-extension.vhost'),
            false,
            'AMQPLAIN',
            null,
            'en_US',
            5.0,
            5.0,
            stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                ]
            ]),
            true,
            3,
            5.0
        );
        $channel = $connection->channel();
        return [$connection, $channel];
    }

    protected function publish(string $queue, array $payload): void
    {
        [$connection, $channel] = $this->connect();
        $channel->queue_declare($queue, false, true, false, false);

        $msg = new AMQPMessage(json_encode($payload), ['delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT]);
        $channel->basic_publish($msg, '', $queue);
    }

    protected function call(string $queue, array $payload): ?string
    {
        [$connection, $channel] = $this->connect();
        list($callbackQueue, ,) = $channel->queue_declare('', false, false, true);

        $response = null;
        $correlationId = Uuid::uuid4()->toString();

        $channel->basic_consume($callbackQueue, '', false, true, false, false, function ($msg) use (&$response, $correlationId) {
            if ($msg->has('correlation_id') && $msg->get('correlation_id') === $correlationId)
                $response = $msg->body;
        });

        $msg = new AMQPMessage(json_encode($payload), [
            'correlation_id' => $correlationId,
            'reply_to' => $callbackQueue
        ]);

        $channel->basic_publish($msg, '', $queue);

        $start = microtime(true);
        while (!$response && microtime(true) - $start < 3) {
            $channel->wait(null, false, 1);
        }

        $channel->close();
        $connection->close();

        return $response;
    }

    /**
     * @throws RandomException
     */
    protected function setCollection(Collection $collection, $ip): Collection
    {
        $hex = bin2hex(random_bytes(16));
        $merged = array_replace_recursive(self::DEFAULT, $collection->toArray());
        if (empty($merged['network']['ipAddress'])) $merged['network']['ipAddress'] = $ip;
        if (empty($merged['session']['id'])) $merged['session']['id'] = $hex;
        if (empty($merged['session']['uniqueId'])) $merged['session']['uniqueId'] = $hex;

        return collect($merged);
    }

    protected function decrypt($data): false|string
    {
        list($iv, $encryptedData) = explode(':', $data);
        $iv = base64_decode($iv);
        $ciphertext = base64_decode($encryptedData);
        $key = substr('PPNrnrOVqSYtfaeVMTwkR2RmjfjUoOEn', 0, 32);
        return openssl_decrypt($ciphertext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    }
}
