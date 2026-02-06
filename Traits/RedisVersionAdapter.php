<?php

namespace HwlowellRequestCache\Traits;

/**
 * Redis版本适配器Trait
 * 动态适配phpredis 3、4、5版本的API差异
 */
trait RedisVersionAdapter
{
    /**
     * Redis实例
     * @var mixed
     */
    protected $redis;
    
    /**
     * phpredis版本
     * @var string
     */
    protected $redisVersion;
    
    /**
     * 初始化Redis版本信息
     */
    protected function initRedisVersion()
    {
        if (extension_loaded('redis')) {
            $this->redisVersion = phpversion('redis');
        } else {
            $this->redisVersion = '0.0.0';
        }
    }
    
    /**
     * 获取Redis版本
     * @return string
     */
    public function getRedisVersion()
    {
        if (!isset($this->redisVersion)) {
            $this->initRedisVersion();
        }
        return $this->redisVersion;
    }
    
    /**
     * 比较Redis版本
     * @param string $version
     * @return int
     */
    protected function compareRedisVersion($version)
    {
        if (!isset($this->redisVersion)) {
            $this->initRedisVersion();
        }
        return version_compare($this->redisVersion, $version);
    }
    
    /**
     * 检查是否支持指定版本
     * @param string $version
     * @return bool
     */
    protected function isRedisVersionSupported($version)
    {
        return $this->compareRedisVersion($version) >= 0;
    }
    
    /**
     * 执行Redis命令（兼容不同版本）
     * @param string $command
     * @param array $parameters
     * @return mixed
     */
    protected function executeRedisCommand($command, $parameters = [])
    {
        //统一命令格式为小写
        $command = strtolower($command);
        
        //处理不同版本的API差异
        switch ($command) {
            case 'set':
                return $this->executeSetCommand($parameters);
            case 'get':
                return $this->executeGetCommand($parameters);
            case 'del':
                return $this->executeDelCommand($parameters);
            case 'exists':
                return $this->executeExistsCommand($parameters);
            case 'expire':
                return $this->executeExpireCommand($parameters);
            case 'ttl':
                return $this->executeTtlCommand($parameters);
            case 'hset':
                return $this->executeHSetCommand($parameters);
            case 'hget':
                return $this->executeHGetCommand($parameters);
            case 'hdel':
                return $this->executeHDelCommand($parameters);
            case 'hgetall':
                return $this->executeHGetAllCommand($parameters);
            case 'hkeys':
                return $this->executeHKeysCommand($parameters);
            case 'hvals':
                return $this->executeHValsCommand($parameters);
            case 'hlen':
                return $this->executeHLenCommand($parameters);
            case 'h_exists':
            case 'hexists':
                return $this->executeHExistsCommand($parameters);
            case 'incr':
                return $this->executeIncrCommand($parameters);
            case 'decr':
                return $this->executeDecrCommand($parameters);
            case 'incrby':
                return $this->executeIncrByCommand($parameters);
            case 'decrby':
                return $this->executeDecrByCommand($parameters);
            case 'lpush':
                return $this->executeLPushCommand($parameters);
            case 'rpush':
                return $this->executeRPushCommand($parameters);
            case 'lpop':
                return $this->executeLPopCommand($parameters);
            case 'rpop':
                return $this->executeRPopCommand($parameters);
            case 'llen':
                return $this->executeLLenCommand($parameters);
            case 'lrange':
                return $this->executeLRangeCommand($parameters);
            case 'sadd':
                return $this->executeSAddCommand($parameters);
            case 'srem':
                return $this->executeSRemCommand($parameters);
            case 'smembers':
                return $this->executeSMembersCommand($parameters);
            case 'sismember':
                return $this->executeSIsMemberCommand($parameters);
            case 'scard':
                return $this->executeSCardCommand($parameters);
            case 'zadd':
                return $this->executeZAddCommand($parameters);
            case 'zrem':
                return $this->executeZRemCommand($parameters);
            case 'zrange':
                return $this->executeZRangeCommand($parameters);
            case 'zrevrange':
                return $this->executeZRevRangeCommand($parameters);
            case 'zscore':
                return $this->executeZScoreCommand($parameters);
            case 'zcard':
                return $this->executeZCardCommand($parameters);
            case 'eval':
                return $this->executeEvalCommand($parameters);
            default:
                //直接调用原始命令
                return call_user_func_array([$this->redis, $command], $parameters);
        }
    }
    
    /**
     * 执行SET命令
     * @param array $parameters
     * @return mixed
     */
    protected function executeSetCommand($parameters)
    {
        return $this->redis->set(...$parameters);
    }
    
