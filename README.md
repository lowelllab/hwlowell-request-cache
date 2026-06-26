# RequestCache Laravel 缓存包

RequestCache 是一个面向 Laravel 应用的请求级缓存包，支持 Redis 主缓存、本地进程缓存、缓存标签、统计监控、Redis Cluster 兼容和严格共享部署模式。

## 功能特性

- 基于 Redis 的请求缓存，并支持本地进程缓存兜底。
- 支持缓存标签，便于按业务分组批量清理。
- 支持请求参数过滤、排序和安全清洗。
- 支持缓存命中统计、趋势统计、Redis 内存和健康监控。
- 支持分布式锁，降低缓存击穿风险。
- 支持缓存预热。
- 支持对敏感缓存数据进行 Laravel 加密。
- 支持 `mget`、`mset` 等批量操作。
- 支持缓存版本控制。
- 支持 Redis Cluster hash tag key 前缀，降低跨 slot 风险。
- 支持 `cluster_safe_mode`，在 Redis Cluster 中将多 key 操作降级为逐 key Redis 操作。
- 支持 `shared_mode`，适用于多实例严格共享缓存部署。
- 支持 `scan_strategy=all_nodes`，在 Redis Cluster 中进行全节点清理和聚合监控。

## 环境要求

- PHP 8.2+
- Laravel Illuminate 组件 8.50+ 到 13.x
- Redis 单实例或 Redis Cluster

## 安装

通过 Composer 安装：

```bash
composer require hwlowell/request-cache
```

## 配置发布

包会通过 Laravel 自动发现注册 Service Provider。如需发布配置文件，执行：

```bash
php artisan vendor:publish --provider="HwlowellRequestCache\RequestCacheServiceProvider" --tag="config"
```

发布后会得到 `config/request_cache.php`。

## Redis Cluster 与严格共享模式

Redis Cluster 部署时，建议启用 `redis_cluster.enabled`。启用后，缓存值 key、标签集合 key、统计 key 和锁 key 默认共享同一个 Redis hash tag，降低多 key 操作触发 `CROSSSLOT` 的风险。

`cluster_safe_mode=true` 会把多 key Redis 命令转为逐 key Redis 命令，使 `mget`、`mset`、标签清理和批量删除仍然访问 Redis Cluster 共享存储，同时避免跨 slot 错误。

`fallback` 表示 Redis 失败时允许使用当前 PHP 进程内的 `LocalCache` 作为本地兜底。该本地缓存不会在 PHP worker、容器或应用实例之间共享。

`shared_mode=true` 是严格共享部署模式。启用后，Redis 写失败会让 `set()` 返回 `false`，失败的 `mset()` 项也返回 `false`；包不会写入 `LocalCache` 后伪装成共享写入成功。Redis 命中和 Redis 写成功仍可写入 `LocalCache`，作为当前进程热点缓存。

```php
return [
    'cache' => [
        'strategy' => [
            'primary' => 'redis',
            'secondary' => 'array',
            'fallback' => true,
            'shared_mode' => true,
        ],
        'redis_cluster' => [
            'enabled' => true,
            'hash_tag' => 'request-cache',
            'cluster_safe_mode' => true,
            'scan_strategy' => 'all_nodes', // 默认值为 single_connection
        ],
    ],
];
```

`redis_cluster` 配置项说明：

- `enabled`：是否启用 Redis Cluster 兼容模式。启用后，包会在生成缓存 key、标签 key、统计 key 和锁 key 时使用统一 hash tag，让相关 key 尽量落在同一个 slot，降低 `CROSSSLOT` 风险。
- `hash_tag`：Redis Cluster hash tag 名称。设置为 `request-cache` 时，生成的 key 会包含类似 `{request-cache}` 的片段；为空时会使用默认的 `app_env_cache` 形式，避免不同应用或环境之间 key 冲突。
- `cluster_safe_mode`：是否启用集群安全模式。启用后，涉及多个 key 的操作会降级为逐 key 操作，例如 `mget`、`mset`、标签清理和批量删除，从而避免 Redis Cluster 不允许跨 slot 多 key 命令的问题。
- `scan_strategy`：控制基于 SCAN 的清理和监控范围。默认 `single_connection` 只处理当前 Redis 连接；设置为 `all_nodes` 时，会读取宿主 Laravel 项目的 `database.redis.clusters` 节点配置，并尝试遍历所有集群节点。

