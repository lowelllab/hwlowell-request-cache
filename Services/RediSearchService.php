<?php

namespace HwlowellRequestCache;

use CmsIg\Seal\Adapter\RediSearch\RediSearchAdapter;
use CmsIg\Seal\Engine;
use Exception;

class RediSearchService
{
    /**
     * 获取索引名称
     */
    protected function getIndexName()
    {
        $appEnv=env('APP_ENV');
        $defaultIndex=$appEnv.'_request_cache';
        return config('request_cache.redis_search.index_name', $defaultIndex);
    }

    /**
     * 单例实例
     */
    protected static $instance;

    /**
     * 搜索引擎实例
     */
    protected $engine;

    /**
     * 模式实例
     */
    protected $schema;

    /**
     * Redis 连接
     */
    protected $redis;

    /**
     * 构造函数
     */
    protected function __construct()
    {
        $this->redis = $this->getRedisConnection();
        $this->engine = $this->createEngine();
    }

    /**
     * 获取实例
     */
    public static function getInstance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 获取 Redis 连接
     */
    protected function getRedisConnection()
    {
        //获取 Laravel Redis 连接
        //$redisConnection = \Illuminate\Support\Facades\Redis::connection();
        $redisConnection = \Illuminate\Support\Facades\Redis::connection();

        //尝试使用 client() 方法获取底层的 Redis 实例
        if (method_exists($redisConnection, 'client')) {
            return $redisConnection->client();
        }

        return $redisConnection;
    }

    /**
     * 创建搜索引擎
     */
    protected function createEngine()
    {
        //定义字段
        $fields = [
            'id' => new \CmsIg\Seal\Schema\Field\IdentifierField('id'),
            'title' => new \CmsIg\Seal\Schema\Field\TextField('title'),
            'content' => new \CmsIg\Seal\Schema\Field\TextField('content'),
            'created_at' => new \CmsIg\Seal\Schema\Field\DateTimeField('created_at'),
            'score' => new \CmsIg\Seal\Schema\Field\FloatField('score'),
        ];

        //创建索引
        $indexName = $this->getIndexName();
        $index = new \CmsIg\Seal\Schema\Index($indexName, $fields);
        //创建schema
        $this->schema = new \CmsIg\Seal\Schema\Schema([$indexName => $index]);

        //创建引擎
        $engine = new Engine(
            new RediSearchAdapter($this->redis),
            $this->schema
        );

        //确保索引存在//检查索引是否存在，不存在则创建
        $indexName = $this->getIndexName();
        if (!$engine->existIndex($indexName)) {
            $engine->createIndex($indexName);
        }

        return $engine;
    }



    /**
     * 搜索
     */
    public function search($query, array $options = [])
    {
        $searchBuilder = $this->engine->createSearchBuilder($this->getIndexName());
        //使用 Condition::search() 创建搜索条件
        $searchBuilder->addFilter(new \CmsIg\Seal\Search\Condition\SearchCondition($query));

        //设置选项
        if (isset($options['limit'])) {
            $searchBuilder->limit($options['limit']);
        }

        if (isset($options['offset'])) {
            $searchBuilder->offset($options['offset']);
        }

        if (isset($options['sort_by'])) {
            foreach ($options['sort_by'] as $field => $direction) {
                $searchBuilder->addSortBy($field, $direction);
            }
        }

        if (isset($options['highlight'])) {
            $fields = $options['highlight'] === true ? ['title', 'content'] : $options['highlight'];
            $searchBuilder->highlight($fields);
        }

        $result = $searchBuilder->getResult();
        return iterator_to_array($result);
    }

