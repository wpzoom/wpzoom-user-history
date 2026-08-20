<?php
/**
 * Activity Log list table for User History plugin.
 *
 * @package UserHistory
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Renders the activity log under User History > Activity Log.
 */
class WPZOOM_User_History_Activity_List_Table extends WP_List_Table {

    /**
     * Activity log instance.
     *
     * @var WPZOOM_User_History_Activity_Log
     */
    private $log;

    /**
     * Constructor.
     *
     * @param WPZOOM_User_History_Activity_Log $log Activity log instance.
     */
    public function __construct($log) {
        $this->log = $log;

        parent::__construct([
            'singular' => 'activity',
            'plural'   => 'activities',
            'ajax'     => false,
            'screen'   => get_current_screen(),
        ]);
    }

    /**
     * Columns.
     *
     * @return array
     */
    public function get_columns() {
        return [
            'created_at'  => __('Date', 'wpzoom-user-history'),
            'user'        => __('User', 'wpzoom-user-history'),
            'event'       => __('Event', 'wpzoom-user-history'),
            'description' => __('Description', 'wpzoom-user-history'),
            'ip_address'  => __('IP Address', 'wpzoom-user-history'),
        ];
    }

    /**
     * Sortable columns.
     *
     * @return array
     */
    protected function get_sortable_columns() {
        return [
            'created_at' => ['created_at', true],
        ];
    }

    /**
     * Read a filter value from the request.
     *
     * @param string $key     Query var.
     * @param string $default Default.
     * @return string
     */
    private function get_request_var($key, $default = '') {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filtering
        return isset($_REQUEST[ $key ]) ? sanitize_text_field(wp_unslash($_REQUEST[ $key ])) : $default;
    }

