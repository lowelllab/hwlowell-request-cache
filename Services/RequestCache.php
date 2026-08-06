<?php

namespace HwlowellRequestCache;

use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Redis;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use HwlowellRequestCache\FilterConfig;
use HwlowellRequestCache\CacheConfig;
use HwlowellRequestCache\LocalCache;

class RequestCache
{

    /**
     * 核心缓存类
     *
     *
     */

    /**
     * 缓存前缀
     */
    protected $prefix;

    /**
     * 应用名称
     */
    protected $appName;

    /**
     * 应用环境
     */
    protected $appEnv;

    /**
     * 本地缓存实例
     */
    protected $localCache;

    /**
     * 默认过期时间（分钟）
     */
    protected $defaultExpire = 5;

    /**
     * 强制校验字符开关
     */
    protected $forceValidate = true;

    /**
     * 缓存标签
     */
    protected $tags = [];

    /**
     * 启用缓存统计
     */
    protected $enableStats = false;

    /**
     * 加密缓存数据
     */
    protected $encryptData = true;

    /**
     * 缓存版本
     */
    protected $version = '1.0';

    /**
     * 缓存大小限制（字节）
     */
    protected $sizeLimit = 1048576; // 1MB

    /**
     * Redis Cluster 节点解析器
     */
    protected $clusterNodeResolver;

    /**
     * 当前绑定的 Laravel Redis 连接名，null 表示未绑定（使用 default_connection）
     */
    protected ?string $connectionName = null;

    /**
     * 加载配置文件
     * @return array|null
     */
    protected function loadConfigFile()
    {
        // 首先尝试加载包内的默认配置文件
        $configPath = __DIR__ . '/../config/request_cache.php';
        if (file_exists($configPath)) {
            return require $configPath;
        }

        // 然后尝试加载 Laravel 项目根目录的配置文件
        if (function_exists('config_path')) {
            try {
                $configPath = config_path('request_cache.php');
                if (file_exists($configPath)) {
                    return require $configPath;
                }
            } catch (\Exception $e) {
                // 忽略错误
            }
        }

        return null;
    }

    /**
     * 从配置文件加载配置
     * @param array $config
     */
    public static function loadConfig(array $config)
    {
        //加载 FilterConfig 配置
        FilterConfig::loadFromConfig($config);

        //加载 CacheConfig 配置
        CacheConfig::loadFromConfig($config);
    }

    /**
     * 读取环境变量，兼容非 Laravel 运行环境
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    protected static function envValue(string $key, $default = null)
    {
        if (function_exists('env')) {
            return env($key, $default);
        }

        $value = getenv($key);
        return $value === false ? $default : $value;
    }

    /**
     * 根据配置解析缓存前缀
     * @param array|null $config
     * @return string
     */
    public static function resolvePrefix(array $config = null)
    {
        if ($config === null && function_exists('config')) {
            try {
                $config = config('request_cache', []);
            } catch (\Exception $e) {
                $config = [];
            }
        }

        $appName = self::envValue('APP_NAME', 'laravel');
        $appEnv = self::envValue('APP_ENV', 'local');
        $defaultPrefix = strtolower(str_replace(' ', '_', $appName)) . '_' . $appEnv . '_cache:';
        $prefix = $config['request_cache']['prefix'] ?? $defaultPrefix;
        $clusterConfig = $config['cache']['redis_cluster'] ?? CacheConfig::getRedisClusterConfig();

        if (!empty($clusterConfig['enabled'])) {
            return self::prefixWithHashTag($prefix, $clusterConfig, $defaultPrefix);
        }

        return $prefix;
    }

    /**
     * 为 Redis Cluster 前缀增加 hash tag
     * @param string $prefix
     * @param array $clusterConfig
     * @param string $defaultPrefix
     * @return string
     */
    protected static function prefixWithHashTag(string $prefix, array $clusterConfig, string $defaultPrefix)
    {
        if (preg_match('/\{[^{}]+\}/', $prefix)) {
            return $prefix;
        }

        $hashTag = $clusterConfig['hash_tag'] ?? null;
        if ($hashTag === null || $hashTag === '') {
            $hashTag = rtrim($defaultPrefix, ':');
        }

        $hashTag = preg_replace('/[^a-zA-Z0-9_\-:]/', '_', $hashTag);
        return '{' . $hashTag . '}:';
    }

    /**
     * 构造函数
     * @param array $config 可选配置数组
     */
    public function __construct(array $config = null)
    {
        //加载配置
        if ($config === null) {
            try {
                $config = config('request_cache', []);
            } catch (\Exception $e) {
                $config = $this->loadConfigFile();
            }
        }

        $config = $config ?? [];
        self::loadConfig($config);

        //Laravel 环境配置
        $this->appName = self::envValue('APP_NAME', 'laravel');
        $this->appEnv = self::envValue('APP_ENV', 'local');
        $this->prefix = self::resolvePrefix($config);

        //加载配置
        if (isset($config['request_cache'])) {
            $requestCacheConfig = $config['request_cache'];

            if (isset($requestCacheConfig['default_expire'])) {
                $this->defaultExpire = $requestCacheConfig['default_expire'];
            }

            if (isset($requestCacheConfig['force_validate'])) {
                $this->forceValidate = $requestCacheConfig['force_validate'];
            }

            if (isset($requestCacheConfig['enable_stats'])) {
                $this->enableStats = $requestCacheConfig['enable_stats'];
            } else {
                $statsConfig = CacheConfig::getStatsConfig();
                $this->enableStats = !empty($statsConfig['enabled']);
            }

            if (isset($requestCacheConfig['encrypt_data'])) {
                $this->encryptData = $requestCacheConfig['encrypt_data'];
            }

            if (isset($requestCacheConfig['version'])) {
                $this->version = $requestCacheConfig['version'];
            }

            if (isset($requestCacheConfig['size_limit'])) {
                $this->sizeLimit = $requestCacheConfig['size_limit'];
            }
        }

        $this->localCache = new LocalCache();

        $this->clusterNodeResolver = new RedisClusterNodeResolver(CacheConfig::getRedisClusterConfig());
    }

