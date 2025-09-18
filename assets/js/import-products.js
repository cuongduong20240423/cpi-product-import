jQuery(document).ready(function ($) {
    let __loading = '<span class="spinner is-active"></span>';
    let __elmBtn = "#form-search-product-af .button";

    let selectedProducts = [];

    function fetchProducts(page = 1) {
        $(__elmBtn).append(__loading);
        $(__elmBtn).prop("disabled", true);
        $.ajax({
            url: cpi_ajax_object.ajax_url,
            method: "GET",
            data: {
                action: "cpi_get_products",
                search: $("#search-product").val(),
                product_cat: $('select[name="product_cat"]').val(),
                author: $('select[name="author"]').val(),
                limit: 10,
                page: page,
            },
            success: function (response) {
                $(__elmBtn).find(".spinner").remove();
                $(__elmBtn).prop("disabled", false);
                displayProducts(
                    response.data.products,
                    page,
                    response.data.total_pages,
                    response.data.pagination_html
                );
            },
        });
    }

    function escapeHTML(str) {
        return str
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    function displayProducts(
        products,
        currentPage,
        totalPages,
        pagination_html
    ) {
        const productList = $("#product-list");
        productList.empty();
        products.forEach((product) => {
            const isSelected = isIdInSelectedProducts(product.id);
            const disabled = isSelected ? "disabled" : "";
            const checked = isSelected ? "checked" : "";
            const productHtml = `<div class="product-item" data-id="${
                product.id
            }" data-product="${escapeHTML(JSON.stringify(product))}">
                <input type="checkbox" ${disabled} ${checked} class="select-input-product">
                <img src="${
                    product.images.length > 0 ? product.images[0].thumbnail : ""
                }" width="50px" height="50px">
                <div>
                    <p style="margin: 0;"><strong>${product.name}</strong> - ${
                product.sku
            }</p>
                    <p style="margin: 0;"><strong>Price</strong>: <span style="color: red;">${
                        product.price_html
                    }</span> (${product.currency})</p>
                </div>
            </div>`;
            productList.append(productHtml);
        });

        productList.append(pagination_html);
    }

    function moveProductToSelectedList(product) {
        const productItem = $(`.product-item[data-id="${product.id}"]`);
        const productHtml = `<div class="selected-product-item" data-id="${
            product.id
        }">
            <button class="remove-product button" data-id="${
                product.id
            }">X</button>
            <img src="${
                product.images.length > 0 ? product.images[0].src : ""
            }" width="50px" height="50px">
            <div>
                <p style="margin: 0;"><strong>${product.name}</strong> - ${
            product.sku
        }</p>
                <p style="margin: 0;"><strong>Price</strong>: <span style="color: red;">${
                    product.price_html
                }</span> (${product.currency})</p>
            </div>
        </div>`;
        $("#selected-product-list").append(productHtml);

        productItem.find('input[type="checkbox"]').prop("disabled", true);
    }

    $("#product-list").on("click", ".select-product", function () {
        const productId = $(this).closest(".product-item").data("id");
        if (!selectedProducts.includes(productId)) {
            selectedProducts.push(productId);
            moveProductToSelectedList(productId);
        }
    });

    function isIdInSelectedProducts(id) {
        return selectedProducts.some((product) => product.id === id);
    }

    function addToSelectedProducts(id, data) {
        if (!isIdInSelectedProducts(id)) {
            selectedProducts.push(data);
        }
    }

    function removeFromSelectedProducts(id) {
        selectedProducts = selectedProducts.filter(
            (product) => product.id !== id
        );
    }

    $("#product-list").on("change", ".select-input-product", function () {
        if ($(this).is(":checked")) {
            const productId = $(this).closest(".product-item").data("id");
            const product = $(this).closest(".product-item").data("product");
            addToSelectedProducts(productId, product);
            moveProductToSelectedList(product);
        }
    });

    $("#selected-product-list").on("click", ".remove-product", function () {
        const productId = $(this).data("id");
        removeFromSelectedProducts(productId);
        $(this).closest(".selected-product-item").remove();
        $(
            `#product-list .product-item[data-id="${productId}"] input[type="checkbox"]`
        )
            .prop("disabled", false)
            .prop("checked", false);
    });

    $("body").on("click", "a.page-numbers", function (e) {
        e.preventDefault();
        let page = $(this).attr("data-page");
        $(this).append(__loading);
        $(this).prop("disabled", true);
        fetchProducts(page);
    });

    $("#import-button-art").click(function () {
        let _this = $(this);

        if (selectedProducts.length === 0) {
            alert("Please select at least one product.");
            return;
        }

        _this.prop("disabled", true);
        _this.append(__loading);


        $.ajax({
            url: cpi_ajax_object.ajax_url,
            method: "POST",
            data: {
                action: "save_products_to_queue", 
                products: selectedProducts
            },
            success: function (response) {
                _this.prop("disabled", false);
                _this.find(".spinner").remove();

                if (response.success) {
                    alert(response.data.message);
                    // Reset danh sách sản phẩm đã chọn
                    selectedProducts = [];
                    // $("#selected-product-list").empty();
                    fetchProducts();
                } else {
                    alert(
                        "Failed to add products to the import queue: " +
                            response.data.message
                    );
                }
            },
            error: function (xhr, status, error) {
                _this.prop("disabled", false);
                _this.find(".spinner").remove();
                alert(`Error: ${status} - ${xhr.status} ${xhr.statusText}`);
            },
        });
    });

    $("#reset-button-art").click(function () {
        selectedProducts = [];
        $("#selected-product-list").empty();
        fetchProducts();
    });

    $("#form-search-product-af").on("submit", function (e) {
        e.preventDefault();
        fetchProducts();
    });

    fetchProducts(); // Gọi hàm này để tải sản phẩm lần đầu
});
