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
     * 获取缓存
     * @param string $key
     * @return mixed
     */
    public function get(string $key)
    {
        $this->cleanExpired();
        
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
        $this->cleanExpired();

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
        $this->cleanExpired();
        
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
        
        asort($this->expires);
        $oldestKey = key($this->expires);
        
        unset($this->cache[$oldestKey]);
        unset($this->expires[$oldestKey]);
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