    /**
     * 设置强制校验字符开关
     * @param bool $force
     * @return $this
     */
    public function setForceValidate(bool $force)
    {
        $this->forceValidate = $force;
        return $this;
    }

    /**
     * 设置缓存标签
     * @param array|string $tags
     * @return $this
     */
    public function tags($tags, ...$additionalTags)
    {
        if (is_array($tags)) {
            $this->tags = $tags;
        } else {
            $this->tags = array_merge([$tags], $additionalTags);
        }
        return $this;
    }

    /**
     * 启用/禁用缓存统计
     * @param bool $enable
     * @return $this
     */
    public function enableStats(bool $enable)
    {
        $this->enableStats = $enable;
        return $this;
    }

    /**
     * 启用/禁用数据加密
     * @param bool $encrypt
     * @return $this
     */
    public function encryptData(bool $encrypt)
    {
        $this->encryptData = $encrypt;
        return $this;
    }

    /**
     * 设置缓存版本
     * @param string $version
     * @return $this
     */
    public function version(string $version)
    {
        $this->version = $version;
        return $this;
    }

    /**
     * 设置缓存大小限制
     * @param int $sizeLimit
     * @return $this
     */
    public function sizeLimit(int $sizeLimit)
    {
        $this->sizeLimit = $sizeLimit;
        return $this;
    }

    /**
     * 绑定目标 Redis 集群，返回携带绑定的克隆实例
     * @param string $name Laravel Redis 连接名
     * @return static
     */
    public function cluster(string $name): static
    {
        $this->assertClusterSwitchingEnabled();
        $this->assertConnectionAllowed($name);

        //返回克隆实例而非 $this：request-cache 注册为容器单例，写在 $this 上会让绑定跨调用粘连
        $clone = clone $this;
        $clone->connectionName = $name;
        $clone->clusterNodeResolver = new RedisClusterNodeResolver(CacheConfig::getRedisClusterConfig(), $name);

        return $clone;
    }

    /**
     * 获取当前有效 Redis 连接名
     * @return string
     */
    protected function effectiveConnectionName(): string
    {
        return $this->connectionName ?? $this->defaultConnectionName();
    }

    /**
     * 获取当前有效 Redis 连接
     * @return mixed
     */
    protected function connection()
    {
        return Redis::connection($this->effectiveConnectionName());
    }

    /**
     * 获取默认连接：统计计数固定读写该连接，与锁跟随目标集群的语义故意不同
     * @return mixed
     */
    protected function defaultConnection()
    {
        return Redis::connection($this->defaultConnectionName());
    }

    /**
     * 解析配置中的默认连接名
     * @return string
     */
    protected function defaultConnectionName(): string
    {
        $name = CacheConfig::getRedisClusterConfig()['default_connection'] ?? CacheConfig::DEFAULT_CONNECTION;

        return is_string($name) && $name !== '' ? $name : CacheConfig::DEFAULT_CONNECTION;
    }

    /**
     * 允许手动绑定的连接名白名单
     * @return array
     */
    protected function allowedConnections(): array
    {
        $configured = CacheConfig::getRedisClusterConfig()['connections'] ?? [];
        if (!empty($configured)) {
            return array_values($configured);
        }

        try {
            $redisConfig = config('database.redis', []);
        } catch (\Exception $e) {
            $redisConfig = [];
        }
        $redisConfig = is_array($redisConfig) ? $redisConfig : [];

        $names = [];
        foreach ($redisConfig as $key => $value) {
            //排除 options 与 clusters 两个非连接键，并用 is_array 过滤 client 这类标量项
            if ($key === 'options' || $key === 'clusters' || !is_array($value)) {
                continue;
            }
            $names[] = (string) $key;
        }

        $clusters = is_array($redisConfig['clusters'] ?? null) ? $redisConfig['clusters'] : [];
        foreach ($clusters as $clusterName => $clusterConfig) {
            if ($clusterName === 'options' || !is_array($clusterConfig)) {
                continue;
            }
            $names[] = (string) $clusterName;
        }

        return array_values(array_unique($names));
    }

    /**
     * 校验连接名是否在白名单内
     * @param string $name
     * @throws \InvalidArgumentException
     */
    protected function assertConnectionAllowed(string $name)
    {
        $allowed = $this->allowedConnections();
        if (in_array($name, $allowed, true)) {
            return;
        }

        throw new \InvalidArgumentException(sprintf(
            'Redis connection [%s] is not allowed for request cache cluster binding. Allowed connections: [%s].',
            $name,
            implode(', ', $allowed)
        ));
    }

