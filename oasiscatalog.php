<?php
/*
Plugin Name: Oasiscatalog - Model Importer
Plugin URI: https://forum.oasiscatalog.com
Description: Импорт моделей товаров из каталога oasiscatalog.com
Version: 1.0.1
Author: Oasiscatalog Team (Krasilnikov Andrey)
Author URI: https://forum.oasiscatalog.com
License: GPL2

WC requires at least: 2.3
WC tested up to: 3.1
*/

if (!defined('ABSPATH')) {
    exit;
}

define('OASIS_MI_PATH', plugin_dir_path(__FILE__));

/**
 * Проверка на наличие включенного Woocommerce при активации плагина
 */
register_activation_hook(__FILE__, 'oasis_mi_activate');

function oasis_mi_activate()
{
    if (!is_plugin_active('woocommerce/woocommerce.php') and current_user_can('activate_plugins')) {
        wp_die('Плагин Oasiscatalog - Model Importer не может работать без Woocommerce <br><a href="' . admin_url('plugins.php') . '">&laquo; Вернуться на страницу плагинов</a>');
    }
}

/**
 * custom option and settings
 */
function oasis_mi_settings_init()
{
    register_setting('oasis_mi', 'oasis_mi_options');

    add_settings_section(
        'oasis_mi_section_developers',
        'Настройка импорта моделей Oasis',
        null,
        'oasis_mi'
    );

    add_settings_field(
        'oasis_mi_api_key',
        'Ключ API',
        'oasis_mi_api_key_cb',
        'oasis_mi',
        'oasis_mi_section_developers',
        [
            'label_for' => 'oasis_mi_api_key',
        ]
    );

    add_settings_field(
        'oasis_mi_category_map',
        'Сопоставления категорий',
        'oasis_mi_category_map_cb',
        'oasis_mi',
        'oasis_mi_section_developers',
        [
            'label_for' => 'oasis_mi_category_map',
        ]
    );

}

function oasis_mi_api_key_cb($args)
{
    $options = get_option('oasis_mi_options');
    ?>

    <input type="text" name="oasis_mi_options[<?php echo esc_attr($args['label_for']); ?>]"
           value="<?php echo isset($options[$args['label_for']]) ? $options[$args['label_for']] : ''; ?>"
           maxlength="255" style="width: 300px;"/>

    <p class="description">После указания ключа можно будет сопоставить Ваш каталог с каталогом сайта Oasis</p>
    <?php
}

function oasis_mi_category_map_cb($args)
{
    $options = get_option('oasis_mi_options');
    if (empty($options['oasis_mi_api_key'])) {
        echo '<p class="description">Укажите ключ!</p>';
        return;
    }

    $oasisCategories = get_oasis_categories($options['oasis_mi_api_key']);

    echo '<table class="wp-list-table widefat fixed striped tags ui-sortable">';
    oasis_mi_recursive_category(0, 1, $args, $options, $oasisCategories);
    echo '</table>';
}

function oasis_mi_recursive_category($parent_id, $level, $args, $options, $oasisCategories)
{
    $wp_categories = get_categories([
        'taxonomy'   => 'product_cat',
        'hide_empty' => false,
        'parent'     => $parent_id,
        'orderby'    => 'name',
    ]);
    foreach ($wp_categories as $wp_category) {
        echo '<tr><td style="padding-left: ' . ($level * 10) . 'px;">' . ($level > 1 ? '- ' : '') . $wp_category->name . ' (#' . $wp_category->term_id . ')</td><td><select name="oasis_mi_options[' . esc_attr($args['label_for']) . '][' . $wp_category->term_id . ']">' .
            get_oasis_categories_tree(
                $oasisCategories,
                0, 0,
                (isset($options[$args['label_for']][$wp_category->term_id]) ? $options[$args['label_for']][$wp_category->term_id] : '')
            ) .
            '</select></td></tr>';
        oasis_mi_recursive_category($wp_category->term_id, $level + 1, $args, $options, $oasisCategories);
    }
}

function get_oasis_categories($key)
{
    $result = [];

    $availableRoots = [
        2891 => 'Продукция',
    ];

    $data = json_decode(file_get_contents('https://api.oasiscatalog.com/v4/categories?format=json&fields=id,parent_id,root,name&key=' . $key),
        true);

    if ($data) {
        foreach ($data as $row) {
            if (isset($availableRoots[$row['root']]) && !empty($row['parent_id'])) {
                $parent = (int)$row['parent_id'];
                if ($parent == 2891) {
                    $parent = 0;
                }
                $result[$parent][] = $row;
            }
        }
    }

    return $result;
}

function get_oasis_categories_tree($tree, $parent_id, $level, $seleted)
{
    $result = '';
    if ($level == 0) {
        $result .= '<option value="">Выберите рубрику Oasis</option>';
    }
    if (isset($tree[$parent_id])) {
        foreach ($tree[$parent_id] as $cat) {
            $result .= '<option value="' . $cat['id'] . '" ' . ($seleted == $cat['id'] ? 'selected' : '') . '>' . str_repeat(' - ',
                    $level) . $cat['name'] . '</option>';
            $result .= get_oasis_categories_tree($tree, $cat['id'], $level + 1, $seleted);
        }
    }
    return $result;
}


/**
 * register our wporg_settings_init to the admin_init action hook
 */
