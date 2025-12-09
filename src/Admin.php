<?php
namespace ServiceNowTablePressSync;

class Admin
{
    public static function init(): void
    {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_init', array(__CLASS__, 'register_settings'));
        add_action('admin_post_servicenow_tablepress_sync_run', array(__CLASS__, 'handle_run'));
    }

    public static function menu(): void
    {
        add_options_page(
            'ServiceNow TablePress Sync (Incremental)',
            'ServiceNow TablePress Sync',
            'manage_options',
            'servicenow-tablepress-sync',
            array(__CLASS__, 'render')
        );
    }

    public static function register_settings(): void
    {
        register_setting('servicenow_tp_incr_group', \SN_TP_SYNC_OPT_API_URL, array('type'=>'string','sanitize_callback'=>'esc_url_raw'));
        register_setting('servicenow_tp_incr_group', \SN_TP_SYNC_OPT_API_USER, array('type'=>'string','sanitize_callback'=>'sanitize_text_field'));
        register_setting('servicenow_tp_incr_group', \SN_TP_SYNC_OPT_API_PASS, array('type'=>'string','sanitize_callback'=>'sanitize_text_field'));
        register_setting('servicenow_tp_incr_group', \SN_TP_SYNC_OPT_TABLE_ID, array('type'=>'integer','sanitize_callback'=>function($v){return (int)$v;}));
    }

    public static function handle_run(): void
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        check_admin_referer('sn_tp_incr_run');

        $url  = (string) get_option(\SN_TP_SYNC_OPT_API_URL,  '');
        $user = (string) get_option(\SN_TP_SYNC_OPT_API_USER, '');
        $pass = (string) get_option(\SN_TP_SYNC_OPT_API_PASS, '');
        $tid  = (int)    get_option(\SN_TP_SYNC_OPT_TABLE_ID,  0);
        $dry  = !empty($_POST['dry_run']);
        $force= !empty($_POST['force_run']);

        if (empty($url) || empty($user) || empty($pass) || $tid <= 0) {
            $msg = 'Asetukset puuttuvat tai virheelliset.';
            self::store_last_run(false, $msg, 0, 0);
            wp_safe_redirect(add_query_arg(array('page'=>'servicenow-tablepress-sync','snmsg'=>rawurlencode($msg),'snsuccess'=>0), admin_url('options-general.php'))); exit;
        }

        $res = Sync::run_incremental($tid, $url, $user, $pass, $dry, $force);
        if (is_wp_error($res)) {
            $msg = 'Virhe: ' . $res->get_error_message();
            self::store_last_run(false, $msg, 0, 0);
            wp_safe_redirect(add_query_arg(array('page'=>'servicenow-tablepress-sync','snmsg'=>rawurlencode($msg),'snsuccess'=>0), admin_url('options-general.php'))); exit;
        }

