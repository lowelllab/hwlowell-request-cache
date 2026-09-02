# RequestCache Laravel 缓存包

RequestCache 是一个面向 Laravel 应用的请求级缓存包，支持 Redis 主缓存、本地进程缓存、缓存标签、统计监控、Redis Cluster 兼容和严格共享部署模式。

部署形态配置、场景示例、升级检查与注意事项见 [docs/v1.1.0-使用规范与注意事项.md](./docs/v1.1.0-使用规范与注意事项.md)。

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
- 支持多 Redis 集群手动切换，通过 `cluster()` 显式指定本次操作访问哪个集群。

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
- `hash_tag`：Redis Cluster hash tag 名称。设置为 `request-cache` 时，生成的 key 会包含类似 `{request-cache}` 的片段；为空时会使用默认的 `app_env_cache` 形式，避免不同应用或环境之间 key 冲突。若同时显式配置了 `request_cache.prefix`，该 prefix 会保留在 hash tag 之后（形如 `{request-cache}:my_app_`）。**多个应用共用同一个 Redis Cluster 时，必须给每个应用配不同的 `hash_tag` 或不同的 `prefix`**：前缀一旦相同，任意一方 `clearAll()` 的 SCAN pattern 都会连带删掉另一方的缓存。
- `cluster_safe_mode`：是否启用集群安全模式。启用后，涉及多个 key 的操作会降级为逐 key 操作，例如 `mget`、`mset`、标签清理和批量删除，从而避免 Redis Cluster 不允许跨 slot 多 key 命令的问题。
- `scan_strategy`：控制基于 SCAN 的清理和监控范围。默认 `single_connection` 只处理当前 Redis 连接；设置为 `all_nodes` 时，会读取宿主 Laravel 项目的 `database.redis.clusters` 节点配置，并按 `default_connection` 的名字取出对应集群的节点列表逐节点遍历。通过 `cluster()` 绑定非默认集群时，该策略强制降级为 `single_connection`。

`redis_cluster` 只控制本包的 Redis Cluster 兼容行为，不负责定义 Redis Cluster 节点。Redis 连接、节点列表和底层连接复用仍应配置在 Laravel 项目的 `config/database.php` 中，并由 Laravel Redis Manager 统一管理。

本包不提供独立 Redis 连接池；缓存读写、标签、统计、锁和扫描回退都会通过 Laravel Redis Manager 获取 Redis 连接。

`scan_strategy` 用于控制基于 SCAN 的清理与监控范围：

- `single_connection`：默认策略，只扫描当前 Redis 连接，并只返回当前连接视角的监控数据。
- `all_nodes`：从 Laravel 的 `config('database.redis.clusters')` 按 `default_connection` 的名字读取对应集群的节点配置，逐节点执行 SCAN，并聚合内存、健康状态和 key 分布数据。绑定非默认集群时本策略强制降级为 `single_connection`。

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

## 多 Redis 集群手动切换

宿主系统多地部署、同一套 Laravel 应用需要访问多个 Redis 集群时，可以通过 `cluster()` 显式指定本次缓存操作访问哪个集群。默认配置的 `default_connection` 为 `default`，因此默认配置向后兼容；显式修改 `default_connection` 后，未绑定数据读写、当前连接清理、统计与监控会真实切换到该连接。

该能力依赖「同系统多集群」前提：各地集群必须共用同一套 `APP_KEY`、`APP_NAME`、`APP_ENV` 与缓存 `version`，`generateKey()` 才能在各集群上算出逐字节相同的缓存 key。跨系统访问不在本能力范围内。

宿主 Laravel 项目 `config/database.php` 中的多集群配置形态：

```php
'clusters' => [
    'default' => [
        ['host' => env('REDIS_LOCAL_HOST_1'), 'port' => 6379],
        ['host' => env('REDIS_LOCAL_HOST_2'), 'port' => 6379],
    ],
    'gz' => [
        ['host' => env('REDIS_GZ_HOST_1'), 'port' => 6379],
    ],
    'hk' => [
        ['host' => env('REDIS_HK_HOST_1'), 'port' => 6379],
    ],
],
```

本包 `config/request_cache.php` 中的对应配置：

```php
'redis_cluster' => [
    'enabled' => true,
    'hash_tag' => 'request-cache',
    'cluster_safe_mode' => true,
    'scan_strategy' => 'all_nodes',
    'default_connection' => 'default',
    'connections' => [],
],
```

