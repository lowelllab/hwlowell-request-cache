<?php

/**
 * RequestCache 配置使用示例
 */

require_once __DIR__ . '/Services/RequestCache.php';
require_once __DIR__ . '/Services/FilterConfig.php';
require_once __DIR__ . '/Services/CacheConfig.php';
require_once __DIR__ . '/Services/LocalCache.php';
require_once __DIR__ . '/Services/RedisConnectionPool.php';

// 使用命名空间
use GeorgeRequestCache\RequestCache;
use GeorgeRequestCache\FilterConfig;
use GeorgeRequestCache\CacheConfig;

// 加载配置文件
$config = require __DIR__ . '/config/request_cache.php';

// 加载配置到 RequestCache
RequestCache::loadConfig($config);

// 创建 RequestCache 实例（可选传入配置）
$requestCache = new RequestCache($config);

// 示例：使用配置后的 RequestCache

echo "配置加载成功！\n";

// 验证 FilterConfig 配置
$filterConfig = [
    'sqlKeywords' => FilterConfig::getSqlKeywords(),
    'removeHtmlTags' => FilterConfig::shouldRemoveHtmlTags(),
    'trimWhitespace' => FilterConfig::shouldTrimWhitespace()
];
echo "\nFilterConfig 配置: " . json_encode($filterConfig, JSON_PRETTY_PRINT) . "\n";

// 验证 CacheConfig 配置
$cacheConfig = [
    'strategy' => CacheConfig::getStrategy(),
    'redisPool' => CacheConfig::getRedisPoolConfig()
];
echo "\nCacheConfig 配置: " . json_encode($cacheConfig, JSON_PRETTY_PRINT) . "\n";

// 验证配置是否正确加载
echo "\n配置验证结果：\n";
echo "- FilterConfig SQL关键字数量: " . count($filterConfig['sqlKeywords']) . "\n";
echo "- FilterConfig 移除HTML标签: " . ($filterConfig['removeHtmlTags'] ? '是' : '否') . "\n";
echo "- CacheConfig 主缓存策略: " . $cacheConfig['strategy']['primary'] . "\n";
echo "- CacheConfig Redis连接池启用: " . ($cacheConfig['redisPool']['enabled'] ? '是' : '否') . "\n";

echo "\n示例完成！配置文件已成功加载并应用。\n";
