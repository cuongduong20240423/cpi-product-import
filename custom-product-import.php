<?php

/**
 * Plugin Name: Custom Product Import
 * Description: A custom plugin to import products and manage synchronization with external APIs.
 * Version: 1.0.0
 * Author: CoinWeb
 * Text Domain: custom-product-import
 */
//  ini_set('display_errors', '1');
// ini_set('display_startup_errors', '1');
// error_reporting(E_ALL);

require_once __DIR__ . '/vendor/autoload.php';

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}
// Autoload classes
spl_autoload_register(function ($class_name) {
    if (strpos($class_name, 'CPI_') === 0) {
        $file_name = 'class-' . strtolower(str_replace('_', '-', $class_name)) . '.php';
        $file_path = plugin_dir_path(__FILE__) . 'includes/' . $file_name;
        if (file_exists($file_path)) {
            require_once $file_path;
        }
    }
});

// Define constants
define('CPI_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CPI_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('CPI_VERSION', '1.0.0');

// Hook to run on plugin activation
register_activation_hook(__FILE__, 'cpi_create_import_table');
/**
 * Create database table for import queue on plugin activation.
 */
function cpi_create_import_table()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'cpi_import_queue';

    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT(20) UNSIGNED NOT NULL,
        product_data LONGTEXT NOT NULL,
        product_id BIGINT(20) UNSIGNED DEFAULT NULL,
        status VARCHAR(50) NOT NULL DEFAULT 'pending',
        message TEXT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

add_action('woocommerce_after_order_itemmeta', function ($item_id, $item, $product) {
    if (!is_admin()) return;
    // Chỉ hiển thị ở admin
    if (!$product) return;
    $format = $product->get_attribute('pa_format');
    if (strtolower($format) !== 'print') return;

    $variation_id = $item->get_variation_id();
    if (!$variation_id) return;

    $cover_url = get_post_meta($variation_id, '_wc_variation_file_cover_url', true);
    $interior_url = get_post_meta($variation_id, '_wc_variation_file_interior_url', true);

    if ($cover_url || $interior_url) {
        echo '<div style="margin-top:8px;padding:8px;background:#f9f9f9;border:1px solid #eee">';
        if ($cover_url) {
            echo '<div><strong>Cover File:</strong> <a href="' . esc_url($cover_url) . '" target="_blank">View</a></div>';
        }
        if ($interior_url) {
            echo '<div><strong>Interior File:</strong> <a href="' . esc_url($interior_url) . '" target="_blank">View</a></div>';
        }
        echo '</div>';
    }
}, 10, 3);

add_filter('post_type_link', function ($permalink, $post, $leavename, $sample) {
    if ($post->post_type === 'product') {
        $slug = $post->post_name;
        // Chỉ thay đổi link ở trang build-your-book hoặc theo điều kiện bạn muốn
        if (is_page('build-your-book')) {
            return home_url('/build-your-book-detail/?pslug=' . $slug);
        }
    }
    return $permalink;
}, 10, 4);

add_action('wp_ajax_cpi_get_interior_products', 'cpi_get_interior_products_callback');
add_action('wp_ajax_nopriv_cpi_get_interior_products', 'cpi_get_interior_products_callback');

function cpi_get_interior_products_callback()
{
    $paged = isset($_POST['paged']) ? intval($_POST['paged']) : 1;
    $per_page = 24;
    $category = !empty($_POST['category']) ? sanitize_text_field($_POST['category']) : '';

    $tax_query = [
        [
            'taxonomy' => 'pa_content-type',
            'field' => 'slug',
            'terms' => ['interior-pages'],
        ]
    ];

    if ($category) {
        $tax_query[] = [
            'taxonomy' => 'product_cat',
            'field' => 'slug',
            'terms' => [$category],
        ];
    }

    $args = [
        'post_type' => 'product',
        'posts_per_page' => $per_page,
        'paged' => $paged,
        'tax_query' => $tax_query,
        'meta_query' => [
            [
                'key' => '_price',
                'value' => 0,
                'compare' => '>',
                'type' => 'NUMERIC'
            ]
        ]
    ];

    $query = new WP_Query($args);
    $products = [];
    foreach ($query->posts as $p) {
		$prod = function_exists('wc_get_product') ? wc_get_product($p->ID) : null;
		
		// Convert YayCurrency helper
        if (class_exists('\Yay_Currency\Helpers\YayCurrencyHelper')) {
            $apply_currency = \Yay_Currency\Helpers\YayCurrencyHelper::detect_current_currency();
            $converted_price = \Yay_Currency\Helpers\YayCurrencyHelper::calculate_price_by_currency( (float) $prod->get_price(), false, $apply_currency );
        }else{
            $converted_price = (float) $prod->get_price();
        }

        $products[] = [
            'id' => $p->ID,
            'title' => $p->post_title,
            'thumb' => get_the_post_thumbnail_url($p->ID, 'large'),
            'price' => $converted_price,
        ];
    }
    wp_send_json_success(['products' => $products, 'max_num_pages' => $query->max_num_pages]);
}

add_action('wp_ajax_cpi_get_product_variations', 'cpi_get_product_variations_callback');
add_action('wp_ajax_nopriv_cpi_get_product_variations', 'cpi_get_product_variations_callback');

function cpi_get_product_variations_callback()
{
    $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;

    if (!$product_id) {
        wp_send_json_error(['message' => 'Product ID is required']);
        return;
    }

    $product = wc_get_product($product_id);
    if (!$product || !$product->is_type('variable')) {
        wp_send_json_error(['message' => 'Product not found or not variable']);
        return;
    }

    $variations = [];
    $attributes = [];

    // Get available variations
    $available_variations = $product->get_available_variations();
    foreach ($available_variations as $variation_data) {
        $variation = wc_get_product($variation_data['variation_id']);
        $variations[] = [
            'variation_id' => $variation_data['variation_id'],
            'attributes' => $variation_data['attributes'],
            'price' => $variation->get_price(),
            'regular_price' => $variation->get_regular_price(),
            'sale_price' => $variation->get_sale_price()
        ];
    }

    // Get product attributes for building selects
    $product_attributes = $product->get_variation_attributes();
    foreach ($product_attributes as $attribute_name => $options) {
        $attribute_data = [];

        if (strpos($attribute_name, 'pa_') === 0) {
            // This is a global attribute
            $taxonomy = $attribute_name;
            $taxonomy_obj = get_taxonomy($taxonomy);
            $attribute_label = $taxonomy_obj ? $taxonomy_obj->labels->singular_name : ucfirst(str_replace(['attribute_', 'pa_', '-', '_'], [' ', '', ' ', ' '], $attribute_name));
            $terms = get_terms([
                'taxonomy' => $taxonomy,
                'hide_empty' => false,
                'slug' => $options
            ]);

            foreach ($terms as $term) {
                $attribute_data[] = [
                    'slug' => $term->slug,
                    'name' => $term->name
                ];
            }
        } else {
            // This is a custom attribute
            foreach ($options as $option) {
                $attribute_data[] = [
                    'slug' => sanitize_title($option),
                    'name' => $option
                ];
            }
        }

        $attributes[$attribute_name] = [
            'label' => $attribute_label,
            'options' => $attribute_data
        ];
    }

    wp_send_json_success([
        'variations' => $variations,
        'attributes' => $attributes
    ]);
}


/**
 * Custom cart display
 */
add_action('wp_ajax_cpi_add_build_book_to_cart', 'cpi_handle_build_book_add_to_cart');
add_action('wp_ajax_nopriv_cpi_add_build_book_to_cart', 'cpi_handle_build_book_add_to_cart');

function cpi_handle_build_book_add_to_cart()
{
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'cpi_add_to_cart')) {
        wp_send_json_error(['message' => 'Security check failed']);
        return;
    }

    $cover_product_id = intval($_POST['cover_product_id']);
    $interior_product_id = intval($_POST['interior_product_id']);
    $format = sanitize_text_field($_POST['format']);
    $page_count = intval($_POST['page_count']);

    if (!$cover_product_id || !$interior_product_id || !$format || !$page_count) {
        wp_send_json_error(['message' => 'Missing required parameters']);
        return;
    }

    // Validate products exist
    $cover_product = wc_get_product($cover_product_id);
    $interior_product = wc_get_product($interior_product_id);

    if (!$cover_product || !$interior_product) {
        wp_send_json_error(['message' => 'One or more products not found']);
        return;
    }

    try {
        // Generate unique bundle ID to link the products
        $bundle_id = uniqid('book_bundle_');

        // Add cover product to cart
        $cover_cart_item_key = WC()->cart->add_to_cart(
            $cover_product_id,
            1,
            0,
            [],
            [
                'build_book_bundle_id' => $bundle_id,
                'build_book_type' => 'cover',
                'build_book_format' => $format,
                'build_book_interior_id' => $interior_product_id
            ]
        );

        if (!$cover_cart_item_key) {
            wp_send_json_error(['message' => 'Failed to add cover to cart']);
            return;
        }

        // Add interior product to cart
        $interior_cart_item_key = WC()->cart->add_to_cart(
            $interior_product_id,
            1,
            0,
            [],
            [
                'build_book_bundle_id' => $bundle_id,
                'build_book_type' => 'interior',
                'build_book_format' => $format,
                'build_book_page_count' => $page_count,
                'build_book_cover_id' => $cover_product_id,
                'build_book_cover_cart_key' => $cover_cart_item_key
            ]
        );

        if (!$interior_cart_item_key) {
            // Remove cover if interior failed
            WC()->cart->remove_cart_item($cover_cart_item_key);
            wp_send_json_error(['message' => 'Failed to add interior to cart']);
            return;
        }

        // Update cover cart item with interior cart key
        WC()->cart->cart_contents[$cover_cart_item_key]['build_book_interior_cart_key'] = $interior_cart_item_key;
        WC()->cart->set_session();

        wp_send_json_success([
            'message' => 'Book added to cart successfully',
            'cover_cart_key' => $cover_cart_item_key,
            'interior_cart_key' => $interior_cart_item_key,
            'bundle_id' => $bundle_id,
            'format' => $format,
            'page_count' => $page_count
        ]);
    } catch (Exception $e) {
        wp_send_json_error(['message' => 'Error adding to cart: ' . $e->getMessage()]);
    }
}

