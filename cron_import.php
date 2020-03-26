<?php
set_time_limit(0);
ini_set('memory_limit', '2G');

/** Set up WordPress environment */
require_once(__DIR__ . '/../../../wp-load.php');

define('OASIS_MI_PATH', plugin_dir_path(__FILE__));
define('OASIS_MI_PREFIX', 'oasis_mi');
define('OASIS_MI_VERSION', '1.0');

echo '[' . date('c') . '] Начало обновления товаров' . PHP_EOL;

include_once(OASIS_MI_PATH . 'functions.php');

$options = get_option('oasis_mi_options');
$api_key = $options['oasis_mi_api_key'];
$selectedCategories = array_filter($options['oasis_mi_category_map']);

$oasisCategories = get_oasis_categories($api_key);

if ($api_key && $selectedCategories) {
    foreach (array_values($selectedCategories) as $oasisCategory) {
        $params = [
            'format'   => 'json',
            'fieldset' => 'full',
            'category' => $oasisCategory,
            'no_vat'   => 0,
            'extend'   => 'is_visible',
            'key'      => $api_key,
        ];

        $products = json_decode(
            file_get_contents('https://api.oasiscatalog.com/v4/products?' . http_build_query($params)),
            true
        );

        $models = [];
        foreach ($products as $product) {
            $models[$product['group_id']][$product['id']] = $product;
        }

        foreach ($models as $model_id => $model) {
            echo '[' . date('c') . '] Начало обработки модели ' . $model_id . PHP_EOL;
            $selectedCategory = [];

            $firstProduct = reset($model);
            foreach ($selectedCategories as $k => $v) {
                if (in_array($v, $firstProduct['categories_array'])) {
                    $selectedCategory[] = $k;
                }
            }
            if (empty($selectedCategory)) {
                foreach ($selectedCategories as $k => $v) {
                    $selectedCategory = recursiveCheckCategories($k, $v, $oasisCategories, $firstProduct['categories_array']);
                }
            }

            upsert_model($model_id, $model, $selectedCategory, true);
        }
    }
}


echo '[' . date('c') . '] Окончание обновления товаров' . PHP_EOL;

