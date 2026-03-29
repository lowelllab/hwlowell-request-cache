# 产品数据结构字段定义

## 数据结构分析

这是一个典型的电商产品数据结构，包含基本信息、价格、图片、属性、分类等多个维度。

---

## 完整字段定义

```php
use CmsIg\Seal\Schema\Field\TextField;
use CmsIg\Seal\Schema\Field\IntegerField;
use CmsIg\Seal\Schema\Field\FloatField;
use CmsIg\Seal\Schema\Field\BooleanField;
use CmsIg\Seal\Schema\Field\DateTimeField;
use CmsIg\Seal\Schema\Field\IdentifierField;
use CmsIg\Seal\Schema\Field\ObjectField;
use CmsIg\Seal\Schema\Field\TypedField;

// 产品索引定义
$productIndex = new Index('products', [
    // ==================== 核心标识字段 ====================
    
    // 主键ID
    'id' => new IdentifierField('id'),
    
    // PDMS系统ID
    'pdms_id' => new IntegerField('pdms_id', filterable: true),
    
    // 唯一标识码
    'uniqid' => new TextField('uniqid', searchable: true, filterable: true),
    
    // 父级唯一标识
    'parent_uniqid' => new TextField('parent_uniqid', filterable: true),
    
    // 分组唯一标识
    'group_uniqid' => new TextField('group_uniqid', filterable: true, multiple: true),
    
    // ==================== 分类关联字段 ====================
    
    // 基础部门ID
    'basic_dept_id' => new IntegerField('basic_dept_id', filterable: true, facet: true),
    
    // 分类ID
    'category_id' => new IntegerField('category_id', filterable: true, facet: true),
    
    // 分类路径（中文）
    'category_path_cn' => new TextField('category_path_cn', searchable: true, filterable: true),
    
    // 分类路径（英文）
    'category_path_en' => new TextField('category_path_en', searchable: true, filterable: true),
    
    // 标签（分号分隔）
    'tags' => new TextField('tags', searchable: true, filterable: true, multiple: true),
    
    // ==================== 产品名称字段 ====================
    
    // 产品名称（中文）
    'name_cn' => new TextField('name_cn', searchable: true, filterable: true),
    
    // 产品名称（英文）
    'name_en' => new TextField('name_en', searchable: true, filterable: true),
    
    // ==================== 单位字段 ====================
    
    // 单位
    'unit' => new TextField('unit', filterable: true, facet: true),
    
    // 单位（中文）
    'unit_cn' => new TextField('unit_cn', filterable: true, facet: true),
    
    // ==================== 价格字段 ====================
    
    // 最低价格
    'bottom_price' => new FloatField('bottom_price', filterable: true, sortable: true),
    
    // 最低含税价格
    'bottom_tax_price' => new FloatField('bottom_tax_price', filterable: true, sortable: true),
    
    // 最高售价
    'max_price' => new FloatField('max_price', filterable: true, sortable: true),
    
    // 最低售价
    'min_price' => new FloatField('min_price', filterable: true, sortable: true),
    
    // 价格范围字符串
    'price' => new TextField('price', searchable: true),
    
    // ==================== 图片字段 ====================
    
    // 主图
    'main_image' => new TextField('main_image'),
    
    // 子图列表
    'sub_images' => new TextField('sub_images', multiple: true),
    
    // 详情图
    'detail_images' => new TextField('detail_images', multiple: true),
    
    // 3D图
    'threed_images' => new TextField('threed_images', multiple: true),
    
    // 设计图
    'designs_images' => new TextField('designs_images', multiple: true),
    
    // ==================== 供应商信息 ====================
    
    // 供应商唯一标识
    'supply_uniqid' => new TextField('supply_uniqid', filterable: true),
    
    // 供应商信息
    'supply_info' => new TextField('supply_info', searchable: true),
    
    // ==================== 默认属性（嵌套对象数组） ====================
    
    'default_attrs' => new ObjectField('default_attrs', [
        'name_cn' => new TextField('name_cn', searchable: true),
        'name_en' => new TextField('name_en', searchable: true),
        'value_cn' => new TextField('value_cn', searchable: true),
        'value_en' => new TextField('value_en', searchable: true),
    ], multiple: true),
    
    // ==================== 描述字段 ====================
    
    // 描述数组（键值对）
    'desc_arr' => new ObjectField('desc_arr', [
        'k' => new TextField('k', searchable: true),
        'v' => new TextField('v', searchable: true),
    ], multiple: true),
    
    // 描述文本
    'desc' => new TextField('desc', searchable: true),
    
    // 备注
    'remark' => new TextField('remark', multiple: true),
    
    // ==================== 状态字段 ====================
    
    // 状态
    'status' => new IntegerField('status', filterable: true, facet: true),
    
    // 是否在店铺展示
    'is_showInStore' => new BooleanField('is_showInStore', filterable: true, facet: true),
    
    // 是否热门
    'is_hot' => new BooleanField('is_hot', filterable: true, facet: true),
    
    // 来源
    'source' => new IntegerField('source', filterable: true, facet: true),
    
    // 类型
    'type' => new IntegerField('type', filterable: true, facet: true),
    
    // 是否百分比
    'is_percent' => new BooleanField('is_percent', filterable: true),
    
    // 百分比评分
    'percent_score' => new IntegerField('percent_score', filterable: true, sortable: true),
    
    // ==================== 统计字段 ====================
    
    // 浏览量
    'viewed' => new IntegerField('viewed', filterable: true, sortable: true),
    
    // 销量
    'sales' => new IntegerField('sales', filterable: true, sortable: true),
    
    // 评论评分
    'comment_score' => new FloatField('comment_score', filterable: true, sortable: true),
    
    // 排序权重
    'sort_order' => new IntegerField('sort_order', sortable: true),
    
    // ==================== 关联字段 ====================
    
    // 部门ID
    'dept_id' => new IntegerField('dept_id', filterable: true),
    
    // 人员ID
    'person_id' => new IntegerField('person_id', filterable: true),
    
    // 包ID列表
    'package_id' => new IntegerField('package_id', filterable: true, multiple: true),
    
    // ==================== 店铺站点信息（嵌套对象数组） ====================
    
    'productSites' => new ObjectField('productSites', [
        'product_id' => new IntegerField('product_id', filterable: true),
        'company_id' => new IntegerField('company_id', filterable: true),
        'shop_category_id' => new IntegerField('shop_category_id', filterable: true),
        'sort_order' => new IntegerField('sort_order'),
        'recycle' => new IntegerField('recycle', filterable: true),
    ], multiple: true),
    
    // ==================== 价格明细（嵌套对象数组） ====================
    
    'prices' => new ObjectField('prices', [
        'model' => new TextField('model', searchable: true, filterable: true),
        'index' => new TextField('index', filterable: true),
        'cost' => new FloatField('cost', filterable: true, sortable: true),
        'cost_max' => new FloatField('cost_max', filterable: true),
        'profit' => new FloatField('profit', filterable: true),
        'discount' => new FloatField('discount', filterable: true),
        'tax' => new FloatField('tax', filterable: true),
        'cost_tax' => new FloatField('cost_tax', filterable: true),
        'sale_price_tax' => new FloatField('sale_price_tax', filterable: true),
        'estimated_price' => new FloatField('estimated_price', filterable: true),
        'estimated_price_tax' => new FloatField('estimated_price_tax', filterable: true),
        'estimated_price_range' => new TextField('estimated_price_range'),
        'estimated_price_range_min' => new FloatField('estimated_price_range_min', filterable: true),
        'estimated_price_range_max' => new FloatField('estimated_price_range_max', filterable: true),
        'recent_price' => new FloatField('recent_price', filterable: true),
        'status' => new IntegerField('status', filterable: true),
        'image' => new TextField('image'),
        'is_change' => new BooleanField('is_change', filterable: true),
        'salesPrice' => new FloatField('salesPrice', filterable: true, sortable: true),
        'salesPriceMax' => new FloatField('salesPriceMax', filterable: true),
        'name_cn' => new TextField('name_cn', searchable: true),
        'name_en' => new TextField('name_en', searchable: true),
    ], multiple: true),
    
    // ==================== 时间字段 ====================
    
    // 创建时间
    'created_at' => new DateTimeField('created_at', filterable: true, sortable: true),
    
    // 更新时间
    'updated_at' => new DateTimeField('updated_at', filterable: true, sortable: true),
    
    // 删除时间
    'deleted_at' => new DateTimeField('deleted_at', filterable: true),
    
    // 图片创建时间
    'images_created_at' => new DateTimeField('images_created_at', filterable: true),
    
    // 图片更新时间
    'images_updated_at' => new DateTimeField('images_updated_at', filterable: true),
    
    // Elasticsearch更新时间
    'elasticsearch_updated_at' => new DateTimeField('elasticsearch_updated_at', filterable: true),
    
    // ==================== 冗余/计算字段 ====================
    
    // 含税价格范围
    'min_price_tax' => new FloatField('min_price_tax', filterable: true, sortable: true),
    
    'max_price_tax' => new FloatField('max_price_tax', filterable: true, sortable: true),
    
    'price_tax' => new TextField('price_tax', searchable: true),
    
    'estimatedPrice' => new TextField('estimatedPrice', searchable: true),
    
    // 父级分组唯一标识（冗余）
    'parent_group_uniqid' => new TextField('parent_group_uniqid', filterable: true),
    
    'parent_group_uniqif' => new TextField('parent_group_uniqif', filterable: true),
    
], [
    'identifier' => 'id',
]);


// 添加文档到索引示例

## 添加文档到索引

### 1. 单文档添加

使用 `saveDocument` 方法添加单个文档：

```php
use CmsIg\Seal\Engine;

