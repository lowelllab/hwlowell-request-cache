<?php

/**
 * RequestCache 配置文件
 */

return [
    /*
    |--------------------------------------------------------------------------
    | RequestCache 基础配置
    |--------------------------------------------------------------------------
    */
    'request_cache' => [
        //缓存前缀
        'prefix' => null, //默认为 appName_appEnv_cache:

        // 默认过期时间（分钟）
        'default_expire' => 5,

        //强制校验字符开关
        'force_validate' => false,

        //启用缓存统计
        'enable_stats' => false,

        //启用包内诊断日志（默认关闭，避免生产刷屏；排查降级/编码失败时可临时打开）
        'enable_logging' => false,

        //加密缓存数据
        'encrypt_data' => false,

        //缓存版本
        'version' => '1.0',

        //缓存大小限制（字节）
        'size_limit' => 1048576, //1MB
    ],

    /*
    |--------------------------------------------------------------------------
    | 过滤配置
    |--------------------------------------------------------------------------
    */
    'filter' => [
        //SQL 关键字列表
        'sql_keywords' => [
            'SELECT', 'INSERT', 'UPDATE', 'DELETE', 'FROM', 'WHERE', 'JOIN', 'ORDER', 'GROUP', 'LIMIT', 'OFFSET',
            'HAVING', 'UNION', 'DISTINCT', 'AS', 'ON', 'IN', 'NOT', 'OR', 'AND', 'LIKE', 'BETWEEN', 'IS', 'NULL',
            'EXEC', 'EXECUTE', 'SP_EXECUTE', 'CALL', 'DROP', 'CREATE', 'ALTER', 'TRUNCATE', 'RENAME', 'GRANT', 'REVOKE',
            'INDEX', 'VIEW', 'PROCEDURE', 'FUNCTION', 'TRIGGER', 'EVENT', 'TABLE', 'DATABASE', 'SCHEMA'
        ],

        //是否移除 HTML 标签
        'remove_html_tags' => true,

        //是否去除首尾空格
        'trim_whitespace' => true,

        //自定义过滤规则
        'custom_filters' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | 缓存策略配置
    |--------------------------------------------------------------------------
    */
    'cache' => [
        //缓存策略
        'strategy' => [
            'primary' => 'redis', //主缓存
            'fallback' => true, //启用降级，Redis 失败时回落进程内 LocalCache
            'shared_mode' => false, //共享模式：Redis 写失败时禁止写入本地缓存并返回成功
        ],

        //本地缓存配置
        'local_cache' => [
            'ttl' => 300, //本地缓存过期时间（秒）
            'size' => 1000, //本地缓存最大条目数
        ],

        //分布式锁配置
        'lock' => [
            'expire' => 5, //锁过期时间（秒）
            'retry_times' => 3, //重试次数
            'retry_delay' => 100000, //重试延迟（微秒）
            'enable_extend' => true, //启用锁续期
            'extend_interval' => 2, //续期间隔（秒）
        ],

        //统计数据配置
        'stats' => [
            'enabled' => true, //启用统计
            'global_expire' => 30 * 24 * 3600, //全局统计过期时间（秒）
            'daily_expire' => 90 * 24 * 3600, //每日统计过期时间（秒）
        ],

        //Redis Cluster 原生分片兼容配置
        'redis_cluster' => [
            'enabled' => false, //启用后使用 Redis hash tag 规避 CROSSSLOT
            'hash_tag' => null, //为空时自动使用 appName_appEnv_cache
            'cluster_safe_mode' => true, //多 key 操作逐 key 兜底
            'scan_strategy' => 'single_connection', //single_connection=当前连接扫描；all_nodes=遍历 Laravel Redis Cluster 配置中的节点扫描
            'default_connection' => 'default', //未显式指定集群时使用的 Laravel Redis 连接名
            'connections' => [], //允许手动切换的连接白名单，留空表示按 database.redis 自动推导
        ],
    ],
    /*
    |--------------------------------------------------------------------------
    | RediSearch 索引名（本包不消费）
    |--------------------------------------------------------------------------
    | RediSearchService 已不随包提供，检索能力请改用 cmsig/seal 系列扩展。
    | 此项仅保留给宿主项目自行读取，包内不会使用它。
    */
    'redis_search' => [
        'index_name' => (function_exists('env') ? env('APP_ENV', 'local') : (getenv('APP_ENV') ?: 'local')).'_request_cache', //索引名称前缀
    ],
];
