<?php
namespace ServiceNowTablePressSync;

class Cron {

    const EVENT = 'servicenow_tablepress_sync_daily';

    public static function init(): void {
        add_action(self::EVENT, array(__CLASS__, 'run'));
    }

    public static function activate(): void {
        if (!wp_next_scheduled(self::EVENT)) {

            $timestamp = strtotime('tomorrow 00:00', current_time('timestamp'));
            
            wp_schedule_event(
                $timestamp,
                'daily',
                self::EVENT
            );
        }
    }

    public static function deactivate(): void {
        $ts = wp_next_scheduled(self::EVENT);
        if ($ts) {
            wp_unschedule_event($ts, self::EVENT);
        }
    }

    public static function run(): void {
        $url  = (string) get_option(\SN_TP_SYNC_OPT_API_URL,  '');
        $user = (string) get_option(\SN_TP_SYNC_OPT_API_USER, '');
        $pass = (string) get_option(\SN_TP_SYNC_OPT_API_PASS, '');
        $tid  = (int)    get_option(\SN_TP_SYNC_OPT_TABLE_ID,  0);

        if (!$url || !$user || !$pass || $tid <= 0) {
            return;
        }

        $res = Sync::run_incremental($tid, $url, $user, $pass, false, true);

        if (is_wp_error($res)) {
            error_log('[SN_TP_SYNC] Virhe cron-ajossa: ' . $res->get_error_message());
            return;
        }

        $rows = (int) ($res['rows'] ?? 0);
        $upd  = (int) ($res['updated'] ?? 0);
        $msg  = "WP-cron: rivit $rows, päivitettyjä $upd";

        Admin::store_last_run(
            !is_wp_error($res),
            $msg,
            $rows,
            $upd
        );
        update_option('SN_TP_SYNC_OPT_LAST_CRON', gmdate('Y-m-d H:i:s'));
    }
}