`redis_cluster` 只控制本包的 Redis Cluster 兼容行为，不负责定义 Redis Cluster 节点。Redis 连接、节点列表和底层连接复用仍应配置在 Laravel 项目的 `config/database.php` 中，并由 Laravel Redis Manager 统一管理。

本包不提供独立 Redis 连接池；缓存读写、标签、统计、锁和扫描回退都会通过 Laravel Redis Manager 获取 Redis 连接。

`scan_strategy` 用于控制基于 SCAN 的清理与监控范围：

- `single_connection`：默认策略，只扫描当前 Redis 连接，并只返回当前连接视角的监控数据。
- `all_nodes`：从 Laravel 的 `config('database.redis.clusters')` 读取 Redis Cluster 节点配置，逐节点执行 SCAN，并聚合内存、健康状态和 key 分布数据。

当 Cluster 节点无法解析时，包会安全回退到当前 Redis 连接。监控结果会通过 `scope` 字段标注实际视角：

- `current_connection`：当前 Redis 连接视角。
- `cluster_aggregate`：已成功聚合所有解析到的 Cluster 节点。
- `cluster_partial`：至少一个节点失败，结果为部分聚合视角。

本包不维护独立 Redis Cluster 节点列表。需要全节点清理或聚合监控时，请在宿主 Laravel 项目的 `database.redis.clusters` 中配置节点，并设置 `scan_strategy => 'all_nodes'`。

宿主 Laravel 项目的 Redis Cluster 节点配置示例：

```php
'clusters' => [
    'default' => [
        ['host' => env('REDIS_CLUSTER_HOST_1'), 'port' => env('REDIS_CLUSTER_PORT_1', 6379)],
        ['host' => env('REDIS_CLUSTER_HOST_2'), 'port' => env('REDIS_CLUSTER_PORT_2', 6379)],
        ['host' => env('REDIS_CLUSTER_HOST_3'), 'port' => env('REDIS_CLUSTER_PORT_3', 6379)],
    ],
],
```

严格多实例部署推荐配置：

```php
'cache' => [
    'strategy' => [
        'fallback' => true,
        'shared_mode' => true,
    ],
    'redis_cluster' => [
        'enabled' => true,
        'hash_tag' => 'request-cache',
        'cluster_safe_mode' => true,
        'scan_strategy' => 'all_nodes',
    ],
],
```

## 基础使用

### 服务类方式

```php
use HwlowellRequestCache\RequestCache;

$cache = new RequestCache();

// 设置缓存
$cache->set('users', ['id' => 1], ['name' => '张三'], 3600);

// 获取缓存
$user = $cache->get('users', ['id' => 1]);

// 删除缓存
$cache->delete('users', ['id' => 1]);

// 清理某个 gateway 的缓存
$cache->clearGateway('users');

// 按标签清理缓存
$cache->clearTags(['users', 'profiles']);

// 清理全部缓存
$cache->clearAll();
```

### 回调缓存 remember

`remember()` 会先尝试读取缓存。缓存未命中时执行回调，并把回调结果写入缓存。

```php
use HwlowellRequestCache\RequestCache;

$cache = new RequestCache();

$data = $cache->remember('users', ['id' => 1], function () {
    // 昂贵操作，例如 API 调用或数据库查询
    return User::find(1);
}, 3600);
```

### Facade 方式

```php
use RequestCache;

RequestCache::set('users', ['id' => 1], ['name' => '张三']);

$user = RequestCache::get('users', ['id' => 1]);
```

## 缓存监控

```php
use HwlowellRequestCache\CacheMonitor;

$monitor = new CacheMonitor();

// 获取缓存统计
$stats = $monitor->getStats();

// 获取 key 分布
$distribution = $monitor->getKeyDistribution();

// 获取最近 7 天趋势
$trend = $monitor->getTrend(7);

// 获取健康状态
$status = $monitor->getHealthStatus();

// 获取 Redis 内存使用
$memory = $monitor->getMemoryUsage();
```

