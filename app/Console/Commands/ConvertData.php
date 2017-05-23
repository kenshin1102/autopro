<?php

namespace App\Console\Commands;

use App\Models\Item;
use App\Models\Size;
use Automattic\WooCommerce\Client;
use Cache;
use Config;
use Illuminate\Console\Command;

class ConvertData extends Command
{
    //https://woocommerce.github.io/woocommerce-rest-api-docs/v3.html?javascript#create-a-product
    const consumer_key = 'ck_468c6beedd1adb3c19fa56d86cc1673dba2348c7';
    const consumer_secret = 'cs_2e4ae77a81d42d14488ba34d0ba782fe7240a713';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'convert:data {domain} {key?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Convert database';

    /**
     * Create a new command instance.
     *
     * @return void
     */

    protected $client;

    protected $limit = 10;

    protected $offset = 20;

    protected $domain;

    protected $tags;

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->client = new Client($this->argument('domain'), self::consumer_key, self::consumer_secret, []);
        $key = $this->argument('key');
        try {
            set_time_limit(0);
            $this->config();
            $this->convertCategories();
            $this->createProductAttribute();
            $this->createProductTag();
            $this->convertProduct($key);
        } catch (\Exception $e) {
            \Log::alert($e);
        }
    }

    private function config()
    {
        //TODO set api key, change url wordpress
    }

    private function convertCategories()
    {
        try {
            foreach (Config::get('convert.categories') as $category) {
                $this->client->post('products/categories', [
                    'product_category' => [
                        'name' => $category
                    ]
                ]);
            }
        } catch (\Exception $e) {
            \Log::alert($e);
        }
    }

    private function convertProduct($key)
    {
        $categories = $this->getAllCategories();
        foreach ($categories['product_categories'] as $category) {
            $items = Item::where('cate', 'LIKE', '%' . $category['name'] . '%')
                ->offset(100)
                ->with('details', 'details.color', 'details.style');
            if ($key) {
                $items = $items->where('keyword', 'like', '%' . $key . '%');
            }

            $items = $items->limit($this->limit)->inRandomOrder()->get();

            foreach ($items as $item) {
                $convertData = $this->createProductAttributes($item->details, $item->link);
                $data = [
                    'product' => [
                        'title' => $item->title,
                        'sku' => str_slug($item->title),
                        'type' => 'variable',
                        'description' => $item->description,
                        'short_description' => $item->keyword,
                        'categories' => [$category['id']],
                        'images' => $convertData['images'],
                        'attributes' => $convertData['attributes'],
                        'variations' => $convertData['variations'],
                        'tags' => $this->tags,
                        'managing_stock' => true,
                        'stock_quantity' => rand(100, 1000),
                        'in_stock' => true,
                    ]
                ];

                try {
                    $product = $this->getProduct(str_slug($item->title));
                    if ($product) {
                        unset($data['images']);
                        unset($data['variations']);
                        $this->client->put('products/' . $product[0]['id'], $data);
                    } else {
                        $this->client->post('products', $data);
                    }
                } catch (\Exception $e) {
                    \Log::alert($e);
                }
            }
        }
    }

    private function createProductAttribute()
    {
        try {
            foreach (Config::get('convert.attribute') as $key => $value) {
                $this->client->post('products/attributes', [
                    'product_attribute' => [
                        'name' => $value,
                        'slug' => $key,
                        'type' => 'select',
                        'order_by' => 'menu_order',
                        'has_archives' => true
                    ]
                ]);
            }
        } catch (\Exception $e) {
            \Log::alert($e);
        }
    }

    private function getAllCategories()
    {
        if (Cache::has('categories')) {
            return Cache::get('categories');
        }

        $categories = $this->client->get('products/categories');
        Cache::put('categories', $categories, 100);

        return $categories;
    }

    private function getAllSizes($id)
    {
        if (Cache::has('sizes')) {
            $sizes = Cache::get('sizes');
        } else {
            $sizes = Size::pluck('sizeName')->toArray();
            Cache::put('sizes', $sizes, 100);
        }

        if ($id === 'full') {
            return $sizes;
        }

        return $sizes[$id] ?? 'S';
    }

    private function createProductAttributes($variations, $link)
    {
        $color = [];
        $style = [];
        $arr = [];

        foreach ($variations as $key => $variation) {
            $color[] = $variation->color->name ?? 'Black';
            $style[] = $variation->style->styleName ?? 'Guys Tee';
            $arr['variations'][] = $this->getVariations($variation, $key);
            if ($key == 0) {
                $link = $variation->link;
            }
            if ($key <= 4) {
                $arr['images'][$key]['src'] = str_replace('//', '', $variation->img);
                $arr['images'][$key]['position'] = $key;
            }
        }

        $size = $this->getAllSizes('full');
        $arr['attributes'] = $this->getAttr($link, $color, $size, $style);

        return $arr;
    }

    private function getVariations($variation, $position)
    {
        return [
            'regular_price' => $variation->price,
            'attributes' => [
                [
                    'name' => 'Color',
                    'slug' => 'color',
                    'option' => $variation->color->name ?? 'Black'
                ],
                [
                    'name' => 'Size',
                    'slug' => 'size',
                    'option' => $this->getAllSizes($position)
                ],
                [
                    'name' => 'Style',
                    'slug' => 'style',
                    'option' => $variation->style->styleName ?? 'Guys Tee'
                ]
            ]
        ];
    }

    private function getAttr($link, $color, $size, $style)
    {
        return [
            [
                'name' => 'link',
                'slug' => 'link',
                'position' => '0',
                'visible' => false,
                'variation' => false,
                'options' => [
                    $link,
                ]
            ],
            [
                'name' => 'Color',
                'slug' => 'color',
                'position' => '3',
                'visible' => true,
                'variation' => true,
                'options' => $color,
            ],
            [
                'name' => 'Size',
                'slug' => 'size',
                'position' => '2',
                'visible' => true,
                'variation' => true,
                'options' => $size,
            ],
            [
                'name' => 'Style',
                'slug' => 'style',
                'position' => '0',
                'visible' => true,
                'variation' => true,
                'options' => $style,
            ],
        ];
    }

    public function getProduct($slug)
    {
        $products = $this->client->get('products', [
            'filter[sku]' => $slug,
            'fields' => 'id, sku',
        ]);

        if (count($products['products']) == 1) {
            return $products['products'];
        }

        return false;
    }

    public function createProductTag()
    {
        $arrTags = [];
        try {
            foreach (Config::get('convert.tags') as $key => $value) {
                $this->client->post('products/tags', [
                    'product_tag' => [
                        'name' => $value,
                    ]
                ]);
            }
        } catch (\Exception $e) {
            \Log::alert($e);
        }

        $tags = $this->client->get('products/tags');
        foreach ($tags['product_tags'] as $tag) {
            $arrTags[] = $tag['id'];
        }
        $this->tags = $arrTags;
    }
}
