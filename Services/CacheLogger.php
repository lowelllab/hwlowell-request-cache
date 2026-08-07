<?php

namespace HwlowellRequestCache;

/**
 * 薄日志封装：有 Laravel Log facade 就写，否则静默。
 * 关键路径必须可观测，避免 Redis 降级/编码失败在生产环境无声失效。
 */
class CacheLogger
{
    /**
     * @param string $message
     * @param array $context
     */
    public static function warning(string $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    /**
     * @param string $message
     * @param array $context
     */
    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    /**
     * @param string $level
     * @param string $message
     * @param array $context
     */
    protected static function write(string $level, string $message, array $context): void
    {
        try {
            if (!class_exists(\Illuminate\Support\Facades\Log::class)) {
                return;
            }

            \Illuminate\Support\Facades\Log::{$level}('[request-cache] ' . $message, $context);
        } catch (\Throwable $e) {
            //日志本身失败不影响缓存主路径
        }
    }
}
