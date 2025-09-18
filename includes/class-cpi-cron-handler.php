<?php
class CPI_Cron_Handler
{
    /**
     * Initialize the cron handler
     */
    public static function init()
    {
        // Register endpoint for server-based cronjob
        add_action('init', [__CLASS__, 'register_server_cron_endpoint']);
    }

    /**
     * Process the cron job
     */
    public static function process_cron()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'cpi_import_queue';

        CPI_Common::write_log('Cronjob started.');

        // Fetch products with 'pending' status
        $products = $wpdb->get_results("SELECT * FROM $table_name WHERE status = 'pending' LIMIT 1");

        if (empty($products)) {
            CPI_Common::write_log('No pending products found for import.');
            return;
        }

        foreach ($products as $product) {
            $product_data = maybe_unserialize($product->product_data);

            try {
                // Call the product import function
                $cpi_product_import = new CPI_Product_Import();
                $result = $cpi_product_import->create_product_by_dwd($product_data);

                // Update status based on result
                $wpdb->update(
                    $table_name,
                    [
                        'status' => $result['success'] ? 'completed' : 'failed', 
                        'message' => $result['message'], 
                        'product_id' => $result['success'] ? $result['product_id'] : null
                    ],
                    ['id' => $product->id],
                    ['%s', '%s'],
                    ['%d']
                );
            } catch (Exception $e) {
                // Handle exceptions and update status with the error message
                $wpdb->update(
                    $table_name,
                    ['status' => 'failed', 'message' => $e->getMessage()],
                    ['id' => $product->id],
                    ['%s', '%s'],
                    ['%d']
                );
            }
        }

        CPI_Common::write_log('Cronjob completed.');
    }

    /**
     * Register server-based cron endpoint
     */
    public static function register_server_cron_endpoint()
    {
        if (isset($_GET['cpi_server_cron']) && $_GET['cpi_server_cron'] === '1') {
            self::handle_server_cron();
        }
    }

    /**
     * Handle server-based cron request
     */
    public static function handle_server_cron()
    {
        // Validate the request with a secure key
        if (!isset($_GET['cron_key']) || $_GET['cron_key'] !== 'kabbazzvggaabgaaeetabaeetaiihhabmmzyaab') {
            wp_die('Unauthorized', '403 Forbidden', ['response' => 403]);
        }

        // Process the cron job
        self::process_cron();

        // Output success message
        echo 'Cronjob executed successfully.';
        exit;
    }
}
