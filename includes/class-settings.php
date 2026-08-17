<?php
/**
 * Settings page for User History plugin.
 *
 * @package UserHistory
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles the plugin settings page (Settings > User History).
 *
 * The page is organized into tabs (General / Lock Account / Dashboard Access /
 * Username Restrictions). Each tab has its own Settings API option group and
 * page slug, derived from the tab key: group
 * `wpzoom_user_history_settings_{tab}` and page `wpzoom-user-history-{tab}`.
 */
class WPZOOM_User_History_Settings {

    /**
     * Constructor — registers hooks.
     */
    public function __construct() {
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('wp_ajax_wpzoom_user_history_clear_all', [$this, 'ajax_clear_all_logs']);
        add_action('wp_ajax_wpzoom_user_history_test_usernames', [$this, 'ajax_test_usernames']);
    }

    /**
     * Settings page tabs.
     *
     * @return array Tab labels keyed by tab slug.
     */
    private function get_tabs() {
        return [
            'general'               => __('General', 'wpzoom-user-history'),
            'lock'                  => __('Lock Account', 'wpzoom-user-history'),
            'dashboard-access'      => __('Dashboard Access', 'wpzoom-user-history'),
            'username-restrictions' => __('Username Restrictions', 'wpzoom-user-history'),
        ];
    }

    /**
     * Currently active settings tab.
     *
     * @return string Tab slug.
     */
    private function get_active_tab() {
        $tabs = $this->get_tabs();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Selecting which tab to display, no state change
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'general';

        return isset($tabs[ $tab ]) ? $tab : 'general';
    }

    /**
     * Settings API option group name for a tab.
     *
     * @param string $tab Tab slug.
     * @return string
     */
    private function get_option_group($tab) {
        return 'wpzoom_user_history_settings_' . str_replace('-', '_', $tab);
    }

    /**
     * Settings API page slug for a tab.
     *
     * @param string $tab Tab slug.
     * @return string
     */
    private function get_settings_page($tab) {
        return 'wpzoom-user-history-' . $tab;
    }

    /**
     * Add settings page under Settings menu.
     */
    public function add_settings_page() {
        add_options_page(
            __('User History', 'wpzoom-user-history'),
            __('User History', 'wpzoom-user-history'),
            'manage_options',
            'wpzoom-user-history',
            [$this, 'render_settings_page']
        );
    }

    /**
     * Register settings, sections, and fields for all tabs.
     */
    public function register_settings() {
        $this->register_general_settings();
        $this->register_lock_settings();
        $this->register_dashboard_access_settings();
        $this->register_username_restrictions_settings();
    }

    /**
     * General tab: privacy and data retention.
     */
    private function register_general_settings() {
        $group = $this->get_option_group('general');
        $page  = $this->get_settings_page('general');

        register_setting($group, 'wpzoom_user_history_track_ip', [
            'type'              => 'string',
            'sanitize_callback' => [$this, 'sanitize_checkbox'],
            'default'           => '1',
        ]);

        register_setting($group, 'wpzoom_user_history_retention_days', [
            'type'              => 'integer',
            'sanitize_callback' => [$this, 'sanitize_retention_days'],
            'default'           => 30,
        ]);

        add_settings_section(
            'wpzoom_user_history_privacy_section',
            __('Privacy', 'wpzoom-user-history'),
            [$this, 'render_privacy_section_description'],
            $page
        );

        add_settings_field(
            'wpzoom_user_history_track_ip',
            __('IP Address Tracking', 'wpzoom-user-history'),
            [$this, 'render_track_ip_field'],
            $page,
            'wpzoom_user_history_privacy_section'
        );

        add_settings_section(
            'wpzoom_user_history_retention_section',
            __('Data Retention', 'wpzoom-user-history'),
            [$this, 'render_retention_section_description'],
            $page
        );

        add_settings_field(
            'wpzoom_user_history_retention_days',
            __('Keep Logs For', 'wpzoom-user-history'),
            [$this, 'render_retention_days_field'],
            $page,
            'wpzoom_user_history_retention_section'
        );
    }

    /**
     * Lock Account tab: locked account message.
     */
    private function register_lock_settings() {
        $group = $this->get_option_group('lock');
        $page  = $this->get_settings_page('lock');

        register_setting($group, 'wpzoom_user_history_locked_message', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ]);

        add_settings_section(
            'wpzoom_user_history_lock_section',
            __('Lock Account', 'wpzoom-user-history'),
            [$this, 'render_lock_section_description'],
            $page
        );

