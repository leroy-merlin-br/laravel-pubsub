<?php

namespace Tests;

use Google\Cloud\PubSub\PubSubClient as GoogleCloudPubSubClient;
use GuzzleHttp\Client;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use LeroyMerlin\LaravelPubSub\Adapters\KafkaAdapter;
use LeroyMerlin\LaravelPubSub\Contracts\AdapterInterface;
use Mockery;
use Predis\Client as RedisClient;
use Psr\Cache\CacheItemPoolInterface;
use LeroyMerlin\LaravelPubSub\PubSubConnectionFactory;
use LeroyMerlin\LaravelPubSub\Adapters\DevNullAdapter;
use LeroyMerlin\LaravelPubSub\Adapters\LocalAdapter;
use LeroyMerlin\LaravelPubSub\Adapters\GoogleCloudAdapter;
use LeroyMerlin\LaravelPubSub\Adapters\HTTPAdapter;
use LeroyMerlin\LaravelPubSub\Adapters\RedisAdapter;

class PubSubConnectionFactoryTest extends TestCase
{
    public function testMakeDevNullAdapter()
    {
        $container = Mockery::mock(Container::class);

        $factory = new PubSubConnectionFactory($container);

        $adapter = $factory->make('/dev/null');
        $this->assertInstanceOf(DevNullAdapter::class, $adapter);
    }

    public function testMakeLocalAdapter()
    {
        $container = Mockery::mock(Container::class);

        $factory = new PubSubConnectionFactory($container);

        $adapter = $factory->make('local');
        $this->assertInstanceOf(LocalAdapter::class, $adapter);
    }

    public function testMakeRedisAdapter()
    {
        $config = [
            'scheme' => 'tcp',
            'host' => 'localhost',
            'password' => null,
            'port' => 6379,
            'database' => 0,
            'read_write_timeout' => 0,
        ];

        $container = Mockery::mock(Container::class);
        $container->shouldReceive('makeWith')
            ->withArgs([
                'pubsub.redis.redis_client',
                ['config' => $config],
            ])
            ->once()
            ->andReturn(Mockery::mock(RedisClient::class));

        $factory = new PubSubConnectionFactory($container);

        $adapter = $factory->make('redis', $config);
        $this->assertInstanceOf(RedisAdapter::class, $adapter);
    }

    public function testMakeKafkaAdapter()
    {
        $config = [
            'consumer_group_id' => 'php-pubsub',
            'brokers' => 'localhost',
        ];

        $container = Mockery::mock(Container::class);

        $producer = Mockery::mock(\RdKafka\Producer::class);
        $producer->shouldReceive('addBrokers')
            ->with('localhost')
            ->once();

        $conf = Mockery::mock(\RdKafka\Conf::class);
        $container->shouldReceive('makeWith')
            ->with('pubsub.kafka.producer', ['conf' => $conf])
            ->once()
            ->andReturn($producer);

        $topicConf = Mockery::mock(\RdKafka\TopicConf::class);
        $topicConf->shouldReceive('set');

        $container->shouldReceive('makeWith')
            ->with('pubsub.kafka.topic_conf')
            ->once()
            ->andReturn($topicConf);

        $conf->shouldReceive('set')
            ->withArgs([
                'metadata.broker.list',
                'localhost',
            ])
            ->once();
        $conf->shouldReceive('set')
            ->withArgs([
                'group.id',
                'php-pubsub',
            ])
            ->once();
        $conf->shouldReceive('set')
            ->withArgs([
                'enable.auto.commit',
                'false',
            ])
            ->once();
        $conf->shouldReceive('set')
            ->withArgs([
                'offset.store.method',
                'broker',
            ])
            ->once();
        $conf->shouldReceive('setDefaultTopicConf')
            ->with($topicConf)
            ->once();

        $container->shouldReceive('makeWith')
            ->with('pubsub.kafka.conf')
            ->once()
            ->andReturn($conf);

        $consumer = Mockery::mock(\RdKafka\KafkaConsumer::class);

        $container->shouldReceive('makeWith')
            ->withArgs([
                'pubsub.kafka.consumer',
                ['conf' => $conf],
            ])
            ->once()
            ->andReturn($consumer);

        $factory = new PubSubConnectionFactory($container);

        $adapter = $factory->make('kafka', $config);
        $this->assertInstanceOf(KafkaAdapter::class, $adapter);
    }