// Customize cart item display
add_filter('woocommerce_cart_item_name', 'cpi_customize_cart_item_name', 10, 3);
function cpi_customize_cart_item_name($product_name, $cart_item, $cart_item_key)
{
    if (isset($cart_item['build_book_bundle_id'])) {
        $bundle_id = $cart_item['build_book_bundle_id'];
        $type = $cart_item['build_book_type'];
        $format = $cart_item['build_book_format'] ?? '';

        $format_icon = '';
        $format_text = '';
        if ($format === 'print') {
            $format_icon = '🖨️';
            $format_text = ' (Print)';
        } elseif ($format === 'download') {
            $format_icon = '📥';
            $format_text = ' (Download)';
        }

        if ($type === 'cover') {
            $product_name = '📖 ' . $product_name . ' <span style="color: #666; font-size: 0.9em;">(Cover' . $format_text . ')</span>';
        } elseif ($type === 'interior') {
            $product_name = '📄 ' . $product_name . ' <span style="color: #666; font-size: 0.9em;">(Interior Pages' . $format_text . ')</span>';
        }

        // Add bundle info
        $product_name .= '<br><small style="color: #999;">' . $format_icon . ' Bundle: ' . substr($bundle_id, -8) . '</small>';
    }

    return $product_name;
}

// Add custom meta to cart items
add_filter('woocommerce_get_item_data', 'cpi_add_cart_item_custom_data', 10, 2);
function cpi_add_cart_item_custom_data($cart_item_data, $cart_item)
{
    if (isset($cart_item['build_book_bundle_id'])) {
        $cart_item_data[] = [
            'key' => 'Book Type',
            'value' => ucfirst($cart_item['build_book_type'])
        ];

        $cart_item_data[] = [
            'key' => 'Bundle ID',
            'value' => substr($cart_item['build_book_bundle_id'], -8)
        ];

        if (isset($cart_item['build_book_page_count']) && $cart_item['build_book_page_count'] > 0) {
            $cart_item_data[] = [
                'key' => 'Page Count',
                'value' => $cart_item['build_book_page_count']
            ];
        }
    }

    return $cart_item_data;
}

