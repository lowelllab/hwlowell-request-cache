<?php

namespace HwlowellRequestCache;

use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use HwlowellRequestCache\FilterConfig;
use HwlowellRequestCache\CacheConfig;
use HwlowellRequestCache\LocalCache;

class RequestCache
{

    /**
     * 核心缓存�?
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
     * 强制校验字符开�?
     */
    protected $forceValidate = false;

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
     * Redis Cluster 节点解析�?
     */
    protected $clusterNodeResolver;

    /**
     * 当前绑定�?Laravel Redis 连接名，null 表示未绑定（使用 default_connection�?
     */
    protected ?string $connectionName = null;

    /**
     * 当前实例生效的缓存配�?
     * @var CacheConfig
     */
    protected $cacheConfig;

    /**
     * 当前实例生效的参数过滤配�?
     * @var FilterConfig
     */
    protected $filterConfig;

    /**
     * 加载配置文件
     * @return array|null
     */
    protected function loadConfigFile()
    {
        //先找宿主项目发布出来的配置。包内那份是兜底默认值，一定存在，
        //先读它就等于�?vendor:publish 出来的配置永远不生效
        if (function_exists('config_path')) {
            try {
                $configPath = config_path('request_cache.php');
                if (file_exists($configPath)) {
                    return require $configPath;
                }
            } catch (\Throwable $e) {
                //忽略错误，回落包内默认配�?
            }
        }

        $configPath = __DIR__ . '/../config/request_cache.php';
        if (file_exists($configPath)) {
            return require $configPath;
        }

        return null;
    }

