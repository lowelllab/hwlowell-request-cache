<?php

require_once __DIR__ . '/vendor/autoload.php';

use GeorgeRequestCache\RequestCache;

//初始化 RequestCache 实例
$cache = new RequestCache();

//启用缓存统计，以便测试完成后查看统计信息
$cache->enableStats(true);

echo "开始性能测试...\n";

//测试: 单个缓存操作性能
echo "\n1. 测试单个缓存操作性能...\n";
$startTime = microtime(true);
$iterations = 1000;

for ($i = 0; $i < $iterations; $i++) {
    $gateway = 'test_gateway_' . $i;
    $params = ['id' => $i, 'name' => 'test_name_' . $i, 'data' => ['nested' => 'value_' . $i]];
    $data = ['result' => 'test_result_' . $i, 'time' => time()];
    
    //设置缓存
    $cache->set($gateway, $params, $data);
    
    //获取缓存
    $result = $cache->get($gateway, $params);
}

$endTime = microtime(true);
$singleOpTime = $endTime - $startTime;
echo "单个缓存操作 ($iterations 次): " . number_format($singleOpTime, 4) . " 秒\n";
echo "每次操作平均时间: " . number_format($singleOpTime / ($iterations * 2), 6) . " 秒\n";

//测试: 批量操作性能
echo "\n2. 测试批量操作性能...\n";
$batchSize = 100;
$batches = 10;

$startTime = microtime(true);

for ($b = 0; $b < $batches; $b++) {
    //准备批量操作数据
    $batchItems = [];
    for ($i = 0; $i < $batchSize; $i++) {
        $index = $b * $batchSize + $i;
        $gateway = 'batch_gateway_' . $index;
        $params = ['id' => $index, 'name' => 'batch_name_' . $index];
        $data = ['result' => 'batch_result_' . $index, 'time' => time()];
        $batchItems[] = [$gateway, $params, $data];
    }
    
    //批量设置
    $cache->mset($batchItems);
    
    //批量获取
    $getItems = array_map(function ($item) {
        return [$item[0], $item[1]];
    }, $batchItems);
    $cache->mget($getItems);
}

$endTime = microtime(true);
$batchOpTime = $endTime - $startTime;
$totalBatchOps = $batches * $batchSize * 2;
echo "批量缓存操作 ($totalBatchOps 次): " . number_format($batchOpTime, 4) . " 秒\n";
echo "每次操作平均时间: " . number_format($batchOpTime / $totalBatchOps, 6) . " 秒\n";

//测试: 参数过滤性能
echo "\n3. 测试参数过滤性能...\n";
$startTime = microtime(true);
$filterIterations = 10000;

for ($i = 0; $i < $filterIterations; $i++) {
    //生成复杂参数
    $complexParams = [
        'id' => $i,
        'name' => 'test_name_' . $i . '<script>alert("xss")</script>',
        'description' => 'This is a test description with SQL keywords like SELECT, FROM, WHERE',
        'data' => [
            'nested' => 'value_' . $i . ' javascript:alert("xss")',
            'deep' => [
                'value' => 'deep_value_' . $i,
                'array' => [1, 2, 3, 4, 5]
            ]
        ],
        'number' => rand(-2000000, 2000000)
    ];
    
    //生成缓存键（会触发参数过滤）
    $cache->generateKey('test_filter_gateway', $complexParams);
}

$endTime = microtime(true);
$filterTime = $endTime - $startTime;
echo "参数过滤 ($filterIterations 次): " . number_format($filterTime, 4) . " 秒\n";
echo "每次过滤平均时间: " . number_format($filterTime / $filterIterations, 6) . " 秒\n";

//测试: 锁操作性能
echo "\n4. 测试锁操作性能...\n";
$lockIterations = 100;
$startTime = microtime(true);

for ($i = 0; $i < $lockIterations; $i++) {
    $gateway = 'lock_gateway_' . $i;
    $params = ['id' => $i];
    
    //使用 remember 方法，会触发锁操作
    $result = $cache->remember($gateway, $params, function () use ($i) {
        //模拟耗时操作
        usleep(100);
        return ['result' => 'lock_result_' . $i];
    });
}

$endTime = microtime(true);
$lockTime = $endTime - $startTime;
echo "锁操作 ($lockIterations 次): " . number_format($lockTime, 4) . " 秒\n";
echo "每次锁操作平均时间: " . number_format($lockTime / $lockIterations, 6) . " 秒\n";

//测试: SCAN 操作性能
echo "\n5. 测试 SCAN 操作性能...\n";
//先创建一些缓存项，以便 SCAN 操作有数据可扫描
$scanItems = 1000;
echo "创建 $scanItems 个缓存项用于 SCAN 测试...\n";

for ($i = 0; $i < $scanItems; $i++) {
    $gateway = 'scan_gateway_' . ($i % 10); //使用 10 个不同的网关
    $params = ['id' => $i, 'page' => $i % 5];
    $data = ['result' => 'scan_result_' . $i];
    $cache->set($gateway, $params, $data);
}

//测试清除指定网关的缓存（会触发 SCAN 操作）
$startTime = microtime(true);
$cache->clearGateway('scan_gateway_0');
$endTime = microtime(true);
$scanTime = $endTime - $startTime;
echo "SCAN 操作: " . number_format($scanTime, 4) . " 秒\n";

//测试: 内存使用
echo "\n6. 测试内存使用...\n";
$memoryUsage = memory_get_usage(true);
echo "当前内存使用: " . number_format($memoryUsage / 1024 / 1024, 2) . " MB\n";

//查看缓存统计信息
echo "\n7. 缓存统计信息...\n";
$stats = $cache->getStats();
echo "总命中次数: " . $stats['hits'] . "\n";
echo "总未命中次数: " . $stats['misses'] . "\n";
echo "命中率: " . $stats['hit_rate'] . "%\n";
echo "今日命中次数: " . $stats['today_hits'] . "\n";
echo "今日未命中次数: " . $stats['today_misses'] . "\n";

echo "\n性能测试完成！\n";
