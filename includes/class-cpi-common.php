<?php
class CPI_Common
{
    public static $settings = [];
    private static $option_name = 'cpi_settings';
    public static function init()
    {
        self::$settings = get_option(self::$option_name, []);
    }

    public function __construct() {}

    public static function get_setting($key, $default = null)
    {
        return isset(self::$settings[$key]) ? self::$settings[$key] : $default;
    }

    public static function get_setting_api_url()
    {
        return self::get_setting('cpi_api_url');
    }

    public static function get_setting_utm_source()
    {
        return self::get_domain_from_url();
    }

    public static function get_setting_utm_referrer()
    {
        return home_url();
    }

    public static function get_setting_lulu_url()
    {
        return CPI_Common::get_setting('cpi_lulu_url');
    }

    public static function get_setting_lulu_client_key()
    {
        return CPI_Common::get_setting('cpi_lulu_client_key');
    }

    public static function get_setting_lulu_client_secret()
    {
        return CPI_Common::get_setting('cpi_lulu_client_secret');
    }

    public static function get_setting_lulu_package_id()
    {
        return CPI_Common::get_setting('cpi_lulu_package_id');
    }

    public static function get_setting_lulu_shipping_level()
    {
        return CPI_Common::get_setting('cpi_lulu_shipping_level');
    }

    public static function get_setting_lulu_source()
    {
        return CPI_Common::get_setting('cpi_lulu_source');
    }

    public static function get_setting_role()
    {
        return CPI_Common::get_setting('cpi_role');
    }

    public static function get_setting_taxonmy_slug_page()
    {
        return CPI_Common::get_setting('cpi_taxonomy_page');
    }

    public static function get_setting_taxonmy_slug_format()
    {
        return CPI_Common::get_setting('cpi_taxonomy_format');
    }

    public static function list_statuses()
    {
        return [
            'pending' => 'Pending',
            'completed' => 'Completed',
            'failed' => 'Failed'
        ];
    }

    public static function get_status_label($status)
    {
        $statuses = self::list_statuses();
        switch ($status) {
            case 'pending':
                return '<span class="cpi-badge cpi-pending">' . $statuses['pending'] . '</span>';
            case 'completed':
                return '<span class="cpi-badge cpi-completed">' . $statuses['completed'] . '</span>';
            case 'failed':
                return '<span class="cpi-badge cpi-failed">' . $statuses['failed'] . '</span>';
            default:
                return '';
        }
    }

    /**
     * Write log using WooCommerce logger
     *
     * @param string $message The log message.
     * @param string $level The log level (info, warning, error).
     * @param string $source The log source (default: 'custom-product-import').
     */
    public static function write_log($message, $level = 'info', $source = 'custom-product-import')
    {
        if (class_exists('WC_Logger')) {
            $logger = wc_get_logger();
            $logger->$level($message, ['source' => $source]);
        }
    }

    public static function write_log_lulu($message, $level = 'info', $source = 'lulu-validate')
    {
        if (class_exists('WC_Logger')) {
            $logger = wc_get_logger();
            $logger->$level($message, ['source' => $source]);
        }
    }

    /**
     * Summary of get_authenticator
     * @return string
     */
    public static function get_authenticator()
    {
        return "Basic " . base64_encode(self::get_setting('cpi_consumer_key') . ":" . self::get_setting('cpi_consumer_secret'));
    }

    public static function get_header_authenticator()
    {
        return [
            'Authorization' => self::get_authenticator(),
            'Content-Type' => 'application/json'
        ];
    }

    /**
     * Summary of get_domain_from_url
     * @return mixed|string
     */
    public static function get_domain_from_url()
    {
        $current_url = home_url();
        $url_parts = parse_url($current_url);
        return $url_parts['host'];
    }

