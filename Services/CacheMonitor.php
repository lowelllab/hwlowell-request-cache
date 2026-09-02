<?php

namespace HwlowellRequestCache;

use Illuminate\Redis\Connections\PhpRedisClusterConnection;
use Illuminate\Support\Facades\Redis;

class CacheMonitor
{
    /**
     * 缓存监控工具
     */

    /**
     * 缓存前缀
     */
    protected $prefix;

    /**
     * Redis Cluster 节点解析器
     */
    protected $clusterNodeResolver;
    
    /**
     * 当前实例生效的缓存配置
     * @var CacheConfig
     */
    protected $cacheConfig;

    /**
     * 构造函数
     * @param array|null $config
     */
    public function __construct(array $config = null)
    {
        //显式传入的配置只属于当前实例；未传入时读取的是应用全局配置，回写静态属性才是安全的
        $usesApplicationConfig = $config === null;

        $config = $config ?? $this->loadConfig();

        //走应用配置时直接并入全局，实例本身不留覆盖层，这样运行时改全局配置仍能影响该实例
        $overrides = $config;
        if ($usesApplicationConfig) {
            RequestCache::loadConfig($config);
            $overrides = [];
        }

        $this->cacheConfig = CacheConfig::make($overrides);
        $this->prefix = RequestCache::resolvePrefix($config, $this->cacheConfig->redisCluster());
        $this->clusterNodeResolver = new RedisClusterNodeResolver($this->cacheConfig->redisCluster());
    }

    /**
     * 加载缓存配置
     * @return array
     */
    protected function loadConfig()
    {
        try {
            return config('request_cache', []);
        } catch (\Throwable $e) {
            $configPath = __DIR__ . '/../config/request_cache.php';
            return file_exists($configPath) ? require $configPath : [];
        }
    }

    /**
     * 获取配置中的默认 Redis 连接名
     */
    protected function defaultConnectionName(): string
    {
        $name = $this->cacheConfig->redisCluster()['default_connection'] ?? CacheConfig::DEFAULT_CONNECTION;

        return is_string($name) && $name !== '' ? $name : CacheConfig::DEFAULT_CONNECTION;
    }

    /**
     * 获取默认 Redis 连接
     * @return mixed
     */
    protected function connection()
    {
        return Redis::connection($this->defaultConnectionName());
    }

    /**
     * 构建统计 key
     * @param string $type
     * @param string|null $date
     * @return string
     */
    protected function buildStatsKey(string $type, string $date = null)
    {
        return $date === null
            ? "{$this->prefix}stats:{$type}"
            : "{$this->prefix}stats:{$type}:{$date}";
    }

    /**
     * 返回扫描或监控作用域
     * @param array $failedNodes
     * @return string
     */
    protected function scanScope(array $failedNodes = []): string
    {
        if (!$this->clusterNodeResolver->isAllNodesStrategy()
            || $this->clusterNodeResolver->usesCurrentConnectionFallback()) {
            return 'current_connection';
        }

        return empty($failedNodes) ? 'cluster_aggregate' : 'cluster_partial';
    }