// 假设 $engine 是已配置的 Engine 实例
$engine->saveDocument('products', [
    'id' => '12530',
    'pdms_id' => 12530,
    'uniqid' => 'MY9460-500',
    'parent_uniqid' => 'MY9460-600',
    'basic_dept_id' => 21,
    'category_id' => 810,
    'category_path_cn' => '灯饰-灯饰-旧资料-装饰照明-吸顶灯-奢华风格',
    'category_path_en' => 'Lighting fixtures-Lighting-Old data-Decorative lighting-Ceiling lamp-Luxury style',
    'name_cn' => '现代吸顶灯MY9460-500',
    'name_en' => 'Modern Ceiling Lights MY9460-500',
    'unit' => 'sqm',
    'unit_cn' => '㎡',
    'main_image' => 'https://img.gbuilderchina.com/gb/20220716/132335/main_MY9460-600.jpg',
    'sub_images' => [
        'https://img.gbuilderchina.com/gb/20240520/171824/1_MY9460-600.jpg'
    ],
    'bottom_price' => 0,
    'bottom_tax_price' => 0,
    'min_price' => 1251,
    'max_price' => 1251,
    'price' => '1251~1251',
    'status' => 1,
    'is_showInStore' => true,
    'is_hot' => false,
    'viewed' => 64,
    'sales' => 0,
    'comment_score' => 5,
    'sort_order' => 999,
    'created_at' => '2023-12-05 14:53:23',
    'updated_at' => '2026-01-29 02:09:23',
    'default_attrs' => [
        [
            'name_cn' => '长度',
            'name_en' => 'Length',
            'value_cn' => '500',
            'value_en' => '500'
        ],
        [
            'name_cn' => '可定制的证书',
            'name_en' => 'Customizable Certificate',
            'value_cn' => 'CE/SAA/UL',
            'value_en' => 'CE/SAA/UL'
        ],
        [
            'name_cn' => '保修',
            'name_en' => 'Warranty',
            'value_cn' => '5年有限 (住宅)，1年有限 (商业)',
            'value_en' => '5 years limited (residential),1 year limited (commercial)'
        ]
    ],
    'prices' => [
        [
            'model' => 'MY9460-500',
            'index' => 'a0',
            'cost' => 417,
            'profit' => 300,
            'salesPrice' => 1251,
            'status' => 1
        ]
    ]
]);
```

### 2. 批量添加文档

使用 `bulk` 方法批量添加多个文档：

```php
$engine->bulk(
    index: 'products',
    saveDocuments: [
        [
            'id' => '12530',
            'name_cn' => '现代吸顶灯MY9460-500',
            'name_en' => 'Modern Ceiling Lights MY9460-500',
            'category_id' => 810,
            'min_price' => 1251,
            'status' => 1
        ],
        [
            'id' => '12531',
            'name_cn' => '现代吸顶灯MY9460-600',
            'name_en' => 'Modern Ceiling Lights MY9460-600',
            'category_id' => 810,
            'min_price' => 1500,
            'status' => 1
        ]
    ],
    bulkSize: 100
);
```

### 3. 删除文档

```php
// 删除单个文档
$engine->deleteDocument('products', '12530');
```

### 4. 批量删除文档

```php
$engine->bulk(
    index: 'products',
    deleteDocumentIdentifiers: ['12530', '12531'],
    bulkSize: 100
);
```

### 5. 更新文档

更新文档使用与添加相同的 `saveDocument` 方法，只需使用相同的 identifier：

```php
// 更新产品价格
$engine->saveDocument('products', [
    'id' => '12530',  // 相同的 ID
    'min_price' => 1100,  // 新的价格
    'max_price' => 1100,
    'updated_at' => date('Y-m-d H:i:s')
]);
```

### 6. 使用 ReindexProvider 批量重建索引

```php
use CmsIg\Seal\Reindex\ReindexProviderInterface;
use CmsIg\Seal\Reindex\ReindexConfig;

