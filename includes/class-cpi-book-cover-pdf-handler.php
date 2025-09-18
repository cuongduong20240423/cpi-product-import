<?php
class CPI_Book_Cover_Pdf_Handler
{
    const COVER_UPLOAD_DIR = '/woocommerce_uploads/lulu_prints/covers/';
    public static function init()
    {
        // Hook vào khi sản phẩm được publish
        // add_action('woocommerce_process_product_meta', [__CLASS__, 'handle_book_cover_pdf'], 20, 1);
        add_action('save_post', [__CLASS__, 'handle_book_cover_pdf'], 10, 3);

        add_action('admin_notices', [__CLASS__, 'show_admin_notice']);
        add_action('admin_head', function () {
            echo '<style>
                .e-notice--dismissible,
                .notice.is-dismissible { 
                    display: none !important; 
                }

                .notice-error-cs{
                    background-color: #d6363824;
                    color: #000;
                    font-weight: 600;
                }
            </style>';
        });

        add_filter('manage_edit-product_columns', [__CLASS__, 'add_pdf_error_column']);
        add_action('manage_product_posts_custom_column', [__CLASS__, 'show_pdf_error_column'], 10, 2);
    }

    public static function show_admin_notice()
    {
        global $post, $pagenow;
        // Chỉ hiển thị ở màn hình edit sản phẩm
        if (
            !is_admin() ||
            $pagenow !== 'post.php' ||
            !isset($post) ||
            $post->post_type !== 'product'
        ) {
            return;
        }
        $error = get_post_meta($post->ID, '_cpi_book_pdf_error', true);
        if ($error) {
            echo '<div class="notice notice-error notice-error-cs"><p>' . esc_html($error) . '</p></div>';
            // Xóa lỗi sau khi hiển thị (nếu muốn)
            // delete_post_meta($post->ID, '_cpi_book_pdf_error');
        }
    }

    public static function add_pdf_error_column($columns)
    {
        $columns['cpi_pdf_error'] = __('Error Upload', 'custom-product-import');
        return $columns;
    }

    public static function show_pdf_error_column($column, $post_id)
    {
        if ($column === 'cpi_pdf_error') {
            $error = get_post_meta($post_id, '_cpi_book_pdf_error', true);
            if ($error) {
                echo '<span style="color:red;font-weight:bold;">' . esc_html($error) . '</span>';
            } else {
                echo '<span style="color:green;">OK</span>';
            }
        }
    }

    public static function handle_book_cover_pdf($post_id, $post, $update)
    {
        if ($post->post_type !== 'product') return;
        $product = wc_get_product($post_id);
        if (!$product) return;

        // Chỉ xử lý khi publish
        // if ($product->post_status !== 'publish') return;

        // Kiểm tra thuộc tính content type
        $content_type = $product->get_attribute('content-type');
        if (strtolower($content_type) !== 'book cover') return;

        // Lấy file download
        $downloads = $product->get_downloads();
        if (empty($downloads)) {
            update_post_meta($post_id, '_cpi_book_pdf_error', 'Download file does not exist.');
            return;
        }

        if (count($downloads) > 1) {
            update_post_meta($post_id, '_cpi_book_pdf_error', 'Only 1 download file is allowed for Book cover.');
            return;
        }

        foreach ($downloads as $download) {
            $file_url = $download->get_file();
            $file_ext = strtolower(pathinfo($file_url, PATHINFO_EXTENSION));
            if ($file_ext !== 'pdf') {
                update_post_meta($post_id, '_cpi_book_pdf_error', 'File download must be a PDF.');
                return;
            }
            // Đọc số trang PDF
            $page_count = self::get_pdf_page_count($file_url);
            if ($page_count < 2) {
                // Nếu < 2 trang thì return lỗi
                // Có thể dùng WP_Error hoặc ghi chú sản phẩm
                update_post_meta($post_id, '_cpi_book_pdf_error', 'PDF file must have at least 2 pages.');
                return;
            } elseif ($page_count == 2) {
                delete_post_meta($post_id, '_cpi_book_pdf_error');
                // continue;
            } else {
                // Nếu > 2 trang thì chỉ lấy trang đầu và cuối, tạo file mới
                $file_url = self::extract_first_last_page($file_url, $post_id);
                if ($file_url) {
                    // Tạo đối tượng WC_Product_Download mới với file mới
                    $new_download = new WC_Product_Download();
                    $new_download->set_name($download->get_name());
                    $new_download->set_file($file_url);

                    // Cập nhật lại downloads cho sản phẩm
                    $product->set_downloads([$new_download]);
                    $product->save();
                    delete_post_meta($post_id, '_cpi_book_pdf_error');
                }
            }

            CPI_Common::process_pdf_to_images(CPI_Common::url_to_path($file_url), $post_id);
        }
    }

    // Hàm lấy số trang PDF (cần FPDI hoặc TCPDF)
    public static function get_pdf_page_count($file_url)
    {
        // Ví dụ với FPDI
        if (!class_exists('setasign\Fpdi\Fpdi')) return 0;
        try {
            $pdf = new \setasign\Fpdi\Fpdi();
            $file_path = CPI_Common::url_to_path($file_url);
            $page_count = $pdf->setSourceFile($file_path);
            return $page_count;
        } catch (\Exception $e) {
            return 0;
        }
    }

    // Hàm tạo file PDF mới chỉ gồm trang đầu và cuối
    public static function extract_first_last_page($file_url, $post_id)
    {
        if (!class_exists('setasign\Fpdi\Fpdi')) return false;
        try {
            $pdf = new \setasign\Fpdi\Fpdi();
            $file_path = CPI_Common::url_to_path($file_url);
            $page_count = $pdf->setSourceFile($file_path);

            // Đặt tên file mới
            $upload_dir = wp_upload_dir();
            $target_dir = $upload_dir['basedir'] . self::COVER_UPLOAD_DIR . intval($post_id) . '/';
            if (!file_exists($target_dir)) {
                mkdir($target_dir, 0755, true);
            }
            $new_file_name = 'cover.pdf';
            $new_file_path = $target_dir . $new_file_name;

            // Trang đầu
            $tplIdx = $pdf->importPage(1);
            $size = $pdf->getTemplateSize($tplIdx);
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($tplIdx);

            // Trang cuối
            $tplIdx = $pdf->importPage($page_count);
            $size = $pdf->getTemplateSize($tplIdx);
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($tplIdx);

            // Lưu file PDF mới
            $pdf->Output($new_file_path, 'F');

            $new_file_url = $upload_dir['baseurl'] . self::COVER_UPLOAD_DIR . intval($post_id) . '/' . $new_file_name;
            return $new_file_url;
        } catch (\Exception $e) {
            return false;
        }
    }
}
