<?php
class CPI_Build_Book_Shortcode
{
    public static function init()
    {
        add_shortcode('build_your_book_detail', [__CLASS__, 'render']);
    }

    public static function render()
    {
        $slug = isset($_GET['pslug']) ? sanitize_text_field($_GET['pslug']) : '';
        if (empty($slug)) {
            return '<p>Missing product slug.</p>';
        }
    
        $product = get_page_by_path($slug, OBJECT, 'product');
        if (!$product) {
            return '<p>Product not found.</p>';
        }
    
        ob_start();
        $template = CPI_PLUGIN_PATH . 'templates/build-book-detail.php';
        if (file_exists($template)) {
            include $template; // file này sẽ dùng biến $product
        } else {
            echo '<h1>Build Your Book: ' . esc_html(get_the_title($product)) . '</h1>';
            echo '<div>' . apply_filters('the_content', $product->post_content) . '</div>';
        }
        return ob_get_clean();
    }
}
CPI_Build_Book_Shortcode::init();