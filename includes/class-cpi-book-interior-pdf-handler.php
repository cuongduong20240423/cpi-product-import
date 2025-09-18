<?php

class CPI_Book_Interior_Pdf_Handler
{
    const INTERIOR_UPLOAD_DIR = '/woocommerce_uploads/lulu_prints/interiors/';
    const PAGE_COUNT_DEFAULT = 32;
    public static function init()
    {
        // Hook vào khi sản phẩm được publish
        add_action('save_post', [__CLASS__, 'handle_book_interior_pdf'], 20, 3);
        add_action('init', function () {
            add_rewrite_rule('^secure-pdf/([^/]+)/?$', 'index.php?secure_pdf_route=$matches[1]', 'top');
        });
        add_filter('query_vars', function ($vars) {
            $vars[] = 'secure_pdf_route';
            return $vars;
        });

        add_action('template_redirect', function () {
            $encoded = get_query_var('secure_pdf_route');
            if ($encoded) {
                $encoded = urldecode($encoded);
                $json = base64_decode($encoded);
                $data = json_decode($json, true);

                if (empty($data['file_url']) || empty($data['token'])) {
                    wp_die("Link not valid.");
                }

                $token = sanitize_text_field($data['token']);
                $file_url = esc_url_raw($data['file_url']);
                $is_infinite = isset($data['infinite']) ? $data['infinite'] : false;

                $transient_key = 'one_time_pdf_' . $token;
                if (!get_transient($transient_key)) {
                    wp_die("Link is in use or expired.");
                }

                if (!$is_infinite) {
                    delete_transient($transient_key);
                }

                $upload_baseurl = wp_upload_dir()['baseurl'];
                $upload_basedir = wp_upload_dir()['basedir'];

                if (strpos($file_url, $upload_baseurl) !== 0) {
                    wp_die("Invalid file path.");
                }

                $relative_path = ltrim(substr($file_url, strlen($upload_baseurl)), '/');
                $relative_path = urldecode($relative_path);
                // $relative_path = ltrim(str_replace($upload_baseurl, '', $file_url), '/');
                $full_path = $upload_basedir . '/' . $relative_path;

                if (file_exists($full_path)) {
                    header('Content-Type: application/pdf');
                    header('Content-Disposition: inline; filename="' . basename($full_path) . '"');
                    header('Content-Length: ' . filesize($full_path));
                    readfile($full_path);
                    exit;
                } else {
                    wp_die("File not found.");
                }
            }
        });
    }

    public static function handle_book_interior_pdf($post_id, $post, $update)
    {
        if ($post->post_type !== 'product') return;

        $product = wc_get_product($post_id);
        if (!$product) return;

        // Chỉ xử lý khi publish
        // if ($product->post_status !== 'publish') return;

        // Kiểm tra thuộc tính content type
        $content_type = $product->get_attribute('content-type');
        if (strtolower($content_type) !== 'interior pages') return;

        // Lấy file download
        $downloads = $product->get_downloads();
        if (empty($downloads)) {
            update_post_meta($post_id, '_cpi_book_pdf_error', 'Download file does not exist.');
            return;
        }

        if (count($downloads) > 1) {
            update_post_meta($post_id, '_cpi_book_pdf_error', 'Only 1 download file is allowed for Book Interior.');
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

            if ($page_count === 0) {
                update_post_meta($post_id, '_cpi_book_pdf_error', 'Unable to read PDF file.');
                return;
            }

            try {
                // $new_pdf_url = self::process_validate_lulu_interior_file($file_url, $post_id);
                $new_pdf_url = self::process_validate_lulu_interior_file($file_url, $post_id);
                $new_pdf_url_generated = CPI_Common::generate_one_time_pdf_link($new_pdf_url, true);

                $class_common = new CPI_Lulu_API;
                $validate_interior_id = $class_common->create_validate_interior_request($new_pdf_url_generated);
                $valid_pod_package_id = $class_common->get_valid_pod_package_ids($validate_interior_id);

                update_post_meta($post_id, '_wc_pod_package_id', $valid_pod_package_id);
                delete_post_meta($post_id, '_cpi_book_pdf_error');

                CPI_Common::process_pdf_to_images(CPI_Common::url_to_path($new_pdf_url), $post_id);

                return $valid_pod_package_id;
            } catch (\Throwable $th) {
                update_post_meta($post_id, '_cpi_book_pdf_error', 'Error processing PDF file: ' . $th->getMessage());
                return;
            }
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

    public static function process_validate_lulu_interior_file($file_url, $product_id, $page_count = self::PAGE_COUNT_DEFAULT)
    {
        if (!class_exists('setasign\Fpdi\Fpdi')) return false;
        try {
            $file_path = CPI_Common::url_to_path($file_url);

            // Đường dẫn lưu file mới
            $upload_dir = wp_upload_dir();
            $target_dir = $upload_dir['basedir'] . self::INTERIOR_UPLOAD_DIR . intval($product_id) . '/';
            if (!file_exists($target_dir)) {
                mkdir($target_dir, 0755, true);
            }
            $new_file_name = $page_count . '.pdf';
            $output_pdf = $target_dir . $new_file_name;
            $class_common = new CPI_Common();
            $class_common->create_pdf_with_page_count($file_path, $output_pdf, $page_count);

            $pdf_url = $upload_dir['baseurl'] . self::INTERIOR_UPLOAD_DIR . intval($product_id) . '/' . $new_file_name;
            return $pdf_url;
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
    }
}
