<?php

namespace HwlowellRequestCache;

use Illuminate\Support\Facades\Redis;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use HwlowellRequestCache\FilterConfig;
use HwlowellRequestCache\CacheConfig;
use HwlowellRequestCache\LocalCache;
use HwlowellRequestCache\RedisConnectionPool;

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
     * Redis 连接池实例
     */
    protected $redisPool;
    
    /**
     * 加载配置文件
     * @return array|null
     */
    protected function loadConfigFile()
    {
        $configPath = __DIR__ . '/../config/request_cache.php';
        if (file_exists($configPath)) {
            return require $configPath;
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
     * 构造函数
     * @param array $config 可选配置数组
     */
    public function __construct(array $config = null)
    {
        //兼容非 Laravel 环境
        $this->appName = function_exists('env') ? env('APP_NAME', 'laravel') : 'laravel';
        $this->appEnv = function_exists('env') ? env('APP_ENV', 'local') : 'local';
        $this->prefix = strtolower(str_replace(' ', '_', $this->appName)) . '_' . $this->appEnv . '_cache:';
        $this->localCache = new LocalCache();
        
        //加载配置
        if ($config === null) {
            if (function_exists('config')) {
                try {
                    $config = config('request-cache');
                } catch (\Exception $e) {
                    //Laravel config function exists but fails (not initialized), fall back to file
                    $config = $this->loadConfigFile();
                }
            } else {
                $config = $this->loadConfigFile();
            }
        }
        
        //加载配置
        if ($config !== null) {
            if (isset($config['request_cache'])) {
                $requestCacheConfig = $config['request_cache'];
                
                if (isset($requestCacheConfig['prefix'])) {
                    $this->prefix = $requestCacheConfig['prefix'];
                }
                
                if (isset($requestCacheConfig['default_expire'])) {
                    $this->defaultExpire = $requestCacheConfig['default_expire'];
                }
                
                if (isset($requestCacheConfig['force_validate'])) {
                    $this->forceValidate = $requestCacheConfig['force_validate'];
                }
                
                if (isset($requestCacheConfig['enable_stats'])) {
                    $this->enableStats = $requestCacheConfig['enable_stats'];
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
        }

        //初始化 Redis 连接池（仅在 Laravel 环境中启用）
        $poolConfig = CacheConfig::getRedisPoolConfig();
        if (function_exists('config') && $poolConfig['enabled']) {
            $this->redisPool = RedisConnectionPool::getInstance($poolConfig);
        }
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
                // 构建单次正则表达式，减少多次 preg_replace 调用
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
        //兼容非 Laravel 环境
        if (function_exists('config')) {
            try {
                $hashKey =  config('app.key', 'default_cache_key') ?: 'default_cache_key';
            } catch (\Exception $e) {
                //Laravel config function exists but fails (not initialized), use default
                $hashKey = 'default_cache_key';
            }
        } else {
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
        $localValue = $this->localCache->get($key);
        if ($localValue !== null) {
            return $localValue;
        }
        
        //尝试从主缓存（Redis）获取
        if ($strategy['primary'] === 'redis') {
            try {
                //使用 Redis 连接池或直接使用 Redis 门面
                $redis = $this->redisPool ?: Redis::connection();
                $value = $redis->get($key);

                if ($value) {
                    //解密数据
                    $originalValue = $value;
                    if ($this->encryptData && function_exists('decrypt')) {
                        try {
                            $value = decrypt($value);
                        } catch (\Exception $e) {
                            //解密失败，使用原始值
                            $value = $originalValue;
                        }
                    }
                    $data = json_decode($value, true);
                    
                    //将数据同步到本地缓存
                    $this->localCache->set($key, $data);
                    
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
        
        // 先尝试从本地缓存获取
        foreach ($items as $index => [$gateway, $params]) {
            $key = $this->generateKey($gateway, $params);
            $localValue = $this->localCache->get($key);
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
                // 使用 Redis 连接池或直接使用 Redis 门面
                $redis = $this->redisPool ?: Redis::connection();
                $values = $redis->mget($keysToGet);

                foreach ($keysToGet as $i => $key) {
                    $value = $values[$i];
                    if ($value) {
                        // 解密数据
                        if ($this->encryptData && function_exists('decrypt')) {
                            $value = decrypt($value);
                        }
                        $data = json_decode($value, true);
                        
                        // 将数据同步到本地缓存
                        $this->localCache->set($key, $data);
                        
                        // 添加到结果
                        if (isset($keyMap[$key])) {
                            $result[$keyMap[$key]] = $data;
                        }
                    }
                }
            } catch (\Exception $e) {
                //Redis 异常，忽略
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
            $jsonData = json_encode($data);

            //检查数据大小
            $dataSize = strlen($jsonData);
            if ($dataSize > $this->sizeLimit) {
                //数据过大，不缓存
                return false;
            }

            //加密数据
            if ($this->encryptData && function_exists('encrypt')) {
                $jsonData = encrypt($jsonData);
                //再次检查加密后的数据大小
                if (strlen($jsonData) > $this->sizeLimit) {
                    //加密后数据过大，不缓存
                    return false;
                }
            }

            // 使用 Redis 连接池或直接使用 Redis 门面
            $redis = $this->redisPool ?: Redis::connection();
            $result = $redis->setex($key, $expire, $jsonData);

            //保存标签关联
            foreach ($this->tags as $tag) {
                $tagKey = "{$this->prefix}tags:{$tag}";
                $redis->sadd($tagKey, $key);
                //为标签设置过期时间，防止内存泄漏
                $redis->expire($tagKey, $expire + 3600);
            }

            //将数据同步到本地缓存
            $this->localCache->set($key, $data, $expire);

            return $result;
        } catch (\Exception $e) {
            //Redis 异常时，尝试使用本地缓存作为回退
            $key = $this->generateKey($gateway, $params);
            $expire = $expire ?? $this->defaultExpire * 60;
            $this->localCache->set($key, $data, $expire);
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
        $strategy = CacheConfig::getStrategy();
        
        try {
            // 如果使用 Redis 作为主缓存，使用管道批量操作
            if ($strategy['primary'] === 'redis') {
                // 使用 Redis 连接池或直接使用 Redis 门面
                $redis = $this->redisPool ?: Redis::connection();
                $pipeline = $redis->pipeline();
            }

            foreach ($items as $index => $item) {
                $gateway = $item[0];
                $params = $item[1];
                $data = $item[2];
                $expire = isset($item[3]) ? $item[3] : null;
                $expire = $expire ?? $this->defaultExpire * 60;
                $key = $this->generateKey($gateway, $params);
                $jsonData = json_encode($data);

                //检查数据大小
                $dataSize = strlen($jsonData);
                if ($dataSize > $this->sizeLimit) {
                    //数据过大，不缓存
                    $results[$index] = false;
                    continue;
                }

                //加密数据
                if ($this->encryptData && function_exists('encrypt')) {
                    $jsonData = encrypt($jsonData);
                    //再次检查加密后的数据大小
                    if (strlen($jsonData) > $this->sizeLimit) {
                        //加密后数据过大，不缓存
                        $results[$index] = false;
                        continue;
                    }
                }

                // 使用管道批量操作
                if ($pipeline) {
                    $pipeline->setex($key, $expire, $jsonData);
                    
                    //保存标签关联
                    foreach ($this->tags as $tag) {
                        $tagKey = "{$this->prefix}tags:{$tag}";
                        $pipeline->sadd($tagKey, $key);
                        //为标签设置过期时间，防止内存泄漏
                        $pipeline->expire($tagKey, $expire + 3600);
                    }
                }

                //将数据同步到本地缓存
                $this->localCache->set($key, $data, $expire);
                $results[$index] = true;
            }

            // 执行管道操作
            if ($pipeline) {
                $pipeline->execute();
            }
        } catch (\Exception $e) {
            //Redis 异常时，尝试使用本地缓存作为回退
            foreach ($items as $index => $item) {
                $gateway = $item[0];
                $params = $item[1];
                $data = $item[2];
                $expire = isset($item[3]) ? $item[3] : null;
                $expire = $expire ?? $this->defaultExpire * 60;
                $key = $this->generateKey($gateway, $params);
                $this->localCache->set($key, $data, $expire);
                $results[$index] = true;
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
        try {
            $key = $this->generateKey($gateway, $params);
            // 使用 Redis 连接池或直接使用 Redis 门面
            $redis = $this->redisPool ?: Redis::connection();
            return $redis->del($key) > 0;
        } catch (\Exception $e) {
            return false;
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
        $keys = [];
        $cursor = '0';

        do {
            // 使用 Redis 连接池或直接使用 Redis 门面
            $redis = $this->redisPool ?: Redis::connection();
            $result = $redis->command('SCAN', [$cursor, 'MATCH', $pattern, 'COUNT', $count]);
            $cursor = $result[0];
            $batchKeys = $result[1];
            
            // 限制内存使用
            if (count($keys) + count($batchKeys) > $batchSize) {
                break;
            }
            
            $keys = array_merge($keys, $batchKeys);
        } while ($cursor != '0');

        return $keys;
    }

    /**
     * 分批删除键
     * @param array $keys
     * @param int $batchSize
     * @return int
     */
    protected function batchDelete(array $keys, int $batchSize = 1000)
    {
        $deleted = 0;
        $redis = $this->redisPool ?: Redis::connection();
        
        // 分批删除
        foreach (array_chunk($keys, $batchSize) as $batch) {
            try {
                $deleted += $redis->del($batch);
                // 每批删除后短暂休眠，减少 Redis 压力
                usleep(10000); // 10ms
            } catch (\Exception $e) {
                // 忽略删除异常
            }
        }
        
        return $deleted;
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

            $keys = $this->scanKeys($pattern);

            if (empty($keys)) {
                return true;
            }

            // 分批删除，减少 Redis 压力
            $deleted = $this->batchDelete($keys);
            return $deleted > 0;
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

            // 使用 Redis 连接池或直接使用 Redis 门面
            $redis = $this->redisPool ?: Redis::connection();

            foreach ($tags as $tag) {
                $tagKey = "{$this->prefix}tags:{$tag}";
                $tagKeys = $redis->smembers($tagKey);
                $keys = array_merge($keys, $tagKeys);
                //删除标签集合
                $redis->del($tagKey);
            }

            if (empty($keys)) {
                return true;
            }

            return $redis->del($keys) > 0;
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

            $keys = $this->scanKeys($pattern);

            if (empty($keys)) {
                return true;
            }

            // 分批删除，减少 Redis 压力
            $deleted = $this->batchDelete($keys);
            return $deleted > 0;
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
        if (!$this->enableStats) {
            return;
        }

        try {
            $today = date('Y-m-d');
            $globalKey = "{$this->prefix}stats:{$type}";
            $dailyKey = "{$this->prefix}stats:{$type}:{$today}";

            // 使用 Redis 连接池或直接使用 Redis 门面
            $redis = $this->redisPool ?: Redis::connection();

            //增加统计计数
            $redis->incr($globalKey);
            $redis->incr($dailyKey);

            //设置过期时间：全局统计 30 天，每日统计 90 天
            $redis->expire($globalKey, 30 * 24 * 3600);
            $redis->expire($dailyKey, 90 * 24 * 3600);
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
        $lockKey = "{$this->prefix}lock:{$key}";
        $lockValue = Str::random(32); //随机值，防止误释放

        try {
            // 使用 Redis 连接池或直接使用 Redis 门面
            $redis = $this->redisPool ?: Redis::connection();
            
            for ($i = 0; $i < $retryTimes; $i++) {
                if ($redis->set($lockKey, $lockValue, 'EX', $expire, 'NX')) {
                    return $lockValue;
                }
                // 自适应重试间隔：指数退避
                $currentDelay = $retryDelay * (1 << $i);
                // 最大重试间隔限制为 1 秒
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
        $lockKey = "{$this->prefix}lock:{$key}";

        try {
            //使用 Lua 脚本确保原子操作
            $script = <<<'LUA'
                if redis.call('get', KEYS[1]) == ARGV[1] then
                    return redis.call('expire', KEYS[1], ARGV[2])
                else
                    return 0
                end
            LUA;

            //使用 Redis 连接池或直接使用 Redis 门面
            $redis = $this->redisPool ?: Redis::connection();
            return $redis->command('eval', [$script, 1, $lockKey, $lockValue, $expire]) > 0;
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
        $lockKey = "{$this->prefix}lock:{$key}";

        try {
            //使用 Lua 脚本确保原子操作
            $script = <<<'LUA'
                if redis.call('get', KEYS[1]) == ARGV[1] then
                    return redis.call('del', KEYS[1])
                else
                    return 0
                end
            LUA;

            //使用 Redis 连接池或直接使用 Redis 门面
            $redis = $this->redisPool ?: Redis::connection();
            return $redis->command('eval', [$script, 1, $lockKey, $lockValue]) > 0;
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
            //使用 Redis 连接池或直接使用 Redis 门面
            $redis = $this->redisPool ?: Redis::connection();
            
            $stats = [
                'hits' => (int) $redis->get("{$this->prefix}stats:hits") ?? 0,
                'misses' => (int) $redis->get("{$this->prefix}stats:misses") ?? 0,
                'today_hits' => (int) $redis->get("{$this->prefix}stats:hits:{$today}") ?? 0,
                'today_misses' => (int) $redis->get("{$this->prefix}stats:misses:{$today}") ?? 0,
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
}