    public function testMakeGoogleCloudAdapter()
    {
        $container = Mockery::mock(Container::class);
        $container->shouldReceive('makeWith')
            ->withArgs([
                'pubsub.gcloud.pub_sub_client',
                [
                    'config' => [
                        'projectId' => 12345,
                        'keyFilePath' => 'my_key_file.json',
                    ],
                ],
            ])
            ->andReturn(Mockery::mock(GoogleCloudPubSubClient::class));

        $factory = new PubSubConnectionFactory($container);

        $config = [
            'project_id' => '12345',
            'key_file' => 'my_key_file.json',
            'client_identifier' => 'blah',
            'auto_create_topics' => false,
            'background_batching' => true,
            'background_daemon' => false,
        ];
        $adapter = $factory->make('gcloud', $config);
        $this->assertInstanceOf(GoogleCloudAdapter::class, $adapter);

        $adapter = $factory->make('gcloud', $config); /* @var GoogleCloudAdapter $adapter */
        $this->assertInstanceOf(GoogleCloudAdapter::class, $adapter);
        $this->assertEquals('blah', $adapter->getClientIdentifier());
        $this->assertFalse($adapter->areTopicsAutoCreated());
        $this->assertTrue($adapter->areSubscriptionsAutoCreated());
        $this->assertTrue($adapter->isBackgroundBatchingEnabled());
        $this->assertFalse(getenv('IS_BATCH_DAEMON_RUNNING'));
    }

    public function testMakeGoogleCloudAdapterWithBackgroundBatchingAndDaemonEnabled()
    {
        $container = Mockery::mock(Container::class);
        $container->shouldReceive('makeWith')
            ->withArgs([
                'pubsub.gcloud.pub_sub_client',
                [
                    'config' => [
                        'projectId' => 12345,
                        'keyFilePath' => 'my_key_file.json',
                    ],
                ],
            ])
            ->andReturn(Mockery::mock(GoogleCloudPubSubClient::class));

        $factory = new PubSubConnectionFactory($container);

        $config = [
            'project_id' => '12345',
            'key_file' => 'my_key_file.json',
            'client_identifier' => 'blah',
            'auto_create_topics' => false,
            'background_batching' => true,
            'background_daemon' => true,
        ];
        $adapter = $factory->make('gcloud', $config);
        $this->assertInstanceOf(GoogleCloudAdapter::class, $adapter);

        $adapter = $factory->make('gcloud', $config); /* @var GoogleCloudAdapter $adapter */
        $this->assertInstanceOf(GoogleCloudAdapter::class, $adapter);
        $this->assertEquals('blah', $adapter->getClientIdentifier());
        $this->assertFalse($adapter->areTopicsAutoCreated());
        $this->assertTrue($adapter->areSubscriptionsAutoCreated());
        $this->assertTrue($adapter->isBackgroundBatchingEnabled());
        $this->assertEquals('true', getenv('IS_BATCH_DAEMON_RUNNING'));
    }

    public function testMakeGoogleCloudAdapterWithAuthCache()
    {
        $cacheImplementation = Mockery::mock(CacheItemPoolInterface::class);

        $container = Mockery::mock(Container::class);
        $container->shouldReceive('make')
            ->with('MyPSR6CacheImplementation::class')
            ->andReturn($cacheImplementation);
        $container->shouldReceive('makeWith')
            ->withArgs([
                'pubsub.gcloud.pub_sub_client',
                [
                    'config' => [
                        'projectId' => 12345,
                        'keyFilePath' => 'my_key_file.json',
                        'authCache' => $cacheImplementation,
                    ],
                ],
            ])
            ->andReturn(Mockery::mock(GoogleCloudPubSubClient::class));

        $factory = new PubSubConnectionFactory($container);

        $config = [
            'project_id' => '12345',
            'key_file' => 'my_key_file.json',
            'auth_cache' => 'MyPSR6CacheImplementation::class',
        ];

        $adapter = $factory->make('gcloud', $config);
        $this->assertInstanceOf(GoogleCloudAdapter::class, $adapter);
    }

    public function testMakeHTTPAdapter()
    {
        $container = Mockery::mock(Container::class);
        $container->shouldReceive('make')
            ->with('pubsub.http.client')
            ->andReturn(Mockery::mock(Client::class));

        $factory = new PubSubConnectionFactory($container);

        $config = [
            'uri' => 'http://127.0.0.1',
            'subscribe_connection_config' => [
                'driver' => '/dev/null',
            ],
        ];
        $adapter = $factory->make('http', $config); /* @var HTTPAdapter $adapter */
        $this->assertInstanceOf(HTTPAdapter::class, $adapter);
        $this->assertEquals('http://127.0.0.1', $adapter->getUri());
        $this->assertInstanceOf(AdapterInterface::class, $adapter->getAdapter());
    }

    public function testMakeInvalidAdapterThrowsInvalidArgumentException()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The driver [rubbish] is not supported.');

        $container = Mockery::mock(Container::class);

        $factory = new PubSubConnectionFactory($container);

        $factory->make('rubbish');
    }
}
