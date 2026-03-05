<?php
/**
 * Admin UI for User History plugin.
 *
 * @package UserHistory
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles admin UI for user history display, AJAX endpoints for
 * history loading/clearing, username changes, user search extension,
 * and the delete user button.
 */
class WPZOOM_User_History_Admin {

    /**
     * Reference to main plugin instance.
     *
     * @var WPZOOM_User_History
     */
    private $plugin;

    /**
     * Constructor — registers admin hooks.
     *
     * @param WPZOOM_User_History $plugin Main plugin instance.
     */
    public function __construct($plugin) {
        $this->plugin = $plugin;

        // Admin UI hooks
        add_action('edit_user_profile', [$this, 'display_history_section'], 99);
        add_action('show_user_profile', [$this, 'display_history_section'], 99);
        add_action('edit_user_profile', [$this, 'display_delete_user_button'], 100);
        add_action('show_user_profile', [$this, 'display_delete_user_button'], 100);

        // Enqueue admin styles and scripts
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

        // AJAX handlers
        add_action('wp_ajax_wpzoom_user_history_load_more', [$this, 'ajax_load_more_history']);
        add_action('wp_ajax_wpzoom_user_history_change_username', [$this, 'ajax_change_username']);
        add_action('wp_ajax_wpzoom_user_history_clear', [$this, 'ajax_clear_history']);
        add_action('wp_ajax_wpzoom_user_history_destroy_sessions', [$this, 'ajax_destroy_sessions']);

        // Extend user search to include history
        add_action('pre_user_query', [$this, 'extend_user_search']);
    }

    // =========================================================================
    // Assets
    // =========================================================================

