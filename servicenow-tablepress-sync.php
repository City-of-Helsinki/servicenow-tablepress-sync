<?php
/**
 * Plugin Name: ServiceNow TablePress Sync
 * Description: Updates a TablePress table with "Sovellusrekisteri" data via customized ServiceNow API.
 * Version: 1.1.2
 * Requires at least: 6.8
 * Requires PHP: 8.0
 * Author: HiQ
 * License: GPLv3
 * Text Domain: servicenow-tablepress-sync
 */

if ( ! defined('ABSPATH') ) { exit; }

if (!defined('SN_TP_SYNC_OPT_API_URL'))        define('SN_TP_SYNC_OPT_API_URL',        'servicenow_tablepress_sync_api_url');
if (!defined('SN_TP_SYNC_OPT_API_USER'))       define('SN_TP_SYNC_OPT_API_USER',       'servicenow_tablepress_sync_api_user');
if (!defined('SN_TP_SYNC_OPT_API_PASS'))       define('SN_TP_SYNC_OPT_API_PASS',       'servicenow_tablepress_sync_api_pass');
if (!defined('SN_TP_SYNC_OPT_TABLE_ID'))       define('SN_TP_SYNC_OPT_TABLE_ID',       'servicenow_tablepress_sync_table_id');
if (!defined('SN_TP_SYNC_OPT_LAST_RUN'))       define('SN_TP_SYNC_OPT_LAST_RUN',       'servicenow_tablepress_sync_last_run');
if (!defined('SN_TP_SYNC_OPT_LAST_SYNC_MAP'))  define('SN_TP_SYNC_OPT_LAST_SYNC_MAP',  'servicenow_tablepress_sync_last_sync_map');

require_once plugin_dir_path(__FILE__) . 'src/Sync.php';
require_once plugin_dir_path(__FILE__) . 'src/CLI.php';
require_once plugin_dir_path(__FILE__) . 'src/Admin.php';

register_activation_hook(__FILE__, function () {
    add_option(SN_TP_SYNC_OPT_API_URL,        '', '', false);
    add_option(SN_TP_SYNC_OPT_API_USER,       '', '', false);
    add_option(SN_TP_SYNC_OPT_API_PASS,       '', '', false);
    add_option(SN_TP_SYNC_OPT_TABLE_ID,       0,  '', false);
    add_option(SN_TP_SYNC_OPT_LAST_RUN,       array(), '', false);
    add_option(SN_TP_SYNC_OPT_LAST_SYNC_MAP,  array(), '', false);
});

add_action('plugins_loaded', function () {
    \ServiceNowTablePressSync\Admin::init();
});

if (defined('WP_CLI') && WP_CLI) {
    \ServiceNowTablePressSync\CLI::register_commands();
}

add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style(
        'tp-sync',
        plugin_dir_url(__FILE__) . 'assets/css/tp-sync.css',
        array(),
        '1.0'
    );
});
