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
 * The plugin has a top-level "User History" admin menu. The parent page is
 * the Activity Log; each settings section (General / Lock Account /
 * Dashboard Access / Username Restrictions) is its own submenu page. Each
 * section has its own Settings API option group and page slug, derived from
 * the section key: group `wpzoom_user_history_settings_{tab}` and page
 * `wpzoom-user-history-{tab}` (which is also the submenu slug).
 */
class WPZOOM_User_History_Settings {

    /**
     * Parent menu slug (Activity Log page).
     */
    const MENU_SLUG = 'wpzoom-user-history';

    /**
     * Activity log page hook suffix (set in add_settings_page()).
     *
     * @var string
     */
    private $activity_hook = '';

    /**
     * Constructor — registers hooks.
     */
    public function __construct() {
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_init', [$this, 'redirect_legacy_settings_url']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_filter('set-screen-option', [$this, 'save_screen_option'], 10, 3);
        add_filter('plugin_action_links_' . plugin_basename(WPZOOM_USER_HISTORY_PLUGIN_DIR . 'wpzoom-user-history.php'), [$this, 'add_plugin_action_links']);
        add_action('wp_ajax_wpzoom_user_history_clear_all', [$this, 'ajax_clear_all_logs']);
        add_action('wp_ajax_wpzoom_user_history_clear_activity', [$this, 'ajax_clear_activity_log']);
        add_action('wp_ajax_wpzoom_user_history_test_usernames', [$this, 'ajax_test_usernames']);
    }

    /**
     * Settings sections (each is a submenu page).
     *
     * @return array Labels keyed by section slug.
     */
    private function get_tabs() {
        return [
            'lock'                  => __('Lock Accounts', 'wpzoom-user-history'),
            'dashboard-access'      => __('Dashboard Access', 'wpzoom-user-history'),
            'username-restrictions' => __('Username Restrictions', 'wpzoom-user-history'),
            'general'               => __('Settings', 'wpzoom-user-history'),
        ];
    }

    /**
     * Currently active settings section, derived from the `page` query var
     * (submenu slug `wpzoom-user-history-{tab}`).
     *
     * @return string Section slug.
     */
    private function get_active_tab() {
        $tabs = $this->get_tabs();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Selecting which page to display, no state change
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        $tab  = (strpos($page, self::MENU_SLUG . '-') === 0) ? substr($page, strlen(self::MENU_SLUG) + 1) : 'general';

        return isset($tabs[ $tab ]) ? $tab : 'general';
    }

    /**
     * Admin URL for a settings section (or the Activity Log when empty).
     *
     * @param string $tab Section slug, or '' for the Activity Log.
     * @return string
     */
    public static function get_page_url($tab = '') {
        $slug = $tab === '' ? self::MENU_SLUG : self::MENU_SLUG . '-' . $tab;
        return add_query_arg('page', $slug, admin_url('admin.php'));
    }

    /**
     * Redirect old Settings > User History links to the new menu location.
     */
    public function redirect_legacy_settings_url() {
        global $pagenow;

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect of a legacy URL
        if ($pagenow !== 'options-general.php' || !isset($_GET['page']) || sanitize_key(wp_unslash($_GET['page'])) !== self::MENU_SLUG) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'general';
        if (!isset($this->get_tabs()[ $tab ])) {
            $tab = 'general';
        }

        wp_safe_redirect(self::get_page_url($tab));
        exit;
    }

    /**
     * Add a Settings link on the Plugins screen.
     *
     * @param array $links Action links.
     * @return array
     */
    public function add_plugin_action_links($links) {
        array_unshift(
            $links,
            '<a href="' . esc_url(self::get_page_url('general')) . '">' . esc_html__('Settings', 'wpzoom-user-history') . '</a>',
            '<a href="' . esc_url(self::get_page_url()) . '">' . esc_html__('Activity Log', 'wpzoom-user-history') . '</a>'
        );
        return $links;
    }

    /**
     * Load the plugin stylesheet on our admin pages.
     *
     * @param string $hook Current admin page hook.
     */
    public function enqueue_assets($hook) {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page detection
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page !== self::MENU_SLUG && strpos($page, self::MENU_SLUG . '-') !== 0) {
            return;
        }

