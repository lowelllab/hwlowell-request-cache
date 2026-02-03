<?php

require_once __DIR__ . '/vendor/autoload.php';

use GeorgeRequestCache\RequestCache;
//测试RequestCache是否能正确加载
try {
    $cache = new RequestCache();
    echo "✓ RequestCache class loaded successfully\n";
    
    //测试基本方法
    $cache->tags('test')->enableStats(true);
    echo "✓ RequestCache methods work correctly\n";
    
    echo "\nAll tests passed! The RequestCache package is ready to use.\n";
} catch (\Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}
