# CMS-IG SEAL 字段类型完整指南

## 目录结构

字段类型文件位于 `vendor/cmsig/seal/src/Schema/Field/` 目录下，按文件名字母顺序排列：

1. AbstractField.php - 抽象基类
2. BooleanField.php - 布尔值字段
3. DateTimeField.php - 日期时间字段
4. FloatField.php - 浮点数字段
5. GeoPointField.php - 地理位置字段
6. IdentifierField.php - 唯一标识字段
7. IntegerField.php - 整数字段
8. JsonObjectField.php - JSON对象字段
9. ObjectField.php - 嵌套对象字段
10. TextField.php - 文本字段
11. TypedField.php - 多类型字段

---

## 1. AbstractField（抽象基类）

### 说明
所有字段类型的抽象基类，定义了通用的字段属性。

### 属性定义
```php
public readonly string $name;      // 字段名称
public readonly bool $multiple;    // 是否允许多值
public readonly bool $searchable;  // 是否可全文搜索
public readonly bool $filterable;  // 是否可过滤
public readonly bool $sortable;    // 是否可排序
public readonly bool $distinct;    // 是否用于去重
public readonly bool $facet;       // 是否用于分面
public readonly array $options;    // 额外选项
```

---

## 2. BooleanField（布尔值字段）

### 说明
用于存储 true 或 false 标志。

### 构造函数参数
```php
public function __construct(
    string $name,                    // 必填：字段名称
    bool $multiple = false,          // 是否多值
    bool $searchable = false,        // 是否可搜索（强制false，设为true会抛异常）
    bool $filterable = false,        // 是否可过滤
    bool $sortable = false,          // 是否可排序
    bool $distinct = false,          // 是否用于去重
    bool $facet = false,             // 是否用于分面
    array $options = [],             // 额外选项
)
```

### 示例数据结构
```php
// 单值布尔
['is_active' => true]

// 多值布尔
['features' => [true, false, true]]
```

### 示例用法
```php
// 基础用法 - 用户状态
$isActive = new BooleanField('is_active');

// 常用配置 - 可过滤的状态字段
$isPublished = new BooleanField(
    name: 'is_published',
    filterable: true,   // 可按发布状态过滤
    facet: true,        // 可统计已发布/未发布数量
);

// 多值布尔
$permissions = new BooleanField(
    name: 'permissions',
    multiple: true,
    filterable: true,
);
```

### 具体应用场景
| 场景 | 字段名 | 配置建议 |
|------|--------|----------|
| 用户状态 | `is_active`, `is_verified` | `filterable: true` |
| 内容状态 | `is_published`, `is_featured` | `filterable: true, facet: true` |
| 产品属性 | `is_in_stock`, `is_on_sale` | `filterable: true` |
| 权限标志 | `can_edit`, `can_delete` | `filterable: true` |

---

## 3. DateTimeField（日期时间字段）

### 说明
用于存储日期和日期时间。

### 构造函数参数
```php
public function __construct(
    string $name,                    // 必填：字段名称
    bool $multiple = false,          // 是否多值
    bool $searchable = false,        // 是否可搜索（强制false，设为true会抛异常）
    bool $filterable = false,        // 是否可过滤
    bool $sortable = false,          // 是否可排序
    bool $distinct = false,          // 是否用于去重
    bool $facet = false,             // 是否用于分面
    array $options = [],             // 额外选项
)
```

### 示例数据结构
```php
// 单值日期时间
['created_at' => '2024-01-15 10:30:00']

// 多值日期时间
['event_dates' => ['2024-01-15', '2024-01-20', '2024-01-25']]
```

### 示例用法
```php
// 基础用法 - 创建时间
$createdAt = new DateTimeField('created_at');

// 常用配置 - 支持过滤、排序和分面
$publishedAt = new DateTimeField(
    name: 'published_at',
    filterable: true,   // 可按日期范围过滤（如最近7天）
    sortable: true,     // 可按时间排序（最新/最早）
    facet: true,        // 可按时间分面（如按月份统计）
);

// 多值日期 - 活动时间
$eventDates = new DateTimeField(
    name: 'event_dates',
    multiple: true,
    filterable: true,
);
```

### 具体应用场景
| 场景 | 字段名 | 配置建议 |
|------|--------|----------|
| 创建时间 | `created_at` | `filterable: true, sortable: true` |
| 更新时间 | `updated_at` | `sortable: true` |
| 发布时间 | `published_at` | `filterable: true, sortable: true, facet: true` |
| 活动时间 | `start_date`, `end_date` | `filterable: true` |
| 过期时间 | `expires_at` | `filterable: true` |

