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
        'force_validate' => true,
        
        //启用缓存统计
        'enable_stats' => false,
        
        //加密缓存数据
        'encrypt_data' => true,
        
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
            'secondary' => 'array', //备用缓存
            'fallback' => true, //启用降级
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
        
        //Redis 连接池配置
        'redis_pool' => [
            'enabled' => true, //禁用连接池（非Laravel环境）
            'max_connections' => 10, //最大连接数
            'min_connections' => 2, //最小连接数
            'connection_timeout' => 5, //连接超时时间（秒）
            'retry_attempts' => 3, //重试次数
            'retry_delay' => 1000, //重试延迟（毫秒）
            'idle_timeout' => 30, //空闲超时时间（秒）
            'health_check_interval' => 60, //健康检查间隔（秒）
        ],
    ],
];