Redis Cluster 模式下，内存和基于 SCAN 的监控结果会包含 `scope` 字段，用于说明结果是当前连接视角、全集群聚合视角还是部分节点聚合视角。

## 进阶用法

### 设置与获取缓存

```php
$cache->set('user_profile', ['user_id' => 1], [
    'name' => '张三',
    'age' => 30,
    'email' => 'zhangsan@example.com',
]);

$userProfile = $cache->get('user_profile', ['user_id' => 1]);

if ($userProfile) {
    echo '缓存命中：' . $userProfile['name'];
} else {
    echo '缓存未命中';
}
```

### 自定义过期时间

```php
// 10 分钟过期
$cache->set('user_profile', ['user_id' => 1], [
    'name' => '张三',
    'age' => 30,
], 10 * 60);
```

### 批量获取

```php
$results = $cache->mget([
    ['user_profile', ['user_id' => 1]],
    ['user_profile', ['user_id' => 2]],
    ['user_profile', ['user_id' => 3]],
]);

foreach ($results as $index => $userProfile) {
    if ($userProfile) {
        echo '用户 ' . ($index + 1) . '：' . $userProfile['name'] . PHP_EOL;
    } else {
        echo '用户 ' . ($index + 1) . ' 缓存未命中' . PHP_EOL;
    }
}
```

### 批量设置

```php
$results = $cache->mset([
    ['user_profile', ['user_id' => 1], ['name' => '张三', 'age' => 30], 600],
    ['user_profile', ['user_id' => 2], ['name' => '李四', 'age' => 25], 600],
    ['user_profile', ['user_id' => 3], ['name' => '王五', 'age' => 35], 600],
]);

print_r($results); // [true, true, true]
```

### 缓存标签

缓存标签用于对缓存进行分组管理，方便批量清理相关缓存。

```php
$cache->tags('user', 'profile')->set('user_profile', ['user_id' => 1], [
    'name' => '张三',
    'age' => 30,
]);

$cache->tags('user', 'settings')->set('user_settings', ['user_id' => 1], [
    'theme' => 'dark',
    'language' => 'zh-CN',
]);

// 清理所有带 user 标签的缓存
$cache->clearTags('user');

// 清理同时关联 user/profile 的缓存
$cache->clearTags(['user', 'profile']);
```

### 网关清理与全量清理

```php
// 清理指定 gateway 的当前版本缓存
$cache->clearGateway('user_profile');

// 清理指定 gateway 的所有版本缓存
$cache->clearGateway('user_profile', true);

// 清理当前版本全部缓存
$cache->clearAll(false);

// 清理所有版本全部缓存
$cache->clearAll(true);
```

在 `scan_strategy=all_nodes` 模式下，`clearGateway()` 和 `clearAll()` 会遍历解析到的 Redis Cluster 节点，并在对应节点连接上删除扫描到的 key。

### 缓存统计

```php
$cache->enableStats(true);

$cache->remember('test', ['id' => 1], function () {
    return ['data' => 'test'];
});

$stats = $cache->getStats();

print_r($stats);
```

输出示例：

```text
Array
(
    [hits] => 0
    [misses] => 1
    [today_hits] => 0
    [today_misses] => 1
    [hit_rate] => 0
)
```

### 缓存预热

缓存预热适合在系统启动或低峰期预先加载热点数据。

```php
$cache->warm('user_profile', ['user_id' => 1], function () {
    return [
        'name' => '张三',
        'age' => 30,
        'email' => 'zhangsan@example.com',
    ];
}, 3600);

$userIds = [1, 2, 3, 4, 5];

foreach ($userIds as $userId) {
    $cache->warm('user_profile', ['user_id' => $userId], function () use ($userId) {
        return [
            'user_id' => $userId,
            'name' => '用户' . $userId,
            'age' => 20 + $userId,
            'email' => 'user' . $userId . '@example.com',
        ];
    });
}
```

### 版本控制