---

## 4. FloatField（浮点数字段）

### 说明
用于存储任何 PHP 浮点数值。

### 构造函数参数
```php
public function __construct(
    string $name,                    // 必填：字段名称
    bool $multiple = false,          // 是否多值
    bool $searchable = false,        // 是否可搜索（强制false，设为true会抛异常）
    bool $filterable = false,        // 是否可过滤
    bool $sortable = false,          // 是否可排序
    bool $distinct = false,          // 是否用于去重
    bool $facet = false,             // 是否用于分面
    array $options = [],             // 额外选项
)
```

### 示例数据结构
```php
// 单值浮点数
['rating' => 4.5]

// 多值浮点数
['coordinates' => [39.9042, 116.4074]]
```

### 示例用法
```php
// 基础用法 - 评分
$rating = new FloatField('rating');

// 常用配置 - 商品评分
$productRating = new FloatField(
    name: 'rating',
    filterable: true,   // 可按评分范围过滤（如4.5分以上）
    sortable: true,     // 可按评分排序
    facet: true,        // 可按评分区间统计
);

// 价格（需要小数精度时）
$price = new FloatField(
    name: 'price',
    filterable: true,
    sortable: true,
);
```

### 具体应用场景
| 场景 | 字段名 | 配置建议 |
|------|--------|----------|
| 评分系统 | `rating`, `score` | `filterable: true, sortable: true, facet: true` |
| 价格（小数） | `price` | `filterable: true, sortable: true` |
| 地理坐标 | `latitude`, `longitude` | `filterable: true` |
| 百分比 | `discount_rate`, `tax_rate` | `filterable: true` |
| 重量/尺寸 | `weight`, `height` | `filterable: true, sortable: true` |

---

## 5. GeoPointField（地理位置字段）

### 说明
用于存储带纬度和经度的地理坐标。

### 重要限制
- 每个索引只能有一个 GeoPointField
- 不支持 `multiple`、`searchable`、`facet`
- 纬度范围：-90 到 90
- 经度范围：-180 到 180

### 构造函数参数
```php
public function __construct(
    string $name,                    // 必填：字段名称
    bool $multiple = false,          // 强制false
    bool $searchable = false,        // 强制false
    bool $filterable = false,        // 是否可过滤
    bool $sortable = false,          // 是否可排序
    bool $distinct = false,          // 是否用于去重
    bool $facet = false,             // 强制false
    array $options = [],             // 额外选项
)
```

### 示例数据结构
```php
// 地理坐标
['location' => ['lat' => 39.9042, 'lon' => 116.4074]]
```

### 示例用法
```php
// 基础用法
$location = new GeoPointField('location');

// 常用配置 - 支持距离过滤和排序
$storeLocation = new GeoPointField(
    name: 'store_location',
    filterable: true,   // 可按距离范围过滤（如附近5公里）
    sortable: true,     // 可按距离排序（最近的优先）
);
```

### 具体应用场景
| 场景 | 字段名 | 配置建议 |
|------|--------|----------|
| 商家位置 | `location`, `store_location` | `filterable: true, sortable: true` |
| 用户位置 | `user_location` | `filterable: true` |
| 配送位置 | `delivery_location` | `filterable: true, sortable: true` |
| 房产位置 | `property_location` | `filterable: true, sortable: true` |

---

## 6. IdentifierField（唯一标识字段）

### 说明
用于存储文档的唯一标识符，每个索引只能有一个。

### 重要限制
- 固定配置，无法自定义参数
- 每个索引只能有一个 IdentifierField
- 建议值格式：字母、数字、连字符、下划线

### 构造函数参数
```php
public function __construct(string $name)  // 仅需字段名称
```

### 固定配置
```php
multiple: false
searchable: false
filterable: true    // 固定启用
sortable: true      // 固定启用
distinct: false
facet: false
options: []
```

### 示例数据结构
```php
// 标识符
['id' => 'user_12345']
['uuid' => '550e8400-e29b-41d4-a716-446655440000']
```

### 示例用法
```php
// 标准ID字段
$id = new IdentifierField('id');

// UUID字段
$uuid = new IdentifierField('uuid');
```

### 具体应用场景
| 场景 | 字段名 | 值格式示例 |
|------|--------|-----------|
| 数据库ID | `id` | `12345`, `user_001` |
| UUID | `uuid` | `550e8400-e29b-41d4-a716-446655440000` |
| 业务编码 | `sku`, `order_no` | `PROD-2024-001`, `ORD-123456` |

