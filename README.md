# RequestCache Laravel Package

A Laravel cache package for request caching with Redis and local cache support.

## Features

- Redis-based caching with local cache fallback
- Cache tagging support
- Request parameter filtering and sanitization
- Cache statistics and monitoring
- Distributed lock support to prevent cache stampedes
- Cache warming functionality
- Encryption support for sensitive data
- Redis connection pool management
- Batch operations support
- Cache version control

## Requirements

- PHP 7.4+
- Laravel 8.75+
- Redis server (for primary caching)

## Installation

You can install the package via composer:

```bash
composer require hwlowell/request-cache
```

## Configuration

The package will automatically register its service provider. You can publish the configuration file if needed:

```bash
php artisan vendor:publish --provider="HwlowellRequestCache\RequestCacheServiceProvider" --tag="config"
```

## Usage

### Basic Usage

```php
use HwlowellRequestCache\RequestCache;

//Create a new instance
$cache = new RequestCache();

//Set cache
$cache->set('users', ['id' => 1], ['name' => 'John Doe'], 3600);

//Get cache
$user = $cache->get('users', ['id' => 1]);

//Delete cache
$cache->delete('users', ['id' => 1]);

//Clear all cache for a gateway
$cache->clearGateway('users');

//Clear cache by tags
$cache->clearTags(['users', 'profiles']);

//Clear all cache
$cache->clearAll();
```

### Using the Decorator Pattern

```php
use HwlowellRequestCache\RequestCache;

$cache = new RequestCache();

//Cache the result of a callback
$data = $cache->remember('users', ['id' => 1], function () {
    //Expensive operation, e.g., API call or database query
    return User::find(1);
}, 3600);
```

### Using the Facade

```php
use RequestCache;

//Set cache
RequestCache::set('users', ['id' => 1], ['name' => 'John Doe']);

//Get cache
$user = RequestCache::get('users', ['id' => 1]);
```

### Cache Monitoring

```php
use HwlowellRequestCache\CacheMonitor;

$monitor = new CacheMonitor();

//Get cache statistics
$stats = $monitor->getStats();

//Get cache key distribution
$distribution = $monitor->getKeyDistribution();

//Get cache usage trend
$trend = $monitor->getTrend(7); // 7 days

//Get health status
$status = $monitor->getHealthStatus();
```

## Advanced Usage

### 1. 基本缓存操作

#### 设置缓存

```php
//设置缓存，默认过期时间
$cache->set('user_profile', ['user_id' => 1], [
    'name' => '张三',
    'age' => 30,
    'email' => 'zhangsan@example.com'
]);

//设置缓存，自定义过期时间（10分钟）
$cache->set('user_profile', ['user_id' => 1], [
    'name' => '张三',
    'age' => 30,
    'email' => 'zhangsan@example.com'
], 10 * 60);
```

#### 获取缓存

```php
//获取缓存
$userProfile = $cache->get('user_profile', ['user_id' => 1]);

if ($userProfile) {
    echo "缓存命中：" . $userProfile['name'];
} else {
    echo "缓存未命中";
}
```

#### 删除缓存

```php
//删除缓存
$cache->delete('user_profile', ['user_id' => 1]);
```

### 2. 批量操作

#### 批量获取缓存

```php
//批量获取缓存
$results = $cache->mget([
    ['user_profile', ['user_id' => 1]],
    ['user_profile', ['user_id' => 2]],
    ['user_profile', ['user_id' => 3]]
]);

foreach ($results as $index => $userProfile) {
    if ($userProfile) {
        echo "用户 " . ($index + 1) . "：" . $userProfile['name'] . "\n";
    } else {
        echo "用户 " . ($index + 1) . " 缓存未命中\n";
    }
}
```

#### 批量设置缓存

```php
//批量设置缓存
$results = $cache->mset([
    ['user_profile', ['user_id' => 1], ['name' => '张三', 'age' => 30], 600],
    ['user_profile', ['user_id' => 2], ['name' => '李四', 'age' => 25], 600],
    ['user_profile', ['user_id' => 3], ['name' => '王五', 'age' => 35], 600]
]);

print_r($results); //[true, true, true]
```

