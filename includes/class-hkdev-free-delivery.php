<?php

if (!defined('ABSPATH')) {
    exit;
}

class HKDEV_Free_Delivery_Module {

    const SETTINGS_KEY = 'hkdev_fd_settings';

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        // Product meta
        add_action('woocommerce_product_options_general_product_data', array($this, 'add_product_meta_field'));
        add_action('woocommerce_admin_process_product_object',         array($this, 'save_product_meta'));

        // Category meta
        add_action('product_cat_add_form_fields',  array($this, 'add_category_meta_field'));
        add_action('product_cat_edit_form_fields', array($this, 'edit_category_meta_field'));
        add_action('created_product_cat',          array($this, 'save_category_meta'));
        add_action('edited_product_cat',           array($this, 'save_category_meta'));

        // Shipping engine
        add_filter('woocommerce_package_rates', array($this, 'process_shipping_logic'), 999, 2);

        // Frontend badge
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));

        // AJAX
        add_action('wp_ajax_hkdev_search_categories', array($this, 'ajax_search_categories'));
        add_action('wp_ajax_hkdev_save_fd_settings',  array($this, 'ajax_save_fd_settings'));
    }

    public static function get_setting($key, $default = '') {
        $options = get_option(self::SETTINGS_KEY, array());
        return isset($options[$key]) ? $options[$key] : $default;
    }

    /**
     * Core shipping logic — applies free delivery when triggers match.
     */
    public function process_shipping_logic($rates, $package) {
        if (!WC()->cart) {
            return $rates;
        }

        $settings = get_option(self::SETTINGS_KEY, array());
        $unlock   = false;

        // Trigger 1: Quantity
        if (!empty($settings['enable_qty'])) {
            $threshold = (int) ($settings['qty_threshold'] ?? 2);
            if (WC()->cart->get_cart_contents_count() >= $threshold) {
                $unlock = true;
            }
        }

        // Trigger 2: Specific products / Trigger 3: Categories
        if (!$unlock) {
            $allowed_prods      = (array) ($settings['products']    ?? array());
            $allowed_cats       = (array) ($settings['categories']  ?? array());
            $check_prod_enabled = !empty($settings['enable_products']);
            $check_cat_enabled  = !empty($settings['enable_cats']);

            foreach (WC()->cart->get_cart() as $item) {
                $p_id = $item['product_id'];
                $v_id = $item['variation_id'];

                if ($check_prod_enabled) {
                    if (in_array($p_id, $allowed_prods) || ($v_id && in_array($v_id, $allowed_prods))) {
                        $unlock = true;
                        break;
                    }
                    if (get_post_meta($p_id, '_hkdev_free_delivery', true) === 'yes') {
                        $unlock = true;
                        break;
                    }
                }

                if ($check_cat_enabled) {
                    $terms = get_the_terms($p_id, 'product_cat');
                    if ($terms && !is_wp_error($terms)) {
                        foreach ($terms as $term) {
                            if (in_array($term->term_id, $allowed_cats)) {
                                $unlock = true;
                                break 2;
                            }
                            if (get_term_meta($term->term_id, 'hkdev_free_delivery', true) === 'yes') {
                                $unlock = true;
                                break 2;
                            }
                        }
                    }
                }
            }
        }

        $label = $settings['label'] ?? 'Free Delivery';

        if ($unlock) {
            $free_id = 'hkdev_free_shipping';
            return array($free_id => new WC_Shipping_Rate($free_id, $label, 0, array(), 'hkdev_fd'));
        }

        return $rates;
    }

    public function enqueue_frontend_assets() {
        if (!is_cart() && !is_checkout()) {
            return;
        }

        $enabled = self::get_setting('enable_anim', 0);
        if (!$enabled) {
            return;
        }

        $label = esc_js(self::get_setting('label', 'Free Delivery'));

        wp_add_inline_style('woocommerce-general', '
            @keyframes hkdev-pulse {
                0%   { box-shadow: 0 0 0 0 rgba(34,197,94,.4); }
                70%  { box-shadow: 0 0 0 10px rgba(34,197,94,0); }
                100% { box-shadow: 0 0 0 0 rgba(34,197,94,0); }
            }
            .hkdev-free-badge {
                display: inline-block;
                padding: 4px 12px;
                background: #dcfce7;
                color: #166534;
                font-weight: 700;
                border-radius: 20px;
                font-size: .85em;
                margin-right: 8px;
                animation: hkdev-pulse 2s infinite;
                border: 1px solid #bbf7d0;
                vertical-align: middle;
            }
        ');

        wp_add_inline_script('jquery', "(function($){
            function applyBadge() {
                \$('ul#shipping_method li label').each(function(){
                    var t = \$(this).text();
                    if (t.indexOf('" . $label . "') !== -1 && !\$(this).find('.hkdev-free-badge').length) {
                        \$(this).html(\$(this).html().replace('" . $label . "', '<span class=\"hkdev-free-badge\">" . $label . "</span>'));
                    }
                });
            }
            \$(document.body).on('updated_cart_totals updated_checkout', applyBadge);
            \$(document).ready(applyBadge);
        })(jQuery);");
    }

    // AJAX: Search categories
    public function ajax_search_categories() {
        check_ajax_referer('search-products', 'security');
        $term       = sanitize_text_field($_GET['term'] ?? '');
        $categories = get_terms(array(
            'taxonomy'   => 'product_cat',
            'name__like' => $term,
            'hide_empty' => false,
            'number'     => 20,
        ));
        $results = array();
        if (!is_wp_error($categories)) {
            foreach ($categories as $cat) {
                $results[] = array('id' => $cat->term_id, 'text' => $cat->name);
            }
        }
        wp_send_json($results);
    }

    // AJAX: Save Free Delivery settings
    public function ajax_save_fd_settings() {
        check_ajax_referer('hkdev_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $data = array(
            'enable_qty'      => !empty($_POST['enable_qty'])      ? 1 : 0,
            'enable_products' => !empty($_POST['enable_products']) ? 1 : 0,
            'enable_cats'     => !empty($_POST['enable_cats'])     ? 1 : 0,
            'enable_anim'     => !empty($_POST['enable_anim'])     ? 1 : 0,
            'qty_threshold'   => absint($_POST['qty_threshold']    ?? 2),
            'label'           => sanitize_text_field($_POST['label'] ?? 'Free Delivery'),
            'products'        => isset($_POST['products'])   ? array_map('absint', (array) $_POST['products'])   : array(),
            'categories'      => isset($_POST['categories']) ? array_map('absint', (array) $_POST['categories']) : array(),
        );

        update_option(self::SETTINGS_KEY, $data);
        wp_send_json_success('Settings saved successfully');
    }

    // Product meta field
    public function add_product_meta_field() {
        echo '<div class="options_group">';
        woocommerce_wp_checkbox(array(
            'id'          => '_hkdev_free_delivery',
            'label'       => 'HKDEV Free Delivery',
            'description' => 'Mark this product to always trigger free delivery when added to cart.',
        ));
        echo '</div>';
    }

    public function save_product_meta($product) {
        $val = isset($_POST['_hkdev_free_delivery']) ? 'yes' : 'no';
        $product->update_meta_data('_hkdev_free_delivery', $val);
    }

    // Category meta fields
    public function add_category_meta_field() {
        ?>
        <div class="form-field">
            <label for="hkdev_free_delivery">HKDEV Free Delivery</label>
            <input type="checkbox" name="hkdev_free_delivery" id="hkdev_free_delivery" value="yes" style="width:20px;">
            <p class="description">Products in this category will unlock free delivery.</p>
        </div>
        <?php
    }

    public function edit_category_meta_field($term) {
        $val = get_term_meta($term->term_id, 'hkdev_free_delivery', true);
        ?>
        <tr class="form-field">
            <th scope="row"><label for="hkdev_free_delivery">HKDEV Free Delivery</label></th>
            <td>
                <input type="checkbox" name="hkdev_free_delivery" id="hkdev_free_delivery" value="yes" <?php checked($val, 'yes'); ?> style="width:20px;">
                <p class="description">Products in this category will unlock free delivery.</p>
            </td>
        </tr>
        <?php
    }

    public function save_category_meta($term_id) {
        $val = isset($_POST['hkdev_free_delivery']) ? 'yes' : 'no';
        update_term_meta($term_id, 'hkdev_free_delivery', $val);
    }
}
