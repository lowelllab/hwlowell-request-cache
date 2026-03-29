<?php

namespace Controller;

use App\Http\Controllers\Controller;
use CmsIg\Seal\Search\Condition\Condition;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RequestCacheTestController extends Controller
{
    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     * 此为示例，不可直接运行
     *
     */

    public function productSearch(Request $request)
    {
        $dataArr = $request->all();
        //默认数据
        if (empty($dataArr)) {
            $dataArr = [
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
                        'cost_max' => 0,
                        'profit' => 300,
                        'discount' => 0,
                        'tax' => 0,
                        'cost_tax' => 0,
                        'sale_price_tax' => 0,
                        'estimated_price' => 0,
                        'estimated_price_tax' => 0,
                        'estimated_price_range' => null,
                        'estimated_price_range_min' => 0,
                        'estimated_price_range_max' => 0,
                        'recent_price' => 0,
                        'status' => 1,
                        'image' => null,
                        'is_change' => 0,
                        'salesPrice' => 1251,
                        'salesPriceMax' => 0,
                        'name_cn' => null,
                        'name_en' => null
                    ]
                ],
                'productSites' => [
                    [
                        'created_at' => '2025-10-09 17:17:44',
                        'updated_at' => '2025-10-09 17:17:44',
                        'product_id' => 12530,
                        'company_id' => 1,
                        'shop_category_id' => 1275,
                        'sort_order' => 999,
                        'recycle' => 0
                    ]
                ],
                'desc_arr' => []
            ];
        }
        $result = $this->testSealRediSearch($dataArr);
        return response()->json([$result]);
    }

    private function testSealRediSearch(array $productData)
    {
        $engine = app('CmsIg\Seal\EngineInterface');
        try {
            $descArr = [];
            $document = [
                'id' => (string) $productData['id'],
                'pdms_id' => $productData['pdms_id'],
                'uniqid' => $productData['uniqid'],
                'parent_uniqid' => $productData['parent_uniqid'],
                'basic_dept_id' => $productData['basic_dept_id'],
                'category_id' => $productData['category_id'],
                'category_path_cn' => $productData['category_path_cn'],
                'category_path_en' => $productData['category_path_en'],
                'name_cn' => $productData['name_cn'],
                'name_en' => $productData['name_en'],
                'unit' => $productData['unit'],
                'unit_cn' => $productData['unit_cn'],
                'main_image' => $productData['main_image'],
                'sub_images' => $productData['sub_images'] ?? [],
                'bottom_price' => (float) $productData['bottom_price'],
                'bottom_tax_price' => (float) $productData['bottom_tax_price'],
                'min_price' => (float) $productData['min_price'],
                'max_price' => (float) $productData['max_price'],
                'price' => $productData['price'],
                'status' => $productData['status'],
                'is_showInStore' => (bool) $productData['is_showInStore'],
                'is_hot' => (bool) $productData['is_hot'],
                'viewed' => $productData['viewed'],
                'sales' => $productData['sales'],
                'comment_score' => (float) $productData['comment_score'],
                'sort_order' => $productData['sort_order'],
                'created_at' => $productData['created_at'],
                'updated_at' => $productData['updated_at'],
                'default_attrs' => $productData['default_attrs'] ?? [],
                'prices' => $productData['prices'] ?? [],
                'productSites' => $productData['productSites'] ?? [],
                'desc_arr' => $descArr,
            ];
            //Log::info('查看数据',$document);
            //print_r(json_encode($document));die;
            //如果不存在先创建索引
            if (!$engine->existIndex('products')) {
                $task = $engine->createIndex('products');
                if ($task !== null) {
                    $task->wait();
                }
            }
            //保存文档到索引
            $task = $engine->saveDocument('products', $document);
            //如果有返回任务，等待完成
            if ($task !== null) {
                $task->wait();
            }

            $saveResult = [
                'success' => true,
                'message' => '文档添加成功',
                'document_id' => $document['id'],
            ];
        } catch (\Exception $e) {
            $saveResult = [
                'success' => false,
                'message' => '文档添加失败: ' . $e->getMessage(),
            ];
        }
        try {
            // 创建搜索查询
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
            //格式化搜索结果
            $hits = [];
            foreach ($searchResult as $hit) {
                $hits[] = [$hit];
            }

            $searchResultData = [
                'success' => true,
                'total' => $searchResult->total(),
                'hits' => $hits,
            ];
        } catch (\Exception $e) {
            Log::error('搜索失败: ' . $e->getMessage());
            $searchResultData = [
                'success' => false,
                'message' => '搜索失败: ' . $e->getMessage(),
            ];
        }
        return [
            'code' => 200,
            'message' => '操作完成',
            'data' => [
                'save_result' => $saveResult,
                'search_result' => $searchResultData,
            ],
        ];
    }
}
