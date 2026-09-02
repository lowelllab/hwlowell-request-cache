<?php

namespace HwlowellRequestCache;

use HwlowellRequestCache\CacheConfig;

class LocalCache
{
    /**
     * 缓存数据
     */
    protected $cache = [];
    
    /**
     * 缓存过期时间
     */
    protected $expires = [];
    
    /**
     * 配置
     */
    protected $config;
    
    /**
     * 构造函数
     * @param array|null $config 实例级本地缓存配置，为空时使用全局配置
     */
    public function __construct(array $config = null)
    {
        $this->config = array_merge(CacheConfig::getLocalCacheConfig(), $config ?? []);
    }
    
    /**
     * 检查单个 key 是否已过期
     * @param string $key
     * @return bool
     */
    protected function isExpired(string $key): bool
    {
        if (!isset($this->expires[$key])) {
            return false;
        }

        if (time() > $this->expires[$key]) {
            unset($this->cache[$key], $this->expires[$key]);

            return true;
        }

        return false;
    }

    /**
     * 获取缓存
     * @param string $key
     * @return mixed
     */
    public function get(string $key)
    {
        if ($this->isExpired($key)) {
            return null;
        }
        
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }
        
        return null;
    }

    /**
     * 判断 key 是否存在
     *
     * 与 get() 区分开：缓存值本身可能就是 null，靠 get() 的返回值判断存在性会把
     * 「缓存了 null」误判成未命中。
     *
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool
    {
        if ($this->isExpired($key)) {
            return false;
        }

        return array_key_exists($key, $this->cache);
    }
    
    /**
     * 设置缓存
     * @param string $key
     * @param mixed $value
     * @param int $expire
     * @return bool
     */
    public function set(string $key, $value, int $expire = null)
    {
        //检查缓存大小
        if (count($this->cache) >= $this->config['size']) {
            $this->evictOldest();
        }
        
        $expire = $expire ?? $this->config['ttl'];
        $this->cache[$key] = $value;
        $this->expires[$key] = time() + $expire;
        
        return true;
    }
    
    /**
     * 删除缓存
     * @param string $key
     * @return bool
     */
    public function delete(string $key)
    {
        if (isset($this->cache[$key])) {
            unset($this->cache[$key]);
            unset($this->expires[$key]);
            return true;
        }
        
        return false;
    }
    
    /**
     * 清除所有缓存
     * @return bool
     */
    public function flush()
    {
        $this->cache = [];
        $this->expires = [];
        return true;
    }

    /**
     * 只清除某个前缀下的缓存
     *
     * 所有实例共用同一个进程内缓存，key 按连接名分了命名空间。集群 A 的清理
     * 不应顺手把集群 B 仍然有效的本地副本一起丢掉，因此按前缀清。
     *
     * @param string $prefix
     * @return bool
     */
    public function flushPrefix(string $prefix)
    {
        if ($prefix === '') {
            return $this->flush();
        }

        foreach (array_keys($this->cache) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->cache[$key], $this->expires[$key]);
            }
        }

        //过期表里可能残留没有对应值的条目
        foreach (array_keys($this->expires) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->expires[$key]);
            }
        }

        return true;
    }
    
    /**
     * 清除过期缓存
     */
    protected function cleanExpired()
    {
        $now = time();
        foreach ($this->expires as $key => $expire) {
            if ($now > $expire) {
                unset($this->cache[$key]);
                unset($this->expires[$key]);
            }
        }
    }
    
    /**
     * 移除最旧的缓存
     */
    protected function evictOldest()
    {
        if (empty($this->expires)) {
            return;
        }

        $now = time();
        $oldestKey = null;
        $oldestExpire = PHP_INT_MAX;

        foreach ($this->expires as $key => $expire) {
            if ($now > $expire) {
                unset($this->cache[$key], $this->expires[$key]);
                continue;
            }

            if ($expire < $oldestExpire) {
                $oldestExpire = $expire;
                $oldestKey = $key;
            }
        }

        if ($oldestKey !== null) {
            unset($this->cache[$oldestKey], $this->expires[$oldestKey]);
        }
    }
    
    /**
     * 获取缓存大小
     * @return int
     */
    public function size()
    {
        $this->cleanExpired();
        return count($this->cache);
    }
}