        wp_enqueue_style(
            'wpzoom-user-history-admin',
            WPZOOM_USER_HISTORY_PLUGIN_URL . 'assets/css/admin.css',
            [],
            WPZOOM_USER_HISTORY_VERSION
        );
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
     * Add the top-level "User History" menu with its submenu pages.
     */
    public function add_settings_page() {
        add_menu_page(
            __('User History', 'wpzoom-user-history'),
            __('User History', 'wpzoom-user-history'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render_activity_log_page'],
            self::get_menu_icon(),
            71 // Right after Users (70)
        );

        // First submenu duplicates the parent so it reads "Activity Log".
        $this->activity_hook = add_submenu_page(
            self::MENU_SLUG,
            __('Activity Log', 'wpzoom-user-history'),
            __('Activity Log', 'wpzoom-user-history'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render_activity_log_page']
        );

        if ($this->activity_hook) {
            add_action('load-' . $this->activity_hook, [$this, 'add_activity_screen_options']);
        }

        foreach ($this->get_tabs() as $tab => $label) {
            add_submenu_page(
                self::MENU_SLUG,
                /* translators: %s: settings section name */
                sprintf(__('User History — %s', 'wpzoom-user-history'), $label),
                $label,
                'manage_options',
                self::MENU_SLUG . '-' . $tab,
                [$this, 'render_settings_page']
            );
        }
    }

    /**
     * Admin menu icon as a base64 SVG data URI.
     *
     * WordPress's svg-painter.js recolors the SVG fills to match the current
     * admin color scheme (and hover/current states), so a single neutral fill
     * is used here rather than the brand colors.
     *
     * @return string
     */
    public static function get_menu_icon() {
        return 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAxOTAgMTkwIj48cGF0aCBmaWxsPSIjYTdhYWFkIiBkPSJNOTUgMTg0LjAxOEMxMTcuODAzIDE4NC4wMTggMTQwLjYxNSAxNzUuMzM0IDE1Ny45NzYgMTU3Ljk3NkMxNzQuOCAxNDEuMTU1IDE4NC4wNjIgMTE4Ljc4OSAxODQuMDYyIDk1QzE4NC4wNjIgNzEuMjExNCAxNzQuNzk3IDQ4Ljg0NDggMTU3Ljk3NiAzMi4wMjM5QzE0MS4xNTUgMTUuMiAxMTguNzg5IDUuOTM3NSA5NSA1LjkzNzVDNzMuNTIxMSA1LjkzNzUgNTMuMjE3OCAxMy41MTM4IDM3LjA5MTYgMjcuMzYzVjE0Ljc1NzdDMzcuMDkxNiAxMy4xMTg5IDM1Ljc2MTYgMTEuNzg4OSAzNC4xMjI4IDExLjc4ODlDMzIuNDg0MSAxMS43ODg5IDMxLjE1NDEgMTMuMTE4OSAzMS4xNTQxIDE0Ljc1NzdWMzQuMTIyOEMzMS4xNTQxIDM0LjUwODggMzEuMjM0MiAzNC44OTQ3IDMxLjM4MjYgMzUuMjU2OUMzMS42ODI1IDM1Ljk4MTMgMzIuMjYxNCAzNi41NjAyIDMyLjk4ODcgMzYuODYzQzMzLjM1MDkgMzcuMDE0NCAzMy43MzY5IDM3LjA5MTYgMzQuMTIyOCAzNy4wOTE2SDUzLjQ4OEM1NS4xMjY3IDM3LjA5MTYgNTYuNDU2NyAzNS43NjE2IDU2LjQ1NjcgMzQuMTIyOEM1Ni40NTY3IDMyLjQ4NDEgNTUuMTI2NyAzMS4xNTQxIDUzLjQ4OCAzMS4xNTQxSDQxLjc4MjJDNTYuNjk3MiAxOC42Nzk0IDc1LjMyMzEgMTEuODc1IDk1IDExLjg3NUMxMTcuMjAzIDExLjg3NSAxMzguMDc3IDIwLjUyMyAxNTMuNzc4IDM2LjIyMTdDMTY5LjQ4IDUxLjkyMDUgMTc4LjEyNSA3Mi43OTY3IDE3OC4xMjUgOTVDMTc4LjEyNSAxMTcuMjAzIDE2OS40NzcgMTM4LjA3NyAxNTMuNzc4IDE1My43NzhDMTIxLjM2OCAxODYuMTg1IDY4LjYzMTUgMTg2LjE4NSAzNi4yMjE3IDE1My43NzhDMTIuMjkzNiAxMjkuODQ3IDUuMjQ1NzcgOTQuMjA0NCAxOC4yNjY3IDYyLjk3MzFDMTguODk5MSA2MS40NTkxIDE4LjE4MzYgNTkuNzIyMyAxNi42Njk1IDU5LjA5QzE1LjE1NTUgNTguNDU0NyAxMy40MTg3IDU5LjE3MzEgMTIuNzg2NCA2MC42ODcyQy0xLjE2Mzc2IDk0LjE1MDkgNi4zODU3NyAxMzIuMzM4IDMyLjAyMzkgMTU3Ljk3NkM0OS4zODgxIDE3NS4zNDMgNzIuMTkxMSAxODQuMDIxIDk1IDE4NC4wMThaIi8+PHBhdGggZmlsbD0iI2E3YWFhZCIgZD0iTTQ5LjU3NTEgMTE5LjQzM1YxNDEuOTU3QzQ5LjU3NTEgMTQzLjU5OCA1MC45MDUxIDE0NC45MjUgNTIuNTQzOSAxNDQuOTI1SDEzNy40NTZDMTM5LjA5NSAxNDQuOTI1IDE0MC40MjUgMTQzLjU5OCAxNDAuNDI1IDE0MS45NTdWMTE5LjQzM0MxNDAuNDI1IDEwMi45OCAxMjcuNzQ1IDg5LjQ1NDQgMTExLjY0OSA4OC4wNjJDMTE3LjkxIDgzLjEyMiAxMjEuOTQ3IDc1LjQ4OTQgMTIxLjk0NyA2Ni45MTU2QzEyMS45NDcgNTIuMDU3IDEwOS44NTkgMzkuOTY4MyA5NSAzOS45NjgzQzgwLjE0MTQgMzkuOTY4MyA2OC4wNTI2IDUyLjA1NyA2OC4wNTI2IDY2LjkxNTZDNjguMDUyNiA3NS40ODk0IDcyLjA5MzEgODMuMTI1IDc4LjM1MTIgODguMDYyQzYyLjI1NDcgODkuNDU0NCA0OS41NzUxIDEwMi45OCA0OS41NzUxIDExOS40MzNaTTczLjk5MDEgNjYuOTE1NkM3My45OTAxIDU1LjMzMTUgODMuNDE1OSA0NS45MDU4IDk1IDQ1LjkwNThDMTA2LjU4NCA0NS45MDU4IDExNi4wMSA1NS4zMzE1IDExNi4wMSA2Ni45MTU2QzExNi4wMSA3OC40OTk3IDEwNi41ODQgODcuOTI1NCA5NSA4Ny45MjU0QzgzLjQxNTkgODcuOTI1NCA3My45OTAxIDc4LjQ5OTcgNzMuOTkwMSA2Ni45MTU2Wk04MS4wODU0IDkzLjg2MjlIMTA4LjkxN0MxMjMuMDE5IDkzLjg2MjkgMTM0LjQ4NyAxMDUuMzM0IDEzNC40ODcgMTE5LjQzNlYxMzguOTkxSDU1LjUxMjZWMTE5LjQzNkM1NS41MTI2IDEwNS4zMzQgNjYuOTg2OSA5My44NjI5IDgxLjA4NTQgOTMuODYyOVoiLz48L3N2Zz4=';
    }

    /**
     * Screen Options for the Activity Log page (rows per page).
     */
    public function add_activity_screen_options() {
        add_screen_option('per_page', [
            'label'   => __('Entries per page', 'wpzoom-user-history'),
            'default' => 30,
            'option'  => 'wpzoom_user_history_activity_per_page',
        ]);
    }

    /**
     * Persist the Activity Log per-page screen option.
     *
     * @param mixed  $status Screen option value (false to not save).
     * @param string $option Option name.
     * @param mixed  $value  Submitted value.
     * @return mixed
     */
    public function save_screen_option($status, $option, $value) {
        if ($option === 'wpzoom_user_history_activity_per_page') {
            return max(1, min(500, (int) $value));
        }
        return $status;
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

        register_setting($group, 'wpzoom_user_history_activity_log_enabled', [
            'type'              => 'string',
            'sanitize_callback' => [$this, 'sanitize_checkbox'],
            'default'           => '1',
        ]);

        register_setting($group, 'wpzoom_user_history_activity_log_events', [
            'type'              => 'array',
            'sanitize_callback' => [$this, 'sanitize_activity_events'],
            'default'           => WPZOOM_User_History_Activity_Log::get_event_group_slugs(),
        ]);

        add_settings_section(
            'wpzoom_user_history_activity_section',
            __('Activity Log', 'wpzoom-user-history'),
            [$this, 'render_activity_section_description'],
            $page
        );

        add_settings_field(
            'wpzoom_user_history_activity_log_enabled',
            __('Activity Log', 'wpzoom-user-history'),
            [$this, 'render_activity_enabled_field'],
            $page,
            'wpzoom_user_history_activity_section'
        );

        add_settings_field(
            'wpzoom_user_history_activity_log_events',
            __('Events to Record', 'wpzoom-user-history'),
            [$this, 'render_activity_events_field'],
            $page,
            'wpzoom_user_history_activity_section'
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
            __('Settings', 'wpzoom-user-history'),
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
     * Sanitize the activity log event groups.
     *
     * @param mixed $value Submitted array of group slugs.
     * @return string[] Valid group slugs.
     */
    public function sanitize_activity_events($value) {
        if (!is_array($value)) {
            return [];
        }
        $valid = WPZOOM_User_History_Activity_Log::get_event_group_slugs();
        return array_values(array_intersect($valid, array_map('sanitize_key', $value)));
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
            <?php esc_html_e('User history and activity log entries older than this many days are automatically deleted. Set to 0 to keep logs indefinitely.', 'wpzoom-user-history'); ?>
        </p>
        <?php
    }

    /**
     * Render the activity log section description.
     */
    public function render_activity_section_description() {
        printf(
            '<p>%s <a href="%s">%s</a></p>',
            esc_html__('Record a site-wide log of what users do — content edits, uploads, comments, user management, logins, plugin/theme changes and settings changes.', 'wpzoom-user-history'),
            esc_url(self::get_page_url()),
            esc_html__('View the Activity Log', 'wpzoom-user-history')
        );
    }

    /**
     * Render the activity log enable field.
     */
    public function render_activity_enabled_field() {
        $value = get_option('wpzoom_user_history_activity_log_enabled', '1');
        ?>
        <label>
            <input type="checkbox" name="wpzoom_user_history_activity_log_enabled" value="1" <?php checked($value, '1'); ?> />
            <?php esc_html_e('Enable the activity log', 'wpzoom-user-history'); ?>
        </label>
        <p class="description">
            <?php esc_html_e('Entries follow the retention period and IP address setting above.', 'wpzoom-user-history'); ?>
        </p>
        <?php
    }

    /**
     * Render the activity log event group checkboxes.
     */
    public function render_activity_events_field() {
        $groups  = WPZOOM_User_History_Activity_Log::get_event_groups();
        $enabled = get_option('wpzoom_user_history_activity_log_events', array_keys($groups));
        if (!is_array($enabled)) {
            $enabled = array_keys($groups);
        }
        ?>
        <fieldset>
            <?php foreach ($groups as $slug => $label) : ?>
                <label style="display:block; margin-bottom:6px;">
                    <input type="checkbox" name="wpzoom_user_history_activity_log_events[]" value="<?php echo esc_attr($slug); ?>" <?php checked(in_array($slug, $enabled, true)); ?> />
                    <?php echo esc_html($label); ?>
                </label>
            <?php endforeach; ?>
        </fieldset>
        <p class="description">
            <?php esc_html_e('Untick a group to stop recording those events. Existing entries are kept until they expire.', 'wpzoom-user-history'); ?>
        </p>
        <?php
    }

    // =========================================================================
    // Lock Account tab fields
    // =========================================================================

    /**
     * Render the Lock Account overview: stats, how it works, currently locked
     * users and recent lock/unlock activity. Shown above the settings form.
     */
    private function render_lock_overview() {
        $lock = WPZOOM_User_History::get_instance()->lock;
        if (!$lock) {
            return;
        }

        $locked_count = $lock->get_locked_user_count();
        $counts       = $lock->get_lock_event_counts(30);
        $locked_users = $lock->get_locked_users(10);
        $events       = $lock->get_lock_events(10);
        $locked_url   = add_query_arg('wpzoom_user_history_filter', 'locked', admin_url('users.php'));
        $date_format  = get_option('date_format') . ' ' . get_option('time_format');
        ?>
        <div class="user-history-overview">
            <div class="user-history-stats">
                <a class="user-history-stat" href="<?php echo esc_url($locked_count ? $locked_url : admin_url('users.php')); ?>">
                    <span class="user-history-stat-value"><?php echo esc_html(number_format_i18n($locked_count)); ?></span>
                    <span class="user-history-stat-label"><?php esc_html_e('Locked accounts', 'wpzoom-user-history'); ?></span>
                </a>
                <div class="user-history-stat">
                    <span class="user-history-stat-value"><?php echo esc_html(number_format_i18n($counts['lock'])); ?></span>
                    <span class="user-history-stat-label"><?php esc_html_e('Locked in the last 30 days', 'wpzoom-user-history'); ?></span>
                </div>
                <div class="user-history-stat">
                    <span class="user-history-stat-value"><?php echo esc_html(number_format_i18n($counts['unlock'])); ?></span>
                    <span class="user-history-stat-label"><?php esc_html_e('Unlocked in the last 30 days', 'wpzoom-user-history'); ?></span>
                </div>
            </div>

            <div class="user-history-overview-columns">
                <div class="user-history-overview-box">
                    <h2><?php esc_html_e('How it works', 'wpzoom-user-history'); ?></h2>
                    <p><?php esc_html_e('Locking an account prevents a user from signing in without deleting anything. Their content, role and profile stay intact, and you can unlock them at any time.', 'wpzoom-user-history'); ?></p>

                    <h3><?php esc_html_e('Where to lock a user', 'wpzoom-user-history'); ?></h3>
                    <ul>
                        <li>
                            <?php
                            printf(
                                /* translators: %s: link to the Users screen */
                                esc_html__('On the %s screen, hover over a user and click "Lock" (or "Unlock"). Select several users and choose Lock / Unlock from the Bulk actions menu to change many at once.', 'wpzoom-user-history'),
                                '<a href="' . esc_url(admin_url('users.php')) . '">' . esc_html__('Users', 'wpzoom-user-history') . '</a>'
                            );
                            ?>
                        </li>
                        <li><?php esc_html_e('On a user\'s profile page (Users → Edit), use the Lock Account / Unlock Account button in the Account History panel.', 'wpzoom-user-history'); ?></li>
                        <li><?php esc_html_e('The Status column on the Users screen shows a "Locked" badge, and the "Locked" view at the top filters the list to locked accounts only.', 'wpzoom-user-history'); ?></li>
                    </ul>

                    <h3><?php esc_html_e('What happens when an account is locked', 'wpzoom-user-history'); ?></h3>
                    <ul>
                        <li><?php esc_html_e('All of the user\'s active sessions are destroyed immediately — they are logged out everywhere.', 'wpzoom-user-history'); ?></li>
                        <li><?php esc_html_e('Login attempts fail with the message configured below, on the login form as well as via XML-RPC and the REST API (application passwords).', 'wpzoom-user-history'); ?></li>
                        <li><?php esc_html_e('Every lock and unlock is recorded in the user\'s Account History and in the Activity Log, together with who did it.', 'wpzoom-user-history'); ?></li>
                        <li><?php esc_html_e('You cannot lock your own account, and super admins cannot be locked on Multisite. WP-CLI access is never blocked so administrators can always recover.', 'wpzoom-user-history'); ?></li>
                    </ul>
                </div>

                <div class="user-history-overview-box">
                    <h2>
                        <?php esc_html_e('Currently locked', 'wpzoom-user-history'); ?>
                        <?php if ($locked_count > count($locked_users)) : ?>
                            <a class="user-history-overview-more" href="<?php echo esc_url($locked_url); ?>">
                                <?php
                                /* translators: %d: total number of locked users */
                                echo esc_html(sprintf(__('View all %d', 'wpzoom-user-history'), $locked_count));
                                ?>
                            </a>
                        <?php endif; ?>
                    </h2>

                    <?php if (empty($locked_users)) : ?>
                        <p class="description"><?php esc_html_e('No accounts are locked right now.', 'wpzoom-user-history'); ?></p>
                    <?php else : ?>
                        <table class="widefat striped user-history-overview-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('User', 'wpzoom-user-history'); ?></th>
                                    <th><?php esc_html_e('Role', 'wpzoom-user-history'); ?></th>
                                    <th><?php esc_html_e('Locked', 'wpzoom-user-history'); ?></th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($locked_users as $user) :
                                $event     = $lock->get_last_lock_event($user->ID);
                                $locked_by = ($event && $event->changed_by) ? get_userdata($event->changed_by) : null;
                                $roles     = array_map(static function ($role) {
                                    return translate_user_role(wp_roles()->get_names()[ $role ] ?? $role);
                                }, $user->roles);
                                ?>
                                <tr>
                                    <td>
                                        <?php echo get_avatar($user->ID, 24, '', '', ['class' => 'user-history-activity-avatar']); ?>
                                        <span class="user-history-activity-user">
                                            <a href="<?php echo esc_url(get_edit_user_link($user->ID)); ?>"><strong><?php echo esc_html($user->display_name); ?></strong></a><br>
                                            <span class="user-history-activity-login"><?php echo esc_html($user->user_login); ?></span>
                                        </span>
                                    </td>
                                    <td><?php echo esc_html(implode(', ', $roles)); ?></td>
                                    <td>
                                        <?php if ($event) : ?>
                                            <span title="<?php echo esc_attr(date_i18n($date_format, strtotime($event->created_at))); ?>">
                                                <?php
                                                /* translators: %s: human-readable time difference */
                                                echo esc_html(sprintf(__('%s ago', 'wpzoom-user-history'), human_time_diff(strtotime($event->created_at), current_time('timestamp'))));
                                                ?>
                                            </span>
                                            <?php if ($locked_by) : ?>
                                                <br><span class="user-history-activity-ago">
                                                    <?php
                                                    /* translators: %s: username of the admin who locked the account */
                                                    echo esc_html(sprintf(__('by %s', 'wpzoom-user-history'), $locked_by->user_login));
                                                    ?>
                                                </span>
                                            <?php endif; ?>
                                        <?php else : ?>
                                            &mdash;
                                        <?php endif; ?>
                                    </td>
                                    <td class="user-history-overview-actions">
                                        <?php if (current_user_can('edit_users') && $user->ID !== get_current_user_id()) : ?>
                                            <a class="button button-small" href="<?php echo esc_url($lock->get_toggle_url($user->ID, false)); ?>"><?php esc_html_e('Unlock', 'wpzoom-user-history'); ?></a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>

                    <h2 class="user-history-overview-subheading"><?php esc_html_e('Recent lock activity', 'wpzoom-user-history'); ?></h2>
                    <?php if (empty($events)) : ?>
                        <p class="description"><?php esc_html_e('No lock or unlock events recorded yet.', 'wpzoom-user-history'); ?></p>
                    <?php else : ?>
                        <ul class="user-history-overview-events">
                            <?php foreach ($events as $event) :
                                $target = get_userdata($event->user_id);
                                $actor  = $event->changed_by ? get_userdata($event->changed_by) : null;
                                $target_name = $target ? $target->user_login : sprintf('#%d', $event->user_id);
                                $actor_name  = $actor ? $actor->user_login : __('system', 'wpzoom-user-history');
                                $target_html = $target ? '<a href="' . esc_url(get_edit_user_link($target->ID)) . '">' . esc_html($target_name) . '</a>' : esc_html($target_name);
                                ?>
                                <li>
                                    <span class="user-history-lock-badge <?php echo $event->change_type === 'lock' ? 'locked' : 'active'; ?>">
                                        <?php echo $event->change_type === 'lock' ? esc_html__('Locked', 'wpzoom-user-history') : esc_html__('Unlocked', 'wpzoom-user-history'); ?>
                                    </span>
                                    <?php
                                    echo wp_kses(
                                        sprintf(
                                            /* translators: 1: locked/unlocked user (linked), 2: admin who performed the action */
                                            __('%1$s by %2$s', 'wpzoom-user-history'),
                                            $target_html,
                                            '<strong>' . esc_html($actor_name) . '</strong>'
                                        ),
                                        ['a' => ['href' => []], 'strong' => []]
                                    );
                                    ?>
                                    <span class="user-history-activity-ago" title="<?php echo esc_attr(date_i18n($date_format, strtotime($event->created_at))); ?>">
                                        &middot;
                                        <?php
                                        /* translators: %s: human-readable time difference */
                                        echo esc_html(sprintf(__('%s ago', 'wpzoom-user-history'), human_time_diff(strtotime($event->created_at), current_time('timestamp'))));
                                        ?>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

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
     * AJAX handler for clearing the activity log.
     */
    public function ajax_clear_activity_log() {
        check_ajax_referer('wpzoom_user_history_clear_activity', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized', 'wpzoom-user-history')]);
        }

        WPZOOM_User_History::get_instance()->activity_log->clear();

        wp_send_json_success(['message' => __('The activity log has been cleared.', 'wpzoom-user-history')]);
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
     * Render a settings section page (one per submenu).
     */
    public function render_settings_page() {
        $tabs       = $this->get_tabs();
        $active_tab = $this->get_active_tab();

        ?>
        <div class="wrap">
            <h1>
                <?php
                /* translators: %s: settings section name */
                echo esc_html(sprintf(__('User History — %s', 'wpzoom-user-history'), $tabs[ $active_tab ]));
                ?>
            </h1>

            <?php settings_errors(); ?>

            <?php if ($active_tab === 'lock') : ?>
                <?php $this->render_lock_overview(); ?>
            <?php endif; ?>

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
     * Render the Activity Log page (parent menu item).
     */
    public function render_activity_log_page() {
        $log = WPZOOM_User_History::get_instance()->activity_log;

        require_once WPZOOM_USER_HISTORY_PLUGIN_DIR . 'includes/class-activity-list-table.php';
        $table = new WPZOOM_User_History_Activity_List_Table($log);
        $table->prepare_items();
        ?>
        <div class="wrap user-history-activity-wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Activity Log', 'wpzoom-user-history'); ?></h1>
            <a href="<?php echo esc_url(self::get_page_url('general') . '#wpzoom_user_history_activity_section'); ?>" class="page-title-action"><?php esc_html_e('Settings', 'wpzoom-user-history'); ?></a>
            <button type="button" class="page-title-action" id="wpzoom-user-history-clear-activity" style="color:#b32d2e; border-color:#b32d2e;"><?php esc_html_e('Clear Activity Log', 'wpzoom-user-history'); ?></button>
            <hr class="wp-header-end">

            <?php if (!$log->is_enabled()) : ?>
                <div class="notice notice-warning inline">
                    <p>
                        <?php esc_html_e('The activity log is currently disabled — new events are not being recorded.', 'wpzoom-user-history'); ?>
                        <a href="<?php echo esc_url(self::get_page_url('general')); ?>"><?php esc_html_e('Enable it in General settings.', 'wpzoom-user-history'); ?></a>
                    </p>
                </div>
            <?php endif; ?>

            <div id="wpzoom-user-history-clear-activity-message" class="notice inline" style="display:none;"><p></p></div>

            <form method="get">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::MENU_SLUG); ?>" />
                <?php
                $table->search_box(__('Search activity', 'wpzoom-user-history'), 'wpzoom-activity');
                $table->display();
                ?>
            </form>
        </div>

        <script>
        (function() {
            var btn = document.getElementById('wpzoom-user-history-clear-activity');
            if (!btn) return;

            btn.addEventListener('click', function() {
                if (!confirm('<?php echo esc_js(__('Delete ALL activity log entries? This cannot be undone.', 'wpzoom-user-history')); ?>')) {
                    return;
                }

                btn.disabled = true;

                var xhr = new XMLHttpRequest();
                xhr.open('POST', '<?php echo esc_url(admin_url('admin-ajax.php')); ?>');
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onload = function() {
                    var box = document.getElementById('wpzoom-user-history-clear-activity-message');
                    var res;
                    try { res = JSON.parse(xhr.responseText); } catch (e) { res = null; }
                    if (res && res.success) {
                        window.location.reload();
                        return;
                    }
                    box.className = 'notice notice-error inline';
                    box.querySelector('p').textContent = (res && res.data && res.data.message) || '<?php echo esc_js(__('Something went wrong.', 'wpzoom-user-history')); ?>';
                    box.style.display = 'block';
                    btn.disabled = false;
                };
                xhr.send('action=wpzoom_user_history_clear_activity&nonce=<?php echo esc_js(wp_create_nonce('wpzoom_user_history_clear_activity')); ?>');
            });
        })();
        </script>
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
