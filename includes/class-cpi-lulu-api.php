<?php

class CPI_Lulu_API
{
    public static function init()
    {
        // Add hooks or initialization logic for Lulu API
    }

    public function __construct()
    {
        // Add actions and filters
        add_action('woocommerce_product_after_variable_attributes', [$this, 'add_custom_fields_to_variations'], 10, 3);
        add_action('admin_footer', [$this, 'add_custom_media_modal_script']);
        add_action('woocommerce_save_product_variation', [$this, 'save_custom_fields_to_variations'], 10, 2);
        add_action('wp_ajax_get_printing_cost_by_product_simple', [$this, 'get_printing_cost_by_product_simple']);
        add_action('wp_ajax_nopriv_get_printing_cost_by_product_simple', [$this, 'get_printing_cost_by_product_simple']);
        add_action('wp_footer', [$this, 'add_custom_price_update_script']);
        add_action('woocommerce_before_calculate_totals', [$this, 'update_cart_item_prices'], 10, 1);
        add_action('woocommerce_before_mini_cart_contents', [$this, 'recalculate_printing_costs_for_mini_cart']);
        add_action('woocommerce_checkout_update_order_review', [$this, 'recalculate_shipping_costs'], 10, 1);
        add_action('woocommerce_cart_calculate_fees', [$this, 'add_lulu_shipping_cost']);
        add_action('woocommerce_order_actions', [$this, 'add_send_order_to_lulu_action']);
        add_action('add_meta_boxes_shop_order', [$this, 'add_lulu_tracking_meta_box']);
        add_action('woocommerce_order_action_send_order_to_lulu', [$this, 'process_send_order_to_lulu_action']);
        add_action('woocommerce_after_checkout_validation', [$this, 'validate_lulu_shipping'], 10, 2);
        add_filter('woocommerce_before_mini_cart', [$this, 'update_cart_item_prices_before_mini_cart']);
        add_filter('woocommerce_package_rates', [$this, 'replace_free_shipping_with_lulu'], 10, 2);
        add_filter('woocommerce_checkout_fields', [$this, 'add_shipping_phone_checkout_field']);
        add_filter('woocommerce_cart_shipping_method_full_label', [$this, 'add_lulu_shipping_method_full_label'], 10, 2);
    }

    public function create_validate_interior_request($source_url)
    {
        $access_token = $this->get_lulu_access_token();
        if (!$access_token) {
            return false;
        }

        $data = array(
            'source_url' => $source_url,
        );

        $response = wp_remote_post(CPI_Common::get_setting_lulu_url() . 'validate-interior', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode($data),
            'timeout' => 10,
        ));