    protected function assertClusterSwitchingEnabled(): void
    {
        if (empty(CacheConfig::getRedisClusterConfig()['enabled'])) {
            throw new \LogicException('Redis cluster switching is disabled.');
        }
    }

    /**
     * 生成带集群前缀的本地缓存 key
     * @param string $key generateKey() 产出的 Redis key
     * @return string
     */
    protected function localCacheKey(string $key): string
    {
        return hash('sha256', $this->effectiveConnectionName()) . ':' . $key;
    }

    /**
     * 构建标签集合 key
     * @param string $tag
     * @return string
     */
    protected function buildTagKey(string $tag)
    {
        return "{$this->prefix}tags:{$tag}";
    }

    /**
     * 构建统计 key
     * @param string $type
     * @param string|null $date
     * @return string
     */
    protected function buildStatsKey(string $type, string $date = null)
    {
        return $date === null
            ? "{$this->prefix}stats:{$type}"
            : "{$this->prefix}stats:{$type}:{$date}";
    }

    /**
     * 构建分布式锁 key
     * @param string $key
     * @return string
     */
    protected function buildLockKey(string $key)
    {
        return "{$this->prefix}lock:" . hash('sha256', $key);
    }

    /**
     * 是否启用 Redis Cluster 安全兜底
     * @return bool
     */
    protected function isClusterSafeMode()
    {
        $clusterConfig = CacheConfig::getRedisClusterConfig();
        return !empty($clusterConfig['enabled']) && !empty($clusterConfig['cluster_safe_mode']);
    }

    /**
     * 是否允许 Redis 写失败后写入本地缓存兜底
     * @return bool
     */
    protected function allowsLocalFallbackWrite(): bool
    {
        $strategy = CacheConfig::getStrategy();
        return !empty($strategy['fallback']) && empty($strategy['shared_mode']);
    }

    /**
     * 判断异常是否来自 Redis Cluster 跨 slot
     * @param \Throwable $e
     * @return bool
     */
    protected function isCrossSlotException(\Throwable $e)
    {
        return stripos($e->getMessage(), 'CROSSSLOT') !== false;
    }

    /**
     * 解码 Redis 中存储的缓存值
     * @param mixed $value
     * @return mixed
     */
    protected function decodeStoredValue($value)
    {
        if (!$value) {
            return null;
        }

        $originalValue = $value;
        if ($this->encryptData && function_exists('decrypt')) {
            try {
                $value = decrypt($value);
            } catch (\Exception $e) {
                //解密失败，使用原始值
                $value = $originalValue;
            }
        }

        return json_decode($value, true);
    }

    /**
     * 编码即将写入 Redis 的缓存值
     * @param mixed $data
     * @return string|false
     */
    protected function encodeStoredValue($data)
    {
        $jsonData = json_encode($data);
        if ($jsonData === false || strlen($jsonData) > $this->sizeLimit) {
            return false;
        }

        if ($this->encryptData && function_exists('encrypt')) {
            $jsonData = encrypt($jsonData);
            if (strlen($jsonData) > $this->sizeLimit) {
                return false;
            }
        }

        return $jsonData;
    }

    /**
     * 过滤参数值
     * @param mixed $value
     * @return mixed
     */
    protected function filterValue($value)
    {
        // 缓存正则表达式模式，减少重复编译开销
        static $regexPatterns = [];
        if (empty($regexPatterns)) {
            $regexPatterns['javascript'] = '/javascript:/i';
            $regexPatterns['onEvents'] = '/on\w+\s*=/i';
        }

        if (is_string($value)) {
            //去除首尾空格
            if (FilterConfig::shouldTrimWhitespace()) {
                $value = trim($value);
            }

            //移除 HTML 标签
            if (FilterConfig::shouldRemoveHtmlTags()) {
                $value = strip_tags($value);
            }

            //移除 SQL 语句关键字
            $sqlKeywords = FilterConfig::getSqlKeywords();
            if (!empty($sqlKeywords)) {
                //构建单次正则表达式，减少多次 preg_replace 调用
                $keywordsPattern = '/\b(' . implode('|', $sqlKeywords) . ')\b/i';
                $value = preg_replace($keywordsPattern, '', $value);
            }

            //移除特殊字符，只保留字母、数字、下划线和中文字符
            $value = preg_replace(FilterConfig::getAllowedCharsPattern(), '', $value);

            //移除潜在的 XSS 攻击向量
            $value = preg_replace($regexPatterns['javascript'], '', $value);
            $value = preg_replace($regexPatterns['onEvents'], '', $value);

            //限制字符串长度，防止过长输入
            $maxLength = 1000;
            if (strlen($value) > $maxLength) {
                $value = substr($value, 0, $maxLength);
            }

            //应用自定义过滤规则
            $customFilters = FilterConfig::getCustomFilters();
            foreach ($customFilters as $filter) {
                $value = call_user_func($filter, $value);
            }
        } elseif (is_array($value)) {
            //递归过滤数组
            foreach ($value as $key => $val) {
                $value[$key] = $this->filterValue($val);
            }

            //限制数组深度，防止嵌套过深
            $this->limitArrayDepth($value, 5);
        } elseif (is_numeric($value)) {
            //限制数值范围，防止过大或过小的值
            $minValue = -1000000;
            $maxValue = 1000000;
            if ($value < $minValue) {
                $value = $minValue;
            } elseif ($value > $maxValue) {
                $value = $maxValue;
            }
        }

        return $value;
    }

