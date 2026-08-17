<?php
/**
 * Plugin Name: WPZOOM User History
 * Plugin URI: https://github.com/wpzoom/user-history
 * Description: Tracks changes made to user accounts (name, email, username, etc.) and displays a history log on the user edit page.
 * Version: 1.4.0
 * Author: WPZOOM
 * Author URI: https://www.wpzoom.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wpzoom-user-history
 * Requires at least: 6.5
 * Requires PHP: 7.4
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('WPZOOM_USER_HISTORY_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WPZOOM_USER_HISTORY_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WPZOOM_USER_HISTORY_VERSION', get_file_data(__FILE__, ['Version' => 'Version'])['Version']);

/**
 * Main User History Class — Orchestrator + shared database layer.
 */
class WPZOOM_User_History {

    /**
     * Database table name (without prefix).
     */
    const TABLE_NAME = 'user_history';

    /**
     * User meta key for lock status.
     */
    const LOCKED_META_KEY = 'wpzoom_user_history_locked';

    /**
     * User meta key for registration context (referrer, source URL, user agent).
     */
    const REGISTRATION_META_KEY = 'wpzoom_user_history_registration';

    /**
     * Singleton instance.
     *
     * @var WPZOOM_User_History
     */
    private static $instance = null;

    /**
     * Change tracker instance.
     *
     * @var WPZOOM_User_History_Tracker
     */
    public $tracker;

    /**
     * Lock feature instance.
     *
     * @var WPZOOM_User_History_Lock
     */
    public $lock;

    /**
     * Admin UI instance.
     *
     * @var WPZOOM_User_History_Admin
     */
    public $admin;

    /**
     * Settings instance.
     *
     * @var WPZOOM_User_History_Settings
     */
    public $settings;

    /**
     * Login tracker instance.
     *
     * @var WPZOOM_User_History_Login_Tracker
     */
    public $login_tracker;

    /**
     * Dashboard access restriction instance.
     *
     * @var WPZOOM_User_History_Dashboard_Access
     */
    public $dashboard_access;

    /**
     * Username restrictions instance.
     *
     * @var WPZOOM_User_History_Username_Restrictions
     */
    public $username_restrictions;

    /**
     * Get singleton instance.
     *
     * @return WPZOOM_User_History
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        // Activation / deactivation hooks
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        // Initialize hooks
        add_action('plugins_loaded', [$this, 'init']);

        // Cron hook for log cleanup
        add_action('wpzoom_user_history_cleanup', [$this, 'cleanup_old_entries']);
    }

    /**
     * Plugin activation.
     */
    public function activate() {
        $this->create_table();
        update_option('wpzoom_user_history_version', WPZOOM_USER_HISTORY_VERSION);

        // Schedule daily cleanup if not already scheduled
        if (!wp_next_scheduled('wpzoom_user_history_cleanup')) {
            wp_schedule_event(time(), 'daily', 'wpzoom_user_history_cleanup');
        }
    }

    /**
     * Plugin deactivation.
     */
    public function deactivate() {
        wp_clear_scheduled_hook('wpzoom_user_history_cleanup');
    }

    /**
     * Delete log entries older than the configured retention period.
     */
    public function cleanup_old_entries() {
        $days = (int) get_option('wpzoom_user_history_retention_days', 30);

        // 0 means keep forever
        if ($days < 1) {
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cron cleanup of custom plugin table
        $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safely constructed from $wpdb->prefix
                "DELETE FROM $table_name WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            )
        );
    }

