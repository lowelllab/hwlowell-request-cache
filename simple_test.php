<?php

require_once __DIR__ . '/vendor/autoload.php';

use HwlowellRequestCache\RequestCache;

//模拟Laravel函数，避免依赖 Laravel 环境
if (!function_exists('config')) {
    function config($key, $default = null) {
        return $default;
    }
}

if (!function_exists('env')) {
    function env($key, $default = null) {
        return $default;
    }
}

if (!function_exists('decrypt')) {
    function decrypt($value) {
        return $value;
    }
}

if (!function_exists('encrypt')) {
    function encrypt($value) {
        return $value;
    }
}

if (!class_exists('Redis')) {
    //模拟 Redis 类，避免依赖 Redis 扩展
    class Redis {
        public static function connection() {
            return new self();
        }
        
        public function get($key) {
            return null;
        }
        
        public function setex($key, $expire, $value) {
            return true;
        }
        
        public function del($key) {
            return 0;
        }
        
        public function pipeline() {
            return $this;
        }
        
        public function execute() {
            return [];
        }
        
        public function sadd($key, $value) {
            return 0;
        }
        
        public function expire($key, $expire) {
            return true;
        }
        
        public function command($command, $params) {
            return ['0', []];
        }
        
        public function incr($key) {
            return 0;
        }
    }
}

//初始化 RequestCache 实例
$cache = new RequestCache();

//禁用加密，避免依赖 Laravel 的加密功能
$cache->encryptData(false);

echo "开始简单性能测试...\n";

//测试 1: 参数过滤性能
echo "\n1. 测试参数过滤性能...\n";
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

//测试: 批量操作性能（本地缓存）
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

//测试: 内存使用
echo "\n3. 测试内存使用...\n";
$memoryUsage = memory_get_usage(true);
echo "当前内存使用: " . number_format($memoryUsage / 1024 / 1024, 2) . " MB\n";

echo "\n简单性能测试完成！\n";