class ProductReindexProvider implements ReindexProviderInterface
{
    public function total(): ?int
    {
        return 1000; // 总文档数
    }

    public function provide(ReindexConfig $reindexConfig): \Generator
    {
        // 从数据库获取产品数据
        $products = $this->getProductsFromDatabase();
        
        foreach ($products as $product) {
            yield [
                'id' => (string) $product['id'],
                'name_cn' => $product['name_cn'],
                'name_en' => $product['name_en'],
                'category_id' => $product['category_id'],
                'min_price' => $product['min_price'],
                'status' => $product['status']
            ];
        }
    }

    public static function getIndex(): string
    {
        return 'products';
    }
}

// 重建索引
$reindexProviders = [new ProductReindexProvider()];
$reindexConfig = ReindexConfig::create()
    ->withIndex('products')
    ->withBulkSize(100)
    ->withDropIndex(true);  // 重建前清空索引

$engine->reindex($reindexProviders, $reindexConfig);
```

---

## 注意事项

1. **ID 格式要求**：
   - 只能包含字母 (a-z, A-Z)、数字 (0-9)、连字符 (-) 和下划线 (_)
   - 不能包含空格或特殊字符
   - 不能以连字符开头

2. **必填字段**：
   - `id` 是唯一必填字段
   - 其他字段可以为 null 或省略

3. **嵌套对象**：
   - `default_attrs`、`prices`、`productSites` 等嵌套对象需要按照定义的字段结构提供
   - 数组类型的嵌套对象会自动处理

4. **批量操作**：
   - 建议使用 `bulkSize` 参数控制批量大小，默认为 100
   - 大批量导入时，适当调整 `bulkSize` 可以优化性能

5. **更新操作**：
   - 更新文档时，只需要提供需要更新的字段和 `id`
   - 未提供的字段会保持不变

```