    /**
     * 限制数组深度
     * @param array &$array
     * @param int $maxDepth
     * @param int $currentDepth
     */
    protected function limitArrayDepth(array &$array, int $maxDepth, int $currentDepth = 1)
    {
        if ($currentDepth >= $maxDepth) {
            foreach ($array as $key => &$value) {
                if (is_array($value)) {
                    $value = '[DEPTH_LIMITED]';
                }
            }
            return;
        }

        foreach ($array as $key => &$value) {
            if (is_array($value)) {
                $this->limitArrayDepth($value, $maxDepth, $currentDepth + 1);
            }
        }
    }

    /**
     * 生成缓存 key
     * @param string $gateway
     * @param array $params
     * @return string
     */
    public function generateKey(string $gateway, array $params)
    {
        //处理分页参数，只缓存第一页
        if (isset($params['page']) && $params['page'] != 1) {
            $params['page'] = 1;
        }

        //过滤 gateway 参数，只允许字母、数字、下划线和连字符
        $sanitizedGateway = preg_replace('/[^a-zA-Z0-9_\-]/', '', $gateway);

        //强制校验字符开关
        if ($this->forceValidate) {
            //过滤参数
            foreach ($params as $key => $value) {
                $params[$key] = $this->filterValue($value);
            }

            //按键名排序
            ksort($params);
        }

        //使用 HMAC-SHA256 生成更安全的缓存键
        $keyData = [$this->version, $sanitizedGateway, $params];
        //Laravel 环境配置
        try {
            $hashKey = config('app.key', 'default_cache_key') ?: 'default_cache_key';
        } catch (\Exception $e) {
            //Laravel config function fails, use default
            $hashKey = 'default_cache_key';
        }
        $hash = hash_hmac('sha256', json_encode($keyData), $hashKey);
        //在缓存 key 中包含版本信息
        return $this->prefix . $this->version . ':' . $sanitizedGateway . ':' . $hash;
    }

    /**
     * 获取缓存
     * @param string $gateway
     * @param array $params
     * @return mixed
     */
    public function get(string $gateway, array $params)
    {
        $key = $this->generateKey($gateway, $params);
        $strategy = CacheConfig::getStrategy();

        //尝试从本地缓存获取
        $localValue = $this->localCache->get($this->localCacheKey($key));
        if ($localValue !== null) {
            return $localValue;
        }

        //尝试从主缓存（Redis）获取
        if ($strategy['primary'] === 'redis') {
            try {
                //使用 Laravel Redis 连接
                $redis = $this->connection();
                $value = $redis->get($key);

                if ($value) {
                    $data = $this->decodeStoredValue($value);

                    //将数据同步到本地缓存
                    $this->localCache->set($this->localCacheKey($key), $data);

                    return $data;
                }
            } catch (\Exception $e) {
                //Redis 异常，尝试使用备用缓存
                if ($strategy['fallback']) {
                    //备用缓存逻辑已在本地缓存中处理
                }
            }
        }

        return null;
    }

    /**
     * 批量获取缓存
     * @param array $items 格式：[[gateway, params], [gateway, params], ...]
     * @return array
     */
    public function mget(array $items)
    {
        $result = [];
        $keysToGet = [];
        $keyMap = [];
        $strategy = CacheConfig::getStrategy();

        //先尝试从本地缓存获取
        foreach ($items as $index => [$gateway, $params]) {
            $key = $this->generateKey($gateway, $params);
            $localValue = $this->localCache->get($this->localCacheKey($key));
            if ($localValue !== null) {
                $result[$index] = $localValue;
            } else {
                $keysToGet[] = $key;
                $keyMap[$key] = $index;
            }
        }

        // 如果有需要从 Redis 获取的键
        if (!empty($keysToGet) && $strategy['primary'] === 'redis') {
            try {
                //使用 Laravel Redis 连接
                $redis = $this->connection();
                $values = $this->isClusterSafeMode()
                    ? array_map(function ($key) use ($redis) {
                        return $redis->get($key);
                    }, $keysToGet)
                    : $redis->mget($keysToGet);

                foreach ($keysToGet as $i => $key) {
                    $value = $values[$i];
                    if ($value) {
                        $data = $this->decodeStoredValue($value);

                        //将数据同步到本地缓存
                        $this->localCache->set($this->localCacheKey($key), $data);

                        //添加到结果
                        if (isset($keyMap[$key])) {
                            $result[$keyMap[$key]] = $data;
                        }
                    }
                }
            } catch (\Exception $e) {
                if (!$this->isCrossSlotException($e)) {
                    return $result;
                }

                foreach ($keysToGet as $key) {
                    try {
                        $redis = $this->connection();
                        $value = $redis->get($key);
                        if (!$value) {
                            continue;
                        }

                        $data = $this->decodeStoredValue($value);
                        $this->localCache->set($this->localCacheKey($key), $data);
                        if (isset($keyMap[$key])) {
                            $result[$keyMap[$key]] = $data;
                        }
                    } catch (\Exception $ignored) {
                        //忽略单 key 读取异常
                    }
                }
            }
        }

        return $result;
    }

