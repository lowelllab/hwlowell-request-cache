<?php

namespace HwlowellRequestCache;

use CmsIg\Seal\Adapter\RediSearch\RediSearchAdapter;
use CmsIg\Seal\Engine;

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
        $schema = new \CmsIg\Seal\Schema\Schema([$indexName => $index]);

        //创建引擎
        $engine = new Engine(
            new RediSearchAdapter($this->redis),
            $schema
        );

        //确保索引存在//检查索引是否存在，不存在则创建
        $indexName = $this->getIndexName();
        if (!$engine->existIndex($indexName)) {
            $engine->createIndex($indexName);
        }

        return $engine;
    }

    /**
     * 索引文档
     */
    public function index($id, array $document)
    {
        //确保文档包含 id 字段
        $document['id'] = $id;
        return $this->engine->saveDocument($this->getIndexName(), $document);
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

        $results = $this->search($query, $options);
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
        $query = preg_replace('/[^a-zA-Z0-9]/', '', $query);
        //在查询前添加 ~ 符号启用模糊搜索
        $fuzzyQuery = $query . '~';
        return $this->search($fuzzyQuery, $options);
    }

    /**
     * 前缀搜索
     */
    public function searchWithPrefix($prefix, array $options = [])
    {
        $prefix = preg_replace('/[^a-zA-Z0-9]/', '', $prefix);
        //在查询后添加 * 符号启用前缀搜索
        $prefixQuery = $prefix . '*';
        return $this->search($prefixQuery, $options);
    }

    /**
     * 组合搜索
     */
    public function searchWithCombination(array $queries, array $options = [])
    {
        $searchBuilder = $this->engine->createSearchBuilder($this->getIndexName());
        //为每个关键词添加搜索条件
        foreach ($queries as $query) {
            //清除$query所有空格及特殊字符
            $query = preg_replace('/[^a-zA-Z0-9]/', '', $query);
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
}