// Prevent individual removal - remove both items together
add_filter('woocommerce_cart_item_remove_link', 'cpi_customize_remove_link', 10, 2);
function cpi_customize_remove_link($link, $cart_item_key)
{
    $cart_item = WC()->cart->get_cart_item($cart_item_key);

    if (isset($cart_item['build_book_bundle_id'])) {
        // Custom remove link that removes both items
        $remove_url = add_query_arg([
            'remove_book_bundle' => $cart_item['build_book_bundle_id'],
            'nonce' => wp_create_nonce('remove_book_bundle')
        ], wc_get_cart_url());

        return sprintf(
            '<a href="%s" class="remove cs-cart-remove" aria-label="%s" data-product_id="%s" data-product_sku="%s">%s</a>',
            esc_url($remove_url),
            esc_attr__('Remove this book from cart', 'woocommerce'),
            esc_attr($cart_item['product_id']),
            esc_attr($cart_item['data']->get_sku()),
            esc_html__('Remove item', 'woocommerce')
        );
    }

    return $link;
}

// Handle bundle removal
add_action('wp_loaded', 'cpi_handle_bundle_removal');
function cpi_handle_bundle_removal()
{
    if (isset($_GET['remove_book_bundle']) && isset($_GET['nonce'])) {
        if (wp_verify_nonce($_GET['nonce'], 'remove_book_bundle')) {
            $bundle_id = sanitize_text_field($_GET['remove_book_bundle']);

            // Find and remove all items with this bundle ID
            $cart_contents = WC()->cart->get_cart();
            foreach ($cart_contents as $cart_item_key => $cart_item) {
                if (isset($cart_item['build_book_bundle_id']) && $cart_item['build_book_bundle_id'] === $bundle_id) {
                    WC()->cart->remove_cart_item($cart_item_key);
                }
            }

            // Redirect to cart
            wp_redirect(wc_get_cart_url());
            exit;
        }
    }
}

