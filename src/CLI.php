<?php
namespace ServiceNowTablePressSync;

use WP_CLI;

class CLI
{
    public static function register_commands(): void
    {
        WP_CLI::add_command(
            'servicenow sync',
            array(__CLASS__, 'sync'),
            array('shortdesc' => 'Incrementally update a TablePress table using a hidden Number column as key.'),
        );

        WP_CLI::add_command(
            'servicenow lastsync',
            array(__CLASS__, 'lastsync'),
            array('shortdesc' => 'Show or update last-sync timestamp for a table (UTC).'),
        );
    }

    public static function sync(array $args, array $assoc_args): void
    {
        $opt_url  = (string) get_option(\SN_TP_SYNC_OPT_API_URL,  '');
        $opt_user = (string) get_option(\SN_TP_SYNC_OPT_API_USER, '');
        $opt_pass = (string) get_option(\SN_TP_SYNC_OPT_API_PASS, '');
        $opt_tid  = (int)    get_option(\SN_TP_SYNC_OPT_TABLE_ID,  0);

        $url     = isset($assoc_args['url'])   ? (string) $assoc_args['url']  : $opt_url;
        $user    = isset($assoc_args['user'])  ? (string) $assoc_args['user'] : $opt_user;
        $pass_in = isset($assoc_args['pass'])  ? (string) $assoc_args['pass'] : $opt_pass;
        $tableId = isset($assoc_args['table']) ? (int)    $assoc_args['table']: $opt_tid;
        $dry_run = isset($assoc_args['dry-run']);
        $force   = isset($assoc_args['force-run']);

        if (empty($url) || empty($user) || empty($pass_in) || $tableId <= 0) {
            WP_CLI::error('Missing settings: provide API URL, username, password, and a valid TablePress table ID.');
        }

        $res = Sync::run_incremental($tableId, $url, $user, $pass_in, $dry_run, $force);
        if (is_wp_error($res)) WP_CLI::error($res->get_error_message());

        if (!empty($res['dry_run'])) WP_CLI::log('Dry-run: would write ' . (int)$res['rows'] . ' rows. Updated items: ' . (int)$res['updated']);
        else WP_CLI::success('Table updated. Rows: ' . (int)$res['rows'] . '. Updated items: ' . (int)$res['updated']);
    }

    public static function lastsync(array $args, array $assoc_args): void
    {
        $tableId = isset($assoc_args['table']) ? (int)$assoc_args['table'] : (int) get_option(\SN_TP_SYNC_OPT_TABLE_ID, 0);
        if ($tableId <= 0) WP_CLI::error('Provide --table=<id> or set the option.');
        if (isset($assoc_args['set'])) {
            $ts = (string) $assoc_args['set'];
            Sync::set_last_sync($tableId, $ts);
            WP_CLI::success('Last-sync for table ' . $tableId . ' set to ' . $ts . ' (UTC assumed).');
            return;
        }
        $ts = Sync::get_last_sync($tableId);
        WP_CLI::log('Last-sync for table ' . $tableId . ': ' . ($ts !== '' ? $ts : '(not set)'));
    }
}
