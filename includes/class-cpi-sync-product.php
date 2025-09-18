<?php
class CPI_Sync_Product
{
    public static function handle_sync_request()
    {
        // 1. check authentication
        $headers = getallheaders();
        $sync_secret = CPI_Common::get_setting('cpi_sync_secret');
        if (empty($sync_secret) || !isset($headers['X-CPI-Sync-Secret']) || $headers['X-CPI-Sync-Secret'] !== $sync_secret) {
            CPI_Common::write_log('Unauthorized sync request', 'error', 'dwd-sync-product');
            wp_send_json_error(['message' => 'Unauthorized'], 401);
        }

        // 2. Check sync is enabled
        if (!CPI_Common::get_setting('cpi_enable_sync')) {
            CPI_Common::write_log('Sync is disabled on this site', 'error', 'dwd-sync-product');
            wp_send_json_error(['message' => 'Sync is disabled'], 403);
        }

        // 3. Get data from request
        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data['id'])) {
            CPI_Common::write_log('Missing product id in sync data', 'error', 'dwd-sync-product');
            wp_send_json_error(['message' => 'Missing product id'], 400);
        }

        $dwd_product_id = $data['id'];

        // 4. Search for existing product by _dwd_product_id
        $product_id = self::get_product_id_by_dwd_id($dwd_product_id);
        if (!$product_id) {
            CPI_Common::write_log("Product with _dwd_product_id={$dwd_product_id} not found", 'error', 'dwd-sync-product');
            wp_send_json_error(['message' => 'Product not found'], 404);
        }

        // 5. Update product details
        $update_args = [
            'ID'           => $product_id,
            'post_title'   => $data['name'],
            'post_content' => $data['description'],
            'post_excerpt' => $data['short_description'],
        ];
        wp_update_post($update_args);

        update_post_meta($product_id, '_price', $data['price']);
        update_post_meta($product_id, '_regular_price', $data['regular_price']);
        update_post_meta($product_id, '_sale_price', $data['sale_price']);

        // Update categories
        if (!empty($data['categories'])) {
            $cat_ids = [];
            foreach ($data['categories'] as $cat) {
                $term = term_exists($cat['slug'], 'product_cat');
                if (!$term) {
                    $term = wp_insert_term($cat['name'], 'product_cat', ['slug' => $cat['slug']]);
                }
                if (!is_wp_error($term)) {
                    $cat_ids[] = intval($term['term_id']);
                }
            }
            wp_set_object_terms($product_id, $cat_ids, 'product_cat');
        }

        // Update tags
        if (!empty($data['tags'])) {
            wp_set_object_terms($product_id, $data['tags'], 'product_tag');
        }

        // 6. Get product type
        $children = get_posts([
            'post_parent' => $product_id,
            'post_type'   => 'product_variation',
            'numberposts' => -1,
            'post_status' => 'any',
        ]);

        foreach ($children as $child) {
            $variation_id = $child->ID;
            $current_format = get_post_meta($variation_id, 'attribute_' . CPI_Common::get_setting_taxonmy_slug_format(), true);
            $current_interior = get_post_meta($variation_id, 'attribute_pa_interior-page', true);
            $current_pages = intval(get_post_meta($variation_id, 'attribute_pa_number-of-pages', true));

            // Search for matching variation in remote data
            $matched = null;
            foreach ($data['variations'] as $remote_var) {
                $remote_interior = '';
                $remote_pages = '';
                foreach ($remote_var['attributes'] as $attr) {
                    if ($attr['slug'] === 'pa_interior-page') $remote_interior = array_keys($attr['options'])[0];
                    if ($attr['slug'] === 'pa_number-of-pages') $remote_pages = array_keys($attr['options'])[0];
                }
                if ($remote_interior == $current_interior && $remote_pages == $current_pages) {
                    $matched = $remote_var;
                    break;
                }
            }
            if (!$matched) continue;

            // Update price
            update_post_meta($variation_id, '_price', $matched['price']);
            update_post_meta($variation_id, '_regular_price', $matched['price']);

            // Update file download or file in
            if ($current_format === 'download') {
                // Update file download
                if (!empty($matched['downloads'])) {
                    $downloads = [];
                    foreach ($matched['downloads'] as $download) {
                        $downloads[$download['id']] = [
                            'name' => $download['name'],
                            'file' => $download['file'],
                        ];
                    }
                    update_post_meta($variation_id, '_downloadable_files', $downloads);
                }
            } elseif ($current_format === 'print') {
                // Update file in và package id
                foreach ($matched['meta_data'] as $meta) {
                    if ($meta['key'] === '_wc_variation_pod_package_id') {
                        update_post_meta($variation_id, '_wc_pod_package_id', $meta['value']);
                    }
                    if ($meta['key'] === '_wc_variation_file_cover_url') {
                        update_post_meta($variation_id, '_wc_variation_file_cover_url', $meta['value']);
                    }
                    if ($meta['key'] === '_wc_variation_file_interior_url') {
                        update_post_meta($variation_id, '_wc_variation_file_interior_url', $meta['value']);
                    }
                }
            }
        }

        CPI_Common::write_log("Product {$product_id} synced from DWD", 'info', 'dwd-sync-product');
        wp_send_json_success(['message' => 'Product synced successfully']);
    }

    private static function get_product_id_by_dwd_id($dwd_product_id)
    {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_dwd_product_id' AND meta_value = %s LIMIT 1",
            $dwd_product_id
        ));
    }

    public function check_imported_by_artfusion(WP_REST_Request $request)
    {
        global $wpdb;

        $payload = $request->get_params();
        if (empty($payload) || ! is_array($payload)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Invalid payload. Expecting object author => [skus].',
            ], 400);
        }

        // Flatten and normalize SKUs (trim, remove empties, unique)
        $all_skus = [];
        foreach ($payload as $author => $skus) {
            if (! is_array($skus)) {
                continue;
            }
            foreach ($skus as $sku) {
                $s = trim((string) $sku);
                if ($s === '') {
                    continue;
                }
                $all_skus[] = $s;
            }
        }
        $all_skus = array_values(array_unique($all_skus));
        if (empty($all_skus)) {
            return new WP_REST_Response([
                'success' => true,
                'missing' => new stdClass(), // empty object
            ], 200);
        }

        // Prepare SQL IN placeholders
        $placeholders = implode(',', array_fill(0, count($all_skus), '%s'));

        $sql = "
            SELECT pm.meta_value
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE pm.meta_key = '_sku'
            AND pm.meta_value IN ( $placeholders )
            AND p.post_type = 'product'
            AND p.post_status != 'trash'
        ";

        // Use wpdb->prepare with all skus as args
        $prepared = $wpdb->prepare($sql, $all_skus);
        $rows = $wpdb->get_col($prepared);

        // Normalize existing SKUs to lowercase for case-insensitive compare
        $existing_lower = array_map('mb_strtolower', $rows);

        // Build missing map per author preserving original sku casing/order
        $missing_map = [];
        foreach ($payload as $author => $skus) {
            if (! is_array($skus)) {
                $missing_map[$author] = [];
                continue;
            }
            $missing = [];
            foreach ($skus as $sku) {
                $s = trim((string) $sku);
                if ($s === '') {
                    continue;
                }
                if (! in_array(mb_strtolower($s), $existing_lower, true)) {
                    $missing[] = $s;
                }
            }
            $missing_map[$author] = array_values(array_unique($missing));
        }

        return new WP_REST_Response([
            'success'     => true,
            'missing_skus' => $missing_map,
        ], 200);
    }
}

add_action('rest_api_init', function () {
    register_rest_route('cpi/v1', '/sync-product', [
        'methods'  => 'POST',
        'callback' => ['CPI_Sync_Product', 'handle_sync_request'],
        'permission_callback' => '__return_true', // Đã kiểm tra bảo mật trong handle_sync_request
    ]);

    register_rest_route('cpi/v1', '/sync-product', [
        'methods'  => 'POST',
        'callback' => ['CPI_Sync_Product', 'check_imported_by_artfusion'],
        'permission_callback' => '__return_true',
    ]);
});