    /**
     * 高级搜索（直接传递原始查询）
     */
    public function advancedSearchRaw($query, array $options = [])
    {
        try {
            $indexName = $this->getIndexName();
            $arguments = [];

            // 设置排序
            if (isset($options['sort_by'])) {
                foreach ($options['sort_by'] as $field => $direction) {
                    $arguments[] = 'SORTBY';
                    $arguments[] = $field;
                    $arguments[] = strtoupper($direction);
                }
            }

            // 设置分页
            if (isset($options['limit'])) {
                $arguments[] = 'LIMIT';
                $arguments[] = isset($options['offset']) ? $options['offset'] : 0;
                $arguments[] = $options['limit'];
            }

            // 设置方言
            $arguments[] = 'DIALECT';
            $arguments[] = '2';

            // 执行搜索
            $fullCommand = 'FT.SEARCH ' . $indexName . ' "' . $query . '"';
            foreach ($arguments as $arg) {
                $fullCommand .= ' ' . $arg;
            }
            error_log('Executing FT.SEARCH command: ' . $fullCommand);
            $result = $this->redis->rawCommand('FT.SEARCH', $indexName, $query, ...$arguments);

            if ($result === false) {
                throw new \Exception('Redis search failed: ' . $this->redis->getLastError());
            }

            // 解析结果
            $total = $result[0];
            $documents = [];

            error_log('Search result total: ' . $total);

            for ($i = 1; $i < count($result); $i++) {
                if (is_string($result[$i])) {
                    $docKey = $result[$i];
                    // 从Redis获取完整的文档内容
                    $docJson = $this->redis->rawCommand('JSON.GET', $docKey);
                    if ($docJson) {
                        $document = json_decode($docJson, true);
                        $documents[] = $document;
                        error_log('Found document: ' . json_encode($document));
                    }
                }
            }

            return [
                'total' => $total,
                'documents' => $documents
            ];
        } catch (\Exception $e) {
            error_log('Advanced search error: ' . $e->getMessage());
            return [
                'total' => 0,
                'documents' => []
            ];
        }
    }

    /**
     * 高级搜索
     */
    public function advancedSearch(array $conditions, array $options = [])
    {
        $searchBuilder = $this->engine->createSearchBuilder($this->getIndexName());

        //添加搜索条件
        foreach ($conditions as $condition) {
            $searchBuilder->addFilter($condition);
        }

        //设置选项
        if (isset($options['limit'])) {
            $searchBuilder->limit($options['limit']);
        }

        if (isset($options['offset'])) {
            $searchBuilder->offset($options['offset']);
        }

        if (isset($options['sort_by'])) {
            foreach ($options['sort_by'] as $field => $direction) {
                $searchBuilder->addSortBy($field, $direction);
            }
        }

        if (isset($options['highlight'])) {
            $fields = $options['highlight'] === true ? ['title', 'content'] : $options['highlight'];
            $searchBuilder->highlight($fields);
        }

        $result = $searchBuilder->getResult();
        return iterator_to_array($result);
    }

    /**
     * 批量索引文档
     */
    public function bulkIndex(array $documents)
    {
        $saveDocuments = [];
        foreach ($documents as $id => $document) {
            $document['id'] = $id;
            $saveDocuments[] = $document;
        }

        return $this->engine->bulk($this->getIndexName(), $saveDocuments, []);
    }

    /**
     * 批量删除文档
     */
    public function bulkDelete(array $ids)
    {
        return $this->engine->bulk($this->getIndexName(), [], $ids);
    }

    /**
     * 获取文档数量
     */
    public function countDocuments()
    {
        return $this->engine->countDocuments($this->getIndexName());
    }

    /**
     * 检查索引是否存在
     */
    public function existIndex()
    {
        return $this->engine->existIndex($this->getIndexName());
    }

    /**
     * 重建索引
     */
    public function rebuildIndex()
    {
        if ($this->engine->existIndex($this->getIndexName())) {
            $this->engine->dropIndex($this->getIndexName());
        }
        return $this->engine->createIndex($this->getIndexName());
    }

    /**
     * 删除文档
     */
    public function delete($id)
    {
        return $this->engine->deleteDocument($this->getIndexName(), $id);
    }

    /**
     * 清除所有索引
     */
    public function clear()
    {
        if ($this->engine->existIndex($this->getIndexName())) {
            $this->engine->dropIndex($this->getIndexName());
        }
        $this->engine->createIndex($this->getIndexName());
        return true;
    }

    /**
     * 获取引擎实例
     */
    public function getEngine()
    {
        return $this->engine;
    }