add_action('admin_init', 'oasis_mi_settings_init');

/**
 * Добавление пункта меню в раздел Инструменты для настройки импорта
 */
if (is_admin()) {

    function oasis_mi_menu()
    {
        add_submenu_page(
            'tools.php',
            'Импорт Oasis',
            'Импорт Oasis',
            'manage_options',
            'oasiscatalog_mi',
            'oasis_mi_page_html'
        );
    }

    add_action('admin_menu', 'oasis_mi_menu');

    function oasis_mi_page_html()
    {
        // check user capabilities
        if (!current_user_can('manage_options')) {
            return;
        }
        if (isset($_GET['settings-updated'])) {
            add_settings_error('oasis_mi_messages', 'oasis_mi_message', 'Настройки сохранены', 'updated');
        }

        // show error/update messages
        settings_errors('oasis_mi_messages');

        $options = get_option('oasis_mi_options');
        ?>
        <link href="https://cdn.jsdelivr.net/npm/select2@4.0.13/dist/css/select2.min.css" rel="stylesheet"/>
        <script src="https://cdn.jsdelivr.net/npm/select2@4.0.13/dist/js/select2.min.js"></script>

        <div class="wrap">
            <h1><?= esc_html('Настройка импорта моделей Oasis'); ?></h1>
            <?php if (!empty($options['oasis_mi_api_key'])) : ?>
            <p>Для включения автоматического обновления каталога необходимо в панели управления Хостингом добавить crontab задачу:<br/>
                <br/>
                <code style="border: dashed 1px #333; border-radius: 4px; padding: 10px 20px;">php <?=OASIS_MI_PATH;?>cron_import.php</code>
            </p>
            <br/>
            <?php endif; ?>

            <form action="options.php" method="post" class="oasis-mi-form">
                <?php
                settings_fields('oasis_mi');
                do_settings_sections('oasis_mi');
                submit_button('Сохранить настроки');
                ?>
            </form>
        </div>
        <script>
            jQuery(document).ready(function () {
                jQuery('.oasis-mi-form select').select2();
            });
        </script>
        <?php
    }

    /**
     * @param $actions
     * @param $post
     * @return mixed
     */
    function oasis_mi_update_link($actions, $post)
    {
        if ($post->post_type != 'product') {
            return $actions;
        }

        $post_status = (isset($_REQUEST['post_status']) ? $_REQUEST['post_status'] : false);
        if (!empty($post_status)) {
            if ($post_status == 'trash') {
                return $actions;
            }
        }

        $model_id = get_post_meta($post->ID, 'model_id');
        if (empty($model_id)) {
            return $actions;
        }

        $actions['oasis_update'] = '<span class="delete"><a href="' . wp_nonce_url(admin_url('edit.php?post_type=product&ids=' . $post->ID . '&action=oasis_update'),
                'oasis_update_' . $post->ID) . '" title="Обновить товар из Oasiscatalog" rel="permalink">Обновить модель Oasiscatalog</a></span>';

        return $actions;
    }

    add_filter('post_row_actions', 'oasis_mi_update_link', 10, 2);
    add_filter('page_row_actions', 'oasis_mi_update_link', 10, 2);

    /**
     *
     */
    function oasis_mi_update_action()
    {
        include_once(OASIS_MI_PATH . 'functions.php');

        if (empty($_REQUEST['ids'])) {
            wp_die('Не выбраны модели для обновления!');
        }

        // Get the original page
        $id = isset($_REQUEST['ids']) ? absint($_REQUEST['ids']) : '';

        $options = get_option('oasis_mi_options');
        $api_key = $options['oasis_mi_api_key'];
        $selectedCategories = array_filter($options['oasis_mi_category_map']);

        $sku = [];
        $sku[] = reset(get_post_meta($id, '_sku'));

        $variations = get_posts(['post_type' => 'product_variation', 'post_parent' => $id]);
        if ($variations) {
            foreach ($variations as $variation) {
                $sku[] = reset(get_post_meta($variation->ID, '_sku'));
            }
        }

        $params = [
            'format'   => 'json',
            'fieldset' => 'full',
            'articles' => implode(",", $sku),
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

        ob_start();
        foreach ($models as $model_id => $model) {
            $selectedCategory = [];

            $firstProduct = reset($model);
            foreach ($selectedCategories as $k => $v) {
                if (in_array($v, $firstProduct['categories_array']) || in_array($v, $firstProduct['full_categories'])) {
                    $selectedCategory[] = $k;
                }
            }

            upsert_model($model_id, $model, $selectedCategory, true, true);
        }

        $result = ob_get_contents();

        add_option('oasis_mi_update_message', nl2br($result));
        ob_end_clean();

        $post_type = 'product';
        $url = add_query_arg([
            'post_type' => $post_type,
        ], 'edit.php');
        wp_redirect($url);
        exit();
    }

    add_action('admin_action_oasis_update', 'oasis_mi_update_action');

    /**
     *
     */
    function oasis_mi_update_message()
    {
        $message = get_option('oasis_mi_update_message');
        if ($message) {
            delete_option('oasis_mi_update_message');
            ?>
            <div class="notice notice-success is-dismissible">
                <p><strong><?= $message; ?></strong></p>
            </div>
            <?php
        }
    }

    add_action('admin_notices', 'oasis_mi_update_message');
}