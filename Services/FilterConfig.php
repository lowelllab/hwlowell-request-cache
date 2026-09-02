<?php

namespace HwlowellRequestCache;

class FilterConfig
{
    /**
     * 参数过滤配置
     */

    /**
     * SQL 关键字列表
     */
    public static $sqlKeywords = [
        'SELECT', 'INSERT', 'UPDATE', 'DELETE', 'FROM', 'WHERE', 'JOIN', 'ORDER', 'GROUP', 'LIMIT', 'OFFSET',
        'HAVING', 'UNION', 'DISTINCT', 'AS', 'ON', 'IN', 'NOT', 'OR', 'AND', 'LIKE', 'BETWEEN', 'IS', 'NULL',
        'EXEC', 'EXECUTE', 'SP_EXECUTE', 'CALL', 'DROP', 'CREATE', 'ALTER', 'TRUNCATE', 'RENAME', 'GRANT', 'REVOKE',
        'INDEX', 'VIEW', 'PROCEDURE', 'FUNCTION', 'TRIGGER', 'EVENT', 'TABLE', 'DATABASE', 'SCHEMA'
    ];

    /**
     * 保留的字符正则表达式
     */
    public static $allowedCharsPattern;

    /**
     * 初始化
     */
    public static function init()
    {
        //根据 PHP 版本生成兼容的正则表达式
        if (PHP_VERSION_ID >= 80000) {
            //PHP 8.0+ 使用 PCRE2，支持 \x{4e00}-\x{9fa5} 格式
            self::$allowedCharsPattern = '/[^\w\\x{4e00}-\\x{9fa5}]/u';
        } else {
            //PHP 7.4 使用 PCRE，支持 \u4e00-\u9fa5 格式
            self::$allowedCharsPattern = '/[^\w\u4e00-\u9fa5]/u';
        }
    }

    /**
     * 当前实例的配置覆盖层
     * @var array
     */
    protected $overrides;

    /**
     * 构造实例级过滤配置：只记录传入的配置，读取时叠加在当前全局配置之上，不回写静态属性
     * @param array $config
     */
    public function __construct(array $config = [])
    {
        $this->overrides = $config;
    }

    /**
     * 构造实例级过滤配置
     * @param array $config
     * @return static
     */
    public static function make(array $config = [])
    {
        return new static($config);
    }

    /**
     * 读取当前全局过滤配置快照
     * @return array
     */
    protected static function currentSettings(): array
    {
        return [
            'sqlKeywords' => self::$sqlKeywords,
            'allowedCharsPattern' => self::getAllowedCharsPattern(),
            'removeHtmlTags' => self::$removeHtmlTags,
            'trimWhitespace' => self::$trimWhitespace,
            'customFilters' => self::$customFilters,
        ];
    }

    /**
     * 把配置数组叠加到给定的基准配置上
     * @param array $base
     * @param array $config
     * @return array
     */
    protected static function resolveSettings(array $base, array $config): array
    {
        $filterConfig = $config['filter'] ?? [];
        if (!is_array($filterConfig)) {
            return $base;
        }

        $keys = [
            'sql_keywords' => 'sqlKeywords',
            'remove_html_tags' => 'removeHtmlTags',
            'trim_whitespace' => 'trimWhitespace',
            'custom_filters' => 'customFilters',
        ];

        foreach ($keys as $configKey => $settingKey) {
            if (isset($filterConfig[$configKey])) {
                $base[$settingKey] = $filterConfig[$configKey];
            }
        }

        return $base;
    }

    /**
     * 从配置文件加载全局配置
     *
     * 这是唯一会改写静态属性的入口，供应用启动时（ServiceProvider）显式调用。
     *
     * @param array $config
     */
    public static function loadFromConfig(array $config)
    {
        $settings = self::resolveSettings(self::currentSettings(), $config);

        self::$sqlKeywords = $settings['sqlKeywords'];
        self::$removeHtmlTags = $settings['removeHtmlTags'];
        self::$trimWhitespace = $settings['trimWhitespace'];
        self::$customFilters = $settings['customFilters'];
    }

    /**
     * 解析当前实例生效的过滤配置
     * @return array
     */
    protected function settings(): array
    {
        $current = self::currentSettings();

        return $this->overrides === []
            ? $current
            : self::resolveSettings($current, $this->overrides);
    }

    /**
     * 获取实例级 SQL 关键字列表
     * @return array
     */
    public function sqlKeywords(): array
    {
        return $this->settings()['sqlKeywords'];
    }

    /**
     * 获取实例级保留字符正则
     * @return string
     */
    public function allowedCharsPattern(): string
    {
        return $this->settings()['allowedCharsPattern'];
    }

    /**
     * 实例级：是否移除 HTML 标签
     * @return bool
     */
    public function shouldRemoveHtml(): bool
    {
        return (bool) $this->settings()['removeHtmlTags'];
    }

    /**
     * 实例级：是否去除首尾空格
     * @return bool
     */
    public function shouldTrim(): bool
    {
        return (bool) $this->settings()['trimWhitespace'];
    }

    /**
     * 获取实例级自定义过滤规则
     * @return array
     */
    public function customFilters(): array
    {
        return $this->settings()['customFilters'];
    }

    /**
     * 是否移除 HTML 标签
     */
    public static $removeHtmlTags = true;

    /**
     * 是否去除首尾空格
     */
    public static $trimWhitespace = true;

    /**
     * 自定义过滤规则
     * @var array
     */
    public static $customFilters = [];


    /**
     * 获取所有 SQL 关键字
     * @return array
     */
    public static function getSqlKeywords()
    {
        return self::$sqlKeywords;
    }

    /**
     * 获取允许的字符正则表达式
     * @return string
     */
    public static function getAllowedCharsPattern()
    {
        if (!isset(self::$allowedCharsPattern)) {
            self::init();
        }
        return self::$allowedCharsPattern;
    }

    /**
     * 是否移除 HTML 标签
     * @return bool
     */
    public static function shouldRemoveHtmlTags()
    {
        return self::$removeHtmlTags;
    }

    /**
     * 是否去除首尾空格
     * @return bool
     */
    public static function shouldTrimWhitespace()
    {
        return self::$trimWhitespace;
    }

    /**
     * 获取自定义过滤规则
     * @return array
     */
    public static function getCustomFilters()
    {
        return self::$customFilters;
    }
}