    /**
     * Create database table.
     */
    private function create_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            changed_by bigint(20) unsigned NOT NULL,
            field_name varchar(100) NOT NULL,
            field_label varchar(100) NOT NULL,
            old_value longtext,
            new_value longtext,
            change_type varchar(50) NOT NULL DEFAULT 'update',
            ip_address varchar(45) DEFAULT '',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY changed_by (changed_by),
            KEY field_name (field_name),
            KEY created_at (created_at),
            KEY old_value_search (field_name, old_value(100))
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Initialize plugin: check for upgrades, include files, create feature instances.
     */
    public function init() {
        // Check for database updates
        $this->maybe_upgrade();

        // Include feature classes
        require_once WPZOOM_USER_HISTORY_PLUGIN_DIR . 'includes/class-tracker.php';
        require_once WPZOOM_USER_HISTORY_PLUGIN_DIR . 'includes/class-lock.php';
        require_once WPZOOM_USER_HISTORY_PLUGIN_DIR . 'includes/class-admin.php';
        require_once WPZOOM_USER_HISTORY_PLUGIN_DIR . 'includes/class-settings.php';
        require_once WPZOOM_USER_HISTORY_PLUGIN_DIR . 'includes/class-login-tracker.php';
        require_once WPZOOM_USER_HISTORY_PLUGIN_DIR . 'includes/class-dashboard-access.php';
        require_once WPZOOM_USER_HISTORY_PLUGIN_DIR . 'includes/class-username-restrictions.php';

        // Create feature instances (each registers its own hooks in constructor)
        $this->tracker               = new WPZOOM_User_History_Tracker($this);
        $this->lock                  = new WPZOOM_User_History_Lock($this);
        $this->admin                 = new WPZOOM_User_History_Admin($this);
        $this->settings              = new WPZOOM_User_History_Settings();
        $this->login_tracker         = new WPZOOM_User_History_Login_Tracker($this);
        $this->dashboard_access      = new WPZOOM_User_History_Dashboard_Access();
        $this->username_restrictions = new WPZOOM_User_History_Username_Restrictions();
    }

    /**
     * Maybe upgrade database.
     */
    private function maybe_upgrade() {
        $current_version = get_option('wpzoom_user_history_version', '0');

        if (version_compare($current_version, WPZOOM_USER_HISTORY_VERSION, '<')) {
            $this->create_table();
            $this->maybe_migrate_lock_data();
            $this->maybe_migrate_rda_settings();
            $this->maybe_migrate_restrict_usernames_settings();
            update_option('wpzoom_user_history_version', WPZOOM_USER_HISTORY_VERSION);
        }
    }

    /**
     * Migrate settings from the Restrict Usernames plugin (c2c_restrict_usernames option).
     *
     * As with the Dashboard Access migration, the feature is auto-enabled only
     * when the old plugin is currently active; otherwise the values are
     * imported but the restriction stays off.
     */
    private function maybe_migrate_restrict_usernames_settings() {
        if (get_option('wpzoom_user_history_migrated_restrict_usernames')) {
            return;
        }

        update_option('wpzoom_user_history_migrated_restrict_usernames', '1');

        $old = get_option('c2c_restrict_usernames');
        if (!is_array($old) || empty($old)) {
            return;
        }

        $to_lines = static function ($value) {
            if (is_string($value)) {
                $value = preg_split('/\R/', $value);
            }
            if (!is_array($value)) {
                return '';
            }
            $value = array_filter(array_map('trim', array_map('strval', $value)), 'strlen');
            return implode("\n", array_values(array_unique(array_map('mb_strtolower', $value))));
        };

        $map = [
            'disallow_spaces'   => ['wpzoom_user_history_username_disallow_spaces', static function ($v) { return $v ? '1' : '0'; }],
            'min_length'        => ['wpzoom_user_history_username_min_length', static function ($v) { return min(60, max(0, (int) $v)); }],
            'max_length'        => ['wpzoom_user_history_username_max_length', static function ($v) { return min(60, max(0, (int) $v)); }],
            'usernames'         => ['wpzoom_user_history_username_blocklist', $to_lines],
            'partial_usernames' => ['wpzoom_user_history_username_partial_blocklist', $to_lines],
            'required_partials' => ['wpzoom_user_history_username_required_partials', $to_lines],
        ];

        foreach ($map as $old_key => list($new_key, $convert)) {
            if (isset($old[ $old_key ]) && get_option($new_key) === false) {
                update_option($new_key, $convert($old[ $old_key ]));
            }
        }

        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if (is_plugin_active('restrict-usernames/restrict-usernames.php')) {
            update_option('wpzoom_user_history_username_restrictions_enabled', '1');
        }
    }

