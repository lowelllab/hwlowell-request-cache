<?php

use CmsIg\Seal\Schema\Field\TextField;
use CmsIg\Seal\Schema\Field\IntegerField;
use CmsIg\Seal\Schema\Field\FloatField;
use CmsIg\Seal\Schema\Field\BooleanField;
use CmsIg\Seal\Schema\Field\DateTimeField;
use CmsIg\Seal\Schema\Field\IdentifierField;
use CmsIg\Seal\Schema\Field\ObjectField;
use CmsIg\Seal\Schema\Field\TypedField;
use CmsIg\Seal\Schema\Index;

return new Index('products', [
    //标识符字段
    'id' => new IdentifierField('id'),
    //基础字段
    'pdms_id' => new IntegerField('pdms_id'),
    'uniqid' => new TextField('uniqid'),
    'parent_uniqid' => new TextField('parent_uniqid'),
    'basic_dept_id' => new IntegerField('basic_dept_id'),
    'category_id' => new IntegerField('category_id'),
    'category_path_cn' => new TextField('category_path_cn'),
    'category_path_en' => new TextField('category_path_en'),
    'name_cn' => new TextField('name_cn'),
    'name_en' => new TextField('name_en'),
    'unit' => new TextField('unit'),
    'unit_cn' => new TextField('unit_cn'),
    'main_image' => new TextField('main_image'),
    'sub_images' => new TextField('sub_images', true),
    'bottom_price' => new FloatField('bottom_price'),
    'bottom_tax_price' => new FloatField('bottom_tax_price'),
    'min_price' => new FloatField('min_price', sortable: true),
    'max_price' => new FloatField('max_price'),
    'price' => new TextField('price'),
    'status' => new IntegerField('status'),
    'is_showInStore' => new BooleanField('is_showInStore'),
    'is_hot' => new BooleanField('is_hot'),
    'viewed' => new IntegerField('viewed'),
    'sales' => new IntegerField('sales'),
    'comment_score' => new FloatField('comment_score'),
    'sort_order' => new IntegerField('sort_order'),
    'created_at' => new DateTimeField('created_at'),
    'updated_at' => new DateTimeField('updated_at'),
    //复杂字段
    'default_attrs' => new ObjectField('default_attrs', [
        'name_cn' => new TextField('name_cn'),
        'name_en' => new TextField('name_en'),
        'value_cn' => new TextField('value_cn'),
        'value_en' => new TextField('value_en'),
    ], true),
    'prices' => new ObjectField('prices', [
        'model' => new TextField('model'),
        'index' => new TextField('index'),
        'cost' => new FloatField('cost'),
        'cost_max' => new FloatField('cost_max'),
        'profit' => new FloatField('profit'),
        'discount' => new FloatField('discount'),
        'tax' => new FloatField('tax'),
        'cost_tax' => new FloatField('cost_tax'),
        'sale_price_tax' => new FloatField('sale_price_tax'),
        'estimated_price' => new FloatField('estimated_price'),
        'estimated_price_tax' => new FloatField('estimated_price_tax'),
        'estimated_price_range' => new TextField('estimated_price_range'),
        'estimated_price_range_min' => new FloatField('estimated_price_range_min'),
        'estimated_price_range_max' => new FloatField('estimated_price_range_max'),
        'recent_price' => new FloatField('recent_price'),
        'status' => new IntegerField('status'),
        'image' => new TextField('image'),
        'is_change' => new IntegerField('is_change'),
        'salesPrice' => new FloatField('salesPrice'),
        'salesPriceMax' => new FloatField('salesPriceMax'),
        'name_cn' => new TextField('name_cn'),
        'name_en' => new TextField('name_en'),
    ], true),

    'productSites' => new ObjectField('productSites', [
        'created_at' => new DateTimeField('created_at'),
        'updated_at' => new DateTimeField('updated_at'),
        'product_id' => new IntegerField('product_id'),
        'company_id' => new IntegerField('company_id'),
        'shop_category_id' => new IntegerField('shop_category_id'),
        'sort_order' => new IntegerField('sort_order'),
        'recycle' => new IntegerField('recycle'),
    ], true),

    'desc_arr' => new ObjectField('desc_arr', [
        'key' => new TextField('key'),
        'value' => new TextField('value'),
    ], true),
]);
