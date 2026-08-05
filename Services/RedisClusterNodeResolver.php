<?php

namespace HwlowellRequestCache;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Redis;

class RedisClusterNodeResolver
{
    protected array $clusterConfig;
    protected ?string $connectionName = null;
    protected bool $usingCurrentConnectionFallback = false;

    public function __construct(array $clusterConfig = null, ?string $connectionName = null)
    {
        $this->clusterConfig = $clusterConfig ?? CacheConfig::getRedisClusterConfig();

        $connectionName = $connectionName === null ? null : trim($connectionName);
        $this->connectionName = ($connectionName === null || $connectionName === '') ? null : $connectionName;
    }

    public function isAllNodesStrategy(): bool
    {
        //绑定非默认集群时强制降级为 single_connection：配置里填 all_nodes 也无效
        if ($this->connectionName !== null && $this->connectionName !== $this->defaultConnectionName()) {
            return false;
        }

        return !empty($this->clusterConfig['enabled'])
            && ($this->clusterConfig['scan_strategy'] ?? CacheConfig::SCAN_STRATEGY_SINGLE_CONNECTION)
                === CacheConfig::SCAN_STRATEGY_ALL_NODES;
    }

    protected function defaultConnectionName(): string
    {
        $name = $this->clusterConfig['default_connection'] ?? CacheConfig::DEFAULT_CONNECTION;
        $name = is_string($name) ? trim($name) : '';

        return $name === '' ? CacheConfig::DEFAULT_CONNECTION : $name;
    }

    public function scanConnections(): array
    {
        $this->usingCurrentConnectionFallback = false;

        if (!$this->isAllNodesStrategy()) {
            return $this->currentConnectionSet();
        }

        $connections = $this->resolveClusterConnections();

        return empty($connections) ? $this->currentConnectionSet() : $connections;
    }

    protected function currentConnectionSet(): array
    {
        $this->usingCurrentConnectionFallback = true;

        return ['current_connection' => $this->currentConnection()];
    }

    protected function currentConnection()
    {
        return Redis::connection($this->connectionName ?? $this->defaultConnectionName());
    }

    public function usesCurrentConnectionFallback(): bool
    {
        return $this->usingCurrentConnectionFallback;
    }

    protected function resolveClusterConnections(): array
    {
        [$clusterName, $nodes] = $this->resolveClusterNodes();
        $connections = [];

        foreach ($nodes as $index => $node) {
            try {
                $name = $this->nodeConnectionName($clusterName, $index);
                $connections[$name] = $this->makeNodeConnection($name, $node);
            } catch (\Exception $e) {
                // Skip failed nodes; callers can fall back when none resolve.
            }
        }

        return $connections;
    }

    protected function readLaravelClusters(): array
    {
        try {
            return config('database.redis.clusters', []);
        } catch (\Exception $e) {
            return [];
        }
    }

    protected function resolveClusterNodes(): array
    {
        $clusters = $this->readLaravelClusters();
        if (empty($clusters)) {
            return ['', []];
        }

        //按当前生效的连接名直取：绑定名优先，未绑定时用 default_connection 的值
        $effectiveName = $this->connectionName ?? $this->defaultConnectionName();
        if (isset($clusters[$effectiveName]) && is_array($clusters[$effectiveName])) {
            return [$effectiveName, array_values($clusters[$effectiveName])];
        }

        if (array_key_exists('default', $clusters)) {
            $clusterName = 'default';
        } elseif (count($clusters) === 1) {
            $clusterName = array_key_first($clusters);
        } else {
            $clusterName = '';
        }

        $nodes = $clusterName !== '' && isset($clusters[$clusterName]) && is_array($clusters[$clusterName])
            ? array_values($clusters[$clusterName])
            : [];

        return [$clusterName, $nodes];
    }

    protected function nodeConnectionName(string $clusterName, int $index): string
    {
        $safeClusterName = preg_replace('/[^A-Za-z0-9_]/', '_', $clusterName);

        return "request_cache_cluster_node_{$safeClusterName}_{$index}";
    }

    protected function makeNodeConnection(string $name, array $node)
    {
        $baseConfig = [];
        try {
            $baseConfig = Config::get('database.redis.default', []);
        } catch (\Exception $e) {
            $baseConfig = [];
        }

        Config::set("database.redis.{$name}", array_merge($baseConfig, ['database' => 0], $node));

        try {
            $root = Redis::getFacadeRoot();
            if (is_object($root) && method_exists($root, 'purge')) {
                Redis::purge($name);
            }
        } catch (\Exception $e) {
            // Purge is best-effort and only needed when the manager supports it.
        }

        return Redis::connection($name);
    }
}