    /**
     * Fetch items.
     */
    public function prepare_items() {
        $per_page = $this->get_items_per_page('wpzoom_user_history_activity_per_page', 30);

        $result = $this->log->get_entries([
            'per_page' => $per_page,
            'page'     => $this->get_pagenum(),
            'user_id'  => (int) $this->get_request_var('activity_user', 0),
            'action'   => sanitize_key($this->get_request_var('activity_action')),
            'group'    => sanitize_key($this->get_request_var('activity_group')),
            'search'   => $this->get_request_var('s'),
            'order'    => strtolower($this->get_request_var('order', 'desc')) === 'asc' ? 'ASC' : 'DESC',
        ]);

        $this->items = $result['items'];

        $this->set_pagination_args([
            'total_items' => $result['total'],
            'per_page'    => $per_page,
            'total_pages' => (int) ceil($result['total'] / $per_page),
        ]);

        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns(), 'created_at'];
    }

    /**
     * Filter dropdowns above the table.
     *
     * @param string $which 'top' or 'bottom'.
     */
    protected function extra_tablenav($which) {
        if ($which !== 'top') {
            return;
        }

        $current_user   = (int) $this->get_request_var('activity_user', 0);
        $current_group  = sanitize_key($this->get_request_var('activity_group'));
        $current_action = sanitize_key($this->get_request_var('activity_action'));
        ?>
        <div class="alignleft actions">
            <label for="wpzoom-activity-user" class="screen-reader-text"><?php esc_html_e('Filter by user', 'wpzoom-user-history'); ?></label>
            <select name="activity_user" id="wpzoom-activity-user">
                <option value="0"><?php esc_html_e('All users', 'wpzoom-user-history'); ?></option>
                <option value="-1" <?php selected($current_user, -1); ?>><?php esc_html_e('Guests / system', 'wpzoom-user-history'); ?></option>
                <?php
                $ids = $this->log->get_actor_ids();
                if (!empty($ids)) {
                    $users = get_users(['include' => $ids, 'orderby' => 'display_name', 'fields' => ['ID', 'display_name', 'user_login']]);
                    foreach ($users as $user) {
                        printf(
                            '<option value="%1$d" %2$s>%3$s</option>',
                            (int) $user->ID,
                            selected($current_user, (int) $user->ID, false),
                            esc_html($user->display_name . ' (' . $user->user_login . ')')
                        );
                    }
                }
                ?>
            </select>

            <label for="wpzoom-activity-group" class="screen-reader-text"><?php esc_html_e('Filter by event group', 'wpzoom-user-history'); ?></label>
            <select name="activity_group" id="wpzoom-activity-group">
                <option value=""><?php esc_html_e('All groups', 'wpzoom-user-history'); ?></option>
                <?php foreach (WPZOOM_User_History_Activity_Log::get_event_groups() as $slug => $label) : ?>
                    <option value="<?php echo esc_attr($slug); ?>" <?php selected($current_group, $slug); ?>><?php echo esc_html($this->short_group_label($slug, $label)); ?></option>
                <?php endforeach; ?>
            </select>

            <label for="wpzoom-activity-action" class="screen-reader-text"><?php esc_html_e('Filter by event', 'wpzoom-user-history'); ?></label>
            <select name="activity_action" id="wpzoom-activity-action">
                <option value=""><?php esc_html_e('All events', 'wpzoom-user-history'); ?></option>
                <?php
                $groups = WPZOOM_User_History_Activity_Log::get_action_groups();
                foreach (WPZOOM_User_History_Activity_Log::get_action_labels() as $action => $label) {
                    printf(
                        '<option value="%1$s" %2$s>%3$s</option>',
                        esc_attr($action),
                        selected($current_action, $action, false),
                        esc_html($label)
                    );
                }
                ?>
            </select>

            <?php submit_button(__('Filter', 'wpzoom-user-history'), '', 'filter_action', false); ?>
        </div>
        <?php
    }

    /**
     * Group label without the parenthetical detail, for compact dropdowns.
     *
     * @param string $slug  Group slug.
     * @param string $label Full label.
     * @return string
     */
    private function short_group_label($slug, $label) {
        $short = [
            'content'    => __('Content', 'wpzoom-user-history'),
            'media'      => __('Media', 'wpzoom-user-history'),
            'comments'   => __('Comments', 'wpzoom-user-history'),
            'users'      => __('Users', 'wpzoom-user-history'),
            'logins'     => __('Logins', 'wpzoom-user-history'),
            'extensions' => __('Plugins & Themes', 'wpzoom-user-history'),
            'settings'   => __('Settings', 'wpzoom-user-history'),
        ];
        return isset($short[ $slug ]) ? $short[ $slug ] : $label;
    }

    /**
     * Empty state.
     */
    public function no_items() {
        esc_html_e('No activity recorded yet.', 'wpzoom-user-history');
    }

    /**
     * Date column.
     *
     * @param object $item Row.
     * @return string
     */
    protected function column_created_at($item) {
        $timestamp = strtotime($item->created_at);
        if (!$timestamp) {
            return esc_html($item->created_at);
        }

        $full = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
        $diff = human_time_diff($timestamp, current_time('timestamp'));

        return sprintf(
            '<span title="%1$s">%2$s</span><br><span class="user-history-activity-ago">%3$s</span>',
            esc_attr($full),
            esc_html($full),
            /* translators: %s: human-readable time difference */
            esc_html(sprintf(__('%s ago', 'wpzoom-user-history'), $diff))
        );
    }

    /**
     * User column.
     *
     * @param object $item Row.
     * @return string
     */
    protected function column_user($item) {
        $user_id = (int) $item->user_id;

        if ($user_id === 0) {
            $context = WPZOOM_User_History_Activity_Log::get_context($item);
            if (!empty($context['via']) && $context['via'] !== 'rest') {
                return '<em>' . esc_html__('System', 'wpzoom-user-history') . '</em>';
            }
            return '<em>' . esc_html__('Guest', 'wpzoom-user-history') . '</em>';
        }

        $user = get_userdata($user_id);
        if (!$user) {
            /* translators: %d: user ID */
            return '<em>' . esc_html(sprintf(__('Deleted user #%d', 'wpzoom-user-history'), $user_id)) . '</em>';
        }

        $avatar = get_avatar($user_id, 24, '', '', ['class' => 'user-history-activity-avatar']);
        $name   = esc_html($user->display_name);
        $login  = esc_html($user->user_login);

        if (current_user_can('edit_user', $user_id)) {
            $name = '<a href="' . esc_url(get_edit_user_link($user_id)) . '">' . $name . '</a>';
        }

        $filter_url = add_query_arg(['page' => 'wpzoom-user-history', 'activity_user' => $user_id], admin_url('admin.php'));

        return $avatar . ' <span class="user-history-activity-user"><strong>' . $name . '</strong><br>'
            . '<a class="user-history-activity-login" href="' . esc_url($filter_url) . '" title="' . esc_attr__('Show only this user', 'wpzoom-user-history') . '">' . $login . '</a></span>';
    }

    /**
     * Event column (badge).
     *
     * @param object $item Row.
     * @return string
     */
    protected function column_event($item) {
        $labels = WPZOOM_User_History_Activity_Log::get_action_labels();
        $groups = WPZOOM_User_History_Activity_Log::get_action_groups();
        $label  = isset($labels[ $item->action ]) ? $labels[ $item->action ] : $item->action;
        $group  = isset($groups[ $item->action ]) ? $groups[ $item->action ] : 'other';

        return sprintf(
            '<span class="user-history-activity-badge user-history-activity-badge-%1$s">%2$s</span>',
            esc_attr($group),
            esc_html($label)
        );
    }

    /**
     * Description column.
     *
     * @param object $item Row.
     * @return string
     */
    protected function column_description($item) {
        return wp_kses(
            $this->log->describe($item),
            [
                'a'      => ['href' => [], 'title' => []],
                'strong' => [],
                'code'   => [],
                'em'     => [],
                'br'     => [],
                'span'   => ['class' => []],
                'del'    => [],
                'ins'    => [],
            ]
        );
    }

    /**
     * IP column.
     *
     * @param object $item Row.
     * @return string
     */
    protected function column_ip_address($item) {
        return $item->ip_address !== '' ? '<code>' . esc_html($item->ip_address) . '</code>' : '&mdash;';
    }

    /**
     * Fallback column.
     *
     * @param object $item        Row.
     * @param string $column_name Column.
     * @return string
     */
    protected function column_default($item, $column_name) {
        return isset($item->$column_name) ? esc_html($item->$column_name) : '';
    }
}
