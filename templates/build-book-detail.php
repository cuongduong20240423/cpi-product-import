<?php

/** @var WP_Post $product */
$product_id = $product->ID;
$product_obj   = function_exists('wc_get_product') ? wc_get_product( $product_id ) : null;
$price = $product_obj ? $product_obj->get_price() : 0;
$thumbnail_id = get_post_thumbnail_id($product_id);
$thumbnail_url = $thumbnail_id ? wp_get_attachment_url($thumbnail_id) : '';
$gallery_ids = get_post_meta($product_id, '_product_image_gallery', true);
$gallery_urls = [];
if ($gallery_ids) {
    $ids = explode(',', $gallery_ids);
    foreach ($ids as $id) {
        $url = wp_get_attachment_url($id);
        if ($url) $gallery_urls[] = $url;
    }
}
$currency_symbol = get_woocommerce_currency_symbol();
?>

<style>
    .container {
        max-width: 1180px;
        margin: 0 auto;
    }

    #step-1,
    #step-3 {
        margin-top: 32px;
        margin-bottom: 32px;
    }

    .box-scbp-build-product {
        font-family: 'Space Mono', monospace;
    }

    .box-scbp-build-product h1,
    .box-scbp-build-product h2 {
        font-size: 30pt;
        color: #191919;
        line-height: 1.2em;
        margin-bottom: 26px;
    }

    .box-scbp-content-cs {
        font-size: 14pt;
        margin-bottom: 20px;
    }

    .box-scbp-price-cs {
        font-size: 18px;
        margin-bottom: 11px;
    }

    .box-scbp-cs-cost {
        font-size: 10pt;
        font-style: italic;
        margin-bottom: 25px;
    }

    .box-scbp-2 {
        background: #f2ffe2;
        margin-bottom: 0;
        text-align: center;
    }

    .box-scbp-3-a {
        padding: 20px 0;
    }

    .box-scbp-3 {
        padding: 0 16px;
        display: inline-flex;
        gap: 47px;
        justify-content: center;
        position: relative;
    }

    .box-scbp-3:before {
        width: 32px;
        height: 100%;
        content: "";
        position: absolute;
        top: 0;
        left: 0;
        background-image: url(<?php echo CPI_PLUGIN_URL . 'assets/images/s-1.svg'; ?>);
        background-size: 32px;
    }

    .box-scbp-3:after {
        width: 32px;
        height: 100%;
        content: "";
        position: absolute;
        top: 0;
        right: 0;
        background-image: url(<?php echo CPI_PLUGIN_URL . 'assets/images/s-1.svg'; ?>);
        background-size: 32px;
        transform: rotate(180deg);
    }

    .box-scbp-4 {
        display: flex;
        justify-content: flex-end;
        align-items: center;
        gap: 30px;
        font-size: 14pt;
        border-top: 1px solid #a7a9ac;
    }

    .box-scbp-btn {
        background: #b9ff66;
        border: none;
        border-radius: 0;
        padding: 30px;
        font-family: 'Space Mono', monospace;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .box-scbp-3 img {
        width: 280px;
        height: 420px;
        border-radius: 20px;
    }

    #step-2 {
        background-color: #f2ffe2;
        padding: 64px 0;
    }

    .row {
        display: flex;
        flex-wrap: wrap;
        margin-left: -15px;
        margin-right: -15px;
    }

    .col {
        padding-left: 15px;
        padding-right: 15px;
        flex: 1 0 0%;
    }

    .step2-left {
        padding-left: 16px;
        position: relative;
    }

    .step2-left:before {
        width: 32px;
        height: 100%;
        content: "";
        position: absolute;
        top: 0;
        left: 0;
        background-image: url(<?php echo CPI_PLUGIN_URL . 'assets/images/s-1.svg'; ?>);
        background-size: 32px;
    }

    #interior-preview {
        width: 100%;
        height: 665px;
        border-radius: 20px;
    }

    #interior-list {
        display: grid;
        grid-template-columns: repeat(6, 1fr);
        gap: 13px;
    }

    .interior-item {
        cursor: pointer;
    }

    .interior-item img {
        width: 100%;
        height: 100%;
    }
	
	.interior-item.active {
		position: relative;
	}

	.interior-item.active::after {
		content: "✔";
		position: absolute;
		top: 2px;
		right: 6px;
		font-size: 20px;
		color: green;
		font-weight: bold;
	}

    .cpi-box-right-step2 {
        width: 100%;
        height: 100%;
        background-color: #191a23;
        display: flex;
        flex-direction: column;
    }

    .cpi-box-right-step2-top {
        padding: 20px;
        text-align: center;
        color: #fff;
        border-bottom: 1px solid #a7a9ac;
    }

    .cpi-box-right-step2-top h2 {
        font-size: 26pt;
        line-height: 1.3em;
        margin-bottom: 10px;
		color: #fff;
    }

    .cpi-box-right-step2-top p {
        font-size: 12pt;
        margin-bottom: 0;
    }

    .cpi-box-right-step2-center {
        padding: 10px 50px;
        border-bottom: 1px solid #a7a9ac;
    }

    .cpi-box-right-step2-bottom {
        padding: 20px;
        flex: 1;
        max-height: 400px;
        overflow-y: auto;
        position: relative;
    }

    .box-scbp-4.box-scbp-4-step2 {
        justify-content: space-between;
    }

    .box-scbp-btn.box-scbp-btn-rotate img {
        transform: rotate(180deg);
    }

    .text-white {
        color: white !important;
    }

    .text-center {
        text-align: center;
    }

    #step-3 {
        margin-bottom: 65px;
    }

    .step3-img-cover {
        display: flex;
        gap: 5px;
        position: relative;
    }

    .step3-img-cover:before {
        width: 32px;
        height: 100%;
        content: "";
        position: absolute;
        top: 0;
        left: 50%;
        transform: translateX(-50%);
        background-image: url(<?php echo CPI_PLUGIN_URL . 'assets/images/s-1.svg'; ?>);
        background-size: 32px;
    }

    .step3-top {
        text-align: center;
        padding: 30px;
    }

    .step3-img-cover img {
        height: 420px;
        border-radius: 20px;
        flex: 0 0 auto;
        width: 278px;
        height: 420px;
        border-radius: 20px;
    }

    .step3-images {
        display: inline-flex;
        gap: 45px;
    }

    .step3-img-interior {
        flex: 0 0 auto;
        width: 278px;
    }

    .step3-img-interior img {
        width: 100%;
        height: 420px;
    }

    .box-scbp-4-step3 {
        justify-content: start;
    }

    .box-scbp-btn.box-scbp-step3-btn img {
        transform: rotate(180deg);
    }

    #step-3 h2 {
        margin-bottom: 15px;
    }

    #step-3 p.box-scbp-content-cs {
        margin-bottom: 60px;
    }

    .step3-img-interior {
        position: relative;
    }

    .step3-img-interior::before {
        width: 32px;
        height: 100%;
        content: "";
        position: absolute;
        top: 0;
        left: -16px;
        background-image: url(<?php echo CPI_PLUGIN_URL . 'assets/images/s-1.svg'; ?>);
        background-size: 32px;
    }

    .step3-info {
        margin-top: 20px;
    }

    .step3-info-title {
        margin-bottom: 20px;
    }

    #select-pages,
    #select-format {
        border: 1px solid #000;
        border-radius: 0;
        color: #000;
        padding: 10px 20px;
        margin-bottom: 15px;

    }

    #select-pages {
        width: 350px;
    }

    #select-format {
        width: 210px;
    }

    .box-scbp-btn-add-to-cart {
        font-size: 13px;
        font-family: 'Space Mono', monospace;
        background: #b9ff66;
        color: #000;
        padding: 20px 30px;
        border-radius: 16px;
        border: 0;
    }

    #step3-price-html {
        margin-bottom: 30px;
        font-size: 20px;
        margin-top: 20px;
    }
	
	#interior-loading{
		color: #FFF;
	}
