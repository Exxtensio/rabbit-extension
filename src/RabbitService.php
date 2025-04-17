<?php

namespace Exxtensio\RabbitExtension;

use Exception;
use Throwable;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Ramsey\Uuid\Uuid;

class RabbitService
{
    protected ?AMQPStreamConnection $connection = null;
    protected ?AMQPChannel $channel = null;
    protected $callbackQueue;
    protected $response;
    protected string $correlationId;

    public function connect(): void
    {
        if ($this->connection && $this->channel) return;
        $this->connection = new AMQPStreamConnection(
            config('rabbit-extension.host'),
            config('rabbit-extension.port'),
            config('rabbit-extension.user'),
            config('rabbit-extension.password')
        );
        $this->channel = $this->connection->channel();
    }

    public function declareQueue(string $queue): void
    {
        $this->connect();
        $this->channel->queue_declare($queue, false, true, false, false);
    }

    public function publish(string $queue, array $payload): void
    {
        $this->declareQueue($queue);
        $msg = new AMQPMessage(json_encode($payload), ['delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT]);
        $this->channel->basic_publish($msg, '', $queue);
    }

    public function call(string $queue, array $payload): ?string
    {
        $this->declareQueue($queue);
        list($this->callbackQueue, ,) = $this->channel->queue_declare('', false, false, true);

        $this->response = null;
        $this->correlationId = Uuid::uuid4()->toString();

        $this->channel->basic_consume(
            $this->callbackQueue,
            '',
            false,
            true,
            false,
            false,
            function ($msg) {
                if ($msg->get('correlation_id') === $this->correlationId)
                    $this->response = $msg->body;
            }
        );

        $msg = new AMQPMessage($payload, [
            'correlation_id' => $this->correlationId,
            'reply_to' => $this->callbackQueue
        ]);

        $this->channel->basic_publish($msg, '', $queue);

        $start = time();
        while (!$this->response) {
            $this->channel->wait(null, false, 5);
            if (time() - $start > 10) break;
        }

        return $this->response;
    }

    public function consume(string $queue, callable $handler): void
    {
        $this->declareQueue($queue);
        $this->channel->basic_qos(0, 1, false);

        $this->channel->basic_consume($queue, '', false, false, false, false, function (AMQPMessage $msg) use ($handler) {
            try {
                $payload = json_decode($msg->getBody(), true);
                $result = call_user_func($handler, $payload);

                $replyTo = $msg->get('reply_to');
                $correlationId = $msg->get('correlation_id');

                if ($replyTo && $correlationId) {
                    $response = new AMQPMessage(
                        is_string($result) ? $result : json_encode($result),
                        ['correlation_id' => $correlationId]
                    );

                    $this->channel->basic_publish($response, '', $replyTo);
                }

                $this->channel->basic_ack($msg->getDeliveryTag());

            } catch (Throwable $e) {
                $this->channel->basic_nack($msg->getDeliveryTag());
            }
        });

        while ($this->channel->is_consuming()) {
            $this->channel->wait();
        }
    }

    /**
     * @throws Exception
     */
    public function close(): void
    {
        $this->channel?->close();
        $this->connection?->close();
    }

    /**
     * @throws Exception
     */
    public function __destruct()
    {
        $this->close();
    }
}
