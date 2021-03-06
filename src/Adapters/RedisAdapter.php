<?php

namespace LeroyMerlin\LaravelPubSub\Adapters;

use Predis\Client;
use LeroyMerlin\LaravelPubSub\Contracts\AdapterInterface;
use LeroyMerlin\LaravelPubSub\Utils\Serialization;

/**
 * Redis adapter
 * @source https://github.com/Superbalist/php-pubsub-redis Superbalist PHP Redis PubSub Adapter
 */
class RedisAdapter implements AdapterInterface
{
    /**
     * @var Client
     */
    protected $client;

    /**
     * @param Client $client
     */
    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * Return the Redis client.
     *
     * @return Client
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     * Subscribe a handler to a channel.
     *
     * @param string $channel
     * @param callable $handler
     */
    public function subscribe($channel, callable $handler)
    {
        $loop = $this->client->pubSubLoop();

        $loop->subscribe($channel);

        foreach ($loop as $message) {
            /** @var \stdClass $message */
            if ($message->kind === 'message') {
                call_user_func($handler, Serialization::unserializeMessagePayload($message->payload));
            }
        }

        unset($loop);
    }

    /**
     * Publish a message to a channel.
     *
     * @param string $channel
     * @param mixed $message
     */
    public function publish($channel, $message)
    {
        $this->client->publish($channel, Serialization::serializeMessage($message));
    }

    /**
     * Publish multiple messages to a channel.
     *
     * @param string $channel
     * @param array $messages
     */
    public function publishBatch($channel, array $messages)
    {
        foreach ($messages as $message) {
            $this->publish($channel, $message);
        }
    }
}