    /**
     * Migrate settings from the Remove Dashboard Access plugin (rda_* options).
     *
     * The restriction is auto-enabled only when the old plugin is currently
     * active (so it stays seamless when replacing it with this plugin). If the
     * old plugin was deactivated long ago, the values are migrated but the
     * feature stays off — turning it on unexpectedly could lock users out.
     */
    private function maybe_migrate_rda_settings() {
        if (get_option('wpzoom_user_history_migrated_rda')) {
            return;
        }

        update_option('wpzoom_user_history_migrated_rda', '1');

        if (get_option('rda_access_switch') === false) {
            return;
        }

        $map = [
            'rda_access_switch' => 'wpzoom_user_history_dashboard_access_switch',
            'rda_access_cap'    => 'wpzoom_user_history_dashboard_access_cap',
            'rda_redirect_url'  => 'wpzoom_user_history_dashboard_redirect_url',
            'rda_login_message' => 'wpzoom_user_history_dashboard_login_message',
            'rda_url_allowlist' => 'wpzoom_user_history_dashboard_url_allowlist',
        ];

        foreach ($map as $old_key => $new_key) {
            $value = get_option($old_key);
            if ($value !== false && get_option($new_key) === false) {
                update_option($new_key, $value);
            }
        }

        // Checkbox options are stored as '1'/'0' strings in this plugin
        if (get_option('wpzoom_user_history_dashboard_enable_profile') === false) {
            update_option('wpzoom_user_history_dashboard_enable_profile', get_option('rda_enable_profile', 1) ? '1' : '0');
        }
        if (get_option('wpzoom_user_history_dashboard_lock_ajax') === false) {
            update_option('wpzoom_user_history_dashboard_lock_ajax', get_option('rda_lock_ajax', 0) ? '1' : '0');
        }

        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if (is_plugin_active('remove-dashboard-access-for-non-admins/remove-dashboard-access.php')) {
            update_option('wpzoom_user_history_dashboard_access_enabled', '1');
        }
    }

