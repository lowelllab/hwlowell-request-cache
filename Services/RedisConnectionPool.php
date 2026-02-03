<?php

namespace GeorgeRequestCache;

use Illuminate\Support\Facades\Redis as LaravelRedis;

class RedisConnectionPool
{
    /**
     * 连接池实例
     */
    protected static $instance;
    
    /**
     * 连接池配置
     */
    protected $config;
    
    /**
     * 连接池
     */
    protected $pool = [];
    
    /**
     * 正在使用的连接
     */
    protected $used = [];
    
    /**
     * 连接池状态
     */
    protected $status = 'idle';
    
    /**
     * 构造函数
     * @param array $config
     */
    protected function __construct(array $config = [])
    {
        $this->config = array_merge([
            'max_connections' => 10,
            'min_connections' => 2,
            'connection_timeout' => 5,
            'retry_attempts' => 3,
            'retry_delay' => 1000,
            'idle_timeout' => 30,
            'health_check_interval' => 60,
        ], $config);
        
        $this->initPool();
    }
    
    /**
     * 获取连接池实例
     * @param array $config
     * @return self
     */
    public static function getInstance(array $config = [])
    {
        if (!self::$instance) {
            self::$instance = new self($config);
        }
        return self::$instance;
    }
    
    /**
     * 初始化连接池
     */
    protected function initPool()
    {
        $this->status = 'initializing';
        
        //创建最小数量的连接
        for ($i = 0; $i < $this->config['min_connections']; $i++) {
            $connection = $this->createConnection();
            if ($connection) {
                $this->pool[] = [
                    'connection' => $connection,
                    'created_at' => time(),
                    'last_used' => time(),
                ];
            }
        }
        
        $this->status = 'idle';
    }
    
    /**
     * 创建 Redis 连接
     * @return mixed
     */
    protected function createConnection()
    {
        try {
            //使用 Laravel Redis 门面获取连接
            return LaravelRedis::connection();
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * 获取连接
     * @return mixed
     */
    public function getConnection()
    {
        $this->cleanIdleConnections();
        
        //尝试从池中获取连接
        if (!empty($this->pool)) {
            $connectionInfo = array_shift($this->pool);
            $connection = $connectionInfo['connection'];
            
            //检查连接是否可用
            if ($this->isConnectionValid($connection)) {
                $connectionId = spl_object_hash($connection);
                $this->used[$connectionId] = [
                    'connection' => $connection,
                    'acquired_at' => time(),
                ];
                return $connection;
            }
        }
        
        //池中没有可用连接，创建新连接
        if (count($this->used) < $this->config['max_connections']) {
            $connection = $this->createConnection();
            if ($connection) {
                $connectionId = spl_object_hash($connection);
                $this->used[$connectionId] = [
                    'connection' => $connection,
                    'acquired_at' => time(),
                ];
                return $connection;
            }
        }
        
        //连接池已满，等待可用连接
        return $this->waitForConnection();
    }
    
    /**
     * 等待可用连接
     * @return mixed
     */
    protected function waitForConnection()
    {
        $startTime = time();
        $timeout = $this->config['connection_timeout'];
        
        while (time() - $startTime < $timeout) {
            usleep(10000); //10ms
            
            if (!empty($this->pool)) {
                $connectionInfo = array_shift($this->pool);
                $connection = $connectionInfo['connection'];
                
                if ($this->isConnectionValid($connection)) {
                    $connectionId = spl_object_hash($connection);
                    $this->used[$connectionId] = [
                        'connection' => $connection,
                        'acquired_at' => time(),
                    ];
                    return $connection;
                }
            }
        }
        
        return null;
    }
    
    /**
     * 释放连接
     * @param mixed $connection
     */
    public function releaseConnection($connection)
    {
        if (!$connection) {
            return;
        }
        
        $connectionId = spl_object_hash($connection);
        
        if (isset($this->used[$connectionId])) {
            unset($this->used[$connectionId]);
            
            //检查连接是否可用
            if ($this->isConnectionValid($connection)) {
                //将连接返回池中
                $this->pool[] = [
                    'connection' => $connection,
                    'created_at' => time(),
                    'last_used' => time(),
                ];
            }
        }
    }
    
    /**
     * 检查连接是否有效
     * @param mixed $connection
     * @return bool
     */
    protected function isConnectionValid($connection)
    {
        try {
            $connection->ping();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * 清理空闲连接
     */
    protected function cleanIdleConnections()
    {
        $now = time();
        $idleTimeout = $this->config['idle_timeout'];
        
        //清理池中过期的连接
        $this->pool = array_filter($this->pool, function ($connectionInfo) use ($now, $idleTimeout) {
            if ($now - $connectionInfo['last_used'] > $idleTimeout) {
                return false;
            }
            return $this->isConnectionValid($connectionInfo['connection']);
        });
        
        //确保池中连接数不低于最小值
        while (count($this->pool) < $this->config['min_connections']) {
            $connection = $this->createConnection();
            if ($connection) {
                $this->pool[] = [
                    'connection' => $connection,
                    'created_at' => time(),
                    'last_used' => time(),
                ];
            } else {
                break;
            }
        }
    }
    
    /**
     * 获取连接池状态
     * @return array
     */
    public function getStatus()
    {
        return [
            'status' => $this->status,
            'pool_size' => count($this->pool),
            'used_connections' => count($this->used),
            'total_connections' => count($this->pool) + count($this->used),
            'config' => $this->config,
        ];
    }
    
    /**
     * 关闭所有连接
     */
    public function closeAll()
    {
        $this->pool = [];
        $this->used = [];
    }
    
    /**
     * 执行 Redis 命令
     * @param string $command
     * @param array $parameters
     * @return mixed
     */
    public function command($command, $parameters = [])
    {
        $connection = $this->getConnection();
        
        if (!$connection) {
            throw new \Exception('No available Redis connection');
        }
        
        try {
            return $connection->command($command, $parameters);
        } finally {
            $this->releaseConnection($connection);
        }
    }
    
    /**
     * 魔术方法：调用 Redis 连接的方法
     * @param string $method
     * @param array $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        $connection = $this->getConnection();
        
        if (!$connection) {
            throw new \Exception('No available Redis connection');
        }
        
        try {
            return call_user_func_array([$connection, $method], $parameters);
        } finally {
            $this->releaseConnection($connection);
        }
    }
}
