<?php
namespace ServiceNowTablePressSync;

class Sync
{
    // GET and SET last sync timestamp
    public static function get_last_sync(int $table_id): string {
        $map = get_option(\SN_TP_SYNC_OPT_LAST_SYNC_MAP, array());
        return is_array($map) && isset($map[$table_id]) ? (string)$map[$table_id] : '';
    }
    public static function set_last_sync(int $table_id, string $ts): void {
        $map = get_option(\SN_TP_SYNC_OPT_LAST_SYNC_MAP, array());
        if (!is_array($map)) { $map = array(); }
        $map[$table_id] = $ts;
        update_option(\SN_TP_SYNC_OPT_LAST_SYNC_MAP, $map, false);
    }

    // FETCH data from ServiceNow API
    public static function fetch_items(string $url, string $user, string $pass, int $timeout = 30, string $since = '') {
        $args = array(
            'headers'  => array(
                'Authorization' => 'Basic ' . base64_encode($user . ':' . $pass),
                'Accept'        => 'application/json',
            ),
            'timeout'  => $timeout,
            'sslverify'=> true,
        );
        $resp = wp_remote_get($url, $args);
        if (is_wp_error($resp)) return $resp;
        $code = (int) wp_remote_retrieve_response_code($resp);
        $body = (string) wp_remote_retrieve_body($resp);
        if ($code !== 200) return new \WP_Error('api_http_error', 'HTTP ' . $code . ' - ' . substr($body, 0, 200));
        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) return new \WP_Error('api_json_error', 'Invalid JSON: ' . json_last_error_msg());
        if (!isset($data['result']) || !is_array($data['result'])) return array();
        return $data['result'];
    }

    // Parse updated timestamp
    protected static function parse_updated_ts(string $s): int {
        $s = trim($s);
        if ($s === '') return 0;
        $dt = \DateTime::createFromFormat('d.m.Y H:i:s', $s);
        if ($dt !== false) return $dt->getTimestamp();
        $dt = \DateTime::createFromFormat('d.m.Y H:i', $s);
        if ($dt !== false) return $dt->getTimestamp();
        $ts = strtotime($s);
        return $ts !== false ? $ts : 0;
    }

    // Format updated display value
    protected static function format_updated_display($v): string {
        if ($v instanceof \DateTimeInterface) return $v->format('d.m.Y H:i');
        if (is_string($v)) {
            $dt = \DateTime::createFromFormat('d.m.Y H:i:s', $v);
            if ($dt !== false) return $dt->format('d.m.Y H:i');
            $dt = \DateTime::createFromFormat('d.m.Y H:i', $v);
            if ($dt !== false) return $dt->format('d.m.Y H:i');
            $ts = strtotime($v);
            if ($ts !== false) return date('d.m.Y H:i', $ts);
            return $v;
        }
        if (is_scalar($v)) return (string)$v;
        return wp_json_encode($v);
    }

    // Helper function to determine if a record should be skipped
    private static function should_skip_record(array $rec, array $skip_statuses): bool {
    $educ = isset($rec['educators'])
        ? mb_strtolower(trim((string)$rec['educators']), 'UTF-8')
        : '';

    $stud = isset($rec['students'])
        ? mb_strtolower(trim((string)$rec['students']), 'UTF-8')
        : '';

    return in_array($educ, $skip_statuses, true)
        && in_array($stud, $skip_statuses, true);
    }

    // RUN the incremental sync
    public static function run_incremental(int $table_id, string $api_url, string $api_user, string $api_pass, bool $dry_run = false, bool $force_run = false)
    {
        if (!class_exists('TablePress')) return new \WP_Error('tablepress_missing', 'TablePress is not available.');
        $model = \TablePress::$model_table;

        $lastSync = self::get_last_sync($table_id);
        $items = self::fetch_items($api_url, $api_user, $api_pass, 30, '');
        if (is_wp_error($items)) return $items;

        // Load table fully for safe save()
        $table = $model->load($table_id, true, true);
        if (is_wp_error($table)) return new \WP_Error('table_missing', 'TablePress table not found (ID=' . $table_id . ').');

        // SAVE existing options so we can restore them before save()
        $existing_options = isset($table['options']) && is_array($table['options']) ? $table['options'] : array();

        if (!isset($table['options']) || !is_array($table['options']) || !isset($table['visibility']) || !is_array($table['visibility'])) {
            if (method_exists($model, 'get_table_template') && method_exists($model, 'prepare_table')) {
                $table = $model->prepare_table($model->get_table_template(), $table, false);
                if (is_wp_error($table)) return $table;
            } else {
                $table['options']    = isset($table['options'])    && is_array($table['options'])    ? $table['options']    : array();
                $table['visibility'] = isset($table['visibility']) && is_array($table['visibility']) ? $table['visibility'] : array();
            }
        }

        // Ensure 9-column schema with Number first
        $defaultHeader9 = array('Number','Name','Educators','Students','Chargeable','Subjects','Departments','Additional Info','Updated');
        $header = (isset($table['data'][0]) && is_array($table['data'][0])) ? $table['data'][0] : $defaultHeader9;
        $hasNumber = isset($header[0]) && strip_tags((string)$header[0]) === 'Number';
        if (!$hasNumber) { $header = $defaultHeader8; }
        $header = array_slice(array_pad($header, 9, ''), 0, 9);

        // Build existing map by Number (col 0)
        $existing = isset($table['data']) && is_array($table['data']) ? $table['data'] : array();
        $map = array(); $order = array();
        foreach ($existing as $idx => $row) {
            if ($idx === 0) continue;
            $num = isset($row[0]) ? trim((string)$row[0]) : '';
            if ($num === '') continue;
            $map[$num] = $row; $order[] = $num;
        }

        // Row builder helpers
        $scalar = function($v): string {
            if ($v instanceof \DateTimeInterface) return $v->format('d.m.Y H:i');
            if (is_scalar($v)) return (string)$v;
            if (is_array($v)) return implode(', ', array_map('strval', $v));
            return wp_json_encode($v);
        };
        $implode_if_array = function($v): string { return is_array($v) ? implode(', ', array_map('strval', $v)) : (string)$v; };
        $badge = function($value): string {
            $status = trim((string)$value);
            if ($status === '') return '';

            $slug = sanitize_title($status);

            return sprintf(
                '<span class="tp-badge tp-badge-%s">%s</span>',
                esc_attr($slug),
                esc_html($status)
            );
        };
        $toRow = function(array $record) use ($scalar, $implode_if_array, $badge): array {

            // SORT departments: if it's an array, sort case-insensitive natural order before imploding
            $departments_raw = $record['departments'] ?? '';
            $departments_str = '';
            if (is_array($departments_raw)) {
                $departments = array_map('strval', $departments_raw);
                usort($departments, function($a, $b) { return strnatcasecmp($a, $b); });
                $departments_str = '<div class="tp-departments">' . implode(', ', $departments) . '</div>';
            } else {
                $departments_str = (string)$departments_raw;
            }

            // Combine educatorDescription and studentDescription fields into additional info
            $additionalInfo = '';
            $educatorDesc = trim((string)($record['educatorDescription'] ?? ''));
            $studentDesc = trim((string)($record['studentDescription'] ?? ''));
            if ($educatorDesc !== '' || $studentDesc !== '') {
                
                if ($educatorDesc !== '') {
                    $additionalInfo .= '<div class="tp-additional-info"><span class="tp-additional-info-header">Opettajat: </span> ' . $scalar($educatorDesc) . '</div>';
                }
                if ($studentDesc !== '') {
                    $additionalInfo .= '<div class="tp-additional-info"><span class="tp-additional-info-header">Oppilaat: </span> ' . $scalar($studentDesc) . '</div>';
                }
               
            } else {
                $additionalInfo = '<div class="tp-additional-info">Ei lisätietoja</div>';
            }

            return array(
                $scalar($record['number']      ?? ''),
                $scalar($record['name']        ?? ''),
                $badge($record['educators'] ?? ''),
                $badge($record['students']  ?? ''),
                $scalar($record['chargeable']  ?? ''),
                $scalar($implode_if_array($record['subjects']    ?? '')),
                $scalar($departments_str),
                $additionalInfo,
                self::format_updated_display($record['updated'] ?? ''),
            );
        };

        $updatedCount = 0;
        $maxUpdatedTs = 0;
        $initialRebuild = $force_run || ($lastSync === '') || !$hasNumber;

        // Statuses to skip
        $skip_statuses = array('ei käsitelty', 'tarkastettavana');

        if ($initialRebuild) {
            $newData = array(); $newData[0] = $header; $order = array();
            foreach ($items as $rec) {
                if (!is_array($rec)) continue;
                $num = isset($rec['number']) ? trim((string)$rec['number']) : '';
                if ($num === '') continue;
                $upd = isset($rec['updated']) ? (string)$rec['updated'] : '';
                $ts = $upd !== '' ? self::parse_updated_ts($upd) : 0;
                if ($ts > $maxUpdatedTs) $maxUpdatedTs = $ts;

                if (self::should_skip_record($rec, $skip_statuses)) {
                    continue;
                }

                $row = $toRow($rec);
                $newData[] = $row; $order[] = $num; $updatedCount++;
            }
            if ($dry_run) return array('dry_run'=>true, 'rows'=>max(0, count($newData)-1), 'updated'=>$updatedCount);
            $table['data'] = $newData;
        } else {
            $lastTs = 0;
            if ($lastSync !== '') $lastTs = self::parse_updated_ts($lastSync);

            $pendingNew = array();
            foreach ($items as $rec) {
                if (!is_array($rec)) continue;
                $num = isset($rec['number']) ? trim((string)$rec['number']) : '';
                if ($num === '') continue;
                $upd = isset($rec['updated']) ? (string)$rec['updated'] : '';
                $updTs = $upd !== '' ? self::parse_updated_ts($upd) : 0;
                if ($updTs > $lastTs) {
                    if ($updTs > $maxUpdatedTs) $maxUpdatedTs = $updTs;

                    if (self::should_skip_record($rec, $skip_statuses)) {
                        continue;
                    }

                    $row = $toRow($rec);
                    if (isset($map[$num])) $map[$num] = $row;
                    else $pendingNew[$num] = $row;
                    $updatedCount++;
                }
            }
            $newData = array(); $newData[0] = $header;
            foreach ($order as $n) { if (isset($map[$n])) $newData[] = $map[$n]; }
            if (!empty($pendingNew)) {
                ksort($pendingNew, SORT_NATURAL | SORT_FLAG_CASE);
                foreach ($pendingNew as $n => $row) { $newData[] = $row; $order[] = $n; }
            }
            if ($dry_run) return array('dry_run'=>true, 'rows'=>max(0, count($newData)-1), 'updated'=>$updatedCount);
            $table['data'] = $newData;
        }

        if (!isset($table['visibility']) || !is_array($table['visibility'])) $table['visibility'] = array();
        if (!isset($table['visibility']['columns']) || !is_array($table['visibility']['columns'])) $table['visibility']['columns'] = array();
        if (!in_array(1, $table['visibility']['columns'], true)) {
            $table['visibility']['columns'][] = 1;
        }

        // BEFORE saving: restore existing options so we don't override user's settings (sortby etc.)
        $table['options'] = $existing_options;

        $save = $model->save($table);
        if (is_wp_error($save)) return $save;

        if ($maxUpdatedTs > 0) {
            $lastSyncToStore = gmdate('Y-m-d H:i:s', $maxUpdatedTs);
            self::set_last_sync($table_id, $lastSyncToStore);
        } else {
            self::set_last_sync($table_id, current_time('mysql', true));
        }

        return array('dry_run'=>false, 'rows'=>max(0, count($table['data'])-1), 'updated'=>$updatedCount);
    }
}