    /**
     * Enqueue admin assets on user-edit.php and profile.php.
     *
     * @param string $hook Current admin page hook.
     */
    public function enqueue_admin_assets($hook) {
        // Only load on user edit/profile pages (users.php CSS is handled by Lock class)
        if (!in_array($hook, ['user-edit.php', 'profile.php'])) {
            return;
        }

        wp_enqueue_style(
            'wpzoom-user-history-admin',
            WPZOOM_USER_HISTORY_PLUGIN_URL . 'assets/css/admin.css',
            [],
            WPZOOM_USER_HISTORY_VERSION
        );

        wp_enqueue_script(
            'wpzoom-user-history-admin',
            WPZOOM_USER_HISTORY_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            WPZOOM_USER_HISTORY_VERSION,
            true
        );

        // Get user ID from URL or current user
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce not required for reading user_id, value is cast to int
        $user_id = isset($_GET['user_id']) ? (int) $_GET['user_id'] : get_current_user_id();

        wp_localize_script('wpzoom-user-history-admin', 'wpzoom_user_history_data', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('wpzoom_user_history_nonce'),
            'changeUsernameNonce' => wp_create_nonce('wpzoom_user_history_change_username'),
            'clearHistoryNonce' => wp_create_nonce('wpzoom_user_history_clear'),
            'destroySessionsNonce' => wp_create_nonce('wpzoom_user_history_destroy_sessions'),
            'lockNonce' => wp_create_nonce('wpzoom_user_history_lock'),
            'userId'  => $user_id,
            'i18n'    => [
                'change'        => __('Change', 'wpzoom-user-history'),
                'cancel'        => __('Cancel', 'wpzoom-user-history'),
                'pleaseWait'    => __('Please wait...', 'wpzoom-user-history'),
                'errorGeneric'  => __('Something went wrong. Please try again.', 'wpzoom-user-history'),
                'confirmClear'  => __('Are you sure you want to clear all history for this user? This cannot be undone.', 'wpzoom-user-history'),
                'clearing'      => __('Clearing...', 'wpzoom-user-history'),
                'clearLog'      => __('Clear Log', 'wpzoom-user-history'),
                'lockAccount'   => __('Lock Account', 'wpzoom-user-history'),
                'unlockAccount' => __('Unlock Account', 'wpzoom-user-history'),
                'confirmLock'   => __('Are you sure you want to lock this user? They will be logged out immediately.', 'wpzoom-user-history'),
                'confirmUnlock' => __('Are you sure you want to unlock this user?', 'wpzoom-user-history'),
                'confirmDestroySessions' => __('Are you sure you want to log out this user from all sessions?', 'wpzoom-user-history'),
                'destroyingSessions' => __('Logging out...', 'wpzoom-user-history'),
                'logOutEverywhere' => __('Log Out Everywhere', 'wpzoom-user-history'),
            ],
        ]);
    }

    // =========================================================================
    // User Edit Page — History Section
    // =========================================================================

    /**
     * Display history section on user edit page.
     *
     * @param WP_User $user The user being edited.
     */
    public function display_history_section($user) {
        // Only show to admins
        if (!current_user_can('edit_users')) {
            return;
        }

        $changes        = $this->plugin->get_user_history($user->ID, 20, 0, 'changes');
        $changes_count  = $this->plugin->get_user_history_count($user->ID, 'changes');
        $logins         = $this->plugin->get_user_history($user->ID, 20, 0, 'logins');
        $logins_count   = $this->plugin->get_user_history_count($user->ID, 'logins');
        $session_manager = WP_Session_Tokens::get_instance($user->ID);
        $sessions        = $session_manager->get_all();
        $sessions_count  = count($sessions);
        ?>
        <div class="user-history-section">
            <h2><?php esc_html_e('Account History', 'wpzoom-user-history'); ?></h2>

            <div class="user-history-tabs">
                <a href="#" class="user-history-tab active" data-tab="changes">
                    <?php esc_html_e('Changes', 'wpzoom-user-history'); ?>
                    <?php if ($changes_count > 0): ?>
                        <span class="user-history-tab-count"><?php echo esc_html($changes_count); ?></span>
                    <?php endif; ?>
                </a>
                <a href="#" class="user-history-tab" data-tab="logins">
                    <?php esc_html_e('Logins', 'wpzoom-user-history'); ?>
                    <?php if ($logins_count > 0): ?>
                        <span class="user-history-tab-count"><?php echo esc_html($logins_count); ?></span>
                    <?php endif; ?>
                </a>
                <a href="#" class="user-history-tab" data-tab="sessions">
                    <?php esc_html_e('Sessions', 'wpzoom-user-history'); ?>
                    <?php if ($sessions_count > 0): ?>
                        <span class="user-history-tab-count"><?php echo esc_html($sessions_count); ?></span>
                    <?php endif; ?>
                </a>
            </div>

            <!-- Changes tab -->
            <div class="user-history-tab-content active" id="user-history-tab-changes" data-user-id="<?php echo esc_attr($user->ID); ?>">
                <?php if (empty($changes)): ?>
                    <p class="user-history-empty">
                        <?php esc_html_e('No changes have been recorded yet.', 'wpzoom-user-history'); ?>
                    </p>
                <?php else: ?>
                    <table class="widefat user-history-table">
                        <thead>
                            <tr>
                                <th class="column-date"><?php esc_html_e('Date', 'wpzoom-user-history'); ?></th>
                                <th class="column-field"><?php esc_html_e('Field', 'wpzoom-user-history'); ?></th>
                                <th class="column-change"><?php esc_html_e('Change', 'wpzoom-user-history'); ?></th>
                                <th class="column-by"><?php esc_html_e('Changed By', 'wpzoom-user-history'); ?></th>
                                <th class="column-ip"><?php esc_html_e('IP', 'wpzoom-user-history'); ?></th>
                            </tr>
                        </thead>
                        <tbody class="user-history-tbody">
                            <?php
                            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped in render_history_rows()
                            echo $this->render_history_rows($changes);
                            ?>
                        </tbody>
                    </table>

                    <div class="user-history-actions">
                        <?php if ($changes_count > 20): ?>
                            <button type="button" class="button user-history-load-more"
                                    data-tab="changes" data-offset="20" data-total="<?php echo esc_attr($changes_count); ?>">
                                <?php esc_html_e('Load More', 'wpzoom-user-history'); ?>
                            </button>
                        <?php endif; ?>
                        <button type="button" class="button user-history-clear-log" id="user-history-clear-log">
                            <?php esc_html_e('Clear Log', 'wpzoom-user-history'); ?>
                        </button>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Logins tab -->
            <div class="user-history-tab-content" id="user-history-tab-logins" data-user-id="<?php echo esc_attr($user->ID); ?>">
                <?php if (empty($logins)): ?>
                    <p class="user-history-empty">
                        <?php esc_html_e('No login events have been recorded yet.', 'wpzoom-user-history'); ?>
                    </p>
                <?php else: ?>
                    <table class="widefat user-history-table">
                        <thead>
                            <tr>
                                <th class="column-date"><?php esc_html_e('Date', 'wpzoom-user-history'); ?></th>
                                <th class="column-event"><?php esc_html_e('Event', 'wpzoom-user-history'); ?></th>
                                <th class="column-ip"><?php esc_html_e('IP Address', 'wpzoom-user-history'); ?></th>
                                <th class="column-browser"><?php esc_html_e('Browser', 'wpzoom-user-history'); ?></th>
                            </tr>
                        </thead>
                        <tbody class="user-history-tbody">
                            <?php
                            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped in render_login_rows()
                            echo $this->render_login_rows($logins);
                            ?>
                        </tbody>
                    </table>

                    <div class="user-history-actions">
                        <?php if ($logins_count > 20): ?>
                            <button type="button" class="button user-history-load-more"
                                    data-tab="logins" data-offset="20" data-total="<?php echo esc_attr($logins_count); ?>">
                                <?php esc_html_e('Load More', 'wpzoom-user-history'); ?>
                            </button>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Sessions tab -->
            <div class="user-history-tab-content" id="user-history-tab-sessions" data-user-id="<?php echo esc_attr($user->ID); ?>">
                <?php if (empty($sessions)): ?>
                    <p class="user-history-empty">
                        <?php esc_html_e('No active sessions.', 'wpzoom-user-history'); ?>
                    </p>
                <?php else: ?>
                    <?php
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped in render_sessions_tab()
                    echo $this->render_sessions_tab($user, $sessions);
                    ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    // =========================================================================
    // User Edit Page — Delete Button
    // =========================================================================

    /**
     * Display delete user button on user edit page.
     *
     * @param WP_User $user The user being edited.
     */
    public function display_delete_user_button($user) {
        // Only show to users who can delete users
        if (!current_user_can('delete_users')) {
            return;
        }

        // Don't allow deleting yourself
        if ($user->ID === get_current_user_id()) {
            return;
        }

        // Don't show for super admins on multisite (they can't be deleted this way)
        if (is_multisite() && is_super_admin($user->ID)) {
            return;
        }

        $delete_url = wp_nonce_url(
            admin_url('users.php?action=delete&user=' . $user->ID),
            'bulk-users'
        );
        ?>
        <div class="user-history-section user-history-delete-section">
            <h2><?php esc_html_e('Delete User', 'wpzoom-user-history'); ?></h2>
            <p class="description">
                <?php esc_html_e('Permanently delete this user account. You will be able to reassign their content to another user.', 'wpzoom-user-history'); ?>
            </p>
            <p>
                <a href="<?php echo esc_url($delete_url); ?>" class="button button-link-delete">
                    <?php esc_html_e('Delete User', 'wpzoom-user-history'); ?>
                </a>
            </p>
        </div>
        <?php
    }

    // =========================================================================
    // History Rendering
    // =========================================================================

    /**
     * Render history table rows.
     *
     * @param array $history Array of history entry objects.
     * @return string HTML output.
     */
    public function render_history_rows($history) {
        $output = '';

        foreach ($history as $entry) {
            $changed_by_user = get_userdata($entry->changed_by);
            $changed_by_name = $changed_by_user ? $changed_by_user->display_name : __('Unknown', 'wpzoom-user-history');
            $changed_by_link = $changed_by_user ? get_edit_user_link($entry->changed_by) : '#';

            $is_self = ($entry->user_id == $entry->changed_by);

            $output .= '<tr class="user-history-entry type-' . esc_attr($entry->change_type) . '">';

            // Date column
            $output .= '<td class="column-date">';
            $output .= '<span class="history-date">' . esc_html(date_i18n(get_option('date_format'), strtotime($entry->created_at))) . '</span>';
            $output .= '<span class="history-time">' . esc_html(date_i18n(get_option('time_format'), strtotime($entry->created_at))) . '</span>';
            $output .= '</td>';

            // Field column
            $output .= '<td class="column-field">';
            $output .= '<strong>' . esc_html($entry->field_label) . '</strong>';
            $output .= '</td>';

            // Change column
            $output .= '<td class="column-change">';
            if ($entry->change_type === 'create') {
                $output .= '<span class="history-new-value">' . esc_html($entry->new_value) . '</span>';
            } elseif ($entry->field_name === 'account_locked') {
                $output .= '<span class="history-new-value">' . esc_html($entry->field_label) . '</span>';
            } elseif ($entry->field_name === 'user_pass') {
                // Password changes just show "Changed" - no values ever stored
                $output .= '<span class="history-new-value">' . esc_html__('Changed', 'wpzoom-user-history') . '</span>';
            } else {
                if (!empty($entry->old_value)) {
                    $output .= '<span class="history-old-value">' . esc_html($this->truncate_value($entry->old_value)) . '</span>';
                    $output .= ' <span class="history-arrow">&rarr;</span> ';
                }
                $output .= '<span class="history-new-value">' . esc_html($this->truncate_value($entry->new_value)) . '</span>';
            }
            $output .= '</td>';

            // Changed by column
            $output .= '<td class="column-by">';
            if ($is_self) {
                $output .= '<span class="history-self">' . esc_html__('Self', 'wpzoom-user-history') . '</span>';
            } else {
                $output .= '<a href="' . esc_url($changed_by_link) . '">' . esc_html($changed_by_name) . '</a>';
            }
            $output .= '</td>';

            // IP column (only show for self-changes)
            $output .= '<td class="column-ip">';
            if ($is_self && !empty($entry->ip_address)) {
                $output .= '<a href="https://ipinfo.io/' . esc_attr($entry->ip_address) . '" target="_blank" rel="noopener noreferrer">' . esc_html($entry->ip_address) . '</a>';
            } else {
                $output .= '&mdash;';
            }
            $output .= '</td>';

            $output .= '</tr>';
        }

        return $output;
    }

    /**
     * Render login history table rows.
     *
     * @param array $history Array of login history entry objects.
     * @return string HTML output.
     */
    public function render_login_rows($history) {
        $output = '';

        foreach ($history as $entry) {
            $row_class = 'user-history-entry type-' . esc_attr($entry->change_type);

            $output .= '<tr class="' . $row_class . '">';

            // Date column
            $output .= '<td class="column-date">';
            $output .= '<span class="history-date">' . esc_html(date_i18n(get_option('date_format'), strtotime($entry->created_at))) . '</span>';
            $output .= '<span class="history-time">' . esc_html(date_i18n(get_option('time_format'), strtotime($entry->created_at))) . '</span>';
            $output .= '</td>';

            // Event column
            $output .= '<td class="column-event">';
            $output .= '<span class="history-event-label event-' . esc_attr($entry->change_type) . '">' . esc_html($entry->field_label) . '</span>';
            $output .= '</td>';

            // IP column
            $output .= '<td class="column-ip">';
            if (!empty($entry->ip_address)) {
                $output .= '<a href="https://ipinfo.io/' . esc_attr($entry->ip_address) . '" target="_blank" rel="noopener noreferrer">' . esc_html($entry->ip_address) . '</a>';
            } else {
                $output .= '&mdash;';
            }
            $output .= '</td>';

            // Browser column
            $output .= '<td class="column-browser">';
            if (!empty($entry->new_value)) {
                $output .= esc_html($this->parse_user_agent($entry->new_value));
            } else {
                $output .= '&mdash;';
            }
            $output .= '</td>';

            $output .= '</tr>';
        }

        return $output;
    }

    /**
     * Render the sessions tab content.
     *
     * @param WP_User $user     The user being viewed.
     * @param array   $sessions Array of session data from WP_Session_Tokens.
     * @return string HTML output.
     */
    public function render_sessions_tab($user, $sessions) {
        // Determine the current session token for highlighting
        $current_token = '';
        if ($user->ID === get_current_user_id()) {
            $cookie = wp_parse_auth_cookie('', 'logged_in');
            if ($cookie && isset($cookie['token'])) {
                $current_token = $cookie['token'];
            }
        }

        $output = '<table class="widefat user-history-table">';
        $output .= '<thead><tr>';
        $output .= '<th class="column-date">' . esc_html__('Login Time', 'wpzoom-user-history') . '</th>';
        $output .= '<th class="column-ip">' . esc_html__('IP Address', 'wpzoom-user-history') . '</th>';
        $output .= '<th class="column-browser">' . esc_html__('Browser', 'wpzoom-user-history') . '</th>';
        $output .= '<th class="column-expires">' . esc_html__('Expires', 'wpzoom-user-history') . '</th>';
        $output .= '</tr></thead><tbody>';

        foreach ($sessions as $verifier => $session) {
            $is_current = (!empty($current_token) && hash('sha256', $current_token) === $verifier);
            $is_expired = (isset($session['expiration']) && $session['expiration'] < time());
            $row_class  = $is_current ? ' session-current' : '';

            $output .= '<tr class="user-history-entry' . $row_class . '">';

            // Login time
            $output .= '<td class="column-date">';
            if (!empty($session['login'])) {
                $output .= '<span class="history-date">' . esc_html(date_i18n(get_option('date_format'), $session['login'])) . '</span>';
                $output .= '<span class="history-time">' . esc_html(date_i18n(get_option('time_format'), $session['login'])) . '</span>';
            } else {
                $output .= '&mdash;';
            }
            if ($is_current) {
                $output .= ' <span class="session-current-badge">' . esc_html__('(current)', 'wpzoom-user-history') . '</span>';
            }
            $output .= '</td>';

            // IP Address
            $output .= '<td class="column-ip">';
            if (!empty($session['ip'])) {
                $output .= '<a href="https://ipinfo.io/' . esc_attr($session['ip']) . '" target="_blank" rel="noopener noreferrer">' . esc_html($session['ip']) . '</a>';
            } else {
                $output .= '&mdash;';
            }
            $output .= '</td>';

            // Browser
            $output .= '<td class="column-browser">';
            if (!empty($session['ua'])) {
                $output .= esc_html($this->parse_user_agent($session['ua']));
            } else {
                $output .= '&mdash;';
            }
            $output .= '</td>';

            // Expires
            $output .= '<td class="column-expires">';
            if ($is_expired) {
                $output .= '<span class="session-expired-badge">' . esc_html__('Expired', 'wpzoom-user-history') . '</span>';
            } elseif (!empty($session['expiration'])) {
                $output .= esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $session['expiration']));
            } else {
                $output .= '&mdash;';
            }
            $output .= '</td>';

            $output .= '</tr>';
        }

        $output .= '</tbody></table>';

        $output .= '<div class="user-history-actions">';
        $output .= '<button type="button" class="button user-history-destroy-sessions" id="user-history-destroy-sessions">';
        $output .= esc_html__('Log Out Everywhere', 'wpzoom-user-history');
        $output .= '</button>';
        $output .= '</div>';

        return $output;
    }

    /**
     * Parse user agent string to extract browser and OS.
     *
     * @param string $ua User agent string.
     * @return string Formatted string like "Chrome on macOS".
     */
    private function parse_user_agent($ua) {
        // Detect browser
        $browser = 'Other';
        if (preg_match('/Edg[e\/]/i', $ua)) {
            $browser = 'Edge';
        } elseif (preg_match('/OPR|Opera/i', $ua)) {
            $browser = 'Opera';
        } elseif (preg_match('/Chrome/i', $ua)) {
            $browser = 'Chrome';
        } elseif (preg_match('/Safari/i', $ua) && !preg_match('/Chrome/i', $ua)) {
            $browser = 'Safari';
        } elseif (preg_match('/Firefox/i', $ua)) {
            $browser = 'Firefox';
        } elseif (preg_match('/MSIE|Trident/i', $ua)) {
            $browser = 'IE';
        }

        // Detect OS
        $os = 'Other';
        if (preg_match('/Windows/i', $ua)) {
            $os = 'Windows';
        } elseif (preg_match('/Macintosh|Mac OS/i', $ua)) {
            $os = 'macOS';
        } elseif (preg_match('/Linux/i', $ua) && !preg_match('/Android/i', $ua)) {
            $os = 'Linux';
        } elseif (preg_match('/Android/i', $ua)) {
            $os = 'Android';
        } elseif (preg_match('/iPhone|iPad|iPod/i', $ua)) {
            $os = 'iOS';
        }

        /* translators: 1: browser name, 2: operating system name */
        return sprintf(__('%1$s on %2$s', 'wpzoom-user-history'), $browser, $os);
    }

    /**
     * Truncate long values for display.
     *
     * @param string $value  Value to truncate.
     * @param int    $length Max length.
     * @return string
     */
    private function truncate_value($value, $length = 50) {
        if (strlen($value) <= $length) {
            return $value;
        }
        return substr($value, 0, $length) . '...';
    }

    // =========================================================================
    // AJAX Handlers
    // =========================================================================

    /**
     * AJAX handler for loading more history.
     */
    public function ajax_load_more_history() {
        check_ajax_referer('wpzoom_user_history_nonce', 'nonce');

        if (!current_user_can('edit_users')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $user_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
        $offset  = isset($_POST['offset']) ? (int) $_POST['offset'] : 0;
        $tab     = isset($_POST['tab']) && $_POST['tab'] === 'logins' ? 'logins' : 'changes';

        if (!$user_id) {
            wp_send_json_error(['message' => 'Invalid user ID']);
        }

        $history = $this->plugin->get_user_history($user_id, 20, $offset, $tab);

        if (empty($history)) {
            wp_send_json_success(['html' => '', 'hasMore' => false]);
        }

        $html  = ($tab === 'logins') ? $this->render_login_rows($history) : $this->render_history_rows($history);
        $total = $this->plugin->get_user_history_count($user_id, $tab);
        $has_more = ($offset + 20) < $total;

        wp_send_json_success([
            'html'      => $html,
            'hasMore'   => $has_more,
            'newOffset' => $offset + 20,
        ]);
    }

    /**
     * AJAX handler for clearing user history.
     */
    public function ajax_clear_history() {
        check_ajax_referer('wpzoom_user_history_clear', 'nonce');

        if (!current_user_can('edit_users')) {
            wp_send_json_error(['message' => __('Unauthorized', 'wpzoom-user-history')]);
        }

        $user_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;

        if (!$user_id) {
            wp_send_json_error(['message' => __('Invalid user ID', 'wpzoom-user-history')]);
        }

        global $wpdb;
        $table_name = $wpdb->prefix . WPZOOM_User_History::TABLE_NAME;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Deleting from custom plugin table, no cache to invalidate
        $deleted = $wpdb->delete(
            $table_name,
            ['user_id' => $user_id],
            ['%d']
        );

        if ($deleted === false) {
            wp_send_json_error(['message' => __('Failed to clear history', 'wpzoom-user-history')]);
        }

        wp_send_json_success([
            'message' => __('History cleared successfully', 'wpzoom-user-history'),
        ]);
    }

    /**
     * AJAX handler for destroying all user sessions.
     */
    public function ajax_destroy_sessions() {
        check_ajax_referer('wpzoom_user_history_destroy_sessions', 'nonce');

        if (!current_user_can('edit_users')) {
            wp_send_json_error(['message' => __('Unauthorized', 'wpzoom-user-history')]);
        }

        $user_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;

        if (!$user_id) {
            wp_send_json_error(['message' => __('Invalid user ID', 'wpzoom-user-history')]);
        }

        $sessions = WP_Session_Tokens::get_instance($user_id);
        $sessions->destroy_all();

        wp_send_json_success([
            'message' => __('All sessions have been destroyed.', 'wpzoom-user-history'),
        ]);
    }

    /**
     * AJAX handler for changing username.
     */
    public function ajax_change_username() {
        $response = [
            'success'   => false,
            'new_nonce' => wp_create_nonce('wpzoom_user_history_change_username'),
        ];

        // Check capability
        if (!current_user_can('edit_users')) {
            $response['message'] = __('You do not have permission to change usernames.', 'wpzoom-user-history');
            wp_send_json($response);
        }

        // Validate nonce
        if (!check_ajax_referer('wpzoom_user_history_change_username', '_ajax_nonce', false)) {
            $response['message'] = __('Security check failed. Please refresh the page.', 'wpzoom-user-history');
            wp_send_json($response);
        }

        // Validate request
        if (empty($_POST['new_username']) || empty($_POST['current_username'])) {
            $response['message'] = __('Invalid request.', 'wpzoom-user-history');
            wp_send_json($response);
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_user handles sanitization
        $new_username = sanitize_user(trim(wp_unslash($_POST['new_username'])), true);
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_user handles sanitization
        $old_username = sanitize_user(trim(wp_unslash($_POST['current_username'])), true);

        // Old username must exist
        $user_id = username_exists($old_username);
        if (!$user_id) {
            $response['message'] = __('Invalid request.', 'wpzoom-user-history');
            wp_send_json($response);
        }

        // If same username, nothing to do
        if ($new_username === $old_username) {
            $response['success'] = true;
            $response['message'] = __('Username unchanged.', 'wpzoom-user-history');
            wp_send_json($response);
        }

        // Validate username length
        if (mb_strlen($new_username) < 3 || mb_strlen($new_username) > 60) {
            $response['message'] = __('Username must be between 3 and 60 characters.', 'wpzoom-user-history');
            wp_send_json($response);
        }

        // Validate username characters
        if (!validate_username($new_username)) {
            $response['message'] = __('This username contains invalid characters.', 'wpzoom-user-history');
            wp_send_json($response);
        }

        // Check illegal logins (using WordPress core filter)
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core WP filter
        $illegal_logins = array_map('strtolower', (array) apply_filters('illegal_user_logins', []));
        if (in_array(strtolower($new_username), $illegal_logins, true)) {
            $response['message'] = __('Sorry, that username is not allowed.', 'wpzoom-user-history');
            wp_send_json($response);
        }

        // Check if new username already exists
        if (username_exists($new_username)) {
            /* translators: %s: the requested username */
            $response['message'] = sprintf(__('The username "%s" is already taken.', 'wpzoom-user-history'), $new_username);
            wp_send_json($response);
        }

        // Change the username
        $this->change_username($user_id, $old_username, $new_username);

        $response['success'] = true;
        /* translators: %s: the new username */
        $response['message'] = sprintf(__('Username changed to "%s".', 'wpzoom-user-history'), $new_username);
        wp_send_json($response);
    }

    /**
     * Change a user's username.
     *
     * @param int    $user_id      User ID.
     * @param string $old_username Old username.
     * @param string $new_username New username.
     */
    private function change_username($user_id, $old_username, $new_username) {
        global $wpdb;

        // Log the change before making it
        $this->plugin->log_change(
            $user_id,
            get_current_user_id(),
            'user_login',
            'Username',
            $old_username,
            $new_username,
            'update'
        );

        // Update user_login
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- user_login is not writable via WP API
        $wpdb->update(
            $wpdb->users,
            ['user_login' => $new_username],
            ['ID' => $user_id],
            ['%s'],
            ['%d']
        );

        // Update user_nicename if it matches the old username
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Conditional update requires direct query
        $wpdb->query($wpdb->prepare(
            "UPDATE $wpdb->users SET user_nicename = %s WHERE ID = %d AND user_nicename = %s",
            sanitize_title($new_username),
            $user_id,
            sanitize_title($old_username)
        ));

        // Update display_name if it matches the old username
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Conditional update requires direct query
        $wpdb->query($wpdb->prepare(
            "UPDATE $wpdb->users SET display_name = %s WHERE ID = %d AND display_name = %s",
            $new_username,
            $user_id,
            $old_username
        ));

        // Handle multisite super admin
        if (is_multisite()) {
            $super_admins = (array) get_site_option('site_admins', ['admin']);
            $key = array_search($old_username, $super_admins);
            if ($key !== false) {
                $super_admins[$key] = $new_username;
                // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core WP multisite option, not a custom plugin option
                update_site_option('site_admins', $super_admins);
            }
        }

        // Clear user cache
        clean_user_cache($user_id);

        /**
         * Fires after a username has been changed.
         *
         * @param int    $user_id      The user ID.
         * @param string $old_username The old username.
         * @param string $new_username The new username.
         */
        do_action('wpzoom_user_history_username_changed', $user_id, $old_username, $new_username);
    }

    // =========================================================================
    // User Search Extension
    // =========================================================================

    /**
     * Extend user search to include historical values.
     *
     * When searching users in admin, also search through old_value in history table
     * to find users by their previous usernames, emails, names, etc.
     *
     * @param WP_User_Query $query The user query.
     */
    public function extend_user_search($query) {
        global $wpdb, $pagenow;

        // Only run on users.php admin page with a search
        if (!is_admin() || $pagenow !== 'users.php') {
            return;
        }

        // Check if there's a search term
        $search = $query->get('search');
        if (empty($search)) {
            return;
        }

        // Remove the wildcard characters that WordPress adds
        $search_term = trim($search, '*');
        if (empty($search_term)) {
            return;
        }

        $history_table = $wpdb->prefix . WPZOOM_User_History::TABLE_NAME;

        // Check if table exists (plugin may not be activated yet)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Checking if custom table exists
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $history_table)) !== $history_table) {
            return;
        }

        // Find user IDs that have matching old values in history
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Querying custom history table for user search
        $user_ids_from_history = $wpdb->get_col(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safely constructed from $wpdb->prefix
                "SELECT DISTINCT user_id FROM $history_table
                WHERE old_value LIKE %s
                AND field_name IN ('user_login', 'user_email', 'first_name', 'last_name', 'display_name', 'nickname')",
                '%' . $wpdb->esc_like($search_term) . '%'
            )
        );

        if (empty($user_ids_from_history)) {
            return;
        }

        // Add these user IDs to the search results by modifying the WHERE clause
        // We inject an OR condition: (original search conditions) OR (ID IN history matches)
        $ids_list = implode(',', array_map('intval', $user_ids_from_history));

        // Append our condition to include users found in history
        $query->query_where .= " OR {$wpdb->users}.ID IN ($ids_list)";
    }
}