---

## 7. IntegerField（整数字段）

### 说明
用于存储任何 PHP 整数值。

### 构造函数参数
```php
public function __construct(
    string $name,                    // 必填：字段名称
    bool $multiple = false,          // 是否多值
    bool $searchable = false,        // 是否可搜索（强制false，设为true会抛异常）
    bool $filterable = false,        // 是否可过滤
    bool $sortable = false,          // 是否可排序
    bool $distinct = false,          // 是否用于去重
    bool $facet = false,             // 是否用于分面
    array $options = [],             // 额外选项
)
```

### 示例数据结构
```php
// 单值整数
['quantity' => 100]

// 多值整数
['scores' => [85, 90, 78]]
```

### 示例用法
```php
// 基础用法 - 数量
$quantity = new IntegerField('quantity');

// 常用配置 - 支持过滤、排序和分面
$age = new IntegerField(
    name: 'age',
    filterable: true,   // 可按年龄范围过滤
    sortable: true,     // 可按年龄排序
    facet: true,        // 可按年龄段统计
);

// 价格（以分为单位，避免浮点精度问题）
$priceCents = new IntegerField(
    name: 'price_cents',
    filterable: true,
    sortable: true,
);
```

### 具体应用场景
| 场景 | 字段名 | 配置建议 |
|------|--------|----------|
| 数量 | `quantity`, `stock` | `filterable: true, sortable: true` |
| 年龄 | `age` | `filterable: true, sortable: true, facet: true` |
| 价格（分） | `price_cents` | `filterable: true, sortable: true` |
| 页数 | `page_count` | `filterable: true, sortable: true` |
| 年份 | `year` | `filterable: true, sortable: true, facet: true` |

---

## 8. JsonObjectField（JSON对象字段）

### 说明
用于存储非结构化的 JSON 对象（实验性功能）。

### 重要限制
- 实验性功能，API 可能随时变更
- 所有功能参数固定为 `false`
- 不支持搜索、过滤、排序、分面

### 构造函数参数
```php
public function __construct(
    string $name,                    // 必填：字段名称
    bool $multiple = false,          // 强制false
    array $options = [],             // 额外选项
)
```

### 固定配置
```php
multiple: false
searchable: false
filterable: false
sortable: false
distinct: false
facet: false
```

### 示例数据结构
```php
// 非结构化元数据
['metadata' => [
    'source' => 'import',
    'imported_at' => '2024-01-15',
    'raw_data' => [...],
    'custom_fields' => [...]
]]
```

### 示例用法
```php
// 存储额外元数据
$metadata = new JsonObjectField('metadata');

// 存储原始数据
$rawData = new JsonObjectField('raw_data');
```

### 具体应用场景
| 场景 | 字段名 | 说明 |
|------|--------|------|
| 额外元数据 | `metadata` | 存储不常查询的附加信息 |
| 原始数据 | `raw_data` | 存储导入的原始数据 |
| 动态属性 | `custom_attributes` | 存储用户自定义属性 |
| 配置信息 | `config` | 存储配置数据 |

---

## 9. ObjectField（嵌套对象字段）

### 说明
用于在嵌套对象中存储字段。

### 构造函数参数
```php
public function __construct(
    string $name,                    // 必填：字段名称
    public readonly array $fields,   // 必填：子字段定义
    bool $multiple = false,          // 是否多值
    array $options = [],             // 额外选项
)
```

### 动态配置
ObjectField 的配置会根据子字段自动计算：
- 如果任意子字段 `searchable: true`，则 ObjectField `searchable: true`
- 如果任意子字段 `filterable: true`，则 ObjectField `filterable: true`
- 如果任意子字段 `sortable: true`，则 ObjectField `sortable: true`
- 如果任意子字段 `distinct: true`，则 ObjectField `distinct: true`
- 如果任意子字段 `facet: true`，则 ObjectField `facet: true`

### 示例数据结构
```php
// 单值对象
['address' => [
    'street' => '123 Main St',
    'city' => 'Beijing',
    'zip' => 100000
]]

// 多值对象
['addresses' => [
    ['street' => '123 Main St', 'city' => 'Beijing'],
    ['street' => '456 Park Ave', 'city' => 'Shanghai']
]]
```

