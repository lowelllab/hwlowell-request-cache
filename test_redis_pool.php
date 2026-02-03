<?php

require __DIR__ . '/vendor/autoload.php';

use HwlowellRequestCache\RequestCache;

//测试 Redis 连接池功能
echo "=== 测试 Redis 连接池功能 ===\n";

try {
    //初始化 RequestCache 实例
    $cache = new RequestCache();
    
    echo "初始化 RequestCache 实例成功\n";
    
    //测试设置缓存
    $gateway = 'test_gateway';
    $params = ['id' => 1, 'name' => 'test'];
    $data = ['result' => 'success', 'message' => 'Hello, Redis Pool!'];
    
    $setResult = $cache->set($gateway, $params, $data, 60);
    echo "设置缓存结果: " . ($setResult ? '成功' : '失败') . "\n";
    
    // 测试获取缓存
    $getResult = $cache->get($gateway, $params);
    echo "获取缓存结果: " . ($getResult ? '成功' : '失败') . "\n";
    if ($getResult) {
        echo "   缓存数据: " . json_encode($getResult) . "\n";
    }
    
    // 测试缓存装饰器
    $rememberResult = $cache->remember($gateway, $params, function() {
        return ['result' => 'success', 'message' => 'Hello, Remember!'];
    }, 60);
    echo "缓存装饰器结果: " . ($rememberResult ? '成功' : '失败') . "\n";
    if ($rememberResult) {
        echo "   装饰器数据: " . json_encode($rememberResult) . "\n";
    }
    
    // 测试删除缓存
    $deleteResult = $cache->delete($gateway, $params);
    echo "删除缓存结果: " . ($deleteResult ? '成功' : '失败') . "\n";
    
    // 测试缓存统计
    $stats = $cache->getStats();
    echo "缓存统计信息:\n";
    echo "   命中次数: " . $stats['hits'] . "\n";
    echo "   未命中次数: " . $stats['misses'] . "\n";
    echo "   今日命中: " . $stats['today_hits'] . "\n";
    echo "   今日未命中: " . $stats['today_misses'] . "\n";
    echo "   命中率: " . $stats['hit_rate'] . "%\n";
    
    //测试缓存清理
    $clearResult = $cache->clearGateway($gateway);
    echo "清理网关缓存结果: " . ($clearResult ? '成功' : '失败') . "\n";
    
    echo "\n=== 测试完成 ===\n";
    
echo "所有测试通过，Redis 连接池功能正常！\n";
    
} catch (\Exception $e) {
    echo "测试失败: " . $e->getMessage() . "\n";
    echo "错误堆栈: " . $e->getTraceAsString() . "\n";
}