    /**
     * 带分页的搜索
     */
    public function searchWithPagination($query, $page = 1, $perPage = 10, array $options = [])
    {
        $offset = ($page - 1) * $perPage;
        $options['limit'] = $perPage;
        $options['offset'] = $offset;

        $results = $this->advancedSearchRaw($query, $options);
        $total = $this->countDocuments();
        $totalPages = ceil($total / $perPage);

        return [
            'results' => $results,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages
            ]
        ];
    }

    /**
     * 模糊搜索
     */
    public function searchWithFuzzy($query, array $options = [])
    {
        //$query = preg_replace('/[^a-zA-Z0-9]/', '', $query);
        //在查询前添加 ~ 符号启用模糊搜索
        $fuzzyQuery = $query . '~';
        return $this->advancedSearchRaw($fuzzyQuery, $options);
    }

    /**
     * 前缀搜索
     */
    public function searchWithPrefix($prefix, array $options = [])
    {
        //$prefix = preg_replace('/[^a-zA-Z0-9]/', '', $prefix);
        //在查询后添加 * 符号启用前缀搜索
        $prefixQuery = $prefix . '*';
        return $this->advancedSearchRaw($prefixQuery, $options);
    }

    /**
     * 组合搜索
     */
    public function searchWithCombination(array $queries, array $options = [])
    {
        $searchBuilder = $this->engine->createSearchBuilder($this->getIndexName());
        //为每个关键词添加搜索条件
        foreach ($queries as $query) {
            //清除$query特殊字符，保留空格
            $query = preg_replace('/[^a-zA-Z0-9\s]/', '', $query);
            $searchBuilder->addFilter(new \CmsIg\Seal\Search\Condition\SearchCondition($query));
        }

        //设置选项
        if (isset($options['limit'])) {
            $searchBuilder->limit($options['limit']);
        }

        if (isset($options['offset'])) {
            $searchBuilder->offset($options['offset']);
        }

        if (isset($options['highlight'])) {
            $fields = $options['highlight'] === true ? ['title', 'content'] : $options['highlight'];
            $searchBuilder->highlight($fields);
        }

        $result = $searchBuilder->getResult();
        return iterator_to_array($result);
    }

    /**
     * 带过滤器的搜索
     */
    public function searchWithFilter($query, array $filters, array $options = [])
    {
        $searchBuilder = $this->engine->createSearchBuilder($this->getIndexName());

        $query = preg_replace('/[^a-zA-Z0-9]/', '', $query);
        //添加搜索条件
        $searchBuilder->addFilter(new \CmsIg\Seal\Search\Condition\SearchCondition($query));

        //设置选项
        if (isset($options['limit'])) {
            $searchBuilder->limit($options['limit']);
        }

        if (isset($options['offset'])) {
            $searchBuilder->offset($options['offset']);
        }

        if (isset($options['highlight'])) {
            $fields = $options['highlight'] === true ? ['title', 'content'] : $options['highlight'];
            $searchBuilder->highlight($fields);
        }

        $result = $searchBuilder->getResult();
        return iterator_to_array($result);
    }

    /**
     * 获取热门搜索词
     * 预留方法
     */
    public function getPopularSearches($limit = 10)
    {
        //这里可以实现热门搜索词的逻辑
        //例如，从 Redis 中获取搜索历史并统计频率
        return [];
    }

    /**
     * 动态添加索引字段
     * @param string $fieldName 字段名称
     * @param string $fieldType 字段类型 (TEXT, TAG, NUMERIC, GEO, DATE)
     * @param array $options 选项
     * @return bool
     */
    public function addField($fieldName, $fieldType = 'TEXT', $options = [])
    {
        try {
            $indexName = $this->getIndexName();

            //构建命令参数
            $args = [];
            $args[] = $indexName;
            $args[] = 'SCHEMA';
            $args[] = 'ADD';
            $args[] = '$[\'' . $fieldName . '\']';
            $args[] = 'AS';
            $args[] = $fieldName;

            //对于category字段，强制使用TAG类型
            if (strtolower($fieldName) === 'category') {
                $fieldType = 'TAG';
            }

            //添加字段类型
            switch (strtoupper($fieldType)) {
                case 'NUMERIC':
                    $args[] = 'NUMERIC';
                    $args[] = 'SORTABLE';
                    break;
                case 'TAG':
                    $args[] = 'TAG';
                    $args[] = 'SEPARATOR';
                    $args[] = ',';
                    $args[] = 'SORTABLE';
                    break;
                case 'GEO':
                    $args[] = 'GEO';
                    break;
                case 'DATE':
                    $args[] = 'NUMERIC';
                    $args[] = 'SORTABLE';
                    break;
                default:
                    $args[] = 'TEXT'; // Default to TEXT type for compatibility
                    $args[] = 'SORTABLE';
            }

            // 添加通用选项
            if (!empty($options)) {
                if (isset($options['sortable']) && $options['sortable']) {
                    $args[] = 'SORTABLE';
                }
                if (isset($options['noindex']) && $options['noindex']) {
                    $args[] = 'NOINDEX';
                }
                if (isset($options['weight']) && is_numeric($options['weight'])) {
                    $args[] = 'WEIGHT';
                    $args[] = $options['weight'];
                }
            }

            //检查redis实例的类型
            $redisClass = get_class($this->redis);
            error_log('Redis class: ' . $redisClass);

            //尝试使用phpredis的rawCommand方法
            if (method_exists($this->redis, 'rawCommand')) {
                //构建完整的参数数组
                $rawArgs = array_merge(['FT.ALTER'], $args);
                error_log('FT.ALTER command: ' . json_encode($rawArgs));
                try {
                    $result = call_user_func_array([$this->redis, 'rawCommand'], $rawArgs);
                    error_log('FT.ALTER result: ' . json_encode($result));
                    return $result === 'OK' || $result === true;
                } catch (Exception $e) {
                    error_log('FT.ALTER error: ' . $e->getMessage());
                    //检查是否是字段重复的错误
                    if (strpos($e->getMessage(), 'Duplicate field') !== false) {
                        return true; // 字段已经存在，返回成功
                    }
                    return false;
                }
            }

            //尝试使用Laravel Redis的command方法
            if (method_exists($this->redis, 'command')) {
                error_log('FT.ALTER command: ' . json_encode($args));
                try {
                    $result = $this->redis->command('FT.ALTER', $args);
                    error_log('FT.ALTER result: ' . json_encode($result));
                    return $result === 'OK' || $result === true;
                } catch (Exception $e) {
                    error_log('FT.ALTER error: ' . $e->getMessage());
                    //检查是否是字段重复的错误
                    if (strpos($e->getMessage(), 'Duplicate field') !== false) {
                        return true; // 字段已经存在，返回成功
                    }
                    return false;
                }
            }

            //尝试使用Laravel Redis的eval方法来执行FT.ALTER命令
            if (method_exists($this->redis, 'eval')) {
                $command = 'FT.ALTER ' . implode(' ', array_map(function($arg) {
                    return is_string($arg) ? '"' . addslashes($arg) . '"' : $arg;
                }, $args));
                error_log('FT.ALTER command via eval: ' . $command);
                try {
                    $result = $this->redis->eval('return redis.call("FT.ALTER", ' . implode(', ', array_map(function($arg) {
                        return is_string($arg) ? '"' . addslashes($arg) . '"' : $arg;
                    }, $args)) . ')', 0);
                    error_log('FT.ALTER result via eval: ' . json_encode($result));
                    return $result === 'OK' || $result === true;
                } catch (Exception $e) {
                    error_log('FT.ALTER error via eval: ' . $e->getMessage());
                    //检查是否是字段重复的错误
                    if (strpos($e->getMessage(), 'Duplicate field') !== false) {
                        return true; //字段已经存在，返回成功
                    }
                    return false;
                }
            }

            //如果以上方法都失败，返回false
            return false;
        } catch (Exception $e) {
            //检查是否是字段重复的错误
            if (strpos($e->getMessage(), 'Duplicate field') !== false) {
                return true; // 字段已经存在，返回成功
            }
            error_log('FT.ALTER error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 批量添加索引字段
     * @param array $fields 字段配置数组
     * @return array 结果数组
     */
    public function addFields($fields)
    {
        $results = [];
        foreach ($fields as $fieldName => $config) {
            $fieldType = isset($config['type']) ? $config['type'] : 'TEXT';
            $options = isset($config['options']) ? $config['options'] : [];
            $results[$fieldName] = $this->addField($fieldName, $fieldType, $options);
        }
        return $results;
    }

    /**
     * 获取索引字段信息
     * @return array
     */
    public function getFields()
    {
        try {
            $indexName = $this->getIndexName();
            $result = $this->redis->rawCommand('FT.INFO', $indexName);

            $fields = [];

            //遍历结果，找到attributes部分
            for ($i = 0; $i < count($result); $i++) {
                if (is_array($result[$i]) && isset($result[$i][0]) && $result[$i][0] === 'attributes') {
                    $attributeCount = $result[$i][1];
                    if (isset($result[$i + 1]) && is_array($result[$i + 1])) {
                        $attributesData = $result[$i + 1];
                        for ($j = 0; $j < count($attributesData); $j++) {
                            if (is_array($attributesData[$j])) {
                                $fieldData = $attributesData[$j];
                                if (isset($fieldData[0]) && isset($fieldData[1])) {
                                    $fieldName = $fieldData[0];
                                    $fieldType = $fieldData[1];
                                    $fields[$fieldName] = ['attribute' => $fieldName, 'type' => $fieldType];
                                    error_log('Found field: ' . $fieldName . ' with type: ' . $fieldType);
                                }
                            }
                        }
                    }
                    break;
                }
            }

            error_log('Final fields: ' . json_encode($fields));
            return $fields;
        } catch (Exception $e) {
            error_log('RediSearch getFields error: ' . $e->getMessage());
            error_log('Error trace: ' . $e->getTraceAsString());
            return [];
        }
    }

    /**
     * 索引文档（自动添加新字段）
     * @param string $id 文档ID
     * @param array $document 文档内容
     * @return bool
     */
    public function index($id, array $document)
    {
        //确保文档包含 id 字段
        $document['id'] = $id;

        //确保created_at字段是数字时间戳格式
        if (isset($document['created_at'])) {
            $createdAt = $document['created_at'];
            if (is_string($createdAt) && strtotime($createdAt) !== false) {
                $document['created_at'] = strtotime($createdAt);
            } elseif (!is_numeric($createdAt)) {
                $document['created_at'] = time();
            }
        } else {
            $document['created_at'] = time();
        }

        //为所有字段添加索引，不管是否已存在
        foreach ($document as $fieldName => $fieldValue) {
            //跳过 id 字段
            if ($fieldName === 'id') {
                continue;
            }

            //根据值类型自动推断字段类型
            $fieldType = $this->inferFieldType($fieldName, $fieldValue);
            $this->addField($fieldName, $fieldType);
        }

        //直接使用Redis命令保存文档和索引
        try {
            $indexName = $this->getIndexName();
            $key = $indexName . ':' . $id;

            //使用JSON.SET保存文档
            $jsonDocument = json_encode($document);
            error_log('JSON document to save: ' . $jsonDocument);
            $jsonSetResult = $this->redis->rawCommand('JSON.SET', $key, '$', $jsonDocument);
            error_log('JSON.SET result: ' . json_encode($jsonSetResult));

            //验证文档是否正确保存
            $jsonGetResult = $this->redis->rawCommand('JSON.GET', $key);
            error_log('JSON.GET result: ' . $jsonGetResult);

            //对于JSON索引，我们不需要使用FT.ADD命令，因为JSON.SET会自动更新索引
            //只需要确保文档被正确保存即可
            error_log('Document saved successfully: ' . $key);
            return $jsonSetResult === true || $jsonSetResult === 'OK';
        } catch (Exception $e) {
            error_log('Index error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 根据值类型推断字段类型
     * @param string $fieldName 字段名称
     * @param mixed $value
     * @return string
     */
    protected function inferFieldType($fieldName, $value)
    {
        //对于category和tags字段，总是使用TAG类型
        $lowerFieldName = strtolower($fieldName);
        if ($lowerFieldName === 'category' || $lowerFieldName === 'tags') {
            return 'TAG';
        }

        if (is_numeric($value)) {
            return 'NUMERIC';
        } elseif (is_string($value) && strlen($value) > 0) {
            //检查是否是日期格式
            if (strtotime($value) !== false) {
                return 'DATE';
            }
            //检查是否是标签格式（逗号分隔）
            if (strpos($value, ',') !== false) {
                return 'TAG';
            }
            return 'TEXT';
        } elseif (is_array($value)) {
            return 'TEXT';
        }
        return 'TEXT';
    }
}
