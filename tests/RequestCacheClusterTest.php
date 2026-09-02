<?php

namespace HwlowellRequestCache\Tests;

use HwlowellRequestCache\CacheConfig;
use HwlowellRequestCache\CacheMonitor;
use HwlowellRequestCache\FilterConfig;
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

        $this->assertStringStartsWith(
            '{request-cache}:2.0:' . RequestCache::sanitizeGateway('user profile') . ':',
            $key
        );
        $this->assertStringStartsWith('{request-cache}:2.0:userprofile-', $key);
    }

    public function testDistinctGatewaysThatSanitiseAliveGenerateDistinctKeys()
    {
        $cache = new RequestCache($this->clusterConfig([
            'hash_tag' => 'request-cache',
        ]));
        $params = ['id' => 1];

        //清洗有损：这些 gateway 的可读片段全都塌缩成同一个串（或空串），
        //只有原始串指纹能把它们区分开，否则不同接口互相读到对方的缓存
        $keys = [
            $cache->generateKey('user.profile', $params),
            $cache->generateKey('userprofile', $params),
            $cache->generateKey('user profile', $params),
            $cache->generateKey('订单详情', $params),
            $cache->generateKey('用户详情', $params),
            $cache->generateKey('', $params),
        ];

        $this->assertCount(count($keys), array_unique($keys));
    }

    public function testSanitizedGatewayStaysGlobSafeAndClearable()
    {
        //指纹只含 hex，可读片段仍限于 [A-Za-z0-9_-]，不会把通配符带进 SCAN pattern
        foreach (['user.profile', '订单详情', 'a*b?c[d]', ''] as $gateway) {
            $this->assertMatchesRegularExpression(
                '/^[A-Za-z0-9_\-]+$/',
                RequestCache::sanitizeGateway($gateway)
            );
        }

        //非 ASCII gateway 过去被清成空串，clearGateway() 直接返回 false 清不掉
        $this->assertNotSame('', RequestCache::sanitizeGateway('订单详情'));
        $this->assertNotSame(
            RequestCache::sanitizeGateway('订单详情'),
            RequestCache::sanitizeGateway('用户详情')
        );
    }

    public function testResolvePrefixKeepsExistingHashTag()
    {
        $config = $this->clusterConfig([
            'hash_tag' => 'request-cache',
        ]);
        $config['request_cache']['prefix'] = '{custom}:';

        $this->assertSame('{custom}:', RequestCache::resolvePrefix($config));
    }

    public function testResolvePrefixKeepsExplicitPrefixAfterHashTag()
    {
        $config = $this->clusterConfig([
            'hash_tag' => 'request-cache',
        ]);
        $config['request_cache']['prefix'] = 'my_app_';

        //显式配置的 prefix 不能被 hash tag 顶掉：两个应用共用一个集群、又都按
        //文档配了同一个 hash_tag 时，丢掉 prefix 会让双方前缀完全相同，
        //任意一方 clearAll() 的 SCAN pattern 都会连带删光另一方的缓存
        $this->assertSame('{request-cache}:my_app_', RequestCache::resolvePrefix($config));
    }

    public function testResolvePrefixIgnoresEmptyConfiguredPrefix()
    {
        $config = $this->clusterConfig([
            'hash_tag' => 'request-cache',
        ]);
        $config['request_cache']['prefix'] = '';

        $this->assertSame('{request-cache}:', RequestCache::resolvePrefix($config));
    }

    public function testResolvePrefixKeepsExplicitlyEmptyPrefixOutsideCluster()
    {
        $config = $this->clusterConfig(['enabled' => false]);
        $config['request_cache']['prefix'] = '';

        //显式配成 '' 是「完全不加前缀」的用法，不能被自动推导的默认前缀顶回去
        $this->assertSame('', RequestCache::resolvePrefix($config));
    }

    public function testResolvePrefixKeepsConfiguredPrefixVerbatimOutsideCluster()
    {
        $config = $this->clusterConfig(['enabled' => false]);
        $config['request_cache']['prefix'] = 'my_app_';

        $this->assertSame('my_app_', RequestCache::resolvePrefix($config));
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
        $cache = new RequestCache($this->clusterConfig([
            'hash_tag' => 'request-cache',
            'cluster_safe_mode' => true,
        ], [
            'local_cache' => [
                'ttl' => 30,
                'size' => 5,
            ],
        ]));

        $this->assertSame(5, $cache->cacheConfig()->localCache()['size']);
        $this->assertTrue($cache->cacheConfig()->redisCluster()['enabled']);
        $this->assertSame('request-cache', $cache->cacheConfig()->redisCluster()['hash_tag']);
    }

    public function testConstructorConfigDoesNotLeakIntoGlobalState()
    {
        $globalLocalCache = CacheConfig::getLocalCacheConfig();
        $globalCluster = CacheConfig::getRedisClusterConfig();
        $globalStrategy = CacheConfig::getStrategy();

        $cache = new RequestCache($this->clusterConfig([
            'hash_tag' => 'isolated',
            'default_connection' => 'gz',
            'connections' => ['default', 'gz'],
        ], [
            'local_cache' => [
                'ttl' => 7,
                'size' => 3,
            ],
            'strategy' => [
                'shared_mode' => true,
            ],
        ]));

        //实例读到的是传入的配置
        $this->assertSame(3, $cache->cacheConfig()->localCache()['size']);
        $this->assertSame('gz', $cache->cacheConfig()->redisCluster()['default_connection']);
        $this->assertTrue($cache->cacheConfig()->strategy()['shared_mode']);

        //全局静态配置不受影响，容器里的其它实例不会被污染
        $this->assertSame($globalLocalCache, CacheConfig::getLocalCacheConfig());
        $this->assertSame($globalCluster, CacheConfig::getRedisClusterConfig());
        $this->assertSame($globalStrategy, CacheConfig::getStrategy());
    }

    public function testNonUtf8ParametersDoNotCollapseIntoTheSameKey()
    {
        $cache = new RequestCache($this->clusterConfig(['hash_tag' => 'request-cache']));

        //GBK 编码的两个不同词，都不是合法 UTF-8，json_encode() 对它们返回 false
        $gbkA = "\xB2\xE2\xCA\xD4";
        $gbkB = "\xB2\xFA\xC6\xB7";
        $this->assertFalse(json_encode(['q' => $gbkA]));

        $this->assertNotSame(
            $cache->generateKey('users', ['q' => $gbkA]),
            $cache->generateKey('users', ['q' => $gbkB])
        );
    }

    public function testUnserialisableFloatParametersDoNotCollapseIntoTheSameKey()
    {
        $cache = new RequestCache($this->clusterConfig(['hash_tag' => 'request-cache']));

        //NAN / INF 同样让 json_encode() 返回 false
        $this->assertNotSame(
            $cache->generateKey('users', ['score' => NAN]),
            $cache->generateKey('users', ['score' => INF])
        );
    }

    public function testRememberConsumesTagsOnACacheHit()
    {
        $redis = $this->redisFake([
            'get' => json_encode(['v' => 1, 'd' => ['name' => 'Ada']]),
        ]);
        Redis::shouldReceive('connection')->andReturn($redis);
        $cache = new RequestCache($this->clusterConfig(['hash_tag' => 'request-cache']));

        $cache->tags('users')->remember('users', ['id' => 1], static fn () => ['name' => 'Ada'], 60);

        $property = (new ReflectionClass($cache))->getProperty('tags');
        $property->setAccessible(true);

        $this->assertSame([], $property->getValue($cache));
    }

    public function testRememberConsumesTagsWhenItFallsThroughToTheCallback()
    {
        $redis = $this->redisFake([
            'get' => null,
            'set' => false,
            'exists' => 0,
        ]);
        Redis::shouldReceive('connection')->andReturn($redis);
        $cache = new RequestCache($this->clusterConfig(['hash_tag' => 'request-cache']));

        //set() 返回 false 表示抢锁失败，remember() 会走等待再回源的分支
        $cache->tags('users')->remember('users', ['id' => 1], static fn () => ['name' => 'Ada'], 60);

        $property = (new ReflectionClass($cache))->getProperty('tags');
        $property->setAccessible(true);

        $this->assertSame([], $property->getValue($cache));
    }

    public function testTagIndexUsesASortedSetAndDropsExpiredMembers()
    {
        $redis = $this->redisFake([
            'setex' => true,
            'zadd' => 1,
            'zremrangebyscore' => 0,
            'expire' => true,
        ]);
        Redis::shouldReceive('connection')->andReturn($redis);
        $cache = new RequestCache($this->clusterConfig(['hash_tag' => 'request-cache']));

        $cache->tags('users')->set('users', ['id' => 1], ['name' => 'Ada'], 60);

        $this->assertArrayHasKey('zadd', $redis->calls);
        $this->assertArrayHasKey('zremrangebyscore', $redis->calls);
        $this->assertArrayNotHasKey('sadd', $redis->calls);
    }

    public function testClearTagsReportsSuccessWhenTaggedKeysAlreadyExpired()
    {
        $redis = $this->redisFake([
            'zrange' => ['{request-cache}:2.0:users:a'],
            //成员已自然过期，DEL 返回 0
            'del' => 0,
        ]);
        Redis::shouldReceive('connection')->andReturn($redis);
        $cache = new RequestCache($this->clusterConfig(['hash_tag' => 'request-cache']));

        $this->assertTrue($cache->clearTags('users'));
    }

    public function testInstanceWithoutOverrideFollowsRuntimeGlobalChanges()
    {
        $cache = new RequestCache();
        $original = CacheConfig::getStrategy();

        try {
            CacheConfig::setStrategy(['shared_mode' => true]);
            $this->assertTrue($cache->cacheConfig()->strategy()['shared_mode']);

            CacheConfig::setStrategy(['shared_mode' => false]);
            $this->assertFalse($cache->cacheConfig()->strategy()['shared_mode']);
        } finally {
            CacheConfig::setStrategy($original);
        }
    }

    public function testInstanceOverrideWinsOverRuntimeGlobalChanges()
    {
        $cache = new RequestCache([
            'cache' => [
                'strategy' => ['shared_mode' => true],
            ],
        ]);
        $original = CacheConfig::getStrategy();

        try {
            CacheConfig::setStrategy(['shared_mode' => false]);

            $this->assertTrue($cache->cacheConfig()->strategy()['shared_mode']);
            $this->assertFalse(CacheConfig::getStrategy()['shared_mode']);
        } finally {
            CacheConfig::setStrategy($original);
        }
    }

    public function testTwoInstancesKeepIndependentConfiguration()
    {
        $first = new RequestCache($this->clusterConfig([
            'hash_tag' => 'first',
            'default_connection' => 'gz',
            'connections' => ['default', 'gz'],
        ]));
        $second = new RequestCache($this->clusterConfig([
            'hash_tag' => 'second',
            'default_connection' => 'hk',
            'connections' => ['default', 'hk'],
        ]));

        $this->assertSame('gz', $first->cacheConfig()->redisCluster()['default_connection']);
        $this->assertSame('hk', $second->cacheConfig()->redisCluster()['default_connection']);
        $this->assertStringStartsWith('{first}:', $first->generateKey('users', ['id' => 1]));
        $this->assertStringStartsWith('{second}:', $second->generateKey('users', ['id' => 1]));
    }

    public function testFilterConfigDoesNotLeakIntoGlobalState()
    {
        $globalKeywords = FilterConfig::getSqlKeywords();

        $cache = new RequestCache([
            'filter' => [
                'sql_keywords' => ['SELECT'],
                'trim_whitespace' => false,
            ],
        ]);

        $reflection = new ReflectionClass($cache);
        $property = $reflection->getProperty('filterConfig');
        $property->setAccessible(true);

        $this->assertSame(['SELECT'], $property->getValue($cache)->sqlKeywords());
        $this->assertFalse($property->getValue($cache)->shouldTrim());
        $this->assertSame($globalKeywords, FilterConfig::getSqlKeywords());
        $this->assertTrue(FilterConfig::shouldTrimWhitespace());
    }

    public function testConfigFileContainsClusterAndSharedModeOptions()
    {
        $config = require __DIR__ . '/../config/request_cache.php';

        $this->assertArrayHasKey('shared_mode', $config['cache']['strategy']);
        $this->assertFalse($config['cache']['strategy']['shared_mode']);
        $this->assertArrayHasKey('redis_cluster', $config['cache']);
        $this->assertArrayHasKey('cluster_safe_mode', $config['cache']['redis_cluster']);
        $this->assertArrayHasKey('scan_strategy', $config['cache']['redis_cluster']);
        $removedKey = 'redis' . '_pool';
        $this->assertArrayNotHasKey($removedKey, $config['cache']);
    }

    public function testStrategyLoadsSharedMode()
    {
        $cache = new RequestCache($this->clusterConfig([], [
            'strategy' => [
                'shared_mode' => true,
            ],
        ]));

        $this->assertTrue($cache->cacheConfig()->strategy()['shared_mode']);
    }

    public function testScanStrategyLoadsAllNodes()
    {
        $cache = new RequestCache($this->clusterConfig([
            'scan_strategy' => 'all_nodes',
        ]));

        $this->assertSame('all_nodes', $cache->cacheConfig()->redisCluster()['scan_strategy']);
    }

    public function testInvalidScanStrategyFallsBackToSingleConnection()
    {
        $cache = new RequestCache($this->clusterConfig([
            'scan_strategy' => 'invalid',
        ]));

        $this->assertSame('single_connection', $cache->cacheConfig()->redisCluster()['scan_strategy']);
    }

    public function testSetRedisClusterConfigNormalizesInvalidScanStrategy()
    {
        CacheConfig::setRedisClusterConfig([
            'scan_strategy' => 'invalid',
        ]);

        $this->assertSame('single_connection', CacheConfig::getRedisClusterConfig()['scan_strategy']);
    }

    public function testConfigFileDeclaresClusterConnectionOptions()
    {
        $config = require __DIR__ . '/../config/request_cache.php';
        $cluster = $config['cache']['redis_cluster'];

        $this->assertArrayHasKey('default_connection', $cluster);
        $this->assertArrayHasKey('connections', $cluster);
        $this->assertSame('default', $cluster['default_connection']);
        $this->assertSame([], $cluster['connections']);
    }

    public function testClusterConnectionOptionsKeepDefaultsWhenAbsent()
    {
        CacheConfig::setRedisClusterConfig([
            'default_connection' => 'default',
            'connections' => [],
        ]);
        CacheConfig::loadFromConfig([
            'cache' => [
                'redis_cluster' => [
                    'enabled' => true,
                ],
            ],
        ]);

        $this->assertSame('default', CacheConfig::getRedisClusterConfig()['default_connection']);
        $this->assertSame([], CacheConfig::getRedisClusterConfig()['connections']);
    }

    public function testClusterConnectionOptionsLoadFromRequestCacheConstructor()
    {
        $cache = new RequestCache($this->clusterConfig([
            'default_connection' => 'gz',
            'connections' => ['default', 'gz', 'hk'],
        ]));

        $this->assertSame('gz', $cache->cacheConfig()->redisCluster()['default_connection']);
        $this->assertSame(['default', 'gz', 'hk'], $cache->cacheConfig()->redisCluster()['connections']);
    }

    public function testClusterConnectionDefaultNameTrimsAndFallsBack()
    {
        CacheConfig::setRedisClusterConfig([
            'default_connection' => '  gz  ',
            'connections' => [],
        ]);
        $this->assertSame('gz', CacheConfig::getRedisClusterConfig()['default_connection']);

        CacheConfig::setRedisClusterConfig([
            'default_connection' => '   ',
            'connections' => [],
        ]);
        $this->assertSame('default', CacheConfig::getRedisClusterConfig()['default_connection']);
    }

    public function testClusterConnectionWhitelistNormalizesEntries()
    {
        CacheConfig::setRedisClusterConfig([
            'connections' => ['gz', 'gz', '  hk  ', '', ['nested'], 123],
        ]);

        $this->assertSame(['gz', 'hk'], CacheConfig::getRedisClusterConfig()['connections']);
    }

    public function testClusterConnectionWhitelistRejectsNonArray()
    {
        $this->expectException(\InvalidArgumentException::class);

        CacheConfig::setRedisClusterConfig(['connections' => 'gz']);
    }

    public function testCacheConfigNoLongerExposesCustomPoolConfiguration()
    {
        $reflection = new ReflectionClass(CacheConfig::class);
        $removedProperty = 'redis' . 'Pool';
        $removedGetter = 'getRedis' . 'PoolConfig';
        $removedSetter = 'setRedis' . 'PoolConfig';

        $this->assertFalse($reflection->hasProperty($removedProperty));
        $this->assertFalse($reflection->hasMethod($removedGetter));
        $this->assertFalse($reflection->hasMethod($removedSetter));

        CacheConfig::loadFromConfig([
            'cache' => [
                'redis' . '_pool' => [
                    'enabled' => true,
                ],
            ],
        ]);

        $this->assertFalse($reflection->hasProperty($removedProperty));
    }

    public function testRuntimeNoLongerReferencesCustomPool()
    {
        $requestCache = file_get_contents(__DIR__ . '/../Services/RequestCache.php');
        $resolver = file_get_contents(__DIR__ . '/../Services/RedisClusterNodeResolver.php');
        $removedClass = 'Redis' . 'Connection' . 'Pool';
        $removedProperty = 'redis' . 'Pool';
        $removedBranch = '$this->' . $removedProperty . ' ?: Redis::connection()';
        $removedGetter = 'getRedis' . 'PoolConfig';

        $this->assertStringNotContainsString($removedClass, $requestCache);
        $this->assertStringNotContainsString($removedProperty, $requestCache);
        $this->assertStringNotContainsString($removedBranch, $requestCache);
        $this->assertStringNotContainsString($removedGetter, $requestCache);
        $this->assertStringNotContainsString('fallbackConnection', $resolver);
        $this->assertStringNotContainsString('$fallbackConnection', $resolver);
        $this->assertFileDoesNotExist(__DIR__ . '/../Services/' . $removedClass . '.php');
    }

    public function testRequestCacheUsesLaravelRedisConnectionForClusterReadsAndWrites()
    {
        $writeRedis = $this->redisFake([
            'setex' => true,
        ]);
        $readRedis = $this->redisFake([
            'get' => json_encode(['name' => 'Ada']),
        ]);
        Redis::shouldReceive('connection')->twice()->andReturn($writeRedis, $readRedis);

        $writeCache = new RequestCache($this->clusterConfig([
            'hash_tag' => 'request-cache',
        ]));
        $readCache = new RequestCache($this->clusterConfig([
            'hash_tag' => 'request-cache',
        ]));

        $this->assertTrue($writeCache->set('users', ['id' => 1], ['name' => 'Ada'], 60));
        $this->assertSame(['name' => 'Ada'], $readCache->get('users', ['id' => 1]));
        $this->assertArrayHasKey('setex', $writeRedis->calls);
        $this->assertArrayHasKey('get', $readRedis->calls);
    }

    public function testRedisClusterNodeResolverUsesCurrentConnectionForSingleConnectionStrategy()
    {
        $fallback = $this->redisFake([]);
        Redis::shouldReceive('connection')->once()->with('default')->andReturn($fallback);

        $resolver = new RedisClusterNodeResolver([
            'enabled' => true,
            'scan_strategy' => 'single_connection',
        ]);

        $this->assertSame(['current_connection' => $fallback], $resolver->scanConnections());
        $this->assertTrue($resolver->usesCurrentConnectionFallback());
    }

    public function testRedisClusterNodeResolverFallsBackWhenClusterConfigIsEmpty()
    {
        Config::set('database.redis.clusters', []);
        $fallback = $this->redisFake([]);
        Redis::shouldReceive('connection')->once()->with('default')->andReturn($fallback);

        $resolver = new RedisClusterNodeResolver([
            'enabled' => true,
            'scan_strategy' => 'all_nodes',
        ]);

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
        Redis::shouldReceive('connection')->once()->with('default')->andReturn($fallback);

        $resolver = new RedisClusterNodeResolver([
            'enabled' => true,
            'scan_strategy' => 'all_nodes',
        ]);

        $this->assertSame(['current_connection' => $fallback], $resolver->scanConnections());
        $this->assertTrue($resolver->usesCurrentConnectionFallback());
    }

    public function testRedisClusterNodeResolverForcesDowngradeWhenBoundToNonDefaultCluster()
    {
        Config::set('database.redis.clusters', [
            'default' => [
                ['host' => '127.0.0.1', 'port' => 7000],
                ['host' => '127.0.0.1', 'port' => 7001],
            ],
            'gz' => [
                ['host' => '127.0.0.1', 'port' => 7002],
                ['host' => '127.0.0.1', 'port' => 7003],
            ],
            'hk' => [
                ['host' => '127.0.0.1', 'port' => 7004],
            ],
        ]);
        $fallback = $this->redisFake([]);
        Redis::shouldReceive('purge')->never();
        Redis::shouldReceive('connection')->once()->with('gz')->andReturn($fallback);

        $resolver = new RedisClusterNodeResolver([
            'enabled' => true,
            'scan_strategy' => 'all_nodes',
            'default_connection' => 'default',
        ], 'gz');

        $this->assertFalse($resolver->isAllNodesStrategy());
        $this->assertSame(['current_connection' => $fallback], $resolver->scanConnections());
        $this->assertTrue($resolver->usesCurrentConnectionFallback());
    }

    public function testRedisClusterNodeResolverKeepsFullNodeScanWhenBoundToDefaultCluster()
    {
        Config::set('database.redis.clusters', [
            'default' => [
                ['host' => '127.0.0.1', 'port' => 7000],
                ['host' => '127.0.0.1', 'port' => 7001],
            ],
            'gz' => [
                ['host' => '127.0.0.1', 'port' => 7002],
            ],
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
            'default_connection' => 'default',
        ], 'default');

        $this->assertTrue($resolver->isAllNodesStrategy());
        $this->assertSame([
            'request_cache_cluster_node_default_0' => $first,
            'request_cache_cluster_node_default_1' => $second,
        ], $resolver->scanConnections());
        $this->assertFalse($resolver->usesCurrentConnectionFallback());
    }

    public function testRedisClusterNodeResolverComparesBindingAgainstConfiguredDefaultConnection()
    {
        Config::set('database.redis.clusters', [
            'gz' => [
                ['host' => '127.0.0.1', 'port' => 7000],
            ],
            'hk' => [
                ['host' => '127.0.0.1', 'port' => 7001],
            ],
        ]);
        $node = $this->redisFake([]);
        Redis::shouldReceive('purge')->once();
        Redis::shouldReceive('connection')
            ->once()
            ->with('request_cache_cluster_node_gz_0')
            ->andReturn($node);

        $resolver = new RedisClusterNodeResolver([
            'enabled' => true,
            'scan_strategy' => 'all_nodes',
            'default_connection' => 'gz',
        ], 'gz');

        $this->assertTrue($resolver->isAllNodesStrategy());
        $this->assertSame(['request_cache_cluster_node_gz_0' => $node], $resolver->scanConnections());
        $this->assertFalse($resolver->usesCurrentConnectionFallback());
    }

    public function testRedisClusterNodeResolverResolvesNodesByDefaultConnectionNameWithoutBinding()
    {
        Config::set('database.redis.clusters', [
            'gz' => [
                ['host' => '127.0.0.1', 'port' => 7000],
            ],
            'hk' => [
                ['host' => '127.0.0.1', 'port' => 7001],
            ],
        ]);
        $node = $this->redisFake([]);
        Redis::shouldReceive('purge')->once();
        Redis::shouldReceive('connection')
            ->once()
            ->with('request_cache_cluster_node_gz_0')
            ->andReturn($node);

        $resolver = new RedisClusterNodeResolver([
            'enabled' => true,
            'scan_strategy' => 'all_nodes',
            'default_connection' => 'gz',
        ]);

        $this->assertSame(['request_cache_cluster_node_gz_0' => $node], $resolver->scanConnections());
        $this->assertFalse($resolver->usesCurrentConnectionFallback());
    }

    public function testRedisClusterNodeResolverKeepsLegacyDefaultBranchWithoutBinding()
    {
        Config::set('database.redis.clusters', [
            'default' => [
                ['host' => '127.0.0.1', 'port' => 7000],
            ],
            'gz' => [
                ['host' => '127.0.0.1', 'port' => 7001],
            ],
            'hk' => [
                ['host' => '127.0.0.1', 'port' => 7002],
            ],
        ]);
        $node = $this->redisFake([]);
        Redis::shouldReceive('purge')->once();
        Redis::shouldReceive('connection')
            ->once()
            ->with('request_cache_cluster_node_default_0')
            ->andReturn($node);

        $resolver = new RedisClusterNodeResolver([
            'enabled' => true,
            'scan_strategy' => 'all_nodes',
            'default_connection' => 'default',
        ]);

        $this->assertSame(['request_cache_cluster_node_default_0' => $node], $resolver->scanConnections());
        $this->assertFalse($resolver->usesCurrentConnectionFallback());
    }

    public function testRedisClusterNodeResolverKeepsLegacySingleClusterBranchWithoutBinding()
    {
        Config::set('database.redis.clusters', [
            'gz' => [
                ['host' => '127.0.0.1', 'port' => 7000],
            ],
        ]);
        $node = $this->redisFake([]);
        Redis::shouldReceive('purge')->once();
        Redis::shouldReceive('connection')
            ->once()
            ->with('request_cache_cluster_node_gz_0')
            ->andReturn($node);

        $resolver = new RedisClusterNodeResolver([
            'enabled' => true,
            'scan_strategy' => 'all_nodes',
            'default_connection' => 'default',
        ]);

        $this->assertSame(['request_cache_cluster_node_gz_0' => $node], $resolver->scanConnections());
        $this->assertFalse($resolver->usesCurrentConnectionFallback());
    }

    public function testRedisClusterNodeResolverCurrentConnectionUsesConfiguredDefaultNameWhenMultipleClustersCannotResolve()
    {
        Config::set('database.redis.clusters', [
            'hk' => [
                ['host' => '127.0.0.1', 'port' => 7000],
            ],
            'other' => [
                ['host' => '127.0.0.1', 'port' => 7001],
            ],
        ]);
        $fallback = $this->redisFake([]);
        Redis::shouldReceive('connection')->once()->with('gz')->andReturn($fallback);

        $resolver = new RedisClusterNodeResolver([
            'enabled' => true,
            'scan_strategy' => 'all_nodes',
            'default_connection' => 'gz',
        ]);

        $this->assertSame(['current_connection' => $fallback], $resolver->scanConnections());
        $this->assertTrue($resolver->usesCurrentConnectionFallback());
    }

    public function testRedisClusterNodeResolverCurrentConnectionFallbackUsesBoundName()
    {
        $fallback = $this->redisFake([]);
        Redis::shouldReceive('connection')->once()->with('gz')->andReturn($fallback);

        $resolver = new RedisClusterNodeResolver([
            'enabled' => true,
            'scan_strategy' => 'single_connection',
            'default_connection' => 'default',
        ], 'gz');

        $this->assertSame(['current_connection' => $fallback], $resolver->scanConnections());
        $this->assertTrue($resolver->usesCurrentConnectionFallback());
    }

    public function testRedisClusterNodeResolverTreatsBlankBindingNameAsUnbound()
    {
        Config::set('database.redis.clusters', [
            'default' => [
                ['host' => '127.0.0.1', 'port' => 7000],
            ],
            'gz' => [
                ['host' => '127.0.0.1', 'port' => 7001],
            ],
        ]);
        $node = $this->redisFake([]);
        Redis::shouldReceive('purge')->once();
        Redis::shouldReceive('connection')
            ->once()
            ->with('request_cache_cluster_node_default_0')
            ->andReturn($node);

        $resolver = new RedisClusterNodeResolver([
            'enabled' => true,
            'scan_strategy' => 'all_nodes',
            'default_connection' => 'default',
        ], '   ');

        $this->assertTrue($resolver->isAllNodesStrategy());
        $this->assertSame(['request_cache_cluster_node_default_0' => $node], $resolver->scanConnections());
        $this->assertFalse($resolver->usesCurrentConnectionFallback());
    }

    public function testRedisClusterNodeResolverDowngradeKeepsClusterConfigValueUntouched()
    {
        CacheConfig::setRedisClusterConfig([
            'enabled' => true,
            'scan_strategy' => 'all_nodes',
            'default_connection' => 'default',
            'connections' => [],
        ]);

        $resolver = new RedisClusterNodeResolver(null, 'gz');

        $this->assertFalse($resolver->isAllNodesStrategy());
        $this->assertSame('all_nodes', CacheConfig::getRedisClusterConfig()['scan_strategy']);

        CacheConfig::setRedisClusterConfig([
            'enabled' => true,
            'scan_strategy' => 'single_connection',
            'default_connection' => 'default',
            'connections' => [],
        ]);
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
        $redis = $this->redisFake([
            'info' => [
            'used_memory' => 100,
            'used_memory_human' => '100B',
            ],
        ]);
        Redis::shouldReceive('connection')->once()->with('default')->andReturn($redis);

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
        $redis = $this->redisFake(['get' => 5]);
        Redis::shouldReceive('connection')->once()->with('default')->andReturn($redis);

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
        $localCache->set($this->localCacheKeyFor($cache, $key), ['name' => 'stale'], 60);

        $cache->delete('users', ['id' => 1]);

        $this->assertNull($localCache->get($this->localCacheKeyFor($cache, $key)));
    }

    public function testClearGatewayFlushesCurrentProcessLocalCache()
    {
        $cache = new RequestCache($this->clusterConfig([
            'hash_tag' => 'request-cache',
        ]));
        $firstKey = $cache->generateKey('users', ['id' => 1]);
        $secondKey = $cache->generateKey('profiles', ['id' => 2]);
        $localCache = $this->localCacheFor($cache);
        $localCache->set($this->localCacheKeyFor($cache, $firstKey), ['name' => 'Ada'], 60);
        $localCache->set($this->localCacheKeyFor($cache, $secondKey), ['name' => 'Bob'], 60);
        $redis = $this->redisFake([
            'scan' => ['0', []],
        ]);
        Redis::shouldReceive('connection')->once()->andReturn($redis);

        $this->assertTrue($cache->clearGateway('users'));
        $this->assertNull($localCache->get($this->localCacheKeyFor($cache, $firstKey)));
        $this->assertNull($localCache->get($this->localCacheKeyFor($cache, $secondKey)));
    }

    public function testClearTagsFlushesCurrentProcessLocalCache()
    {
        $cache = new RequestCache($this->clusterConfig([
            'hash_tag' => 'request-cache',
        ]));
        $key = $cache->generateKey('users', ['id' => 1]);
        $localCache = $this->localCacheFor($cache);
        $localCache->set($this->localCacheKeyFor($cache, $key), ['name' => 'Ada'], 60);
        $redis = $this->redisFake([
            'smembers' => [],
            'del' => 1,
        ]);
        Redis::shouldReceive('connection')->once()->andReturn($redis);

        $this->assertTrue($cache->clearTags('users'));
        $this->assertNull($localCache->get($this->localCacheKeyFor($cache, $key)));
    }

    public function testClearAllFlushesCurrentProcessLocalCache()
    {
        $cache = new RequestCache($this->clusterConfig([
            'hash_tag' => 'request-cache',
        ]));
        $key = $cache->generateKey('users', ['id' => 1]);
        $localCache = $this->localCacheFor($cache);
        $localCache->set($this->localCacheKeyFor($cache, $key), ['name' => 'Ada'], 60);
        $redis = $this->redisFake([
            'scan' => ['0', []],
        ]);
        Redis::shouldReceive('connection')->once()->andReturn($redis);

        $this->assertTrue($cache->clearAll());
        $this->assertNull($localCache->get($this->localCacheKeyFor($cache, $key)));
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

        //节点扫描失败意味着该分片没被清理，必须体现在返回值上；
        //但不能因此阻断健康节点的清理
        $this->assertFalse($cache->clearGateway('users'));
        $this->assertCount(1, $healthy->calls['del']);
    }

    public function testClearAllEscapesGlobMetacharactersInPrefix()
    {
        Config::set('database.redis.options.prefix', '');
        $config = $this->clusterConfig(['enabled' => false]);
        $config['request_cache']['prefix'] = 'my*app:';
        $cache = new RequestCache($config);
        $redis = $this->redisFake(['scan' => ['0', []]]);
        Redis::shouldReceive('connection')->once()->andReturn($redis);

        $cache->clearAll();

        //prefix 里的 * 是字面量，不能被 Redis 当成通配符去匹配别的应用的 key
        $this->assertSame('my\\*app:*', $redis->calls['scan'][0][1]['match']);
    }

    public function testTagsSurviveClusterBinding()
    {
        $cache = new RequestCache($this->clusterConfig([
            'cluster_safe_mode' => true,
            'connections' => ['default', 'gz'],
        ]));
        $redis = $this->redisFake([
            'setex' => true,
            'zadd' => 1,
            'zremrangebyscore' => 0,
            'expire' => true,
        ]);
        Redis::shouldReceive('connection')->atLeast()->once()->with('gz')->andReturn($redis);

        //tags() 在前、cluster() 在后是自然写法；克隆时清空标签会让这条写入
        //静默进不了任何标签索引，事后 clearTags('users') 也清不掉它
        $cache->tags('users')->cluster('gz')->set('users', ['id' => 1], ['name' => 'Ada'], 60);

        $this->assertArrayHasKey('zadd', $redis->calls);
    }

    public function testRememberCachesTheCallbackResultAfterTheLockWaitTimesOut()
    {
        $redis = $this->redisFake([
            'get' => null,
            'set' => false, //抢锁失败
            'exists' => 0,  //持锁者已退出，等待立即结束
            'setex' => true,
        ]);
        Redis::shouldReceive('connection')->andReturn($redis);
        $cache = new RequestCache($this->clusterConfig(['hash_tag' => 'request-cache']));

        $data = $cache->remember('users', ['id' => 1], static fn () => ['name' => 'Ada'], 60);

        $this->assertSame(['name' => 'Ada'], $data);
        //回源结果必须落缓存，否则持续竞争下每个等待超时者都会重复回源且谁都不填坑
        $this->assertArrayHasKey('setex', $redis->calls);
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
                return str_contains($key, ':' . RequestCache::sanitizeGateway('users') . ':')
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

    public function testMsetReportsPipelineWriteFailures()
    {
        $cache = new RequestCache($this->clusterConfig([
            'hash_tag' => 'request-cache',
            'cluster_safe_mode' => false,
        ]));
        $pipeline = $this->redisFake([
            'setex' => true,
            //第二条写入被 Redis 拒绝
            'exec' => [true, false],
        ]);
        $redis = $this->redisFake(['pipeline' => $pipeline]);
        Redis::shouldReceive('connection')->atLeast()->once()->andReturn($redis);

        $result = $cache->mset([
            ['users', ['id' => 1], ['name' => 'Ada'], 60],
            ['users', ['id' => 2], ['name' => 'Bob'], 60],
        ]);

        $this->assertSame([0 => true, 1 => false], $result);

        //失败项不得留下本地副本，否则调用方拿到 false 却仍能从本进程读到数据
        $secondKey = $cache->generateKey('users', ['id' => 2]);
        $this->assertNull($this->localCacheFor($cache)->get($this->localCacheKeyFor($cache, $secondKey)));

        $firstKey = $cache->generateKey('users', ['id' => 1]);
        $this->assertSame(
            ['name' => 'Ada'],
            $this->localCacheFor($cache)->get($this->localCacheKeyFor($cache, $firstKey))
        );
    }

    public function testMsetTreatsNonArrayPipelineResponseAsSuccess()
    {
        $cache = new RequestCache($this->clusterConfig([
            'hash_tag' => 'request-cache',
            'cluster_safe_mode' => false,
        ]));
        $pipeline = $this->redisFake([
            'setex' => true,
            'exec' => true,
        ]);
        $redis = $this->redisFake(['pipeline' => $pipeline]);
        Redis::shouldReceive('connection')->atLeast()->once()->andReturn($redis);

        $result = $cache->mset([
            ['users', ['id' => 1], ['name' => 'Ada'], 60],
        ]);

        //客户端不返回逐条响应时无从判断，按成功处理好过把成功写入报成失败
        $this->assertSame([0 => true], $result);
    }

    public function testMsetKeepsResultOrderWhenAnItemFailsBeforeThePipeline()
    {
        $config = $this->clusterConfig([
            'hash_tag' => 'request-cache',
            'cluster_safe_mode' => false,
        ]);
        //第二条超出 size_limit，会在编码阶段就失败，早于管道 exec() 回填结果
        $config['request_cache']['size_limit'] = 512;

        $cache = new RequestCache($config);
        $pipeline = $this->redisFake([
            'setex' => true,
            'exec' => [true],
        ]);
        $redis = $this->redisFake(['pipeline' => $pipeline]);
        Redis::shouldReceive('connection')->atLeast()->once()->andReturn($redis);

        $result = $cache->mset([
            ['users', ['id' => 1], ['name' => 'Ada'], 60],
            ['users', ['id' => 2], ['name' => str_repeat('x', 4096)], 60],
        ]);

        //下标必须跟着入参顺序：调用方常用 array_values() / === / array_combine()
        //消费这个返回值，顺序错位会让结果与条目静默对不上
        $this->assertSame([0 => true, 1 => false], $result);
        $this->assertSame([true, false], array_values($result));
    }

    public function testSetReportsFailureAndSkipsSideEffectsWhenRedisRejectsTheWrite()
    {
        $cache = new RequestCache($this->clusterConfig([
            'hash_tag' => 'request-cache',
            'cluster_safe_mode' => true,
        ]));
        //Redis 拒绝写入（OOM、只读副本等）时只返回 false，不抛异常
        $redis = $this->redisFake(['setex' => false]);
        Redis::shouldReceive('connection')->atLeast()->once()->andReturn($redis);

        $this->assertFalse($cache->tags('users')->set('users', ['id' => 1], ['name' => 'Ada'], 60));

        //返回失败就不能留下本地副本和标签索引条目，否则 shared_mode 下调用方
        //拿到 false 却仍能从本进程读到这条数据
        $key = $cache->generateKey('users', ['id' => 1]);
        $this->assertNull($this->localCacheFor($cache)->get($this->localCacheKeyFor($cache, $key)));
        $this->assertArrayNotHasKey('zadd', $redis->calls);
    }

    public function testSetKeepsLocalCopyWithinConfiguredLocalTtl()
    {
        $cache = new RequestCache($this->clusterConfig([
            'hash_tag' => 'request-cache',
            'cluster_safe_mode' => true,
        ], [
            'local_cache' => [
                'ttl' => 30,
                'size' => 1000,
            ],
        ]));
        $redis = $this->redisFake(['setex' => true]);
        Redis::shouldReceive('connection')->atLeast()->once()->andReturn($redis);

        $cache->set('users', ['id' => 1], ['name' => 'Ada'], 3600);

        //本地副本必须压在 local_cache.ttl 以内，而不是跟着 Redis 的 3600 秒。
        //否则常驻进程会在别的实例改写 Redis 之后继续返回旧值整整一小时
        $key = $cache->generateKey('users', ['id' => 1]);
        $expiresAt = $this->localCacheExpiresFor($cache, $this->localCacheKeyFor($cache, $key));

        $this->assertNotNull($expiresAt);
        $this->assertLessThanOrEqual(time() + 30, $expiresAt);
    }

    public function testSetKeepsLocalCopyShorterThanRedisTtlWhenRedisTtlIsSmaller()
    {
        $cache = new RequestCache($this->clusterConfig([
            'hash_tag' => 'request-cache',
            'cluster_safe_mode' => true,
        ], [
            'local_cache' => [
                'ttl' => 300,
                'size' => 1000,
            ],
        ]));
        $redis = $this->redisFake(['setex' => true]);
        Redis::shouldReceive('connection')->atLeast()->once()->andReturn($redis);

        $cache->set('users', ['id' => 1], ['name' => 'Ada'], 10);

        $key = $cache->generateKey('users', ['id' => 1]);
        $expiresAt = $this->localCacheExpiresFor($cache, $this->localCacheKeyFor($cache, $key));

        $this->assertNotNull($expiresAt);
        $this->assertLessThanOrEqual(time() + 10, $expiresAt);
    }

    public function testMsetKeepsLocalCopyWithinConfiguredLocalTtl()
    {
        $cache = new RequestCache($this->clusterConfig([
            'hash_tag' => 'request-cache',
            'cluster_safe_mode' => false,
        ], [
            'local_cache' => [
                'ttl' => 30,
                'size' => 1000,
            ],
        ]));
        $pipeline = $this->redisFake([
            'setex' => true,
            'exec' => [true],
        ]);
        $redis = $this->redisFake(['pipeline' => $pipeline]);
        Redis::shouldReceive('connection')->atLeast()->once()->andReturn($redis);

        $cache->mset([
            ['users', ['id' => 1], ['name' => 'Ada'], 3600],
        ]);

        $key = $cache->generateKey('users', ['id' => 1]);
        $expiresAt = $this->localCacheExpiresFor($cache, $this->localCacheKeyFor($cache, $key));

        $this->assertNotNull($expiresAt);
        $this->assertLessThanOrEqual(time() + 30, $expiresAt);
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

    public function testClusterBindingReturnsCloneAndKeepsOriginalUnbound()
    {
        $cache = new RequestCache($this->clusterConfig([
            'connections' => ['default', 'gz', 'hk'],
        ]));
        $bound = $cache->cluster('gz');

        $this->assertNotSame($cache, $bound);
        $this->assertNull($this->connectionNameFor($cache));
        $this->assertSame('gz', $this->connectionNameFor($bound));
        $this->assertNotSame($this->clusterResolverFor($cache), $this->clusterResolverFor($bound));
        //LocalCache 由所有克隆共用，隔离靠 localCacheKey() 的连接名命名空间
        $this->assertSame($this->localCacheFor($cache), $this->localCacheFor($bound));

        $bound->tags(['users']);
        $reflection = new ReflectionClass($cache);
        $property = $reflection->getProperty('tags');
        $property->setAccessible(true);
        $this->assertSame([], $property->getValue($cache));
    }

    public function testClusterBindingRejectsNameOutsideWhitelist()
    {
        $cache = new RequestCache($this->clusterConfig([
            'connections' => ['default', 'gz'],
        ]));

        try {
            $cache->cluster('hk');
            $this->fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('hk', $e->getMessage());
            $this->assertStringContainsString('default, gz', $e->getMessage());
        }
    }

    public function testClusterBindingWhitelistDerivesFromDatabaseRedisConfig()
    {
        Config::set('database.redis', [
            'client' => 'phpredis',
            'options' => ['prefix' => 'x'],
            'default' => ['host' => '127.0.0.1'],
            'cache' => ['host' => '127.0.0.1'],
            'clusters' => [
                'options' => ['cluster' => 'redis'],
                'default' => [['host' => '127.0.0.1', 'port' => 7000]],
                'gz' => [['host' => '127.0.0.1', 'port' => 7001]],
                'hk' => [['host' => '127.0.0.1', 'port' => 7002]],
            ],
        ]);
        $cache = new RequestCache($this->clusterConfig(['connections' => []]));
        $reflection = new ReflectionClass($cache);
        $method = $reflection->getMethod('allowedConnections');
        $method->setAccessible(true);
        $allowed = $method->invoke($cache);
        sort($allowed);

        $this->assertSame(['cache', 'default', 'gz', 'hk'], $allowed);
        $this->assertNotContains('client', $allowed);
        $this->assertNotContains('options', $allowed);
        $this->assertNotContains('clusters', $allowed);
        $this->assertInstanceOf(RequestCache::class, $cache->cluster('hk'));
    }

    public function testClusterBindingWhitelistHonoursConfiguredConnections()
    {
        $cache = new RequestCache($this->clusterConfig([
            'connections' => ['gz'],
        ]));
        $reflection = new ReflectionClass($cache);
        $method = $reflection->getMethod('allowedConnections');
        $method->setAccessible(true);

        $this->assertSame(['gz'], $method->invoke($cache));
        $this->assertInstanceOf(RequestCache::class, $cache->cluster('gz'));

        $this->expectException(\InvalidArgumentException::class);
        $cache->cluster('default');
    }

    public function testClusterBindingRoutesReadsAndWritesThroughBoundConnection()
    {
        $cfg = $this->clusterConfig([
            'hash_tag' => 'request-cache',
            'connections' => ['default', 'gz'],
        ]);
        $writeRedis = $this->redisFake([
            'setex' => true,
            'sadd' => true,
            'expire' => true,
        ]);
        $readRedis = $this->redisFake([
            'get' => json_encode(['name' => 'Ada']),
        ]);
        Redis::shouldReceive('connection')->twice()->with('gz')->andReturn($writeRedis, $readRedis);

        $writeCache = (new RequestCache($cfg))->cluster('gz');
        $readCache = (new RequestCache($cfg))->cluster('gz');

        $this->assertTrue($writeCache->set('users', ['id' => 1], ['name' => 'Ada'], 60));
        $this->assertSame(['name' => 'Ada'], $readCache->get('users', ['id' => 1]));
        $this->assertArrayHasKey('setex', $writeRedis->calls);
        $this->assertArrayHasKey('get', $readRedis->calls);
    }

    public function testClusterBindingRoutesDeleteThroughBoundConnection()
    {
        $redis = $this->redisFake(['del' => 1]);
        Redis::shouldReceive('connection')->once()->with('gz')->andReturn($redis);
        $cache = (new RequestCache($this->clusterConfig([
            'connections' => ['default', 'gz'],
        ])))->cluster('gz');

        $this->assertTrue($cache->delete('users', ['id' => 1]));
        $this->assertCount(1, $redis->calls['del']);
    }

    public function testClusterBindingRoutesMgetThroughBoundConnection()
    {
        $mgetRedis = $this->redisFake([
            'mget' => function () {
                throw new RuntimeException('CROSSSLOT Keys in request do not hash to the same slot');
            },
        ]);
        $singleRedis = $this->redisFake([
            'get' => json_encode(['region' => 'gz']),
        ]);
        Redis::shouldReceive('connection')
            ->times(3)
            ->with('gz')
            ->andReturn($mgetRedis, $singleRedis, $singleRedis);
        $cache = (new RequestCache($this->clusterConfig([
            'cluster_safe_mode' => false,
            'connections' => ['default', 'gz'],
        ])))->cluster('gz');

        $result = $cache->mget([
            ['users', ['id' => 1]],
            ['users', ['id' => 2]],
        ]);

        $this->assertSame([
            0 => ['region' => 'gz'],
            1 => ['region' => 'gz'],
        ], $result);
        $this->assertCount(2, $singleRedis->calls['get']);
    }

    public function testClusterBindingRoutesMsetThroughBoundConnection()
    {
        $pipeline = $this->redisFake([
            'setex' => true,
            'sadd' => true,
            'expire' => true,
            'exec' => true,
        ]);
        $redis = $this->redisFake(['pipeline' => $pipeline]);
        Redis::shouldReceive('connection')->once()->with('gz')->andReturn($redis);
        $cache = (new RequestCache($this->clusterConfig([
            'cluster_safe_mode' => false,
            'connections' => ['default', 'gz'],
        ])))->cluster('gz');

        $result = $cache->mset([
            ['users', ['id' => 1], ['name' => 'Ada'], 60],
            ['users', ['id' => 2], ['name' => 'Bob'], 60],
        ]);

        $this->assertSame([0 => true, 1 => true], $result);
        $this->assertCount(2, $pipeline->calls['setex']);
    }

    public function testClusterBindingRoutesClearTagsAndBatchDeleteThroughBoundConnection()
    {
        $redis = $this->redisFake([
            'zrange' => ['{request-cache}:2.0:users:a'],
            'del' => 1,
        ]);
        Redis::shouldReceive('connection')->twice()->with('gz')->andReturn($redis, $redis);
        $cache = (new RequestCache($this->clusterConfig([
            'hash_tag' => 'request-cache',
            'connections' => ['default', 'gz'],
        ])))->cluster('gz');

        $this->assertTrue($cache->clearTags('users'));
        $this->assertArrayHasKey('zrange', $redis->calls);
        $this->assertArrayHasKey('del', $redis->calls);
    }

    public function testClusterBindingRoutesClearGatewayAndClearAllThroughBoundConnection()
    {
        $redis = $this->redisFake(['scan' => ['0', []]]);
        Redis::shouldReceive('connection')->twice()->with('gz')->andReturn($redis, $redis);
        $cache = (new RequestCache($this->clusterConfig([
            'hash_tag' => 'request-cache',
            'scan_strategy' => 'single_connection',
            'connections' => ['default', 'gz'],
        ])))->cluster('gz');

        $this->assertTrue($cache->clearGateway('users'));
        $this->assertTrue($cache->clearAll());
        $this->assertCount(2, $redis->calls['scan']);
    }

    public function testClusterBindingRoutesRememberLocksThroughBoundConnection()
    {
        $redis = $this->redisFake([
            'get' => null,
            'set' => true,
            'setex' => true,
            'eval' => 1,
        ]);
        Redis::shouldReceive('connection')->with('gz')->andReturn($redis);
        $cache = (new RequestCache($this->clusterConfig([
            'connections' => ['default', 'gz'],
        ])))->cluster('gz');

        $result = $cache->remember('users', ['id' => 1], fn () => ['name' => 'Ada'], 60);

        $this->assertSame(['name' => 'Ada'], $result);
        $this->assertCount(1, $redis->calls['set']);
        // renewLock before/after callback + releaseLock
        $this->assertGreaterThanOrEqual(3, count($redis->calls['eval']));
        $this->assertCount(1, $redis->calls['setex']);
        $this->assertCount(2, $redis->calls['get']);
    }

    public function testClusterBindingRoutesRenewLockThroughBoundConnection()
    {
        $redis = $this->redisFake(['eval' => 1]);
        Redis::shouldReceive('connection')->once()->with('gz')->andReturn($redis);
        $cache = (new RequestCache($this->clusterConfig([
            'connections' => ['default', 'gz'],
        ])))->cluster('gz');
        $reflection = new ReflectionClass($cache);
        $method = $reflection->getMethod('renewLock');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($cache, 'cache-key', 'lock-value', 10));
        $this->assertCount(1, $redis->calls['eval']);
    }

    public function testClusterBindingKeepsStatsOnDefaultConnection()
    {
        $redis = $this->redisFake([
            'incr' => 1,
            'expire' => true,
            'get' => 5,
        ]);
        Redis::shouldReceive('connection')->twice()->with('default')->andReturn($redis, $redis);
        $cache = (new RequestCache($this->clusterConfig([
            'connections' => ['default', 'gz'],
        ])))->cluster('gz');
        //enableStats() 返回克隆，必须接住返回值
        $cache = $cache->enableStats(true);
        $reflection = new ReflectionClass($cache);
        $method = $reflection->getMethod('recordStats');
        $method->setAccessible(true);

        $method->invoke($cache, 'hits');

        $this->assertCount(2, $redis->calls['incr']);
        $this->assertSame(5, $cache->getStats()['hits']);
    }

    public function testClusterBindingStatsFollowConfiguredDefaultConnection()
    {
        $redis = $this->redisFake([
            'incr' => 1,
            'expire' => true,
        ]);
        Redis::shouldReceive('connection')->once()->with('gz')->andReturn($redis);
        $cache = new RequestCache($this->clusterConfig([
            'default_connection' => 'gz',
            'connections' => ['default', 'gz'],
        ]));
        $cache = $cache->enableStats(true);
        $reflection = new ReflectionClass($cache);
        $method = $reflection->getMethod('recordStats');
        $method->setAccessible(true);

        $method->invoke($cache, 'hits');

        $this->assertCount(2, $redis->calls['incr']);
    }

    public function testClusterBindingIsAvailableThroughFacadeAndKeepsSingletonUnbound()
    {
        Config::set('request_cache', $this->clusterConfig([
            'connections' => ['default', 'gz'],
        ]));
        $singleton = $this->app->make('request-cache');
        $bound = \HwlowellRequestCache\Facades\RequestCache::cluster('default');

        $this->assertInstanceOf(RequestCache::class, $bound);
        $this->assertNotSame($singleton, $bound);
        $this->assertSame('default', $this->connectionNameFor($bound));
        $this->assertNull($this->connectionNameFor($this->app->make('request-cache')));
        $this->assertSame($singleton, $this->app->make('request-cache'));
    }

    public function testClusterBindingAbsenceKeepsDefaultConfigurationCompatible()
    {
        $redis = $this->redisFake(['get' => json_encode(['name' => 'Ada'])]);
        Redis::shouldReceive('connection')->once()->with('default')->andReturn($redis);
        $cache = new RequestCache($this->clusterConfig([
            'hash_tag' => 'request-cache',
            'connections' => ['default', 'gz'],
        ]));

        $this->assertSame(['name' => 'Ada'], $cache->get('users', ['id' => 1]));
    }

    public function testClusterBindingDisabledRejectsExplicitBinding()
    {
        $cache = new RequestCache($this->clusterConfig([
            'enabled' => false,
            'connections' => ['default', 'gz'],
        ]));

        $this->expectException(\LogicException::class);
        $cache->cluster('gz');
    }

    public function testClusterBindingDisabledStillAllowsUnboundDefaultPath()
    {
        $redis = $this->redisFake(['get' => null]);
        Redis::shouldReceive('connection')->once()->with('gz')->andReturn($redis);
        $cache = new RequestCache($this->clusterConfig([
            'enabled' => false,
            'default_connection' => 'gz',
            'connections' => ['default', 'gz'],
        ]));

        $this->assertNull($cache->get('users', ['id' => 1]));
    }

    public function testClusterBindingUnboundReadAndWriteUseConfiguredDefaultConnection()
    {
        $cfg = $this->clusterConfig([
            'default_connection' => 'gz',
            'connections' => ['default', 'gz'],
        ]);
        $readRedis = $this->redisFake(['get' => json_encode(['region' => 'gz'])]);
        $writeRedis = $this->redisFake([
            'setex' => true,
            'sadd' => true,
            'expire' => true,
        ]);
        Redis::shouldReceive('connection')->twice()->with('gz')->andReturn($readRedis, $writeRedis);

        $readCache = new RequestCache($cfg);
        $writeCache = new RequestCache($cfg);

        $this->assertSame(['region' => 'gz'], $readCache->get('users', ['id' => 1]));
        $this->assertTrue($writeCache->set('users', ['id' => 1], ['region' => 'gz'], 60));
    }

    public function testClusterBindingUnboundCurrentConnectionCleanupUsesConfiguredDefaultConnection()
    {
        $redis = $this->redisFake(['scan' => ['0', []]]);
        Redis::shouldReceive('connection')->once()->with('gz')->andReturn($redis);
        $cache = new RequestCache($this->clusterConfig([
            'default_connection' => 'gz',
            'scan_strategy' => 'single_connection',
            'connections' => ['default', 'gz'],
        ]));

        $this->assertTrue($cache->clearGateway('users'));
        $this->assertCount(1, $redis->calls['scan']);
    }

    public function testClusterBindingCacheMonitorUsesConfiguredDefaultConnectionForStatsAndTrend()
    {
        $redis = $this->redisFake([
            'get' => 5,
            'scan' => ['0', []],
            'info' => ['used_memory' => 0, 'maxmemory' => 0],
            'ping' => 'PONG',
        ]);
        //getStats() 内部键数量与内存各取一次后复用；此前健康判定会各自再取一遍，
        //同一次调用要连上 7 次并把整个 keyspace 扫两轮
        Redis::shouldReceive('connection')->times(5)->with('gz')->andReturn($redis);
        $monitor = new CacheMonitor($this->clusterConfig([
            'enabled' => false,
            'default_connection' => 'gz',
            'connections' => ['default', 'gz'],
        ]));

        $this->assertSame(5, $monitor->getStats()['hits']);
        $this->assertSame(5, $monitor->getTrend(1)[0]['hits']);
    }

    public function testGetStatsScansTheKeyspaceOnlyOnce()
    {
        $redis = $this->redisFake([
            'get' => 1,
            'scan' => ['0', ['{request-cache}:2.0:users:a']],
            'info' => ['used_memory' => 0, 'maxmemory' => 0],
            'ping' => 'PONG',
        ]);
        Redis::shouldReceive('connection')->with('gz')->andReturn($redis);
        $monitor = new CacheMonitor($this->clusterConfig([
            'enabled' => false,
            'default_connection' => 'gz',
            'connections' => ['default', 'gz'],
        ]));

        $stats = $monitor->getStats();

        $this->assertSame(1, $stats['cache_keys']);
        $this->assertCount(1, $redis->calls['scan']);
        $this->assertCount(1, $redis->calls['info']);
    }

    public function testClusterBindingCacheMonitorUsesConfiguredDefaultConnectionForRedisInfoAndPing()
    {
        $redis = $this->redisFake([
            'info' => ['used_memory' => 0, 'maxmemory' => 0],
            'ping' => 'PONG',
            'scan' => ['0', []],
        ]);
        Redis::shouldReceive('connection')->times(4)->with('gz')->andReturn($redis);
        $monitor = new CacheMonitor($this->clusterConfig([
            'enabled' => false,
            'default_connection' => 'gz',
            'connections' => ['default', 'gz'],
        ]));

        $this->assertSame(['used_memory' => 0, 'maxmemory' => 0], $monitor->getRedisInfo('memory'));
        $this->assertSame('healthy', $monitor->getHealthStatus());
    }

    public function testLocalCacheIsolationKeepsDefaultAndBoundClusterValuesApart()
    {
        $cache = new RequestCache($this->clusterConfig([
            'connections' => ['default', 'gz'],
        ]));
        $bound = $cache->cluster('gz');
        $key = $cache->generateKey('users', ['id' => 1]);
        $defaultRedis = $this->redisFake(['get' => json_encode(['region' => 'default'])]);
        $gzRedis = $this->redisFake(['get' => json_encode(['region' => 'gz'])]);
        Redis::shouldReceive('connection')->once()->with('default')->andReturn($defaultRedis);
        Redis::shouldReceive('connection')->once()->with('gz')->andReturn($gzRedis);

        $this->assertSame(['region' => 'default'], $cache->get('users', ['id' => 1]));
        $this->assertSame(['region' => 'gz'], $bound->get('users', ['id' => 1]));
        $this->assertNotSame(
            $this->localCacheKeyFor($cache, $key),
            $this->localCacheKeyFor($bound, $key)
        );
        $this->assertCount(1, $gzRedis->calls['get']);
    }

    public function testLocalCacheIsolationKeepsBoundClusterAndDefaultValuesApartInReverseOrder()
    {
        $cache = new RequestCache($this->clusterConfig([
            'connections' => ['default', 'gz'],
        ]));
        $bound = $cache->cluster('gz');
        $key = $cache->generateKey('users', ['id' => 1]);
        $defaultRedis = $this->redisFake(['get' => json_encode(['region' => 'default'])]);
        $gzRedis = $this->redisFake(['get' => json_encode(['region' => 'gz'])]);
        Redis::shouldReceive('connection')->once()->with('gz')->andReturn($gzRedis);
        Redis::shouldReceive('connection')->once()->with('default')->andReturn($defaultRedis);

        $this->assertSame(['region' => 'gz'], $bound->get('users', ['id' => 1]));
        $this->assertSame(['region' => 'default'], $cache->get('users', ['id' => 1]));
        $this->assertNotSame(
            $this->localCacheKeyFor($cache, $key),
            $this->localCacheKeyFor($bound, $key)
        );
        $this->assertCount(1, $defaultRedis->calls['get']);
    }

    public function testLocalCacheIsolationSharesOneStoreAcrossClusterClones()
    {
        $cache = new RequestCache($this->clusterConfig([
            'connections' => ['default', 'gz', 'hk'],
        ]));
        $gz = $cache->cluster('gz');
        $hk = $cache->cluster('hk');
        $key = $cache->generateKey('users', ['id' => 1]);

        //克隆共用同一个进程内缓存：每个克隆各建一份的话，链式调用写进去的本地
        //副本会随克隆一起被丢弃，delete() 也清不掉别的实例持有的副本
        $this->assertSame($this->localCacheFor($cache), $this->localCacheFor($gz));
        $this->assertSame($this->localCacheFor($gz), $this->localCacheFor($hk));

        $this->localCacheFor($gz)->set($this->localCacheKeyFor($gz, $key), ['region' => 'gz']);
        $this->localCacheFor($hk)->set($this->localCacheKeyFor($hk, $key), ['region' => 'hk']);

        //隔离由 key 的连接名命名空间保证，而不是各自一份存储
        $this->assertSame(['region' => 'gz'], $this->localCacheFor($gz)->get($this->localCacheKeyFor($gz, $key)));
        $this->assertSame(['region' => 'hk'], $this->localCacheFor($hk)->get($this->localCacheKeyFor($hk, $key)));
        $this->assertNotSame(
            $this->localCacheKeyFor($gz, $key),
            $this->localCacheKeyFor($hk, $key)
        );
    }

    public function testChainedModifiersReturnClonesAndLeaveTheOriginalUntouched()
    {
        $cache = new RequestCache($this->clusterConfig([
            'connections' => ['default', 'gz'],
        ]));

        //request-cache 注册为容器单例，这些修饰符若写在 $this 上就会跨调用粘连，
        //在 Octane / 队列 worker 里更会跨请求泄漏
        $this->assertNotSame($cache, $cache->version('9.9'));
        $this->assertNotSame($cache, $cache->sizeLimit(16));
        $this->assertNotSame($cache, $cache->encryptData(true));
        $this->assertNotSame($cache, $cache->enableStats(false));
        $this->assertNotSame($cache, $cache->tags(['users']));

        $baseline = $cache->generateKey('users', ['id' => 1]);
        $cache->version('9.9');
        $cache->sizeLimit(16);

        $this->assertSame($baseline, $cache->generateKey('users', ['id' => 1]));
        $this->assertStringContainsString(':2.0:', $baseline);
        $this->assertStringContainsString(':9.9:', $cache->version('9.9')->generateKey('users', ['id' => 1]));
        $this->assertSame([], $this->tagsFor($cache));
        $this->assertSame(['users'], $this->tagsFor($cache->tags(['users'])));
    }

    public function testLocalCacheIsolationHashesEffectiveConnectionName()
    {
        $cache = new RequestCache($this->clusterConfig([
            'connections' => ['default', 'gz'],
        ]));
        $bound = $cache->cluster('gz');
        $key = $cache->generateKey('users', ['id' => 1]);

        $this->assertSame(
            hash('sha256', 'default') . ':' . $key,
            $this->localCacheKeyFor($cache, $key)
        );
        $this->assertSame(
            hash('sha256', 'gz') . ':' . $key,
            $this->localCacheKeyFor($bound, $key)
        );
    }

    public function testLocalCacheIsolationFallsBackToConfiguredDefaultConnectionName()
    {
        $cache = new RequestCache($this->clusterConfig([
            'default_connection' => 'gz',
            'connections' => ['default', 'gz'],
        ]));
        $key = $cache->generateKey('users', ['id' => 1]);

        $this->assertSame(
            hash('sha256', 'gz') . ':' . $key,
            $this->localCacheKeyFor($cache, $key)
        );
    }

    public function testLocalCacheIsolationAvoidsDelimiterCollision()
    {
        $cache = new RequestCache($this->clusterConfig([
            'connections' => ['a|b', 'a'],
        ]));
        $left = $cache->cluster('a|b');
        $right = $cache->cluster('a');
        $leftKey = 'c';
        $rightKey = 'b|c';

        $this->assertNotSame(
            $this->localCacheKeyFor($left, $leftKey),
            $this->localCacheKeyFor($right, $rightKey)
        );
    }

    public function testLocalCacheIsolationDeleteOnlyRemovesBoundClusterEntry()
    {
        $cache = new RequestCache($this->clusterConfig([
            'connections' => ['default', 'gz'],
        ]));
        $bound = $cache->cluster('gz');
        $key = $cache->generateKey('users', ['id' => 1]);
        $redis = $this->redisFake(['del' => 1]);
        Redis::shouldReceive('connection')->once()->with('gz')->andReturn($redis);

        $this->localCacheFor($cache)->set($this->localCacheKeyFor($cache, $key), ['region' => 'default']);
        $this->localCacheFor($bound)->set($this->localCacheKeyFor($bound, $key), ['region' => 'gz']);

        $this->assertTrue($bound->delete('users', ['id' => 1]));
        $this->assertNull($this->localCacheFor($bound)->get($this->localCacheKeyFor($bound, $key)));
        $this->assertSame(
            ['region' => 'default'],
            $this->localCacheFor($cache)->get($this->localCacheKeyFor($cache, $key))
        );
    }

    public function testLocalCacheIsolationClearGatewayFlushesOnlyBoundCloneLocalCache()
    {
        $cache = new RequestCache($this->clusterConfig([
            'scan_strategy' => 'single_connection',
            'connections' => ['default', 'gz'],
        ]));
        $bound = $cache->cluster('gz');
        $key = $cache->generateKey('users', ['id' => 1]);
        $redis = $this->redisFake(['scan' => ['0', []]]);
        Redis::shouldReceive('connection')->once()->with('gz')->andReturn($redis);

        $this->localCacheFor($cache)->set($this->localCacheKeyFor($cache, $key), ['region' => 'default']);
        $this->localCacheFor($bound)->set($this->localCacheKeyFor($bound, $key), ['region' => 'gz']);

        $this->assertTrue($bound->clearGateway('users'));
        $this->assertNull($this->localCacheFor($bound)->get($this->localCacheKeyFor($bound, $key)));
        // 默认实例的 LocalCache 已与克隆隔离，不受 bound->clearGateway 影响
        $this->assertSame(
            ['region' => 'default'],
            $this->localCacheFor($cache)->get($this->localCacheKeyFor($cache, $key))
        );
    }

    public function testLocalCacheIsolationMgetDoesNotHitOtherClusterLocalEntry()
    {
        $cache = new RequestCache($this->clusterConfig([
            'connections' => ['default', 'gz'],
        ]));
        $bound = $cache->cluster('gz');
        $local = $this->localCacheFor($cache);
        $key = $cache->generateKey('users', ['id' => 1]);
        $gzRedis = $this->redisFake(['get' => json_encode(['region' => 'gz'])]);
        Redis::shouldReceive('connection')->once()->with('gz')->andReturn($gzRedis);

        $local->set($this->localCacheKeyFor($cache, $key), ['region' => 'default']);

        $this->assertSame(
            [0 => ['region' => 'gz']],
            $bound->mget([['users', ['id' => 1]]])
        );
        $this->assertCount(1, $gzRedis->calls['get']);
    }

    public function testLocalCacheIsolationMsetPipelineWritesPrefixedLocalCacheKeys()
    {
        $cache = new RequestCache($this->clusterConfig([
            'cluster_safe_mode' => false,
            'connections' => ['default', 'gz'],
        ]));
        $bound = $cache->cluster('gz');
        $boundLocal = $this->localCacheFor($bound);
        $firstKey = $cache->generateKey('users', ['id' => 1]);
        $secondKey = $cache->generateKey('users', ['id' => 2]);
        $pipeline = $this->redisFake([
            'setex' => true,
            'sadd' => true,
            'expire' => true,
            'exec' => true,
        ]);
        $redis = $this->redisFake(['pipeline' => $pipeline]);
        Redis::shouldReceive('connection')->atLeast()->once()->with('gz')->andReturn($redis);

        $result = $bound->mset([
            ['users', ['id' => 1], ['name' => 'Ada'], 60],
            ['users', ['id' => 2], ['name' => 'Bob'], 60],
        ]);

        $this->assertSame([0 => true, 1 => true], $result);
        $this->assertSame(
            ['name' => 'Ada'],
            $boundLocal->get($this->localCacheKeyFor($bound, $firstKey))
        );
        $this->assertSame(
            ['name' => 'Bob'],
            $boundLocal->get($this->localCacheKeyFor($bound, $secondKey))
        );
        $this->assertNull($this->localCacheFor($cache)->get($this->localCacheKeyFor($cache, $firstKey)));
    }

    public function testLocalCacheIsolationSetFallbackWritesPrefixedLocalCacheKey()
    {
        $cache = new RequestCache($this->clusterConfig([
            'connections' => ['default', 'gz'],
        ], [
            'strategy' => [
                'fallback' => true,
                'shared_mode' => false,
            ],
        ]));
        $bound = $cache->cluster('gz');
        $key = $cache->generateKey('users', ['id' => 1]);
        Redis::shouldReceive('connection')
            ->once()
            ->with('gz')
            ->andThrow(new RuntimeException('redis write failed'));

        $this->assertTrue($bound->set('users', ['id' => 1], ['name' => 'Ada'], 60));
        $this->assertSame(
            ['name' => 'Ada'],
            $this->localCacheFor($bound)->get($this->localCacheKeyFor($bound, $key))
        );
        $this->assertNull($this->localCacheFor($cache)->get($this->localCacheKeyFor($cache, $key)));
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
        $this->assertNull($this->localCacheFor($cache)->get($this->localCacheKeyFor($cache, $key)));
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
        $this->assertSame(['name' => 'Ada'], $this->localCacheFor($cache)->get($this->localCacheKeyFor($cache, $key)));
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
        $this->assertNull($this->localCacheFor($cache)->get($this->localCacheKeyFor($cache, $cache->generateKey('users', ['id' => 1]))));
        $this->assertNull($this->localCacheFor($cache)->get($this->localCacheKeyFor($cache, $cache->generateKey('users', ['id' => 2]))));
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
        $this->assertSame(['name' => 'Ada'], $this->localCacheFor($cache)->get($this->localCacheKeyFor($cache, $cache->generateKey('users', ['id' => 1]))));
        $this->assertSame(['name' => 'Bob'], $this->localCacheFor($cache)->get($this->localCacheKeyFor($cache, $cache->generateKey('users', ['id' => 2]))));
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
        $this->assertNull($this->localCacheFor($warmCache)->get($this->localCacheKeyFor($warmCache, $warmCache->generateKey('users', ['id' => 3]))));

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
        $this->assertNull($this->localCacheFor($rememberCache)->get($this->localCacheKeyFor($rememberCache, $rememberCache->generateKey('users', ['id' => 4]))));
    }

    public function testReadmeDocumentsSharedModeClusterSafeModeAndFallbackDifferences()
    {
        $readme = file_get_contents(__DIR__ . '/../README.md');

        $this->assertStringContainsString('cluster_safe_mode', $readme);
        $this->assertStringContainsString('fallback', $readme);
        $this->assertStringContainsString('shared_mode', $readme);
        $this->assertStringContainsString('逐 key Redis 命令', $readme);
        $this->assertStringContainsString('LocalCache', $readme);
        $this->assertStringContainsString('不会写入 `LocalCache`', $readme);
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
        $this->assertStringContainsString('Redis 写失败会返回失败', $readme);
        $this->assertStringContainsString('不会写入本地缓存', $readme);
    }

    public function testReadmeDocumentsClusterMonitorCurrentConnectionScope()
    {
        $readme = file_get_contents(__DIR__ . '/../README.md');

        $this->assertTrue(
            str_contains($readme, 'current_connection') || str_contains($readme, '当前连接视角')
        );
    }

    public function testReadmeNoLongerDocumentsCustomPoolFeature()
    {
        $readme = file_get_contents(__DIR__ . '/../README.md');
        $removedKey = 'redis' . '_pool';

        $this->assertStringNotContainsString($removedKey, $readme);
        $this->assertStringNotContainsString('支持 Redis 连接池管理', $readme);
        $this->assertStringNotContainsString('连接池管理', $readme);
        $this->assertStringNotContainsString('高并发场景可启用 Redis 连接池', $readme);
        $this->assertStringNotContainsString('连接池配置', $readme);
        $this->assertStringContainsString('Laravel Redis Manager', $readme);
        $this->assertStringContainsString('本包不提供独立 Redis 连接池', $readme);
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

    public function testReadmeDocumentsMultiClusterConfigurationOptions()
    {
        $readme = file_get_contents(__DIR__ . '/../README.md');

        $this->assertStringContainsString('## 多 Redis 集群手动切换', $readme);
        $this->assertStringContainsString("'default_connection' => 'default'", $readme);
        $this->assertStringContainsString("'connections' => []", $readme);
        $this->assertStringContainsString("'gz' => [", $readme);
        $this->assertStringContainsString("'hk' => [", $readme);
        $this->assertStringContainsString('REDIS_GZ_HOST_1', $readme);
        $this->assertStringContainsString('database.redis.clusters', $readme);
    }

    public function testReadmeDocumentsClusterMethodUsageAndCloneSemantics()
    {
        $readme = file_get_contents(__DIR__ . '/../README.md');

        $this->assertStringContainsString("RequestCache::cluster('gz')->get(", $readme);
        $this->assertStringContainsString("RequestCache::cluster('hk')->set(", $readme);
        $this->assertStringContainsString('克隆实例', $readme);
        $this->assertStringContainsString('不会污染容器中的 `request-cache` 单例', $readme);
        $this->assertStringContainsString('每次操作只连接一个集群', $readme);
    }

    public function testReadmeDeclaresMultiClusterBoundaries()
    {
        $readme = file_get_contents(__DIR__ . '/../README.md');

        $this->assertStringContainsString('不提供自动兜底读取顺序', $readme);
        $this->assertStringContainsString('不提供自动回填', $readme);
        $this->assertStringContainsString('不提供删除广播与跨集群一致性保证', $readme);
        $this->assertStringContainsString('不解析跨集群数据格式差异', $readme);
        $this->assertStringContainsString('不内置跨集群调用的超时、熔断与并发控制策略', $readme);
        $this->assertStringContainsString('由调用方在业务代码中自行实现', $readme);
    }

    public function testReadmeDocumentsMultiClusterFallbackAndBackfillExample()
    {
        $readme = file_get_contents(__DIR__ . '/../README.md');

        $this->assertStringContainsString(
            "RequestCache::cluster('default')->set('users', \$params, \$data, 600);",
            $readme
        );
        $this->assertStringContainsString("foreach (['default', 'gz', 'hk'] as \$name) {", $readme);
        $this->assertStringContainsString(
            "RequestCache::cluster(\$name)->delete('users', \$params);",
            $readme
        );
        $this->assertStringContainsString('回源数据库', $readme);
    }

    public function testReadmeDocumentsLockStatsOwnershipAndLocalCacheIsolation()
    {
        $readme = file_get_contents(__DIR__ . '/../README.md');

        $this->assertStringContainsString('分布式锁跟随目标集群', $readme);
        $this->assertStringContainsString('统计计数固定写入默认连接', $readme);
        $this->assertStringContainsString('各集群在当前 PHP 进程内各持一份热点缓存，互不串用', $readme);
        $this->assertStringContainsString('LocalCache', $readme);
        $this->assertStringContainsString('APP_KEY', $readme);
    }

    public function testReadmeDocumentsForcedDowngradeForBoundClusters()
    {
        $readme = file_get_contents(__DIR__ . '/../README.md');

        $this->assertStringContainsString('绑定非默认集群时', $readme);
        $this->assertStringContainsString('强制降级', $readme);
        $this->assertStringContainsString('single_connection', $readme);
        $this->assertStringContainsString('all_nodes', $readme);
        $this->assertStringContainsString('配置值本身不会被改写', $readme);
        $this->assertStringContainsString('绑定名恰好等于 `default_connection` 时生效', $readme);
        $this->assertStringNotContainsString('遍历当前绑定集群的全部节点', $readme);
    }

    public function testReadmeWarnsBoundClusterCleanupCoversFirstMasterNodeOnly()
    {
        $readme = file_get_contents(__DIR__ . '/../README.md');

        $this->assertStringContainsString('清理能力边界警示', $readme);
        $this->assertStringContainsString('只覆盖该集群的第一个 master 节点', $readme);
        $this->assertStringContainsString('_masters()[0]', $readme);
        $this->assertStringContainsString('随 TTL 自然过期收敛', $readme);
        $this->assertStringContainsString('不是缺陷', $readme);
        $this->assertStringContainsString('请在未绑定的默认集群上使用', $readme);
    }

    public function testReadmeChangelogAndComposerVersionStayAligned()
    {
        $readme = file_get_contents(__DIR__ . '/../README.md');

        $this->assertStringContainsString('### v1.1.0', $readme);
        $this->assertStringContainsString('### v1.0.5', $readme);

        $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
        $this->assertSame('1.1.0', $composer['version']);
    }

    public function testHongKongReadOnlySmokeContainsNoMutationCalls()
    {
        $path = __DIR__ . '/Integration/HongKongRedisClusterReadOnlySmoke.php';

        $this->assertFileExists($path);
        $this->assertFalse(str_ends_with(basename($path), 'Test.php'));

        $smoke = file_get_contents($path);
        $this->assertStringContainsString('RUN_HK_REDIS_CLUSTER_TEST', $smoke);
        $this->assertStringContainsString('HK_REDIS_SEEDS', $smoke);
        $this->assertStringContainsString('HK_REDIS_PASSWORD', $smoke);
        $this->assertStringContainsString('READ_ONLY_COMMANDS', $smoke);
        $this->assertStringContainsString('PhpRedisClusterConnection', $smoke);

        $mutationTokens = implode('|', [
            'set', 'setex', 'psetex', 'mset', 'append', 'getset',
            'del', 'unlink', 'expire', 'expireat', 'pexpire', 'persist',
            'incr', 'incrby', 'decr', 'decrby',
            'sadd', 'srem', 'hset', 'hdel', 'lpush', 'rpush', 'lpop', 'rpop',
            'zadd', 'zrem', 'rename', 'renamenx', 'restore', 'migrate',
            'eval', 'evalsha', 'script', 'publish',
            'flushdb', 'flushall',
            'delete', 'warm', 'remember', 'clearGateway', 'clearAll', 'clearTags',
        ]);

        $this->assertDoesNotMatchRegularExpression(
            '/(?:->|::)\s*(?:' . $mutationTokens . ')\s*\(/i',
            $smoke
        );
        $this->assertDoesNotMatchRegularExpression(
            '/[\'"](?:' . $mutationTokens . ')[\'"]/i',
            $smoke
        );
        $this->assertDoesNotMatchRegularExpression('/\b(?:rawCommand|command)\s*\(/i', $smoke);
        $this->assertDoesNotMatchRegularExpression('/\b(?:var_dump|print_r|echo)\b/i', $smoke);
        $this->assertDoesNotMatchRegularExpression('/cluster\s+(?:nodes|slots|shards)/i', $smoke);
    }

    public function testDeleteReportsSuccessWhenTheKeyWasAlreadyGone()
    {
        $cache = new RequestCache($this->clusterConfig());
        //DEL 返回 0 只说明 key 已自然过期，不代表删除失败；与
        //clearGateway()/clearTags() 保持同一套成功判据
        $redis = $this->redisFake(['del' => 0]);
        Redis::shouldReceive('connection')->once()->andReturn($redis);

        $this->assertTrue($cache->delete('users', ['id' => 1]));
        $this->assertCount(1, $redis->calls['del']);
    }

    public function testDeleteReportsFailureOnlyWhenRedisThrows()
    {
        $cache = new RequestCache($this->clusterConfig());
        $redis = $this->redisFake([
            'del' => function () {
                throw new \RuntimeException('connection lost');
            },
        ]);
        Redis::shouldReceive('connection')->once()->andReturn($redis);

        $this->assertFalse($cache->delete('users', ['id' => 1]));
    }

    public function testClearGatewayFlushesOnlyTheBoundConnectionLocalNamespace()
    {
        $cache = new RequestCache($this->clusterConfig([
            'scan_strategy' => 'single_connection',
            'connections' => ['default', 'gz'],
        ]));
        $bound = $cache->cluster('gz');
        $key = $cache->generateKey('users', ['id' => 1]);
        $redis = $this->redisFake(['scan' => ['0', []]]);
        Redis::shouldReceive('connection')->once()->with('gz')->andReturn($redis);

        $store = $this->localCacheFor($cache);
        $store->set($this->localCacheKeyFor($cache, $key), ['region' => 'default']);
        $store->set($this->localCacheKeyFor($bound, $key), ['region' => 'gz']);

        $this->assertTrue($bound->clearGateway('users'));

        //共用一个 LocalCache，但清理只作用于被绑定连接的命名空间，
        //不把其它集群仍然有效的本地副本一起丢掉
        $this->assertNull($store->get($this->localCacheKeyFor($bound, $key)));
        $this->assertSame(['region' => 'default'], $store->get($this->localCacheKeyFor($cache, $key)));
    }

    public function testKeyDistributionShapeDoesNotDependOnScanStrategy()
    {
        $redis = $this->redisFake([
            'scan' => ['0', ['{request-cache}:2.0:users:first']],
        ]);
        Redis::shouldReceive('connection')->once()->andReturn($redis);
        $monitor = new CacheMonitor($this->clusterConfig([
            'hash_tag' => 'request-cache',
            'scan_strategy' => 'single_connection',
        ]));

        $distribution = $monitor->getKeyDistribution();

        //过去 single_connection 直接返回扁平的 version=>gateway=>count，
        //调用方没法用同一套代码同时消费两种策略的返回值
        $this->assertSame('current_connection', $distribution['scope']);
        $this->assertSame(1, $distribution['distribution']['2.0']['users']);
        $this->assertArrayHasKey('nodes', $distribution);
        $this->assertArrayHasKey('failed_nodes', $distribution);
    }

    public function testMonitorSkipsBookkeepingKeysWhenCountingAndGrouping()
    {
        $keys = [
            '{request-cache}:2.0:users:first',
            '{request-cache}:stats:hits',
            '{request-cache}:stats:hits:2026-09-02',
            '{request-cache}:tags:users',
            '{request-cache}:lock:' . hash('sha256', 'x'),
        ];
        $redis = $this->redisFake(['scan' => ['0', $keys]]);
        Redis::shouldReceive('connection')->twice()->andReturn($redis, $redis);
        $monitor = new CacheMonitor($this->clusterConfig([
            'hash_tag' => 'request-cache',
            'scan_strategy' => 'single_connection',
        ]));

        //统计/标签/锁与缓存值同前缀，算进来会虚报缓存条目数
        $this->assertSame(1, $monitor->getCacheKeyCount());
        $this->assertSame(
            ['2.0' => ['users' => 1]],
            $monitor->getKeyDistribution()['distribution']
        );
    }

    public function testMonitorMemoryReadPassesAClusterRouteKeyOnSingleConnection()
    {
        $redis = $this->redisFake(['info' => ['used_memory' => 1024]]);
        Redis::shouldReceive('connection')->once()->andReturn($redis);
        $monitor = new CacheMonitor($this->clusterConfig([
            'hash_tag' => 'request-cache',
            'scan_strategy' => 'single_connection',
        ]));

        $monitor->getMemoryUsage();

        //不传路由 key 时适配器会退回硬编码的 {request-cache}:ping，
        //读数来自与本应用 hash_tag 无关的节点
        $this->assertSame([['memory']], $redis->calls['info']);
    }

    public function testFloatValuesKeepTheirTypeThroughTheEnvelope()
    {
        $cache = new RequestCache($this->clusterConfig());
        $reflection = new ReflectionClass($cache);
        $encode = $reflection->getMethod('encodeStoredValue');
        $encode->setAccessible(true);
        $decode = $reflection->getMethod('decodeStoredValue');
        $decode->setAccessible(true);

        $encoded = $encode->invoke($cache, ['price' => 1251.0, 'qty' => 2], 60);
        $entry = $decode->invoke($cache, $encoded);

        //缺少 JSON_PRESERVE_ZERO_FRACTION 时 1251.0 会被写成 1251，读回来变成 int
        $this->assertIsFloat($entry['data']['price']);
        $this->assertSame(1251.0, $entry['data']['price']);
        $this->assertIsInt($entry['data']['qty']);
    }

    public function testParamsThatDifferOnlyInNumericTypeGenerateDistinctKeys()
    {
        $cache = new RequestCache($this->clusterConfig());

        $this->assertNotSame(
            $cache->generateKey('users', ['id' => 1]),
            $cache->generateKey('users', ['id' => 1.0])
        );
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
                'redis_cluster' => array_merge([
                    'enabled' => true,
                    'hash_tag' => null,
                    'cluster_safe_mode' => true,
                    'scan_strategy' => 'single_connection',
                    'default_connection' => 'default',
                    'connections' => [],
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

    private function connectionNameFor(RequestCache $cache)
    {
        $reflection = new ReflectionClass($cache);
        $property = $reflection->getProperty('connectionName');
        $property->setAccessible(true);

        return $property->getValue($cache);
    }

    private function tagsFor(RequestCache $cache)
    {
        $reflection = new ReflectionClass($cache);
        $property = $reflection->getProperty('tags');
        $property->setAccessible(true);

        return $property->getValue($cache);
    }

    private function clusterResolverFor(RequestCache $cache)
    {
        $reflection = new ReflectionClass($cache);
        $property = $reflection->getProperty('clusterNodeResolver');
        $property->setAccessible(true);

        return $property->getValue($cache);
    }

    private function localCacheExpiresFor(RequestCache $cache, string $localKey)
    {
        $localCache = $this->localCacheFor($cache);
        $reflection = new ReflectionClass($localCache);
        $property = $reflection->getProperty('expires');
        $property->setAccessible(true);

        return $property->getValue($localCache)[$localKey] ?? null;
    }

    private function localCacheKeyFor(RequestCache $cache, string $key): string
    {
        $reflection = new ReflectionClass($cache);
        $method = $reflection->getMethod('localCacheKey');
        $method->setAccessible(true);

        return $method->invoke($cache, $key);
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