### 3. 缓存装饰器（Remember）

缓存装饰器是一个非常实用的功能，它会先尝试从缓存获取数据，如果缓存未命中，则执行回调函数获取数据并自动存入缓存。

```php
//使用缓存装饰器
$userProfile = $cache->remember('user_profile', ['user_id' => 1], function() {
    //这里是获取数据的逻辑，例如从数据库查询
    echo "执行回调函数获取数据\n";
    return [
        'name' => '张三',
        'age' => 30,
        'email' => 'zhangsan@example.com',
        'timestamp' => time()
    ];
}, 10 * 60); //10分钟过期

echo "用户信息：" . $userProfile['name'] . " (" . date('Y-m-d H:i:s', $userProfile['timestamp']) . ")\n";

//再次调用，会从缓存获取
$userProfile = $cache->remember('user_profile', ['user_id' => 1], function() {
    echo "执行回调函数获取数据\n";
    return [
        'name' => '张三',
        'age' => 30,
        'email' => 'zhangsan@example.com',
        'timestamp' => time()
    ];
});

echo "用户信息：" . $userProfile['name'] . " (" . date('Y-m-d H:i:s', $userProfile['timestamp']) . ")\n";
```

### 4. 缓存标签

缓存标签可以帮助你对缓存进行分组管理，方便批量清除特定分组的缓存。

```php
//使用缓存标签
$cache->tags('user', 'profile')->set('user_profile', ['user_id' => 1], [
    'name' => '张三',
    'age' => 30
]);

$cache->tags('user', 'settings')->set('user_settings', ['user_id' => 1], [
    'theme' => 'dark',
    'language' => 'zh-CN'
]);

//清除特定标签的缓存
$cache->clearTags('user'); //清除所有带有 'user' 标签的缓存
//或者
$cache->clearTags(['user', 'profile']); //清除同时带有 'user' 和 'profile' 标签的缓存
```

### 5. 缓存清除

#### 清除指定网关的缓存

```php
//清除指定网关的缓存（当前版本）
$cache->clearGateway('user_profile');

//清除指定网关的所有版本缓存
$cache->clearGateway('user_profile', true);
```

#### 清除所有缓存

```php
//清除所有缓存（当前版本）
$cache->clearAll(false);

//清除所有版本的缓存
$cache->clearAll(true);
```

### 6. 缓存统计

```php
//启用缓存统计
$cache->enableStats(true);

//执行一些缓存操作
$cache->remember('test', ['id' => 1], function() {
    return ['data' => 'test'];
});

//获取缓存统计信息
$stats = $cache->getStats();

print_r($stats);
/*
输出示例：
Array
(
    [hits] => 0
    [misses] => 1
    [today_hits] => 0
    [today_misses] => 1
    [hit_rate] => 0
)
*/
```

### 7. 缓存预热

缓存预热可以在系统启动或低峰期预先加载缓存数据，提高系统响应速度。

```php
//缓存预热
$cache->warm('user_profile', ['user_id' => 1], function() {
    //从数据库或其他数据源获取数据
    return [
        'name' => '张三',
        'age' => 30,
        'email' => 'zhangsan@example.com'
    ];
}, 3600); //1小时过期

//批量预热多个用户的缓存
$userIds = [1, 2, 3, 4, 5];
foreach ($userIds as $userId) {
    $cache->warm('user_profile', ['user_id' => $userId], function() use ($userId) {
        //从数据库获取用户信息
        return [
            'user_id' => $userId,
            'name' => '用户' . $userId,
            'age' => 20 + $userId,
            'email' => 'user' . $userId . '@example.com'
        ];
    });
}
```

### 8. 高级配置选项

#### 版本控制

```php
//设置缓存版本
$cache->version('2.0');

//生成的缓存键会包含版本信息
$key = $cache->generateKey('user_profile', ['user_id' => 1]);
echo "缓存键：" . $key . "\n";
```