</style>
<div class="box-scbp-build-product">
    <!-- Step 1: Cover -->
    <div id="step-1" class="build-step">
        <div class="container">
            <h1><?php echo esc_html(get_the_title($product)); ?></h1>
            <div class="box-scbp-content-cs" style=""><?php echo esc_html($product->post_content); ?></div>
            <div class="box-scbp-price-cs"><?php echo $currency_symbol; ?><?php echo esc_html($price); ?></div>
            <div class="box-scbp-cs-cost">
                *Printing costs vary based on the number of pages selected
            </div>
            <div class="box-scbp-2">
                <div class="box-scbp-3-a">
                    <div class="box-scbp-3">
                        <?php if ($thumbnail_url): ?>
                            <img src="<?php echo esc_url($thumbnail_url); ?>" alt="Thumbnail">
                        <?php endif; ?>
                        <?php foreach ($gallery_urls as $url): ?>
                            <img src="<?php echo esc_url($url); ?>" alt="Gallery">
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="box-scbp-4" style="">
                    <span class="box-scbp-span-1">Pick your pages</span>
                    <button class="box-scbp-btn" id="btn-next-step-1">
                        <img src="<?php echo CPI_PLUGIN_URL . '/assets/images/right.png'; ?>" width="28px">
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Step 2: Chọn interior-pages -->
    <div id="step-2" class="build-step" style="display:none;">
        <div class="container">
            <div class="cpi-step2-top row">
                <div class="col">
                    <div class="step2-left">
                        <img id="interior-preview" src="" alt="Interior Preview">
                    </div>
                </div>
                <div class="step2-right col">
                    <div class="cpi-box-right-step2">
                        <div class="cpi-box-right-step2-top">
                            <h2>2. Pick Your Page Style</h2>
                            <p>Match your cover with the perfect format</p>
                        </div>
                        <div class="cpi-box-right-step2-center">
                            <select id="select-category">
                                <option value="">Choose Categories</option>
                                <?php
                                $terms = get_terms([
                                    'taxonomy' => 'product_cat',
                                    'hide_empty' => false,
                                ]);
                                foreach ($terms as $term) {
                                    echo '<option value="' . esc_attr($term->slug) . '">' . esc_html($term->name) . '</option>';
                                }
                                ?>
                            </select>
                        </div>

                        <div class="cpi-box-right-step2-bottom">
                            <div id="interior-list"></div>
                            <div id="interior-loading" style="display:none;position:absolute;top:10px;left:0;right:0;text-align:center;">
                                <span>Loading...</span>
                            </div>
                        </div>

                        <div class="box-scbp-4 box-scbp-4-step2">
                            <button class="box-scbp-btn box-scbp-btn-rotate" id="btn-back-step-2">
                                <img src="<?php echo CPI_PLUGIN_URL . '/assets/images/right.png'; ?>" width="28px">
                            </button>
                            <span class="box-scbp-span-1 text-white">Page Count and Printing</span>
                            <button class="box-scbp-btn" id="btn-next-step-2">
                                <img src="<?php echo CPI_PLUGIN_URL . '/assets/images/right.png'; ?>" width="28px">
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Step 3: Preview & Options -->
    <div id="step-3" class="build-step" style="display:none;">
        <div class="container">
            <h2 class="text-center">3. Page Count, Printing and Preview</h2>
            <div class="box-scbp-content-cs text-center">Decide how many pages you need and how you’d like it delivered</div>
            <div class="box-scbp-2">
                <div class="step3-top">
                    <div class="step3-images">
                        <!-- Ảnh cover, gallery, interior -->
                        <div class="step3-img-cover">
                            <img id="cover-preview" src="">
                            <img id="gallery-preview" src="">
                        </div>
                        <div class="step3-img-interior">
                            <img id="interior-preview-3" src="">
                        </div>
                    </div>
                </div>
                <div class="box-scbp-4 box-scbp-4-step3">
                    <button class="box-scbp-btn box-scbp-step3-btn" id="btn-back-step-3">
                        <img src="<?php echo CPI_PLUGIN_URL . '/assets/images/right.png'; ?>" width="28px">
                    </button>
                </div>
            </div>

            <div class="step3-info">
                <div class="step3-info-title">Cover: <span id="cover-title"></span></div>
                <div class="step3-info-title">Interior Design: <span id="interior-title"></span></div>

                <div id="interior-variations-container">
                    <!-- Variation selects will be dynamically added here -->
                    <!-- Format   -->
                    <div style="margin-bottom: 15px;">
                        <select id="select-format" class="variation-select">
                            <option value="">Choose Format</option>
                            <option value="download">Download</option>
                            <option value="print">Print</option>
                        </select>
                    </div>

                    <!-- number of page  -->
                    <div style="margin-bottom: 15px;">
                        <select id="select-pages" class="variation-select">
                            <option value="">Choose Page Count</option>
                            <?php for ($i = 32; $i <= 120; $i++): ?>
                                <option value="<?php echo $i; ?>"><?php echo $i; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>

                <div id="step3-price-html">
                    <!-- Giá sẽ được cập nhật ở đây -->
                </div>
            </div>
            <button id="btn-add-to-cart" class="box-scbp-btn-add-to-cart">Add to Cart</button>
        </div>
    </div>
