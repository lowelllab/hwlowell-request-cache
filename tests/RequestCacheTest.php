<?php

namespace HwlowellRequestCache\Tests;

use HwlowellRequestCache\RequestCache;
use HwlowellRequestCache\LocalCache;
use Illuminate\Support\Facades\Redis;
use Mockery;
use PHPUnit\Framework\TestCase;

class RequestCacheTest extends TestCase
{
    /**
     * @var RequestCache
     */
    protected $requestCache;

    /**
     * @var Mockery\MockInterface
     */
    protected $redisMock;

    /**
     * @var Mockery\MockInterface
     */
    protected $redisConnectionMock;

    /**
     * @var Mockery\MockInterface
     */
    protected $redisPoolMock;

    /**
     * @var Mockery\MockInterface
     */
    protected $localCacheMock;

    protected function setUp(): void
    {
        parent::setUp();

        //Enable Redis connection pool
        \HwlowellRequestCache\CacheConfig::setRedisPoolConfig(['enabled' => true]);

        //Mock Redis facade
        $this->redisMock = Mockery::mock('alias:Illuminate\\Support\\Facades\\Redis');
        $this->redisConnectionMock = Mockery::mock();
        $this->redisMock->shouldReceive('connection')->andReturn($this->redisConnectionMock);

        //Mock RedisConnectionPool with magic method support
        $this->redisPoolMock = Mockery::mock();
        $this->redisPoolMock->shouldReceive('getConnection')->andReturn($this->redisConnectionMock);
        $this->redisPoolMock->shouldReceive('releaseConnection')->andReturnNull();
        $this->redisPoolMock->shouldReceive('closeAll')->andReturnNull();
        $this->redisPoolMock->shouldReceive('getStatus')->andReturn([]);
        
        //Setup default behavior for Redis connection
        $this->redisConnectionMock->shouldReceive('ping')->andReturn(true);
        
        //Create RequestCache instance
        $this->requestCache = new RequestCache();

        //Override the localCache property using reflection
        $this->localCacheMock = Mockery::mock(LocalCache::class);
        $reflection = new \ReflectionClass($this->requestCache);
        $property = $reflection->getProperty('localCache');
        $property->setAccessible(true);
        $property->setValue($this->requestCache, $this->localCacheMock);

        //Override the redisPool property using reflection
        $redisPoolProperty = $reflection->getProperty('redisPool');
        $redisPoolProperty->setAccessible(true);
        $redisPoolProperty->setValue($this->requestCache, $this->redisPoolMock);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testConstructorInitializesCorrectly()
    {
        $this->assertInstanceOf(RequestCache::class, $this->requestCache);
    }

    public function testSetForceValidate()
    {
        $result = $this->requestCache->setForceValidate(false);
        $this->assertInstanceOf(RequestCache::class, $result);
    }

    public function testTags()
    {
        //Test with single tag
        $result1 = $this->requestCache->tags('test');
        $this->assertInstanceOf(RequestCache::class, $result1);

        //Test with multiple tags
        $result2 = $this->requestCache->tags('tag1', 'tag2', 'tag3');
        $this->assertInstanceOf(RequestCache::class, $result2);

        //Test with array of tags
        $result3 = $this->requestCache->tags(['tag4', 'tag5']);
        $this->assertInstanceOf(RequestCache::class, $result3);
    }

    public function testEnableStats()
    {
        $result = $this->requestCache->enableStats(false);
        $this->assertInstanceOf(RequestCache::class, $result);
    }

    public function testEncryptData()
    {
        $result = $this->requestCache->encryptData(true);
        $this->assertInstanceOf(RequestCache::class, $result);
    }

    public function testVersion()
    {
        $result = $this->requestCache->version('2.0');
        $this->assertInstanceOf(RequestCache::class, $result);
    }

    public function testSizeLimit()
    {
        $result = $this->requestCache->sizeLimit(2048576);
        $this->assertInstanceOf(RequestCache::class, $result);
    }

    public function testGenerateKey()
    {
        $gateway = 'test_gateway';
        $params = ['id' => 1, 'name' => 'test'];
        $key = $this->requestCache->generateKey($gateway, $params);
        $this->assertIsString($key);
        $this->assertNotEmpty($key);
    }

    public function testGetFromLocalCache()
    {
        $gateway = 'test_gateway';
        $params = ['id' => 1];
        $expectedData = ['key' => 'value'];

        $key = $this->requestCache->generateKey($gateway, $params);
        $this->localCacheMock->shouldReceive('get')->with($key)->andReturn($expectedData);

        $result = $this->requestCache->get($gateway, $params);
        $this->assertEquals($expectedData, $result);
    }

    public function testGetFromRedis()
    {
        $gateway = 'test_gateway';
        $params = ['id' => 1];
        $expectedData = ['key' => 'value'];
        $jsonData = json_encode($expectedData);

        $key = $this->requestCache->generateKey($gateway, $params);
        $this->localCacheMock->shouldReceive('get')->with($key)->andReturn(null);
        $this->redisPoolMock->shouldReceive('get')->with($key)->andReturn($jsonData);
        $this->localCacheMock->shouldReceive('set')->with($key, $expectedData)->andReturn(true);

        $result = $this->requestCache->get($gateway, $params);
        $this->assertEquals($expectedData, $result);
    }

    public function testGetReturnsNullWhenNoCache()
    {
        $gateway = 'test_gateway';
        $params = ['id' => 1];

        $key = $this->requestCache->generateKey($gateway, $params);
        $this->localCacheMock->shouldReceive('get')->with($key)->andReturn(null);

        $redisConnectionMock = Mockery::mock();
        $redisConnectionMock->shouldReceive('get')->with($key)->andReturn(null);
        $this->redisMock->shouldReceive('connection')->andReturn($redisConnectionMock);

        $result = $this->requestCache->get($gateway, $params);
        $this->assertNull($result);
    }

    public function testSet()
    {
        $gateway = 'test_gateway';
        $params = ['id' => 1];
        $data = ['key' => 'value'];
        $expire = 300;

        $key = $this->requestCache->generateKey($gateway, $params);
        $jsonData = json_encode($data);

        $redisConnectionMock = Mockery::mock();
        $redisConnectionMock->shouldReceive('setex')->with($key, $expire, $jsonData)->andReturn(true);
        $this->redisMock->shouldReceive('connection')->andReturn($redisConnectionMock);

        $this->localCacheMock->shouldReceive('set')->with($key, $data, $expire)->andReturn(true);

        $result = $this->requestCache->set($gateway, $params, $data, $expire);
        $this->assertTrue($result);
    }

    public function testSetWithTags()
    {
        $gateway = 'test_gateway';
        $params = ['id' => 1];
        $data = ['key' => 'value'];
        $expire = 300;

        $this->requestCache->tags('test_tag');

        $key = $this->requestCache->generateKey($gateway, $params);
        $jsonData = json_encode($data);
        $tagKey = 'laravel_local_cache:tags:test_tag';

        $redisConnectionMock = Mockery::mock();
        $redisConnectionMock->shouldReceive('setex')->with($key, $expire, $jsonData)->andReturn(true);
        $redisConnectionMock->shouldReceive('sadd')->with($tagKey, $key)->andReturn(true);
        $redisConnectionMock->shouldReceive('expire')->with($tagKey, $expire + 3600)->andReturn(true);
        $this->redisMock->shouldReceive('connection')->andReturn($redisConnectionMock);

        $this->localCacheMock->shouldReceive('set')->with($key, $data, $expire)->andReturn(true);

        $result = $this->requestCache->set($gateway, $params, $data, $expire);
        $this->assertTrue($result);
    }

    public function testDelete()
    {
        $gateway = 'test_gateway';
        $params = ['id' => 1];

        $key = $this->requestCache->generateKey($gateway, $params);
        $this->redisPoolMock->shouldReceive('del')->with($key)->andReturn(1);

        $result = $this->requestCache->delete($gateway, $params);
        $this->assertTrue($result);
    }

    public function testClearGateway()
    {
        $gateway = 'test_gateway';
        $pattern = 'laravel_local_cache:1.0:' . $gateway . ':*';
        $keys = ['key1', 'key2'];

        $this->redisPoolMock->shouldReceive('command')->with('SCAN', ['0', 'MATCH', $pattern, 'COUNT', 1000])->andReturn(['0', $keys]);
        $this->redisPoolMock->shouldReceive('del')->with($keys)->andReturn(2);

        $result = $this->requestCache->clearGateway($gateway);
        $this->assertTrue($result);
    }

    public function testClearTags()
    {
        $tags = ['tag1', 'tag2'];
        $tagKey1 = 'laravel_local_cache:tags:tag1';
        $tagKey2 = 'laravel_local_cache:tags:tag2';
        $keys = ['key1', 'key2', 'key3'];

        $this->redisPoolMock->shouldReceive('smembers')->with($tagKey1)->andReturn(['key1', 'key2']);
        $this->redisPoolMock->shouldReceive('smembers')->with($tagKey2)->andReturn(['key3']);
        $this->redisPoolMock->shouldReceive('del')->with($tagKey1)->andReturn(1);
        $this->redisPoolMock->shouldReceive('del')->with($tagKey2)->andReturn(1);
        $this->redisPoolMock->shouldReceive('del')->with($keys)->andReturn(3);

        $result = $this->requestCache->clearTags($tags);
        $this->assertTrue($result);
    }

    public function testClearAll()
    {
        $pattern = 'laravel_local_cache:*';
        $keys = ['key1', 'key2', 'key3'];

        $this->redisPoolMock->shouldReceive('command')->with('SCAN', ['0', 'MATCH', $pattern, 'COUNT', 1000])->andReturn(['0', $keys]);
        $this->redisPoolMock->shouldReceive('del')->with($keys)->andReturn(3);

        $result = $this->requestCache->clearAll();
        $this->assertTrue($result);
    }

    public function testRememberWithCacheHit()
    {
        $gateway = 'test_gateway';
        $params = ['id' => 1];
        $expectedData = ['key' => 'value'];

        $key = $this->requestCache->generateKey($gateway, $params);
        $this->localCacheMock->shouldReceive('get')->with($key)->andReturn($expectedData);

        $callback = function () {
            return ['key' => 'value'];
        };

        $result = $this->requestCache->remember($gateway, $params, $callback);
        $this->assertEquals($expectedData, $result);
    }

    public function testRememberWithCacheMiss()
    {
        $gateway = 'test_gateway';
        $params = ['id' => 1];
        $expectedData = ['key' => 'value'];

        $key = $this->requestCache->generateKey($gateway, $params);
        $this->localCacheMock->shouldReceive('get')->with($key)->andReturn(null);

        $redisConnectionMock = Mockery::mock();
        $redisConnectionMock->shouldReceive('get')->with($key)->andReturn(null);
        $redisConnectionMock->shouldReceive('set')->with('laravel_local_cache:lock:' . $key, Mockery::any(), 'EX', 5, 'NX')->andReturn(true);
        $redisConnectionMock->shouldReceive('eval')->with(Mockery::any(), 1, 'laravel_local_cache:lock:' . $key, Mockery::any())->andReturn(1);
        $redisConnectionMock->shouldReceive('setex')->with($key, 300, json_encode($expectedData))->andReturn(true);
        $this->redisMock->shouldReceive('connection')->andReturn($redisConnectionMock);

        $this->localCacheMock->shouldReceive('set')->with($key, $expectedData, 300)->andReturn(true);

        $callback = function () use ($expectedData) {
            return $expectedData;
        };

        $result = $this->requestCache->remember($gateway, $params, $callback);
        $this->assertEquals($expectedData, $result);
    }

    public function testGetStats()
    {
        $redisConnectionMock = Mockery::mock();
        $redisConnectionMock->shouldReceive('get')->with('laravel_local_cache:stats:hits')->andReturn(10);
        $redisConnectionMock->shouldReceive('get')->with('laravel_local_cache:stats:misses')->andReturn(5);
        $redisConnectionMock->shouldReceive('get')->with('laravel_local_cache:stats:hits:' . date('Y-m-d'))->andReturn(2);
        $redisConnectionMock->shouldReceive('get')->with('laravel_local_cache:stats:misses:' . date('Y-m-d'))->andReturn(1);
        $this->redisMock->shouldReceive('connection')->andReturn($redisConnectionMock);

        $stats = $this->requestCache->getStats();
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('hits', $stats);
        $this->assertArrayHasKey('misses', $stats);
        $this->assertArrayHasKey('today_hits', $stats);
        $this->assertArrayHasKey('today_misses', $stats);
        $this->assertArrayHasKey('hit_rate', $stats);
    }

    public function testWarm()
    {
        $gateway = 'test_gateway';
        $params = ['id' => 1];
        $expectedData = ['key' => 'value'];
        $expire = 300;

        $key = $this->requestCache->generateKey($gateway, $params);
        $jsonData = json_encode($expectedData);

        $redisConnectionMock = Mockery::mock();
        $redisConnectionMock->shouldReceive('setex')->with($key, $expire, $jsonData)->andReturn(true);
        $this->redisMock->shouldReceive('connection')->andReturn($redisConnectionMock);

        $this->localCacheMock->shouldReceive('set')->with($key, $expectedData, $expire)->andReturn(true);

        $callback = function () use ($expectedData) {
            return $expectedData;
        };

        $result = $this->requestCache->warm($gateway, $params, $callback, $expire);
        $this->assertEquals($expectedData, $result);
    }

    public function testSetWithLargeData()
    {
        $gateway = 'test_gateway';
        $params = ['id' => 1];
        //Create large data that exceeds size limit
        $largeData = str_repeat('a', 2097152); // 2MB

        $this->requestCache->sizeLimit(1048576); // 1MB

        $result = $this->requestCache->set($gateway, $params, $largeData);
        $this->assertFalse($result);
    }

    public function testSetWithRedisException()
    {
        $gateway = 'test_gateway';
        $params = ['id' => 1];
        $data = ['key' => 'value'];

        $redisConnectionMock = Mockery::mock();
        $redisConnectionMock->shouldReceive('setex')->andThrow(new \Exception('Redis error'));
        $this->redisMock->shouldReceive('connection')->andReturn($redisConnectionMock);

        $key = $this->requestCache->generateKey($gateway, $params);
        $this->localCacheMock->shouldReceive('set')->with($key, $data, 300)->andReturn(true);

        $result = $this->requestCache->set($gateway, $params, $data);
        $this->assertTrue($result);
    }
}