#### 数据加密

```php
//启用/禁用数据加密
$cache->encryptData(true); //启用加密
$cache->encryptData(false); //禁用加密
```

#### 缓存大小限制

```php
//设置缓存大小限制（512KB）
$cache->sizeLimit(512 * 1024);
```

#### 强制参数校验

```php
//启用/禁用强制参数校验
$cache->setForceValidate(true); //启用
$cache->setForceValidate(false); //禁用
```

## Complete Usage Examples

### Example 1: User Information Cache

```php
<?php

use HwlowellRequestCache\RequestCache;

//初始化缓存
$cache = new RequestCache([
    'request_cache' => [
        'prefix' => 'my_app_',
        'default_expire' => 5, // 5分钟
        'enable_stats' => true,
        'encrypt_data' => true,
    ]
]);

//模拟从数据库获取用户信息的函数
function getUserFromDatabase($userId) {
    echo "从数据库获取用户信息：$userId\n";
    //模拟数据库查询延迟
    sleep(1);
    return [
        'user_id' => $userId,
        'name' => '用户' . $userId,
        'age' => 20 + $userId % 10,
        'email' => 'user' . $userId . '@example.com',
        'created_at' => date('Y-m-d H:i:s')
    ];
}

//使用缓存装饰器获取用户信息
function getUserInfo($userId) {
    global $cache;
    return $cache->remember('user_profile', ['user_id' => $userId], function() use ($userId) {
        return getUserFromDatabase($userId);
    }, 300); //5分钟过期
}

//测试缓存效果
echo "第一次获取用户 1 的信息：\n";
$user1 = getUserInfo(1);
print_r($user1);

echo "\n第二次获取用户 1 的信息（应该从缓存获取）：\n";
$user1Again = getUserInfo(1);
print_r($user1Again);

echo "\n获取用户 2 的信息：\n";
$user2 = getUserInfo(2);
print_r($user2);

//获取缓存统计
$stats = $cache->getStats();
echo "\n缓存统计：\n";
print_r($stats);

//清除用户 1 的缓存
echo "\n清除用户 1 的缓存\n";
$cache->delete('user_profile', ['user_id' => 1]);

echo "\n第三次获取用户 1 的信息（应该重新从数据库获取）：\n";
$user1Third = getUserInfo(1);
print_r($user1Third);

//获取更新后的缓存统计
$stats = $cache->getStats();
echo "\n更新后的缓存统计：\n";
print_r($stats);
```

### Example 2: Product List Cache

```php
<?php

use HwlowellRequestCache\RequestCache;

// 初始化缓存
$cache = new RequestCache();

//从数据库获取商品列表的函数
function getProductsFromDatabase($category, $page = 1, $pageSize = 10) {
    echo "从数据库获取商品列表：$category, 第 $page 页\n";
    //数据库查询延迟
    sleep(1);
    
    $products = [];
    for ($i = 0; $i < $pageSize; $i++) {
        $productId = ($page - 1) * $pageSize + $i + 1;
        $products[] = [
            'id' => $productId,
            'name' => $category . '商品' . $productId,
            'price' => 100 + $productId,
            'stock' => 1000 - $productId,
            'created_at' => date('Y-m-d H:i:s')
        ];
    }
    
    return [
        'products' => $products,
        'total' => 100,
        'page' => $page,
        'page_size' => $pageSize
    ];
}

//使用缓存装饰器获取商品列表
function getProducts($category, $page = 1, $pageSize = 10) {
    global $cache;
    return $cache->tags('product', $category)->remember('product_list', [
        'category' => $category,
        'page' => $page,
        'page_size' => $pageSize
    ], function() use ($category, $page, $pageSize) {
        return getProductsFromDatabase($category, $page, $pageSize);
    }, 600); //10分钟过期
}

//测试缓存效果
echo "第一次获取电子产品列表：\n";
$electronics = getProducts('electronics', 1, 5);
echo "获取到 " . count($electronics['products']) . " 个商品\n";

foreach ($electronics['products'] as $product) {
    echo "- " . $product['name'] . "：￥" . $product['price'] . "\n";
}

echo "\n第二次获取电子产品列表（应该从缓存获取）：\n";
$electronicsAgain = getProducts('electronics', 1, 5);
echo "获取到 " . count($electronicsAgain['products']) . " 个商品\n";

//获取服装类商品
echo "\n获取服装类商品列表：\n";
$clothing = getProducts('clothing', 1, 5);
echo "获取到 " . count($clothing['products']) . " 个商品\n";

//清除所有商品缓存
echo "\n清除所有商品缓存\n";
$cache->clearTags('product');

//再次获取电子产品列表
echo "\n第三次获取电子产品列表（应该重新从数据库获取）：\n";
$electronicsThird = getProducts('electronics', 1, 5);
echo "获取到 " . count($electronicsThird['products']) . " 个商品\n";
```

