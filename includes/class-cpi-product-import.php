<?php

class CPI_Product_Import
{
    private $logger;

    public static function init() {}

    public function __construct()
    {
        // $this->logger = wc_get_logger();
        add_action('wp_ajax_cpi_get_products', [$this, 'get_products_callback']);
        add_action('wp_ajax_import_selected_products', [$this, 'import_selected_products_callback']);
        add_action('wp_ajax_save_products_to_queue', [$this, 'save_products_to_queue_callback']);
    }

    /**
     * Summary of download_image
     * @param mixed $image_url
     * @return int|WP_Error
     */
    public function download_image($image_url)
    {
        // Get the image content from the URL
        $image_data = file_get_contents($image_url);

        // Get filename from URL
        $filename = basename($image_url);

        // Create a file in the uploads folder
        $upload_dir = wp_upload_dir();
        $file_path = $upload_dir['path'] . '/' . $filename;
        file_put_contents($file_path, $image_data);

        // Check file type
        $file_type = wp_check_filetype($filename, null);

        // Prepare file information for insertion into the media library
        $attachment = array(
            'post_mime_type' => $file_type['type'],
            'post_title' => sanitize_file_name($filename),
            'post_content' => '',
            'post_status' => 'inherit'
        );

        // Insert file into media library and return ID
        $attach_id = wp_insert_attachment($attachment, $file_path);

        // Create metadata for attachment
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attach_data = wp_generate_attachment_metadata($attach_id, $file_path);
        wp_update_attachment_metadata($attach_id, $attach_data);

        return $attach_id;
    }

    /**
     * Summary of product_exists_by_name
     * @param mixed $product_name
     * @return bool|int
     */
    public function product_exists_by_name($product_name)
    {
        $existing_product = get_page_by_title($product_name, OBJECT, 'product');
        return $existing_product ? $existing_product->ID : false;
    }

