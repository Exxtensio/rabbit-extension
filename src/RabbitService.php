<?php

namespace Exxtensio\RabbitExtension;

use Exception;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Throwable;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Ramsey\Uuid\Uuid;

class RabbitService
{
    protected ?AMQPStreamConnection $connection = null;
    protected ?AMQPChannel $channel = null;
    protected LoggerInterface $log;
    protected $callbackQueue;
    protected $response;
    protected string $correlationId;

    public function connect(): void
    {
        if ($this->connection && $this->channel) return;

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ]
        ]);

        $this->connection = new AMQPStreamConnection(
            config('rabbit-extension.host'),
            config('rabbit-extension.port'),
            config('rabbit-extension.user'),
            config('rabbit-extension.password'),
            config('rabbit-extension.vhost'),
            false,
            'AMQPLAIN',
            null,
            'en_US',
            1.0,
            1.0,
            $context,
        );
        $this->channel = $this->connection->channel();
        $this->log = Log::channel('rabbit');
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

        $this->channel->basic_consume($this->callbackQueue, '', false, true, false, false, function ($msg) {
            if ($msg->has('correlation_id') && $msg->get('correlation_id') === $this->correlationId) $this->response = $msg->body;
        });

        $msg = new AMQPMessage(json_encode($payload), [
            'correlation_id' => $this->correlationId,
            'reply_to' => $this->callbackQueue
        ]);

        $this->channel->basic_publish($msg, '', $queue);

        $start = time();
        while (!$this->response) {
            $this->channel->wait(null, false, 1);
            if (time() - $start > 2) break;
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

                if ($msg->has('reply_to') && $msg->has('correlation_id')) {
                    $response = new AMQPMessage(
                        is_string($result) ? $result : json_encode($result),
                        ['correlation_id' => $msg->get('correlation_id')]
                    );

                    $this->channel->basic_publish($response, '', $msg->get('reply_to'));
                }

                $this->channel->basic_ack($msg->getDeliveryTag());

            } catch (Throwable $e) {
                $this->log->error($e);
                $this->channel->basic_nack($msg->getDeliveryTag(), false, true);
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
