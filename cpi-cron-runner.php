<?php
require_once dirname(__DIR__, 3) . '/wp-load.php';
if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.\n");
}

CPI_Cron_Handler::process_cron();

echo "Cron job completed via CLI.\n";
