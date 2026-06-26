<?php

namespace HwlowellRequestCache;

use Illuminate\Support\Facades\Config;

class CacheConfig
{
    public const SCAN_STRATEGY_SINGLE_CONNECTION = 'single_connection';
    public const SCAN_STRATEGY_ALL_NODES = 'all_nodes';

    /**
     * 缓存策略
     */
    public static $strategy = [
        'primary' => 'redis', //主缓存
        'secondary' => 'array', //备用缓存
        'fallback' => true, //启用降级
        'shared_mode' => false, //共享模式：Redis 写失败时禁止本地写入兜底
    ];
    
    /**
     * 本地缓存配置
     */
    public static $localCache = [
        'ttl' => 300, //本地缓存过期时间（秒）
        'size' => 1000, //本地缓存最大条目数
    ];
    
    /**
     * 分布式锁配置
     */
    public static $lock = [
        'expire' => 5, //锁过期时间（秒）
        'retryTimes' => 3, //重试次数
        'retryDelay' => 100000, //重试延迟（微秒）
        'enableExtend' => true, //启用锁续期
        'extendInterval' => 2, //续期间隔（秒）
    ];
    
    /**
     * 统计数据配置
     */
    public static $stats = [
        'enabled' => true, //启用统计
        'globalExpire' => 30 * 24 * 3600, //全局统计过期时间（秒）
        'dailyExpire' => 90 * 24 * 3600, //每日统计过期时间（秒）
    ];
    
    /**
     * Redis Cluster 配置
     */
    public static $redisCluster = [
        'enabled' => false, //启用 Redis Cluster 原生分片兼容模式
        'hash_tag' => null, //为空时使用 app_env_cache 作为 hash tag
        'cluster_safe_mode' => true, //多 key 操作使用逐 key 兜底
        'scan_strategy' => self::SCAN_STRATEGY_SINGLE_CONNECTION, //当前阶段使用当前连接执行 SCAN
    ];
    
    /**
     * RediSearch 配置
     */
    public static $redisSearch = [
        'enabled' => true, // 启用 RediSearch
        'index_name' => 'request_cache_index', // 索引名称
        'max_results' => 1000, // 最大搜索结果数
        'timeout_ms' => 500, // 搜索超时时间（毫秒）
    ];
    
    /**
     * 归一化 Redis Cluster 配置
     * @param array $config
     * @return array
     */
    protected static function normalizeRedisClusterConfig(array $config): array
    {
        $allowed = [
            self::SCAN_STRATEGY_SINGLE_CONNECTION,
            self::SCAN_STRATEGY_ALL_NODES,
        ];
        $strategy = $config['scan_strategy'] ?? self::SCAN_STRATEGY_SINGLE_CONNECTION;
        $config['scan_strategy'] = in_array($strategy, $allowed, true)
            ? $strategy
            : self::SCAN_STRATEGY_SINGLE_CONNECTION;

        return $config;
    }

    /**
     * 从配置文件加载配置
     * @param array $config
     */
    public static function loadFromConfig(array $config)
    {
        if (isset($config['cache'])) {
            $cacheConfig = $config['cache'];
            
            if (isset($cacheConfig['strategy'])) {
                self::$strategy = array_merge(self::$strategy, $cacheConfig['strategy']);
            }
            
            if (isset($cacheConfig['local_cache'])) {
                self::$localCache = array_merge(self::$localCache, $cacheConfig['local_cache']);
            }
            
            if (isset($cacheConfig['lock'])) {
                $lockConfig = $cacheConfig['lock'];
                if (isset($lockConfig['retry_times'])) {
                    $lockConfig['retryTimes'] = $lockConfig['retry_times'];
                }
                if (isset($lockConfig['retry_delay'])) {
                    $lockConfig['retryDelay'] = $lockConfig['retry_delay'];
                }
                if (isset($lockConfig['enable_extend'])) {
                    $lockConfig['enableExtend'] = $lockConfig['enable_extend'];
                }
                if (isset($lockConfig['extend_interval'])) {
                    $lockConfig['extendInterval'] = $lockConfig['extend_interval'];
                }
                self::$lock = array_merge(self::$lock, $lockConfig);
            }
            
            if (isset($cacheConfig['stats'])) {
                $statsConfig = $cacheConfig['stats'];
                if (isset($statsConfig['global_expire'])) {
                    $statsConfig['globalExpire'] = $statsConfig['global_expire'];
                }
                if (isset($statsConfig['daily_expire'])) {
                    $statsConfig['dailyExpire'] = $statsConfig['daily_expire'];
                }
                self::$stats = array_merge(self::$stats, $statsConfig);
            }
            
            if (isset($cacheConfig['redis_cluster'])) {
                self::$redisCluster = self::normalizeRedisClusterConfig(array_merge(
                    self::$redisCluster,
                    $cacheConfig['redis_cluster']
                ));
            }
        }

        if (isset($config['redis_search'])) {
            self::$redisSearch = array_merge(self::$redisSearch, $config['redis_search']);
        }
    }
    
    /**
     * 获取缓存策略
     * @return array
     */
    public static function getStrategy()
    {
        return self::$strategy;
    }
    
    /**
     * 获取本地缓存配置
     * @return array
     */
    public static function getLocalCacheConfig()
    {
        return self::$localCache;
    }
    
    /**
     * 获取分布式锁配置
     * @return array
     */
    public static function getLockConfig()
    {
        return self::$lock;
    }
    
    /**
     * 获取统计数据配置
     * @return array
     */
    public static function getStatsConfig()
    {
        return self::$stats;
    }
    
    /**
     * 设置缓存策略
     * @param array $strategy
     */
    public static function setStrategy(array $strategy)
    {
        self::$strategy = array_merge(self::$strategy, $strategy);
    }
    
    /**
     * 设置本地缓存配置
     * @param array $config
     */
    public static function setLocalCacheConfig(array $config)
    {
        self::$localCache = array_merge(self::$localCache, $config);
    }
    
    /**
     * 设置分布式锁配置
     * @param array $config
     */
    public static function setLockConfig(array $config)
    {
        self::$lock = array_merge(self::$lock, $config);
    }
    
    /**
     * 设置统计数据配置
     * @param array $config
     */
    public static function setStatsConfig(array $config)
    {
        self::$stats = array_merge(self::$stats, $config);
    }
    
    /**
     * 获取 Redis Cluster 配置
     * @return array
     */
    public static function getRedisClusterConfig()
    {
        return self::$redisCluster;
    }

    /**
     * 设置 Redis Cluster 配置
     * @param array $config
     */
    public static function setRedisClusterConfig(array $config)
    {
        self::$redisCluster = self::normalizeRedisClusterConfig(array_merge(self::$redisCluster, $config));
    }
    
    /**
     * 获取 RediSearch 配置
     * @return array
     */
    public static function getRediSearchConfig()
    {
        return self::$redisSearch;
    }
    
    /**
     * 设置 RediSearch 配置
     * @param array $config
     */
    public static function setRediSearchConfig(array $config)
    {
        self::$redisSearch = array_merge(self::$redisSearch, $config);
    }
}
