<?php

namespace HwlowellRequestCache;

/**
 * 薄日志封装：默认关闭；开启后有 Laravel Log facade 才写。
 */
class CacheLogger
{
    /**
     * null 表示跟随配置文件；显式 true/false 覆盖配置
     * @var bool|null
     */
    protected static $enabled = null;

    /**
     * 设置是否输出日志；传 null 恢复为读取配置
     * @param bool|null $enabled
     */
    public static function setEnabled(?bool $enabled): void
    {
        self::$enabled = $enabled;
    }

    /**
     * 当前是否允许写日志
     */
    public static function isEnabled(): bool
    {
        if (self::$enabled !== null) {
            return self::$enabled;
        }

        try {
            if (function_exists('config')) {
                return (bool) config('request_cache.request_cache.enable_logging', false);
            }
        } catch (\Throwable $e) {
            //忽略
        }

        return false;
    }

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
        if (!self::isEnabled()) {
            return;
        }

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