## Performance Optimization Tips

1. **合理设置缓存过期时间**：根据数据更新频率设置合适的过期时间，避免缓存过期过于频繁或数据过时。

2. **使用批量操作**：对于多个缓存操作，使用 `mget` 和 `mset` 批量操作可以减少网络请求次数。

3. **启用 Redis 连接池**：在高并发场景下，启用 Redis 连接池可以显著提高性能。

4. **合理使用缓存标签**：为相关的缓存数据添加标签，方便批量管理和清除。

5. **缓存预热**：在系统启动或低峰期预先加载热点数据。

6. **监控缓存命中率**：定期检查缓存命中率，优化缓存策略。

7. **避免缓存穿透**：对不存在的数据也进行适当的缓存处理。

8. **合理设置本地缓存大小**：根据服务器内存情况设置合适的本地缓存大小。

## Notes

1. **Redis 依赖**：该系统默认使用 Redis 作为主缓存，确保 Redis 服务正常运行。

2. **数据大小限制**：默认缓存大小限制为 1MB，超过此大小的数据不会被缓存。

3. **参数过滤**：默认会对缓存参数进行过滤，移除潜在的安全风险。

4. **加密依赖**：数据加密功能依赖 Laravel 的 `encrypt` 和 `decrypt` 函数，在非 Laravel 环境中会自动禁用。

5. **连接池配置**：根据服务器性能和并发量调整 Redis 连接池配置。

6. **错误处理**：系统内置了错误处理机制，当 Redis 不可用时会自动降级到本地缓存。

7. **版本控制**：通过版本号可以轻松实现缓存的整体更新，避免缓存不一致问题。

## Configuration Options

### Cache Strategy

```php
//In CacheConfig.php
CacheConfig::$strategy = [
    'primary' => 'redis', //Primary cache
    'secondary' => 'array', //Secondary cache
    'fallback' => true, //Enable fallback
];
```

### Local Cache Config

```php
//In CacheConfig.php
CacheConfig::$localCache = [
    'ttl' => 300, //Local cache TTL in seconds
    'size' => 1000, //Maximum number of items in local cache
];
```

### Filter Config

```php
//In FilterConfig.php
//Add custom filters
FilterConfig::addCustomFilter(function ($value) {
    //Custom filtering logic
    return $value;
});
```

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.

## Testing

You can run the tests to ensure everything is working correctly:

```bash
# Run basic tests with Redis connection pool enabled
vendor/bin/phpunit tests/RequestCacheTest.php

# Run tests with Redis connection pool disabled
vendor/bin/phpunit tests/RequestCacheWithoutPoolTest.php

# Run complete tests with all features
vendor/bin/phpunit tests/RequestCacheCompleteTest.php

# Run all tests
vendor/bin/phpunit tests/

# Generate code coverage report
vendor/bin/phpunit tests/ --coverage-html coverage

```

## Changelog

### v1.0.0
- Initial release
- Redis-based caching with local cache fallback
- Cache tagging support
- Request parameter filtering
- Cache statistics and monitoring
- Distributed lock support
- Cache warming functionality
- Encryption support