        add_settings_field(
            'wpzoom_user_history_locked_message',
            __('Locked Account Message', 'wpzoom-user-history'),
            [$this, 'render_locked_message_field'],
            $page,
            'wpzoom_user_history_lock_section'
        );
    }

    /**
     * Dashboard Access tab: restrict wp-admin to allowed users.
     */
    private function register_dashboard_access_settings() {
        $group = $this->get_option_group('dashboard-access');
        $page  = $this->get_settings_page('dashboard-access');

        register_setting($group, 'wpzoom_user_history_dashboard_access_enabled', [
            'type'              => 'string',
            'sanitize_callback' => [$this, 'sanitize_checkbox'],
            'default'           => '0',
        ]);

        register_setting($group, 'wpzoom_user_history_dashboard_access_switch', [
            'type'              => 'string',
            'sanitize_callback' => [$this, 'sanitize_access_switch'],
            'default'           => 'manage_options',
        ]);

        register_setting($group, 'wpzoom_user_history_dashboard_access_cap', [
            'type'              => 'string',
            'sanitize_callback' => [$this, 'sanitize_access_cap'],
            'default'           => 'manage_options',
        ]);

        register_setting($group, 'wpzoom_user_history_dashboard_redirect_url', [
            'type'              => 'string',
            'sanitize_callback' => [$this, 'sanitize_redirect_url'],
            'default'           => '',
        ]);

        register_setting($group, 'wpzoom_user_history_dashboard_enable_profile', [
            'type'              => 'string',
            'sanitize_callback' => [$this, 'sanitize_checkbox'],
            'default'           => '1',
        ]);

        register_setting($group, 'wpzoom_user_history_dashboard_login_message', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ]);

        register_setting($group, 'wpzoom_user_history_dashboard_lock_ajax', [
            'type'              => 'string',
            'sanitize_callback' => [$this, 'sanitize_checkbox'],
            'default'           => '0',
        ]);

        register_setting($group, 'wpzoom_user_history_dashboard_url_allowlist', [
            'type'              => 'string',
            'sanitize_callback' => [$this, 'sanitize_url_allowlist'],
            'default'           => '',
        ]);

        add_settings_section(
            'wpzoom_user_history_dashboard_section',
            __('Dashboard Access Controls', 'wpzoom-user-history'),
            [$this, 'render_dashboard_section_description'],
            $page
        );

        add_settings_field(
            'wpzoom_user_history_dashboard_access_enabled',
            __('Restrict Dashboard Access', 'wpzoom-user-history'),
            [$this, 'render_dashboard_enabled_field'],
            $page,
            'wpzoom_user_history_dashboard_section'
        );

        add_settings_field(
            'wpzoom_user_history_dashboard_access_switch',
            __('Dashboard User Access', 'wpzoom-user-history'),
            [$this, 'render_access_switch_field'],
            $page,
            'wpzoom_user_history_dashboard_section'
        );

        add_settings_field(
            'wpzoom_user_history_dashboard_redirect_url',
            __('Redirect URL', 'wpzoom-user-history'),
            [$this, 'render_redirect_url_field'],
            $page,
            'wpzoom_user_history_dashboard_section'
        );

        add_settings_field(
            'wpzoom_user_history_dashboard_enable_profile',
            __('User Profile Access', 'wpzoom-user-history'),
            [$this, 'render_enable_profile_field'],
            $page,
            'wpzoom_user_history_dashboard_section'
        );

        add_settings_field(
            'wpzoom_user_history_dashboard_login_message',
            __('Login Message', 'wpzoom-user-history'),
            [$this, 'render_login_message_field'],
            $page,
            'wpzoom_user_history_dashboard_section'
        );

        add_settings_section(
            'wpzoom_user_history_dashboard_advanced_section',
            __('Advanced', 'wpzoom-user-history'),
            [$this, 'render_dashboard_advanced_section_description'],
            $page
        );

        add_settings_field(
            'wpzoom_user_history_dashboard_lock_ajax',
            __('AJAX Requests', 'wpzoom-user-history'),
            [$this, 'render_lock_ajax_field'],
            $page,
            'wpzoom_user_history_dashboard_advanced_section'
        );

        add_settings_field(
            'wpzoom_user_history_dashboard_url_allowlist',
            __('Allowed URLs', 'wpzoom-user-history'),
            [$this, 'render_url_allowlist_field'],
            $page,
            'wpzoom_user_history_dashboard_advanced_section'
        );
    }

    /**
     * Username Restrictions tab: rules for usernames chosen at registration.
     */
    private function register_username_restrictions_settings() {
        $group = $this->get_option_group('username-restrictions');
        $page  = $this->get_settings_page('username-restrictions');

        register_setting($group, 'wpzoom_user_history_username_restrictions_enabled', [
            'type'              => 'string',
            'sanitize_callback' => [$this, 'sanitize_checkbox'],
            'default'           => '0',
        ]);

        register_setting($group, 'wpzoom_user_history_username_disallow_spaces', [
            'type'              => 'string',
            'sanitize_callback' => [$this, 'sanitize_checkbox'],
            'default'           => '0',
        ]);

        register_setting($group, 'wpzoom_user_history_username_min_length', [
            'type'              => 'integer',
            'sanitize_callback' => [$this, 'sanitize_username_length'],
            'default'           => 0,
        ]);

        register_setting($group, 'wpzoom_user_history_username_max_length', [
            'type'              => 'integer',
            'sanitize_callback' => [$this, 'sanitize_username_length'],
            'default'           => 0,
        ]);

        register_setting($group, 'wpzoom_user_history_username_blocklist', [
            'type'              => 'string',
            'sanitize_callback' => [$this, 'sanitize_username_list'],
            'default'           => '',
        ]);

        register_setting($group, 'wpzoom_user_history_username_partial_blocklist', [
            'type'              => 'string',
            'sanitize_callback' => [$this, 'sanitize_username_list'],
            'default'           => '',
        ]);

        register_setting($group, 'wpzoom_user_history_username_required_partials', [
            'type'              => 'string',
            'sanitize_callback' => [$this, 'sanitize_username_list'],
            'default'           => '',
        ]);

        register_setting($group, 'wpzoom_user_history_username_error_message', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ]);

        register_setting($group, 'wpzoom_user_history_username_apply_to_admins', [
            'type'              => 'string',
            'sanitize_callback' => [$this, 'sanitize_checkbox'],
            'default'           => '0',
        ]);

        add_settings_section(
            'wpzoom_user_history_username_section',
            __('Username Restrictions', 'wpzoom-user-history'),
            [$this, 'render_username_section_description'],
            $page
        );

        add_settings_field(
            'wpzoom_user_history_username_restrictions_enabled',
            __('Restrict Usernames', 'wpzoom-user-history'),
            [$this, 'render_username_enabled_field'],
            $page,
            'wpzoom_user_history_username_section'
        );

        add_settings_field(
            'wpzoom_user_history_username_disallow_spaces',
            __('Spaces', 'wpzoom-user-history'),
            [$this, 'render_username_disallow_spaces_field'],
            $page,
            'wpzoom_user_history_username_section'
        );

        add_settings_field(
            'wpzoom_user_history_username_length',
            __('Length', 'wpzoom-user-history'),
            [$this, 'render_username_length_field'],
            $page,
            'wpzoom_user_history_username_section'
        );

        add_settings_field(
            'wpzoom_user_history_username_blocklist',
            __('Blocked Usernames', 'wpzoom-user-history'),
            [$this, 'render_username_blocklist_field'],
            $page,
            'wpzoom_user_history_username_section'
        );

        add_settings_field(
            'wpzoom_user_history_username_partial_blocklist',
            __('Blocked Text', 'wpzoom-user-history'),
            [$this, 'render_username_partial_blocklist_field'],
            $page,
            'wpzoom_user_history_username_section'
        );

        add_settings_field(
            'wpzoom_user_history_username_required_partials',
            __('Required Text', 'wpzoom-user-history'),
            [$this, 'render_username_required_partials_field'],
            $page,
            'wpzoom_user_history_username_section'
        );

        add_settings_field(
            'wpzoom_user_history_username_error_message',
            __('Error Message', 'wpzoom-user-history'),
            [$this, 'render_username_error_message_field'],
            $page,
            'wpzoom_user_history_username_section'
        );

        add_settings_section(
            'wpzoom_user_history_username_advanced_section',
            __('Advanced', 'wpzoom-user-history'),
            '__return_null',
            $page
        );

        add_settings_field(
            'wpzoom_user_history_username_apply_to_admins',
            __('Administrators', 'wpzoom-user-history'),
            [$this, 'render_username_apply_to_admins_field'],
            $page,
            'wpzoom_user_history_username_advanced_section'
        );
    }

    // =========================================================================
    // Sanitize callbacks
    // =========================================================================

    /**
     * Sanitize a username length limit.
     *
     * WordPress caps usernames at 60 characters, so anything above that is
     * clamped. 0 means no limit.
     *
     * @param mixed $value Input value.
     * @return int Integer between 0 and 60.
     */
    public function sanitize_username_length($value) {
        return min(60, max(0, (int) $value));
    }

    /**
     * Sanitize a newline-separated username / text-fragment list.
     *
     * Trims each line, lowercases (matching is case-insensitive), drops
     * empties and duplicates. Returns the cleaned newline-separated string.
     *
     * @param mixed $value Raw textarea value.
     * @return string
     */
    public function sanitize_username_list($value) {
        if (!is_string($value) || $value === '') {
            return '';
        }

        // Strip null bytes and other control chars (except tab/newlines).
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);

        $clean = [];
        foreach (preg_split('/\R/', $value) as $line) {
            $line = trim(wp_strip_all_tags($line));
            if ($line !== '') {
                $clean[] = mb_strtolower($line);
            }
        }

        return implode("\n", array_values(array_unique($clean)));
    }

    /**
     * Sanitize checkbox value.
     *
     * @param mixed $value Checkbox value.
     * @return string '1' or '0'.
     */
    public function sanitize_checkbox($value) {
        return $value ? '1' : '0';
    }

    /**
     * Sanitize retention days value.
     *
     * @param mixed $value Input value.
     * @return int Non-negative integer.
     */
    public function sanitize_retention_days($value) {
        $value = (int) $value;
        return max(0, $value);
    }

    /**
     * Sanitize the dashboard access switch.
     *
     * Accepts the literal string 'capability' (advanced mode) or one of the
     * role-default capabilities. Any other value falls back to
     * 'manage_options' so a tampered POST cannot disable the restriction.
     *
     * @param mixed $value Submitted value.
     * @return string Sanitized switch value.
     */
    public function sanitize_access_switch($value) {
        $value = is_string($value) ? $value : '';

        $allowed   = array_values(WPZOOM_User_History_Dashboard_Access::get_default_role_caps());
        $allowed[] = 'capability';

        if (in_array($value, $allowed, true)) {
            return $value;
        }

        return 'manage_options';
    }

    /**
     * Sanitize the dashboard access capability.
     *
     * Accepts any capability that actually exists in `$wp_roles`. Anything
     * else — empty strings, arbitrary text, capabilities no role grants —
     * falls back to the access switch value so the restriction cannot be
     * silently disabled.
     *
     * @param mixed $value Submitted capability.
     * @return string Sanitized capability.
     */
    public function sanitize_access_cap($value) {
        $fallback = get_option('wpzoom_user_history_dashboard_access_switch', 'manage_options');
        if ($fallback === 'capability') {
            $fallback = 'manage_options';
        }

        if (empty($value) || !is_string($value)) {
            return $fallback;
        }

        /** @global WP_Roles $wp_roles */
        global $wp_roles;

        if (!isset($wp_roles) || !is_a($wp_roles, 'WP_Roles')) {
            return $fallback;
        }

        foreach ($wp_roles->role_objects as $role) {
            if (!empty($role->capabilities) && is_array($role->capabilities) && array_key_exists($value, $role->capabilities)) {
                return $value;
            }
        }

        return $fallback;
    }

    /**
     * Sanitize the redirect URL.
     *
     * @param mixed $value Submitted URL.
     * @return string Sanitized URL, or empty string (= homepage at runtime).
     */
    public function sanitize_redirect_url($value) {
        return empty($value) ? '' : esc_url_raw($value);
    }

    /**
     * Sanitize the URL allowlist textarea.
     *
     * For each line: trim whitespace, normalize to a same-origin relative
     * path (or drop), strip URL fragments, and dedupe. Returns the cleaned
     * newline-separated string for storage.
     *
     * @param mixed $value Raw textarea value.
     * @return string Cleaned newline-separated URL list.
     */
    public function sanitize_url_allowlist($value) {
        if (!is_string($value) || $value === '') {
            return '';
        }

        $lines = preg_split('/\R/', $value);
        $clean = [];

        foreach ($lines as $line) {
            // Strip null bytes and other ASCII control chars (except tab)
            // before any structural parsing — they're never legitimate in a
            // URL and they break downstream strpos/parse_url assumptions.
            $line = preg_replace('/[\x00-\x08\x0E-\x1F\x7F]/', '', $line);

            $line = sanitize_text_field($line);
            if ($line === '') {
                continue;
            }

            $relative = $this->normalize_url_to_relative($line);
            if ($relative === null) {
                continue;
            }

            // Reject "allow everything" entries — a path ending in `/` with
            // no query string would allowlist every request under that
            // directory, including the dashboard root itself.
            $parsed_path  = wp_parse_url($relative, PHP_URL_PATH);
            $parsed_query = wp_parse_url($relative, PHP_URL_QUERY);
            if ($parsed_path && substr($parsed_path, -1) === '/' && empty($parsed_query)) {
                continue;
            }

            $clean[] = $relative;
        }

        $clean = array_values(array_unique($clean));

        return implode("\n", $clean);
    }

    /**
     * Normalize a single allowlist URL line to a same-origin relative path.
     *
     * Drops external hosts and protocol-relative URLs (host confusion risk);
     * strips fragments; auto-prepends a leading slash for bare relative paths.
     *
     * @param string $url Raw URL string.
     * @return string|null Cleaned relative path with optional query string, or null if invalid.
     */
    private function normalize_url_to_relative($url) {
        // Reject protocol-relative URLs outright — `//evil.example/foo` would
        // be host-confused in many redirect/match contexts and there's no
        // reason a same-site allowlist should accept them.
        if (strpos($url, '//') === 0) {
            return null;
        }

        // Absolute URL: keep only if the host matches this site.
        if (preg_match('#^https?://#i', $url)) {
            $url_host  = wp_parse_url($url, PHP_URL_HOST);
            $home_host = wp_parse_url(home_url(), PHP_URL_HOST);

            if (empty($url_host) || empty($home_host) || strtolower($url_host) !== strtolower($home_host)) {
                return null;
            }

            $path  = wp_parse_url($url, PHP_URL_PATH);
            $query = wp_parse_url($url, PHP_URL_QUERY);

            if (empty($path)) {
                return null;
            }

            return $path . ($query ? '?' . $query : '');
        }

        // Relative path: strip everything from `#` onwards (fragments never
        // reach the server).
        $hash_pos = strpos($url, '#');
        if ($hash_pos !== false) {
            $url = substr($url, 0, $hash_pos);
        }

        if ($url === '') {
            return null;
        }

        // Reject anything that looks like a non-http(s) URI scheme. A real
        // relative path never has `:` before the first `/`. This blocks
        // `javascript:`, `data:`, `file:`, and friends from being stored as
        // "/javascript:..." after the leading-slash prepend below.
        $colon_pos = strpos($url, ':');
        $slash_pos = strpos($url, '/');
        if ($colon_pos !== false && ($slash_pos === false || $colon_pos < $slash_pos)) {
            return null;
        }

        if ($url[0] !== '/') {
            $url = '/' . $url;
        }

        return $url;
    }

    // =========================================================================
    // General tab fields
    // =========================================================================

    /**
     * Render the privacy settings section description.
     */
    public function render_privacy_section_description() {
        echo '<p>' . esc_html__('Configure privacy-related settings for GDPR compliance.', 'wpzoom-user-history') . '</p>';
    }

    /**
     * Render the IP tracking settings field.
     */
    public function render_track_ip_field() {
        $value = get_option('wpzoom_user_history_track_ip', '1');
        ?>
        <label>
            <input type="checkbox" name="wpzoom_user_history_track_ip" value="1" <?php checked($value, '1'); ?> />
            <?php esc_html_e('Record IP addresses when users make changes to their own profiles', 'wpzoom-user-history'); ?>
        </label>
        <p class="description">
            <?php esc_html_e('When enabled, the IP address is recorded for each change and displayed in the Account History table. Disable this to comply with GDPR or other privacy regulations.', 'wpzoom-user-history'); ?>
        </p>
        <?php
    }

    /**
     * Render the data retention section description.
     */
    public function render_retention_section_description() {
        echo '<p>' . esc_html__('Configure how long history logs are kept before automatic cleanup.', 'wpzoom-user-history') . '</p>';
    }

    /**
     * Render the retention days field.
     */
    public function render_retention_days_field() {
        $value = get_option('wpzoom_user_history_retention_days', 30);
        ?>
        <input type="number" name="wpzoom_user_history_retention_days" min="0" step="1" class="small-text"
               value="<?php echo esc_attr($value); ?>" /> <?php esc_html_e('days', 'wpzoom-user-history'); ?>
        <p class="description">
            <?php esc_html_e('Logs older than this many days are automatically deleted. Set to 0 to keep logs indefinitely.', 'wpzoom-user-history'); ?>
        </p>
        <?php
    }

    // =========================================================================
    // Lock Account tab fields
    // =========================================================================

    /**
     * Render the lock settings section description.
     */
    public function render_lock_section_description() {
        echo '<p>' . esc_html__('Configure the message shown when a locked user tries to log in.', 'wpzoom-user-history') . '</p>';
    }

    /**
     * Render the locked message settings field.
     */
    public function render_locked_message_field() {
        $value = get_option('wpzoom_user_history_locked_message', '');
        ?>
        <input type="text" name="wpzoom_user_history_locked_message" class="regular-text"
               value="<?php echo esc_attr($value); ?>"
               placeholder="<?php echo esc_attr__('Your account has been locked. Please contact the administrator.', 'wpzoom-user-history'); ?>" />
        <p class="description">
            <?php esc_html_e('This message is displayed on the login screen when a locked user attempts to log in. Leave empty to use the default message.', 'wpzoom-user-history'); ?>
        </p>
        <?php
    }

    // =========================================================================
    // Dashboard Access tab fields
    // =========================================================================

    /**
     * Render the dashboard access section description.
     */
    public function render_dashboard_section_description() {
        echo '<p>' . esc_html__('Restrict access to the WordPress dashboard (wp-admin) to certain user roles, or to users with a specific capability. Everyone else is redirected to the URL of your choice.', 'wpzoom-user-history') . '</p>';
    }

    /**
     * Render the dashboard access enable field.
     */
    public function render_dashboard_enabled_field() {
        $value = get_option('wpzoom_user_history_dashboard_access_enabled', '0');
        ?>
        <label>
            <input type="checkbox" name="wpzoom_user_history_dashboard_access_enabled" value="1" <?php checked($value, '1'); ?> />
            <?php esc_html_e('Block disallowed users from accessing the dashboard', 'wpzoom-user-history'); ?>
        </label>
        <p class="description">
            <?php esc_html_e('When enabled, users without the required role or capability are redirected away from wp-admin and their admin Toolbar is trimmed down.', 'wpzoom-user-history'); ?>
        </p>
        <?php
    }

    /**
     * Render the access switch field (role presets + capability dropdown).
     */
    public function render_access_switch_field() {
        $switch   = get_option('wpzoom_user_history_dashboard_access_switch', 'manage_options');
        $defaults = WPZOOM_User_History_Dashboard_Access::get_default_role_caps();
        ?>
        <p><label>
            <input type="radio" name="wpzoom_user_history_dashboard_access_switch" value="<?php echo esc_attr($defaults['admin']); ?>" <?php checked($defaults['admin'], $switch); ?> />
            <?php esc_html_e('Administrators only', 'wpzoom-user-history'); ?>
        </label></p>
        <p><label>
            <input type="radio" name="wpzoom_user_history_dashboard_access_switch" value="<?php echo esc_attr($defaults['editor']); ?>" <?php checked($defaults['editor'], $switch); ?> />
            <?php esc_html_e('Editors and Administrators', 'wpzoom-user-history'); ?>
        </label></p>
        <p><label>
            <input type="radio" name="wpzoom_user_history_dashboard_access_switch" value="<?php echo esc_attr($defaults['author']); ?>" <?php checked($defaults['author'], $switch); ?> />
            <?php esc_html_e('Authors, Editors, and Administrators', 'wpzoom-user-history'); ?>
        </label></p>
        <p><label>
            <input type="radio" name="wpzoom_user_history_dashboard_access_switch" value="capability" <?php checked('capability', $switch); ?> />
            <?php esc_html_e('Limit by capability:', 'wpzoom-user-history'); ?>
        </label>
        <?php $this->render_caps_dropdown(); ?></p>
        <p class="description">
            <?php
            printf(
                /* translators: %s: link to the Roles and Capabilities documentation */
                esc_html__('Users need the selected capability to access the dashboard. Learn more about %s.', 'wpzoom-user-history'),
                sprintf(
                    '<a href="%1$s" target="_blank">%2$s</a>',
                    esc_url('https://wordpress.org/documentation/article/roles-and-capabilities/'),
                    esc_html__('Roles & Capabilities', 'wpzoom-user-history')
                )
            );
            ?>
        </p>
        <?php
    }

    /**
     * Render the capabilities dropdown for the "Limit by capability" option.
     */
    private function render_caps_dropdown() {
        /** @global WP_Roles $wp_roles */
        global $wp_roles;

        $capabilities = [];
        foreach ($wp_roles->role_objects as $role) {
            if (is_array($role->capabilities)) {
                foreach ($role->capabilities as $cap => $grant) {
                    $capabilities[ $cap ] = $cap;
                }
            }
        }

        // Drop legacy user levels and any numeric keys some plugins register.
        $capabilities = array_filter($capabilities, static function ($cap) {
            return is_string($cap) && !preg_match('/^level_(?:\d|10)$/', $cap);
        }, ARRAY_FILTER_USE_KEY);

        if (empty($capabilities)) {
            return;
        }

        ksort($capabilities);

        $access_cap = get_option('wpzoom_user_history_dashboard_access_cap', 'manage_options');

        echo '<select name="wpzoom_user_history_dashboard_access_cap">';
        foreach ($capabilities as $capability) {
            printf(
                '<option value="%1$s" %2$s>%3$s</option>',
                esc_attr($capability),
                selected($access_cap, $capability, false),
                esc_html($capability)
            );
        }
        echo '</select>';
    }

    /**
     * Render the redirect URL field.
     */
    public function render_redirect_url_field() {
        $value = get_option('wpzoom_user_history_dashboard_redirect_url', '');
        ?>
        <input type="text" name="wpzoom_user_history_dashboard_redirect_url" class="regular-text"
               value="<?php echo esc_attr($value); ?>"
               placeholder="<?php echo esc_attr(home_url()); ?>" />
        <p class="description">
            <?php esc_html_e('Disallowed users are redirected to this URL when they try to access the dashboard. Leave empty to redirect to the homepage.', 'wpzoom-user-history'); ?>
        </p>
        <?php
    }

    /**
     * Render the profile access field.
     */
    public function render_enable_profile_field() {
        $value = get_option('wpzoom_user_history_dashboard_enable_profile', '1');
        ?>
        <label>
            <input type="checkbox" name="wpzoom_user_history_dashboard_enable_profile" value="1" <?php checked($value, '1'); ?> />
            <?php esc_html_e('Allow all users to edit their profiles in the dashboard', 'wpzoom-user-history'); ?>
        </label>
        <p class="description">
            <?php esc_html_e('When disabled, restricted users are also redirected away from their own profile page.', 'wpzoom-user-history'); ?>
        </p>
        <?php
    }

    /**
     * Render the login message field.
     */
    public function render_login_message_field() {
        $value = get_option('wpzoom_user_history_dashboard_login_message', '');
        ?>
        <input type="text" name="wpzoom_user_history_dashboard_login_message" class="regular-text"
               value="<?php echo esc_attr($value); ?>"
               placeholder="<?php echo esc_attr__('(Disabled when empty)', 'wpzoom-user-history'); ?>" />
        <p class="description">
            <?php esc_html_e('Display this message to users above the login form. Leave empty to not show a message.', 'wpzoom-user-history'); ?>
        </p>
        <?php
    }

    /**
     * Render the dashboard access advanced section description.
     */
    public function render_dashboard_advanced_section_description() {
        echo '<p>' . esc_html__('Less-common options for sites with custom AJAX endpoints or admin pages that should remain reachable.', 'wpzoom-user-history') . '</p>';
    }

    /**
     * Render the AJAX lock field.
     */
    public function render_lock_ajax_field() {
        $value = get_option('wpzoom_user_history_dashboard_lock_ajax', '0');
        ?>
        <label>
            <input type="checkbox" name="wpzoom_user_history_dashboard_lock_ajax" value="1" <?php checked($value, '1'); ?> />
            <?php esc_html_e('Also block disallowed users from admin-ajax.php requests', 'wpzoom-user-history'); ?>
        </label>
        <p class="description">
            <?php esc_html_e('Most sites should leave this off — AJAX endpoints conventionally enforce their own capability checks. Enable only if you know your AJAX surface relies on this setting to gate it.', 'wpzoom-user-history'); ?>
        </p>
        <?php
    }

    /**
     * Render the URL allowlist field.
     */
    public function render_url_allowlist_field() {
        $value = get_option('wpzoom_user_history_dashboard_url_allowlist', '');
        ?>
        <textarea name="wpzoom_user_history_dashboard_url_allowlist" class="large-text code" rows="5"
                  placeholder="<?php echo esc_attr("/wp-admin/admin.php?page=customer-portal\n/wp-admin/admin.php?page=customer-*"); ?>"><?php echo esc_textarea($value); ?></textarea>
        <p class="description">
            <?php esc_html_e('One URL per line. Each URL listed here is exempt from the dashboard redirect.', 'wpzoom-user-history'); ?>
            <?php
            echo ' ';
            echo wp_kses(
                sprintf(
                    /* translators: %1$s: the * character, %2$s: example pattern, %3$s and %4$s: example page slugs */
                    __('Use %1$s as a wildcard in a query value to allow related sub-pages at once — for example, %2$s matches %3$s, %4$s, and any other matching page.', 'wpzoom-user-history'),
                    '<code>*</code>',
                    '<code>?page=customer-*</code>',
                    '<code>customer-portal</code>',
                    '<code>customer-invoices</code>'
                ),
                ['code' => []]
            );
            echo ' ';
            esc_html_e('Absolute URLs on this site are converted to relative paths on save; external hosts are dropped.', 'wpzoom-user-history');
            ?>
        </p>
        <?php
    }

    // =========================================================================
    // Username Restrictions tab fields
    // =========================================================================

    /**
     * Render the username restrictions section description.
     */
    public function render_username_section_description() {
        echo '<p>' . esc_html__('Control which usernames visitors may pick when registering — block specific names or words, require a prefix or suffix, or enforce a length. Existing accounts are never affected. Matching is case-insensitive.', 'wpzoom-user-history') . '</p>';
    }

    /**
     * Render the master toggle.
     */
    public function render_username_enabled_field() {
        $value = get_option('wpzoom_user_history_username_restrictions_enabled', '0');
        ?>
        <label>
            <input type="checkbox" name="wpzoom_user_history_username_restrictions_enabled" value="1" <?php checked($value, '1'); ?> />
            <?php esc_html_e('Apply the rules below to usernames chosen during registration', 'wpzoom-user-history'); ?>
        </label>
        <p class="description">
            <?php esc_html_e('Works with the standard WordPress registration form, Multisite signup, BuddyPress, WooCommerce and most plugins that validate usernames through WordPress.', 'wpzoom-user-history'); ?>
        </p>
        <?php
    }

    /**
     * Render the disallow spaces field.
     */
    public function render_username_disallow_spaces_field() {
        $value = get_option('wpzoom_user_history_username_disallow_spaces', '0');
        ?>
        <label>
            <input type="checkbox" name="wpzoom_user_history_username_disallow_spaces" value="1" <?php checked($value, '1'); ?> />
            <?php esc_html_e('Do not allow spaces in usernames', 'wpzoom-user-history'); ?>
        </label>
        <p class="description">
            <?php esc_html_e('WordPress (single-site) allows spaces in usernames by default.', 'wpzoom-user-history'); ?>
        </p>
        <?php
    }

    /**
     * Render the min/max length fields.
     */
    public function render_username_length_field() {
        $min = (int) get_option('wpzoom_user_history_username_min_length', 0);
        $max = (int) get_option('wpzoom_user_history_username_max_length', 0);
        ?>
        <label>
            <?php esc_html_e('Minimum', 'wpzoom-user-history'); ?>
            <input type="number" name="wpzoom_user_history_username_min_length" min="0" max="60" step="1" class="small-text" value="<?php echo esc_attr($min); ?>" />
        </label>
        &nbsp;&nbsp;
        <label>
            <?php esc_html_e('Maximum', 'wpzoom-user-history'); ?>
            <input type="number" name="wpzoom_user_history_username_max_length" min="0" max="60" step="1" class="small-text" value="<?php echo esc_attr($max); ?>" />
        </label>
        <?php esc_html_e('characters', 'wpzoom-user-history'); ?>
        <p class="description">
            <?php esc_html_e('Set to 0 for no limit. WordPress itself never allows more than 60 characters.', 'wpzoom-user-history'); ?>
        </p>
        <?php
    }

    /**
     * Render the exact blocklist field.
     */
    public function render_username_blocklist_field() {
        $value = get_option('wpzoom_user_history_username_blocklist', '');
        ?>
        <textarea name="wpzoom_user_history_username_blocklist" class="large-text code" rows="5"
                  placeholder="<?php echo esc_attr("admin\nsupport\nwebmaster"); ?>"><?php echo esc_textarea($value); ?></textarea>
        <p class="description">
            <?php esc_html_e('One username per line. These exact usernames cannot be registered — useful for official-sounding names or names you want to reserve.', 'wpzoom-user-history'); ?>
        </p>
        <?php
    }

    /**
     * Render the partial blocklist field.
     */
    public function render_username_partial_blocklist_field() {
        $value = get_option('wpzoom_user_history_username_partial_blocklist', '');
        ?>
        <textarea name="wpzoom_user_history_username_partial_blocklist" class="large-text code" rows="5"
                  placeholder="<?php echo esc_attr("admin_\nofficial"); ?>"><?php echo esc_textarea($value); ?></textarea>
        <p class="description">
            <?php esc_html_e('One entry per line. Usernames containing any of these text fragments anywhere are rejected — useful for offensive words or a naming pattern reserved for staff (e.g. "admin_").', 'wpzoom-user-history'); ?>
        </p>
        <?php
    }

    /**
     * Render the required partials field.
     */
    public function render_username_required_partials_field() {
        $value = get_option('wpzoom_user_history_username_required_partials', '');
        ?>
        <textarea name="wpzoom_user_history_username_required_partials" class="large-text code" rows="5"
                  placeholder="<?php echo esc_attr("^member_\n_team^"); ?>"><?php echo esc_textarea($value); ?></textarea>
        <p class="description">
            <?php esc_html_e('One entry per line. When set, a username must contain at least one of these text fragments.', 'wpzoom-user-history'); ?>
            <?php
            echo ' ';
            echo wp_kses(
                sprintf(
                    /* translators: %1$s: the ^ character, %2$s: example prefix pattern, %3$s: example suffix pattern */
                    __('Prefix with %1$s to require it at the start (%2$s) or suffix with %1$s to require it at the end (%3$s); without %1$s it may appear anywhere.', 'wpzoom-user-history'),
                    '<code>^</code>',
                    '<code>^member_</code>',
                    '<code>_team^</code>'
                ),
                ['code' => []]
            );
            echo ' ';
            esc_html_e('Leave empty to not require anything.', 'wpzoom-user-history');
            ?>
        </p>
        <?php
    }

    /**
     * Render the error message field.
     */
    public function render_username_error_message_field() {
        $value = get_option('wpzoom_user_history_username_error_message', '');
        ?>
        <input type="text" name="wpzoom_user_history_username_error_message" class="regular-text"
               value="<?php echo esc_attr($value); ?>"
               placeholder="<?php echo esc_attr__('This username is not allowed. Please choose another.', 'wpzoom-user-history'); ?>" />
        <p class="description">
            <?php esc_html_e('Shown to visitors who pick a restricted username. Leave empty to use the default message. You may want to explain your naming rules here.', 'wpzoom-user-history'); ?>
        </p>
        <?php
    }

    /**
     * Render the apply-to-admins field.
     */
    public function render_username_apply_to_admins_field() {
        $value = get_option('wpzoom_user_history_username_apply_to_admins', '0');
        ?>
        <label>
            <input type="checkbox" name="wpzoom_user_history_username_apply_to_admins" value="1" <?php checked($value, '1'); ?> />
            <?php esc_html_e('Also apply the rules to usernames chosen by administrators', 'wpzoom-user-history'); ?>
        </label>
        <p class="description">
            <?php esc_html_e('By default, users who can create accounts (administrators) bypass these rules when adding users in wp-admin or changing a username from the user edit page. Enable this to enforce the rules for them too. WP-CLI is always exempt.', 'wpzoom-user-history'); ?>
        </p>
        <?php
    }

    /**
     * Render the "Test Usernames" tool (Username Restrictions tab).
     */
    private function render_username_test_tool() {
        ?>
        <hr />
        <h2><?php esc_html_e('Test Usernames', 'wpzoom-user-history'); ?></h2>
        <p class="description">
            <?php esc_html_e('Check how the saved rules above evaluate sample usernames. Separate multiple usernames with commas. Save your changes first — the test uses the stored settings.', 'wpzoom-user-history'); ?>
        </p>
        <p>
            <input type="text" id="wpzoom-user-history-test-usernames" class="regular-text" placeholder="admin, john_doe, member_jane" />
            <button type="button" class="button" id="wpzoom-user-history-test-usernames-btn">
                <?php esc_html_e('Test', 'wpzoom-user-history'); ?>
            </button>
        </p>
        <ul id="wpzoom-user-history-test-usernames-results" style="display:none; margin-left: 4px;"></ul>

        <script>
        (function() {
            var input = document.getElementById('wpzoom-user-history-test-usernames');
            var btn = document.getElementById('wpzoom-user-history-test-usernames-btn');
            var list = document.getElementById('wpzoom-user-history-test-usernames-results');
            if (!input || !btn || !list) return;

            function run() {
                var value = input.value.trim();
                if (!value) return;

                btn.disabled = true;

                var xhr = new XMLHttpRequest();
                xhr.open('POST', '<?php echo esc_url(admin_url('admin-ajax.php')); ?>');
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onload = function() {
                    btn.disabled = false;
                    list.innerHTML = '';
                    var res;
                    try { res = JSON.parse(xhr.responseText); } catch (e) { res = null; }

                    if (!res || !res.success) {
                        var li = document.createElement('li');
                        li.style.color = '#b32d2e';
                        li.textContent = (res && res.data && res.data.message) || '<?php echo esc_js(__('Something went wrong.', 'wpzoom-user-history')); ?>';
                        list.appendChild(li);
                    } else {
                        res.data.results.forEach(function(r) {
                            var li = document.createElement('li');
                            var name = document.createElement('code');
                            name.textContent = r.username;
                            var status = document.createElement('strong');
                            status.style.color = r.valid ? '#0a6b2e' : '#b32d2e';
                            status.textContent = r.valid ? '<?php echo esc_js(__('allowed', 'wpzoom-user-history')); ?>' : '<?php echo esc_js(__('restricted', 'wpzoom-user-history')); ?>';
                            li.appendChild(name);
                            li.appendChild(document.createTextNode(' — '));
                            li.appendChild(status);
                            if (!r.valid && r.reason) {
                                li.appendChild(document.createTextNode(' (' + r.reason + ')'));
                            }
                            list.appendChild(li);
                        });
                        if (!res.data.enabled) {
                            var note = document.createElement('li');
                            note.className = 'description';
                            note.textContent = '<?php echo esc_js(__('Note: username restrictions are currently disabled, so these rules are not being enforced.', 'wpzoom-user-history')); ?>';
                            list.appendChild(note);
                        }
                    }
                    list.style.display = 'block';
                };
                xhr.send('action=wpzoom_user_history_test_usernames&nonce=<?php echo esc_js(wp_create_nonce('wpzoom_user_history_test_usernames')); ?>&usernames=' + encodeURIComponent(value));
            }

            btn.addEventListener('click', run);
            input.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') { e.preventDefault(); run(); }
            });
        })();
        </script>
        <?php
    }

    // =========================================================================
    // AJAX
    // =========================================================================

    /**
     * AJAX handler for the username test tool.
     */
    public function ajax_test_usernames() {
        check_ajax_referer('wpzoom_user_history_test_usernames', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized', 'wpzoom-user-history')]);
        }

        $restrictions = WPZOOM_User_History::get_instance()->username_restrictions;
        if (!$restrictions) {
            wp_send_json_error(['message' => __('Something went wrong.', 'wpzoom-user-history')]);
        }

        $raw       = isset($_POST['usernames']) ? sanitize_text_field(wp_unslash($_POST['usernames'])) : '';
        $usernames = array_filter(array_map('trim', explode(',', $raw)), 'strlen');
        $usernames = array_slice(array_unique($usernames), 0, 50);

        $results = [];
        foreach ($usernames as $username) {
            $reason    = $restrictions->get_restriction_reason($username);
            $results[] = [
                'username' => $username,
                'valid'    => $reason === null,
                'reason'   => $reason === null ? '' : $reason,
            ];
        }

        wp_send_json_success([
            'enabled' => $restrictions->is_enabled(),
            'results' => $results,
        ]);
    }

    /**
     * AJAX handler for clearing all logs.
     */
    public function ajax_clear_all_logs() {
        check_ajax_referer('wpzoom_user_history_clear_all', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized', 'wpzoom-user-history')]);
        }

        global $wpdb;
        $table_name = $wpdb->prefix . WPZOOM_User_History::TABLE_NAME;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Truncating custom plugin table
        $wpdb->query(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safely constructed from $wpdb->prefix
            "TRUNCATE TABLE $table_name"
        );

        wp_send_json_success([
            'message' => __('All logs have been cleared.', 'wpzoom-user-history'),
        ]);
    }

    // =========================================================================
    // Page rendering
    // =========================================================================

    /**
     * Render the settings page with tab navigation.
     */
    public function render_settings_page() {
        $tabs       = $this->get_tabs();
        $active_tab = $this->get_active_tab();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('User History Settings', 'wpzoom-user-history'); ?></h1>

            <h2 class="nav-tab-wrapper">
                <?php foreach ($tabs as $tab => $label) : ?>
                    <a href="<?php echo esc_url(add_query_arg(['page' => 'wpzoom-user-history', 'tab' => $tab], admin_url('options-general.php'))); ?>"
                       class="nav-tab <?php echo $active_tab === $tab ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html($label); ?>
                    </a>
                <?php endforeach; ?>
            </h2>

            <form method="post" action="options.php">
                <?php
                settings_fields($this->get_option_group($active_tab));
                do_settings_sections($this->get_settings_page($active_tab));
                submit_button();
                ?>
            </form>

            <?php if ($active_tab === 'general') : ?>
                <?php $this->render_clear_all_logs(); ?>
            <?php elseif ($active_tab === 'dashboard-access') : ?>
                <?php $this->render_dashboard_access_js(); ?>
            <?php elseif ($active_tab === 'username-restrictions') : ?>
                <?php $this->render_username_test_tool(); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render the Clear All Logs block (General tab).
     */
    private function render_clear_all_logs() {
        ?>
        <hr />
        <h2><?php esc_html_e('Clear All Logs', 'wpzoom-user-history'); ?></h2>
        <p class="description">
            <?php esc_html_e('Delete all history and login logs for every user. This cannot be undone.', 'wpzoom-user-history'); ?>
        </p>
        <p>
            <button type="button" class="button button-link-delete" id="wpzoom-user-history-clear-all">
                <?php esc_html_e('Clear All Logs', 'wpzoom-user-history'); ?>
            </button>
            <span id="wpzoom-user-history-clear-all-message" style="display:none; margin-left:10px;"></span>
        </p>

        <script>
        (function() {
            var btn = document.getElementById('wpzoom-user-history-clear-all');
            if (!btn) return;

            btn.addEventListener('click', function() {
                if (!confirm('<?php echo esc_js(__('Are you sure you want to delete ALL history logs for every user? This cannot be undone.', 'wpzoom-user-history')); ?>')) {
                    return;
                }

                btn.disabled = true;
                btn.textContent = '<?php echo esc_js(__('Clearing...', 'wpzoom-user-history')); ?>';

                var xhr = new XMLHttpRequest();
                xhr.open('POST', '<?php echo esc_url(admin_url('admin-ajax.php')); ?>');
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onload = function() {
                    var msg = document.getElementById('wpzoom-user-history-clear-all-message');
                    try {
                        var res = JSON.parse(xhr.responseText);
                        if (res.success) {
                            msg.style.color = '#0a6b2e';
                            msg.textContent = res.data.message;
                        } else {
                            msg.style.color = '#b32d2e';
                            msg.textContent = res.data.message || '<?php echo esc_js(__('Something went wrong.', 'wpzoom-user-history')); ?>';
                            btn.disabled = false;
                            btn.textContent = '<?php echo esc_js(__('Clear All Logs', 'wpzoom-user-history')); ?>';
                        }
                    } catch(e) {
                        msg.style.color = '#b32d2e';
                        msg.textContent = '<?php echo esc_js(__('Something went wrong.', 'wpzoom-user-history')); ?>';
                        btn.disabled = false;
                        btn.textContent = '<?php echo esc_js(__('Clear All Logs', 'wpzoom-user-history')); ?>';
                    }
                    msg.style.display = 'inline';
                };
                xhr.send('action=wpzoom_user_history_clear_all&nonce=<?php echo wp_create_nonce('wpzoom_user_history_clear_all'); ?>');
            });
        })();
        </script>
        <?php
    }

    /**
     * Inline JS for the Dashboard Access tab: the capability dropdown is only
     * enabled while the "Limit by capability" radio is selected (mimics the
     * "Page on front" toggle in options-reading.php).
     */
    private function render_dashboard_access_js() {
        ?>
        <script>
        (function() {
            var radios = document.querySelectorAll('input[name="wpzoom_user_history_dashboard_access_switch"]');
            var select = document.querySelector('select[name="wpzoom_user_history_dashboard_access_cap"]');
            if (!radios.length || !select) return;

            function toggle() {
                var capRadio = document.querySelector('input[name="wpzoom_user_history_dashboard_access_switch"][value="capability"]');
                select.disabled = !(capRadio && capRadio.checked);
            }

            toggle();
            radios.forEach(function(radio) {
                radio.addEventListener('change', toggle);
            });
        })();
        </script>
        <?php
    }
}
