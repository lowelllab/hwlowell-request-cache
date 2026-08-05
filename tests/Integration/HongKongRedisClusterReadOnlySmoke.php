<?php

namespace HwlowellRequestCache\Tests\Integration;

use HwlowellRequestCache\Facades\RequestCache;
use HwlowellRequestCache\RedisClusterNodeResolver;
use HwlowellRequestCache\RequestCacheServiceProvider;
use Illuminate\Redis\Connections\PhpRedisClusterConnection;
use Illuminate\Support\Facades\Redis;
use Orchestra\Testbench\TestCase;

class HongKongRedisClusterReadOnlySmoke extends TestCase
{
    private const READ_ONLY_COMMANDS = ['ping', 'scan'];

    protected function getPackageProviders($app)
    {
        return [
            RequestCacheServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        $config = require __DIR__ . '/../../config/request_cache.php';
        $config['request_cache']['enable_stats'] = false;
        $config['cache']['strategy']['fallback'] = false;
        $config['cache']['redis_cluster'] = array_merge(
            $config['cache']['redis_cluster'],
            [
                'enabled' => true,
                'connections' => ['hk'],
                'scan_strategy' => 'all_nodes',
            ]
        );

        $app['config']->offsetSet('request_cache', $config);

        $password = getenv('HK_REDIS_PASSWORD');
        $nodes = $this->parseSeeds((string) getenv('HK_REDIS_SEEDS'), (string) $password);

        $app['config']->offsetSet('database.redis', [
            'client' => 'phpredis',
            'options' => [
                'cluster' => 'redis',
            ],
            'clusters' => [
                'hk' => $nodes,
            ],
        ]);
    }

    public function testHongKongClusterSupportsReadOnlyRoutingAndScan(): void
    {
        if (getenv('RUN_HK_REDIS_CLUSTER_TEST') !== '1') {
            $this->markTestSkipped('Set the explicit opt-in environment variable to run this smoke test.');
        }

        if (!extension_loaded('redis')) {
            $this->markTestSkipped('The phpredis extension is required.');
        }

        if (getenv('HK_REDIS_SEEDS') === false || getenv('HK_REDIS_SEEDS') === ''
            || getenv('HK_REDIS_PASSWORD') === false || getenv('HK_REDIS_PASSWORD') === '') {
            $this->markTestSkipped('Required connection environment variables are missing.');
        }

        try {
            $connection = Redis::connection('hk');
            $this->assertTrue(
                $connection instanceof PhpRedisClusterConnection,
                'The named connection did not resolve to the expected cluster connection type.'
            );
            $this->assertTrue(
                strtoupper((string) $this->runReadOnly($connection, 'ping')) === 'PONG',
                'The read-only connectivity check failed.'
            );

            $nonce = bin2hex(random_bytes(32));
            $miss = RequestCache::cluster('hk')->get(
                'read_only_smoke_' . $nonce,
                ['nonce' => $nonce]
            );
            $this->assertTrue($miss === null, 'The random read unexpectedly matched data.');

            $resolver = new RedisClusterNodeResolver([
                'enabled' => true,
                'scan_strategy' => 'all_nodes',
                'default_connection' => 'default',
            ], 'hk');
            $this->assertFalse($resolver->isAllNodesStrategy());

            $result = $this->runReadOnly(
                $connection,
                'scan',
                '0',
                ['match' => '__request_cache_read_only_smoke__:' . $nonce . ':*', 'count' => 10]
            );
            $validResult = $result === false
                || (is_array($result) && count($result) === 2 && is_array($result[1]));
            $this->assertTrue($validResult, 'The read-only scan returned an unexpected structure.');

            $matches = $result === false ? [] : $result[1];
            $this->assertTrue($matches === [], 'The random scan unexpectedly returned matches.');
        } catch (\PHPUnit\Framework\AssertionFailedError $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->fail('The read-only cluster smoke test could not complete.');
        }
    }

    private function parseSeeds(string $seeds, string $password): array
    {
        $nodes = [];

        foreach (array_filter(array_map('trim', explode(',', $seeds))) as $seed) {
            $separator = strrpos($seed, ':');
            if ($separator === false) {
                continue;
            }

            $host = trim(substr($seed, 0, $separator));
            $port = substr($seed, $separator + 1);
            if ($host === '' || !ctype_digit($port)) {
                continue;
            }

            $nodes[] = [
                'host' => $host,
                'port' => (int) $port,
                'password' => $password,
            ];
        }

        return $nodes;
    }

    private function runReadOnly($connection, string $command, ...$arguments)
    {
        // 禁止非白名单命令。
        if (!in_array($command, self::READ_ONLY_COMMANDS, true)) {
            throw new \LogicException('Command is not allowed by the read-only whitelist.');
        }

        return $connection->{$command}(...$arguments);
    }
}