    /**
     * 执行GET命令
     * @param array $parameters
     * @return mixed
     */
    protected function executeGetCommand($parameters)
    {
        return $this->redis->get(...$parameters);
    }
    
    /**
     * 执行DEL命令
     * @param array $parameters
     * @return mixed
     */
    protected function executeDelCommand($parameters)
    {
        return $this->redis->del(...$parameters);
    }
    
    /**
     * 执行EXISTS命令
     * @param array $parameters
     * @return mixed
     */
    protected function executeExistsCommand($parameters)
    {
        return $this->redis->exists(...$parameters);
    }
    
    /**
     * 执行EXPIRE命令
     * @param array $parameters
     * @return mixed
     */
    protected function executeExpireCommand($parameters)
    {
        return $this->redis->expire(...$parameters);
    }
    
    /**
     * 执行TTL命令
     * @param array $parameters
     * @return mixed
     */
    protected function executeTtlCommand($parameters)
    {
        return $this->redis->ttl(...$parameters);
    }
    
    /**
     * 执行HSET命令
     * @param array $parameters
     * @return mixed
     */
    protected function executeHSetCommand($parameters)
    {
        return $this->redis->hSet(...$parameters);
    }
    
    /**
     * 执行HGET命令
     * @param array $parameters
     * @return mixed
     */
    protected function executeHGetCommand($parameters)
    {
        return $this->redis->hGet(...$parameters);
    }
    
    /**
     * 执行HDEL命令
     * @param array $parameters
     * @return mixed
     */
    protected function executeHDelCommand($parameters)
    {
        return $this->redis->hDel(...$parameters);
    }
    
    /**
     * 执行HGETALL命令
     * @param array $parameters
     * @return mixed
     */
    protected function executeHGetAllCommand($parameters)
    {
        return $this->redis->hGetAll(...$parameters);
    }
    
    /**
     * 执行HKEYS命令
     * @param array $parameters
     * @return mixed
     */
    protected function executeHKeysCommand($parameters)
    {
        return $this->redis->hKeys(...$parameters);
    }
    
    /**
     * 执行HVALS命令
     * @param array $parameters
     * @return mixed
     */
    protected function executeHValsCommand($parameters)
    {
        return $this->redis->hVals(...$parameters);
    }
    
    /**
     * 执行HLEN命令
     * @param array $parameters
     * @return mixed
     */
    protected function executeHLenCommand($parameters)
    {
        return $this->redis->hLen(...$parameters);
    }
    
    /**
     * 执行HEXISTS命令
     * @param array $parameters
     * @return mixed
     */
    protected function executeHExistsCommand($parameters)
    {
        return $this->redis->hExists(...$parameters);
    }
    
    /**
     * 执行INCR命令
     * @param array $parameters
     * @return mixed
     */
    protected function executeIncrCommand($parameters)
    {
        return $this->redis->incr(...$parameters);
    }
    
    /**
     * 执行DECR命令
     * @param array $parameters
     * @return mixed
     */
    protected function executeDecrCommand($parameters)
    {
        return $this->redis->decr(...$parameters);
    }
    
    /**
     * 执行INCRBY命令
     * @param array $parameters
     * @return mixed
     */
    protected function executeIncrByCommand($parameters)
    {
        return $this->redis->incrBy(...$parameters);
    }
    
    /**
     * 执行DECRBY命令
     * @param array $parameters
     * @return mixed
     */
    protected function executeDecrByCommand($parameters)
    {
        return $this->redis->decrBy(...$parameters);
    }
    
    /**
     * 执行LPUSH命令
     * @param array $parameters
     * @return mixed
     */
    protected function executeLPushCommand($parameters)
    {
        return $this->redis->lPush(...$parameters);
    }
    
    /**
     * 执行RPUSH命令
     * @param array $parameters
     * @return mixed
     */
    protected function executeRPushCommand($parameters)
    {
        return $this->redis->rPush(...$parameters);
    }
    
    /**
     * 执行LPOP命令
     * @param array $parameters
     * @return mixed
     */
    protected function executeLPopCommand($parameters)
    {
        return $this->redis->lPop(...$parameters);
    }
    
    /**
     * 执行RPOP命令
     * @param array $parameters
     * @return mixed
     */
    protected function executeRPopCommand($parameters)
    {
        return $this->redis->rPop(...$parameters);
    }
    
    /**
     * 执行LLEN命令
     * @param array $parameters
     * @return mixed
     */
    protected function executeLLenCommand($parameters)
    {
        return $this->redis->lLen(...$parameters);
    }
    
    /**
     * 执行LRANGE命令
     * @param array $parameters
     * @return mixed
     */
    protected function executeLRangeCommand($parameters)
    {
        return $this->redis->lRange(...$parameters);
    }
    