</div>

<script>
    jQuery(function($) {
        let step = 1;
        let selectedInterior = null;
        let paged = 1;
        let maxPages = 1;
        let loading = false;
        let selectedCat = '';
        let interiorVariations = []; // Store interior product variations
		let currency_symbol = '<?php echo $currency_symbol; ?>';

        function showStep(n) {
            $('.build-step').hide();
            $('#step-' + n).show();
            step = n;
        }

        $('#btn-next-step-1').on('click', function() {
            showStep(2);
            paged = 1;
            loadInteriorProducts(paged, true);
        });

        $('#btn-back-step-2').on('click', function() {
            showStep(1);
        });

        $('#btn-next-step-2').on('click', function() {
            if (!selectedInterior) {
                alert('Please select an interior design first.');
                return;
            }
            showStep(3);
            fillStep3();
            // loadInteriorVariations(selectedInterior.id);
        });

        $('#btn-back-step-3').on('click', function() {
            showStep(2);
        });

        $('#select-category').on('change', function() {
            selectedCat = $(this).val();
            paged = 1;
            loadInteriorProducts(paged, true);
        });

        function loadInteriorProducts(page, reset = false) {
            if (loading) return;
            loading = true;
			$("#interior-list").html('');
            $('#interior-loading').show();
            $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                action: 'cpi_get_interior_products',
                paged: page,
                category: selectedCat
            }, function(res) {
                $('#interior-loading').hide();
                loading = false;
                if (res.success) {
                    maxPages = res.data.max_num_pages;
                    let html = '';
                    res.data.products.forEach(function(p, idx) {
                       	let active = (idx == 0 && page == 1) ? 'active' : '';
                        html += `<div class="interior-item ${active}" data-id="${p.id}" data-thumb="${p.thumb}" data-title="${p.title}" data-price="${p.price}">
                        <img src="${p.thumb}">
                    </div>`;
                        if (idx == 0 && page == 1) { // chọn mặc định sản phẩm đầu tiên
                            $('#interior-preview').attr('src', p.thumb);
                            selectedInterior = p;
                        }
                    });
                    if (reset) $('#interior-list').html(html);
                    else $('#interior-list').append(html);
                }
            });
        }

        $('#interior-list').on('click', '.interior-item', function() {
            let thumb = $(this).data('thumb');
            let title = $(this).data('title');
            let price = $(this).data('price') || 0;
			$('.interior-item').removeClass('active');
            $(this).addClass('active');
            $('#interior-preview').attr('src', thumb);
            selectedInterior = {
                id: $(this).data('id'),
                thumb,
                title,
                price
            };
        });

        // Infinite scroll for interior-list
        $('.cpi-box-right-step2-bottom').on('scroll', function() {
            let $this = $(this);
            if ($this[0].scrollHeight - $this.scrollTop() - $this.outerHeight() < 50) {
                if (paged < maxPages && !loading) {
                    paged++;
                    loadInteriorProducts(paged, false);
                }
            }
        });

        function fillStep3() {
            $('#cover-preview').attr('src', '<?php echo esc_js($thumbnail_url); ?>');
            $('#gallery-preview').attr('src', '<?php echo esc_js($gallery_urls[0] ?? ''); ?>');
            $('#interior-preview-3').attr('src', selectedInterior ? selectedInterior.thumb : '');
            $('#cover-title').text('<?php echo esc_js(get_the_title($product)); ?>');
            $('#interior-title').text(selectedInterior ? selectedInterior.title : '');
        }

        function loadInteriorVariations(productId) {
            $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                action: 'cpi_get_product_variations',
                product_id: productId
            }, function(res) {
                if (res.success) {
                    interiorVariations = res.data.variations;
                    buildVariationSelects(res.data.attributes);
                } else {
                    console.error('Failed to load variations:', res.data.message);
                }
            });
        }

        function buildVariationSelects(attributes) {
            let html = '';

            Object.keys(attributes).forEach(function(attrName) {
                let attrLabel = attributes[attrName].label || 'Attribute';

                html += `<div style="margin-bottom: 15px;">
                    <select class="variation-select" data-attribute="${attrName}" style="border: 1px solid #000; border-radius: 0; color: #000; padding: 10px 20px; width: 350px;">
                        <option value="">Choose ${attrLabel}</option>`;

                attributes[attrName].options.forEach(function(option) {
                    html += `<option value="${option.slug}">${option.name}</option>`;
                });

                html += `</select></div>`;
            });

            $('#interior-variations-container').html(html);
        }

        // Handle variation selection changes - Sử dụng event delegation
        $(document).on('change', '.variation-select', function() {
            updateStep3Price();
        });

        function updateStep3Price() {

            // Get variation data

            let format = $('#select-format').val();
            let pageCount = $('#select-pages').val();

            if (!format || !pageCount) return;

            let coverPrice = <?php echo floatval($price); ?>;
            console.log(selectedInterior);
            let interiorPrice = parseFloat(selectedInterior.price) || 0;

            if (format === 'print' && pageCount) {
                // Show loading state
                $('#step3-price-html').html('<span style="color: #666;">Calculating printing cost...</span>');

                // Gọi AJAX lấy phí in ấn
                $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                    action: 'get_printing_cost_by_product_simple',
                    page_number: pageCount,
                    format: format,
                    cover_product_id: <?php echo $product_id; ?>,
                    interior_product_id: selectedInterior.id,
                }, function(res) {
                    if (res.success) {
                        var printingCost = parseFloat(res.data.printing_cost) || 0;
                        var total = coverPrice + interiorPrice + printingCost;
                        var priceHtml = `
                            <div style="border: 1px solid #ddd; padding: 15px; background: #f9f9f9; border-radius: 5px;">
                                <div>Cover: ${currency_symbol}${coverPrice.toFixed(2)}</div>
                                <div>Interior: ${currency_symbol}${interiorPrice.toFixed(2)}</div>
                                <div>Printing Fee: ${currency_symbol}${printingCost.toFixed(2)}</div>
                                <hr style="margin: 10px 0;">
                                <div style="font-size: 1.2em; font-weight: bold; color: #2c5282;">
                                    Total: ${currency_symbol}${total.toFixed(2)}
                                </div>
                            </div>
                        `;
                    } else {
                        var priceHtml = '<span style="color: red;">Error getting printing cost: ' + (res.data ? res.data.message : 'Unknown error') + '</span>';
                    }
                    $('#step3-price-html').html(priceHtml);
                }).fail(function(xhr, status, error) {
                    console.error('AJAX error:', status, error);
                    $('#step3-price-html').html('<span style="color: red;">Error calculating printing cost</span>');
                });
            } else if (format === 'download') {
                var total = coverPrice + interiorPrice;
                var priceHtml = `
                    <div style="border: 1px solid #ddd; padding: 15px; background: #f9f9f9; border-radius: 5px;">
                        <div>Cover: ${currency_symbol}${coverPrice.toFixed(2)}</div>
                        <div>Interior: ${currency_symbol}${interiorPrice.toFixed(2)}</div>
                        <hr style="margin: 10px 0;">
                        <div style="font-size: 1.2em; font-weight: bold; color: #2c5282;">
                            Total: ${currency_symbol}${total.toFixed(2)}
                        </div>
                    </div>
                `;
                $('#step3-price-html').html(priceHtml);
            } else {
                $('#step3-price-html').html('<span style="color: #666;">Please select format and page count</span>');
            }
        }

        // Add to cart functionality
        $('#btn-add-to-cart').on('click', function() {
            // Validate đã chọn interior chưa
            if (!selectedInterior) {
                alert('Please select an interior design first.');
                return;
            }

            // Validate đã chọn format và page count chưa
            let format = $('#select-format').val();
            let pageCount = $('#select-pages').val();
            if (!format || !pageCount) {
                alert('Please select format and page count.');
                return;
            }

            // Show loading state
            $(this).prop('disabled', true).text('Adding to cart...');

            // Add to cart
            $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                action: 'cpi_add_build_book_to_cart',
                cover_product_id: <?php echo $product_id; ?>,
                interior_product_id: selectedInterior.id,
                format: format,
                page_count: pageCount,
                nonce: '<?php echo wp_create_nonce('cpi_add_to_cart'); ?>'
            }, function(response) {
                if (response.success) {
                    // Redirect to cart or show success message
                    window.location.href = '<?php echo wc_get_cart_url(); ?>';
                } else {
                    alert('Error adding to cart: ' + (response.data ? response.data.message : 'Unknown error'));
                }
            }).fail(function() {
                alert('Error adding to cart. Please try again.');
            }).always(function() {
                $('#btn-add-to-cart').prop('disabled', false).text('Add to Cart');
            });
        });
    });
</script>