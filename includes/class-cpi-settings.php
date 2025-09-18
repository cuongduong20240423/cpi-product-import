<?php

class CPI_Settings
{
    private static $option_name = 'cpi_settings';

    public static function init()
    {
        add_action('admin_menu', [__CLASS__, 'register_menus']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
    }

    /**
     * Đăng ký menu cha và các menu con
     */
    public static function register_menus()
    {
        // Tạo menu cha "Import Product"
        add_menu_page(
            __('Import Product', 'custom-product-import'),
            __('Import Product', 'custom-product-import'),
            'manage_options',
            'cpi-main-menu',
            [__CLASS__, 'render_import_product_page'],
            'dashicons-upload',
            80
        );

        // Thêm menu con "Settings"
        add_submenu_page(
            'cpi-main-menu',
            __('Settings', 'custom-product-import'),
            __('Settings', 'custom-product-import'),
            'manage_options',
            'cpi-settings',
            [__CLASS__, 'render_settings_page']
        );

        add_submenu_page(
            'cpi-main-menu',
            __('Import Queue', 'custom-product-import'),
            __('Import Queue', 'custom-product-import'),
            'manage_options',
            'cpi-import-queue',
            [__CLASS__, 'render_import_queue_page']
        );
    }

    /**
     * Đăng ký các cài đặt
     */
    public static function register_settings()
    {
        register_setting('cpi_settings_group', self::$option_name);
    }

    /**
     * Summary of render_import_product_page
     * @return void
     */
    public static function render_import_product_page()
    {
        $categories = CPI_Product_Import::get_categories();
        $users = CPI_Product_Import::get_users();
?>
        <div class="wrap">
            <h1><?php _e('Import product', 'custom-product-import'); ?></h1>
            <div id="import-products-app">
                <div class="d-flex">
                    <!-- This is where the product import management interface will be displayed. -->
                    <div id="left-column" class="d-col-6 pe-15">
                        <div class="postbox">
                            <h2><?php _e('List product', 'textdomain'); ?></h2>
                            <div class="">
                                <form action="" id="form-search-product-af" class="d-flex align-items-center">
                                    <input type="text" id="search-product" placeholder="<?php _e('Enter product name', 'textdomain'); ?>" class="me-1">
                                    <select name="product_cat" id="" class="me-1"><?php echo $categories; ?></select>
                                    <select name="author" id="" class="me-1"><?php echo $users; ?></select>
                                    <button type="submit" class="button">Filter</button>
                                </form>
                            </div>
                            <hr>
                            <div id="product-list"></div>
                        </div>
                    </div>
                    <div id="right-column" class="flex-grow-1">
                        <div class="postbox">
                            <h2><?php _e('Product Seleted', 'textdomain'); ?></h2>
                            <div id="selected-product-list"></div>
                            <button id="import-button-art" class="button"><?php _e('Proceed to create product', 'textdomain'); ?></button>
                            <button id="reset-button-art" class="button" style="border-color: red;"><?php _e('Reset', 'textdomain'); ?></button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php
    }

    /**
     * Summary of render_settings_page
     * @return void
     */
    public static function render_settings_page()
    {
        if (isset($_POST['cpi_save_settings']) && $_POST['cpi_save_settings']) {
            $settings = [
                'cpi_api_url' => sanitize_text_field($_POST['cpi_api_url']),
                'cpi_consumer_key' => sanitize_text_field($_POST['cpi_consumer_key']),
                'cpi_consumer_secret' => sanitize_text_field($_POST['cpi_consumer_secret']),
                'cpi_currency' => sanitize_text_field($_POST['cpi_currency']),
                'cpi_role' => sanitize_text_field($_POST['cpi_role']),
                'cpi_taxonomy_page' => sanitize_text_field($_POST['cpi_taxonomy_page']),
                'cpi_taxonomy_format' => sanitize_text_field($_POST['cpi_taxonomy_format']),
                'cpi_lulu_url' => sanitize_text_field($_POST['cpi_lulu_url']),
                'cpi_lulu_client_key' => sanitize_text_field($_POST['cpi_lulu_client_key']),
                'cpi_lulu_client_secret' => sanitize_text_field($_POST['cpi_lulu_client_secret']),
                'cpi_lulu_package_id' => sanitize_text_field($_POST['cpi_lulu_package_id']),
                'cpi_lulu_shipping_level' => sanitize_text_field($_POST['cpi_lulu_shipping_level']),

                'cpi_enable_sync' => isset($_POST['cpi_enable_sync']) ? 1 : 0,
                'cpi_sync_url' => sanitize_text_field($_POST['cpi_sync_url']),
                'cpi_sync_secret' => sanitize_text_field($_POST['cpi_sync_secret']),
            ];

            update_option(self::$option_name, $settings);

            echo '<div class="updated"><p>' . __('Settings saved.', 'custom-product-import') . '</p></div>';
        }

        $settings = get_option(self::$option_name, [
            'cpi_api_url' => 'https://domain.com/',
            'cpi_consumer_key' => '',
            'cpi_consumer_secret' => '',
            'cpi_currency' => get_option('woocommerce_currency', 'SGD'),
            'cpi_role' => '',
            'cpi_taxonomy_page' => 'pa_number-of-pages',
            'cpi_taxonomy_format' => 'pa_format',
            'cpi_lulu_url' => 'https://api.lulu.com/',
            'cpi_lulu_client_key' => '',
            'cpi_lulu_client_secret' => '',
            'cpi_lulu_package_id' => 'FCSTDPB060UW444MXX',
            'cpi_lulu_shipping_level' => 'MAIL',
            'cpi_enable_sync' => 0,
            'cpi_sync_url' => '',
            'cpi_sync_secret' => '',
        ]);

        if (empty($settings['cpi_sync_secret'])) {
            $settings['cpi_sync_secret'] = wp_generate_password(32, false, false);
            update_option(self::$option_name, $settings);
        }

        if (empty($settings['cpi_sync_url'])) {
            $settings['cpi_sync_url'] = home_url('/wp-json/cpi/v1/sync-product');
            update_option(self::$option_name, $settings);
        }

        if (empty($settings['cpi_enable_sync'])) {
            $settings['cpi_enable_sync'] = 0;
            update_option(self::$option_name, $settings);
        }

        $roles = get_editable_roles();
    ?>
        <div class="wrap">
            <h1><?php _e('Settings', 'custom-product-import'); ?></h1>
            <form method="post">
                <h2><?php _e('Website Settings', 'custom-product-import'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="cpi_api_url"><?php _e('API URL', 'custom-product-import'); ?></label></th>
                        <td><input type="text" name="cpi_api_url" id="cpi_api_url" value="<?php echo esc_attr($settings['cpi_api_url']); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cpi_consumer_key"><?php _e('Consumer Key', 'custom-product-import'); ?></label></th>
                        <td><input type="text" name="cpi_consumer_key" id="cpi_consumer_key" value="<?php echo esc_attr($settings['cpi_consumer_key']); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cpi_consumer_secret"><?php _e('Consumer Secret', 'custom-product-import'); ?></label></th>
                        <td><input type="text" name="cpi_consumer_secret" id="cpi_consumer_secret" value="<?php echo esc_attr($settings['cpi_consumer_secret']); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cpi_currency"><?php _e('Currency', 'custom-product-import'); ?></label></th>
                        <td><input type="text" name="cpi_currency" id="cpi_currency" value="<?php echo esc_attr($settings['cpi_currency']); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cpi_role"><?php _e('Select Role', 'custom-product-import'); ?></label></th>
                        <td>
                            <select name="cpi_role" id="cpi_role" class="regular-text">
                                <?php foreach ($roles as $role_key => $role_data): ?>
                                    <option value="<?php echo esc_attr($role_key); ?>" <?php selected($settings['cpi_role'], $role_key); ?>>
                                        <?php echo esc_html($role_data['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cpi_taxonomy_page"><?php _e('Taxonomy slug Number Of Page', 'custom-product-import'); ?></label></th>
                        <td><input type="text" name="cpi_taxonomy_page" id="cpi_taxonomy_page" value="<?php echo esc_attr($settings['cpi_taxonomy_page']); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cpi_taxonomy_format"><?php _e('Taxonomy slug Format', 'custom-product-import'); ?></label></th>
                        <td><input type="text" name="cpi_taxonomy_format" id="cpi_taxonomy_format" value="<?php echo esc_attr($settings['cpi_taxonomy_format']); ?>" class="regular-text"></td>
                    </tr>
                </table>

                <hr>
                <h2><?php _e('Lulu Settings', 'custom-product-import'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="cpi_lulu_url"><?php _e('Lulu API URL', 'custom-product-import'); ?></label></th>
                        <td><input type="text" name="cpi_lulu_url" id="cpi_lulu_url" value="<?php echo esc_attr($settings['cpi_lulu_url']); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cpi_lulu_client_key"><?php _e('Lulu Client Key', 'custom-product-import'); ?></label></th>
                        <td><input type="text" name="cpi_lulu_client_key" id="cpi_lulu_client_key" value="<?php echo esc_attr($settings['cpi_lulu_client_key']); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cpi_lulu_client_secret"><?php _e('Lulu Client Secret', 'custom-product-import'); ?></label></th>
                        <td><input type="text" name="cpi_lulu_client_secret" id="cpi_lulu_client_secret" value="<?php echo esc_attr($settings['cpi_lulu_client_secret']); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cpi_lulu_package_id"><?php _e('Lulu Package ID', 'custom-product-import'); ?></label></th>
                        <td><input type="text" name="cpi_lulu_package_id" id="cpi_lulu_package_id" value="<?php echo esc_attr($settings['cpi_lulu_package_id']); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cpi_lulu_shipping_level"><?php _e('Lulu Shipping Level', 'custom-product-import'); ?></label></th>
                        <td><input type="text" name="cpi_lulu_shipping_level" id="cpi_lulu_shipping_level" value="<?php echo esc_attr($settings['cpi_lulu_shipping_level']); ?>" class="regular-text"></td>
                    </tr>
                </table>

                <hr>
                <h2><?php _e('Product Sync Settings', 'custom-product-import'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="cpi_enable_sync"><?php _e('Enable Product Sync', 'custom-product-import'); ?></label>
                        </th>
                        <td>
                            <input type="checkbox" name="cpi_enable_sync" id="cpi_enable_sync" value="1" <?php checked($settings['cpi_enable_sync'], 1); ?>>
                            <span class="description"><?php _e('Tick to enable product sync feature.', 'custom-product-import'); ?></span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="cpi_sync_url"><?php _e('Sync Endpoint URL', 'custom-product-import'); ?></label>
                        </th>
                        <td>
                            <input type="text" name="cpi_sync_url" id="cpi_sync_url" value="<?php echo esc_attr($settings['cpi_sync_url']); ?>" class="regular-text">
                            <span class="description"><?php _e('This is the endpoint for product sync. Use this URL on website B.', 'custom-product-import'); ?></span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="cpi_sync_secret"><?php _e('Sync Secret Key', 'custom-product-import'); ?></label>
                        </th>
                        <td>
                            <input type="text" name="cpi_sync_secret" id="cpi_sync_secret" value="<?php echo esc_attr($settings['cpi_sync_secret']); ?>" class="regular-text" readonly>
                            <button type="button" class="button" id="cpi_regen_secret"><?php _e('Regenerate', 'custom-product-import'); ?></button>
                            <span class="description"><?php _e('Use this key for authentication from website B.', 'custom-product-import'); ?></span>
                        </td>
                    </tr>
                </table>

                <script>
                    jQuery(document).ready(function($) {
                        $('#cpi_regen_secret').on('click', function(e) {
                            e.preventDefault();
                            var rand = Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2);
                            $('#cpi_sync_secret').val(rand);
                        });
                    });
                </script>

                <p class="submit">
                    <input type="submit" name="cpi_save_settings" id="cpi_save_settings" class="button button-primary" value="<?php _e('Save Changes', 'custom-product-import'); ?>">
                </p>
            </form>
        </div>
<?php
    }

    /**
     * Summary of render_import_queue_page
     * @return void
     */
    public static function render_import_queue_page()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'cpi_import_queue';
        $results = $wpdb->get_results("SELECT * FROM $table_name ORDER BY created_at DESC");

        echo '<div class="wrap">';
        echo '<h1>' . __('Import Queue', 'custom-product-import') . '</h1>';
        echo '<table class="wp-list-table widefat fixed striped table-view-list">';
        echo '
            <thead>
                <tr>
                    <th>Product Name</th>
                    <th>User</th>
                    <th>Status</th>
                    <th>Message</th>
                    <th>Created At</th>
                    <th>Updated At</th>
                </tr>
            </thead>
            ';
        echo '<tbody>';
        foreach ($results as $row) {
            $product_data = maybe_unserialize($row->product_data);
            $user = get_user_by('id', $row->user_id);
            echo '<tr>';
            echo '<td>
                <div class="product-item" style="border-bottom: 0;">
                    <div class="product-image">
                        <img src="' . esc_url($product_data['images'][0]['thumbnail'] ?? '') . '" alt="' . esc_attr($product_data['name']) . '" width="50px" height="50px">
                    </div>
                    <div>
                        <div class="product-name"><strong>' . esc_html($product_data['name']) . '</strong></div>
                        <div class="product-price" style="color: red;">' . $product_data['price_html'] . '</div>
                    </div>
                </div>
            </td>';
            echo '<td>' . esc_html($user->user_login) . '</td>';
            echo '<td>' . CPI_Common::get_status_label($row->status) . '</td>';
            echo '<td>' . esc_html($row->message) . '</td>';
            echo '<td>' . esc_html($row->created_at) . '</td>';
            echo '<td>' . esc_html($row->updated_at) . '</td>';
            echo '</tr>';
        }
        echo '</tbody>';
        echo '</table>';
        echo '</div>';
    }
}
