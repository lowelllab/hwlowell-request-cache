<?php

namespace HwlowellRequestCache;

class CacheConfig
{
    public const SCAN_STRATEGY_SINGLE_CONNECTION = 'single_connection';
    public const SCAN_STRATEGY_ALL_NODES = 'all_nodes';
    public const DEFAULT_CONNECTION = 'default';

    /**
     * 缓存策略
     */
    public static $strategy = [
        'primary' => 'redis', //主缓存
        'fallback' => true, //启用降级，Redis 失败时回落进程内 LocalCache
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
        'default_connection' => self::DEFAULT_CONNECTION, //未显式绑定集群时使用的连接名
        'connections' => [], //允许手动切换的连接白名单，空数组表示自动推导
    ];
    
    /**
     * RediSearch 配置
     *
     * @deprecated 本包不再自带 RediSearchService，这里只保留 index_name 供宿主
     *             项目自行读取；检索能力请改用 cmsig/seal 系列扩展。
     */
    public static $redisSearch = [
        'index_name' => 'request_cache_index', // 索引名称
    ];

    /**
     * 当前实例的配置覆盖层
     * @var array
     */
    protected $overrides;

    /**
     * 构造实例级配置：只记录传入的配置，读取时叠加在当前全局配置之上，不回写静态属性
     *
     * 静态属性是整个应用共享的默认值。把构造实例时传入的配置直接写进静态属性，
     * 会让一个临时实例的配置泄漏给容器单例和其它实例。反过来，读取时才叠加而
     * 不是构造时快照，是为了让运行时改动全局配置（如 setStrategy()）仍能对
     * 没有显式覆盖该段的实例生效。
     *
     * @param array $config
     */
    public function __construct(array $config = [])
    {
        $this->overrides = $config;

        //提前解析一次，让非法配置在构造时就抛错，而不是等到第一次读取
        $this->sections();
    }

    /**
     * 构造实例级配置
     * @param array $config
     * @return static
     */
    public static function make(array $config = [])
    {
        return new static($config);
    }

    /**
     * 读取当前全局配置快照
     * @return array
     */
    protected static function currentSections(): array
    {
        return [
            'strategy' => self::$strategy,
            'localCache' => self::$localCache,
            'lock' => self::$lock,
            'stats' => self::$stats,
            'redisCluster' => self::$redisCluster,
            'redisSearch' => self::$redisSearch,
        ];
    }

    /**
     * 把配置数组叠加到给定的基准配置上
     * @param array $base
     * @param array $config
     * @return array
     */
    protected static function resolveSections(array $base, array $config): array
    {
        $cacheConfig = $config['cache'] ?? [];
        if (!is_array($cacheConfig)) {
            $cacheConfig = [];
        }

        if (isset($cacheConfig['strategy'])) {
            $base['strategy'] = array_merge($base['strategy'], $cacheConfig['strategy']);
        }

        if (isset($cacheConfig['local_cache'])) {
            $base['localCache'] = array_merge($base['localCache'], $cacheConfig['local_cache']);
        }

        if (isset($cacheConfig['lock'])) {
            $base['lock'] = array_merge($base['lock'], self::normalizeLockConfig($cacheConfig['lock']));
        }

        if (isset($cacheConfig['stats'])) {
            $base['stats'] = array_merge($base['stats'], self::normalizeStatsConfig($cacheConfig['stats']));
        }

        if (isset($cacheConfig['redis_cluster'])) {
            $base['redisCluster'] = self::normalizeRedisClusterConfig(array_merge(
                $base['redisCluster'],
                $cacheConfig['redis_cluster']
            ));
        }

        if (isset($config['redis_search'])) {
            $base['redisSearch'] = array_merge($base['redisSearch'], $config['redis_search']);
        }

        return $base;
    }