---

## 字段分类说明

### 1. 核心标识字段
| 字段名 | 类型 | 用途 |
|--------|------|------|
| `id` | IdentifierField | 主键 |
| `pdms_id` | IntegerField | PDMS系统ID |
| `uniqid` | TextField | 产品唯一码 |
| `parent_uniqid` | TextField | 父产品标识 |
| `group_uniqid` | TextField | 分组标识 |

### 2. 分类关联字段
| 字段名 | 类型 | 用途 |
|--------|------|------|
| `basic_dept_id` | IntegerField | 部门ID |
| `category_id` | IntegerField | 分类ID |
| `category_path_cn` | TextField | 中文分类路径 |
| `category_path_en` | TextField | 英文分类路径 |
| `tags` | TextField | 标签 |

### 3. 产品名称字段
| 字段名 | 类型 | 用途 |
|--------|------|------|
| `name_cn` | TextField | 中文名称（可搜索） |
| `name_en` | TextField | 英文名称（可搜索） |

### 4. 价格字段
| 字段名 | 类型 | 用途 |
|--------|------|------|
| `bottom_price` | FloatField | 底价 |
| `bottom_tax_price` | FloatField | 含税底价 |
| `max_price` | FloatField | 最高价 |
| `min_price` | FloatField | 最低价 |
| `price` | TextField | 价格范围字符串 |

