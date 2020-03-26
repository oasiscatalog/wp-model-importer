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
            $result .= '<option value="' . $cat['id'] . '" ' . ($seleted == $cat['id'] ? 'selected' : '') . '>' . str_repeat('-',
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
        ?>
        <div class="wrap">
            <h1><?= esc_html('Настройка импорта моделей Oasis'); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('oasis_mi');
                do_settings_sections('oasis_mi');
                submit_button('Сохранить настроки');
                ?>
            </form>
        </div>
        <?php
    }
}