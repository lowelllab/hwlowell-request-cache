<?php

namespace HwlowellRequestCache\Tests;

use HwlowellRequestCache\CacheConfig;
use HwlowellRequestCache\CacheMonitor;
use HwlowellRequestCache\RedisClusterNodeResolver;
use HwlowellRequestCache\RequestCache;
use HwlowellRequestCache\RequestCacheServiceProvider;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Orchestra\Testbench\TestCase;
use ReflectionClass;
use RuntimeException;

class RequestCacheClusterTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    protected function getPackageProviders($app)
    {
        return [
            RequestCacheServiceProvider::class,
        ];
    }

    public function testResolvePrefixAddsConfiguredHashTag()
    {
        $config = $this->clusterConfig([
            'hash_tag' => 'request-cache',
        ]);

        $this->assertSame('{request-cache}:', RequestCache::resolvePrefix($config));
    }

    public function testGeneratedCacheKeyUsesClusterHashTag()
    {
        $cache = new RequestCache($this->clusterConfig([
            'hash_tag' => 'request-cache',
        ]));

        $key = $cache->generateKey('user profile', ['id' => 1]);

        $this->assertStringStartsWith('{request-cache}:2.0:userprofile:', $key);
    }

    public function testResolvePrefixKeepsExistingHashTag()
    {
        $config = $this->clusterConfig([
            'hash_tag' => 'request-cache',
        ]);
        $config['request_cache']['prefix'] = '{custom}:';

        $this->assertSame('{custom}:', RequestCache::resolvePrefix($config));
    }

    public function testTagStatsAndLockKeysUseSameHashTag()
    {
        $cache = new RequestCache($this->clusterConfig([
            'hash_tag' => 'request-cache',
        ]));
        $reflection = new ReflectionClass($cache);

        $tagMethod = $reflection->getMethod('buildTagKey');
        $tagMethod->setAccessible(true);
        $statsMethod = $reflection->getMethod('buildStatsKey');
        $statsMethod->setAccessible(true);
        $lockMethod = $reflection->getMethod('buildLockKey');
        $lockMethod->setAccessible(true);

        $this->assertStringStartsWith('{request-cache}:tags:', $tagMethod->invoke($cache, 'users'));
        $this->assertStringStartsWith('{request-cache}:stats:', $statsMethod->invoke($cache, 'hits'));
        $this->assertStringStartsWith('{request-cache}:lock:', $lockMethod->invoke($cache, 'cache-key'));
    }

    public function testConstructorLoadsCacheAndClusterConfig()
    {
        new RequestCache($this->clusterConfig([
            'hash_tag' => 'request-cache',
            'cluster_safe_mode' => true,
        ], [
            'local_cache' => [
                'ttl' => 30,
                'size' => 5,
            ],
        ]));

        $this->assertSame(5, CacheConfig::getLocalCacheConfig()['size']);
        $this->assertTrue(CacheConfig::getRedisClusterConfig()['enabled']);
        $this->assertSame('request-cache', CacheConfig::getRedisClusterConfig()['hash_tag']);
    }

    public function testConfigFileContainsClusterAndSharedModeOptions()
    {
        $config = require __DIR__ . '/../config/request_cache.php';

        $this->assertArrayHasKey('shared_mode', $config['cache']['strategy']);
        $this->assertFalse($config['cache']['strategy']['shared_mode']);
        $this->assertArrayHasKey('redis_cluster', $config['cache']);
        $this->assertArrayHasKey('cluster_safe_mode', $config['cache']['redis_cluster']);
        $this->assertArrayHasKey('scan_strategy', $config['cache']['redis_cluster']);
    }

    public function testStrategyLoadsSharedMode()
    {
        new RequestCache($this->clusterConfig([], [
            'strategy' => [
                'shared_mode' => true,
            ],
        ]));

        $this->assertTrue(CacheConfig::getStrategy()['shared_mode']);
    }

    public function testScanStrategyLoadsAllNodes()
    {
        new RequestCache($this->clusterConfig([
            'scan_strategy' => 'all_nodes',
        ]));

        $this->assertSame('all_nodes', CacheConfig::getRedisClusterConfig()['scan_strategy']);
    }

    public function testInvalidScanStrategyFallsBackToSingleConnection()
    {
        new RequestCache($this->clusterConfig([
            'scan_strategy' => 'invalid',
        ]));

        $this->assertSame('single_connection', CacheConfig::getRedisClusterConfig()['scan_strategy']);
    }

    public function testSetRedisClusterConfigNormalizesInvalidScanStrategy()
    {
        CacheConfig::setRedisClusterConfig([
            'scan_strategy' => 'invalid',
        ]);

        $this->assertSame('single_connection', CacheConfig::getRedisClusterConfig()['scan_strategy']);
    }

    public function testRedisClusterNodeResolverUsesCurrentConnectionForSingleConnectionStrategy()
    {
        $fallback = $this->redisFake([]);
        $resolver = new RedisClusterNodeResolver([
            'enabled' => true,
            'scan_strategy' => 'single_connection',
        ], $fallback);

        $this->assertSame(['current_connection' => $fallback], $resolver->scanConnections());
        $this->assertTrue($resolver->usesCurrentConnectionFallback());
    }

    public function testRedisClusterNodeResolverFallsBackWhenClusterConfigIsEmpty()
    {
        Config::set('database.redis.clusters', []);
        $fallback = $this->redisFake([]);
        $resolver = new RedisClusterNodeResolver([
            'enabled' => true,
            'scan_strategy' => 'all_nodes',
        ], $fallback);

        $this->assertSame(['current_connection' => $fallback], $resolver->scanConnections());
        $this->assertTrue($resolver->usesCurrentConnectionFallback());
    }

    public function testRedisClusterNodeResolverResolvesConfiguredClusterNodes()
    {
        Config::set('database.redis.clusters.default', [
            ['host' => '127.0.0.1', 'port' => 7000],
            ['host' => '127.0.0.1', 'port' => 7001],
        ]);
        $first = $this->redisFake([]);
        $second = $this->redisFake([]);
        Redis::shouldReceive('purge')->twice();
        Redis::shouldReceive('connection')
            ->once()
            ->with('request_cache_cluster_node_default_0')
            ->andReturn($first);
        Redis::shouldReceive('connection')
            ->once()
            ->with('request_cache_cluster_node_default_1')
            ->andReturn($second);

        $resolver = new RedisClusterNodeResolver([
            'enabled' => true,
            'scan_strategy' => 'all_nodes',
        ]);

        $this->assertSame([
            'request_cache_cluster_node_default_0' => $first,
            'request_cache_cluster_node_default_1' => $second,
        ], $resolver->scanConnections());
        $this->assertFalse($resolver->usesCurrentConnectionFallback());
    }

    public function testRedisClusterNodeResolverFallsBackWhenNodeConnectionsFail()
    {
        Config::set('database.redis.clusters.default', [
            ['host' => '127.0.0.1', 'port' => 7000],
        ]);
        $fallback = $this->redisFake([]);
        Redis::shouldReceive('purge')->once();
        Redis::shouldReceive('connection')
            ->once()
            ->with('request_cache_cluster_node_default_0')
            ->andThrow(new RuntimeException('node unavailable'));

        $resolver = new RedisClusterNodeResolver([
            'enabled' => true,
            'scan_strategy' => 'all_nodes',
        ], $fallback);

        $this->assertSame(['current_connection' => $fallback], $resolver->scanConnections());
        $this->assertTrue($resolver->usesCurrentConnectionFallback());
    }

    public function testServiceProviderRegistersCacheServices()
    {
        $this->assertInstanceOf(RequestCache::class, $this->app->make('request-cache'));
        $this->assertInstanceOf(CacheMonitor::class, $this->app->make('cache-monitor'));
    }

    public function testCacheMonitorUsesSamePrefixAsRequestCache()
    {
        $config = $this->clusterConfig([
            'hash_tag' => 'request-cache',
        ]);

        $monitor = new CacheMonitor($config);
        $reflection = new ReflectionClass($monitor);
        $property = $reflection->getProperty('prefix');
        $property->setAccessible(true);

        $this->assertSame(RequestCache::resolvePrefix($config), $property->getValue($monitor));
    }

    public function testCacheMonitorStatsKeysUseClusterHashTag()
    {
        $monitor = new CacheMonitor($this->clusterConfig([
            'hash_tag' => 'request-cache',
        ]));
        $reflection = new ReflectionClass($monitor);
        $method = $reflection->getMethod('buildStatsKey');
        $method->setAccessible(true);

        $this->assertSame('{request-cache}:stats:hits', $method->invoke($monitor, 'hits'));
        $this->assertSame('{request-cache}:stats:hits:2026-06-25', $method->invoke($monitor, 'hits', '2026-06-25'));
    }

    public function testCacheMonitorMemoryUsageDeclaresCurrentConnectionScope()
    {
        $monitor = new CacheMonitor($this->clusterConfig([
            'hash_tag' => 'request-cache',
        ]));
        Redis::shouldReceive('info')->once()->with('memory')->andReturn([
            'used_memory' => 100,
            'used_memory_human' => '100B',
        ]);

        $memory = $monitor->getMemoryUsage();

        $this->assertSame('current_connection', $memory['scope']);
        $this->assertSame(100, $memory['used_memory']);
    }

    public function testClusterMonitorMemoryUsageAggregatesAllNodes()
    {
        Config::set('database.redis.clusters.default', [
            ['host' => '127.0.0.1', 'port' => 7000],
            ['host' => '127.0.0.1', 'port' => 7001],
        ]);
        $first = $this->redisFake([
            'info' => ['used_memory' => 100, 'maxmemory' => 1000],
        ]);
        $second = $this->redisFake([
            'info' => ['used_memory' => 250, 'maxmemory' => 2000],
        ]);
        Redis::shouldReceive('purge')->twice();
        Redis::shouldReceive('connection')
            ->once()
            ->with('request_cache_cluster_node_default_0')
            ->andReturn($first);
        Redis::shouldReceive('connection')
            ->once()
            ->with('request_cache_cluster_node_default_1')
            ->andReturn($second);

        $monitor = new CacheMonitor($this->clusterConfig([
            'hash_tag' => 'request-cache',
            'scan_strategy' => 'all_nodes',
        ]));
        $memory = $monitor->getMemoryUsage();

        $this->assertSame('cluster_aggregate', $memory['scope']);
        $this->assertSame(350, $memory['total_used_memory']);
        $this->assertCount(2, $memory['nodes']);
    }

    public function testClusterMonitorHealthStatusWarnsWhenOneNodeFails()
    {
        Config::set('database.redis.clusters.default', [
            ['host' => '127.0.0.1', 'port' => 7000],
            ['host' => '127.0.0.1', 'port' => 7001],
        ]);
        $healthy = $this->redisFake([
            'ping' => 'PONG',
        ]);
        $failed = $this->redisFake([
            'ping' => function () {
                throw new RuntimeException('node unavailable');
            },
        ]);
        Redis::shouldReceive('purge')->twice();
        Redis::shouldReceive('connection')
            ->once()
            ->with('request_cache_cluster_node_default_0')
            ->andReturn($healthy);
        Redis::shouldReceive('connection')
            ->once()
            ->with('request_cache_cluster_node_default_1')
            ->andReturn($failed);

        $monitor = new CacheMonitor($this->clusterConfig([
            'hash_tag' => 'request-cache',
            'scan_strategy' => 'all_nodes',
        ]));

        $this->assertSame('warning', $monitor->getHealthStatus());
    }

    public function testClusterMonitorKeyDistributionAggregatesAllNodes()
    {
        Config::set('database.redis.clusters.default', [
            ['host' => '127.0.0.1', 'port' => 7000],
            ['host' => '127.0.0.1', 'port' => 7001],
        ]);
        $first = $this->redisFake([
            'scan' => ['0', ['{request-cache}:2.0:users:first']],
        ]);
        $second = $this->redisFake([
            'scan' => ['0', ['{request-cache}:2.0:profiles:second']],
        ]);
        Redis::shouldReceive('purge')->twice();
        Redis::shouldReceive('connection')
            ->once()
            ->with('request_cache_cluster_node_default_0')
            ->andReturn($first);
        Redis::shouldReceive('connection')
            ->once()
            ->with('request_cache_cluster_node_default_1')
            ->andReturn($second);

        $monitor = new CacheMonitor($this->clusterConfig([
            'hash_tag' => 'request-cache',
            'scan_strategy' => 'all_nodes',
        ]));
        $distribution = $monitor->getKeyDistribution();

        $this->assertSame('cluster_aggregate', $distribution['scope']);
        $this->assertSame(1, $distribution['distribution']['2.0']['users']);
        $this->assertSame(1, $distribution['distribution']['2.0']['profiles']);
    }

    public function testClusterMonitorStatsTrendDoesNotUseNodeAggregation()
    {
        Redis::shouldReceive('get')->twice()->andReturn(5, 5);
        Redis::shouldReceive('connection')->never();

        $monitor = new CacheMonitor($this->clusterConfig([
            'hash_tag' => 'request-cache',
            'scan_strategy' => 'all_nodes',
        ]));
        $trend = $monitor->getTrend(1);

        $this->assertSame(5, $trend[0]['hits']);
        $this->assertSame(5, $trend[0]['misses']);
    }

    public function testDeleteClearsCurrentProcessLocalCacheBeforeRedis()
    {
        $cache = new RequestCache($this->clusterConfig([
            'hash_tag' => 'request-cache',
        ]));
        $key = $cache->generateKey('users', ['id' => 1]);

        $localCache = $this->localCacheFor($cache);
        $localCache->set($key, ['name' => 'stale'], 60);

        $cache->delete('users', ['id' => 1]);

        $this->assertNull($localCache->get($key));
    }

    public function testClearGatewayFlushesCurrentProcessLocalCache()
    {
        $cache = new RequestCache($this->clusterConfig([
            'hash_tag' => 'request-cache',
        ]));
        $firstKey = $cache->generateKey('users', ['id' => 1]);
        $secondKey = $cache->generateKey('profiles', ['id' => 2]);
        $localCache = $this->localCacheFor($cache);
        $localCache->set($firstKey, ['name' => 'Ada'], 60);
        $localCache->set($secondKey, ['name' => 'Bob'], 60);
        $redis = $this->redisFake([
            'scan' => ['0', []],
        ]);
        Redis::shouldReceive('connection')->once()->andReturn($redis);

        $this->assertTrue($cache->clearGateway('users'));
        $this->assertNull($localCache->get($firstKey));
        $this->assertNull($localCache->get($secondKey));
    }

    public function testClearTagsFlushesCurrentProcessLocalCache()
    {
        $cache = new RequestCache($this->clusterConfig([
            'hash_tag' => 'request-cache',
        ]));
        $key = $cache->generateKey('users', ['id' => 1]);
        $localCache = $this->localCacheFor($cache);
        $localCache->set($key, ['name' => 'Ada'], 60);
        $redis = $this->redisFake([
            'smembers' => [],
            'del' => 1,
        ]);
        Redis::shouldReceive('connection')->once()->andReturn($redis);

        $this->assertTrue($cache->clearTags('users'));
        $this->assertNull($localCache->get($key));
    }

    public function testClearAllFlushesCurrentProcessLocalCache()
    {
        $cache = new RequestCache($this->clusterConfig([
            'hash_tag' => 'request-cache',
        ]));
        $key = $cache->generateKey('users', ['id' => 1]);
        $localCache = $this->localCacheFor($cache);
        $localCache->set($key, ['name' => 'Ada'], 60);
        $redis = $this->redisFake([
            'scan' => ['0', []],
        ]);
        Redis::shouldReceive('connection')->once()->andReturn($redis);

        $this->assertTrue($cache->clearAll());
        $this->assertNull($localCache->get($key));
    }

    public function testAllNodesClearAllScansAndDeletesKeysOnEachClusterNode()
    {
        Config::set('database.redis.clusters.default', [
            ['host' => '127.0.0.1', 'port' => 7000],
            ['host' => '127.0.0.1', 'port' => 7001],
        ]);
        $first = $this->redisFake([
            'scan' => ['0', ['{request-cache}:2.0:users:first']],
            'del' => 1,
        ]);
        $second = $this->redisFake([
            'scan' => ['0', ['{request-cache}:2.0:users:second']],
            'del' => 1,
        ]);
        Redis::shouldReceive('purge')->twice();
        Redis::shouldReceive('connection')
            ->once()
            ->with('request_cache_cluster_node_default_0')
            ->andReturn($first);
        Redis::shouldReceive('connection')
            ->once()
            ->with('request_cache_cluster_node_default_1')
            ->andReturn($second);

        $cache = new RequestCache($this->clusterConfig([
            'hash_tag' => 'request-cache',
            'scan_strategy' => 'all_nodes',
        ]));

        $this->assertTrue($cache->clearAll());
        $this->assertCount(1, $first->calls['scan']);
        $this->assertCount(1, $second->calls['scan']);
        $this->assertCount(1, $first->calls['del']);
        $this->assertCount(1, $second->calls['del']);
    }

    public function testAllNodesClearGatewayDeletesHealthyNodeKeysWhenOneScanFails()
    {
        Config::set('database.redis.clusters.default', [
            ['host' => '127.0.0.1', 'port' => 7000],
            ['host' => '127.0.0.1', 'port' => 7001],
        ]);
        $failed = $this->redisFake([
            'scan' => function () {
                throw new RuntimeException('scan failed');
            },
        ]);
        $healthy = $this->redisFake([
            'scan' => ['0', ['{request-cache}:2.0:users:healthy']],
            'del' => 1,
        ]);
        Redis::shouldReceive('purge')->twice();
        Redis::shouldReceive('connection')
            ->once()
            ->with('request_cache_cluster_node_default_0')
            ->andReturn($failed);
        Redis::shouldReceive('connection')
            ->once()
            ->with('request_cache_cluster_node_default_1')
            ->andReturn($healthy);

        $cache = new RequestCache($this->clusterConfig([
            'hash_tag' => 'request-cache',
            'scan_strategy' => 'all_nodes',
        ]));

        $this->assertTrue($cache->clearGateway('users'));
        $this->assertCount(1, $healthy->calls['del']);
    }

    public function testSingleConnectionClearAllKeepsCurrentConnectionBehavior()
    {
        $redis = $this->redisFake([
            'scan' => ['0', []],
        ]);
        Redis::shouldReceive('connection')->once()->andReturn($redis);
        $cache = new RequestCache($this->clusterConfig([
            'hash_tag' => 'request-cache',
            'scan_strategy' => 'single_connection',
        ]));

        $this->assertTrue($cache->clearAll());
        $this->assertCount(1, $redis->calls['scan']);
    }

    public function testMgetUsesSingleKeyReadsWhenClusterSafeModeEnabled()
    {
        $cache = new RequestCache($this->clusterConfig([
            'hash_tag' => 'request-cache',
            'cluster_safe_mode' => true,
        ]));
        $redis = $this->redisFake([
            'get' => function ($key) {
                return str_contains($key, ':users:')
                    ? json_encode(['source' => 'redis'])
                    : null;
            },
        ]);
        Redis::shouldReceive('connection')->once()->andReturn($redis);

        $result = $cache->mget([
            ['users', ['id' => 1]],
            ['users', ['id' => 2]],
        ]);

        $this->assertCount(2, $redis->calls['get']);
        $this->assertSame(['source' => 'redis'], $result[0]);
        $this->assertSame(['source' => 'redis'], $result[1]);
    }

    public function testMgetFallsBackToSingleKeyReadsOnCrossSlot()
    {
        $cache = new RequestCache($this->clusterConfig([
            'hash_tag' => 'request-cache',
            'cluster_safe_mode' => false,
        ]));
        $mgetRedis = $this->redisFake([
            'mget' => function () {
                throw new RuntimeException('CROSSSLOT Keys in request do not hash to the same slot');
            },
        ]);
        $singleRedis = $this->redisFake([
            'get' => function () {
                return json_encode(['source' => 'fallback']);
            },
        ]);
        Redis::shouldReceive('connection')->times(3)->andReturn($mgetRedis, $singleRedis, $singleRedis);

        $result = $cache->mget([
            ['users', ['id' => 1]],
            ['users', ['id' => 2]],
        ]);

        $this->assertSame(['source' => 'fallback'], $result[0]);
        $this->assertSame(['source' => 'fallback'], $result[1]);
        $this->assertCount(2, $singleRedis->calls['get']);
    }

    public function testMsetUsesSetWhenClusterSafeModeEnabled()
    {
        $cache = new RequestCache($this->clusterConfig([
            'hash_tag' => 'request-cache',
            'cluster_safe_mode' => true,
        ]));
        $redis = $this->redisFake([
            'setex' => true,
            'sadd' => true,
            'expire' => true,
        ]);
        Redis::shouldReceive('connection')->twice()->andReturn($redis);

        $result = $cache->mset([
            ['users', ['id' => 1], ['name' => 'Ada'], 60],
            ['users', ['id' => 2], ['name' => 'Bob'], 60],
        ]);

        $this->assertSame([0 => true, 1 => true], $result);
        $this->assertCount(2, $redis->calls['setex']);
    }

    public function testMsetPreservesCrossSlotRedisDowngrade()
    {
        $cache = new RequestCache($this->clusterConfig([
            'hash_tag' => 'request-cache',
            'cluster_safe_mode' => false,
        ]));
        $pipeline = $this->redisFake([
            'setex' => true,
            'sadd' => true,
            'expire' => true,
            'exec' => function () {
                throw new RuntimeException('CROSSSLOT Keys in request do not hash to the same slot');
            },
        ]);
        $pipelineRedis = $this->redisFake([
            'pipeline' => $pipeline,
        ]);
        $writeRedis = $this->redisFake([
            'setex' => true,
            'sadd' => true,
            'expire' => true,
        ]);
        Redis::shouldReceive('connection')->times(3)->andReturn($pipelineRedis, $writeRedis, $writeRedis);

        $result = $cache->mset([
            ['users', ['id' => 1], ['name' => 'Ada'], 60],
            ['users', ['id' => 2], ['name' => 'Bob'], 60],
        ]);

        $this->assertSame([0 => true, 1 => true], $result);
        $this->assertCount(2, $writeRedis->calls['setex']);
    }

    public function testBatchDeleteFallsBackToSingleKeyDeletesOnCrossSlot()
    {
        $cache = new RequestCache($this->clusterConfig([
            'hash_tag' => 'request-cache',
            'cluster_safe_mode' => false,
        ]));
        $redis = $this->redisFake([
            'del' => function ($keys) {
                if (is_array($keys)) {
                    throw new RuntimeException('CROSSSLOT Keys in request do not hash to the same slot');
                }

                return 1;
            },
        ]);
        Redis::shouldReceive('connection')->once()->andReturn($redis);

        $reflection = new ReflectionClass($cache);
        $method = $reflection->getMethod('batchDelete');
        $method->setAccessible(true);
        $deleted = $method->invoke($cache, ['{request-cache}:1:a', '{request-cache}:1:b']);

        $this->assertSame(2, $deleted);
    }

    public function testSetDoesNotFallbackToLocalCacheWhenSharedModeEnabled()
    {
        $cache = new RequestCache($this->clusterConfig([
            'hash_tag' => 'request-cache',
        ], [
            'strategy' => [
                'fallback' => true,
                'shared_mode' => true,
            ],
        ]));
        Redis::shouldReceive('connection')->once()->andThrow(new RuntimeException('redis write failed'));

        $result = $cache->set('users', ['id' => 1], ['name' => 'Ada'], 60);
        $key = $cache->generateKey('users', ['id' => 1]);

        $this->assertFalse($result);
        $this->assertNull($this->localCacheFor($cache)->get($key));
    }

    public function testSetKeepsLocalFallbackWhenSharedModeDisabled()
    {
        $cache = new RequestCache($this->clusterConfig([
            'hash_tag' => 'request-cache',
        ], [
            'strategy' => [
                'fallback' => true,
                'shared_mode' => false,
            ],
        ]));
        Redis::shouldReceive('connection')->once()->andThrow(new RuntimeException('redis write failed'));

        $result = $cache->set('users', ['id' => 1], ['name' => 'Ada'], 60);
        $key = $cache->generateKey('users', ['id' => 1]);

        $this->assertTrue($result);
        $this->assertSame(['name' => 'Ada'], $this->localCacheFor($cache)->get($key));
    }

    public function testMsetDoesNotFallbackToLocalCacheWhenSharedModeEnabled()
    {
        $cache = new RequestCache($this->clusterConfig([
            'hash_tag' => 'request-cache',
            'cluster_safe_mode' => false,
        ], [
            'strategy' => [
                'fallback' => true,
                'shared_mode' => true,
            ],
        ]));
        $pipeline = $this->redisFake([
            'setex' => true,
            'sadd' => true,
            'expire' => true,
            'exec' => function () {
                throw new RuntimeException('redis write failed');
            },
        ]);
        $redis = $this->redisFake([
            'pipeline' => $pipeline,
        ]);
        Redis::shouldReceive('connection')->once()->andReturn($redis);

        $result = $cache->mset([
            ['users', ['id' => 1], ['name' => 'Ada'], 60],
            ['users', ['id' => 2], ['name' => 'Bob'], 60],
        ]);

        $this->assertSame([0 => false, 1 => false], $result);
        $this->assertNull($this->localCacheFor($cache)->get($cache->generateKey('users', ['id' => 1])));
        $this->assertNull($this->localCacheFor($cache)->get($cache->generateKey('users', ['id' => 2])));
    }

    public function testMsetPreservesCrossSlotRedisDowngradeInSharedMode()
    {
        $cache = new RequestCache($this->clusterConfig([
            'hash_tag' => 'request-cache',
            'cluster_safe_mode' => false,
        ], [
            'strategy' => [
                'fallback' => true,
                'shared_mode' => true,
            ],
        ]));
        $pipeline = $this->redisFake([
            'setex' => true,
            'sadd' => true,
            'expire' => true,
            'exec' => function () {
                throw new RuntimeException('CROSSSLOT Keys in request do not hash to the same slot');
            },
        ]);
        $pipelineRedis = $this->redisFake([
            'pipeline' => $pipeline,
        ]);
        $writeRedis = $this->redisFake([
            'setex' => true,
            'sadd' => true,
            'expire' => true,
        ]);
        Redis::shouldReceive('connection')->times(3)->andReturn($pipelineRedis, $writeRedis, $writeRedis);

        $result = $cache->mset([
            ['users', ['id' => 1], ['name' => 'Ada'], 60],
            ['users', ['id' => 2], ['name' => 'Bob'], 60],
        ]);

        $this->assertSame([0 => true, 1 => true], $result);
        $this->assertSame(['name' => 'Ada'], $this->localCacheFor($cache)->get($cache->generateKey('users', ['id' => 1])));
        $this->assertSame(['name' => 'Bob'], $this->localCacheFor($cache)->get($cache->generateKey('users', ['id' => 2])));
    }

    public function testWarmAndRememberUseSharedModeSetSemanticsWithoutChangingReturnValue()
    {
        $warmCache = new RequestCache($this->clusterConfig([
            'hash_tag' => 'request-cache',
        ], [
            'strategy' => [
                'fallback' => true,
                'shared_mode' => true,
            ],
        ]));
        Redis::shouldReceive('connection')->once()->andThrow(new RuntimeException('redis write failed'));

        $warmResult = $warmCache->warm('users', ['id' => 3], fn () => ['name' => 'Cara'], 60);

        $this->assertSame(['name' => 'Cara'], $warmResult);
        $this->assertNull($this->localCacheFor($warmCache)->get($warmCache->generateKey('users', ['id' => 3])));

        Mockery::close();

        $rememberCache = new RequestCache($this->clusterConfig([
            'hash_tag' => 'request-cache',
        ], [
            'strategy' => [
                'fallback' => true,
                'shared_mode' => true,
            ],
        ]));
        Redis::shouldReceive('connection')->andThrow(new RuntimeException('redis unavailable'));

        $rememberResult = $rememberCache->remember('users', ['id' => 4], fn () => ['name' => 'Dana'], 60);

        $this->assertSame(['name' => 'Dana'], $rememberResult);
        $this->assertNull($this->localCacheFor($rememberCache)->get($rememberCache->generateKey('users', ['id' => 4])));
    }

    public function testReadmeDocumentsSharedModeClusterSafeModeAndFallbackDifferences()
    {
        $readme = file_get_contents(__DIR__ . '/../README.md');

        $this->assertStringContainsString('cluster_safe_mode', $readme);
        $this->assertStringContainsString('fallback', $readme);
        $this->assertStringContainsString('shared_mode', $readme);
        $this->assertStringContainsString('single-key Redis commands', $readme);
        $this->assertStringContainsString('LocalCache', $readme);
        $this->assertStringContainsString('will not write to `LocalCache`', $readme);
    }

    public function testReadmeSharedModeConfigurationExampleMatchesPackageConfigShape()
    {
        $readme = file_get_contents(__DIR__ . '/../README.md');

        $this->assertStringContainsString("'strategy'", $readme);
        $this->assertStringContainsString("'shared_mode' => true", $readme);
        $this->assertStringContainsString("'redis_cluster'", $readme);
        $this->assertStringContainsString("'cluster_safe_mode' => true", $readme);
        $this->assertStringContainsString("'hash_tag' => 'request-cache'", $readme);
    }

    public function testReadmeNotesDescribeSharedModeWriteFailureSemantics()
    {
        $readme = file_get_contents(__DIR__ . '/../README.md');

        $this->assertStringContainsString('shared_mode', $readme);
        $this->assertStringContainsString('Redis 写失败会返回失败结果', $readme);
        $this->assertStringContainsString('不会写入本地缓存', $readme);
    }

    public function testReadmeDocumentsClusterMonitorCurrentConnectionScope()
    {
        $readme = file_get_contents(__DIR__ . '/../README.md');

        $this->assertTrue(
            str_contains($readme, 'current_connection') || str_contains($readme, '当前连接视角')
        );
    }

    public function testReadmeDocumentsAllNodesScanStrategyAndClusterScopes()
    {
        $readme = file_get_contents(__DIR__ . '/../README.md');

        $this->assertStringContainsString('scan_strategy', $readme);
        $this->assertStringContainsString('all_nodes', $readme);
        $this->assertStringContainsString('single_connection', $readme);
        $this->assertStringContainsString('database.redis.clusters', $readme);
        $this->assertStringContainsString('cluster_aggregate', $readme);
        $this->assertStringContainsString('cluster_partial', $readme);
        $this->assertStringContainsString('current_connection', $readme);
    }

    private function clusterConfig(array $clusterOverrides = [], array $cacheOverrides = [])
    {
        return [
            'request_cache' => [
                'prefix' => null,
                'default_expire' => 5,
                'force_validate' => true,
                'enable_stats' => false,
                'encrypt_data' => false,
                'version' => '2.0',
                'size_limit' => 1048576,
            ],
            'filter' => [
                'sql_keywords' => [],
                'remove_html_tags' => true,
                'trim_whitespace' => true,
                'custom_filters' => [],
            ],
            'cache' => array_replace_recursive([
                'strategy' => [
                    'primary' => 'redis',
                    'secondary' => 'array',
                    'fallback' => true,
                    'shared_mode' => false,
                ],
                'local_cache' => [
                    'ttl' => 300,
                    'size' => 1000,
                ],
                'lock' => [
                    'expire' => 5,
                    'retry_times' => 3,
                    'retry_delay' => 100000,
                    'enable_extend' => true,
                    'extend_interval' => 2,
                ],
                'stats' => [
                    'enabled' => false,
                    'global_expire' => 30 * 24 * 3600,
                    'daily_expire' => 90 * 24 * 3600,
                ],
                'redis_pool' => [
                    'enabled' => false,
                ],
                'redis_cluster' => array_merge([
                    'enabled' => true,
                    'hash_tag' => null,
                    'cluster_safe_mode' => true,
                    'scan_strategy' => 'single_connection',
                ], $clusterOverrides),
            ], $cacheOverrides),
        ];
    }

    private function localCacheFor(RequestCache $cache)
    {
        $reflection = new ReflectionClass($cache);
        $property = $reflection->getProperty('localCache');
        $property->setAccessible(true);

        return $property->getValue($cache);
    }

    private function redisFake(array $handlers)
    {
        return new class($handlers) {
            public array $calls = [];

            public function __construct(private array $handlers)
            {
            }

            public function __call(string $name, array $arguments)
            {
                $this->calls[$name][] = $arguments;
                $handler = $this->handlers[$name] ?? null;

                if ($handler instanceof \Closure) {
                    return $handler(...$arguments);
                }

                return $handler;
            }
        };
    }
}