    /**
     * 获取缓存统计信息
     * @return array
     */
    public function getStats()
    {
        try {
            $today = date('Y-m-d');
            $redis = $this->connection();

            //键数量与内存各取一次后复用：健康判定此前自己又各取一遍，
            //一次 getStats() 会把整个 keyspace 扫两轮
            $keyCount = $this->getCacheKeyCount();
            $memory = $this->getMemoryUsage();

            $stats = [
                'hits' => (int) $redis->get($this->buildStatsKey('hits')),
                'misses' => (int) $redis->get($this->buildStatsKey('misses')),
                'today_hits' => (int) $redis->get($this->buildStatsKey('hits', $today)),
                'today_misses' => (int) $redis->get($this->buildStatsKey('misses', $today)),
                'cache_keys' => $keyCount,
                'memory_usage' => $memory,
                'health_status' => $this->evaluateHealth(
                    static fn () => $memory,
                    static fn () => $keyCount
                ),
            ];

            $stats['hit_rate'] = $stats['hits'] + $stats['misses'] > 0
                ? round($stats['hits'] / ($stats['hits'] + $stats['misses']) * 100, 2)
                : 0;

            return $stats;
        } catch (\Throwable $e) {
            return [
                'hits' => 0,
                'misses' => 0,
                'today_hits' => 0,
                'today_misses' => 0,
                'hit_rate' => 0,
                'cache_keys' => 0,
                'memory_usage' => 0,
                'health_status' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * 获取缓存键数量
     * @return int
     */
    public function getCacheKeyCount()
    {
        try {
            $count = 0;

            //只累加数量：把整个 keyspace 的 key 收进数组再 count()，
            //在百万级缓存上会把上百万个字符串堆进 PHP 内存
            $this->eachScannedKey(RequestCache::escapeGlobLiteral($this->prefix) . '*', function () use (&$count) {
                $count++;
            });

            return $count;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 获取内存使用情况
     * @return array
     */
    public function getMemoryUsage()
    {
        try {
            if (!$this->clusterNodeResolver->isAllNodesStrategy()) {
                $info = RedisClientAdapter::wrap($this->connection())->info('memory');
                return array_merge(['scope' => 'current_connection'], $this->normalizeMemoryInfo($info));
            }

            $connections = $this->clusterNodeResolver->scanConnections();
            $nodes = [];
            $failed = [];
            $total = 0;
            $maxmemory = 0;

            foreach ($connections as $name => $redis) {
                try {
                    $info = RedisClientAdapter::wrap($redis)->info('memory', $this->prefix . 'ping');
                    $nodes[$name] = $this->normalizeMemoryInfo($info);
                    $total += (int) ($info['used_memory'] ?? 0);
                    $maxmemory += (int) ($info['maxmemory'] ?? 0);
                } catch (\Throwable $e) {
                    $failed[$name] = $e->getMessage();
                }
            }

            if (empty($nodes)) {
                return [
                    'scope' => 'current_connection',
                    'used_memory' => 0,
                    'total_used_memory' => 0,
                    'maxmemory' => 0,
                    'total_maxmemory' => 0,
                    'nodes' => [],
                    'failed_nodes' => $failed,
                ];
            }

            return [
                'scope' => $this->scanScope($failed),
                'used_memory' => $total,
                'total_used_memory' => $total,
                'maxmemory' => $maxmemory,
                'total_maxmemory' => $maxmemory,
                'nodes' => $nodes,
                'failed_nodes' => $failed,
            ];
        } catch (\Throwable $e) {
            return [
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * 标准化 Redis memory 信息
     * @param array $info
     * @return array
     */
    protected function normalizeMemoryInfo(array $info): array
    {
        return [
            'used_memory' => $info['used_memory'] ?? 0,
            'used_memory_human' => $info['used_memory_human'] ?? '0B',
            'used_memory_rss' => $info['used_memory_rss'] ?? 0,
            'used_memory_rss_human' => $info['used_memory_rss_human'] ?? '0B',
            'used_memory_peak' => $info['used_memory_peak'] ?? 0,
            'used_memory_peak_human' => $info['used_memory_peak_human'] ?? '0B',
            'used_memory_lua' => $info['used_memory_lua'] ?? 0,
            'used_memory_lua_human' => $info['used_memory_lua_human'] ?? '0B',
            'maxmemory' => $info['maxmemory'] ?? 0,
            'maxmemory_human' => $info['maxmemory_human'] ?? '0B',
            'maxmemory_policy' => $info['maxmemory_policy'] ?? 'noeviction',
            'mem_fragmentation_ratio' => $info['mem_fragmentation_ratio'] ?? 0,
        ];
    }

    /**
     * 获取缓存健康状态
     * @return string
     */
    public function getHealthStatus()
    {
        //传闭包而不是取好的值：PING 阶段就能判定时不必再去扫 keyspace
        return $this->evaluateHealth(
            fn () => $this->getMemoryUsage(),
            fn () => $this->getCacheKeyCount()
        );
    }

    /**
     * 判定健康状态
     *
     * 内存与键数量以闭包传入：单独调用时按需求值，保留 PING 阶段的短路；
     * getStats() 则传入已取得的值，避免同一次调用里重复扫描整个 keyspace。
     *
     * @param callable $memoryProvider
     * @param callable $keyCountProvider
     * @return string
     */
    protected function evaluateHealth(callable $memoryProvider, callable $keyCountProvider): string
    {
        try {
            if ($this->clusterNodeResolver->isAllNodesStrategy()) {
                $connections = $this->clusterNodeResolver->scanConnections();
                $healthyNodes = 0;
                $failedNodes = 0;

                foreach ($connections as $redis) {
                    try {
                        if ($this->isPong($this->pingConnection($redis))) {
                            $healthyNodes++;
                        } else {
                            $failedNodes++;
                        }
                    } catch (\Throwable $e) {
                        $failedNodes++;
                    }
                }

                if ($healthyNodes === 0) {
                    return 'unavailable';
                }

                if ($failedNodes > 0) {
                    return 'warning';
                }
            }

            //检查 Redis 连接
            if (!$this->clusterNodeResolver->isAllNodesStrategy()) {
                if (!$this->isPong($this->pingConnection($this->connection()))) {
                    return 'unavailable';
                }
            }

            //检查内存使用情况
            $memory = $memoryProvider();
            if (isset($memory['maxmemory']) && $memory['maxmemory'] > 0) {
                $usedPercent = ($memory['used_memory'] / $memory['maxmemory']) * 100;
                if ($usedPercent > 90) {
                    return 'critical';
                } elseif ($usedPercent > 75) {
                    return 'warning';
                }
            }

            //检查缓存键数量
            if ($keyCountProvider() > 100000) {
                return 'warning';
            }

            return 'healthy';
        } catch (\Throwable $e) {
            return 'error';
        }
    }

    /**
     * 对指定连接执行 PING
     *
     * RedisCluster::ping() 必须携带路由目标，无参调用会抛出 ArgumentCountError；
     * 这里用监控自身的 key 前缀定位节点，避免触碰业务 key。
     *
     * @param mixed $redis
     * @return mixed
     */
    protected function pingConnection($redis)
    {
        if ($redis instanceof PhpRedisClusterConnection) {
            return $redis->ping($this->prefix . 'ping');
        }

        return $redis->ping();
    }

    /**
     * 判断 PING 响应是否正常
     * @param mixed $pong
     * @return bool
     */
    protected function isPong($pong): bool
    {
        if (is_bool($pong)) {
            return $pong;
        }

        return is_string($pong) && strtoupper(ltrim($pong, '+')) === 'PONG';
    }

    /**
     * 获取缓存使用趋势
     * @param int $days
     * @return array
     */
    public function getTrend(int $days = 7)
    {
        try {
            $trend = [];
            $redis = $this->connection();

            for ($i = $days - 1; $i >= 0; $i--) {
                $date = date('Y-m-d', strtotime("-{$i} days"));
                $hits = (int) $redis->get($this->buildStatsKey('hits', $date)) ?? 0;
                $misses = (int) $redis->get($this->buildStatsKey('misses', $date)) ?? 0;

                $trend[] = [
                    'date' => $date,
                    'hits' => $hits,
                    'misses' => $misses,
                    'hit_rate' => $hits + $misses > 0 ? round($hits / ($hits + $misses) * 100, 2) : 0,
                ];
            }

            return $trend;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 获取缓存键分布
     * @return array
     */
    public function getKeyDistribution()
    {
        try {
            $distribution = [];

            //边扫边聚合：内存占用取决于 version:gateway 组合数，而不是 key 总量
            $pattern = RequestCache::escapeGlobLiteral($this->prefix) . '*';
            $scan = $this->eachScannedKey($pattern, function ($key) use (&$distribution) {
                [$version, $gateway] = $this->parseCacheKeyParts($key);
                if ($version === null || $gateway === null) {
                    return;
                }

                if (!isset($distribution[$version][$gateway])) {
                    $distribution[$version][$gateway] = 0;
                }

                $distribution[$version][$gateway]++;
            });

            if (!$this->clusterNodeResolver->isAllNodesStrategy()) {
                return $distribution;
            }

            return [
                'scope' => $scan['scope'],
                'distribution' => $distribution,
                'nodes' => $scan['nodes'],
                'failed_nodes' => $scan['failed_nodes'],
            ];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 解析缓存 key 中的版本和 gateway
     * @param string $key
     * @return array
     */
    protected function parseCacheKeyParts(string $key): array
    {
        $redisPrefix = $this->redisPrefix();
        if ($redisPrefix !== '') {
            $key = preg_replace('/^' . preg_quote($redisPrefix, '/') . '/', '', $key);
        }

        $key = preg_replace('/^' . preg_quote($this->prefix, '/') . '/', '', $key);
        $parts = explode(':', $key);

        return count($parts) >= 2 ? [$parts[0], $parts[1]] : [null, null];
    }

    /**
     * 清理过期缓存
     * @return int
     */
    public function cleanExpired()
    {
        //Redis 会自动清理过期缓存，这里可以添加一些自定义的清理逻辑
        //例如清理长时间未访问的缓存等
        return 0;
    }

    /**
     * 获取 Redis 信息
     * @param string $section
     * @return array
     */
    public function getRedisInfo(string $section = 'all')
    {
        try {
            return $this->connection()->info($section);
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * 使用 SCAN 命令获取匹配的键
     * @param string $pattern
     * @param int $count
     * @return array
     */
    protected function redisPrefix(): string
    {
        try {
            return config('database.redis.options.prefix', '');
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * 使用 SCAN 命令获取匹配的键
     * @param string $pattern
     * @param int $count
     * @param bool $withMeta
     * @return array
     */
    protected function eachScannedKey(string $pattern, callable $handler, int $count = 1000): array
    {
        $nodes = [];
        $failed = [];
        $redisPrefix = $this->redisPrefix();
        $connections = $this->clusterNodeResolver->scanConnections();

        foreach ($connections as $name => $redis) {
            $cursor = RequestCache::initialScanCursor($redis);
            $iterations = 0;
            $matched = 0;

            try {
                $matchPattern = RequestCache::escapeGlobLiteral($redisPrefix) . $pattern;

                do {
                    $result = $redis->scan($cursor, ['match' => $matchPattern, 'count' => $count]);
                    if ($result === false) {
                        break;
                    }

                    $cursor = $result[0];
                    foreach ($result[1] as $key) {
                        $matched++;
                        $handler($key, $name);
                    }
                    $iterations++;
                } while (!RequestCache::isScanCursorFinished($cursor));

                $nodes[$name] = [
                    'matched_keys' => $matched,
                    'scan_iterations' => $iterations,
                ];
            } catch (\Throwable $e) {
                $failed[$name] = $e->getMessage();
            }
        }

        return [
            'scope' => $this->scanScope($failed),
            'nodes' => $nodes,
            'failed_nodes' => $failed,
        ];
    }
}