        $rows = (int) $res['rows'];
        $upd  = (int) $res['updated'];
        $msg  = $dry ? ("Dry-run: rivit $rows, päivitettyjä $upd") : ("Synkronointi OK: rivit $rows, päivitettyjä $upd");
        self::store_last_run(true, $msg, $rows, $upd);
        wp_safe_redirect(add_query_arg(array('page'=>'servicenow-tablepress-sync','snmsg'=>rawurlencode($msg),'snsuccess'=>1), admin_url('options-general.php'))); exit;
    }

    private static function store_last_run(bool $ok, string $message, int $rows, int $updated): void
    {
        update_option(\SN_TP_SYNC_OPT_LAST_RUN, array(
            'time_utc' => current_time('mysql', true),
            'success'  => $ok,
            'message'  => $message,
            'rows'     => $rows,
            'updated'  => $updated,
        ));
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) return;
        $api_url  = (string) get_option(\SN_TP_SYNC_OPT_API_URL,  '');
        $api_user = (string) get_option(\SN_TP_SYNC_OPT_API_USER, '');
        $api_pass = (string) get_option(\SN_TP_SYNC_OPT_API_PASS, '');
        $table_id = (int)    get_option(\SN_TP_SYNC_OPT_TABLE_ID,  0);
        $last_run = get_option(\SN_TP_SYNC_OPT_LAST_RUN, array());
        $last_sync= \ServiceNowTablePressSync\Sync::get_last_sync($table_id ?: 0);

        $snmsg     = isset($_GET['snmsg']) ? sanitize_text_field(wp_unslash($_GET['snmsg'])) : '';
        $snsuccess = isset($_GET['snsuccess']) ? (int) $_GET['snsuccess'] : -1;
        ?>
        <div class="wrap">
            <h1>ServiceNow TablePress Sync</h1>

            <?php if ($snmsg !== ''): ?>
                <div class="<?php echo $snsuccess === 1 ? 'notice notice-success' : 'notice notice-error'; ?>"><p><?php echo esc_html($snmsg); ?></p></div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields('servicenow_tp_incr_group'); ?>
                <table class="form-table" role="presentation">
                    <tbody>
                    <tr>
                        <th scope="row"><label for="sn_api_url">API-osoite</label></th>
                        <td><input name="<?php echo esc_attr(\SN_TP_SYNC_OPT_API_URL); ?>" id="sn_api_url" type="url" class="regular-text" value="<?php echo esc_attr($api_url); ?>" placeholder="https://example.com/api/changes" required></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sn_api_user">API-käyttäjänimi</label></th>
                        <td><input name="<?php echo esc_attr(\SN_TP_SYNC_OPT_API_USER); ?>" id="sn_api_user" type="text" class="regular-text" value="<?php echo esc_attr($api_user); ?>" required></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sn_api_pass">API-salasana</label></th>
                        <td><input name="<?php echo esc_attr(\SN_TP_SYNC_OPT_API_PASS); ?>" id="sn_api_pass" type="password" class="regular-text" value="<?php echo esc_attr($api_pass); ?>" autocomplete="off" required>
                            <p class="description">Huom: salasana tallennetaan selväkielisenä.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sn_table_id">TablePress taulukon ID</label></th>
                        <td><input name="<?php echo esc_attr(\SN_TP_SYNC_OPT_TABLE_ID); ?>" id="sn_table_id" type="number" min="1" step="1" class="small-text" value="<?php echo esc_attr($table_id ?: ''); ?>" required></td>
                    </tr>
                    </tbody>
                </table>
                <?php submit_button('Tallenna asetukset'); ?>
            </form>

            <hr>

            <h2>Manuaalinen ajo</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('sn_tp_incr_run'); ?>
                <input type="hidden" name="action" value="servicenow_tablepress_sync_run">
                <label><input type="checkbox" name="dry_run" value="1"> Dry-run (ei tallenna muutoksia)</label>
                <label style="margin-left:12px;"><input type="checkbox" name="force_run" value="1"> Force-run (ohita last-sync)</label><br><br>
                <?php submit_button('Aja synkronointi nyt', 'primary', 'submit', false); ?>
            </form>

            <h2>Tilatiedot</h2>
            <table class="widefat striped" style="max-width: 720px;">
                <tbody>
                <tr><th style="width:220px;">Viimeisin haettu aikaleima (UTC)</th><td><?php echo $last_sync !== '' ? esc_html($last_sync) : '—'; ?></td></tr>
                <tr><th>Viimeisin ajo (UTC)</th><td><?php echo isset($last_run['time_utc']) ? esc_html($last_run['time_utc']) : '—'; ?></td></tr>
                <tr><th>Tulos</th><td><?php echo isset($last_run['success']) ? ($last_run['success'] ? 'Onnistui' : 'Epäonnistui') : '—'; ?></td></tr>
                <tr><th>Rivejä</th><td><?php echo isset($last_run['rows']) ? (int)$last_run['rows'] : 0; ?></td></tr>
                <tr><th>Päivitettyjä</th><td><?php echo isset($last_run['updated']) ? (int)$last_run['updated'] : 0; ?></td></tr>
                <tr><th>Viesti</th><td><?php echo isset($last_run['message']) ? esc_html($last_run['message']) : '—'; ?></td></tr>
                </tbody>
            </table>
        </div>
        <?php
    }
}
