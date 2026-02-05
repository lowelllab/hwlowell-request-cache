<?php

namespace HwlowellRequestCache\Tests;

use HwlowellRequestCache\RequestCache;
use HwlowellRequestCache\LocalCache;
use HwlowellRequestCache\CacheConfig;
use HwlowellRequestCache\RedisConnectionPool;
use Illuminate\Support\Facades\Redis;
use Mockery;
use PHPUnit\Framework\TestCase;

class RequestCacheCompleteTest extends TestCase
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

    /**
     * 测试结果记录
     * @var array
     */
    protected $testResults = [];

    /**
     * Redis操作记录
     * @var array
     */
    protected $redisOperations = [];

    protected function setUp(): void
    {
        parent::setUp();

        //重置测试结果
        $this->testResults = [];
        $this->redisOperations = [];

        //配置缓存策略
        CacheConfig::setStrategy([
            'primary' => 'redis',
            'secondary' => 'array',
            'fallback' => true,
        ]);

        //启用Redis连接池
        CacheConfig::setRedisPoolConfig(['enabled' => true]);

        //Mock Redis facade
        $this->redisMock = Mockery::mock('alias:Illuminate\\Support\\Facades\\Redis');
        $this->redisConnectionMock = Mockery::mock();
        $this->redisMock->shouldReceive('connection')->andReturn($this->redisConnectionMock);

        //Mock RedisConnectionPool with operation logging
        $this->redisPoolMock = Mockery::mock();
        $this->redisPoolMock->shouldReceive('getConnection')->andReturn($this->redisConnectionMock)->andReturnUsing(function() {
            $this->redisOperations[] = 'getConnection';
            return $this->redisConnectionMock;
        });
        $this->redisPoolMock->shouldReceive('releaseConnection')->andReturnNull()->andReturnUsing(function() {
            $this->redisOperations[] = 'releaseConnection';
            return null;
        });
        $this->redisPoolMock->shouldReceive('closeAll')->andReturnNull();
        $this->redisPoolMock->shouldReceive('getStatus')->andReturn([]);
        $this->redisPoolMock->shouldReceive('get')->andReturnNull()->andReturnUsing(function($key) {
            $this->redisOperations[] = "get:{$key}";
            return null;
        });
        $this->redisPoolMock->shouldReceive('setex')->andReturn(true)->andReturnUsing(function($key, $expire, $value) {
            $this->redisOperations[] = "setex:{$key}";
            return true;
        });
        $this->redisPoolMock->shouldReceive('del')->andReturn(1)->andReturnUsing(function($key) {
            $this->redisOperations[] = "del:{$key}";
            return 1;
        });
        $this->redisPoolMock->shouldReceive('sadd')->andReturn(1)->andReturnUsing(function($key, $value) {
            $this->redisOperations[] = "sadd:{$key}";
            return 1;
        });
        $this->redisPoolMock->shouldReceive('expire')->andReturn(1)->andReturnUsing(function($key, $expire) {
            $this->redisOperations[] = "expire:{$key}";
            return 1;
        });
        $this->redisPoolMock->shouldReceive('command')->andReturn(['0', []])->andReturnUsing(function($command, $params) {
            $this->redisOperations[] = "command:{$command}";
            return ['0', []];
        });
        $this->redisPoolMock->shouldReceive('set')->andReturn(true)->andReturnUsing(function($key, $value, $options) {
            $this->redisOperations[] = "set:{$key}";
            return true;
        });
        $this->redisPoolMock->shouldReceive('eval')->andReturn(1)->andReturnUsing(function($script, $count, $key, $value) {
            $this->redisOperations[] = "eval:{$key}";
            return 1;
        });
        $this->redisPoolMock->shouldReceive('incr')->andReturn(1)->andReturnUsing(function($key) {
            $this->redisOperations[] = "incr:{$key}";
            return 1;
        });
        $this->redisPoolMock->shouldReceive('smembers')->andReturn([])->andReturnUsing(function($key) {
            $this->redisOperations[] = "smembers:{$key}";
            return [];
        });
        $this->redisPoolMock->shouldReceive('mget')->andReturn([])->andReturnUsing(function($keys) {
            $this->redisOperations[] = "mget:" . count($keys) . "keys";
            return [];
        });

        //Mock LocalCache
        $this->localCacheMock = Mockery::mock(LocalCache::class);
        $this->localCacheMock->shouldReceive('get')->andReturnNull();
        $this->localCacheMock->shouldReceive('set')->andReturn(true);

        //Create RequestCache instance
        $this->requestCache = new RequestCache();

        //Override properties using reflection
        $reflection = new \ReflectionClass($this->requestCache);
        
        //Override localCache
        $localCacheProperty = $reflection->getProperty('localCache');
        $localCacheProperty->setAccessible(true);
        $localCacheProperty->setValue($this->requestCache, $this->localCacheMock);

        //Override redisPool
        $redisPoolProperty = $reflection->getProperty('redisPool');
        $redisPoolProperty->setAccessible(true);
        $redisPoolProperty->setValue($this->requestCache, $this->redisPoolMock);
    }

    /**
     * 打印Redis操作记录
     */
    protected function printRedisOperations()
    {
        echo "\n=== Redis操作记录 ===\n";
        if (empty($this->redisOperations)) {
            echo "  无Redis操作\n";
        } else {
            foreach ($this->redisOperations as $operation) {
                echo "  - {$operation}\n";
            }
        }
        echo "\n";
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * 记录测试结果
     * @param string $method
     * @param string $cacheType
     * @param bool $success
     * @param string $message
     */
    protected function recordTestResult($method, $cacheType, $success, $message = '')
    {
        $this->testResults[] = [
            'method' => $method,
            'cache_type' => $cacheType,
            'success' => $success,
            'message' => $message,
        ];
    }

    /**
     * 打印测试结果
     */
    protected function printTestResults()
    {
        echo "\n=== 测试结果汇总 ===\n";
        foreach ($this->testResults as $result) {
            $status = $result['success'] ? '✅' : '❌';
            echo sprintf("%s %-30s [%-10s] %s\n", 
                $status, 
                $result['method'], 
                $result['cache_type'], 
                $result['message']
            );
        }
        echo "\n";
    }

    /**
     * 测试构造函数
     */
    public function testConstructor()
    {
        $this->assertInstanceOf(RequestCache::class, $this->requestCache);
        $this->recordTestResult('__construct', 'N/A', true, '构造函数初始化成功');
    }

    /**
     * 测试配置方法
     */
    public function testConfigurationMethods()
    {
        // 测试 setForceValidate
        $result = $this->requestCache->setForceValidate(false);
        $this->assertInstanceOf(RequestCache::class, $result);
        $this->recordTestResult('setForceValidate', 'N/A', true, '设置强制校验字符开关成功');

        // 测试 tags
        $result = $this->requestCache->tags('test');
        $this->assertInstanceOf(RequestCache::class, $result);
        $this->recordTestResult('tags', 'N/A', true, '设置单个标签成功');

        $result = $this->requestCache->tags('tag1', 'tag2', 'tag3');
        $this->assertInstanceOf(RequestCache::class, $result);
        $this->recordTestResult('tags', 'N/A', true, '设置多个标签成功');

        $result = $this->requestCache->tags(['tag4', 'tag5']);
        $this->assertInstanceOf(RequestCache::class, $result);
        $this->recordTestResult('tags', 'N/A', true, '设置标签数组成功');

        // 测试 enableStats
        $result = $this->requestCache->enableStats(true);
        $this->assertInstanceOf(RequestCache::class, $result);
        $this->recordTestResult('enableStats', 'N/A', true, '启用缓存统计成功');

        // 测试 encryptData
        $result = $this->requestCache->encryptData(false);
        $this->assertInstanceOf(RequestCache::class, $result);
        $this->recordTestResult('encryptData', 'N/A', true, '禁用数据加密成功');

        // 测试 version
        $result = $this->requestCache->version('2.0');
        $this->assertInstanceOf(RequestCache::class, $result);
        $this->recordTestResult('version', 'N/A', true, '设置缓存版本成功');

        // 测试 sizeLimit
        $result = $this->requestCache->sizeLimit(2097152);
        $this->assertInstanceOf(RequestCache::class, $result);
        $this->recordTestResult('sizeLimit', 'N/A', true, '设置缓存大小限制成功');
    }

    /**
     * 测试 generateKey 方法
     */
    public function testGenerateKey()
    {
        $gateway = 'test_gateway';
        $params = ['id' => 1, 'name' => 'test'];
        
        $key = $this->requestCache->generateKey($gateway, $params);
        $this->assertIsString($key);
        $this->assertNotEmpty($key);
        $this->recordTestResult('generateKey', 'N/A', true, '生成缓存key成功');
    }

    /**
     * 测试 get 方法
     */
    public function testGet()
    {
        $gateway = 'test_gateway';
        $params = ['id' => 1];
        
        // 测试缓存未命中的情况
        $this->localCacheMock->shouldReceive('get')->andReturn(null);
        $this->redisPoolMock->shouldReceive('get')->andReturn(null);
        
        $result = $this->requestCache->get($gateway, $params);
        $this->assertNull($result);
        $this->recordTestResult('get', 'N/A', true, '缓存未命中测试成功');
    }

    /**
     * 测试 mget 方法
     */
    public function testMget()
    {
        $items = [
            ['test_gateway', ['id' => 1]],
            ['test_gateway', ['id' => 2]]
        ];
        
        $result = $this->requestCache->mget($items);
        $this->assertIsArray($result);
        $this->recordTestResult('mget', 'Redis', true, '批量获取缓存成功');
    }

    /**
     * 测试 set 方法
     */
    public function testSet()
    {
        $gateway = 'test_gateway';
        $params = ['id' => 1];
        $data = ['key' => 'value'];
        $expire = 300;
        
        $result = $this->requestCache->set($gateway, $params, $data, $expire);
        $this->assertTrue($result);
        $this->recordTestResult('set', 'Redis', true, '设置缓存成功');
    }

    /**
     * 测试 mset 方法
     */
    public function testMset()
    {
        $items = [
            ['test_gateway', ['id' => 1], ['key' => 'value1'], 300],
            ['test_gateway', ['id' => 2], ['key' => 'value2'], 300]
        ];
        
        $result = $this->requestCache->mset($items);
        $this->assertIsArray($result);
        $this->recordTestResult('mset', 'Redis', true, '批量设置缓存成功');
    }

    /**
     * 测试 delete 方法
     */
    public function testDelete()
    {
        $gateway = 'test_gateway';
        $params = ['id' => 1];
        
        $result = $this->requestCache->delete($gateway, $params);
        $this->assertTrue($result);
        $this->recordTestResult('delete', 'Redis', true, '删除缓存成功');
    }

    /**
     * 测试 clearGateway 方法
     */
    public function testClearGateway()
    {
        $gateway = 'test_gateway';
        
        $result = $this->requestCache->clearGateway($gateway);
        $this->assertTrue($result);
        $this->recordTestResult('clearGateway', 'Redis', true, '清除指定网关缓存成功');
    }

    /**
     * 测试 clearTags 方法
     */
    public function testClearTags()
    {
        $tags = ['test', 'cache'];
        
        $result = $this->requestCache->clearTags($tags);
        $this->assertTrue($result);
        $this->recordTestResult('clearTags', 'Redis', true, '清除指定标签缓存成功');
    }

    /**
     * 测试 clearAll 方法
     */
    public function testClearAll()
    {
        $result = $this->requestCache->clearAll();
        $this->assertTrue($result);
        $this->recordTestResult('clearAll', 'Redis', true, '清除所有缓存成功');
    }

    /**
     * 测试 remember 方法
     */
    public function testRemember()
    {
        $gateway = 'test_gateway';
        $params = ['id' => 1];
        $expectedData = ['key' => 'value'];
        
        $callback = function () use ($expectedData) {
            return $expectedData;
        };
        
        $result = $this->requestCache->remember($gateway, $params, $callback);
        $this->assertEquals($expectedData, $result);
        $this->recordTestResult('remember', 'Redis', true, '缓存装饰器成功');
    }

    /**
     * 测试 getStats 方法
     */
    public function testGetStats()
    {
        $stats = $this->requestCache->getStats();
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('hits', $stats);
        $this->assertArrayHasKey('misses', $stats);
        $this->assertArrayHasKey('today_hits', $stats);
        $this->assertArrayHasKey('today_misses', $stats);
        $this->assertArrayHasKey('hit_rate', $stats);
        $this->recordTestResult('getStats', 'Redis', true, '获取缓存统计信息成功');
    }

    /**
     * 测试 warm 方法
     */
    public function testWarm()
    {
        $gateway = 'test_gateway';
        $params = ['id' => 1];
        $expectedData = ['key' => 'value'];
        
        $callback = function () use ($expectedData) {
            return $expectedData;
        };
        
        $result = $this->requestCache->warm($gateway, $params, $callback);
        $this->assertEquals($expectedData, $result);
        $this->recordTestResult('warm', 'Redis', true, '预热缓存成功');
    }

    /**
     * 测试所有方法后打印结果
     */
    public function testAllMethods()
    {
        // 运行所有测试方法
        $this->testConstructor();
        $this->testConfigurationMethods();
        $this->testGenerateKey();
        $this->testGet();
        $this->testMget();
        $this->testSet();
        $this->testMset();
        $this->testDelete();
        $this->testClearGateway();
        $this->testClearTags();
        $this->testClearAll();
        $this->testRemember();
        $this->testGetStats();
        $this->testWarm();
        
        // 打印测试结果
        $this->printTestResults();
        
        // 打印Redis操作记录
        $this->printRedisOperations();
        
        // 验证所有测试都通过
        $failedTests = array_filter($this->testResults, function ($result) {
            return !$result['success'];
        });
        
        $this->assertEmpty($failedTests, '所有测试方法都应该通过');
    }
}
