
在之前的版本中尝试了在Service中和model中直接集成，自定义判断数据结构与数据类型，自动生成RediSearch索引结构，但是效果偏差。

主要有几个影响因素：

#1：无法model或Service类中自动注册使用，归根是无法自动根据数据类型生成索引数据结构，数据具有不确定性，复杂结构数据难以生成适当的匹配规则；
如：过滤，排序，组合搜索，去重等规则。

#2：就算强行生成索引类型，也无法使用封装好的固定链式查询语句检索文档，需要手动使用原生语法，先查看生成的索引数据结构，匹配对应的DSL语法，进行检索，否则也会搜索失败。得不到想要的结果。

结论：需手动注册服务，手动建立索引文件，添加索引文档，根据业务需要追加索引字段，删除文档。每次追加字段都需要重新添加映射才会生效。


直接说明seal扩展集成rediSearch。

确保redis版本高于等于6.2,php版本8.0+


使用顺序：
1：创建resources/schemas/索引名称.php 索引文件
2：在索引文件中定义索引数据结构，具体查看示例
3：在controller或者Service中初始化seal，调用createIndex 创建索引，在使用 saveDocument 添加索引文档。
特殊说明：index文档并非需要包含所有数据字段，如果需要用于索引的字段只有两个，那么index索引字段只需定义三个，文档Id（必须）,其他两个需要索引的字段。
4：添加文档成功即可执行Search查询。

$document=[DB后的数据结构];
//创建索引 products为索引名称需和resources/schemas/目录中的索引文件对应。
if (!$engine->existIndex('products')) {
    //这里会动态读取resources/schemas/索引名称.php 索引文件
    $task = $engine->createIndex('products');
    if ($task !== null) {
        $task->wait();
    }
}
//保存文档到索引
$task = $engine->saveDocument('products', $document);
$searchQuery = $engine->createSearchBuilder('products')
            ->addFilter(Condition::search('12530'))
            ->addFilter(Condition::search('MY9460'))
            //按产品名称搜索
            ->addFilter(Condition::search('吸顶灯'))
            ->addFilter(Condition::search('Ceiling'))
            //按分类路径搜索
            ->addFilter(Condition::search('灯饰'))
            ->addFilter(Condition::search('Lighting'))
            //按价格升序
            ->addSortBy('min_price', 'asc')
            //分页
            ->limit(10)
            ->offset(0);
//执行搜索
$searchResult = $searchQuery->getResult();



手动创建 resources/schemas/索引名称.php
在索引文件中添加对应的索引数据结构，在seal中使用createIndex时会自动加载其中的配置，代码在：
vendor\cmsig\seal-laravel-package\src\SealProvider.php 86-88行

laravel服务启动时：
- Laravel 启动时， SealProvider 会被注册
- 读取 config/cmsig_seal.php 中的配置
- 创建 PhpFileLoader 实例，配置为加载 resources/schemas/目录
- PhpFileLoader 加载 resources/schemas/products.php 文件，获取 Index 对象
- 创建 Schema 对象，包含所有加载的索引定义
- 创建 Engine 实例，传入 Schema 对象


在MD目录中有关于filed字段的说明文档和product-schema示例.md 使用示例，在RediSearch/Controller/RequestCacheTestController.php
productSearch方法是具体的使用示例。
schemas/products.php可直接放在外层resources目录中

说明：外层config中一定得有cmsig_seal.php 配置文件，不然无法指定前缀及rediSearch访问地址。

下面是参考的文档地址：

官方文档：
https://redis.io/docs/latest/develop/ai/search-and-query/
seal配置：
https://github.com/PHP-CMSIG/search/blob/0.12/integrations/laravel/README.md
seal
https://php-cmsig.github.io/search/schema/index.html
seal-laravel-package
https://github.com/PHP-CMSIG/search/tree/0.12/packages/seal-redisearch-adapter

必要的三个composer包
"cmsig/seal": "^0.12.9",
"cmsig/seal-laravel-package": "^0.12.9",
"cmsig/seal-redisearch-adapter": "^0.12.9",