- `default_connection`：未显式调用 `cluster()` 时的数据读写、当前连接清理、统计与监控使用的权威 Laravel Redis 连接名，默认 `default`。
- `connections`：允许手动切换的连接名白名单。留空表示自动推导——顶层仅接收数组连接并排除 `options`、`client`、`clusters`；`database.redis.clusters` 下仅接收数组值并显式排除 `options`。显式非数组配置抛出 `InvalidArgumentException`，绝不自动推导。
- `enabled`：`false` 时 `cluster()` 抛出 `LogicException`，但未绑定路径仍按 `default_connection` 工作；只有 `true` 才允许显式多集群绑定。它不是仅控制 SCAN 的开关。

### cluster() 用法

```php
use HwlowellRequestCache\RequestCache;

// 默认读写 default 集群
$data = RequestCache::get('users', ['id' => 1]);

// 切换到广州集群读取
$data = RequestCache::cluster('gz')->get('users', ['id' => 1]);

// 切换到香港集群写入
RequestCache::cluster('hk')->set('users', ['id' => 1], ['name' => '张三'], 600);
```

`cluster()` 返回的是携带集群绑定的**克隆实例**，绑定只在这条调用链上生效，用完即失效，不会污染容器中的 `request-cache` 单例。每次操作只连接一个集群，需要访问另一个集群时重新调用一次 `cluster()`。

集群绑定对以下 API 全部生效：`get`、`set`、`delete`、`mget`、`mset`、`remember`、`warm`、`clearGateway`、`clearAll`、`clearTags`。

### 能力边界

本包只提供「这次操作连到哪个集群」的切换机制，不提供任何跨集群策略。以下能力**明确不提供**，全部由调用方在业务代码中自行实现：

- 不提供自动兜底读取顺序：本地集群 miss 后不会自动去其他集群再读一次。
- 不提供自动回填：从远端集群读到数据后不会自动写回本地集群。
- 不提供删除广播与跨集群一致性保证：删除是否覆盖其他集群由调用方决定，本包不承诺可靠投递。
- 不解析跨集群数据格式差异：各集群数据结构不一致时由读取方自行处理。
- 不内置跨集群调用的超时、熔断与并发控制策略。

`CacheMonitor` 不支持运行时 `cluster('x')` 指定目标，但其默认监控连接遵循 `default_connection`。

### 业务侧兜底与回填示例

兜底顺序、回填和回源全部由调用方编排：

```php
$params = ['id' => 1];

// 优先读本机所在集群
$data = RequestCache::get('users', $params);

// 本地 miss，手动依次尝试其他区域集群
if ($data === null) {
    $data = RequestCache::cluster('gz')->get('users', $params);
}

if ($data === null) {
    $data = RequestCache::cluster('hk')->get('users', $params);
}

// 读到远端数据后手动回填本地集群，回填就是一次普通写入
if ($data !== null) {
    RequestCache::cluster('default')->set('users', $params, $data, 600);
}

// 仍然读不到则回源数据库，由业务自行决定是否写缓存
if ($data === null) {
    $data = User::query()->find($params['id'])?->toArray();
}
```

删除需要覆盖多个集群时同样由调用方显式遍历：

```php
foreach (['default', 'gz', 'hk'] as $name) {
    RequestCache::cluster($name)->delete('users', $params);
}
```

### 锁与统计的连接归属

两者归属**故意不同**：

- 分布式锁跟随目标集群。`remember()` 的锁保护的是目标集群上的写入，锁必须与被保护的数据落在同一集群，否则跨机房并发写同一集群时锁形同虚设。
- 统计计数固定写入 `default_connection` 指定的权威默认连接；`CacheMonitor` 的统计、趋势与单连接 Redis 信息也读取该连接。

### LocalCache 按集群隔离

缓存 key 不含集群信息。为避免跨集群串用，本包使用 `hash('sha256', effectiveConnectionName()) . ':' . key` 构造本地缓存 key；不直接拼接连接名与分隔符，从而避免连接名含特殊字符时的边界碰撞。各集群在当前 PHP 进程内各持一份热点缓存，互不串用。

`cluster()` 返回的克隆实例各自持有独立的 `LocalCache`（`__clone`），互不共享容量与条目；清理操作只 flush 当前实例自己的本地缓存。

### 绑定集群时 all_nodes 被强制降级