    /**
     * 归一化锁配置，兼容下划线写法
     * @param array $lockConfig
     * @return array
     */
    protected static function normalizeLockConfig(array $lockConfig): array
    {
        $aliases = [
            'retry_times' => 'retryTimes',
            'retry_delay' => 'retryDelay',
            'enable_extend' => 'enableExtend',
            'extend_interval' => 'extendInterval',
        ];

        foreach ($aliases as $snake => $camel) {
            if (isset($lockConfig[$snake])) {
                $lockConfig[$camel] = $lockConfig[$snake];
            }
        }

        return $lockConfig;
    }

    /**
     * 归一化统计配置，兼容下划线写法
     * @param array $statsConfig
     * @return array
     */
    protected static function normalizeStatsConfig(array $statsConfig): array
    {
        $aliases = [
            'global_expire' => 'globalExpire',
            'daily_expire' => 'dailyExpire',
        ];

        foreach ($aliases as $snake => $camel) {
            if (isset($statsConfig[$snake])) {
                $statsConfig[$camel] = $statsConfig[$snake];
            }
        }

        return $statsConfig;
    }

    /**
     * 解析当前实例生效的各段配置
     * @return array
     */
    protected function sections(): array
    {
        $current = self::currentSections();

        return $this->overrides === []
            ? $current
            : self::resolveSections($current, $this->overrides);
    }

    /**
     * 获取实例级缓存策略
     * @return array
     */
    public function strategy(): array
    {
        return $this->sections()['strategy'];
    }

    /**
     * 获取实例级本地缓存配置
     * @return array
     */
    public function localCache(): array
    {
        return $this->sections()['localCache'];
    }

    /**
     * 获取实例级分布式锁配置
     * @return array
     */
    public function lock(): array
    {
        return $this->sections()['lock'];
    }

    /**
     * 获取实例级统计配置
     * @return array
     */
    public function stats(): array
    {
        return $this->sections()['stats'];
    }

    /**
     * 获取实例级 Redis Cluster 配置
     * @return array
     */
    public function redisCluster(): array
    {
        return $this->sections()['redisCluster'];
    }

    /**
     * 获取实例级 RediSearch 配置
     * @deprecated 见 self::$redisSearch
     * @return array
     */
    public function rediSearch(): array
    {
        return $this->sections()['redisSearch'];
    }


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

        $defaultConnection = $config['default_connection'] ?? self::DEFAULT_CONNECTION;
        $defaultConnection = is_string($defaultConnection) ? trim($defaultConnection) : '';
        $config['default_connection'] = $defaultConnection === ''
            ? self::DEFAULT_CONNECTION
            : $defaultConnection;

        $connections = $config['connections'];
        if (!is_array($connections)) {
            throw new \InvalidArgumentException('request_cache.cache.redis_cluster.connections must be an array.');
        }

        $normalizedConnections = [];
        foreach ($connections as $connectionName) {
            if (!is_string($connectionName)) {
                continue;
            }

            $connectionName = trim($connectionName);
            if ($connectionName === '' || in_array($connectionName, $normalizedConnections, true)) {
                continue;
            }

            $normalizedConnections[] = $connectionName;
        }
        $config['connections'] = $normalizedConnections;

        return $config;
    }

    /**
     * 从配置文件加载全局配置
     *
     * 这是唯一会改写静态属性的入口，供应用启动时（ServiceProvider）显式调用。
     *
     * @param array $config
     */
    public static function loadFromConfig(array $config)
    {
        $sections = self::resolveSections(self::currentSections(), $config);

        self::$strategy = $sections['strategy'];
        self::$localCache = $sections['localCache'];
        self::$lock = $sections['lock'];
        self::$stats = $sections['stats'];
        self::$redisCluster = $sections['redisCluster'];
        self::$redisSearch = $sections['redisSearch'];
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
     * @deprecated 见 self::$redisSearch
     * @return array
     */
    public static function getRediSearchConfig()
    {
        return self::$redisSearch;
    }
    
    /**
     * 设置 RediSearch 配置
     * @deprecated 见 self::$redisSearch
     * @param array $config
     */
    public static function setRediSearchConfig(array $config)
    {
        self::$redisSearch = array_merge(self::$redisSearch, $config);
    }
}