```php
$cache->version('2.0');

$key = $cache->generateKey('user_profile', ['user_id' => 1]);

echo '缓存键：' . $key . PHP_EOL;
```

### 数据加密

```php
$cache->encryptData(true);  // 启用加密
$cache->encryptData(false); // 禁用加密
```

### 缓存大小限制

```php
// 设置缓存大小限制为 512KB
$cache->sizeLimit(512 * 1024);
```

### 强制参数校验

```php
$cache->setForceValidate(true);  // 启用
$cache->setForceValidate(false); // 禁用
```

## 完整示例

### 示例 1：用户信息缓存

```php
<?php

use HwlowellRequestCache\RequestCache;

$cache = new RequestCache([
    'request_cache' => [
        'prefix' => 'my_app_',
        'default_expire' => 5,
        'enable_stats' => true,
        'encrypt_data' => true,
    ],
]);

function getUserFromDatabase($userId) {
    echo "从数据库获取用户信息：{$userId}\n";
    sleep(1);

    return [
        'user_id' => $userId,
        'name' => '用户' . $userId,
        'age' => 20 + $userId % 10,
        'email' => 'user' . $userId . '@example.com',
        'created_at' => date('Y-m-d H:i:s'),
    ];
}

function getUserInfo($userId) {
    global $cache;

    return $cache->remember('user_profile', ['user_id' => $userId], function () use ($userId) {
        return getUserFromDatabase($userId);
    }, 300);
}

$user1 = getUserInfo(1);
$user1Again = getUserInfo(1);

print_r($user1);
print_r($user1Again);

$stats = $cache->getStats();
print_r($stats);
```

### 示例 2：商品列表缓存

```php
<?php

use HwlowellRequestCache\RequestCache;

$cache = new RequestCache();

function getProductsFromDatabase($category, $page = 1, $pageSize = 10) {
    echo "从数据库获取商品列表：{$category}, 第 {$page} 页\n";
    sleep(1);

    $products = [];
    for ($i = 0; $i < $pageSize; $i++) {
        $productId = ($page - 1) * $pageSize + $i + 1;
        $products[] = [
            'id' => $productId,
            'name' => $category . '商品' . $productId,
            'price' => 100 + $productId,
            'stock' => 1000 - $productId,
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }

    return [
        'products' => $products,
        'total' => 100,
        'page' => $page,
        'page_size' => $pageSize,
    ];
}

function getProducts($category, $page = 1, $pageSize = 10) {
    global $cache;

    return $cache->tags('product', $category)->remember('product_list', [
        'category' => $category,
        'page' => $page,
        'page_size' => $pageSize,
    ], function () use ($category, $page, $pageSize) {
        return getProductsFromDatabase($category, $page, $pageSize);
    }, 600);
}

$electronics = getProducts('electronics', 1, 5);
$electronicsAgain = getProducts('electronics', 1, 5);

echo '获取到 ' . count($electronics['products']) . " 个商品\n";

$cache->clearTags('product');
```

## 性能优化建议

1. 根据数据更新频率合理设置缓存过期时间，避免过期过于频繁或数据过旧。
2. 多个缓存操作优先使用 `mget` 和 `mset`，减少网络请求次数。
3. 为相关缓存添加标签，便于批量清理。
4. 在系统启动或低峰期预热热点数据。
5. 定期观察缓存命中率，持续优化缓存策略。
6. 对不存在的数据也进行适当短期缓存，降低缓存穿透风险。
7. 根据服务器内存设置合适的本地缓存大小。
8. Redis Cluster 部署中启用 `redis_cluster.enabled` 和 `cluster_safe_mode`。
9. Redis Cluster 需要全节点清理或聚合监控时启用 `scan_strategy=all_nodes`。
10. 多实例严格共享部署中启用 `shared_mode`，避免 Redis 写失败后出现本地伪成功。

## 注意事项

