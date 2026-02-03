<?php

namespace GeorgeRequestCache;

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
     * 从配置文件加载配置
     * @param array $config
     */
    public static function loadFromConfig(array $config)
    {
        if (isset($config['filter'])) {
            $filterConfig = $config['filter'];
            
            if (isset($filterConfig['sql_keywords'])) {
                self::$sqlKeywords = $filterConfig['sql_keywords'];
            }
            
            if (isset($filterConfig['remove_html_tags'])) {
                self::$removeHtmlTags = $filterConfig['remove_html_tags'];
            }
            
            if (isset($filterConfig['trim_whitespace'])) {
                self::$trimWhitespace = $filterConfig['trim_whitespace'];
            }
            
            if (isset($filterConfig['custom_filters'])) {
                self::$customFilters = $filterConfig['custom_filters'];
            }
        }
    }
    
    /**
     * 静态构造函数
     */
    public static function __staticConstruct()
    {
        self::init();
    }
    
    /**
     * 调用静态方法时自动初始化
     */
    public static function __callStatic($name, $arguments)
    {
        if (!isset(self::$allowedCharsPattern)) {
            self::init();
        }
        return call_user_func_array([self, $name], $arguments);
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
     * 添加自定义过滤规则
     * @param callable $filter
     */
    public static function addCustomFilter(callable $filter)
    {
        self::$customFilters[] = $filter;
    }

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
