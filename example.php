<?php

use HwlowellRequestCache\RequestCache;

require __DIR__ . '/vendor/autoload.php';

// 初始化缓存
$cache = new RequestCache();

echo "=== 测试 RediSearch 功能 ===\n";

// 示例 1: 索引文档
echo "\n1. 索引文档...";
$cache->indexSearch('doc1', [
    'title' => '测试文档',
    'content' => '这是一个测试文档，用于测试 RediSearch 功能',
    'created_at' => date('Y-m-d H:i:s'),
    'score' => 100
]);
$cache->indexSearch('doc2', [
    'title' => 'Redis 缓存',
    'content' => 'Redis 是一个高性能的内存数据库，常用于缓存',
    'created_at' => date('Y-m-d H:i:s'),
    'score' => 90
]);
$cache->indexSearch('doc3', [
    'title' => 'Laravel 框架',
    'content' => 'Laravel 是一个流行的 PHP 框架，提供了丰富的功能',
    'created_at' => date('Y-m-d H:i:s'),
    'score' => 85
]);
echo " 完成\n";

// 示例 2: 搜索文档
echo "\n2. 搜索文档...";
$results = $cache->search('测试');
echo " 找到 " . count($results) . " 个结果\n";
foreach ($results as $result) {
    echo "   - " . $result['title'] . " (Score: " . $result['score'] . ")\n";
}

// 示例 3: 搜索 Redis 相关文档
echo "\n3. 搜索 Redis 相关文档...";
$results = $cache->search('Redis');
echo " 找到 " . count($results) . " 个结果\n";
foreach ($results as $result) {
    echo "   - " . $result['title'] . " (Score: " . $result['score'] . ")\n";
}

// 示例 4: 删除文档
echo "\n4. 删除文档 doc1...";
$cache->deleteSearch('doc1');
echo " 完成\n";

// 示例 5: 再次搜索，确认删除
echo "\n5. 再次搜索 '测试'...";
$results = $cache->search('测试');
echo " 找到 " . count($results) . " 个结果\n";

// 示例 6: 清除搜索索引
echo "\n6. 清除搜索索引...";
$cache->clearSearch();
echo " 完成\n";

// 示例 7: 确认索引已清除
echo "\n7. 确认索引已清除...";
$results = $cache->search('测试');
echo " 找到 " . count($results) . " 个结果\n";

echo "\n=== 测试完成 ===\n";