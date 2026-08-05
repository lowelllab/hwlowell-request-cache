<?php

namespace HwlowellRequestCache;

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
     * 构造函数
     * @param array|null $config
     */
    public function __construct(array $config = null)
    {
        $config = $config ?? $this->loadConfig();
        RequestCache::loadConfig($config);
        $this->prefix = RequestCache::resolvePrefix($config);
        $this->clusterNodeResolver = new RedisClusterNodeResolver(CacheConfig::getRedisClusterConfig());
    }

    /**
     * 加载缓存配置
     * @return array
     */
    protected function loadConfig()
    {
        try {
            return config('request_cache', []);
        } catch (\Exception $e) {
            $configPath = __DIR__ . '/../config/request_cache.php';
            return file_exists($configPath) ? require $configPath : [];
        }
    }

    /**
     * 获取配置中的默认 Redis 连接名
     */
    protected function defaultConnectionName(): string
    {
        $name = CacheConfig::getRedisClusterConfig()['default_connection'] ?? CacheConfig::DEFAULT_CONNECTION;

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
            $stats = [
                'hits' => (int) $redis->get($this->buildStatsKey('hits')) ?? 0,
                'misses' => (int) $redis->get($this->buildStatsKey('misses')) ?? 0,
                'today_hits' => (int) $redis->get($this->buildStatsKey('hits', $today)) ?? 0,
                'today_misses' => (int) $redis->get($this->buildStatsKey('misses', $today)) ?? 0,
                'cache_keys' => $this->getCacheKeyCount(),
                'memory_usage' => $this->getMemoryUsage(),
                'health_status' => $this->getHealthStatus(),
            ];

            $stats['hit_rate'] = $stats['hits'] + $stats['misses'] > 0
                ? round($stats['hits'] / ($stats['hits'] + $stats['misses']) * 100, 2)
                : 0;

            return $stats;
        } catch (\Exception $e) {
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
            $pattern = $this->prefix . '*';
            $keys = $this->scanKeys($pattern);
            return count($keys);
        } catch (\Exception $e) {
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
                $info = $this->connection()->info('memory');
                return array_merge(['scope' => 'current_connection'], $this->normalizeMemoryInfo($info));
            }

            $connections = $this->clusterNodeResolver->scanConnections();
            $nodes = [];
            $failed = [];
            $total = 0;
            $maxmemory = 0;

            foreach ($connections as $name => $redis) {
                try {
                    $info = $redis->info('memory');
                    $nodes[$name] = $this->normalizeMemoryInfo($info);
                    $total += (int) ($info['used_memory'] ?? 0);
                    $maxmemory += (int) ($info['maxmemory'] ?? 0);
                } catch (\Exception $e) {
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
        } catch (\Exception $e) {
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
        try {
            if ($this->clusterNodeResolver->isAllNodesStrategy()) {
                $connections = $this->clusterNodeResolver->scanConnections();
                $healthyNodes = 0;
                $failedNodes = 0;

                foreach ($connections as $redis) {
                    try {
                        $pong = $redis->ping();
                        if ($pong === 'PONG' || $pong === true || $pong === '+PONG') {
                            $healthyNodes++;
                        } else {
                            $failedNodes++;
                        }
                    } catch (\Exception $e) {
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
                $pong = $this->connection()->ping();
                if ($pong !== 'PONG') {
                    return 'unavailable';
                }
            }

            //检查内存使用情况
            $memory = $this->getMemoryUsage();
            if (isset($memory['maxmemory']) && $memory['maxmemory'] > 0) {
                $usedPercent = ($memory['used_memory'] / $memory['maxmemory']) * 100;
                if ($usedPercent > 90) {
                    return 'critical';
                } elseif ($usedPercent > 75) {
                    return 'warning';
                }
            }

            //检查缓存键数量
            $keyCount = $this->getCacheKeyCount();
            if ($keyCount > 100000) {
                return 'warning';
            }

            return 'healthy';
        } catch (\Exception $e) {
            return 'error';
        }
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
        } catch (\Exception $e) {
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
            $scan = $this->scanKeys($this->prefix . '*', 1000, true);
            $keys = $scan['keys'];

            $distribution = [];
            foreach ($keys as $key) {
                [$version, $gateway] = $this->parseCacheKeyParts($key);
                if ($version === null || $gateway === null) {
                    continue;
                }

                if (!isset($distribution[$version])) {
                    $distribution[$version] = [];
                }

                if (!isset($distribution[$version][$gateway])) {
                    $distribution[$version][$gateway] = 0;
                }

                $distribution[$version][$gateway]++;
            }

            if (!$this->clusterNodeResolver->isAllNodesStrategy()) {
                return $distribution;
            }

            return [
                'scope' => $scan['scope'],
                'distribution' => $distribution,
                'nodes' => $scan['nodes'],
                'failed_nodes' => $scan['failed_nodes'],
            ];
        } catch (\Exception $e) {
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
        } catch (\Exception $e) {
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
        } catch (\Exception $e) {
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
    protected function scanKeys(string $pattern, int $count = 1000, bool $withMeta = false)
    {
        $keys = [];
        $nodes = [];
        $failed = [];
        $redisPrefix = $this->redisPrefix();
        $connections = $this->clusterNodeResolver->scanConnections();

        foreach ($connections as $name => $redis) {
            $cursor = '0';
            $iterations = 0;
            $nodeKeys = [];

            try {
                do {
                    $result = $redis->scan($cursor, ['match' => $redisPrefix . $pattern, 'count' => $count]);
                    if ($result === false) {
                        break;
                    }

                    $cursor = $result[0];
                    $batch = $result[1];
                    $nodeKeys = array_merge($nodeKeys, $batch);
                    $iterations++;
                } while ($cursor != '0');

                $keys = array_merge($keys, $nodeKeys);
                $nodes[$name] = [
                    'matched_keys' => count($nodeKeys),
                    'scan_iterations' => $iterations,
                ];
            } catch (\Exception $e) {
                $failed[$name] = $e->getMessage();
            }
        }

        $keys = array_values(array_unique($keys));

        if (!$withMeta) {
            return $keys;
        }

        return [
            'keys' => $keys,
            'scope' => $this->scanScope($failed),
            'nodes' => $nodes,
            'failed_nodes' => $failed,
        ];
    }
}