### 示例用法
```php
// 地址对象
$address = new ObjectField('address', [
    'street' => new TextField('street'),
    'city' => new TextField('city', filterable: true, facet: true),
    'zip' => new IntegerField('zip', filterable: true),
]);

// 多值地址
$addresses = new ObjectField('addresses', [
    'street' => new TextField('street'),
    'city' => new TextField('city', filterable: true),
    'zip' => new IntegerField('zip', filterable: true),
], multiple: true);

// 用户资料
$userProfile = new ObjectField('profile', [
    'first_name' => new TextField('first_name', searchable: true),
    'last_name' => new TextField('last_name', searchable: true),
    'bio' => new TextField('bio', searchable: true),
    'birth_date' => new DateTimeField('birth_date', filterable: true),
]);
```

### 具体应用场景
| 场景 | 字段名 | 子字段示例 |
|------|--------|-----------|
| 地址 | `address` | `street`, `city`, `zip`, `country` |
| 用户资料 | `profile` | `first_name`, `last_name`, `bio` |
| 产品规格 | `specs` | `weight`, `dimensions`, `material` |
| 联系信息 | `contact` | `email`, `phone`, `website` |

---

## 10. TextField（文本字段）

### 说明
用于存储任何文本，是唯一默认支持全文搜索的字段类型。

### 构造函数参数
```php
public function __construct(
    string $name,                    // 必填：字段名称
    bool $multiple = false,          // 是否多值
    bool $searchable = true,         // 是否可搜索（默认true）
    bool $filterable = false,        // 是否可过滤
    bool $sortable = false,          // 是否可排序
    bool $distinct = false,          // 是否用于去重
    bool $facet = false,             // 是否用于分面
    array $options = [],             // 额外选项
)
```

### 示例数据结构
```php
// 单值文本
['title' => 'Product Name']

// 多值文本
['tags' => ['electronics', 'smartphone', 'apple']]
```

### 示例用法
```php
// 基础用法 - 默认可搜索
$title = new TextField('title');

// 常用配置 - 支持搜索、过滤和分面
$category = new TextField(
    name: 'category',
    searchable: true,   // 可全文搜索
    filterable: true,   // 可精确过滤（如按分类筛选）
    facet: true,        // 可用于分面统计
);

// 多值文本 - 标签
$tags = new TextField(
    name: 'tags',
    multiple: true,     // 存储多个标签
    searchable: true,   // 可搜索标签
    filterable: true,   // 可按标签过滤
    facet: true,        // 可统计标签分布
);
```

### 具体应用场景
| 场景 | 字段名 | 配置建议 |
|------|--------|----------|
| 标题 | `title`, `name` | `searchable: true` |
| 描述 | `description`, `content` | `searchable: true` |
| 分类 | `category` | `searchable: true, filterable: true, facet: true` |
| 标签 | `tags` | `multiple: true, searchable: true, filterable: true, facet: true` |
| 作者 | `author` | `searchable: true, filterable: true, facet: true` |

---

## 11. TypedField（多类型字段）

### 说明
用于存储不同类型的数据结构，根据类型字段动态选择字段结构。

### 构造函数参数
```php
public function __construct(
    string $name,                    // 必填：字段名称
    public readonly string $typeField, // 必填：类型字段名称
    public readonly array $types,    // 必填：类型定义
    bool $multiple = false,          // 是否多值
    array $options = [],             // 额外选项
)
```

### 动态配置
TypedField 的配置会根据子字段自动计算（同 ObjectField）。

### 示例数据结构
```php
// 多类型数据
['media' => [
    [
        'type' => 'book',
        'title' => 'PHP Programming',
        'author' => 'John Doe',
        'pages' => 300
    ],
    [
        'type' => 'movie',
        'title' => 'The Matrix',
        'director' => 'Wachowskis',
        'duration' => 136
    ]
]]
```

### 示例用法
```php
// 多媒体内容
$media = new TypedField(
    name: 'media',
    typeField: 'type',  // 用于区分类型的字段
    types: [
        'book' => [
            'title' => new TextField('title', searchable: true),
            'author' => new TextField('author', searchable: true, filterable: true, facet: true),
            'pages' => new IntegerField('pages', filterable: true, sortable: true),
            'isbn' => new TextField('isbn', filterable: true),
        ],
        'movie' => [
            'title' => new TextField('title', searchable: true),
            'director' => new TextField('director', searchable: true, filterable: true),
            'duration' => new IntegerField('duration', filterable: true, sortable: true),
            'rating' => new FloatField('rating', filterable: true, sortable: true),
        ],
    ],
    multiple: true,  // 可以存储多个媒体项目
);

// 产品目录（不同类型的产品）
$products = new TypedField(
    name: 'products',
    typeField: 'product_type',
    types: [
        'electronics' => [
            'brand' => new TextField('brand', filterable: true, facet: true),
            'model' => new TextField('model', searchable: true),
            'warranty_months' => new IntegerField('warranty_months'),
        ],
        'clothing' => [
            'brand' => new TextField('brand', filterable: true, facet: true),
            'size' => new TextField('size', filterable: true, facet: true),
            'color' => new TextField('color', filterable: true, facet: true),
            'material' => new TextField('material', filterable: true),
        ],
    ],
);
```