    /**
     * 执行SADD命令
     * @param array $parameters
     * @return mixed
     */
    protected function executeSAddCommand($parameters)
    {
        return $this->redis->sAdd(...$parameters);
    }
    
    /**
     * 执行SREM命令
     * @param array $parameters
     * @return mixed
     */
    protected function executeSRemCommand($parameters)
    {
        return $this->redis->sRem(...$parameters);
    }
    
    /**
     * 执行SMEMBERS命令
     * @param array $parameters
     * @return mixed
     */
    protected function executeSMembersCommand($parameters)
    {
        return $this->redis->sMembers(...$parameters);
    }
    
    /**
     * 执行SISMEMBER命令
     * @param array $parameters
     * @return mixed
     */
    protected function executeSIsMemberCommand($parameters)
    {
        return $this->redis->sIsMember(...$parameters);
    }
    
    /**
     * 执行SCARD命令
     * @param array $parameters
     * @return mixed
     */
    protected function executeSCardCommand($parameters)
    {
        return $this->redis->sCard(...$parameters);
    }
    
    /**
     * 执行ZADD命令
     * @param array $parameters
     * @return mixed
     */
    protected function executeZAddCommand($parameters)
    {
        //处理phpredis 3.x和4.x+的ZADD命令差异
        if ($this->compareRedisVersion('4.0.0') < 0) {
            //3.x版本：zAdd($key, $score1, $member1, $score2, $member2, ...)
            return $this->redis->zAdd(...$parameters);
        } else {
            //4.x+版本：zAdd($key, ['nx' => true, 'xx' => false], $score, $member) 或 zAdd($key, $score, $member)
            if (isset($parameters[1]) && is_array($parameters[1])) {
                //带选项的调用
                return $this->redis->zAdd(...$parameters);
            } else {
                //兼容旧格式
                $key = array_shift($parameters);
                $options = [];
                $scoresAndMembers = $parameters;
                return $this->redis->zAdd($key, $options, ...$scoresAndMembers);
            }
        }
    }
    
    /**
     * 执行ZREM命令
     * @param array $parameters
     * @return mixed
     */
    protected function executeZRemCommand($parameters)
    {
        return $this->redis->zRem(...$parameters);
    }
    
    /**
     * 执行ZRANGE命令
     * @param array $parameters
     * @return mixed
     */
    protected function executeZRangeCommand($parameters)
    {
        return $this->redis->zRange(...$parameters);
    }
    
    /**
     * 执行ZREVRANGE命令
     * @param array $parameters
     * @return mixed
     */
    protected function executeZRevRangeCommand($parameters)
    {
        return $this->redis->zRevRange(...$parameters);
    }
    
    /**
     * 执行ZSCORE命令
     * @param array $parameters
     * @return mixed
     */
    protected function executeZScoreCommand($parameters)
    {
        return $this->redis->zScore(...$parameters);
    }
    
    /**
     * 执行ZCARD命令
     * @param array $parameters
     * @return mixed
     */
    protected function executeZCardCommand($parameters)
    {
        return $this->redis->zCard(...$parameters);
    }
    
    /**
     * 执行EVAL命令
     * @param array $parameters
     * @return mixed
     */
    protected function executeEvalCommand($parameters)
    {
        //处理phpredis 3.x、4.x、5.x的EVAL命令差异
        //格式1: [script, keysAndArgs, numKeys] (phpredis 5.0+)
        //格式2: [script, numKeys, key1, key2, ..., arg1, arg2, ...] (所有版本)
        if (isset($parameters[1]) && is_array($parameters[1])) {
            //格式1: [script, keysAndArgs, numKeys]
            $script = $parameters[0];
            $keysAndArgs = $parameters[1];
            $numKeys = $parameters[2];
            
            //转换为格式2
            $commandArgs = array_merge([$script, $numKeys], $keysAndArgs);
            return $this->redis->eval(...$commandArgs);
        } else {
            //格式2: 直接调用
            return $this->redis->eval(...$parameters);
        }
    }
    
    /**
     * 魔术方法：调用Redis方法
     * @param string $method
     * @param array $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        //检查是否是Redis命令
        $command = strtolower($method);
        
        //处理常见的命令别名
        $commandMap = [
            'h_exists' => 'hexists',
            'hexists' => 'h_exists',
            'sismember' => 's_is_member',
            's_is_member' => 'sismember',
        ];
        
        if (isset($commandMap[$command])) {
            $method = $commandMap[$command];
        }
        
        //直接调用Redis实例的方法
        return call_user_func_array([$this->redis, $method], $parameters);
    }
}