绑定非默认集群时，`scan_strategy` 强制降级为 `single_connection`：即使配置里填的是 `all_nodes`，`RequestCache::cluster('gz')->clearAll()` 也只在 `gz` 这一个连接上执行 SCAN，绝不会遍历节点、更不会跨集群扫描。降级只发生在运行时的扫描路径上，配置值本身不会被改写，`config('request_cache.cache.redis_cluster.scan_strategy')` 读出来仍是你写进去的那个值。

`all_nodes` 只在未调用 `cluster()`、或绑定名恰好等于 `default_connection` 时生效，此时按 `default_connection` 的名字从 `database.redis.clusters` 取出对应集群的节点列表逐节点 SCAN。

### 清理能力边界警示

> **绑定集群后 `clearGateway()` 与 `clearAll()` 只覆盖该集群的第一个 master 节点。** Laravel 的 `PhpRedisClusterConnection::scan()` 在未指定节点时使用 `_masters()[0]`，本包不传节点参数，因此其余分片上的 key 扫描不到、也不会被删除，而方法仍然返回成功。这是已知且已接受的行为，不是缺陷：各集群数据不要求强一致，缓存 miss 可回源数据库，残留 key 会随 TTL 自然过期收敛。需要覆盖整个集群的清理时，请在未绑定的默认集群上使用 `scan_strategy => 'all_nodes'`。

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
    // 用 !== null 判断：0 / false / '' / null 都可能是合法缓存值
    if ($userProfile !== null) {
        echo '用户 ' . ($index + 1) . '：' . ($userProfile['name'] ?? '') . PHP_EOL;
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

// 清理同时关联 user 与 profile 的缓存（多标签取交集）
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

在 `scan_strategy=all_nodes` 模式下，`clearGateway()` 和 `clearAll()` 会遍历 `default_connection` 对应集群的全部节点；通过 `cluster()` 绑定非默认集群时强制降级为 `single_connection`，只清理该集群第一个 master 节点上的 key，并在对应节点连接上删除扫描到的 key。

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
        'enable_logging' => false, //默认关闭包内诊断日志；排查时可临时打开
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
11. 多集群部署中，兜底读取顺序、回填和删除覆盖范围全部在业务代码中显式编排，避免跨集群串行读取拖慢主链路。

## 注意事项

1. **Redis 依赖：** 默认使用 Redis 作为主缓存，请确保 Redis 服务可用。
2. **数据大小限制：** 默认单条缓存大小限制为 1MB，超过限制的数据不会写入缓存。
3. **参数过滤：** `force_validate` 默认关闭；开启后只影响 key 哈希输入的清洗形态，指纹已保证唯一性，不构成安全边界。
4. **加密依赖：** 数据加密依赖 Laravel 的 `encrypt` 和 `decrypt` 函数；非 Laravel 环境下会自动跳过。
5. **错误处理：** 默认策略允许 Redis 不可用时使用当前进程本地缓存兜底；启用 `shared_mode` 后，Redis 写失败会返回失败，不会写入本地缓存并伪装成功。
6. **版本控制：** 可通过缓存版本号实现整体换版，减少缓存不一致问题。
7. **Redis Cluster 全节点扫描：** `all_nodes` 依赖 Laravel 的 `database.redis.clusters` 配置；解析失败时会回退当前连接视角。
8. **多集群前提：** `cluster()` 依赖各地集群共用同一套 `APP_KEY`、`APP_NAME`、`APP_ENV` 与缓存 `version`，否则各集群算出的缓存 key 不同，跨集群读取必然 miss。
9. **多集群边界：** 本包只提供集群切换机制，不提供自动兜底读取顺序、不提供自动回填、不提供删除广播与跨集群一致性保证。
10. **本地副本陈旧窗口：** 进程内副本最长存活 `cache.local_cache.ttl`（默认 300 秒）。这段时间内本进程读不到其他实例对 Redis 的改写，常驻进程（Octane、队列 worker）尤其要按业务容忍度调整该值。
11. **RediSearch 已移除：** `search*` 系列方法恒定返回空值（`false` / `[]` / `0`），保留仅为兼容既有调用方。检索能力请改用 `cmsig/seal` + `seal-redisearch-adapter`，用法见 `RediSearch/readme.txt`。

## 配置选项

### 缓存策略

```php
CacheConfig::$strategy = [
    'primary' => 'redis',       // 主缓存
    'fallback' => true,         // Redis 失败时是否回落进程内 LocalCache
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
    'scan_strategy' => 'single_connection',// single_connection=当前连接；all_nodes=聚合 default_connection 对应集群的节点，绑定其他集群时强制降级
    'default_connection' => 'default',     // 未显式指定集群时使用的连接名
    'connections' => [],                   // 允许手动切换的连接名白名单，留空表示自动推导
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

`ttl` 是进程内副本存活时间的**硬上限**，读写两条路径都受它约束：本地副本的实际存活时间取 `min(Redis 剩余 TTL, local_cache.ttl)`。它决定了「别的实例改写 Redis 之后，本进程最长返回多久旧值」，需要更强的跨实例一致性就调小它。

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

### 未发布（Unreleased）

> 以下变更已合入代码但尚未发版，`composer.json` 的 `version` 仍为 `1.1.0`。发版时把本节改为对应版本号。

- **本地缓存副本不再超过 `local_cache.ttl`。** 此前写入路径直接沿用 Redis 的 TTL，`set(..., 3600)` 会让常驻进程（Octane、队列 worker）在别的实例改写 Redis 之后，仍按 3600 秒返回旧值。现在 `set()`、`mset()` 与两者的本地兜底写入都与读取路径共用同一个上限。
- **集群模式下不再丢弃显式配置的 `prefix`。** 此前 `redis_cluster.enabled=true` 时 `resolvePrefix()` 直接返回 `{hash_tag}:`，把 `request_cache.prefix` 整个丢掉；共用一个集群、又都按文档配了同一个 `hash_tag` 的多个应用会得到完全相同的 key 前缀，任意一方 `clearAll()` 都会连带删光另一方的缓存。现在显式 prefix 会保留在 hash tag 之后，形如 `{request-cache}:my_app_`。
- **`mset()` 管道路径按 `exec()` 的逐条响应返回结果。** 此前入队即记 `true` 且从不检查 `exec()` 返回值，管道内单条写入失败时调用方仍拿到 `true`；失败项现在返回 `false` 且不写入本地副本。客户端不返回逐条响应（非数组）时仍按成功处理。返回数组的下标顺序保证与入参一致——管道结果要等 `exec()` 之后才回填，而超出 `size_limit` 或非 UTF-8 的条目在那之前就已失败，两者混在一批里时不重排会让 `array_values()`、`===` 比较或 `array_combine($ids, $results)` 这类用法静默错位。
- **`set()` 同样不再忽略 `setex` 返回的 `false`。** Redis 拒绝写入（OOM、只读副本）时不抛异常，只返回 `false`，此前会在返回失败的同时照常写入标签索引和本地副本——`shared_mode=true` 的调用方拿到 `false` 却仍能从本进程读到这条数据。
- **`remember()` 的等待窗口与持锁者实际 TTL 对齐。** `enable_extend` 会把持锁 TTL 从 `lock.expire` 拉长到 `expire + extend_interval * 5`（默认 5→15 秒），而 `waitForCachedEntry()` 一直按未拉长的 `lock.expire` 超时，导致耗时落在 5～15 秒的回调仍会击穿。**副作用：抢锁失败的调用方最长阻塞时间从 `lock.expire` 变为拉长后的 TTL**（持锁者提前释放时会立刻结束等待）；不接受这个延迟就调小 `lock.expire` / `extend_interval`，或关掉 `enable_extend`。
- **`remember()` 在等待超时后回源的结果现在会写入缓存。** 此前直接返回不落缓存，持续竞争下每个等待超时者都重复回源且谁都不填坑。
- **`cluster()` 不再丢掉标签。** `__clone()` 此前清空 `tags`，使 `tags('users')->cluster('gz')->set(...)` 静默写不进任何标签索引，事后 `clearTags('users')` 也清不掉它。两种链式顺序现在都生效。
- **`clearGateway()` / `clearAll()` 的成功判据收紧为「没有节点报错」。** 旧判据是 `found === 0 || deleted > 0`，会把「第一轮 SCAN 就抛异常、一个 key 都没扫到」报成成功。删除条数仍不作为判据（SCAN 命中的 key 可能在 DEL 前自然过期），与 `clearTags()` 一致。**`all_nodes` 下只要有一个节点扫描失败就返回 `false`**，健康节点的清理不受影响，扫描与批量删除失败现在也会经 `CacheLogger` 打 warning。
- **SCAN pattern 中的字面量片段会转义 glob 元字符。** `prefix` / `version` / Laravel redis prefix 里的 `*` `?` `[` `]` `\` 此前直接进入 `SCAN MATCH`，会让清理范围超出本应用。key 形态不受影响。
- `vendor:publish` 出来的配置现在真正生效：`loadConfigFile()` 改为先读宿主项目的 `config/request_cache.php`，包内那份只作兜底。
- 移除从未被任何代码读取的 `strategy.secondary`，以及 `redis_search` 中的 `enabled` / `max_results` / `timeout_ms`。
- `search*` 系列方法与 `CacheConfig` 的 RediSearch 访问器标记为 `@deprecated`：`RediSearchService` 已不随包提供，它们恒定返回空值，检索能力请改用 `cmsig/seal`。
- 修复 `FilterConfig::__callStatic()` 在调用未定义静态方法时无限递归导致栈溢出（该魔术方法已随同样无用的 `__staticConstruct()` 一并移除）。
- 新增 `.gitattributes`，`tests/`、`example.php` 等开发期文件不再随 composer dist 包分发。

#### 升级影响

- **key 形态**：只有**同时**满足「`redis_cluster.enabled=true`」和「显式配置了非空 `request_cache.prefix`」的部署会改变，旧 key 立即 miss 并随 TTL 回收，处理方式见 [docs 第六节](./docs/v1.1.0-使用规范与注意事项.md#六形态之间切换的迁移影响)。其余部署不变。
- **返回值语义**：`clearGateway()` / `clearAll()` 在节点报错时从 `true` 变为 `false`，`set()` / `mset()` 在 Redis 拒绝写入时从「可能为真」变为确定的 `false`。按返回值做告警的调用方会看到此前被吞掉的失败。
- **延迟**：`remember()` 抢锁失败方的最长等待从 `lock.expire` 变为拉长后的持锁 TTL，见上。
- **本地缓存**：若此前依赖「本地副本跟随 Redis 长 TTL」的行为，请调大 `cache.local_cache.ttl`。

### v1.1.0

- 新增 `RedisClientAdapter` / `CacheLogger`：抹平 Redis 与 RedisCluster API 差异，关键降级与失败路径可观测。
- 全量 `catch (\Throwable)`，避免集群上 `Error` / `ArgumentCountError` 直穿成 500。
- `mset()` 在 Cluster 上不再调用不存在的 `pipeline()`；标签索引写入移出管道，遗留 SET→ZSET 可迁移。
- 缓存值编码支持非 UTF-8 的 serialize 信封兜底；`force_validate` 默认改为 `false`。
- `clearTags([...])` 多标签改为真正交集；`remember()` 持锁 TTL 拉长并在回调前后续期。
- `all_nodes` 节点连接继承 `database.redis.clusters.options` 凭据；`cluster()` 克隆隔离 `LocalCache`。
- `request_cache.enable_logging` 默认 `false`，关闭包内 `[request-cache]` 诊断日志。
- 构造时只传 `cache` 段会从应用配置补齐 `request_cache` 段，避免 version 等静默丢失。
- 使用规范、形态配置与升级说明合并为 [docs/v1.1.0-使用规范与注意事项.md](./docs/v1.1.0-使用规范与注意事项.md)。

### v1.0.5

- 新增多 Redis 集群手动切换：`RequestCache::cluster('gz')` 返回携带集群绑定的克隆实例；默认配置仍读写 `default`，显式 `default_connection` 控制未绑定路径。
- 新增 `redis_cluster.default_connection` 与 `redis_cluster.connections` 两个配置项。
- 明确边界：本包只提供集群切换机制，不提供自动兜底读取顺序、不提供自动回填、不提供删除广播与跨集群一致性保证。
- 分布式锁跟随目标集群，统计计数固定写入默认连接。
- `LocalCache` 按集群隔离；`cluster()` 克隆各自持有独立本地缓存。
- `scan_strategy=all_nodes` 适用范围收窄：仅在未绑定或绑定名等于 `default_connection` 时生效，绑定其他集群时强制降级为 `single_connection`。
- 清理能力边界：绑定集群后 `clearGateway()` 与 `clearAll()` 只覆盖该集群第一个 master 节点，残留 key 随 TTL 收敛。

### v1.0.4

- 版本号对齐至 `1.0.4`，无功能变更，功能内容见 `v1.0.3`。

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