    /**
     * 从配置文件加载配�?
     * @param array $config
     */
    public static function loadConfig(array $config)
    {
        //加载 FilterConfig 配置
        FilterConfig::loadFromConfig($config);

        //加载 CacheConfig 配置
        CacheConfig::loadFromConfig($config);

        if (isset($config['request_cache']['enable_logging'])) {
            CacheLogger::setEnabled((bool) $config['request_cache']['enable_logging']);
        }
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
     * @param array|null $clusterConfig 已归一化的集群配置，为空时�?$config 或全局配置推导
     * @return string
     */
    public static function resolvePrefix(array $config = null, array $clusterConfig = null)
    {
        if ($config === null && function_exists('config')) {
            try {
                $config = config('request_cache', []);
            } catch (\Throwable $e) {
                $config = [];
            }
        }

        $config = $config ?? [];
        $clusterConfig = $clusterConfig
            ?? $config['cache']['redis_cluster']
            ?? CacheConfig::getRedisClusterConfig();

        $appName = self::envValue('APP_NAME', 'laravel');
        $appEnv = self::envValue('APP_ENV', 'local');
        $defaultPrefix = strtolower(str_replace(' ', '_', $appName)) . '_' . $appEnv . '_cache:';

        //$prefix 的取值必须与 1.1.0 逐字节一致：?? 只在 null 时回落，显式配成 ''
        //（用于完全不加前缀）或非字符串的都原样沿用，否则非集群部署�?key 形态会�?
        $prefix = $config['request_cache']['prefix'] ?? $defaultPrefix;

        //只有「非空字符串且不等于自动推导值」才算用户显式配置，需要在集群模式�?
        //保留；其余情况沿�?1.1.0 的行为，只输�?hash tag
        $configuredPrefix = is_string($prefix) && $prefix !== '' && $prefix !== $defaultPrefix
            ? $prefix
            : null;

        if (!empty($clusterConfig['enabled'])) {
            return self::prefixWithHashTag($prefix, $clusterConfig, $defaultPrefix, $configuredPrefix);
        }

        return $prefix;
    }

    /**
     * �?Redis Cluster 前缀增加 hash tag
     * @param string $prefix
     * @param array $clusterConfig
     * @param string $defaultPrefix
     * @param string|null $configuredPrefix 用户显式配置�?prefix，未配置�?null
     * @return string
     */
    protected static function prefixWithHashTag(
        string $prefix,
        array $clusterConfig,
        string $defaultPrefix,
        string $configuredPrefix = null
    ) {
        if (preg_match('/\{[^{}]+\}/', $prefix)) {
            return $prefix;
        }

        $hashTag = $clusterConfig['hash_tag'] ?? null;
        if ($hashTag === null || $hashTag === '') {
            $hashTag = rtrim($defaultPrefix, ':');
        }

        $hashTag = preg_replace('/[^a-zA-Z0-9_\-:]/', '_', $hashTag);

        //显式配置�?prefix 必须跟在 hash tag 后面保留下来。丢掉它意味着共用同一�?
        //集群、又都按文档配了 hash_tag 的多个应用会得到完全相同�?key 前缀�?
        //彼此的缓存虽然因 app.key 不同而不会互相命中，但任意一�?clearAll() �?
        //SCAN pattern 都会连带删光另一方的缓存�?
        return '{' . $hashTag . '}:' . ($configuredPrefix ?? '');
    }

    /**
     * 构造函�?
     * @param array $config 可选配置数�?
     */
    public function __construct(array $config = null)
    {
        //显式传入的配置只属于当前实例；未传入时读取的是应用全局配置，回写静态属性才是安全的
        $usesApplicationConfig = $config === null;

        //加载配置
        if ($config === null) {
            try {
                $config = config('request_cache', []);
            } catch (\Throwable $e) {
                $config = $this->loadConfigFile();
            }
        }

        $config = $config ?? [];
        if (!isset($config['request_cache'])) {
            try {
                $fromApp = function_exists('config') ? config('request_cache.request_cache') : null;
            } catch (\Throwable $e) {
                $fromApp = null;
            }
            if (is_array($fromApp) && $fromApp !== []) {
                $config['request_cache'] = $fromApp;
            } else {
                $defaults = $this->loadConfigFile();
                if (is_array($defaults['request_cache'] ?? null)) {
                    $config['request_cache'] = $defaults['request_cache'];
                }
            }
        }

        //走应用配置时直接并入全局，实例本身不留覆盖层，这样运行时改全局配置仍能影响该实�?
        $overrides = $config;
        if ($usesApplicationConfig) {
            self::loadConfig($config);
            $overrides = [];
        }

        $this->cacheConfig = CacheConfig::make($overrides);
        $this->filterConfig = FilterConfig::make($overrides);

        //Laravel 环境配置
        $this->appName = self::envValue('APP_NAME', 'laravel');
        $this->appEnv = self::envValue('APP_ENV', 'local');
        $this->prefix = self::resolvePrefix($config, $this->cacheConfig->redisCluster());

        //加载配置
        if (isset($config['request_cache'])) {
            $requestCacheConfig = $config['request_cache'];

            if (isset($requestCacheConfig['default_expire'])) {
                $this->defaultExpire = $requestCacheConfig['default_expire'];
            }

            if (isset($requestCacheConfig['force_validate'])) {
                $this->forceValidate = $requestCacheConfig['force_validate'];
            } else {
                $this->forceValidate = false;
            }

            if (isset($requestCacheConfig['enable_stats'])) {
                $this->enableStats = $requestCacheConfig['enable_stats'];
            } else {
                $this->enableStats = !empty($this->cacheConfig->stats()['enabled']);
            }

            if (isset($requestCacheConfig['enable_logging'])) {
                CacheLogger::setEnabled((bool) $requestCacheConfig['enable_logging']);
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

        $this->localCache = new LocalCache($this->cacheConfig->localCache());

        $this->clusterNodeResolver = new RedisClusterNodeResolver($this->cacheConfig->redisCluster());
    }

    /**
     * 克隆时沿用同一�?LocalCache
     *
     * 所有链式修饰符（tags/version/sizeLimit/encryptData/enableStats/cluster）都
     * 返回克隆，`tags('users')->set(...)` 这类常见写法每次调用都会克隆一次。若�?
     * 这里重建 LocalCache，克隆写入的进程内副本会随即被丢弃，进程内缓存对链式
     * 调用等于失效。localCacheKey() 已按连接名分命名空间，多集群之间不会串�?
     *
     * 标签**�?*清空：`tags('users')->cluster('gz')->set(...)` �?
     * `cluster('gz')->tags('users')->set(...)` 都是自然写法，清空会让前者静默丢�?
     * 标签——写入不进任何标签索引，事后 clearTags('users') 也清不掉它。标签本就是
     * 一次性写入修饰符，由 consumeTags() 在下一次写入时取走，带进克隆不会滞留�?
     */
    public function __clone()
    {
        $this->clusterNodeResolver = new RedisClusterNodeResolver(
            $this->cacheConfig->redisCluster(),
            $this->connectionName
        );
    }
    /**
     * 获取当前实例生效的缓存配�?
     * @return CacheConfig
     */
    public function cacheConfig(): CacheConfig
    {
        return $this->cacheConfig;
    }

    /**
     * 设置强制校验字符开�?
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
     * @return static
     */
    public function tags($tags, ...$additionalTags)
    {
        $clone = clone $this;
        $clone->tags = is_array($tags) ? $tags : array_merge([$tags], $additionalTags);

        return $clone;
    }

    /**
     * 启用/禁用缓存统计
     * @param bool $enable
     * @return static
     */
    public function enableStats(bool $enable)
    {
        $clone = clone $this;
        $clone->enableStats = $enable;

        return $clone;
    }

    /**
     * 启用/禁用数据加密
     * @param bool $encrypt
     * @return static
     */
    public function encryptData(bool $encrypt)
    {
        $clone = clone $this;
        $clone->encryptData = $encrypt;

        return $clone;
    }

    /**
     * 设置缓存版本
     * @param string $version
     * @return static
     */
    public function version(string $version)
    {
        $clone = clone $this;
        $clone->version = $version;

        return $clone;
    }

    /**
     * 设置缓存大小限制
     * @param int $sizeLimit
     * @return static
     */
    public function sizeLimit(int $sizeLimit)
    {
        $clone = clone $this;
        $clone->sizeLimit = $sizeLimit;

        return $clone;
    }

    /**
     * 绑定目标 Redis 集群，返回携带绑定的克隆实例
     * @param string $name Laravel Redis 连接�?
     * @return static
     */
    public function cluster(string $name): static
    {
        $this->assertClusterSwitchingEnabled();
        $this->assertConnectionAllowed($name);

        //返回克隆实例而非 $this：request-cache 注册为容器单例，写在 $this 上会让绑定跨调用粘连
        $clone = clone $this;
        $clone->connectionName = $name;
        $clone->clusterNodeResolver = new RedisClusterNodeResolver($this->cacheConfig->redisCluster(), $name);

        return $clone;
    }

    /**
     * 获取当前有效 Redis 连接�?
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
     * 获取 Redis 客户端适配�?
     * @return RedisClientAdapter
     */
    protected function adaptedConnection(): RedisClientAdapter
    {
        return RedisClientAdapter::wrap($this->connection());
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
     * 解析配置中的默认连接�?
     * @return string
     */
    protected function defaultConnectionName(): string
    {
        $name = $this->cacheConfig->redisCluster()['default_connection'] ?? CacheConfig::DEFAULT_CONNECTION;

        return is_string($name) && $name !== '' ? $name : CacheConfig::DEFAULT_CONNECTION;
    }

    /**
     * 允许手动绑定的连接名白名�?
     * @return array
     */
    protected function allowedConnections(): array
    {
        $configured = $this->cacheConfig->redisCluster()['connections'] ?? [];
        if (!empty($configured)) {
            return array_values($configured);
        }

        try {
            $redisConfig = config('database.redis', []);
        } catch (\Throwable $e) {
            $redisConfig = [];
        }
        $redisConfig = is_array($redisConfig) ? $redisConfig : [];

        $names = [];
        foreach ($redisConfig as $key => $value) {
            //排除 options �?clusters 两个非连接键，并�?is_array 过滤 client 这类标量�?
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
        if (empty($this->cacheConfig->redisCluster()['enabled'])) {
            throw new \LogicException('Redis cluster switching is disabled.');
        }
    }

    /**
     * 生成带集群前缀的本地缓�?key
     * @param string $key generateKey() 产出�?Redis key
     * @return string
     */
    protected function localCacheKey(string $key): string
    {
        return hash('sha256', $this->effectiveConnectionName()) . ':' . $key;
    }

    /**
     * 清空当前连接命名空间下的进程内缓�?
     *
     * 所有实例（含各链式修饰符产生的克隆）共用一�?LocalCache，直�?flush()
     * 会把其它集群仍然有效的副本一起丢掉，白白多打一�?Redis�?
     */
    protected function flushLocalNamespace(): void
    {
        $this->localCache->flushPrefix($this->localCacheKey(''));
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
        $clusterConfig = $this->cacheConfig->redisCluster();
        return !empty($clusterConfig['enabled']) && !empty($clusterConfig['cluster_safe_mode']);
    }

    /**
     * 是否允许 Redis 写失败后写入本地缓存兜底
     * @return bool
     */
    protected function allowsLocalFallbackWrite(): bool
    {
        $strategy = $this->cacheConfig->strategy();
        return !empty($strategy['fallback']) && empty($strategy['shared_mode']);
    }

    /**
     * 判断异常是否来自 Redis Cluster �?slot
     * @param \Throwable $e
     * @return bool
     */
    protected function isCrossSlotException(\Throwable $e)
    {
        return stripos($e->getMessage(), 'CROSSSLOT') !== false;
    }

    /**
     * 存储信封版本�?
     */
    const ENVELOPE_VERSION = 1;

    /**
     * 解码 Redis 中存储的缓存�?
     *
     * 返回命中条目而不是裸数据：裸数据无法区分「没有这�?key」与「缓存的就是
     * null / 0」，会让空结果永远缓存不住，形成穿透�?
     *
     * @param mixed $value
     * @return array|null ['data' => mixed, 'expires_at' => int|null]，未命中返回 null
     */
    protected function decodeStoredValue($value)
    {
        if ($value === null || $value === false || $value === '') {
            return null;
        }

        $originalValue = $value;
        if ($this->encryptData && function_exists('decrypt')) {
            try {
                $value = decrypt($value);
            } catch (\Throwable $e) {
                //解密失败，使用原始�?
                $value = $originalValue;
            }
        }

        if (is_string($value) && str_starts_with($value, 's:')) {
            $payload = @unserialize(substr($value, 2), ['allowed_classes' => false]);
            if (is_array($payload)
                && ($payload['v'] ?? null) === self::ENVELOPE_VERSION
                && array_key_exists('d', $payload)
            ) {
                return [
                    'data' => $payload['d'],
                    'expires_at' => isset($payload['e']) ? (int) $payload['e'] : null,
                ];
            }

            return null;
        }

        $decoded = json_decode($value, true);

        if (is_array($decoded)
            && ($decoded['v'] ?? null) === self::ENVELOPE_VERSION
            && array_key_exists('d', $decoded)
        ) {
            return [
                'data' => $decoded['d'],
                'expires_at' => isset($decoded['e']) ? (int) $decoded['e'] : null,
            ];
        }

        //兼容 1.0.5 之前写入的裸 JSON；解析失败按未命中处理，避免把损坏的值当成缓存的 null
        if ($decoded === null && strtolower(trim((string) $value)) !== 'null') {
            return null;
        }

        return ['data' => $decoded, 'expires_at' => null];
    }

    /**
     * 编码即将写入 Redis 的缓存�?
     * @param mixed $data
     * @param int|null $expire 剩余生存秒数，用于本地缓存对�?Redis 过期时间
     * @return string|false
     */
    protected function encodeStoredValue($data, int $expire = null)
    {
        $envelope = ['v' => self::ENVELOPE_VERSION, 'd' => $data];
        if ($expire !== null && $expire > 0) {
            $envelope['e'] = time() + $expire;
        }

        //JSON_PRESERVE_ZERO_FRACTION：否�?1.0 会被写成 1，读回来变成 int�?
        //调用方存进去的浮点在往返后静默换了类型
        $jsonData = json_encode($envelope, JSON_PRESERVE_ZERO_FRACTION);
        if ($jsonData === false) {
            $payload = 's:' . serialize($envelope);
            CacheLogger::warning('json_encode failed for cache value, using serialize envelope');
            if (strlen($payload) > $this->sizeLimit) {
                CacheLogger::warning('serialized cache value exceeds size limit', [
                    'size' => strlen($payload),
                    'limit' => $this->sizeLimit,
                ]);
                return false;
            }
            $jsonData = $payload;
        } elseif (strlen($jsonData) > $this->sizeLimit) {
            CacheLogger::warning('cache value exceeds size limit', [
                'size' => strlen($jsonData),
                'limit' => $this->sizeLimit,
            ]);
            return false;
        }

        if ($this->encryptData && function_exists('encrypt')) {
            $jsonData = encrypt($jsonData);
            if (strlen($jsonData) > $this->sizeLimit) {
                CacheLogger::warning('encrypted cache value exceeds size limit', [
                    'size' => strlen($jsonData),
                    'limit' => $this->sizeLimit,
                ]);
                return false;
            }
        }

        return $jsonData;
    }

    /**
     * 计算本地缓存副本可以存活多久
     *
     * 本地副本绝不能比 Redis 上的值活得更久，否则常驻进程（Octane、队�?worker�?
     * 会在 Redis key 过期后继续返回旧值�?
     *
     * @param int|null $expiresAt
     * @return int|null
     */
    protected function localCacheTtl($expiresAt)
    {
        $localTtl = $this->cacheConfig->localCache()['ttl'] ?? 300;

        if ($expiresAt === null) {
            return null;
        }

        $remaining = $expiresAt - time();

        return $remaining < $localTtl ? $remaining : null;
    }

    /**
     * 计算写入路径上本地缓存副本可以存活多�?
     *
     * 写入时若直接沿用 Redis �?TTL，常驻进程（Octane、队�?worker）会在别�?
     * 实例改写或删�?Redis 上的值之后，继续按那�?TTL 返回旧值——`set()` �?
     * 3600 就意味着最长一小时的进程内陈旧窗口。读取路径已经用 localCacheTtl()
     * 把副本压�?local_cache.ttl 以内，写入路径必须用同一个上限�?
     *
     * @param int $expire Redis 上的生存秒数
     * @return int|null
     */
    protected function localWriteTtl(int $expire)
    {
        return $this->localCacheTtl(time() + $expire);
    }

    /**
     * 过滤参数�?
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
            if ($this->filterConfig->shouldTrim()) {
                $value = trim($value);
            }

            //移除 HTML 标签
            if ($this->filterConfig->shouldRemoveHtml()) {
                $value = strip_tags($value);
            }

            //移除 SQL 语句关键�?
            $sqlKeywords = $this->filterConfig->sqlKeywords();
            if (!empty($sqlKeywords)) {
                //构建单次正则表达式，减少多次 preg_replace 调用
                $keywordsPattern = '/\b(' . implode('|', $sqlKeywords) . ')\b/i';
                $value = preg_replace($keywordsPattern, '', $value);
            }

            //移除特殊字符，只保留字母、数字、下划线和中文字�?
            $value = preg_replace($this->filterConfig->allowedCharsPattern(), '', $value);

            //移除潜在�?XSS 攻击向量
            $value = preg_replace($regexPatterns['javascript'], '', $value);
            $value = preg_replace($regexPatterns['onEvents'], '', $value);

            //限制字符串长度，防止过长输入
            $maxLength = 1000;
            if (strlen($value) > $maxLength) {
                $value = substr($value, 0, $maxLength);
            }

            //应用自定义过滤规�?
            $customFilters = $this->filterConfig->customFilters();
            foreach ($customFilters as $filter) {
                $value = call_user_func($filter, $value);
            }
        } elseif (is_array($value)) {
            //递归过滤数组
            foreach ($value as $key => $val) {
                $value[$key] = $this->filterValue($val);
            }

            //限制数组深度，防止嵌套过�?
            $this->limitArrayDepth($value, 5);
        } elseif (is_numeric($value)) {
            //限制数值范围，防止过大或过小的�?
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
     * gateway 可读片段的原始串指纹长度（hex 字符数）
     */
    const GATEWAY_TOKEN_LENGTH = 12;

    /**
     * 清洗 gateway 名称，并追加原始串指�?
     *
     * 生成 key 与按 pattern 清理必须走同一套规则，否则写进去的 key 清不掉，
     * �?gateway 中的通配符还会扩�?SCAN 的匹配范围�?
     *
     * 但清洗本身是有损的，�?gateway 是直接参�?HMAC 的：`user.profile` �?
     * `userprofile` 会塌缩成同一个片段，任何�?ASCII 名字更是被清成空串�?
     * 塌缩就意味着两个不同接口算出同一个缓�?key、互相读到对方的数据。因�?
     * 在可读片段后固定追加一段原始串指纹。clearGateway() 复用本函数，所�?
     * 仍能精确命中单个 gateway，而不会误清同名塌缩的其它 gateway�?
     *
     * @param string $gateway
     * @return string
     */
    public static function sanitizeGateway(string $gateway): string
    {
        $sanitized = preg_replace('/[^a-zA-Z0-9_\-]/', '', $gateway);
        $token = substr(hash('sha256', $gateway), 0, self::GATEWAY_TOKEN_LENGTH);

        return $sanitized === '' ? 'g' . $token : $sanitized . '-' . $token;
    }

    /**
     * 转义 SCAN MATCH pattern 中的字面量片�?
     *
     * pattern 由字面量（Laravel redis prefix、缓�?prefix、version）和我们自己
     * 拼上去的 `*` 组成。字面量里如果含 `*` `?` `[` `]` `\`，Redis 会把它们当成
     * 通配符，�?clearAll() / clearGateway() 的匹配范围超出本应用。gateway 已由
     * sanitizeGateway() 收敛�?[A-Za-z0-9_-]，无需转义�?
     *
     * @param string $literal
     * @return string
     */
    public static function escapeGlobLiteral(string $literal): string
    {
        return addcslashes($literal, '*?[]\\');
    }

    /**
     * 递归按键名排序，使参数顺序不影响缓存 key
     * @param array $params
     * @return array
     */
    protected static function sortParams(array $params): array
    {
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                $params[$key] = self::sortParams($value);
            }
        }

        ksort($params);

        return $params;
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
     * 把任意入参序列化成用于哈希的规范字符�?
     *
     * 不能直接�?json_encode()：它对非�?UTF-8、NAN/INF、超�?512 层的嵌套都会
     * 返回 false，�?false 传进 hash() 会被当成空字符串，所有这类入参就塌缩�?
     * 同一个缓�?key，进而把别人的数据返回给当前请求。serialize() 原样保留字节
     * 且不会失败，用它兜底；两种编码各自带前缀，避免跨编码撞串�?
     *
     * JSON_PRESERVE_ZERO_FRACTION 用于区分 1 �?1.0：缺了它两者都编码�?`1`�?
     * 参数只差类型的两次请求会算出同一个缓�?key�?
     *
     * @param mixed $value
     * @return string
     */
    protected static function canonicalize($value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);

        return $encoded === false ? 's:' . serialize($value) : 'j:' . $encoded;
    }

    /**
     * 生成缓存 key
     * @param string $gateway
     * @param array $params
     * @return string
     */
    public function generateKey(string $gateway, array $params)
    {
        //过滤 gateway 参数，只允许字母、数字、下划线和连字符
        $sanitizedGateway = self::sanitizeGateway($gateway);

        //参数指纹取自原始入参：filterValue() 是有损清洗，只用清洗结果做哈希会�?
        //不同的入参塌缩成同一�?key（例如所有大�?1000000 �?ID、所有只�?SQL
        //关键字或标点的字符串），进而把别人的缓存返回给当前请求�?
        $fingerprint = hash('sha256', self::canonicalize(self::sortParams($params)));

        $filteredParams = $params;
        if ($this->forceValidate) {
            foreach ($filteredParams as $key => $value) {
                $filteredParams[$key] = $this->filterValue($value);
            }
        }
        $filteredParams = self::sortParams($filteredParams);

        //使用 HMAC-SHA256 生成更安全的缓存�?
        $keyData = [$this->version, $sanitizedGateway, $filteredParams, $fingerprint];
        //Laravel 环境配置
        try {
            $hashKey = config('app.key', 'default_cache_key') ?: 'default_cache_key';
        } catch (\Throwable $e) {
            //Laravel config function fails, use default
            $hashKey = 'default_cache_key';
        }
        $hash = hash_hmac('sha256', self::canonicalize($keyData), $hashKey);
        //在缓�?key 中包含版本信�?
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
        $entry = $this->getEntry($gateway, $params);

        return $entry === null ? null : $entry['data'];
    }

    /**
     * 获取缓存条目
     *
     * �?get() 的区别在于能区分「未命中」与「命中且值为 null」，remember() 依赖
     * 这一点才能把空结果缓存住�?
     *
     * @param string $gateway
     * @param array $params
     * @return array|null ['data' => mixed, 'expires_at' => int|null]
     */
    protected function getEntry(string $gateway, array $params)
    {
        $key = $this->generateKey($gateway, $params);
        $localKey = $this->localCacheKey($key);
        $strategy = $this->cacheConfig->strategy();

        //尝试从本地缓存获�?
        if ($this->localCache->has($localKey)) {
            return ['data' => $this->localCache->get($localKey), 'expires_at' => null];
        }

        //尝试从主缓存（Redis）获�?
        if ($strategy['primary'] === 'redis') {
            try {
                //使用 Laravel Redis 连接
                $redis = $this->connection();
                $entry = $this->decodeStoredValue($redis->get($key));

                if ($entry !== null) {
                    if ($entry['expires_at'] !== null && $entry['expires_at'] <= time()) {
                        return null;
                    }

                    //将数据同步到本地缓存，存活时间不超过 Redis 上的剩余时间
                    $this->localCache->set($localKey, $entry['data'], $this->localCacheTtl($entry['expires_at']));

                    return $entry;
                }
            } catch (\Throwable $e) {
                //Redis 异常，本地缓存兜底已在上面处�?
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
        $strategy = $this->cacheConfig->strategy();

        //先尝试从本地缓存获取；未命中项保�?null 占位，保证返回值与入参下标一一对应
        foreach ($items as $index => [$gateway, $params]) {
            $key = $this->generateKey($gateway, $params);
            $localKey = $this->localCacheKey($key);
            $result[$index] = null;

            if ($this->localCache->has($localKey)) {
                $result[$index] = $this->localCache->get($localKey);
            } else {
                $keysToGet[] = $key;
                $keyMap[$key][] = $index;
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
                    $entry = $this->decodeStoredValue($values[$i] ?? null);
                    if ($entry === null) {
                        continue;
                    }

                    if ($entry['expires_at'] !== null && $entry['expires_at'] <= time()) {
                        continue;
                    }

                    //将数据同步到本地缓存
                    $this->localCache->set($this->localCacheKey($key), $entry['data'], $this->localCacheTtl($entry['expires_at']));

                    //添加到结�?
                    if (isset($keyMap[$key])) {
                        foreach ($keyMap[$key] as $idx) {
                            $result[$idx] = $entry['data'];
                        }
                    }
                }
            } catch (\Throwable $e) {
                if (!$this->isCrossSlotException($e)) {
                    return $result;
                }

                foreach ($keysToGet as $key) {
                    try {
                        $redis = $this->connection();
                        $entry = $this->decodeStoredValue($redis->get($key));
                        if ($entry === null) {
                            continue;
                        }

                        if ($entry['expires_at'] !== null && $entry['expires_at'] <= time()) {
                            continue;
                        }

                        $this->localCache->set($this->localCacheKey($key), $entry['data'], $this->localCacheTtl($entry['expires_at']));
                        if (isset($keyMap[$key])) {
                            foreach ($keyMap[$key] as $idx) {
                                $result[$idx] = $entry['data'];
                            }
                        }
                    } catch (\Throwable $ignored) {
                        //忽略�?key 读取异常
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
        $tags = $this->consumeTags();

        try {
            $key = $this->generateKey($gateway, $params);
            $expire = $expire ?? $this->defaultExpire * 60;
            $jsonData = $this->encodeStoredValue($data, $expire);
            if ($jsonData === false) {
                return false;
            }

            // 使用 Laravel Redis 连接
            $redis = $this->connection();
            $result = $redis->setex($key, $expire, $jsonData);

            //Redis 明确拒绝写入（OOM、只读副本等）时不抛异常，只返回 false�?
            //继续往下走会在返回 false 的同时留下本地副本和标签索引条目�?
            //shared_mode 下调用方拿到失败却仍能从本进程读到数据�?
            if ($result === false) {
                CacheLogger::warning('Redis rejected the cache write', ['key' => $key]);

                return false;
            }

            //保存标签关联
            foreach ($tags as $tag) {
                $this->touchTagIndex($redis, $this->buildTagKey($tag), $key, $expire);
            }

            //将数据同步到本地缓存
            $this->localCache->set($this->localCacheKey($key), $data, $this->localWriteTtl($expire));

            return $result;
        } catch (\Throwable $e) {
            //Redis 异常时，尝试使用本地缓存作为回退
            CacheLogger::warning('Redis set failed, falling back to local cache', [
                'error' => $e->getMessage(),
            ]);
            $key = $this->generateKey($gateway, $params);
            $expire = $expire ?? $this->defaultExpire * 60;

            if (!$this->allowsLocalFallbackWrite()) {
                return false;
            }

            $this->localCache->set($this->localCacheKey($key), $data, $this->localWriteTtl($expire));
            return true;
        }
    }

    /**
     * 取出并清空本次操作的标签
     *
     * 标签只对紧随其后的一次写入生效；不清空会让容器单例上的标签持续附加到
     * 后续所有写入上�?
     *
     * @return array
     */
    protected function consumeTags(): array
    {
        $tags = $this->tags;
        $this->tags = [];

        return $tags;
    }

    /**
     * 把缓�?key 记入标签索引
     *
     * 索引�?ZSET 而不�?SET：成员的 score 存该 key 的绝对过期时间，每次写入
     * 顺手剔除已过期的成员。用 SET 时成员只增不减，而标签本身的 TTL 又被每次
     * 写入续期，热标签的索引会永不过期地无限膨胀�?
     *
     * @param mixed $redis
     * @param string $tagKey
     * @param string $cacheKey
     * @param int $expire
     */
    protected function touchTagIndex($redis, string $tagKey, string $cacheKey, int $expire): void
    {
        $now = time();

        // 部分客户端对 WRONGTYPE 只返�?false 不抛异常；先看类型再决定是否重建
        $type = null;
        try {
            $type = $redis->type($tagKey);
        } catch (\Throwable $e) {
            $type = null;
        }

        $isSet = $type === 2 || $type === 'set' || $type === 'SET';
        if ($isSet) {
            $redis->del($tagKey);
        }

        try {
            $redis->zadd($tagKey, $now + $expire, $cacheKey);
        } catch (\Throwable $e) {
            if (!$this->isWrongTypeException($e)) {
                throw $e;
            }

            //1.0.6 之前标签索引�?SET，遇到遗留结构直接重�?
            $redis->del($tagKey);
            $redis->zadd($tagKey, $now + $expire, $cacheKey);
        }

        //剔除已过期成员，索引大小跟随存活条目而不是历史写入总量
        try {
            $redis->zremrangebyscore($tagKey, '-inf', (string) $now);
            $redis->expire($tagKey, $expire + 3600);
        } catch (\Throwable $e) {
            if ($this->isWrongTypeException($e)) {
                $redis->del($tagKey);
                $redis->zadd($tagKey, $now + $expire, $cacheKey);
                $redis->expire($tagKey, $expire + 3600);
            } else {
                throw $e;
            }
        }
    }

    /**
     * 读取标签索引中的缓存 key
     * @param mixed $redis
     * @param string $tagKey
     * @return array
     */
    protected function readTagIndex($redis, string $tagKey): array
    {
        try {
            $members = $redis->zrange($tagKey, 0, -1);
        } catch (\Throwable $e) {
            if (!$this->isWrongTypeException($e)) {
                throw $e;
            }

            //兼容 1.0.6 之前写入�?SET 结构
            $members = $redis->smembers($tagKey);
        }

        return is_array($members) ? $members : [];
    }

    /**
     * 判断异常是否来自 Redis 的类型不匹配
     * @param \Throwable $e
     * @return bool
     */
    protected function isWrongTypeException(\Throwable $e): bool
    {
        return stripos($e->getMessage(), 'WRONGTYPE') !== false;
    }

    /**
     * 批量设置缓存
     * @param array $items 格式：[[gateway, params, data, expire], [gateway, params, data, expire], ...]
     * @return array 格式：[true, false, true, ...] 对应每个设置操作的结�?
     */
    public function mset(array $items)
    {
        $results = [];
        $pipeline = null;
        $pipelineWrites = [];
        $strategy = $this->cacheConfig->strategy();
        $tags = $this->consumeTags();

        try {
            $redis = null;
            if ($strategy['primary'] === 'redis' && !$this->isClusterSafeMode()) {
                $redis = $this->connection();
                $pipeline = RedisClientAdapter::wrap($redis)->pipelineOrNull();
            }

            foreach ($items as $index => $item) {
                $gateway = $item[0];
                $params = $item[1];
                $data = $item[2];
                $expire = isset($item[3]) ? $item[3] : null;
                $expire = $expire ?? $this->defaultExpire * 60;
                $key = $this->generateKey($gateway, $params);
                $jsonData = $this->encodeStoredValue($data, $expire);
                if ($jsonData === false) {
                    $results[$index] = false;
                    continue;
                }

                if ($pipeline) {
                    $pipeline->setex($key, $expire, $jsonData);
                    //入队顺序�?exec() 返回值顺序，靠位置把响应对回原始下标
                    $pipelineWrites[] = [$index, $key, $data, $expire, $tags];
                    continue;
                }

                $this->tags = $tags;
                $results[$index] = $this->set($gateway, $params, $data, $expire);
            }

            if ($pipeline) {
                $responses = $pipeline->exec();
                foreach ($pipelineWrites as $position => [$index, $key, $data, $expire, $itemTags]) {
                    $results[$index] = $this->pipelineWriteSucceeded($responses, $position);
                    if (!$results[$index]) {
                        continue;
                    }

                    foreach ($itemTags as $tag) {
                        $this->touchTagIndex($redis, $this->buildTagKey($tag), $key, $expire);
                    }

                    $this->localCache->set($this->localCacheKey($key), $data, $this->localWriteTtl($expire));
                }
            }
        } catch (\Throwable $e) {
            if (!$this->isCrossSlotException($e)) {
                CacheLogger::warning('Redis mset failed, falling back to local cache', [
                    'error' => $e->getMessage(),
                ]);
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

                    $this->localCache->set($this->localCacheKey($key), $data, $this->localWriteTtl($expire));
                    $results[$index] = true;
                }
                return $this->orderResultsByInput($results, $items);
            }

            foreach ($items as $index => $item) {
                $expire = isset($item[3]) ? $item[3] : null;
                $this->tags = $tags;
                $results[$index] = $this->set($item[0], $item[1], $item[2], $expire);
            }
        }

        return $this->orderResultsByInput($results, $items);
    }

    /**
     * �?mset() 的结果按入参顺序排列
     *
     * 管道路径的结果要�?exec() 之后才能回填，而编码阶段就失败的条目（超出
     * size_limit、非 UTF-8）在那之前就已写进数组，两者混在一批里会让返回值的
     * 下标顺序与入参不符。下标本身是对的，但 array_values()�?== 比较�?
     * array_combine($ids, $results) 这类用法会因此静默错位�?
     *
     * @param array $results
     * @param array $items mset() 的原始入参，其键顺序即期望顺�?
     * @return array
     */
    protected function orderResultsByInput(array $results, array $items): array
    {
        $ordered = [];

        foreach (array_keys($items) as $index) {
            if (array_key_exists($index, $results)) {
                $ordered[$index] = $results[$index];
            }
        }

        //入参之外的下标理论上不存在，真出现了也不能丢
        foreach ($results as $index => $result) {
            if (!array_key_exists($index, $ordered)) {
                $ordered[$index] = $result;
            }
        }

        return $ordered;
    }

    /**
     * 判断管道中第 N 条写入是否成�?
     *
     * setex 失败�?phpredis 在响应数组里�?false，predis 放状态对象；只有明确
     * 拿到 false 才算失败。客户端不返回数组时无从判断，按成功处理——总比�?
     * 所有成功写入报成失败要好�?
     *
     * @param mixed $responses pipeline exec() 的返回�?
     * @param int $position 命令入队位置
     * @return bool
     */
    protected function pipelineWriteSucceeded($responses, int $position): bool
    {
        if (!is_array($responses)) {
            return true;
        }

        $responses = array_values($responses);

        return !array_key_exists($position, $responses) || $responses[$position] !== false;
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
            //删除条数不作为成功判据：key 可能已自然过期，DEL 返回 0 并不代表
            //删除失败。与 clearGateway()/clearTags() 保持同一套语义，只有 Redis
            //异常才算失败�?
            $this->connection()->del($key);

            return true;
        } catch (\Throwable $e) {
            CacheLogger::warning('delete failed', [
                'error' => $e->getMessage(),
            ]);

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
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * 解析 SCAN 游标初�?
     *
     * phpredis 要求游标�?null 起始：传�?0 �?'0' 会被判定为迭代已结束并立刻返�?
     * false，导致整轮扫描一�?key 都取不到。predis 则需要字面量 '0'�?
     *
     * @param mixed $redis
     * @return string|null
     */
    public static function initialScanCursor($redis)
    {
        return $redis instanceof PhpRedisConnection ? null : '0';
    }

    /**
     * 判断 SCAN 是否已遍历完�?
     * @param mixed $cursor
     * @return bool
     */
    public static function isScanCursorFinished($cursor): bool
    {
        return $cursor === null || (int) $cursor === 0;
    }

    /**
     * 使用指定连接分批删除�?
     * @param mixed $redis
     * @param array $keys
     * @param int $batchSize
     * @param bool|null $failed 出参：是否有批次删除失败
     * @return int
     */
    protected function batchDeleteOnConnection($redis, array $keys, int $batchSize = 1000, &$failed = null): int
    {
        $deleted = 0;
        $failed = false;
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
            } catch (\Throwable $e) {
                if (!$this->isCrossSlotException($e)) {
                    $failed = true;
                    CacheLogger::warning('batch delete failed', [
                        'keys' => count($batch),
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }

                foreach ($batch as $key) {
                    try {
                        $keyWithoutPrefix = $redisPrefix
                            ? preg_replace('/^' . preg_quote($redisPrefix, '/') . '/', '', $key)
                            : $key;
                        $deleted += $redis->del($keyWithoutPrefix);
                    } catch (\Throwable $ignored) {
                        //�?key 删除失败不中断整批，但要让调用方知道清理不完�?
                        $failed = true;
                    }
                }
            }
        }

        return $deleted;
    }

    /**
     * 分批删除�?
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
     * �?pattern 清理缓存
     * @param string $pattern
     * @return bool
     */
    protected function clearByPattern(string $pattern): bool
    {
        $connections = $this->clusterNodeResolver->scanConnections();
        $failedNodes = 0;

        foreach ($connections as $redis) {
            if ($this->scanAndDeleteOnConnection($redis, $pattern)['failed']) {
                $failedNodes++;
            }
        }

        $this->flushLocalNamespace();

        //删除条数不作为成功判据：SCAN 命中�?key 可能�?DEL 之前就自然过期，
        //deleted < found 并不代表清理失败。这�?clearTags() 同一套判据�?
        //但反过来，节点扫描或删除真的抛了异常就必须体现在返回值上——旧判据
        //（found === 0 || deleted > 0）会把「第一�?SCAN 就抛异常、一�?key �?
        //没扫到」报成成功�?
        return $failedNodes === 0;
    }

    /**
     * 边扫描边删除
     *
     * 不先把全�?key 收集到内存再删：那样既受 batchSize 上限约束会静默漏删，
     * 也会在大 keyspace 上占用大量内存�?
     *
     * @param mixed $redis
     * @param string $pattern
     * @param int $count 单轮 SCAN 的游标步�?
     * @param int $batchSize 单次 DEL 的最�?key �?
     * @return array{found:int, deleted:int, failed:bool}
     */
    protected function scanAndDeleteOnConnection($redis, string $pattern, int $count = 1000, int $batchSize = 1000): array
    {
        $found = 0;
        $deleted = 0;
        $failed = false;
        $batchFailed = false;
        $cursor = self::initialScanCursor($redis);
        //$pattern 里的 `*` 是我们自己拼的通配符，Laravel �?redis prefix 是字面量
        $fullPattern = self::escapeGlobLiteral($this->redisPrefix()) . $pattern;
        $buffer = [];

        try {
            do {
                $result = $redis->scan($cursor, ['match' => $fullPattern, 'count' => $count]);

                if ($result === false) {
                    break;
                }

                $cursor = $result[0];
                $buffer = array_merge($buffer, $result[1]);
                $found += count($result[1]);

                if (count($buffer) >= $batchSize) {
                    $deleted += $this->batchDeleteOnConnection($redis, $buffer, $batchSize, $batchFailed);
                    $failed = $failed || $batchFailed;
                    $buffer = [];
                }
            } while (!self::isScanCursorFinished($cursor));

            if (!empty($buffer)) {
                $deleted += $this->batchDeleteOnConnection($redis, $buffer, $batchSize, $batchFailed);
                $failed = $failed || $batchFailed;
            }
        } catch (\Throwable $e) {
            //某个节点失败时保留已删除计数，由调用方按整体结果判断
            $failed = true;
            CacheLogger::warning('scan-and-delete failed on connection', [
                'pattern' => $pattern,
                'found' => $found,
                'deleted' => $deleted,
                'error' => $e->getMessage(),
            ]);
        }

        return ['found' => $found, 'deleted' => $deleted, 'failed' => $failed];
    }

    /**
     * 清除指定网关的所有缓�?
     * @param string $gateway
     * @param bool $allVersions
     * @return bool
     */
    public function clearGateway(string $gateway, bool $allVersions = false)
    {
        try {
            //�?generateKey() 使用同一套清洗规则：既保证含特殊字符�?gateway 能被清掉�?
            //也防�?gateway 里的 * ? [ 直接进入 SCAN pattern 扩大删除范围
            $sanitizedGateway = self::sanitizeGateway($gateway);
            if ($sanitizedGateway === '') {
                return false;
            }

            //prefix �?version 是字面量，其中的 glob 元字符必须转�?
            $prefix = self::escapeGlobLiteral($this->prefix);

            if ($allVersions) {
                //清除所有版本的缓存
                $pattern = $prefix . '*:' . $sanitizedGateway . ':*';
            } else {
                //只清除当前版本的缓存
                $pattern = $prefix . self::escapeGlobLiteral((string) $this->version)
                    . ':' . $sanitizedGateway . ':*';
            }
            return $this->clearByPattern($pattern);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 清除指定标签的缓�?
     * @param array|string $tags
     * @return bool
     */
    public function clearTags($tags)
    {
        try {
            $tags = is_array($tags) ? $tags : func_get_args();
            if ($tags === []) {
                $this->flushLocalNamespace();

                return true;
            }

            //使用 Laravel Redis 连接
            $redis = $this->connection();
            $tagKeys = [];
            $keys = null;

            foreach ($tags as $tag) {
                $tagKey = $this->buildTagKey($tag);
                $tagKeys[] = $tagKey;
                $members = $this->readTagIndex($redis, $tagKey);
                $keys = $keys === null ? $members : array_values(array_intersect($keys, $members));
            }

            if (!empty($keys)) {
                $this->batchDelete($keys);
            }

            if (count($tagKeys) === 1) {
                //单标签：整张索引一起清�?
                $redis->del($tagKeys[0]);
            } else {
                //多标签取交集：只从各索引摘掉被删成员，保留只挂在单个标签上的 key
                foreach ($tagKeys as $tagKey) {
                    $this->detachKeysFromTagIndex($redis, $tagKey, $keys ?? []);
                }
            }

            $this->flushLocalNamespace();

            //删除条数不作为成功判据：索引里的 key 可能已经自然过期，DEL 返回 0
            //并不代表清理失败。只�?Redis 异常才算失败，走下面�?catch�?
            return true;
        } catch (\Throwable $e) {
            CacheLogger::warning('clearTags failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * 从标签索引中移除指定缓存 key（兼�?ZSET 与遗�?SET�?
     * @param mixed $redis
     * @param string $tagKey
     * @param array $cacheKeys
     */
    protected function detachKeysFromTagIndex($redis, string $tagKey, array $cacheKeys): void
    {
        if ($cacheKeys === []) {
            return;
        }

        try {
            $redis->zrem($tagKey, ...array_values($cacheKeys));
        } catch (\Throwable $e) {
            if (!$this->isWrongTypeException($e)) {
                throw $e;
            }

            $redis->srem($tagKey, ...array_values($cacheKeys));
        }
    }

    /**
     * 清除所有缓�?
     * @param bool $allVersions
     * @return bool
     */
    public function clearAll(bool $allVersions = true)
    {
        try {
            //prefix �?version 是字面量，其中的 glob 元字符必须转�?
            $prefix = self::escapeGlobLiteral($this->prefix);

            if ($allVersions) {
                //清除所有版本的缓存
                $pattern = $prefix . '*';
            } else {
                //只清除当前版本的缓存
                $pattern = $prefix . self::escapeGlobLiteral((string) $this->version) . ':*';
            }
            return $this->clearByPattern($pattern);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 记录缓存统计
     * @param string $type
     */
    protected function recordStats(string $type)
    {
        $statsConfig = $this->cacheConfig->stats();
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

            //设置过期时间：全局统计 30 天，每日统计 90 �?
            $redis->expire($globalKey, $statsConfig['globalExpire'] ?? 30 * 24 * 3600);
            $redis->expire($dailyKey, $statsConfig['dailyExpire'] ?? 90 * 24 * 3600);
        } catch (\Throwable $e) {
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
    protected function acquireLock(string $key, int $expire = null, int $retryTimes = null, int $retryDelay = null)
    {
        $lockConfig = $this->cacheConfig->lock();
        $expire = $expire ?? (int) ($lockConfig['expire'] ?? 10);
        $retryTimes = $retryTimes ?? (int) ($lockConfig['retryTimes'] ?? 5);
        $retryDelay = $retryDelay ?? (int) ($lockConfig['retryDelay'] ?? 100000);

        $lockKey = $this->buildLockKey($key);
        $lockValue = Str::random(32); //随机值，防止误释�?

        try {
            //使用 Laravel Redis 连接
            $redis = $this->connection();

            for ($i = 0; $i < max(1, $retryTimes); $i++) {
                if ($redis->set($lockKey, $lockValue, 'EX', $expire, 'NX')) {
                    return $lockValue;
                }
                //自适应重试间隔：指数退�?
                $currentDelay = $retryDelay * (1 << $i);
                //最大重试间隔限制为 1 �?
                $currentDelay = min($currentDelay, 1000000);
                usleep($currentDelay);
            }
        } catch (\Throwable $e) {
            CacheLogger::warning('acquireLock failed due to Redis error', [
                'error' => $e->getMessage(),
            ]);
        }

        CacheLogger::warning('acquireLock exhausted retries', ['key' => $key]);

        return null;
    }

    /**
     * 等待持锁者把结果写入缓存
     *
     * 没有这一步时，重试窗口耗尽的等待方会直接执行回调：回调耗时一旦超过退�?
     * 总时长，防击穿就完全失效，并发进程会各跑一遍回调�?
     *
     * @param string $gateway
     * @param array $params
     * @param string $key
     * @param int|null $lockExpire 持锁者实际持有的 TTL，remember() 会传入拉长后的�?
     * @return array|null 命中的缓存条�?
     */
    protected function waitForCachedEntry(string $gateway, array $params, string $key, int $lockExpire = null)
    {
        $lockConfig = $this->cacheConfig->lock();
        //必须用持锁者实际的 TTL：enable_extend 会把持锁 TTL �?lock.expire 拉长�?
        //expire + extendInterval * 5，等待方却按未拉长的值超时，就会在持锁者还�?
        //跑回调时集体放弃、各自回源——正好是本方法要防的那个场景�?
        $lockExpire = $lockExpire ?? (int) ($lockConfig['expire'] ?? 10);
        $deadline = microtime(true) + $lockExpire;
        $interval = max(10000, (int) ($lockConfig['retryDelay'] ?? 100000));
        $lockKey = $this->buildLockKey($key);

        while (microtime(true) < $deadline) {
            usleep($interval);

            $entry = $this->getEntry($gateway, $params);
            if ($entry !== null) {
                return $entry;
            }

            try {
                //持锁者已经退出（正常释放或异常崩溃），没必要继续�?
                if (!$this->connection()->exists($lockKey)) {
                    return null;
                }
            } catch (\Throwable $e) {
                return null;
            }
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
            $renewed = $redis->eval($script, 1, $lockKey, $lockValue, $expire) > 0;
            if (!$renewed) {
                CacheLogger::warning('renewLock failed', ['key' => $key]);
            }

            return $renewed;
        } catch (\Throwable $e) {
            CacheLogger::warning('renewLock Redis error', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

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
        } catch (\Throwable $e) {
            //Redis 异常时，返回 false 表示释放锁失�?
            return false;
        }
    }


    /**
     * 持锁期间续期：回调前后各 renew 一次；remember() �?enable_extend 时会拉长持锁 TTL
     * @param callable $callback
     * @param string $key
     * @param string $lockValue
     * @return mixed
     */
    protected function invokeWithLockRenewal(callable $callback, string $key, string $lockValue, int $expire = null)
    {
        $lockConfig = $this->cacheConfig->lock();
        $enable = !empty($lockConfig['enableExtend'] ?? $lockConfig['enable_extend'] ?? true);
        //必须使用 remember() 算好的拉�?TTL；若这里写回�?expire，拉长会立刻被打�?
        $expire = $expire ?? (int) ($lockConfig['expire'] ?? 10);

        if (!$enable) {
            return $callback();
        }

        // 无异步心跳：回调前后各续期一次，续期时长与持�?TTL 一�?
        $this->renewLock($key, $lockValue, $expire);
        try {
            return $callback();
        } finally {
            $this->renewLock($key, $lockValue, $expire);
        }
    }
    /**
     * 缓存装饰�?
     * @param string $gateway
     * @param array $params
     * @param callable $callback
     * @param int $expire
     * @return mixed
     */
    public function remember(string $gateway, array $params, callable $callback, int $expire = null)
    {
        //先取走标签：remember() 有命中、等锁命中、直接回源三条提前返回的路径�?
        //在入口统一消费才能保证标签不会残留到实例上、泄漏给后续无关写入
        $tags = $this->consumeTags();

        //尝试从缓存获取；用条目而不是裸值判断，空结果才能被缓存�?
        $entry = $this->getEntry($gateway, $params);

        if ($entry !== null) {
            //记录缓存命中
            $this->recordStats('hits');
            return $entry['data'];
        }

        //记录缓存未命�?
        $this->recordStats('misses');

        //获取锁，防止缓存击穿
        $key = $this->generateKey($gateway, $params);
        $lockConfig = $this->cacheConfig->lock();
        $lockExpire = (int) ($lockConfig['expire'] ?? 10);
        if (!empty($lockConfig['enableExtend'] ?? $lockConfig['enable_extend'] ?? true)) {
            // 同步 PHP 无法在回调中途可靠心跳：拉长持锁 TTL，再配合回调前后 renewLock
            $extendInterval = max(1, (int) ($lockConfig['extendInterval'] ?? $lockConfig['extend_interval'] ?? 2));
            $lockExpire = max($lockExpire, $lockExpire + $extendInterval * 5);
        }
        $lockValue = $this->acquireLock($key, $lockExpire);

        if ($lockValue) {
            try {
                //再次检查缓存（双重检查）
                $entry = $this->getEntry($gateway, $params);
                if ($entry !== null) {
                    return $entry['data'];
                }

                //执行回调获取数据
                $data = $this->invokeWithLockRenewal($callback, $key, $lockValue, $lockExpire);

                //存入缓存
                $this->tags = $tags;
                $this->set($gateway, $params, $data, $expire);
            } finally {
                //释放�?
                $this->releaseLock($key, $lockValue);
            }

            return $data;
        }

        //抢锁失败：先等持锁者写完，实在等不到再自己回源
        $entry = $this->waitForCachedEntry($gateway, $params, $key, $lockExpire);
        if ($entry !== null) {
            return $entry['data'];
        }

        //回源结果同样要落缓存：不写的话，持续竞争下每个等待超时的调用方都�?
        //重复回源，且谁都不填坑，防击穿在超时之后彻底失效
        $data = $callback();
        $this->tags = $tags;
        $this->set($gateway, $params, $data, $expire);

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
                'hits' => (int) $redis->get($this->buildStatsKey('hits')),
                'misses' => (int) $redis->get($this->buildStatsKey('misses')),
                'today_hits' => (int) $redis->get($this->buildStatsKey('hits', $today)),
                'today_misses' => (int) $redis->get($this->buildStatsKey('misses', $today)),
            ];

            $stats['hit_rate'] = $stats['hits'] + $stats['misses'] > 0
                ? round($stats['hits'] / ($stats['hits'] + $stats['misses']) * 100, 2)
                : 0;

            return $stats;
        } catch (\Throwable $e) {
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

    /*
    |--------------------------------------------------------------------------
    | RediSearch（已废弃�?
    |--------------------------------------------------------------------------
    | RediSearchService 已从本包移除，下列所�?search* 方法只会走降级分支，
    | 恒定返回空值（false / [] / 0）。保留它们仅为兼容既有调用方，不要在新代�?
    | 中使用；检索能力请改用 cmsig/seal + seal-redisearch-adapter，用法见
    | RediSearch/readme.txt�?
    */

    /**
     * 解析 RediSearch 服务
     *
     * 该服务是可选组件，本包并不自带实现。缺失时�?RuntimeException，让�?
     * search 方法沿用既有�?catch 分支返回降级值，而不是抛出调用方接不住的
     * Error（catch(\Exception) 捕获不到 Error）�?
     *
     * @deprecated 改用 cmsig/seal
     * @return mixed
     */
    protected function searchService()
    {
        if (!class_exists('\\HwlowellRequestCache\\RediSearchService')) {
            throw new \RuntimeException('RediSearchService is not installed.');
        }

        return \HwlowellRequestCache\RediSearchService::getInstance();
    }

    /**
     * 索引文档�?RediSearch
     * @deprecated 恒返�?false，改�?cmsig/seal
     * @param string $id
     * @param array $document
     * @return bool
     */
    public function indexSearch($id, array $document)
    {
        try {
            $searchService = $this->searchService();
            return $searchService->index($id, $document);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 搜索缓存内容
     * @deprecated 恒返回空数组，改�?cmsig/seal
     * @param string $query
     * @param array $options
     * @return array
     */
    public function search($query, array $options = [])
    {
        try {
            $searchService = $this->searchService();
            return $searchService->search($query, $options);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 从搜索索引中删除文档
     * @deprecated 恒返�?false，改�?cmsig/seal
     * @param string $id
     * @return bool
     */
    public function deleteSearch($id)
    {
        try {
            $searchService = $this->searchService();
            return $searchService->delete($id);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 清除搜索索引
     * @deprecated 恒返�?false，改�?cmsig/seal
     * @return bool
     */
    public function clearSearch()
    {
        try {
            $searchService = $this->searchService();
            return $searchService->clear();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 高级搜索
     * @deprecated 恒返回空数组，改�?cmsig/seal
     * @param array $conditions
     * @param array $options
     * @return array
     */
    public function advancedSearch(array $conditions, array $options = [])
    {
        try {
            $searchService = $this->searchService();
            return $searchService->advancedSearch($conditions, $options);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 批量索引文档
     * @deprecated 恒返�?false，改�?cmsig/seal
     * @param array $documents
     * @return mixed
     */
    public function bulkIndexSearch(array $documents)
    {
        try {
            $searchService = $this->searchService();
            return $searchService->bulkIndex($documents);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 批量删除文档
     * @deprecated 恒返�?false，改�?cmsig/seal
     * @param array $ids
     * @return mixed
     */
    public function bulkDeleteSearch(array $ids)
    {
        try {
            $searchService = $this->searchService();
            return $searchService->bulkDelete($ids);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 获取搜索文档数量
     * @deprecated 恒返�?0，改�?cmsig/seal
     * @return int
     */
    public function countSearchDocuments()
    {
        try {
            $searchService = $this->searchService();
            return $searchService->countDocuments();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 检查搜索索引是否存�?
     * @deprecated 恒返�?false，改�?cmsig/seal
     * @return bool
     */
    public function existSearchIndex()
    {
        try {
            $searchService = $this->searchService();
            return $searchService->existIndex();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 重建搜索索引
     * @deprecated 恒返�?false，改�?cmsig/seal
     * @return mixed
     */
    public function rebuildSearchIndex()
    {
        try {
            $searchService = $this->searchService();
            return $searchService->rebuildIndex();
        } catch (\Throwable $e) {
            return false;
        }
    }
}