// Prevent quantity changes for bundled items
add_filter('woocommerce_cart_item_quantity', 'cpi_restrict_bundle_quantity', 10, 3);
function cpi_restrict_bundle_quantity($product_quantity, $cart_item_key, $cart_item)
{
    if (isset($cart_item['build_book_bundle_id'])) {
        // Make quantity read-only for bundled items
        return sprintf('<span class="quantity">%s</span> <small>(Book bundle)</small>', $cart_item['quantity']);
    }

    return $product_quantity;
}

// Ensure bundled items stay together during checkout
add_action('woocommerce_checkout_create_order_line_item', 'cpi_add_bundle_meta_to_order', 10, 4);
function cpi_add_bundle_meta_to_order($item, $cart_item_key, $values, $order)
{
    if (isset($values['build_book_bundle_id'])) {
        $item->add_meta_data('_build_book_bundle_id', $values['build_book_bundle_id']);
        $item->add_meta_data('_build_book_type', $values['build_book_type']);
        $item->add_meta_data('_build_book_format', $values['build_book_format']);

        if (isset($values['build_book_interior_id'])) {
            $item->add_meta_data('_build_book_interior_id', $values['build_book_interior_id']);
            $item->add_meta_data('_build_book_interior_variation_id', $values['build_book_interior_variation_id']);
        }

        if (isset($values['build_book_cover_id'])) {
            $item->add_meta_data('_build_book_cover_id', $values['build_book_cover_id']);
        }

        if (isset($values['build_book_cover_variation_id'])) {
            $item->add_meta_data('_build_book_cover_variation_id', $values['build_book_cover_variation_id']);
        }

        if (isset($values['build_book_page_count'])) {
            $item->add_meta_data('_build_book_page_count', $values['build_book_page_count']);
        }
    }
}
/**
 * end custom cart display
 */

// Initialize the plugin
class Custom_Product_Import
{
    public function __construct()
    {
        // Load assets
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

        // Initialize Common
        CPI_Common::init();

        // Initialize settings
        CPI_Settings::init();

        // Initialize product import functionality
        new CPI_Product_Import();

        // Initialize Lulu API integration
        new CPI_Lulu_API();

        // Initialize product synchronization
        new CPI_Sync_Product();

        new CPI_Build_Book_Shortcode();

        // Initialize cron jobs
        CPI_Cron_Handler::init();

        CPI_Book_Cover_Pdf_Handler::init();

        CPI_Book_Interior_Pdf_Handler::init();
    }

    public function enqueue_assets()
    {
        // Lấy thông tin màn hình hiện tại
        $screen = get_current_screen();

        // Kiểm tra nếu màn hình thuộc plugin của bạn
        if (strpos($screen->id, 'cpi') !== false) {
            // Tải CSS
            wp_enqueue_style('cpi-admin-style', CPI_PLUGIN_URL . 'assets/css/admin-style.css', [], CPI_VERSION);

            // Tải JavaScript
            wp_enqueue_script('cpi-import-products', CPI_PLUGIN_URL . 'assets/js/import-products.js', ['jquery'], CPI_VERSION, true);

            // Truyền dữ liệu từ PHP sang JavaScript
            wp_localize_script('cpi-import-products', 'cpi_ajax_object', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('cpi_nonce'),
                'url_get_product' => CPI_Common::get_setting_api_url() . "wp-json/api/v1/get-products"
            ]);
        }
    }
}

new Custom_Product_Import();
