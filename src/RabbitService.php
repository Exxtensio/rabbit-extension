<?php

namespace Exxtensio\RabbitExtension;

use Exception;

class RabbitService
{
    protected ?\PhpAmqpLib\Connection\AMQPStreamConnection $connection = null;
    protected ?\PhpAmqpLib\Channel\AMQPChannel $channel = null;

    /**
     * @throws Exception
     */
    public function __construct()
    {
        $this->ensureConnection();
    }

    protected function ensureConnection(): bool
    {
        if ($this->connection && $this->connection->isConnected() && $this->channel)
            return true;

        try {
            $this->connection = new \PhpAmqpLib\Connection\AMQPStreamConnection(
                config('rabbit-extension.host'),
                config('rabbit-extension.port'),
                config('rabbit-extension.user'),
                config('rabbit-extension.password')
            );

            $this->channel = $this->connection->channel();
            return true;
        } catch (Exception $e) {
            $this->connection = null;
            $this->channel = null;
            return false;
        }
    }

    public function publish(string $queue, string $message): void
    {
        if (!$this->ensureConnection()) return;

        $this->channel->queue_declare($queue, false, true, false, false);
        $msg = new \PhpAmqpLib\Message\AMQPMessage($message);
        $this->channel->basic_publish($msg, '', $queue);
    }

    public function publishResponse(string $queue, array $array): void
    {
        if (!$this->ensureConnection()) return;

        $correlationId = uniqid();
        $callbackQueue = $this->channel->queue_declare('', false, false, true, false)[0];

        $msg = new \PhpAmqpLib\Message\AMQPMessage(json_encode([
            'correlation_id' => $correlationId,
            'reply_to' => $callbackQueue,
            ...$array
        ]));
        $this->channel->basic_publish($msg, '', $queue);

        $response = null;

        $this->channel->basic_consume($callbackQueue, '', false, true, false, false, function ($reply) use ($response, $correlationId) {
            if($reply->get('correlation_id') === $correlationId) {
                $response = $reply->body;
            }
        });

        while (!$response) {
            $this->channel->wait();
        }

        dd($response);
    }

    public function consume(string $queue, callable $callback): void
    {
        if (!$this->ensureConnection()) return;

        $this->channel->queue_declare($queue, false, true, false, false);
        $this->channel->basic_consume($queue, '', false, true, false, false, $callback);

        try {
            while ($this->channel && count($this->channel->callbacks)) {
                $this->channel->wait();
            }
        } catch (Exception $e) {}
    }

    /**
     * @throws Exception
     */
    public function __destruct()
    {
        try {
            $this->channel?->close();
            $this->connection?->close();
        } catch (\Throwable $e) {}
    }
}