1. **Redis 依赖：** 默认使用 Redis 作为主缓存，请确保 Redis 服务可用。
2. **数据大小限制：** 默认单条缓存大小限制为 1MB，超过限制的数据不会写入缓存。
3. **参数过滤：** 默认会过滤缓存参数，移除潜在安全风险。
4. **加密依赖：** 数据加密依赖 Laravel 的 `encrypt` 和 `decrypt` 函数；非 Laravel 环境下会自动跳过。
5. **错误处理：** 默认策略允许 Redis 不可用时使用当前进程本地缓存兜底；启用 `shared_mode` 后，Redis 写失败会返回失败，不会写入本地缓存并伪装成功。
6. **版本控制：** 可通过缓存版本号实现整体换版，减少缓存不一致问题。
7. **Redis Cluster 全节点扫描：** `all_nodes` 依赖 Laravel 的 `database.redis.clusters` 配置；解析失败时会回退当前连接视角。

## 配置选项

### 缓存策略

```php
CacheConfig::$strategy = [
    'primary' => 'redis',       // 主缓存
    'secondary' => 'array',     // 备用缓存
    'fallback' => true,         // 是否启用兜底
    'shared_mode' => false,     // Redis 写失败时是否禁止本地写入伪成功
];
```

当所有成功写入都必须通过 Redis 被所有应用实例共享时，请启用 `shared_mode`。

### Redis Cluster 配置

```php
'redis_cluster' => [
    'enabled' => false,                    // 是否启用 Redis Cluster 兼容模式
    'hash_tag' => null,                    // 为空时使用默认 app_env_cache 作为 hash tag
    'cluster_safe_mode' => true,           // 多 key 操作使用逐 key 兜底
    'scan_strategy' => 'single_connection',// single_connection=当前连接；all_nodes=聚合 Laravel Redis Cluster 节点
],
```

当 `database.redis.clusters` 已配置且缓存清理或监控必须覆盖所有 Redis Cluster 节点时，使用 `scan_strategy => 'all_nodes'`。监控结果会在 `scope` 字段中返回 `current_connection`、`cluster_aggregate` 或 `cluster_partial`。

### 本地缓存配置

```php
CacheConfig::$localCache = [
    'ttl' => 300,  // 本地缓存过期时间，单位秒
    'size' => 1000,// 本地缓存最大条目数
];
```

### 参数过滤配置

```php
FilterConfig::addCustomFilter(function ($value) {
    // 自定义过滤逻辑
    return $value;
});
```

## 测试

```bash
# 安装依赖
composer install

# 运行全部测试
composer test

# 直接运行 Redis Cluster 与共享模式测试
vendor/bin/phpunit tests/RequestCacheClusterTest.php

# 按名称过滤测试
vendor/bin/phpunit tests/RequestCacheClusterTest.php --filter SharedMode

# 生成覆盖率报告
composer test-coverage
```

Windows PowerShell 环境下，也可以直接使用 PHPUnit PHP 入口：

```powershell
php vendor\phpunit\phpunit\phpunit
php vendor\phpunit\phpunit\phpunit tests\RequestCacheClusterTest.php --filter "ClusterMonitor|AllNodesClear"
```

## 更新日志

### v1.0.3

- README 全面中文化，补充 Redis Cluster 全节点清理与聚合监控说明。
- 明确 `scan_strategy=single_connection|all_nodes` 的使用场景和回退行为。
- 补充监控 `scope=current_connection|cluster_aggregate|cluster_partial` 的含义。
- 补充 Windows PowerShell 下直接运行 PHPUnit 的示例。

### v1.0.2

- 新增 Redis Cluster 兼容配置。
- 新增基于 hash tag 的缓存、标签、统计和锁 key 前缀。
- 新增多 key Redis 操作和 `CROSSSLOT` 错误的集群安全降级。
- 新增严格 `shared_mode` 写入语义，适用于共享部署。
- 对齐 `CacheMonitor` 与 `RequestCache` 的统计 key。
- 增加 Redis Cluster 与共享模式相关 PHPUnit/Testbench 覆盖。

### v1.0.0

- 初始版本发布。
- 支持 Redis 主缓存和本地缓存兜底。
- 支持缓存标签。
- 支持请求参数过滤。
- 支持缓存统计和监控。
- 支持分布式锁。
- 支持缓存预热。
- 支持数据加密。

## 许可证

本项目采用 MIT License。详情请查看 [License File](LICENSE)。