    /**
     * 设置缓存
     * @param string $gateway
     * @param array $params
     * @param mixed $data
     * @param int $expire
     * @return bool
     */
    public function set(string $gateway, array $params, $data, int $expire = null)
    {
        try {
            $key = $this->generateKey($gateway, $params);
            $expire = $expire ?? $this->defaultExpire * 60;
            $jsonData = $this->encodeStoredValue($data);
            if ($jsonData === false) {
                return false;
            }

            // 使用 Laravel Redis 连接
            $redis = $this->connection();
            $result = $redis->setex($key, $expire, $jsonData);

            //保存标签关联
            foreach ($this->tags as $tag) {
                $tagKey = $this->buildTagKey($tag);
                $redis->sadd($tagKey, $key);
                //为标签设置过期时间，防止内存泄漏
                $redis->expire($tagKey, $expire + 3600);
            }

            //将数据同步到本地缓存
            $this->localCache->set($this->localCacheKey($key), $data, $expire);

            return $result;
        } catch (\Exception $e) {
            //Redis 异常时，尝试使用本地缓存作为回退
            $key = $this->generateKey($gateway, $params);
            $expire = $expire ?? $this->defaultExpire * 60;

            if (!$this->allowsLocalFallbackWrite()) {
                return false;
            }

            $this->localCache->set($this->localCacheKey($key), $data, $expire);
            return true;
        }
    }

    /**
     * 批量设置缓存
     * @param array $items 格式：[[gateway, params, data, expire], [gateway, params, data, expire], ...]
     * @return array 格式：[true, false, true, ...] 对应每个设置操作的结果
     */
    public function mset(array $items)
    {
        $results = [];
        $pipeline = null;
        $localWrites = [];
        $strategy = CacheConfig::getStrategy();

        try {
            //如果使用 Redis 作为主缓存，使用管道批量操作
            if ($strategy['primary'] === 'redis' && !$this->isClusterSafeMode()) {
                //使用 Laravel Redis 连接
                $redis = $this->connection();
                $pipeline = $redis->pipeline();
            }

            foreach ($items as $index => $item) {
                $gateway = $item[0];
                $params = $item[1];
                $data = $item[2];
                $expire = isset($item[3]) ? $item[3] : null;
                $expire = $expire ?? $this->defaultExpire * 60;
                $key = $this->generateKey($gateway, $params);
                $jsonData = $this->encodeStoredValue($data);
                if ($jsonData === false) {
                    $results[$index] = false;
                    continue;
                }

                //使用管道批量操作
                if ($pipeline) {
                    $pipeline->setex($key, $expire, $jsonData);

                    //保存标签关联
                    foreach ($this->tags as $tag) {
                        $tagKey = $this->buildTagKey($tag);
                        $pipeline->sadd($tagKey, $key);
                        //为标签设置过期时间，防止内存泄漏
                        $pipeline->expire($tagKey, $expire + 3600);
                    }
                    $localWrites[$index] = [$key, $data, $expire];
                    $results[$index] = true;
                    continue;
                }

                $results[$index] = $this->set($gateway, $params, $data, $expire);
            }

            //执行管道操作
            if ($pipeline) {
                $pipeline->exec();
                foreach ($localWrites as [$key, $data, $expire]) {
                    $this->localCache->set($this->localCacheKey($key), $data, $expire);
                }
            }
        } catch (\Exception $e) {
            if (!$this->isCrossSlotException($e)) {
                //Redis 异常时，尝试使用本地缓存作为回退
                foreach ($items as $index => $item) {
                    if (array_key_exists($index, $results) && $results[$index] === false) {
                        continue;
                    }

                    $gateway = $item[0];
                    $params = $item[1];
                    $data = $item[2];
                    $expire = isset($item[3]) ? $item[3] : null;
                    $expire = $expire ?? $this->defaultExpire * 60;
                    $key = $this->generateKey($gateway, $params);

                    if (!$this->allowsLocalFallbackWrite()) {
                        $results[$index] = false;
                        continue;
                    }

                    $this->localCache->set($this->localCacheKey($key), $data, $expire);
                    $results[$index] = true;
                }
                return $results;
            }

            //Redis Cluster 跨 slot 时降级为逐 key 写入
            foreach ($items as $index => $item) {
                $expire = isset($item[3]) ? $item[3] : null;
                $results[$index] = $this->set($item[0], $item[1], $item[2], $expire);
            }
        }

        return $results;
    }

