<?php

namespace GeorgeRequestCache;

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
     * 构造函数
     */
    public function __construct()
    {
        $appName = env('APP_NAME', 'laravel');
        $appEnv = env('APP_ENV', 'local');
        $this->prefix = strtolower(str_replace(' ', '_', $appName)) . '_' . $appEnv . '_cache:';
    }

    /**
     * 获取缓存统计信息
     * @return array
     */
    public function getStats()
    {
        try {
            $today = date('Y-m-d');
            $stats = [
                'hits' => (int) Redis::get('cache:stats:hits') ?? 0,
                'misses' => (int) Redis::get('cache:stats:misses') ?? 0,
                'today_hits' => (int) Redis::get('cache:stats:hits:' . $today) ?? 0,
                'today_misses' => (int) Redis::get('cache:stats:misses:' . $today) ?? 0,
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
            $info = Redis::info('memory');
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
        } catch (\Exception $e) {
            return [
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * 获取缓存健康状态
     * @return string
     */
    public function getHealthStatus()
    {
        try {
            //检查 Redis 连接
            $pong = Redis::ping();
            if ($pong !== 'PONG') {
                return 'unavailable';
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

            for ($i = $days - 1; $i >= 0; $i--) {
                $date = date('Y-m-d', strtotime("-{$i} days"));
                $hits = (int) Redis::get('cache:stats:hits:' . $date) ?? 0;
                $misses = (int) Redis::get('cache:stats:misses:' . $date) ?? 0;

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
            $pattern = $this->prefix . '*';
            $keys = $this->scanKeys($pattern);

            $distribution = [];
            foreach ($keys as $key) {
                //提取版本和网关信息
                $parts = explode(':', $key);
                if (count($parts) >= 3) {
                    $version = $parts[1];
                    $gateway = $parts[2];

                    if (!isset($distribution[$version])) {
                        $distribution[$version] = [];
                    }

                    if (!isset($distribution[$version][$gateway])) {
                        $distribution[$version][$gateway] = 0;
                    }

                    $distribution[$version][$gateway]++;
                }
            }

            return $distribution;
        } catch (\Exception $e) {
            return [];
        }
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
            return Redis::info($section);
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
    protected function scanKeys(string $pattern, int $count = 1000)
    {
        $keys = [];
        $cursor = '0';

        do {
            $result = Redis::command('SCAN', [$cursor, 'MATCH', $pattern, 'COUNT', $count]);
            $cursor = $result[0];
            $keys = array_merge($keys, $result[1]);
        } while ($cursor != '0');

        return $keys;
    }
}
