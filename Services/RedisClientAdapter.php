<?php

namespace HwlowellRequestCache;

/**
 * 抹平 phpredis Redis 与 RedisCluster 的 API 差异。
 * 只暴露本包用到的一小撮命令，不做完整代理。
 */
class RedisClientAdapter
{
    /**
     * @param mixed $connection Laravel Redis connection 或底层 client
     */
    public function __construct(protected $connection)
    {
    }

    /**
     * @param mixed $connection
     * @return static
     */
    public static function wrap($connection): self
    {
        return new self($connection);
    }

    /**
     * @return mixed
     */
    public function connection()
    {
        return $this->connection;
    }

    /**
     * @return mixed 底层 phpredis 客户端
     */
    public function client()
    {
        if (is_object($this->connection) && method_exists($this->connection, 'client')) {
            try {
                return $this->connection->client();
            } catch (\Throwable $e) {
                return null;
            }
        }

        return $this->connection;
    }

    public function isCluster(): bool
    {
        $client = $this->client();

        return is_object($client) && (
            $client instanceof \RedisCluster
            || (class_exists(\RedisCluster::class) && is_a($client, \RedisCluster::class))
            || str_contains(get_class($client), 'RedisCluster')
        );
    }

    /**
     * 集群上 RedisCluster::pipeline() 不存在；调用方拿到 null 后应走逐 key 路径。
     *
     * @return mixed|null
     */
    public function pipelineOrNull()
    {
        if ($this->isCluster()) {
            return null;
        }

        try {
            if (is_object($this->connection)) {
                return $this->connection->pipeline();
            }

            $client = $this->client();
            if (is_object($client)) {
                return $client->pipeline();
            }
        } catch (\Throwable $e) {
            CacheLogger::warning('pipeline unavailable, falling back to per-key writes', [
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * @param string $section
     * @param string|null $routeKey 集群路由 key
     * @return array
     */
    public function info(string $section = 'memory', ?string $routeKey = null): array
    {
        try {
            if ($this->isCluster()) {
                $client = $this->client();
                $routeKey = $routeKey ?? $this->defaultRouteKey();
                $info = @$client->info($routeKey, $section);
                if (!is_array($info) || $info === []) {
                    $info = @$client->info($routeKey);
                }

                return is_array($info) ? $info : [];
            }

            $info = $this->connection->info($section);

            return is_array($info) ? $info : [];
        } catch (\Throwable $e) {
            CacheLogger::warning('info() failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @param string|null $routeKey
     * @return int
     */
    public function dbsize(?string $routeKey = null): int
    {
        try {
            if ($this->isCluster()) {
                $client = $this->client();
                $routeKey = $routeKey ?? $this->defaultRouteKey();

                return (int) $client->dbsize($routeKey);
            }

            return (int) $this->connection->dbsize();
        } catch (\Throwable $e) {
            CacheLogger::warning('dbsize() failed', ['error' => $e->getMessage()]);

            return 0;
        }
    }

    /**
     * @param string|null $routeKey
     * @return bool
     */
    public function ping(?string $routeKey = null): bool
    {
        try {
            if ($this->isCluster()) {
                $client = $this->client();
                $routeKey = $routeKey ?? $this->defaultRouteKey();
                $result = $client->ping($routeKey);

                return $result === true || $result === '+PONG' || $result === 'PONG';
            }

            $result = $this->connection->ping();

            return $result === true || $result === '+PONG' || $result === 'PONG';
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * SCAN 初始游标：phpredis 要求 null，而不是 '0'
     * @return null
     */
    public static function initialScanCursor()
    {
        return null;
    }

    /**
     * @param mixed $cursor
     * @return bool
     */
    public static function isScanCursorFinished($cursor): bool
    {
        return $cursor === 0 || $cursor === '0' || $cursor === null;
    }

    protected function defaultRouteKey(): string
    {
        return '{request-cache}:ping';
    }
}