        if (is_wp_error($response)) {
            throw new Exception('Error creating validate interior request: ' . $response->get_error_message());
        } else {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (isset($body['id'])) {
                return $body['id'];
            } else {
                throw new Exception('Error creating validate interior request: ' . print_r($body, true));
            }
        }
    }

    public function get_valid_pod_package_ids($validate_interior_id)
    {
        $access_token = $this->get_lulu_access_token();
        if (!$access_token) {
            return false;
        }

        $max_attempts = 30; // Số lần thử tối đa (30 lần, tương đương với 1 phút)
        $attempts = 0;

        while ($attempts < $max_attempts) {
            $response = wp_remote_get(CPI_Common::get_setting_lulu_url() . 'validate-interior/' . $validate_interior_id, array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $access_token,
                    'Content-Type' => 'application/json',
                ),
                'timeout' => 10,
            ));

            if (is_wp_error($response)) {
                throw new Exception('Error getting validate interior details: ' . $response->get_error_message());
            } else {
                $body = json_decode(wp_remote_retrieve_body($response), true);
                if (isset($body['status']) && $body['status'] === 'VALIDATED') {
                    if (isset($body['valid_pod_package_ids']) && !empty($body['valid_pod_package_ids'])) {
                        return substr($body['valid_pod_package_ids'][0], 0, 9) . CPI_Common::get_setting_lulu_package_id();
                    } else {
                        throw new Exception('Error getting validate interior details: No valid POD package IDs found');
                    }
                } elseif (isset($body['status']) && $body['status'] === 'ERROR') {
                    throw new Exception('Validation failed: ' . implode(', ', $body['errors']));
                }
            }

            $attempts++;
            sleep(2); // Chờ 2 giây trước khi thử lại
        }
        throw new Exception('Error getting validate interior details: Maximum attempts reached');
    }

    public function get_first_valid_pod_package_id($source_url)
    {
        try {
            $validate_interior_id = $this->create_validate_interior_request($source_url);
            $valid_pod_package_id = $this->get_valid_pod_package_ids($validate_interior_id);
            return $valid_pod_package_id;
        } catch (Exception $e) {
            throw new Exception('Error getting first valid POD package ID: ' . $e->getMessage());
        }
    }


    /**
     * Add custom fields to variations admin
     */
    public function add_custom_fields_to_variations($loop, $variation_data, $variation)
    {
        $variation_id = $variation->ID;
        $cover_url = get_post_meta($variation_id, '_wc_variation_file_cover_url', true);
        $interior_url = get_post_meta($variation_id, '_wc_variation_file_interior_url', true);
        $pod_package_id = get_post_meta($variation_id, '_wc_pod_package_id', true);
?>
        <div class="form-row form-row-full downloadable_files downloadable_file_cover">
            <table class="widefat">
                <thead>
                    <tr>
                        <th colspan="2"><strong>File Cover URL(PDF)</strong></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="file_url">
                            <input type="text" class="input_text" placeholder="<?php esc_attr_e('http://', 'woocommerce'); ?>" name="_wc_variation_file_cover_url[<?php echo esc_attr($variation_id); ?>]" value="<?php echo esc_attr($cover_url); ?>" />
                        </td>
                        <td class="file_url_choose" width="1%"><a href="#" class="button upload_file_button2" data-choose="<?php esc_attr_e('Choose file', 'woocommerce'); ?>" data-update="<?php esc_attr_e('Insert file URL', 'woocommerce'); ?>"><?php esc_html_e('Choose file', 'woocommerce'); ?></a></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="form-row form-row-full downloadable_files downloadable_file_interior">
            <table class="widefat">
                <thead>
                    <tr>
                        <th colspan="2"><strong>File Interior URL(PDF)</strong></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="file_url">
                            <input type="text" class="input_text" placeholder="<?php esc_attr_e('http://', 'woocommerce'); ?>" name="_wc_variation_file_interior_url[<?php echo esc_attr($variation_id); ?>]" value="<?php echo esc_attr($interior_url); ?>" />
                        </td>
                        <td class="file_url_choose" width="1%"><a href="#" class="button upload_file_button2" data-choose="<?php esc_attr_e('Choose file', 'woocommerce'); ?>" data-update="<?php esc_attr_e('Insert file URL', 'woocommerce'); ?>"><?php esc_html_e('Choose file', 'woocommerce'); ?></a></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="form-row form-row-full downloadable_files downloadable_file_interior">
            <table class="widefat">
                <thead>
                    <tr>
                        <th colspan="1"><strong>Pod Package id</strong></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="file_package_id">
                            <input type="text" class="input_text" placeholder="<?php esc_attr_e('', 'woocommerce'); ?>" name="_wc_pod_package_id[<?php echo esc_attr($variation_id); ?>]" value="<?php echo esc_attr($pod_package_id); ?>" />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    <?php
    }

    public function add_custom_media_modal_script()
    {
    ?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                // Uploading files.
                var downloadable_file_frame_custom;
                var file_path_field_custom;

                $(document.body).on('click', '.upload_file_button2', function(event) {
                    var $el = $(this);

                    file_path_field_custom = $el.closest('tr').find('td.file_url input');

                    event.preventDefault();

                    // If the media frame already exists, reopen it.
                    if (downloadable_file_frame_custom) {
                        downloadable_file_frame_custom.open();
                        return;
                    }

                    var downloadable_file_states_custom = [
                        // Main states.
                        new wp.media.controller.Library({
                            library: wp.media.query({
                                type: 'application/pdf'
                            }),
                            multiple: false,
                            title: $el.data('choose'),
                            priority: 20,
                            filterable: 'uploaded',
                        }),
                    ];

                    // Create the media frame.
                    downloadable_file_frame_custom = wp.media.frames.downloadable_file = wp.media({
                        // Set the title of the modal.
                        title: $el.data('choose') || 'Chọn file PDF',
                        button: {
                            text: $el.data('update') || 'Chọn PDF'
                        },
                        library: {
                            type: 'application/pdf',
                        },
                        multiple: false,
                        states: downloadable_file_states_custom,
                    });

                    // When an image is selected, run a callback.
                    downloadable_file_frame_custom.on('select', function() {
                        var file_path = '';
                        var selection = downloadable_file_frame_custom.state().get('selection');

                        selection.map(function(attachment) {
                            attachment = attachment.toJSON();
                            if (attachment.url) {
                                file_path = attachment.url;
                            }
                        });

                        file_path_field_custom.val(file_path).trigger('change');
                    });

                    // Set post to 0 and set our custom type.
                    downloadable_file_frame_custom.on('ready', function() {
                        downloadable_file_frame_custom.uploader.options.uploader.params = {
                            type: 'downloadable_product',
                        };
                    });

                    // Finally, open the modal.
                    downloadable_file_frame_custom.open();
                });

                function cs_load_file_lulu_and_download($_this) {
                    let format = $_this.val();
                    console.log(format)
                    if (format == 'print') {
                        $_this.closest('.woocommerce_variation').find('.variable_is_downloadable').prop("checked", false);
                        $_this.closest('.woocommerce_variation').find('.show_if_variation_downloadable').hide();
                        $_this.closest('.woocommerce_variation').find('.downloadable_file_cover').show();
                        $_this.closest('.woocommerce_variation').find('.downloadable_file_interior').show();
                    } else {
                        $_this.closest('.woocommerce_variation').find('.variable_is_downloadable').prop("checked", true);
                        $_this.closest('.woocommerce_variation').find('.show_if_variation_downloadable').show();
                        $_this.closest('.woocommerce_variation').find('.downloadable_file_cover').hide();
                        $_this.closest('.woocommerce_variation').find('.downloadable_file_interior').hide();
                    }
                }

                $('#woocommerce-product-data').on('woocommerce_variations_loaded', function() {
                    $('#woocommerce-product-data .woocommerce_variation select[name*="attribute_pa_format["]').each(function() {
                        cs_load_file_lulu_and_download($(this));
                    })
                });
                $(document.body).on('change', '#woocommerce-product-data .woocommerce_variation select[name*="attribute_pa_format["]', function() {
                    cs_load_file_lulu_and_download($(this));
                });
            });
        </script>
        <?php
    }


    /**
     * Save custom fields to variations admin
     */
    public function save_custom_fields_to_variations($variation_id, $i)
    {
        try {
            if (isset($_POST['_wc_variation_file_cover_url'][$variation_id])) {
                $cover_url = sanitize_text_field($_POST['_wc_variation_file_cover_url'][$variation_id]);
                update_post_meta($variation_id, '_wc_variation_file_cover_url', $cover_url);
            }

            if (isset($_POST['_wc_variation_file_interior_url'][$variation_id])) {
                $interior_url = sanitize_text_field($_POST['_wc_variation_file_interior_url'][$variation_id]);
                update_post_meta($variation_id, '_wc_variation_file_interior_url', $interior_url);
            }

            if (isset($_POST['_wc_pod_package_id'][$variation_id])) {
                $interior_url = sanitize_text_field($_POST['_wc_pod_package_id'][$variation_id]);
                update_post_meta($variation_id, '_wc_pod_package_id', $interior_url);
            }

            $format = get_post_meta($variation_id, 'attribute_pa_format', true);
            $number_of_pages = get_post_meta($variation_id, 'attribute_pa_number-of-pages', true);

            if (strtolower($format) === 'print' && intval($number_of_pages) >= 32) {
                $interior_url = get_post_meta($variation_id, '_wc_variation_file_interior_url', true);
                $pod_package_id = get_post_meta($variation_id, '_wc_pod_package_id', true);
                if ($interior_url && !$pod_package_id) {
                    $pod_package_id = $this->get_first_valid_pod_package_id($interior_url);
                    update_post_meta($variation_id, '_wc_pod_package_id', $pod_package_id);
                } else {
                    throw new Exception('Interior URL not found for variation ID: ' . $variation_id);
                }
            }
        } catch (Exception $e) {
            CPI_Common::write_log_lulu('Error saving custom fields for variation ID ' . $variation_id . ': ' . $e->getMessage(), 'error');
            throw new Exception('Error saving custom fields for variation ID ' . $variation_id . ': ' . $e->getMessage());
        }
    }

    /**
     * Summary of get_lulu_access_token
     */
    public function get_lulu_access_token()
    {
        $response = wp_remote_post(CPI_Common::get_setting_lulu_url() . 'auth/realms/glasstree/protocol/openid-connect/token', array(
            'body' => array(
                'grant_type' => 'client_credentials',
                'client_id' => CPI_Common::get_setting_lulu_client_key(),
                'client_secret' => CPI_Common::get_setting_lulu_client_secret(),
            ),
        ));

        if (is_wp_error($response)) {
            error_log('Error getting Lulu access token: ' . $response->get_error_message());
            return false;
        } else {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (isset($body['access_token'])) {
                return $body['access_token'];
            } else {
                error_log('Error getting Lulu access token: ' . print_r($body, true));
                return false;
            }
        }
    }

    /**
     * Summary of get_printing_cost
     */
    public function get_printing_cost()
    {
        $variation_id = intval($_POST['variation_id']);
        $number_of_pages = intval($_POST['page_number']);
        $package_id = get_post_meta($variation_id, '_wc_pod_package_id', true);
        if (!$package_id) {
            wp_send_json_error(array('message' => 'Error getting printing cost from Lulu: Package ID not found'));
        }

        $access_token = $this->get_lulu_access_token();

        $data = [
            'line_items' => [
                [
                    'page_count' => $number_of_pages,
                    'quantity' => 1,
                    'pod_package_id' => $package_id,
                ]
            ],
            'shipping_address' => [
                "city" => "null",
                "country_code" => "SG",
                "postcode" => "546080",
                "state_code" => null,
                "street1" => "normal",
                "phone_number" => "00000000"
            ],
            'shipping_level' => CPI_Common::get_setting_lulu_shipping_level(),
        ];

        $response = wp_remote_post(CPI_Common::get_setting_lulu_url() . 'print-job-cost-calculations', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode($data),
            'timeout' => 10,
        ));

        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => 'Error getting printing cost from Lulu: ' . $response->get_error_message()));
        } else {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (isset($body['line_item_costs'][0]['total_cost_incl_tax'])) {
                wp_send_json_success(array('printing_cost' => $body['line_item_costs'][0]['total_cost_incl_tax']));
            } else {
                wp_send_json_error(array('message' => 'Error getting printing cost from Lulu: ' . print_r($body, true)));
            }
        }
    }

    public function get_printing_cost_by_product_simple()
    {
        $cover_product_id = intval($_POST['cover_product_id']);
        $interior_product_id = intval($_POST['interior_product_id']);
        $number_of_pages = intval($_POST['page_number']);
        $package_id = get_post_meta($interior_product_id, '_wc_pod_package_id', true);
        if (!$package_id) {
            wp_send_json_error(array('message' => 'Error getting printing cost from Lulu: Package ID not found'));
        }

        $access_token = $this->get_lulu_access_token();

        $data = [
            'line_items' => [
                [
                    'page_count' => $number_of_pages,
                    'quantity' => 1,
                    'pod_package_id' => $package_id,
                ]
            ],
            'shipping_address' => [
                "city" => "null",
                "country_code" => "SG",
                "postcode" => "546080",
                "state_code" => null,
                "street1" => "normal",
                "phone_number" => "00000000"
            ],
            'shipping_level' => CPI_Common::get_setting_lulu_shipping_level(),
        ];

        $response = wp_remote_post(CPI_Common::get_setting_lulu_url() . 'print-job-cost-calculations', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode($data),
            'timeout' => 10,
        ));

        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => 'Error getting printing cost from Lulu: ' . $response->get_error_message()));
        } else {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (isset($body['line_item_costs'][0]['total_cost_incl_tax'])) {
				$total_cost = floatval($body['line_item_costs'][0]['total_cost_incl_tax']);
				
				if (class_exists('\Yay_Currency\Helpers\YayCurrencyHelper')) {
					$apply_currency = \Yay_Currency\Helpers\YayCurrencyHelper::detect_current_currency();
					$total_cost = CPI_Common::convert_currency($total_cost, 'USD', $apply_currency['currency']);
				}
				
                wp_send_json_success(array('printing_cost' => $total_cost));
            } else {
                wp_send_json_error(array('message' => 'Error getting printing cost from Lulu: ' . print_r($body, true)));
            }
        }
    }

    /**
     * Summary of get_printing_cost
     */
    public function add_custom_price_update_script()
    {
        if (is_product()) {
        ?>
            <style>
                .woocommerce div.product form.cart {
                    position: relative;
                }
            </style>
            <script type="text/javascript">
                var cs_html_loading = '<div class="blockUI blockOverlay" style="z-index: 1000; border: none; margin: 0px; padding: 0px; width: 100%; height: 100%; top: 0px; left: 0px; background: rgb(255, 255, 255); opacity: 0.6; cursor: wait; position: absolute;"></div>';
                jQuery(document).ready(function($) {
                    function updatePrice() {
                        var format = $('select[name="attribute_pa_format"]').val();
                        var numberOfPages = $('select[name="attribute_pa_number-of-pages"]').val();
                        $('.single_variation_wrap').addClass('hide');

                        if (format === 'print' && numberOfPages) {
                            var list_variations = $(".variations_form").data("product_variations");
                            var variation_id = '';
                            list_variations.forEach(element => {
                                if (element.attributes['attribute_pa_format'] == format && element.attributes['attribute_pa_number-of-pages'] == numberOfPages) {
                                    variation_id = element.variation_id;
                                    return;
                                }
                            });

                            $('.woocommerce div.product form.cart').append(cs_html_loading);
                            $.ajax({
                                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                                type: 'POST',
                                data: {
                                    action: 'get_printing_cost',
                                    variation_id: variation_id,
                                    page_number: numberOfPages
                                },
                                success: function(response) {
                                    if (response.success) {
                                        var originalPriceText = $('.single_variation_wrap .woocommerce-Price-amount.amount').text();
                                        var originalPrice = parseFloat(originalPriceText.replace(/[^0-9.-]+/g, ""));
                                        var currencySymbol = $('.single_variation_wrap .woocommerce-Price-currencySymbol').text();

                                        var printingCost = parseFloat(response.data.printing_cost);
                                        var newPrice = originalPrice + printingCost;
                                        $('.single_variation_wrap .woocommerce-Price-amount.amount').html('<bdi><span class="woocommerce-Price-currencySymbol">' + currencySymbol + '</span>' + newPrice.toFixed(2) + '</bdi>');
                                    }
                                    $('.single_variation_wrap').removeClass('hide');
                                    $('.woocommerce div.product form.cart').find('.blockUI').remove();
                                }
                            });
                        } else {
                            $('.single_variation_wrap').removeClass('hide');
                        }
                    }

                    $('select[name="attribute_pa_format"], select[name="attribute_pa_number-of-pages"], select[name="attribute_pa_interior-page"]').change(function() {
                        updatePrice();
                    });

                    updatePrice();
                });
            </script>
            <?php
        }
    }

    /**
     * Summary of update_cart_item_prices
     * @param mixed $cart
     */
    public function update_cart_item_prices($cart)
    {
        // Collect all products with the format print and number_of_pages selected
        $products = [];
        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            $product = $cart_item['data'];
            $format = $cart_item['build_book_format'] ?? '';
            $number_of_pages = $cart_item['build_book_page_count'] ?? 0;

            if ($format === 'print' && $number_of_pages > 0) {
				$base_price = $product->get_price();
				if ( class_exists('\Yay_Currency\Helpers\YayCurrencyHelper') ) {
					//$apply_currency = \Yay_Currency\Helpers\YayCurrencyHelper::detect_current_currency();
					//$base_price = apply_filters( 'yay_currency_revert_price', $product->get_price(), $apply_currency );
					$base_price = get_post_meta( $product->get_id(), '_price', true );
				}

                $products[] = [
                    'cart_item_key' => $cart_item_key,
                    'product_id' => $product->get_id(),
                    'number_of_pages' => $number_of_pages,
                    'original_price' => floatval($base_price)
                ];
            }
        }

        if (!empty($products)) {
            // Send a single API request to Lulu to calculate printing costs for all of these products
            $printing_costs = $this->get_printing_costs_from_lulu($products);

            if ($printing_costs !== false) {
                // Update product prices in the cart based on results from the API
                foreach ($products as $product) {
                    $cart_item_key = $product['cart_item_key'];
                    $original_price = $product['original_price'];
                    $printing_cost = $printing_costs[$product['product_id']] ?? 0;
                    $new_price = $original_price + $printing_cost;

                    if (isset($cart->cart_contents[$cart_item_key])) {
						$cart->cart_contents[$cart_item_key]['data']->set_price($new_price);
					}
                }
            }
        }
    }

    /**
     * Summary of recalculate_printing_costs_for_mini_cart
     */
    public function recalculate_printing_costs_for_mini_cart()
    {
        $cart = WC()->cart;
        $products = [];

        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {

            $product = $cart_item['data'];
            $format = $cart_item['build_book_format'] ?? '';
            $number_of_pages = $cart_item['build_book_page_count'] ?? 0;

            if ($format === 'print' && $number_of_pages > 0) {
                $products[] = [
                    'cart_item_key' => $cart_item_key,
                    'product_id' => $product->get_id(),
                    'number_of_pages' => $number_of_pages,
                    'original_price' => floatval($product->get_price())
                ];
            }
        }

        if (!empty($products)) {
            // Send a single API request to Lulu to calculate printing costs for all of these products
            $printing_costs = $this->get_printing_costs_from_lulu($products);

            if ($printing_costs !== false) {
                // Update product prices in the cart based on results from the API
                foreach ($products as $product) {
                    $cart_item_key = $product['cart_item_key'];
                    $original_price = $product['original_price'];
                    $printing_cost = $printing_costs[$product['product_id']] ?? 0;
                    $new_price = $original_price + $printing_cost;

                    $cart->get_cart()[$cart_item_key]['data']->set_price($new_price);
                }
            }
        }
    }

    public function get_printing_costs_from_lulu($products)
    {
        $access_token = $this->get_lulu_access_token();

        if (!$access_token) {
            return false;
        }

        $line_items = [];
        foreach ($products as $product) {
            $pod_package_id = get_post_meta($product['product_id'], '_wc_pod_package_id', true);
            $line_items[] = [
                'page_count' => intval($product['number_of_pages']),
                'quantity' => 1,
                'pod_package_id' => $pod_package_id,
            ];
        }

        $data = [
            'line_items' => $line_items,
            'shipping_address' => [
                "city" => "null",
                "country_code" => "SG",
                "postcode" => "546080",
                "state_code" => null,
                "street1" => "normal",
                "phone_number" => "00000000"
            ],
            'shipping_level' => CPI_Common::get_setting_lulu_shipping_level(),
        ];

        $response = wp_remote_post(CPI_Common::get_setting_lulu_url() . 'print-job-cost-calculations', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode($data),
            'timeout' => 10,
        ));

        if (is_wp_error($response)) {
            return false;
        } else {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (isset($body['line_item_costs'])) {
                $printing_costs = [];
				
				$target_currency = get_option( 'woocommerce_currency' );
				
                foreach ($body['line_item_costs'] as $index => $cost) {
					$total_cost = floatval($cost['total_cost_incl_tax']);
					if (class_exists('\Yay_Currency\Helpers\YayCurrencyHelper')) {
						$total_cost = CPI_Common::convert_currency($total_cost, 'USD', $target_currency);
					}

                    $printing_costs[$products[$index]['product_id']] = floatval($total_cost);
                }
                return $printing_costs;
            } else {
                return false;
            }
        }
    }
    public function update_cart_item_prices_before_mini_cart()
    {
        $products = [];
        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {

            $product = $cart_item['data'];
            $format = $cart_item['build_book_format'] ?? '';
            $number_of_pages = $cart_item['build_book_page_count'] ?? 0;

            if ($format === 'print' && $number_of_pages > 0) {
                $products[] = [
                    'cart_item_key' => $cart_item_key,
                    'product_id' => $product->get_id(),
                    'number_of_pages' => $number_of_pages,
                    'original_price' => floatval($product->get_price())
                ];
            }
        }

        if (!empty($products)) {

            $printing_costs = $this->get_printing_costs_from_lulu($products);

            if ($printing_costs !== false) {
                foreach ($products as $product) {
                    $cart_item_key = $product['cart_item_key'];
                    $original_price = $product['original_price'];
                    $printing_cost = $printing_costs[$product['product_id']] ?? 0;
                    $new_price = $original_price + $printing_cost;

                    WC()->cart->get_cart()[$cart_item_key]['data']->set_price($new_price);
                }
            }
        }
    }

    /**
     * Summary of recalculate_shipping_costs
     */
    public function recalculate_shipping_costs($posted_data)
    {
        parse_str($posted_data, $output);

        // Collect all products with format print
        $products = [];
        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            $product = $cart_item['data'];
            $format = $cart_item['build_book_format'] ?? '';
            $number_of_pages = $cart_item['build_book_page_count'] ?? 0;

            if ($format === 'print' && $number_of_pages > 0) {
                $products[] = [
                    'cart_item_key' => $cart_item_key,
                    'product_id' => $product->get_id(),
                    'number_of_pages' => $number_of_pages,
                    'quantity' => $cart_item['quantity']
                ];
            }
        }

        WC()->session->set('lulu_shipping_cost', 0);
        WC()->session->set('fulfillment_cost', 0);

        if (!empty($products)) {

            $shipping_address = [];
            if (isset($output['ship_to_different_address']) && $output['ship_to_different_address'] == 1) {
                $shipping_address = [
                    "city" => $output['shipping_city'],
                    "country_code" => $output['shipping_country'],
                    "postcode" => $output['shipping_postcode'],
                    "state_code" => $output['shipping_state'],
                    "street1" => $output['shipping_address_1'],
                    "phone_number" => $output['shipping_phone']
                ];
            } else {
                $shipping_address = [
                    "city" => $output['billing_city'],
                    "country_code" => $output['billing_country'],
                    "postcode" => $output['billing_postcode'],
                    "state_code" => $output['billing_state'],
                    "street1" => $output['billing_address_1'],
                    "phone_number" => $output['billing_phone']
                ];
            }

            // send a single API request to Lulu to calculate shipping costs for all of these products
            $shipping_costs = $this->get_shipping_costs_from_lulu($products, $shipping_address);


            if (is_array($shipping_costs) && isset($shipping_costs['error'])) {
                // Display error message to the user
                wc_add_notice($shipping_costs['error'], 'error');
            } elseif ($shipping_costs !== false) {
                // update shipping costs in the session
                $target_currency = get_option( 'woocommerce_currency' );

                $lulu_shipping_cost = CPI_Common::convert_currency($shipping_costs['shipping_cost']['total_cost_incl_tax'], 'USD', $target_currency);
                $total_cost_excl_tax = CPI_Common::convert_currency($shipping_costs['fulfillment_cost']['total_cost_excl_tax'], 'USD', $target_currency);

                WC()->session->set('lulu_shipping_cost', floatval($lulu_shipping_cost));
                WC()->session->set('fulfillment_cost', floatval($total_cost_excl_tax));
            }
        }
    }

    public function get_shipping_costs_from_lulu($products, $shipping_address)
    {
        $access_token = $this->get_lulu_access_token();

        if (!$access_token) {
            return array('error' => 'Unable to get access token from Lulu.');
        }

        $line_items = [];
        foreach ($products as $product) {
            $product_id = $product['product_id'];
            $pod_package_id = get_post_meta($product_id, '_wc_pod_package_id', true);
            $line_items[] = [
                'page_count' => intval($product['number_of_pages']),
                'quantity' => $product['quantity'],
                'pod_package_id' => $pod_package_id,
            ];
        }

        $data = [
            'line_items' => $line_items,
            'shipping_address' => $shipping_address,
            'shipping_level' => CPI_Common::get_setting_lulu_shipping_level(),
        ];

        $response = wp_remote_post(CPI_Common::get_setting_lulu_url() . 'print-job-cost-calculations', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode($data),
            'timeout' => 10,
        ));

        if (is_wp_error($response)) {
            return array('error' => 'Error getting shipping cost from Lulu: ' . $response->get_error_message());
        } else {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (isset($body['shipping_cost']['total_cost_incl_tax'])) {
                return $body;
            } elseif (isset($body['shipping_address']['detail']['errors'])) {
                $errors = $body['shipping_address']['detail']['errors'];
                $error_messages = array_map(function ($error) {
                    return $error['message'];
                }, $errors);
                return array('error' => implode(', ', $error_messages));
            } else {
                return array('error' => 'Error getting shipping cost: ' . print_r($body, true));
            }
        }
    }

    public function add_lulu_shipping_cost()
    {
		if (is_admin() && !defined('DOING_AJAX')) return;
		
		if (is_checkout()) {
			$fulfillment_cost = WC()->session->get('fulfillment_cost');
			if ($fulfillment_cost) {
				WC()->cart->add_fee(__('Fulfillment cost', 'woocommerce'), $fulfillment_cost);
			}
		}
        
    }

    /**
     * Summary of replace_free_shipping_with_lulu
     */
    public function replace_free_shipping_with_lulu($rates, $package)
    {
        $lulu_shipping_cost = WC()->session->get('lulu_shipping_cost');
		$logger = wc_get_logger();
        $logger->log("Lulu Shipping rates: " . print_r($rates, true), 'debug');
        foreach ($rates as $rate_id => $rate) {

            if ('free_shipping' === $rate->method_id) {
                if ($lulu_shipping_cost > 0) {
                    $rates[$rate_id]->cost = $lulu_shipping_cost;
                    $rates[$rate_id]->label = '';
                }
            }
        }

        return $rates;
    }

    /**
     * Summary of add_lulu_shipping_method_full_label
     */
    public function add_lulu_shipping_method_full_label($label, $method)
    {
        if (is_cart()) return $label;
        $logger = wc_get_logger();
        $logger->log("Cost: " . json_encode($method->cost), 'debug');
        if ($method->cost > 0) {
            $cost = wc_price($method->cost);
            return $cost;
        }
        return $label;
    }

    /**
     * Summary of add_shipping_phone_checkout_field
     */
    public function add_shipping_phone_checkout_field($fields)
    {
        $fields['shipping']['shipping_phone'] = array(
            'type'        => 'text',
            'label'       => __('Shipping Phone', 'woocommerce'),
            'placeholder' => __('Phone numbe', 'woocommerce'),
            'required'    => true,
            'class'       => array('form-row-wide'),
            'clear'       => true,
        );
        return $fields;
    }

    /**
     * Summary of add_send_order_to_lulu_action
     */
    public function add_send_order_to_lulu_action($actions)
    {
        global $theorder;

        if ($this->order_has_print_product($theorder)) {
            $sent_to_lulu = $theorder->get_meta('_sent_to_lulu');
            if ($sent_to_lulu) {
                $tracking_id = $theorder->get_meta('_lulu_tracking_id');
                $detail = $this->get_lulu_print_job_details($tracking_id);
                if ($detail && $detail['status']['name'] === 'REJECTED') {
                    $actions['send_order_to_lulu'] = __('Resend Order to Lulu', 'woocommerce');
                }
            } else {
                $actions['send_order_to_lulu'] = __('Send Order to Lulu', 'woocommerce');
            }
        }

        return $actions;
    }

    public function order_has_print_product($order)
    {
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) {
                continue; // Skip if product is not found
            }
            $format = $item->get_meta('_build_book_format');
            if (strtolower($format) === 'print') {
                return true;
            }
        }
        return false;
    }

    /**
     * Summary of add_lulu_tracking_meta_box
     */
    public function add_lulu_tracking_meta_box()
    {
        global $post;

        $order = wc_get_order($post->ID);
        if ($this->order_has_print_product($order)) {
            add_meta_box(
                'lulu_tracking_meta_box',
                __('Lulu Tracking Information', 'woocommerce'),
                [$this, 'display_lulu_tracking_meta_box'],
                'shop_order',
                'normal',
                'core'
            );
        }
    }

    public function display_lulu_tracking_meta_box($post)
    {
        $tracking_id = get_post_meta($post->ID, '_lulu_tracking_id', true);
        if ($tracking_id) {
            $print_job_details = $this->get_lulu_print_job_details($tracking_id);
            if ($print_job_details) {
            ?>
                <table border="1" cellpadding="5" style="width: 100%;">
                    <thead>
                        <tr>
                            <th>Field</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Tracking ID</td>
                            <td><?php echo $print_job_details['id']; ?></td>
                        </tr>
                        <tr>
                            <td>Order ID</td>
                            <td><?php echo $print_job_details['order_id']; ?></td>
                        </tr>
                        <tr>
                            <td>Contact Email</td>
                            <td><?php echo $print_job_details['contact_email']; ?></td>
                        </tr>
                        <tr>
                            <td>Shipping Address</td>
                            <td>
                                <?php
                                foreach ($print_job_details['shipping_address'] as $key => $value) {
                                    echo "<strong>" . ucfirst(str_replace('_', ' ', $key)) . ":</strong> $value<br>";
                                }
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <td>Line Items</td>
                            <td>
                                <table border="1" cellpadding="3" style="width: 100%">
                                    <thead>
                                        <tr>
                                            <th>Title</th>
                                            <th>Quantity</th>
                                            <th>Status</th>
                                            <th>Thumbnail</th>
                                            <th>Source URL (Interior)</th>
                                            <th>Source URL (Cover)</th>
                                            <th>Cost (Excl. Tax)</th>
                                            <th>Rejection Reason</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($print_job_details['line_items'] as $item) : ?>
                                            <tr>
                                                <td><?php echo $item['title']; ?></td>
                                                <td><?php echo $item['quantity']; ?></td>
                                                <td><?php echo $item['status']['name']; ?></td>
                                                <td><img src="<?php echo $item['thumbnail_url']; ?>" width="50"></td>
                                                <td><a href="<?php echo $item['printable_normalization']['interior']['source_url']; ?>" target="_blank">Interior File</a></td>
                                                <td><a href="<?php echo $item['printable_normalization']['cover']['source_url']; ?>" target="_blank">Cover File</a></td>
                                                <td><?php echo $print_job_details['costs']['line_item_costs'][0]['total_cost_excl_tax']; ?> USD</td>
                                                <td>
                                                    <?php
                                                    if (!empty($item['status']['messages']['printable_normalization']['cover'][0])) {
                                                        echo $item['status']['messages']['printable_normalization']['cover'][0];
                                                    } else {
                                                        echo "-";
                                                    }
                                                    ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </td>
                        </tr>
                        <tr>
                            <td>Shipping Level</td>
                            <td><?php echo $print_job_details['shipping_level']; ?></td>
                        </tr>
                        <tr>
                            <td>Status</td>
                            <td><?php echo $print_job_details['status']['name']; ?> - <?php echo $print_job_details['status']['message']; ?></td>
                        </tr>
                        <tr>
                            <td>Shipping Cost</td>
                            <td><?php echo $print_job_details['costs']['shipping_cost']['total_cost_excl_tax']; ?> USD</td>
                        </tr>
                        <tr>
                            <td>Fulfillment Cost</td>
                            <td><?php echo $print_job_details['costs']['fulfillment_cost']['total_cost_excl_tax']; ?> USD</td>
                        </tr>
                        <tr>
                            <td><strong>Total Cost (Excl. Tax)</strong></td>
                            <td><strong style="color: red"><?php echo $print_job_details['costs']['total_cost_excl_tax']; ?></strong> USD</td>
                        </tr>
                    </tbody>
                </table>
<?php
            } else {
                echo '<p>' . __('Unable to retrieve print job details.', 'woocommerce') . '</p>';
            }
        } else {
            echo '<p>' . __('No tracking information available.', 'woocommerce') . '</p>';
        }
    }

    public function create_lulu_print_job($order_id)
    {
        $order = wc_get_order($order_id);
        $access_token = $this->get_lulu_access_token();
        if (!$access_token) {
            return false;
        }

        $line_items = [];

        $lulu_datas = [];

        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            $product_id = $product->get_id();
            $package_id = get_post_meta($product_id, '_wc_pod_package_id', true);

            $build_book_format = $item->get_meta('_build_book_format');
            $build_book_bundle_id = $item->get_meta('_build_book_bundle_id');
            $build_book_type = $item->get_meta('_build_book_type');
            $build_book_page_count = $item->get_meta('_build_book_page_count');

            //get file download by product id
            $downloads = $product->get_downloads();
            $file_url = '';

            if (!empty($downloads)) {
                // Lấy file download đầu tiên
                $first_download = array_values($downloads)[0];
                $file_url = $first_download->get_file();
            }

            if ($build_book_format == 'print' && $build_book_bundle_id) {
                if ($build_book_type == 'interior' && $file_url) {
                    $interior_url = CPI_Book_Interior_Pdf_Handler::process_validate_lulu_interior_file($file_url, $product_id, $build_book_page_count);
                    $file_url = CPI_Common::generate_one_time_pdf_link($interior_url, true);
                }

                $lulu_datas[$build_book_bundle_id]['is_build'] = true;
                $lulu_datas[$build_book_bundle_id]['datas']['build_book_bundle_id'] = $build_book_bundle_id;
                $lulu_datas[$build_book_bundle_id]['datas']['package_id'] = $package_id;
                $lulu_datas[$build_book_bundle_id]['datas']['quantity'] = $item->get_quantity();
                $lulu_datas[$build_book_bundle_id]['datas']['name'][] = $product->get_name();
                if ($build_book_type  == 'cover') {
                    $lulu_datas[$build_book_bundle_id]['datas']['cover_url'] = $file_url;
                } elseif ($build_book_type == 'interior') {
                    $lulu_datas[$build_book_bundle_id]['datas']['interior_url'] = $file_url;
                    $lulu_datas[$build_book_bundle_id]['datas']['build_book_page_count'] = $build_book_page_count;
                }

                continue;
            }
        }

        if ($lulu_datas) {
            foreach ($lulu_datas as $key => $lulu_data) {
                $data = $lulu_data['datas'];
                $cover_url = $data['cover_url'];
                $interior_url = $data['interior_url'];
                $package_id = $data['package_id'];
                $quantity = $data['quantity'];
                $name = $lulu_data['is_build'] ? implode(' - ', $data['name']) : $data['name'];
                if (!$cover_url || !$interior_url) {
                    continue;
                }

                if ($lulu_data['is_build']) {
                    try {
                        $class_common = new CPI_Common();
                        $cover_url = $class_common->create_cover_file($cover_url, $data['package_id'], $data['build_book_page_count']);
                    } catch (Exception $e) {
                        error_log('Error creating cover for bundle: ' . $e->getMessage());
                        continue;
                    }
                }

                $line_items[] = array(
                    'external_id' => $item_id,
                    'printable_normalization' => array(
                        'cover' => array(
                            'source_url' => $cover_url,
                        ),
                        'interior' => array(
                            'source_url' => $interior_url,
                        ),
                        'pod_package_id' => $package_id,
                    ),
                    'quantity' => $quantity,
                    'title' => $name,
                );
            }
        }

        // echo "<pre>";
        // print_r($line_items);
        // echo "</pre>";
        // die;

        $shipping = $order->get_address('shipping');
        $shipping_address = array(
            'city' => $shipping['city'],
            'country_code' => $shipping['country'],
            'name' => $shipping['first_name'] . ' ' . $shipping['last_name'],
            'phone_number' => $shipping['phone'],
            'postcode' => $shipping['postcode'],
            'state_code' => $shipping['state'],
            'street1' => $shipping['address_1'],
            'street2' => $shipping['address_2'],
        );

        $payload = array(
            'contact_email' => $order->get_billing_email(),
            'external_id' => $order_id,
            'line_items' => $line_items,
            'production_delay' => 120,
            'shipping_address' => $shipping_address,
            'shipping_level' => CPI_Common::get_setting_lulu_shipping_level(),
        );

        $response = wp_remote_post(CPI_Common::get_setting_lulu_url() . 'print-jobs', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode($payload),
            'timeout' => 60,
        ));

        if (is_wp_error($response)) {
            $order->add_order_note(sprintf(__('Error creating Lulu print job: %s', 'woocommerce'), $response->get_error_message()));
            error_log('Error creating Lulu print job: ' . $response->get_error_message());
            return false;
        } else {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $order->update_meta_data('_lulu_response_body', $body);
            if (isset($body['id'])) {
                $order->update_meta_data('_lulu_print_job_id', $body['id']);
                return array('tracking_id' => $body['id']);
            } else {
                // Xử lý lỗi từ API và thêm vào ghi chú đơn hàng
                if (isset($body['errors'])) {
                    $error_messages = [];
                    foreach ($body['errors'] as $field => $messages) {
                        foreach ($messages as $message) {
                            $error_messages[] = sprintf('%s: %s', $field, $message);
                        }
                    }
                    $order->add_order_note(sprintf(__('Error creating Lulu print job: %s', 'woocommerce'), implode(', ', $error_messages)));
                } else {
                    $allMessages = [];
                    foreach ($body as $section) {
                        $errors = $section['detail']['errors'] ?? [];

                        foreach ($errors as $error) {
                            if (isset($error['message'])) {
                                $allMessages[] = $error['message'];
                            }
                        }
                    }

                    $order->add_order_note(__('Error creating Lulu print job: Unknown error \n' .  implode("\n", $allMessages), 'woocommerce'));
                }
                error_log('Error creating Lulu print job: ' . print_r($body, true));
                return false;
            }
        }
    }

    /**
     *  Summary of process_send_order_to_lulu_action
     */
    public function process_send_order_to_lulu_action($order)
    {
        if ($order->get_status() === 'completed' || $order->get_status() === 'processing') {
            $order_id = $order->get_id();
            $sent_to_lulu = $order->get_meta('_sent_to_lulu');
            $tracking_id = $order->get_meta('_lulu_tracking_id');
            $detail = $this->get_lulu_print_job_details($tracking_id);

            if (!$sent_to_lulu || ($detail && $detail['status']['name'] === 'REJECTED')) {
                $result = $this->create_lulu_print_job($order_id);

                if ($result) {
                    $order->update_meta_data('_sent_to_lulu', true);
                    $order->update_meta_data('_lulu_tracking_id', $result['tracking_id']);
                    $order->add_order_note(sprintf(__('Order sent to Lulu. Tracking ID: %s', 'woocommerce'), $result['tracking_id']));
                } else {
                    $order->add_order_note(__('Failed to send order to Lulu.', 'woocommerce'));
                }
                $order->save();
            } else {
                $order->add_order_note(__('Order has already been sent to Lulu.', 'woocommerce'));
            }
        } else {
            $order->add_order_note(__('Order must be in processing or completed status to send to Lulu.', 'woocommerce'));
        }
    }

    /**
     * Summary of get_lulu_print_job_details
     * @param mixed $print_job_id
     */
    public function get_lulu_print_job_details($print_job_id)
    {
        $access_token = $this->get_lulu_access_token();
        if (!$access_token) {
            return false;
        }

        $response = wp_remote_get(CPI_Common::get_setting_lulu_url() . 'print-jobs/' . $print_job_id, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json',
            ),
            'timeout' => 10,
        ));

        if (is_wp_error($response)) {
            error_log('Error getting Lulu print job details: ' . $response->get_error_message());
            return false;
        } else {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            return $body;
        }
    }

    public function validate_lulu_shipping($data, $errors)
    {
        $posted_data = http_build_query($data);

        if (wc_notice_count('error') <= 0) {
            $this->recalculate_shipping_costs($posted_data);
        }
    }
}
