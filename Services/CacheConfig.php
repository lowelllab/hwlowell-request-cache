<?php

namespace HwlowellRequestCache;

use Illuminate\Support\Facades\Config;

class CacheConfig
{
    /**
     * 缓存策略
     */
    public static $strategy = [
        'primary' => 'redis', //主缓存
        'secondary' => 'array', //备用缓存
        'fallback' => true, //启用降级
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
     * Redis 连接池配置
     */
    public static $redisPool = [
        'enabled' => true, //启用连接池
        'max_connections' => 10, //最大连接数
        'min_connections' => 2, //最小连接数
        'connection_timeout' => 5, //连接超时时间（秒）
        'retry_attempts' => 3, //重试次数
        'retry_delay' => 1000, //重试延迟（毫秒）
        'idle_timeout' => 30, //空闲超时时间（秒）
        'health_check_interval' => 60, //健康检查间隔（秒）
    ];
    
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
                self::$lock = array_merge(self::$lock, $cacheConfig['lock']);
            }
            
            if (isset($cacheConfig['stats'])) {
                self::$stats = array_merge(self::$stats, $cacheConfig['stats']);
            }
            
            if (isset($cacheConfig['redis_pool'])) {
                self::$redisPool = array_merge(self::$redisPool, $cacheConfig['redis_pool']);
            }
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
     * 获取 Redis 连接池配置
     * @return array
     */
    public static function getRedisPoolConfig()
    {
        return self::$redisPool;
    }
    
    /**
     * 设置 Redis 连接池配置
     * @param array $config
     */
    public static function setRedisPoolConfig(array $config)
    {
        self::$redisPool = array_merge(self::$redisPool, $config);
    }
}