    /**
     * 删除缓存
     * @param string $gateway
     * @param array $params
     * @return bool
     */
    public function delete(string $gateway, array $params)
    {
        $key = $this->generateKey($gateway, $params);
        $this->localCache->delete($this->localCacheKey($key));

        try {
            //使用 Laravel Redis 连接
            $redis = $this->connection();
            return $redis->del($key) > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 获取 Laravel Redis key 前缀
     * @return string
     */
    protected function redisPrefix(): string
    {
        try {
            return config('database.redis.options.prefix', '');
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * 使用 SCAN 命令获取匹配的键
     * @param string $pattern
     * @param int $count
     * @param int $batchSize
     * @return array
     */
    protected function scanKeys(string $pattern, int $count = 1000, int $batchSize = 10000)
    {
        $connections = $this->clusterNodeResolver->scanConnections();
        if (count($connections) === 1) {
            return $this->scanKeysOnConnection(reset($connections), $pattern, $count, $batchSize);
        }

        $result = $this->scanKeysOnConnections($connections, $pattern, $count, $batchSize);
        return $result['keys'];
    }

    /**
     * 使用指定连接执行 SCAN
     * @param mixed $redis
     * @param string $pattern
     * @param int $count
     * @param int $batchSize
     * @return array
     */
    protected function scanKeysOnConnection($redis, string $pattern, int $count, int $batchSize): array
    {
        $keys = [];
        $cursor = self::initialScanCursor($redis);
        $redisPrefix = $this->redisPrefix();

        do {
            $fullPattern = $redisPrefix . $pattern;
            $result = $redis->scan($cursor, ['match' => $fullPattern, 'count' => $count]);

            if ($result === false) {
                break;
            }

            $cursor = $result[0];
            $batchKeys = $result[1];

            //限制内存使用
            if (count($keys) + count($batchKeys) > $batchSize) {
                break;
            }

            $keys = array_merge($keys, $batchKeys);
        } while (!self::isScanCursorFinished($cursor));

        return $keys;
    }

    /**
     * 解析 SCAN 游标初值
     *
     * phpredis 要求游标以 null 起始：传入 0 或 '0' 会被判定为迭代已结束并立刻返回
     * false，导致整轮扫描一条 key 都取不到。predis 则需要字面量 '0'。
     *
     * @param mixed $redis
     * @return string|null
     */
    public static function initialScanCursor($redis)
    {
        return $redis instanceof PhpRedisConnection ? null : '0';
    }

    /**
     * 判断 SCAN 是否已遍历完成
     * @param mixed $cursor
     * @return bool
     */
    public static function isScanCursorFinished($cursor): bool
    {
        return $cursor === null || (int) $cursor === 0;
    }

    /**
     * 使用多个连接执行 SCAN
     * @param array $connections
     * @param string $pattern
     * @param int $count
     * @param int $batchSize
     * @return array
     */
    protected function scanKeysOnConnections(array $connections, string $pattern, int $count, int $batchSize): array
    {
        $keys = [];
        $keysByNode = [];
        $failed = [];

        foreach ($connections as $name => $redis) {
            try {
                $nodeKeys = $this->scanKeysOnConnection($redis, $pattern, $count, $batchSize);
                $keysByNode[$name] = $nodeKeys;
                $keys = array_merge($keys, $nodeKeys);
            } catch (\Exception $e) {
                $failed[$name] = $e->getMessage();
            }
        }

        return [
            'keys' => array_values(array_unique($keys)),
            'keys_by_node' => $keysByNode,
            'failed_nodes' => $failed,
            'scanned_nodes' => count($connections) - count($failed),
        ];
    }

    /**
     * 使用指定连接分批删除键
     * @param mixed $redis
     * @param array $keys
     * @param int $batchSize
     * @return int
     */
    protected function batchDeleteOnConnection($redis, array $keys, int $batchSize = 1000): int
    {
        $deleted = 0;
        $redisPrefix = $this->redisPrefix();

        //分批删除
        foreach (array_chunk($keys, $batchSize) as $batch) {
            try {
                // Remove Redis prefix from keys if present
                $batchWithoutPrefix = $redisPrefix ? array_map(function($key) use ($redisPrefix) {
                    return preg_replace('/^' . preg_quote($redisPrefix, '/') . '/', '', $key);
                }, $batch) : $batch;

                if ($this->isClusterSafeMode()) {
                    foreach ($batchWithoutPrefix as $key) {
                        $deleted += $redis->del($key);
                    }
                } else {
                    $deleted += $redis->del($batchWithoutPrefix);
                }
                //每批删除后短暂休眠，减少 Redis 压力
                usleep(10000); // 10ms
            } catch (\Exception $e) {
                if (!$this->isCrossSlotException($e)) {
                    continue;
                }

                foreach ($batch as $key) {
                    try {
                        $keyWithoutPrefix = $redisPrefix
                            ? preg_replace('/^' . preg_quote($redisPrefix, '/') . '/', '', $key)
                            : $key;
                        $deleted += $redis->del($keyWithoutPrefix);
                    } catch (\Exception $ignored) {
                        //忽略单 key 删除异常
                    }
                }
            }
        }

        return $deleted;
    }

    /**
     * 分批删除键
     * @param array $keys
     * @param int $batchSize
     * @return int
     */
    protected function batchDelete(array $keys, int $batchSize = 1000)
    {
        $redis = $this->connection();
        return $this->batchDeleteOnConnection($redis, $keys, $batchSize);
    }

    /**
     * 按 pattern 清理缓存
     * @param string $pattern
     * @return bool
     */
    protected function clearByPattern(string $pattern): bool
    {
        $connections = $this->clusterNodeResolver->scanConnections();
        if (count($connections) === 1) {
            $redis = reset($connections);
            $keys = $this->scanKeysOnConnection($redis, $pattern, 1000, 10000);
            $deleted = empty($keys) ? 0 : $this->batchDeleteOnConnection($redis, $keys);
            $this->localCache->flush();
            return empty($keys) || $deleted > 0;
        }

        $scan = $this->scanKeysOnConnections($connections, $pattern, 1000, 10000);
        $deleted = 0;
        foreach ($scan['keys_by_node'] as $nodeName => $keys) {
            if (!isset($connections[$nodeName])) {
                continue;
            }
            $deleted += $this->batchDeleteOnConnection($connections[$nodeName], $keys);
        }

        $this->localCache->flush();
        return empty($scan['keys']) || $deleted > 0;
    }

    /**
     * 清除指定网关的所有缓存
     * @param string $gateway
     * @param bool $allVersions
     * @return bool
     */
    public function clearGateway(string $gateway, bool $allVersions = false)
    {
        try {
            if ($allVersions) {
                //清除所有版本的缓存
                $pattern = $this->prefix . '*:' . $gateway . ':*';
            } else {
                //只清除当前版本的缓存
                $pattern = $this->prefix . $this->version . ':' . $gateway . ':*';
            }
            return $this->clearByPattern($pattern);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 清除指定标签的缓存
     * @param array|string $tags
     * @return bool
     */
    public function clearTags($tags)
    {
        try {
            $tags = is_array($tags) ? $tags : func_get_args();
            $keys = [];

            //使用 Laravel Redis 连接
            $redis = $this->connection();

            foreach ($tags as $tag) {
                $tagKey = $this->buildTagKey($tag);
                $tagKeys = $redis->smembers($tagKey);
                $keys = array_merge($keys, $tagKeys);
                //删除标签集合
                $redis->del($tagKey);
            }

            if (empty($keys)) {
                $this->localCache->flush();
                return true;
            }

            $deleted = $this->batchDelete(array_unique($keys));
            if ($deleted > 0) {
                $this->localCache->flush();
            }

            return $deleted > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 清除所有缓存
     * @param bool $allVersions
     * @return bool
     */
    public function clearAll(bool $allVersions = true)
    {
        try {
            if ($allVersions) {
                //清除所有版本的缓存
                $pattern = $this->prefix . '*';
            } else {
                //只清除当前版本的缓存
                $pattern = $this->prefix . $this->version . ':*';
            }
            return $this->clearByPattern($pattern);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 记录缓存统计
     * @param string $type
     */
    protected function recordStats(string $type)
    {
        $statsConfig = CacheConfig::getStatsConfig();
        if (!$this->enableStats) {
            return;
        }

        try {
            $today = date('Y-m-d');
            $globalKey = $this->buildStatsKey($type);
            $dailyKey = $this->buildStatsKey($type, $today);

            // 使用 Laravel Redis 连接
            $redis = $this->defaultConnection();

            //增加统计计数
            $redis->incr($globalKey);
            $redis->incr($dailyKey);

            //设置过期时间：全局统计 30 天，每日统计 90 天
            $redis->expire($globalKey, $statsConfig['globalExpire'] ?? 30 * 24 * 3600);
            $redis->expire($dailyKey, $statsConfig['dailyExpire'] ?? 90 * 24 * 3600);
        } catch (\Exception $e) {
            //忽略统计异常
        }
    }

    /**
     * 获取分布式锁
     * @param string $key
     * @param int $expire
     * @param int $retryTimes
     * @param int $retryDelay
     * @return string|null
     */
    protected function acquireLock(string $key, int $expire = 10, int $retryTimes = 5, int $retryDelay = 100000)
    {
        $lockKey = $this->buildLockKey($key);
        $lockValue = Str::random(32); //随机值，防止误释放

        try {
            //使用 Laravel Redis 连接
            $redis = $this->connection();

            for ($i = 0; $i < $retryTimes; $i++) {
                if ($redis->set($lockKey, $lockValue, 'EX', $expire, 'NX')) {
                    return $lockValue;
                }
                //自适应重试间隔：指数退避
                $currentDelay = $retryDelay * (1 << $i);
                //最大重试间隔限制为 1 秒
                $currentDelay = min($currentDelay, 1000000);
                usleep($currentDelay);
            }
        } catch (\Exception $e) {
            //Redis 异常时，返回 null 表示获取锁失败
        }

        return null;
    }

    /**
     * 续期分布式锁
     * @param string $key
     * @param string $lockValue
     * @param int $expire
     * @return bool
     */
    protected function renewLock(string $key, string $lockValue, int $expire = 10)
    {
        $lockKey = $this->buildLockKey($key);

        try {
            //使用 Lua 脚本确保原子操作
            $script = <<<'LUA'
                if redis.call('get', KEYS[1]) == ARGV[1] then
                    return redis.call('expire', KEYS[1], ARGV[2])
                else
                    return 0
                end
            LUA;

            //使用 Laravel Redis 连接
            $redis = $this->connection();

            //直接执行 eval 命令
            return $redis->eval($script, 1, $lockKey, $lockValue, $expire) > 0;
        } catch (\Exception $e) {
            //Redis 异常时，返回 false 表示续期失败
            return false;
        }
    }

    /**
     * 释放分布式锁
     * @param string $key
     * @param string $lockValue
     * @return bool
     */
    protected function releaseLock(string $key, string $lockValue)
    {
        $lockKey = $this->buildLockKey($key);

        try {
            //使用 Lua 脚本确保原子操作
            $script = <<<'LUA'
                if redis.call('get', KEYS[1]) == ARGV[1] then
                    return redis.call('del', KEYS[1])
                else
                    return 0
                end
            LUA;

            //使用 Laravel Redis 连接
            $redis = $this->connection();

            //直接执行 eval 命令
            return $redis->eval($script, 1, $lockKey, $lockValue) > 0;
        } catch (\Exception $e) {
            //Redis 异常时，返回 false 表示释放锁失败
            return false;
        }
    }

    /**
     * 缓存装饰器
     * @param string $gateway
     * @param array $params
     * @param callable $callback
     * @param int $expire
     * @return mixed
     */
    public function remember(string $gateway, array $params, callable $callback, int $expire = null)
    {
        //尝试从缓存获取
        $data = $this->get($gateway, $params);

        if ($data !== null) {
            //记录缓存命中
            $this->recordStats('hits');
            return $data;
        }

        //记录缓存未命中
        $this->recordStats('misses');

        //获取锁，防止缓存击穿
        $key = $this->generateKey($gateway, $params);
        $lockValue = $this->acquireLock($key);

        if ($lockValue) {
            try {
                //再次检查缓存（双重检查）
                $data = $this->get($gateway, $params);
                if ($data !== null) {
                    return $data;
                }

                //执行回调获取数据
                $data = $callback();

                //存入缓存
                $this->set($gateway, $params, $data, $expire);
            } finally {
                //释放锁
                $this->releaseLock($key, $lockValue);
            }
        } else {
            //锁获取失败，直接执行回调
            $data = $callback();
        }

        return $data;
    }

    /**
     * 获取缓存统计信息
     * @return array
     */
    public function getStats()
    {
        try {
            $today = date('Y-m-d');
            //使用 Laravel Redis 连接
            $redis = $this->defaultConnection();

            $stats = [
                'hits' => (int) $redis->get($this->buildStatsKey('hits')) ?? 0,
                'misses' => (int) $redis->get($this->buildStatsKey('misses')) ?? 0,
                'today_hits' => (int) $redis->get($this->buildStatsKey('hits', $today)) ?? 0,
                'today_misses' => (int) $redis->get($this->buildStatsKey('misses', $today)) ?? 0,
            ];

            $stats['hit_rate'] = $stats['hits'] + $stats['misses'] > 0
                ? round($stats['hits'] / ($stats['hits'] + $stats['misses']) * 100, 2)
                : 0;

            return $stats;
        } catch (\Exception $e) {
            return [
                'hits' => 0,
                'misses' => 0,
                'today_hits' => 0,
                'today_misses' => 0,
                'hit_rate' => 0,
            ];
        }
    }

    /**
     * 预热缓存
     * @param string $gateway
     * @param array $params
     * @param callable $callback
     * @param int $expire
     * @return mixed
     */
    public function warm(string $gateway, array $params, callable $callback, int $expire = null)
    {
        $data = $callback();
        $this->set($gateway, $params, $data, $expire);
        return $data;
    }

    /**
     * 索引文档到 RediSearch
     * @param string $id
     * @param array $document
     * @return bool
     */
    public function indexSearch($id, array $document)
    {
        try {
            $searchService = \HwlowellRequestCache\RediSearchService::getInstance();
            return $searchService->index($id, $document);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 搜索缓存内容
     * @param string $query
     * @param array $options
     * @return array
     */
    public function search($query, array $options = [])
    {
        try {
            $searchService = \HwlowellRequestCache\RediSearchService::getInstance();
            return $searchService->search($query, $options);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * 从搜索索引中删除文档
     * @param string $id
     * @return bool
     */
    public function deleteSearch($id)
    {
        try {
            $searchService = \HwlowellRequestCache\RediSearchService::getInstance();
            return $searchService->delete($id);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 清除搜索索引
     * @return bool
     */
    public function clearSearch()
    {
        try {
            $searchService = \HwlowellRequestCache\RediSearchService::getInstance();
            return $searchService->clear();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 高级搜索
     * @param array $conditions
     * @param array $options
     * @return array
     */
    public function advancedSearch(array $conditions, array $options = [])
    {
        try {
            $searchService = \HwlowellRequestCache\RediSearchService::getInstance();
            return $searchService->advancedSearch($conditions, $options);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * 批量索引文档
     * @param array $documents
     * @return mixed
     */
    public function bulkIndexSearch(array $documents)
    {
        try {
            $searchService = \HwlowellRequestCache\RediSearchService::getInstance();
            return $searchService->bulkIndex($documents);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 批量删除文档
     * @param array $ids
     * @return mixed
     */
    public function bulkDeleteSearch(array $ids)
    {
        try {
            $searchService = \HwlowellRequestCache\RediSearchService::getInstance();
            return $searchService->bulkDelete($ids);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 获取搜索文档数量
     * @return int
     */
    public function countSearchDocuments()
    {
        try {
            $searchService = \HwlowellRequestCache\RediSearchService::getInstance();
            return $searchService->countDocuments();
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * 检查搜索索引是否存在
     * @return bool
     */
    public function existSearchIndex()
    {
        try {
            $searchService = \HwlowellRequestCache\RediSearchService::getInstance();
            return $searchService->existIndex();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 重建搜索索引
     * @return mixed
     */
    public function rebuildSearchIndex()
    {
        try {
            $searchService = \HwlowellRequestCache\RediSearchService::getInstance();
            return $searchService->rebuildIndex();
        } catch (\Exception $e) {
            return false;
        }
    }
}