    public static function url_to_path($file_url)
    {
        $upload_dir = wp_upload_dir();
        if (strpos($file_url, $upload_dir['baseurl']) === 0) {
            return str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $file_url);
        }
        return $file_url;
    }

    public function getClosestLuluSize($width_in, $height_in, $sizes)
    {
        $closest = null;
        $smallest_diff = PHP_INT_MAX;

        foreach ($sizes as $size) {
            // Kiểm tra cả 2 hướng (portrait và landscape)
            $diff_portrait = pow($size['width'] - $width_in, 2) + pow($size['height'] - $height_in, 2);
            $diff_landscape = pow($size['height'] - $width_in, 2) + pow($size['width'] - $height_in, 2);

            // Lấy độ chênh lệch nhỏ nhất giữa 2 hướng
            $current_diff = min($diff_portrait, $diff_landscape);

            if ($current_diff < $smallest_diff) {
                $smallest_diff = $current_diff;
                // Nếu landscape gần hơn thì xoay kích thước
                if ($diff_landscape < $diff_portrait) {
                    $closest = ['width' => $size['height'], 'height' => $size['width']];
                } else {
                    $closest = $size;
                }
            }
        }
        return $closest;
    }

    public function create_pdf_with_page_count($input_pdf, $output_pdf, $page_count)
    {
        try {
            // Danh sách trim size NO BLEED (inch)
            $lulu_no_bleed_sizes = [
                ['width' => 4.25, 'height' => 6.87],
                ['width' => 5.0,  'height' => 8.0],
                ['width' => 5.25, 'height' => 8.0],
                ['width' => 5.5,  'height' => 8.5],
                ['width' => 6.0,  'height' => 9.0],
                ['width' => 6.14, 'height' => 9.21],
                ['width' => 6.69, 'height' => 9.61],
                ['width' => 7.0,  'height' => 10.0],
                ['width' => 7.44, 'height' => 9.69],
                ['width' => 7.5,  'height' => 9.25],
                ['width' => 8.0,  'height' => 10.0],
                ['width' => 8.25, 'height' => 10.75],
                ['width' => 8.25, 'height' => 6.0],    // Landscape
                ['width' => 8.25, 'height' => 8.25],
                ['width' => 8.5,  'height' => 8.5],
                ['width' => 8.5,  'height' => 11.0]
            ];

            // Đọc kích thước gốc
            $pdf = new \setasign\Fpdi\Fpdi();
            $src_count = $pdf->setSourceFile($input_pdf);
            if ($src_count < 1) {
                throw new \Exception("Input PDF has no pages.");
            }

            $tplIdx = $pdf->importPage(1);
            $size = $pdf->getTemplateSize($tplIdx);
            $width_pt = $size['width'];
            $height_pt = $size['height'];

            $width_in = $width_pt / 25.4;
            $height_in = $height_pt / 25.4;

            // Lấy trim size Lulu gần nhất (đã sửa để xử lý cả landscape/portrait)
            $matched_size = $this->getClosestLuluSize($width_in, $height_in, $lulu_no_bleed_sizes);

            // Xác định hướng trang (P = portrait, L = landscape)
            $orientation = $size['orientation'];
            $target_width_pt = $matched_size['width'] * 25.4;
            $target_height_pt = $matched_size['height'] * 25.4;

            // Thêm debug để kiểm tra
            // var_dump([
            //     'original_size' => ['width' => $width_in, 'height' => $height_in],
            //     'matched_size' => $matched_size,
            //     'orientation' => $orientation
            // ]);

            // Gộp danh sách trang cần lặp lại
            $all_pages = range(1, $src_count);
            $final_pages = [];
            $loops = intval($page_count / $src_count);
            $remain = $page_count % $src_count;
            for ($i = 0; $i < $loops; $i++) {
                $final_pages = array_merge($final_pages, $all_pages);
            }
            if ($remain > 0) {
                $final_pages = array_merge($final_pages, array_slice($all_pages, 0, $remain));
            }

            // Tạo file PDF mới
            $new_pdf = new \setasign\Fpdi\Fpdi();
            $new_pdf->setSourceFile($input_pdf);

            foreach ($final_pages as $page_num) {
                $tplIdx = $new_pdf->importPage($page_num);
                $orig_size = $new_pdf->getTemplateSize($tplIdx);

                // Tính tỷ lệ scale sao cho không vượt quá khung trang
                $scale = min(
                    $target_width_pt / $orig_size['width'],
                    $target_height_pt / $orig_size['height']
                );

                $scaled_width = $orig_size['width'] * $scale;
                $scaled_height = $orig_size['height'] * $scale;

                // Tính vị trí căn giữa
                $x_offset = ($target_width_pt - $scaled_width) / 2;
                $y_offset = ($target_height_pt - $scaled_height) / 2;

                // Tạo trang mới và chèn nội dung
                $new_pdf->AddPage($orientation, [$target_width_pt, $target_height_pt]);
                $new_pdf->useTemplate($tplIdx, $x_offset, $y_offset, $scaled_width, $scaled_height);
            }

            $new_pdf->Output($output_pdf, 'F');
            return true;
        } catch (Exception $e) {
            throw new \Exception($e->getMessage());
        }
    }



    public static function generate_one_time_pdf_link($full_file_url, $is_infinite = false)
    {
        $current_domain = self::get_domain_from_url();
        $file_domain = parse_url($full_file_url, PHP_URL_HOST);

        if ($file_domain && strtolower($file_domain) !== strtolower($current_domain)) {
            // Nếu domain khác thì trả về URL gốc
            return esc_url_raw($full_file_url);
        }

        $token = wp_generate_password(12, false); // random token
        $data = [
            'file_url' => esc_url_raw($full_file_url),
            'token'    => $token,
            'infinite' => $is_infinite,
        ];
        $encoded = base64_encode(json_encode($data));

        // Lưu transient cho 1 ngày
        if (!$is_infinite) {
            set_transient('one_time_pdf_' . $token, true, DAY_IN_SECONDS);
        } else {
            set_transient('one_time_pdf_' . $token, true);
        }

        return home_url('/secure-pdf/' . urlencode($encoded));
    }

    public static function process_pdf_to_images($file_path, $product_id)
    {
        if (!class_exists('Imagick')) {
            throw new \Exception('Imagick extension is not installed.');
        }

        $upload_dir = wp_upload_dir();

        $product = wc_get_product($product_id);
        if (!$product) return false;

        $thumbnail_name = '/thumbnail_' . $product_id . '.jpg';
        $gallery_name = '/gallery_' . $product_id . '.jpg';

        // Thumbnail
        if (!$product->get_image_id()) {
            try {
                $imagick = new \Imagick();
                $imagick->setResolution(300, 300); // DPI
                $imagick->readImage($file_path . '[0]'); // Trang đầu tiên
                $imagick->setImageFormat('jpeg');
                $thumb_path = $upload_dir['path'] . $thumbnail_name;
                $imagick->writeImage($thumb_path);
                $imagick->clear();
                $imagick->destroy();

                // Thêm thumbnail vào sản phẩm
                $thumb_id = self::insert_image_to_media_library($thumb_path, $thumbnail_name, $product_id);
                set_post_thumbnail($product_id, $thumb_id);
            } catch (\Exception $e) {
                throw new \Exception('Error processing thumbnail: ' . $e->getMessage());
            }
        }

        // Gallery
        $gallery_ids = $product->get_gallery_image_ids();
        if (empty($gallery_ids)) {
            try {
                $imagick = new \Imagick();
                $imagick->setResolution(300, 300);
                $imagick->readImage($file_path . '[1]'); // Trang thứ 2
                $imagick->setImageFormat('jpeg');
                $gallery_path = $upload_dir['path'] . $gallery_name;
                $imagick->writeImage($gallery_path);
                $imagick->clear();
                $imagick->destroy();

                // Thêm gallery vào sản phẩm
                $gallery_id = self::insert_image_to_media_library($gallery_path, $gallery_name, $product_id);
                update_post_meta($product_id, '_product_image_gallery', implode(',', [$gallery_id]));
            } catch (\Exception $e) {
                throw new \Exception('Error processing gallery: ' . $e->getMessage());
            }
        }
        return true;
    }

    // Hàm hỗ trợ: insert_image_to_media_library
    public static function insert_image_to_media_library($file_path, $file_name, $product_id)
    {
        $filetype = wp_check_filetype($file_name, null);
        $attachment = [
            'post_mime_type' => $filetype['type'],
            'post_title'     => sanitize_file_name($file_name),
            'post_content'   => '',
            'post_status'    => 'inherit'
        ];
        $attach_id = wp_insert_attachment($attachment, $file_path, $product_id);
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attach_data = wp_generate_attachment_metadata($attach_id, $file_path);
        wp_update_attachment_metadata($attach_id, $attach_data);
        return $attach_id;
    }

    public function parse_lulu_code($code)
    {
        // Lấy width và height
        $width_inch = intval(substr($code, 0, 4)) / 100;
        $height_inch = intval(substr($code, 5, 4)) / 100;

        // Lấy số trang
        preg_match('/PB(\d{3})/', $code, $matches);
        $page_count = isset($matches[1]) ? intval($matches[1]) : 0;

        return [
            'width' => $width_inch,
            'height' => $height_inch,
            'page_count' => $page_count
        ];
    }

    public function create_cover_from_secure_pdfs($input_file, $lulu_book_size, $page_count, $bleed = 0.125)
    {
        // Lấy thư mục lưu file mới
        $upload_dir = wp_upload_dir();
        $cover_dir = $upload_dir['basedir'] . '/woocommerce_uploads/cover-prints/';
        if (!file_exists($cover_dir)) {
            wp_mkdir_p($cover_dir);
        }
        $unique_id = time() . '-' . wp_generate_uuid4();
        $cover_file = $cover_dir . "cover-$unique_id.pdf";
        $cover_url = $upload_dir['baseurl'] . '/woocommerce_uploads/cover-prints/cover-' . $unique_id . '.pdf';

        // Kiểm tra file tồn tại
        if (!file_exists($input_file)) {
            throw new Exception("File PDF không tồn tại: $input_file");
        }
        if (!is_readable($input_file)) {
            throw new Exception("PHP không có quyền đọc file: $input_file");
        }

        // Lấy kích thước từ lulu_book_size
        $cover_width_inch = $lulu_book_size['width'];
        $cover_height_inch = $lulu_book_size['height'];

        $paper_density = 444; // 444 trang/inch
        $spine_width_inch = ($page_count / $paper_density) + 0.06;

        // Tính toán kích thước bìa với bleed
        $cover_width_inch = ($cover_width_inch * 2) + $spine_width_inch + ($bleed * 2);
        $cover_height_inch = $cover_height_inch + ($bleed * 2);

        // Chuyển sang mm
        $cover_width_mm = $cover_width_inch * 25.4;
        $cover_height_mm = $cover_height_inch * 25.4;

        // Tạo PDF cover
        $pdf = new \setasign\Fpdi\Fpdi();
        $page_count_file = $pdf->setSourceFile($input_file);

        $coverPdf = new \setasign\Fpdi\Fpdi();
        $coverPdf->AddPage('L', [$cover_width_mm, $cover_height_mm]);
        $coverPdf->SetMargins(0, 0, 0);
        $coverPdf->SetAutoPageBreak(false, 0);

        $coverPdf->setSourceFile($input_file);

        // Vẽ mặt trước bìa (trang cuối)
        $tplIdxFront = $coverPdf->importPage($page_count_file);
        $coverPdf->useTemplate(
            $tplIdxFront,
            $bleed * 25.4,
            $bleed * 25.4,
            $lulu_book_size['width'] * 25.4,
            $lulu_book_size['height'] * 25.4
        );

        // Vẽ mặt sau bìa (trang đầu)
        try {
            $tplIdxBack = $coverPdf->importPage(1);
            $coverPdf->useTemplate(
                $tplIdxBack,
                ($bleed + $lulu_book_size['width'] + $spine_width_inch) * 25.4,
                $bleed * 25.4,
                $lulu_book_size['width'] * 25.4,
                $lulu_book_size['height'] * 25.4
            );
        } catch (Exception $e) {
            throw new Exception("Cannot import first page from PDF file: " . $e->getMessage());
        }

        $coverPdf->Output($cover_file, 'F');

        return self::generate_one_time_pdf_link($cover_url, true);
    }

    public function create_cover_file($cover_url, $pod_package_id, $page_count)
    {
        // Parse lulu book size
        $lulu_book_size = $this->parse_lulu_code($pod_package_id);
        if (!$lulu_book_size) {
            return false;
        }

        // Decode secure URLs để lấy file paths
        $cover_file_path = CPI_Common::url_to_path($cover_url);

        // Tạo cover file
        $cover_url = $this->create_cover_from_secure_pdfs($cover_file_path, $lulu_book_size, $page_count);
        return $cover_url;
    }
	
	public static function convert_currency($amount, $from_currency = 'USD', $to_currency = null)
    {
        $amount = floatval($amount);
        if ($to_currency === null) {
            $to_currency = get_option('woocommerce_currency');
        }

        if ($from_currency === $to_currency) {
            return round($amount, 2);
        }

        // Try YayCurrency helper (two possible class names in this project)
        if (class_exists('\Yay_Currency\Helpers\YayCurrencyHelper') || class_exists('YayCurrencyHelper')) {
            $Y = class_exists('\Yay_Currency\Helpers\YayCurrencyHelper') ? '\Yay_Currency\Helpers\YayCurrencyHelper' : 'YayCurrencyHelper';
            $converted_list = $Y::converted_currency();

			$from_obj = $Y::get_currency_by_currency_code($from_currency, $converted_list);
			$to_obj = $Y::get_currency_by_currency_code($to_currency, $converted_list);

			if ($from_obj && $to_obj) {
				$rate_from = $Y::get_rate_fee($from_obj);
				$rate_to = $Y::get_rate_fee($to_obj);

				if ($rate_from && $rate_from > 0) {
					return round($amount * ($rate_to / $rate_from), $to_obj['numberDecimal']);
				}
			}

			// Fallback: if calculate_price_by_currency exists try to use it
			if (method_exists($Y, 'calculate_price_by_currency')) {
				$apply = $to_obj ?: $Y::detect_current_currency();
				$converted = $Y::calculate_price_by_currency($amount, false, $apply);
				return round(floatval($converted), 2);
			}
        }

        return round($amount, 2);
    }
}