    /**
     * Summary of product_exists_by_sku_id
     * @param mixed $sku_or_id
     * @return bool|string
     */
    public function product_exists_by_sku_id($sku_or_id)
    {
        global $wpdb;

        // Check sky by product
        $product_id = $wpdb->get_var($wpdb->prepare("
        SELECT post_id FROM {$wpdb->postmeta}
        WHERE (meta_key = '_sku' AND meta_value = %s)
        OR (meta_key = '_dwd_product_id' AND meta_value = %s)
        LIMIT 1
    ", $sku_or_id, $sku_or_id));

        return $product_id ? $product_id : false;
    }

    /**
     * Summary of create_or_get_author
     * @param mixed $author_data
     * @param mixed $image_id
     * @return int|WP_Error
     */
    public function create_or_get_author($author_data, $image_id = null)
    {
        $author = get_user_by('login', $author_data['login']);

        if (!$author) {
            // Tạo tài khoản mới nếu chưa có
            $author_id = wp_insert_user([
                'user_login' => $author_data['login'],
                'user_email' => $author_data['email'],
                'user_nicename' => $author_data['nicename'],
                'user_pass' => wp_generate_password(),
                'role' => CPI_Common::get_setting_role(),
                'first_name' => $author_data['display_name'],
                'description' => $author_data['description']
            ]);
        } else {
            // Lấy ID của tài khoản nếu đã có
            $author_id = $author->ID;
            // Cập nhật thông tin tài khoản hiện có
            wp_update_user([
                'ID' => $author_id,
                'user_email' => $author_data['email'],
                'user_nicename' => $author_data['nicename'],
                'first_name' => $author_data['display_name'],
                'role' => CPI_Common::get_setting_role(),
                'description' => $author_data['description']
            ]);
        }

        // Lấy thông tin meta của tài khoản Dokan
        $meta = get_user_meta($author_id, 'dokan_profile_settings', true);
        $meta = !empty($meta) ? $meta : [];

        // Cập nhật tên cửa hàng
        $meta['store_name'] = $author_data['display_name'];

        // Chỉ thêm banner nếu chưa có
        if (empty($meta['banner']) && $image_id) {
            $meta['banner'] = $image_id;
        }

        // Chỉ thêm gravatar nếu chưa có và avatar URL được cung cấp
        if (empty($meta['gravatar']) && !empty($author_data['avatar_url'])) {
            $attachment_id = $this->download_image($author_data['avatar_url']);
            if ($attachment_id) {
                $meta['gravatar'] = $attachment_id;
            }
        }

        // Cập nhật thông tin meta Dokan
        update_user_meta($author_id, 'dokan_profile_settings', $meta);

        return $author_id;
    }

    public function create_or_get_global_attribute($attribute_name, $attribute_values)
    {
        global $wpdb;

        // Kiểm tra xem thuộc tính đã tồn tại chưa
        $attribute_taxonomy_name = wc_sanitize_taxonomy_name($attribute_name);
        $attribute_taxonomy = 'pa_' . $attribute_taxonomy_name;

        if (!taxonomy_exists($attribute_taxonomy)) {
            // Tạo thuộc tính nếu chưa tồn tại
            $attribute_id = wc_create_attribute([
                'name'         => $attribute_name,
                'slug'         => $attribute_taxonomy_name,
                'type'         => 'select',
                'order_by'     => 'menu_order',
                'has_archives' => false,
            ]);

            if (is_wp_error($attribute_id)) {
                return false;
            }

            // Đăng ký taxonomy cho thuộc tính
            register_taxonomy(
                $attribute_taxonomy,
                apply_filters('woocommerce_taxonomy_objects_' . $attribute_taxonomy, ['product']),
                apply_filters('woocommerce_taxonomy_args_' . $attribute_taxonomy, [
                    'hierarchical' => false,
                    'show_ui'      => false,
                    'query_var'    => true,
                    'rewrite'      => false,
                ])
            );
        }

        // Thêm các giá trị vào taxonomy của thuộc tính
        $term_ids = [];
        foreach ($attribute_values as $value) {
            $term_slug = sanitize_title($value);

            $term = term_exists($term_slug, $attribute_taxonomy);

            if ($term) {
                $term_ids[] = intval($term['term_id']);
            } else {
                $new_term = wp_insert_term($value, $attribute_taxonomy);
                if (!is_wp_error($new_term))
                    $term_ids[] = intval($term['term_id']);
            }
        }

        return $term_ids;
    }

    /**
     * Import product without variations
     *
     * @param array $product_data
     * @param int $product_id
     */
    protected function import_product_with_variations($product_data, $product_id)
    {
        $new_variations = [];

        // Lấy các giá trị của thuộc tính `pa_format`
        $format_options = [
            'download' => 'Download',
            'print'    => 'Print',
        ];

        // Lặp qua từng biến thể từ API
        foreach ($product_data['variations'] as $variation_data) {
            // Lặp qua từng giá trị của `pa_format` để tạo biến thể mới
            foreach ($format_options as $format_slug => $format_name) {
                // Sao chép dữ liệu biến thể gốc
                $new_variation = $variation_data;

                // Thêm thuộc tính `pa_format` vào biến thể
                $new_variation['attributes'][] = [
                    'name'   => 'Format',
                    'slug'   => CPI_Common::get_setting_taxonmy_slug_format(),
                    'options' => [$format_slug => $format_slug],
                ];

                // Thêm biến thể mới vào danh sách
                $new_variations[] = $new_variation;
            }
        }

        // Cập nhật lại biến thể
        $product_data['variations'] = $new_variations;

        foreach ($product_data['variations'] as $variation_data) {
            $variation_id = wp_insert_post([
                'post_title'   => $product_data['name'] . ' - ' . implode(', ', array_column($variation_data['attributes'], 'option')),
                'post_status'  => 'publish',
                'post_parent'  => $product_id,
                'post_type'    => 'product_variation',
                'post_content' => '',
            ]);

            if (is_wp_error($variation_id)) {
                throw new Exception('Failed to create variation: ' . $variation_id->get_error_message());
            }

            // Lưu meta dữ liệu cho biến thể
            update_post_meta($variation_id, '_sku', $variation_data['sku']);
            update_post_meta($variation_id, '_price', $variation_data['price']);
            update_post_meta($variation_id, '_regular_price', $variation_data['regular_price']);
            update_post_meta($variation_id, '_sale_price', $variation_data['sale_price'] ?? '');
            update_post_meta($variation_id, '_stock_status', $variation_data['stock_status']);
            update_post_meta($variation_id, '_manage_stock', $variation_data['manage_stock']);
            update_post_meta($variation_id, '_stock', $variation_data['stock_quantity']);
            // update_post_meta($variation_id, '_virtual', $variation_data['virtual']);
            // update_post_meta($variation_id, '_downloadable', $variation_data['downloadable']);
            update_post_meta($variation_id, '_dwd_product_id', $variation_data['id']);

            // Lưu các thuộc tính của biến thể
            foreach ($variation_data['attributes'] as $attribute) {
                $attribute_taxonomy = $attribute['slug'];
                $options = $attribute['options'];
                $attribute_value = array_keys($options)[0] ?? '';
                // Kiểm tra và thêm giá trị thuộc tính nếu cần
                $term = term_exists(strval($attribute_value), $attribute_taxonomy);
                if (!$term) {
                    wp_insert_term($attribute_value, $attribute_taxonomy);
                }

                // Lưu thuộc tính vào biến thể
                update_post_meta($variation_id, 'attribute_' . $attribute_taxonomy, $attribute_value);
            }

            // Lưu downloads cho biến thể
            if (!empty($variation_data['downloads'])) {
                $variation_downloads = [];
                foreach ($variation_data['downloads'] as $download) {
                    $variation_downloads[$download['id']] = [
                        'name' => $download['name'],
                        'file' => $download['file'],
                    ];
                }
                update_post_meta($variation_id, '_downloadable_files', $variation_downloads);
            }

            // Lưu hình ảnh cho biến thể
            if (!empty($variation_data['images'])) {
                $gallery_ids = [];
                foreach ($variation_data['images'] as $image) {
                    $image_id = $this->download_image($image['src']);
                    if ($image_id) {
                        $gallery_ids[] = $image_id;
                    }
                }

                if (!empty($gallery_ids)) {
                    // Lưu hình ảnh đầu tiên làm thumbnail cho biến thể
                    set_post_thumbnail($variation_id, $gallery_ids[0]);

                    // Lưu các hình ảnh còn lại vào gallery (nếu cần)
                    update_post_meta($variation_id, '_variation_image_gallery', implode(',', $gallery_ids));
                }
            }

            // Lưu meta dữ liệu bổ sung cho biến thể
            $pod_package_id = '';
            if (!empty($variation_data['meta_data'])) {
                foreach ($variation_data['meta_data'] as $meta) {
                    if ($meta['key'] == '_wc_variation_pod_package_id') {
                        $pod_package_id = $meta['value'];
                        continue;
                    }
                    update_post_meta($variation_id, $meta['key'], $meta['value']);
                }
            }

            // Kích hoạt hook nếu biến thể là `print`
            $is_print = false;

            foreach ($variation_data['attributes'] as $attribute) {
                if ($attribute['slug'] === CPI_Common::get_setting_taxonmy_slug_format() && array_keys($attribute['options'])[0] === 'print') {
                    $is_print = true;
                    break;
                }
            }

            if (!$is_print) {
                update_post_meta($variation_id, '_virtual', 'yes');
                update_post_meta($variation_id, '_downloadable', 'yes');
            }

            if ($is_print) {
                update_post_meta($variation_id, '_virtual', 'no');
                update_post_meta($variation_id, '_downloadable', 'no');
                if ($pod_package_id) {
                    update_post_meta($variation_id, '_wc_pod_package_id', $pod_package_id);
                } else {
                    do_action('woocommerce_save_product_variation', $variation_id, 0);
                }
            }
        }
    }

    /**
     * Import product without variations
     * @param mixed $product_data
     * @param mixed $product_id
     * @return void
     */
    protected function import_product_without_variations($product_data, $product_id)
    {
        $content_type = '';
        foreach ($product_data['attributes'] as $attr) {
            if ($attr['slug'] === 'pa_content-type') {
                $content_type = array_key_first($attr['options']);
                break;
            }
        }

        $format_attr_slug = CPI_Common::get_setting_taxonmy_slug_format();
        $page_attr_slug = CPI_Common::get_setting_taxonmy_slug_page();
        $format_options = [
            'download' => 'Download',
            'print'    => 'Print',
        ];

        // Đảm bảo các term format tồn tại và lấy term_id
        $format_term_ids = [];
        foreach ($format_options as $slug => $name) {
            $term = term_exists($slug, $format_attr_slug);
            if (!$term) {
                $term = wp_insert_term($name, $format_attr_slug, ['slug' => $slug]);
            }
            if (!is_wp_error($term)) {
                $format_term_ids[$slug] = intval($term['term_id']);
            }
        }

        // Thêm thuộc tính format vào _product_attributes nếu chưa có
        $product_data_attributes = [];
        $product_attributes = get_post_meta($product_id, '_product_attributes', true);
        if (!is_array($product_attributes)) $product_attributes = [];
        if (!isset($product_attributes[$format_attr_slug])) {
            $product_attributes[$format_attr_slug] = [
                'name'         => $format_attr_slug,
                'value'        => implode('|', $format_term_ids), // dùng term_id
                'is_visible'   => 1,
                'is_variation' => 1,
                'is_taxonomy'  => true,
            ];
            wp_set_object_terms($product_id, array_values($format_term_ids), $format_attr_slug);

            if ($content_type === 'interior-pages') {
                $terms = get_terms([
                    'taxonomy'   => $page_attr_slug,
                    'hide_empty' => false,
                ]);
                $page_options = [];
                $page_term_ids = [];
                foreach ($terms as $term) {
                    $page_options[$term->slug] = $term->name;
                    $page_term_ids[] = $term->term_id;
                }

                $product_attributes[$page_attr_slug] = [
                    'name'         => $page_attr_slug,
                    'value'        => implode('|', $page_term_ids),
                    'is_visible'   => 1,
                    'is_variation' => 1,
                    'is_taxonomy'  => true,
                ];
                wp_set_object_terms($product_id, array_values($page_term_ids), $page_attr_slug);
            }

            update_post_meta($product_id, '_product_attributes', $product_attributes);
            
        }

        // Book Cover
        if ($content_type === 'book-cover') {
            foreach ($format_options as $format_slug => $format_name) {
                $variation_id = wp_insert_post([
                    'post_title'   => $product_data['name'] . ' - ' . $format_name,
                    'post_status'  => 'publish',
                    'post_parent'  => $product_id,
                    'post_type'    => 'product_variation',
                    'post_content' => '',
                ]);
                if (is_wp_error($variation_id)) continue;

                update_post_meta($variation_id, '_sku', $product_data['sku'] . '-' . $format_slug);
                update_post_meta($variation_id, '_price', $product_data['price']);
                update_post_meta($variation_id, '_regular_price', $product_data['regular_price']);
                update_post_meta($variation_id, '_sale_price', $product_data['sale_price'] ?? '');
                update_post_meta($variation_id, '_stock_status', $product_data['stock_status']);
                update_post_meta($variation_id, '_manage_stock', $product_data['manage_stock']);
                update_post_meta($variation_id, '_stock', $product_data['stock_quantity']);
                update_post_meta($variation_id, '_dwd_product_id', $product_data['id']);
                update_post_meta($variation_id, 'attribute_' . CPI_Common::get_setting_taxonmy_slug_format(), $format_slug);

                if ($format_slug === 'download') {
                    update_post_meta($variation_id, '_virtual', 'yes');
                    update_post_meta($variation_id, '_downloadable', 'yes');
                    if (!empty($product_data['downloads'])) {
                        $downloads = [];
                        foreach ($product_data['downloads'] as $download) {
                            $downloads[$download['id']] = [
                                'name' => $download['name'],
                                'file' => $download['file'],
                            ];
                        }
                        update_post_meta($variation_id, '_downloadable_files', $downloads);
                    }
                } else {
                    update_post_meta($variation_id, '_virtual', 'no');
                    update_post_meta($variation_id, '_downloadable', 'no');

                    // Lấy file đầu tiên trong downloads và update vào _wc_variation_file_cover_url
                    if (!empty($product_data['downloads']) && is_array($product_data['downloads'])) {
                        $first_download = reset($product_data['downloads']);
                        if (!empty($first_download['file'])) {
                            update_post_meta($variation_id, '_wc_variation_file_cover_url', $first_download['file']);
                        }
                    }
                }
            }
        }
        // Interior Pages
        elseif ($content_type === 'interior-pages') {
            // Lấy link nội dung từ meta_data
            $interior_links = [];
            if (!empty($product_data['meta_data'])) {
                foreach ($product_data['meta_data'] as $meta) {
                    if ($meta['key'] === '_wc_interior_pdf_links' && is_array($meta['value'])) {
                        $interior_links = $meta['value'];
                        break;
                    }
                }
            }

            foreach ($page_options as $page_slug => $page_name) {
                foreach ($format_options as $format_slug => $format_name) {
                    // Tạo biến thể cho từng cặp page + format
                    $variation_id = wp_insert_post([
                        'post_title'   => $product_data['name'] . " - $format_name - $page_name",
                        'post_status'  => 'publish',
                        'post_parent'  => $product_id,
                        'post_type'    => 'product_variation',
                        'post_content' => '',
                    ]);
                    if (is_wp_error($variation_id)) continue;

                    update_post_meta($variation_id, '_sku', $product_data['sku'] . "-$format_slug-$page_slug");
                    update_post_meta($variation_id, '_price', $product_data['price']);
                    update_post_meta($variation_id, '_regular_price', $product_data['regular_price']);
                    update_post_meta($variation_id, '_sale_price', $variation_data['sale_price'] ?? '');
                    
                    update_post_meta($variation_id, 'attribute_' . $format_attr_slug, $format_slug);
                    update_post_meta($variation_id, 'attribute_' . $page_attr_slug, $page_slug);

                    // Nếu là download
                    if ($format_slug === 'download') {
                        update_post_meta($variation_id, '_virtual', 'yes');
                        update_post_meta($variation_id, '_downloadable', 'yes');
                        if (!empty($interior_links[$page_slug])) {
                            $downloads = [
                                $page_slug => [
                                    'name' => $product_data['name'] . " $page_slug pages",
                                    'file' => $interior_links[$page_slug],
                                ]
                            ];
                            update_post_meta($variation_id, '_downloadable_files', $downloads);
                        }
                    } else { // print
                        update_post_meta($variation_id, '_virtual', 'no');
                        update_post_meta($variation_id, '_downloadable', 'no');
                        if (!empty($interior_links[$page_slug])) {
                            update_post_meta($variation_id, '_wc_variation_file_interior_url', $interior_links[$page_slug]);
                        }

                        do_action('woocommerce_save_product_variation', $variation_id, 0);
                    }
                }
            }
        }
    }

    public function create_product_by_dwd($product_data)
    {
        set_time_limit(0);
        global $wpdb;

        // Bắt đầu transaction
        $wpdb->query('START TRANSACTION');

        try {
            $sku_id = $product_data['sku'] ? $product_data['sku'] : $product_data['id'];
            // Kiểm tra nếu sản phẩm đã tồn tại

            if ($this->product_exists_by_sku_id($sku_id)) {
                throw new Exception('Product already exists');
            }

            // Tạo hoặc lấy thông tin tác giả
            $author_id = $this->create_or_get_author($product_data['author']);

            // Tạo sản phẩm chính
            $product_id = wp_insert_post([
                'post_title'   => wp_strip_all_tags($product_data['name']),
                'post_content' => $product_data['description'] ?? '',
                'post_excerpt' => $product_data['short_description'] ?? '',
                'post_status'  => 'publish',
                'post_type'    => 'product',
                'post_author'  => $author_id,
            ]);

            if (is_wp_error($product_id)) {
                throw new Exception('Failed to create product: ' . $product_id->get_error_message());
            }

            // Nếu sản phẩm có biến thể, thiết lập loại sản phẩm là "variable"
            wp_set_object_terms($product_id, 'variable', 'product_type');

            // Lưu meta dữ liệu sản phẩm chính
            update_post_meta($product_id, '_sku', $product_data['sku']);
            update_post_meta($product_id, '_dwd_product_id', $product_data['id']);
            update_post_meta($product_id, '_price', $product_data['price']);
            update_post_meta($product_id, '_regular_price', $product_data['regular_price']);
            update_post_meta($product_id, '_sale_price', $product_data['sale_price'] ?? '');
            update_post_meta($product_id, '_currency', $product_data['currency']);
            update_post_meta($product_id, '_stock_status', $product_data['stock_status']);
            update_post_meta($product_id, '_manage_stock', $product_data['manage_stock']);
            update_post_meta($product_id, '_stock', $product_data['stock_quantity']);
            update_post_meta($product_id, '_weight', $product_data['weight']);
            update_post_meta($product_id, '_length', $product_data['dimensions']['length']);
            update_post_meta($product_id, '_width', $product_data['dimensions']['width']);
            update_post_meta($product_id, '_height', $product_data['dimensions']['height']);
            update_post_meta($product_id, '_virtual', $product_data['virtual']);
            update_post_meta($product_id, '_downloadable', $product_data['downloadable']);

            // Lưu downloads
            if (!empty($product_data['downloads'])) {
                $downloads = [];
                foreach ($product_data['downloads'] as $download) {
                    $downloads[$download['id']] = [
                        'name' => $download['name'],
                        'file' => $download['file'],
                    ];
                }
                update_post_meta($product_id, '_downloadable_files', $downloads);
            }

            // Lưu metadata
            if (!empty($product_data['meta_data'])) {
                foreach ($product_data['meta_data'] as $meta) {
                    update_post_meta($product_id, $meta['key'], $meta['value']);
                }
            }

            // Lưu hình ảnh thumbnail
            if (!empty($product_data['images'][0]['src'])) {
                $thumbnail_id = $this->download_image($product_data['images'][0]['src']);
                if ($thumbnail_id) {
                    set_post_thumbnail($product_id, $thumbnail_id);
                }
            }

            // Lưu gallery images
            if (!empty($product_data['images'])) {
                $gallery_ids = [];
                foreach ($product_data['images'] as $key => $image) {
                    if ($key === 0) continue; // Bỏ qua thumbnail (đã lưu ở trên)
                    $image_id = $this->download_image($image['src']);
                    if ($image_id) {
                        $gallery_ids[] = $image_id;
                    }
                }
                if (!empty($gallery_ids)) {
                    update_post_meta($product_id, '_product_image_gallery', implode(',', $gallery_ids));
                }
            }

            // Gán danh mục sản phẩm
            if (!empty($product_data['categories'])) {
                $category_ids = [];
                foreach ($product_data['categories'] as $category) {
                    $term = term_exists($category['name'], 'product_cat');
                    if (!$term) {
                        $term = wp_insert_term($category['name'], 'product_cat', [
                            'slug' => $category['slug'],
                        ]);
                    }
                    if (!is_wp_error($term)) {
                        $category_ids[] = intval($term['term_id']);
                    }
                }
                wp_set_object_terms($product_id, $category_ids, 'product_cat');
            }

            // Gán tags
            if (!empty($product_data['tags'])) {
                wp_set_object_terms($product_id, $product_data['tags'], 'product_tag');
            }

            // Lưu thuộc tính vào sản phẩm chính
            $updated_attributes = [];
            $has_size_attribute = false;
            if (!empty($product_data['attributes'])) {

                //reset attributes
                foreach ($product_data['attributes'] as $attribute) {
                    $attribute_name = $attribute['name'];
                    $attribute_slug = $attribute['slug'];
                    $attribute_options = $attribute['options'];

                    if ($attribute_slug == CPI_Common::get_setting('cpi_taxonomy_page')) $has_size_attribute = true;

                    $attribute_taxonomy = wc_sanitize_taxonomy_name($attribute_slug);
                    if (!taxonomy_exists($attribute_taxonomy)) {
                        $attribute_id = wc_create_attribute([
                            'name'         => $attribute_name,
                            'slug'         => $attribute_slug,
                            'type'         => 'select',
                            'order_by'     => 'menu_order',
                            'has_archives' => false,
                        ]);

                        if (is_wp_error($attribute_id)) {
                            throw new Exception('Failed to create attribute: ' . $attribute_id->get_error_message());
                        }

                        // Đăng ký taxonomy cho attribute
                        register_taxonomy(
                            $attribute_taxonomy,
                            apply_filters('woocommerce_taxonomy_objects_' . $attribute_taxonomy, ['product']),
                            apply_filters('woocommerce_taxonomy_args_' . $attribute_taxonomy, [
                                'hierarchical' => false,
                                'show_ui'      => false,
                                'query_var'    => true,
                                'rewrite'      => false,
                            ])
                        );
                    }


                    // Kiểm tra và thêm các giá trị (options) vào attribute
                    $term_ids = [];
                    foreach ($attribute_options as $option_slug => $option_name) {
                        $term_slug = sanitize_title($option_slug);
                        $term = term_exists($term_slug, $attribute_taxonomy);

                        if ($term) {
                            $term_ids[] = intval($term['term_id']);
                        } else {
                            $new_term = wp_insert_term($option_name, $attribute_taxonomy, ['slug' => $term_slug]);
                            if (!is_wp_error($new_term)) {
                                $term_ids[] = intval($new_term['term_id']);
                            } else {
                                throw new Exception('Failed to create term: ' . $new_term->get_error_message());
                            }
                        }
                    }

                    $attribute['option_ids'] = $term_ids;
                    $updated_attributes[] = $attribute;
                }

                if ($has_size_attribute) {
                    $term_ids = [];
                    $term_download = term_exists('download', CPI_Common::get_setting_taxonmy_slug_format());
                    $term_print = term_exists('print', CPI_Common::get_setting_taxonmy_slug_format());

                    if (!$term_download) {
                        $term_download = wp_insert_term('Download', CPI_Common::get_setting_taxonmy_slug_format(), ['slug' => 'download']);
                    }
                    $term_ids[] = intval($term_download['term_id']);

                    if (!$term_print) {
                        $term_print = wp_insert_term('Print', CPI_Common::get_setting_taxonmy_slug_format(), ['slug' => 'print']);
                    }
                    $term_ids[] = intval($term_print['term_id']);
                }

                // Cập nhật lại attributes
                $product_data['attributes'] = $updated_attributes;
                // end reset attributes

                $product_attributes = [];
                foreach ($product_data['attributes'] as $attribute) {

                    // Tạo hoặc lấy taxonomy của thuộc tính
                    $term_ids = $attribute['option_ids'];
                    if ($term_ids) {
                        // Lưu thông tin thuộc tính vào mảng `_product_attributes`
                        $attribute_slug = $attribute['slug'];
                        $product_attributes[$attribute_slug] = [
                            'name'         => $attribute_slug,
                            'value'        => implode('|', $term_ids), // Lưu term ID thay vì name
                            'is_visible'   => $attribute['visible'], // Hiển thị trong tab thuộc tính
                            'is_variation' => $attribute['variation'], // Sử dụng cho biến thể
                            'is_taxonomy'  => true, // Là taxonomy-based attribute
                        ];

                        // Liên kết các giá trị thuộc tính với sản phẩm
                        wp_set_object_terms($product_id, $term_ids, $attribute_slug);
                    }
                }

                // Lưu thuộc tính vào meta key `_product_attributes`
                update_post_meta($product_id, '_product_attributes', $product_attributes);

                // Làm mới bộ nhớ đệm của sản phẩm
                wc_delete_product_transients($product_id);
            }

            if (!empty($product_data['variations'])) {
                $this->import_product_with_variations($product_data, $product_id);
            } else {
                $this->import_product_without_variations($product_data, $product_id);
            }

            // Commit transaction nếu không có lỗi
            $wpdb->query('COMMIT');

            return [
                'success'    => true,
                'message'    => 'Product created successfully',
                'product_id' => $product_id,
                //                 'product' => $product_data,
            ];
        } catch (Exception $e) {
            // Rollback transaction nếu có lỗi
            $wpdb->query('ROLLBACK');

            return [
                'success' => false,
                'message' => $e->getMessage(),
                'product' => $product_data,
            ];
        }
    }

    public function sync_products_job()
    {
        $logger = wc_get_logger();
        $logger->log("========== Start create product Art Fusion ==========", '');
        $limit = 1;
        $offset = 0;

        // Get the list of unsynchronized products from system A
        $api_url = CPI_Common::get_setting_api_url() . "wp-json/api/v1/get-products?utm_source=" . CPI_Common::get_setting_utm_source() . "&limit={$limit}&offset={$offset}";
        $response = wp_remote_get($api_url, array(
            'headers' => CPI_Common::get_header_authenticator()
        ));

        if (is_wp_error($response)) {
            error_log('Error fetching products: ' . $response->get_error_message());
            return;
        }

        $products = json_decode(wp_remote_retrieve_body($response), true)['products'];

        $synced_product_ids = [];

        foreach ($products as $product_data) {
            // Test and create products on system B
            $this->create_product_by_dwd($product_data);
            $synced_product_ids[] = $product_data['id'];
        }

        $logger->log("Product id create " . json_encode($synced_product_ids), 'debug');

        // Update product flags that have been synchronized on system A
        if (!empty($synced_product_ids)) {
            wp_remote_post(CPI_Common::get_setting_api_url() . "wp-json/api/v1/update-sync-flag", array(
                'body' => json_encode(array('product_ids' => $synced_product_ids)),
                'headers' => CPI_Common::get_header_authenticator()
            ));
        }

        $logger->log("========== End create product Art Fusion ==========\n", '');
    }

    public static function get_categories()
    {
        // get categories
        $api_url = CPI_Common::get_setting_api_url() . "wp-json/api/v1/get-categories";
        $response = wp_remote_get($api_url, array(
            'headers' => CPI_Common::get_header_authenticator()
        ));

        if (is_wp_error($response)) {
            error_log('Error fetching products: ' . $response->get_error_message());
            return;
        }

        $categories = json_decode(wp_remote_retrieve_body($response), true);
        $html  = '';
        if ($categories) {
            foreach ($categories as $category) {
                if ($category['parent_id'] == 0) {
                    $html .= "<option value='" . $category['slug'] . "'>" . $category['name'] . "</option>";
                }
                foreach ($categories as $category2) {
                    if ($category2['parent_id'] == $category['id']) {
                        $html .= "<option value='" . $category2['slug'] . "'>--" . $category2['name'] . "</option>";
                    }
                }
            }
        }
        return $html;
    }

    public static function get_users()
    {
        // get author
        $api_url = CPI_Common::get_setting_api_url() . "wp-json/api/v1/get-users?utm_source=" . CPI_Common::get_setting_utm_source();
        $response = wp_remote_get($api_url, array(
            'headers' => CPI_Common::get_header_authenticator()
        ));

        if (is_wp_error($response)) {
            error_log('Error fetching products: ' . $response->get_error_message());
            return;
        }

        $users = json_decode(wp_remote_retrieve_body($response), true);
        $html  = '<option value="">Select author</option>';
        if ($users) {
            foreach ($users as $user) {
                $html .= "<option value='" . $user['user_nicename'] . "'>" . $user['name'] . "</option>";
            }
        }
        return $html;
    }

    public function get_products_callback()
    {
        $params = $_GET;
        $params['utm_source'] = CPI_Common::get_setting_utm_source();
        $params['product_cat'] = $params['product_cat'] ?? '';
        unset($params['action']);
        $api_url = add_query_arg($params, CPI_Common::get_setting_api_url() . "wp-json/api/v1/get-products");
        $response = wp_remote_get($api_url, array(
            'headers' => CPI_Common::get_header_authenticator(),
            'timeout' => 20
        ));

        if (is_wp_error($response)) {
            error_log('Error fetching products: ' . $response->get_error_message());
            return;
        }

        $datas = json_decode(wp_remote_retrieve_body($response), true);
        if (!empty($datas['products'])) {
            $store_currency = get_option('woocommerce_currency', 'USD');
            $rate = $this->get_currency_rate($store_currency, CPI_Common::get_setting('cpi_currency'));
            foreach ($datas['products'] as $key => $product) {
                $price_convert = $this->convert_price($product['price'], $rate);
                $datas['products'][$key]['price_convert'] = $price_convert;
                $datas['products'][$key]['CS_currency_convert'] = CPI_Common::get_setting('cpi_currency');
            }
        }

        wp_send_json_success($datas);
    }

    public function get_currency_rate($base_currency, $target_currency)
    {
        $req_url = "https://open.er-api.com/v6/latest/" . $base_currency;
        $response_json = file_get_contents($req_url);
        if (false !== $response_json) {
            try {
                $response = json_decode($response_json);
                if ('success' === $response->result && property_exists($response->rates, $target_currency)) {
                    $new_rate = $response->rates->$target_currency;
                    return $new_rate;
                }
            } catch (Exception $e) {
                throw $e;
            }
        }
    }

    public function convert_price($price, $rate)
    {
        if (!$price) return '';
        return round($price * $rate, 2);
    }

    public function remove_uncategorized_category($product_id)
    {
        $uncategorized_term = term_exists('Uncategorized', 'product_cat');

        if ($uncategorized_term) {
            wp_remove_object_terms($product_id, intval($uncategorized_term['term_id']), 'product_cat');
        }
    }

    public function call_website_mark_imported($product_ids = [])
    {
        $logger = wc_get_logger();
        $response = wp_remote_post(CPI_Common::get_setting_api_url() . "wp-json/api/v1/mark-imported", array(
            'body' => json_encode([
                'product_ids' => $product_ids,
                'imported_by' => CPI_Common::get_setting_utm_source()
            ]),
            'headers' => CPI_Common::get_header_authenticator()
        ));

        if (is_wp_error($response)) {
            $logger->log("Mark Imported dwd errors: " . json_encode($product_ids), '');
            return false;
        }

        if (!empty($product_ids)) {
            foreach ($product_ids as $product_id) {
                $today = current_time('Y-m-d');
                update_post_meta($product_id, '_update_mark_imported_sync_date', $today);
                update_post_meta($product_id, '_update_mark_imported_sync_date_status', 'sccess');
            }
        }

        $logger->log("Mark Imported dwd success: " . json_encode($product_ids), '');
        return true;
    }

    public function send_api_to_site_import($order_id)
    {
        $logger = wc_get_logger();
        $order = wc_get_order($order_id);

        // Kiểm tra nếu đơn hàng đã gửi đi
        if ($order->get_meta('_sent_to_website_af') == 'yes') {
            return;
        }

        $logger->log("========== Start Order change status completed ==========", '');

        $order_data = [
            'id'           => $order_id,
            'currency'     => $order->get_currency(),
            'total'        => $order->get_total(),
            'billing_details'   => [
                'first_name' => $order->get_billing_first_name(),
                'last_name'  => $order->get_billing_last_name(),
                'email'      => $order->get_billing_email(),
                'phone'      => $order->get_billing_phone(),
                'address_1'  => $order->get_billing_address_1(),
                'address_2'  => $order->get_billing_address_2(),
                'city'       => $order->get_billing_city(),
                'postcode'   => $order->get_billing_postcode(),
                'country'    => $order->get_billing_country(),
                'state'      => $order->get_billing_state(),
            ],
            'line_items'   => [],
            'utm_source' => CPI_Common::get_setting_utm_source(),
            'referral' => CPI_Common::get_setting_utm_referrer(),
        ];

        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            if (!$product) continue;
            $sku = get_parent_sku($product->get_id());

            $dwd_product_id = $product->get_meta('_dwd_product_id', true);
            if (!$dwd_product_id) continue;

            $order_data['line_items'][] = [
                'id'         => $product->get_id(),
                '_dwd_product_id'    => $product->get_meta('_dwd_product_id', true),
                'sku' => $sku,
                'name' => $item->get_name(),
                'quantity'   => $item->get_quantity(),
                'subtotal'   => $item->get_subtotal(),
                'total'      => $item->get_total(),
            ];
        }

        if (empty($order_data['line_items'])) return;

        $response = wp_remote_post(CPI_Common::get_setting_api_url() . "wp-json/api/v1/my-create-orders", array(
            'body' => json_encode($order_data),
            'headers' => CPI_Common::get_header_authenticator(),
            'timeout' => 60
        ));

        $logger->log("Response create order" . json_encode($response), 'debug');

        if (wp_remote_retrieve_response_code($response) == 201) {
            $order->add_meta_data('_sent_to_website_af', 'yes');
            $order->add_meta_data('_order_id_on_website_af', wp_remote_retrieve_body($response));
            $order->save();
            $logger->log("========== Create Order DWD success ==========", '');
        } else {
            $logger->log("========== Create Order DWD Failed ==========", '');
        }
    }

    public function import_selected_products_callback()
    {
        $products = $_POST['products'];
        if (!$products) {
            return wp_send_json_error([
                'success' => false,
                'message' => 'Error!!!'
            ], 500);
        }

        $response_datas = [];

        foreach ($products as $key => $product) {
            $product_ids[] = $product['id'];
            $response_datas[] = $this->create_product_by_dwd($product);
            $this->call_website_mark_imported($product_ids);
        }

        wp_send_json_success($response_datas);
    }

    public function save_products_to_queue_callback()
    {
        global $wpdb;

        $products = isset($_POST['products']) ? $_POST['products'] : [];
        $user_id = get_current_user_id();

        if (empty($products) || !$user_id) {
            wp_send_json_error(['message' => 'Invalid request. Please provide valid products and ensure the user is logged in.'], 400);
        }

        $table_name = $wpdb->prefix . 'cpi_import_queue';
        $inserted_count = 0;

        foreach ($products as $product) {
            $product_data = maybe_serialize($product);

            $result = $wpdb->insert(
                $table_name,
                [
                    'user_id' => $user_id,
                    'product_data' => $product_data,
                    'status' => 'pending',
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                ],
                ['%d', '%s', '%s', '%s', '%s']
            );

            if ($result !== false) {
                $inserted_count++;
            } else {
                error_log('Failed to insert product into queue: ' . $wpdb->last_error);
            }
        }

        if ($inserted_count > 0) {
            wp_send_json_success(['message' => "$inserted_count products added to the import queue."]);
        } else {
            wp_send_json_error(['message' => 'Failed to add products to the import queue. Please try again later.'], 500);
        }
    }
}