### 5. 图片字段
| 字段名 | 类型 | 用途 |
|--------|------|------|
| `main_image` | TextField | 主图URL |
| `sub_images` | TextField | 子图列表 |
| `detail_images` | TextField | 详情图 |
| `threed_images` | TextField | 3D图 |
| `designs_images` | TextField | 设计图 |

### 6. 嵌套对象字段
| 字段名 | 类型 | 用途 |
|--------|------|------|
| `default_attrs` | ObjectField | 默认属性列表 |
| `desc_arr` | ObjectField | 描述键值对 |
| `productSites` | ObjectField | 店铺站点信息 |
| `prices` | ObjectField | 价格明细列表 |

### 7. 状态字段
| 字段名 | 类型 | 用途 |
|--------|------|------|
| `status` | IntegerField | 状态 |
| `is_showInStore` | BooleanField | 是否展示 |
| `is_hot` | BooleanField | 是否热门 |
| `source` | IntegerField | 来源 |
| `type` | IntegerField | 类型 |

### 8. 统计字段
| 字段名 | 类型 | 用途 |
|--------|------|------|
| `viewed` | IntegerField | 浏览量 |
| `sales` | IntegerField | 销量 |
| `comment_score` | FloatField | 评论评分 |
| `sort_order` | IntegerField | 排序权重 |

### 9. 时间字段
| 字段名 | 类型 | 用途 |
|--------|------|------|
| `created_at` | DateTimeField | 创建时间 |
| `updated_at` | DateTimeField | 更新时间 |
| `deleted_at` | DateTimeField | 删除时间 |
| `elasticsearch_updated_at` | DateTimeField | ES更新时间 |

---

## 使用建议

### 1. 搜索场景
```php
// 产品名称搜索
$nameQuery = new SearchQuery('products');
$nameQuery->addCondition(new Condition('name_cn', '吸顶灯', Condition::OPERATOR_CONTAINS));

// 多字段搜索
$multiQuery = new SearchQuery('products');
$multiQuery->addCondition(new Condition('name_cn', '现代', Condition::OPERATOR_CONTAINS));
$multiQuery->addCondition(new Condition('name_en', 'Modern', Condition::OPERATOR_CONTAINS));
```

### 2. 过滤场景
```php
// 按分类过滤
$categoryFilter = new FilterQuery('products');
$categoryFilter->addCondition(new Condition('category_id', 810, Condition::OPERATOR_EQUALS));

// 按价格范围过滤
$priceFilter = new FilterQuery('products');
$priceFilter->addCondition(new Condition('min_price', 1000, Condition::OPERATOR_GTE));
$priceFilter->addCondition(new Condition('max_price', 2000, Condition::OPERATOR_LTE));

// 按状态过滤
$statusFilter = new FilterQuery('products');
$statusFilter->addCondition(new Condition('status', 1, Condition::OPERATOR_EQUALS));
$statusFilter->addCondition(new Condition('is_showInStore', true, Condition::OPERATOR_EQUALS));
```

### 3. 排序场景
```php
// 按价格排序
$priceSort = new SortQuery('products');
$priceSort->addSort('min_price', SortQuery::ORDER_ASC);

// 按销量排序
$salesSort = new SortQuery('products');
$salesSort->addSort('sales', SortQuery::ORDER_DESC);

// 按时间排序
$timeSort = new SortQuery('products');
$timeSort->addSort('created_at', SortQuery::ORDER_DESC);
```

### 4. 分面统计场景
```php
// 按分类分面
$categoryFacet = new FacetQuery('products');
$categoryFacet->addFacet('category_id');

// 按状态分面
$statusFacet = new FacetQuery('products');
$statusFacet->addFacet('status');
$statusFacet->addFacet('is_hot');
```

---

## 注意事项

1. **图片字段**：图片URL使用 TextField 存储，不进行全文搜索
2. **嵌套对象**：`default_attrs`、`prices`、`productSites` 使用 ObjectField 存储数组结构
3. **多值字段**：`tags`、`sub_images`、`package_id` 等使用 `multiple: true`
4. **价格计算**：价格相关字段使用 FloatField，支持范围过滤和排序
5. **时间字段**：所有时间字段使用 DateTimeField，支持时间范围过滤
6. **状态字段**：状态类字段使用 IntegerField 或 BooleanField，支持分面统计