### 具体应用场景
| 场景 | 字段名 | 类型示例 |
|------|--------|----------|
| 多媒体库 | `media` | `book`, `movie`, `music`, `game` |
| 产品目录 | `products` | `electronics`, `clothing`, `food` |
| 活动日程 | `events` | `meeting`, `conference`, `webinar` |
| 文档库 | `documents` | `pdf`, `doc`, `image`, `video` |

---

## 字段类型快速选择指南

### 根据数据类型选择

| 数据类型 | 推荐字段类型 | 说明 |
|----------|-------------|------|
| 文本内容 | TextField | 唯一支持全文搜索 |
| 布尔值 | BooleanField | 状态标志 |
| 整数 | IntegerField | 计数、年龄、价格（分） |
| 小数 | FloatField | 评分、坐标、百分比 |
| 日期时间 | DateTimeField | 时间戳、日期范围 |
| 地理位置 | GeoPointField | 经纬度坐标 |
| 唯一标识 | IdentifierField | 每个索引一个 |
| 嵌套对象 | ObjectField | 结构化数据 |
| 多类型数据 | TypedField | 不同类型不同结构 |
| 非结构化数据 | JsonObjectField | 实验性功能 |

### 根据功能需求选择

| 功能需求 | 适用字段类型 |
|----------|-------------|
| 全文搜索 | TextField |
| 精确过滤 | 所有字段（设置 filterable: true） |
| 范围过滤 | IntegerField, FloatField, DateTimeField |
| 距离过滤 | GeoPointField |
| 排序 | 所有字段（设置 sortable: true） |
| 分面统计 | 所有字段（设置 facet: true） |
| 多值存储 | 所有字段（设置 multiple: true，GeoPointField 除外） |

---

## 最佳实践

1. **选择合适的字段类型**：根据数据类型选择最匹配的字段类型
2. **只启用必要功能**：避免启用不需要的特性，提高性能
3. **注意字段限制**：了解每个字段类型的特殊限制
4. **保持命名一致性**：使用清晰、一致的字段命名规范
5. **合理使用复合结构**：对于复杂数据，使用 ObjectField 或 TypedField
6. **谨慎使用实验性功能**：JsonObjectField 仍处于实验阶段
7. **考虑跨引擎兼容性**：特别是 IdentifierField 的值格式
8. **测试配置**：确保字段配置符合搜索引擎要求

---

## 参数默认值汇总

| 参数 | TextField | BooleanField | IntegerField | FloatField | DateTimeField | GeoPointField | IdentifierField | TypedField | ObjectField | JsonObjectField |
|------|-----------|--------------|--------------|------------|---------------|---------------|-----------------|------------|------------|----------------|
| `multiple` | `false` | `false` | `false` | `false` | `false` | `false` (强制) | `false` (强制) | `false` | `false` | `false` (强制) |
| `searchable` | `true` | `false` (强制) | `false` (强制) | `false` (强制) | `false` (强制) | `false` (强制) | `false` (强制) | 动态计算 | 动态计算 | `false` (强制) |
| `filterable` | `false` | `false` | `false` | `false` | `false` | `false` | `true` (强制) | 动态计算 | 动态计算 | `false` (强制) |
| `sortable` | `false` | `false` | `false` | `false` | `false` | `false` | `true` (强制) | 动态计算 | 动态计算 | `false` (强制) |
| `distinct` | `false` | `false` | `false` | `false` | `false` | `false` | `false` (强制) | 动态计算 | 动态计算 | `false` (强制) |
| `facet` | `false` | `false` | `false` | `false` | `false` | `false` (强制) | `false` (强制) | 动态计算 | 动态计算 | `false` (强制) |
| `options` | `[]` | `[]` | `[]` | `[]` | `[]` | `[]` | `[]` (强制) | `[]` | `[]` | `[]` |