    /**
     * Migrate lock data from lock-user-account plugin (baba_user_locked meta key).
     */
    private function maybe_migrate_lock_data() {
        if (get_option('wpzoom_user_history_migrated_lock')) {
            return;
        }

        global $wpdb;

        // Find all users locked by the old plugin
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration query
        $locked_user_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s",
                'baba_user_locked',
                'yes'
            )
        );

        foreach ($locked_user_ids as $user_id) {
            update_user_meta((int) $user_id, self::LOCKED_META_KEY, '1');
        }

        update_option('wpzoom_user_history_migrated_lock', '1');
    }

    // =========================================================================
    // Shared Database Methods (used by Tracker, Lock, and Admin classes)
    // =========================================================================

    /**
     * Insert a change log entry.
     *
     * @param int    $user_id     User ID.
     * @param int    $changed_by  ID of user who made the change.
     * @param string $field_name  Database field name.
     * @param string $field_label Human-readable label.
     * @param string $old_value   Old value.
     * @param string $new_value   New value.
     * @param string $change_type Change type (update, create, lock, unlock).
     */
    public function log_change($user_id, $changed_by, $field_name, $field_label, $old_value, $new_value, $change_type = 'update') {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $ip_address = '';
        if (get_option('wpzoom_user_history_track_ip', '1') === '1') {
            $ip_address = $this->get_client_ip();
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Inserting into custom plugin table
        $wpdb->insert(
            $table_name,
            [
                'user_id'     => $user_id,
                'changed_by'  => $changed_by,
                'field_name'  => $field_name,
                'field_label' => $field_label,
                'old_value'   => $old_value,
                'new_value'   => $new_value,
                'change_type' => $change_type,
                'ip_address'  => $ip_address,
                'created_at'  => current_time('mysql'),
            ],
            ['%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );
    }

    /**
     * Translate a field label for display.
     *
     * Labels are stored in the database in English so that logs stay
     * language-neutral (and readable if the site language changes later).
     * Translation happens here, at display time. Unknown labels — including
     * ones added by third-party code — are returned unchanged.
     *
     * @param string $label Stored (English) label.
     * @return string Translated label.
     */
    public static function translate_field_label($label) {
        $labels = [
            'Username'          => __('Username', 'wpzoom-user-history'),
            'Email'             => __('Email', 'wpzoom-user-history'),
            'Password'          => __('Password', 'wpzoom-user-history'),
            'Nicename'          => __('Nicename', 'wpzoom-user-history'),
            'Display Name'      => __('Display Name', 'wpzoom-user-history'),
            'Website'           => __('Website', 'wpzoom-user-history'),
            'First Name'        => __('First Name', 'wpzoom-user-history'),
            'Last Name'         => __('Last Name', 'wpzoom-user-history'),
            'Nickname'          => __('Nickname', 'wpzoom-user-history'),
            'Biographical Info' => __('Biographical Info', 'wpzoom-user-history'),
            'Role'              => __('Role', 'wpzoom-user-history'),
            'Account Created'   => __('Account Created', 'wpzoom-user-history'),
            'Account Locked'    => __('Account Locked', 'wpzoom-user-history'),
            'Account Unlocked'  => __('Account Unlocked', 'wpzoom-user-history'),
            'Login'             => __('Login', 'wpzoom-user-history'),
            'Logout'            => __('Logout', 'wpzoom-user-history'),
            'Failed Login'      => __('Failed Login', 'wpzoom-user-history'),
        ];

        /**
         * Filters the map of stored field labels to their translated versions.
         *
         * @param array  $labels Map of stored (English) label => translated label.
         * @param string $label  The label being translated.
         */
        $labels = apply_filters('wpzoom_user_history_field_labels', $labels, $label);

        return isset($labels[ $label ]) ? $labels[ $label ] : $label;
    }

    /**
     * Get the client IP address.
     *
     * @return string IP address or empty string.
     */
    private function get_client_ip() {
        $headers = [
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_FORWARDED_FOR',  // Standard proxy header
            'REMOTE_ADDR',           // Direct connection
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[ $header ])) {
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated with filter_var below
                $ip = wp_unslash($_SERVER[ $header ]);
                // X-Forwarded-For can contain multiple IPs — take the first
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '';
    }

    /**
     * Get history for a user.
     *
     * @param int    $user_id User ID.
     * @param int    $limit   Number of entries.
     * @param int    $offset  Offset.
     * @param string $type    Type of entries: 'changes' (default) or 'logins'.
     * @return array
     */
    public function get_user_history($user_id, $limit = 50, $offset = 0, $type = 'changes') {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        if ($type === 'logins') {
            $type_clause = "AND change_type IN ('login', 'logout', 'login_failed')";
        } else {
            $type_clause = "AND change_type NOT IN ('login', 'logout', 'login_failed')";
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Querying custom history table
        $results = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and type clause are safely constructed
                "SELECT * FROM $table_name
                WHERE user_id = %d $type_clause
                ORDER BY created_at DESC
                LIMIT %d OFFSET %d",
                $user_id,
                $limit,
                $offset
            )
        );

        return $results;
    }

    /**
     * Get total history count for a user.
     *
     * @param int    $user_id User ID.
     * @param string $type    Type of entries: 'changes' (default) or 'logins'.
     * @return int
     */
    public function get_user_history_count($user_id, $type = 'changes') {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        if ($type === 'logins') {
            $type_clause = "AND change_type IN ('login', 'logout', 'login_failed')";
        } else {
            $type_clause = "AND change_type NOT IN ('login', 'logout', 'login_failed')";
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Counting from custom history table
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and type clause are safely constructed
                "SELECT COUNT(*) FROM $table_name WHERE user_id = %d $type_clause",
                $user_id
            )
        );
    }
}

// Initialize the plugin
WPZOOM_User_History::get_instance();
