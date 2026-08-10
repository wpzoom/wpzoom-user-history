<?php
/**
 * Dashboard access restriction for User History plugin.
 *
 * Ported from the "Remove Dashboard Access" plugin
 * (remove-dashboard-access-for-non-admins) and adapted to this plugin's
 * prefix conventions. Settings UI lives in WPZOOM_User_History_Settings
 * (Dashboard Access tab); this class only enforces.
 *
 * @package UserHistory
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Restricts wp-admin access to users with a configured capability.
 *
 * Disallowed users are redirected to a configurable URL (homepage by
 * default). Profile access can optionally remain available, and an
 * allowlist of admin URLs can punch holes through the restriction
 * (e.g. front-end account pages served via admin.php).
 */
class WPZOOM_User_History_Dashboard_Access {

    /**
     * Constructor — registers hooks.
     */
    public function __construct() {
        add_action('init', [$this, 'maybe_restrict_access']);
        add_filter('login_message', [$this, 'output_login_message']);
    }

    /**
     * Whether the dashboard access restriction is enabled.
     *
     * @return bool
     */
    public function is_enabled() {
        return get_option('wpzoom_user_history_dashboard_access_enabled', '0') === '1';
    }

    /**
     * Default capabilities for the role-based access presets.
     *
     * Shared with the settings UI, which renders one radio button per entry.
     *
     * @return array Capabilities keyed by 'admin', 'editor', 'author'.
     */
    public static function get_default_role_caps() {
        /**
         * Filter the capability defaults for admins, editors, and authors.
         *
         * @param array $capabilities {
         *     Default capabilities for various roles.
         *
         *     @type string $admin  Capability for administrators only. Default 'manage_options'.
         *     @type string $editor Capability for admins + editors. Default 'edit_others_posts'.
         *     @type string $author Capability for admins + editors + authors. Default 'publish_posts'.
         * }
         */
        return apply_filters('wpzoom_user_history_default_caps_for_role', [
            'admin'  => 'manage_options',
            'editor' => 'edit_others_posts',
            'author' => 'publish_posts',
        ]);
    }

    /**
     * Resolve the capability required for dashboard access.
     *
     * @return string Capability name.
     */
    public function get_capability() {
        $switch = get_option('wpzoom_user_history_dashboard_access_switch', 'manage_options');

        if ($switch === 'capability') {
            $capability = get_option('wpzoom_user_history_dashboard_access_cap', 'manage_options');
        } else {
            $capability = $switch;
        }

        if (!$capability) {
            $capability = 'manage_options';
        }

        /**
         * Filter the capability required to access the dashboard.
         *
         * @param string $capability Capability needed to access the dashboard.
         */
        return apply_filters('wpzoom_user_history_dashboard_access_capability', $capability);
    }

    /**
     * Whether disallowed users may still access their profile page.
     *
     * @return bool
     */
    private function is_profile_access_enabled() {
        return get_option('wpzoom_user_history_dashboard_enable_profile', '1') === '1';
    }

    /**
     * URL disallowed users are redirected to. Falls back to the homepage.
     *
     * @return string
     */
    private function get_redirect_url() {
        $redirect_url = get_option('wpzoom_user_history_dashboard_redirect_url', '');

        return $redirect_url ? $redirect_url : home_url();
    }

    /**
     * Determine if the current user is allowed to access the dashboard,
     * and lock it up if not. Runs on `init`.
     */
    public function maybe_restrict_access() {
        if (!$this->is_enabled()) {
            return;
        }

        if (defined('DOING_AJAX') && DOING_AJAX) {
            $default_strict = get_option('wpzoom_user_history_dashboard_lock_ajax', '0') === '1';

            /**
             * Filter whether the dashboard lock applies to AJAX requests.
             *
             * By default the plugin exempts `/wp-admin/admin-ajax.php` so individual
             * AJAX handlers can do their own capability checks (this matches WP's
             * broader convention). The "AJAX Requests" setting on the Dashboard
             * Access tab seeds the default; the filter has the final word.
             *
             * @param bool   $strict     Whether to enforce the capability check on AJAX requests.
             * @param string $capability The configured access capability.
             */
            $strict_ajax = apply_filters('wpzoom_user_history_strict_ajax', $default_strict, $this->get_capability());

            if (!$strict_ajax) {
                return;
            }
        }

        if ($this->is_allowed_page()) {
            return;
        }

        if (current_user_can($this->get_capability())) {
            return;
        }

        $this->lock_it_up();
    }

    /**
     * "Lock it up" hooks for disallowed users.
     *
     * dashboard_redirect - Redirects disallowed users out of wp-admin.
     * hide_menus         - Hides the admin menus (except profile).
     * hide_toolbar_items - Hides various Toolbar items on front and back-end.
     */
    private function lock_it_up() {
        add_action('admin_init', [$this, 'dashboard_redirect']);
        add_action('admin_head', [$this, 'hide_menus']);
        add_action('admin_bar_menu', [$this, 'hide_toolbar_items'], 999);
    }

    /**
     * Hide admin menus other than profile.php.
     */
    public function hide_menus() {
        /** @global array $menu */
        global $menu;

        if (!$menu || !is_array($menu)) {
            return;
        }

        foreach ($menu as $index => $values) {
            if (isset($values[2]) && $values[2] !== 'profile.php') {
                remove_menu_page($values[2]);
            }
        }
    }

    /**
     * Redirect disallowed users to the configured URL.
     */
    public function dashboard_redirect() {
        /** @global string $pagenow */
        global $pagenow;

        if ($this->is_allowed_page()) {
            return;
        }

        if (($pagenow && $pagenow !== 'profile.php') || (defined('IS_PROFILE_PAGE') && !IS_PROFILE_PAGE) || !$this->is_profile_access_enabled()) {
            $redirect_url = $this->get_redirect_url();

            // Add the configured redirect host to the safe-redirect allowlist so
            // wp_safe_redirect() honors admin-configured external destinations.
            // Hosts other than this one still fall back to home_url() per WP core.
            $redirect_host = wp_parse_url($redirect_url, PHP_URL_HOST);
            if ($redirect_host) {
                $filter = static function ($hosts) use ($redirect_host) {
                    $hosts[] = $redirect_host;
                    return $hosts;
                };
                add_filter('allowed_redirect_hosts', $filter);
                wp_safe_redirect($redirect_url);
                remove_filter('allowed_redirect_hosts', $filter);
            } else {
                wp_safe_redirect($redirect_url);
            }
            exit;
        }
    }

    /**
     * Returns the allowlist of admin pages exempt from the restriction.
     *
     * @return array Allowlist keyed by $pagenow; each value is a list of allowed $_GET param sets.
     */
    private function get_allowlist() {
        $allowlist = [
            'admin.php' => [
                [
                    'page' => 'WFLS', // Wordfence Login Security 2FA
                ],
            ],
            'admin-post.php' => [],
        ];

        // Merge admin-configured URL allowlist entries. Each line of the
        // textarea becomes a pagenow-keyed entry alongside the static
        // defaults, so the same matcher handles both sources uniformly.
        $user_entries = $this->parse_url_allowlist(
            (string) get_option('wpzoom_user_history_dashboard_url_allowlist', '')
        );

        foreach ($user_entries as $entry) {
            $pagenow = $entry['pagenow'];
            if (!isset($allowlist[ $pagenow ])) {
                $allowlist[ $pagenow ] = [];
            }

            if (empty($entry['params'])) {
                // Path-only entry → broadest possible "allow this page" semantics.
                // An empty list is read by is_allowed_page() as "no GET constraint."
                $allowlist[ $pagenow ] = [];
            } else {
                $allowlist[ $pagenow ][] = $entry['params'];
            }
        }

        /**
         * Filter the allowlist of admin pages.
         *
         * Structure: associative array keyed by `$pagenow`. The value is a list of
         * allowed `$_GET` param sets. A request matches when its `$_GET` is a
         * superset of any one set in the list (so additional params like 2FA OTP
         * codes do not break the allowlist). An empty array means "this page is
         * allowed regardless of GET params."
         *
         * Example — allow Wordfence 2FA (`admin.php?page=WFLS`, plus any extra
         * params like `&wfls-action=otp&otp=…`):
         *
         *  [
         *     'admin.php' => [
         *         [ 'page' => 'WFLS' ],
         *     ],
         *  ]
         *
         * @param array $allowlist The allowlist of admin pages.
         */
        return apply_filters('wpzoom_user_history_dashboard_allowlist', $allowlist);
    }

    /**
     * Parse the admin-configured URL allowlist textarea into {pagenow, params} entries.
     *
     * Each line is treated as a relative path (the settings sanitizer normalizes
     * absolute URLs to relative before storage, so we should always see paths
     * here — but we defensively re-parse to be robust against direct DB edits).
     *
     * @param string $raw Newline-separated allowlist string.
     * @return array List of [ 'pagenow' => string, 'params' => array ] entries.
     */
    private function parse_url_allowlist($raw) {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $entries = [];
        $lines   = preg_split('/\R/', $raw);

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $path  = wp_parse_url($line, PHP_URL_PATH);
            $query = wp_parse_url($line, PHP_URL_QUERY);

            if (empty($path)) {
                continue;
            }

            $pagenow = $this->path_to_pagenow($path);
            if ($pagenow === '') {
                continue;
            }

            $params = [];
            if (!empty($query)) {
                wp_parse_str($query, $params);
            }

            $entries[] = [
                'pagenow' => $pagenow,
                'params'  => $params,
            ];
        }

        return $entries;
    }

    /**
     * Compute the `$pagenow` that WP core would assign to a request whose
     * REQUEST_URI starts with the given path.
     *
     * Mirrors the regex + transformations in `wp-includes/vars.php` — keeping
     * this in lockstep with WP is important: a mismatch means the allowlist
     * entry never actually matches the live request.
     *
     * @param string $path Path component (e.g. `/wp-admin/users.php`).
     * @return string `$pagenow`-compatible string (e.g. `users.php`), or '' if path was invalid.
     */
    private function path_to_pagenow($path) {
        if (!is_string($path) || $path === '') {
            return '';
        }

        $path = preg_replace('#^/wp-admin/(network/|user/)?#i', '', $path);
        $path = trim($path, '/');

        if ($path === '' || $path === 'index' || $path === 'index.php') {
            return 'index.php';
        }

        // Take only the first segment.
        if (preg_match('#(.*?)(/|$)#', $path, $matches)) {
            $pagenow = strtolower($matches[1]);
        } else {
            $pagenow = strtolower($path);
        }

        if (substr($pagenow, -4) !== '.php') {
            $pagenow .= '.php';
        }

        return $pagenow;
    }

    /**
     * Checks if the current page is in the allowlist.
     *
     * @return bool
     */
    private function is_allowed_page() {
        global $pagenow;

        if (empty($pagenow)) {
            return false;
        }

        $allowlist = $this->get_allowlist();

        if (!array_key_exists($pagenow, $allowlist)) {
            return false;
        }

        // Empty page entry → page allowed with no GET param constraints.
        if (empty($allowlist[ $pagenow ])) {
            return $this->is_target_page_registered($pagenow);
        }

        // Iterate over each set of allowed GET parameters for the current page.
        foreach ($allowlist[ $pagenow ] as $allowed_params_set) {
            if (!$this->is_params_set_allowed($allowed_params_set)) {
                continue;
            }

            // For `admin.php?page=<slug>` allowlist entries, confirm the target
            // submenu page is actually registered in WP. Without this, the admin
            // chrome renders for any allowlist key even when the plugin that
            // supplies that page (e.g. Wordfence) isn't active.
            if (!$this->is_target_page_registered($pagenow)) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * Verify that the requested admin page is actually registered with WordPress.
     *
     * For `admin.php?page=<slug>` requests, confirms `<slug>` has been registered
     * via `add_menu_page()` / `add_submenu_page()`. Other pagenows are WP-core
     * scripts and are trusted.
     *
     * @param string $pagenow Current admin page.
     * @return bool True if the page is a registered admin surface.
     */
    private function is_target_page_registered($pagenow) {
        if ($pagenow !== 'admin.php') {
            return true;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing check, no state change
        if (empty($_GET['page'])) {
            return false;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Compared against registered page hooks only
        $page_slug = wp_unslash($_GET['page']);
        if (!is_string($page_slug)) {
            return false;
        }

        // get_plugin_page_hook() lives in wp-admin/includes/plugin.php, which is
        // NOT auto-loaded on the `init` hook. Lazy-load so the check works
        // whichever hook calls it.
        if (!function_exists('get_plugin_page_hook')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return get_plugin_page_hook($page_slug, $pagenow) !== null;
    }

    /**
     * Checks if a set of allowed parameters matches the current $_GET parameters.
     *
     * Subset match: every key/value in the allowed set must appear in $_GET.
     * Additional params are permitted so legitimate sub-flows of an allowed
     * page (e.g. WFLS 2FA's `&wfls-action=otp&otp=…`) still match.
     *
     * @param array $allowed_params_set A set of allowed GET parameters.
     * @return bool
     */
    private function is_params_set_allowed($allowed_params_set) {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only routing check, no state change
        if (!is_array($_GET) || !is_array($allowed_params_set)) {
            return false;
        }

        foreach ($allowed_params_set as $param_key => $param_value) {
            if (!isset($_GET[ $param_key ])) {
                return false;
            }

            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Compared against the stored allowlist value only
            $actual = wp_unslash($_GET[ $param_key ]);
            // phpcs:enable WordPress.Security.NonceVerification.Recommended

            // Reject mismatched container types up front — an array submission
            // can never satisfy a scalar allowlist value (or vice versa).
            if (!is_string($actual) || !is_string($param_value)) {
                return false;
            }

            // Wildcard glob: when the allowlist value contains `*`, match the
            // request value via a simple `*`-only glob. Intentionally NOT
            // fnmatch — fnmatch adds `?` and `[…]` metacharacters that widen
            // the allow surface in non-obvious ways.
            if (strpos($param_value, '*') !== false) {
                if (!$this->wildcard_match($param_value, $actual)) {
                    return false;
                }
                continue;
            }

            if ($actual !== $param_value) {
                return false;
            }
        }

        return true;
    }

    /**
     * Glob-match a single `*`-only pattern against a string.
     *
     * Only `*` is treated as a wildcard (any number of characters, including
     * zero). Every other character — including regex metacharacters — matches
     * literally. Linear no-regex walk, so admin-supplied patterns can't ReDoS.
     *
     * @param string $pattern Allowlist value possibly containing `*`.
     * @param string $subject Actual request value to test.
     * @return bool True if the subject matches the pattern.
     */
    private function wildcard_match($pattern, $subject) {
        // Fast path: no wildcard → exact compare.
        if (strpos($pattern, '*') === false) {
            return $pattern === $subject;
        }

        $segments = explode('*', $pattern);
        $count    = count($segments);

        // First segment must be a prefix of the subject.
        $first = $segments[0];
        if ($first !== '' && strpos($subject, $first) !== 0) {
            return false;
        }
        $offset = strlen($first);

        // Middle segments must appear in order, each after the previous.
        for ($i = 1; $i < $count - 1; $i++) {
            $segment = $segments[ $i ];
            if ($segment === '') {
                // Consecutive `*`s in the pattern — no constraint here.
                continue;
            }

            $pos = strpos($subject, $segment, $offset);
            if ($pos === false) {
                return false;
            }
            $offset = $pos + strlen($segment);
        }

        // Last segment must be a suffix.
        $last = $segments[ $count - 1 ];
        if ($last === '') {
            return true;
        }

        $last_len = strlen($last);
        if (strlen($subject) < $offset + $last_len) {
            return false;
        }

        return substr($subject, -$last_len) === $last;
    }

    /**
     * Hide Toolbar items for disallowed users (front and back-end).
     *
     * @param WP_Admin_Bar $wp_admin_bar Admin bar instance.
     */
    public function hide_toolbar_items($wp_admin_bar) {
        $edit_profile = !$this->is_profile_access_enabled() ? 'edit-profile' : '';

        if (is_admin()) {
            $ids = ['about', 'comments', 'new-content', $edit_profile];

            /**
             * Filter the Toolbar node IDs removed for disallowed users in the admin.
             *
             * @param array $ids Toolbar node IDs.
             */
            $nodes = apply_filters('wpzoom_user_history_toolbar_nodes', $ids);
        } else {
            $ids = ['about', 'dashboard', 'comments', 'new-content', 'edit', $edit_profile];

            /**
             * Filter the Toolbar node IDs removed for disallowed users on the front-end.
             *
             * @param array $ids Toolbar node IDs.
             */
            $nodes = apply_filters('wpzoom_user_history_frontend_toolbar_nodes', $ids);
        }

        foreach ($nodes as $id) {
            $wp_admin_bar->remove_menu($id);
        }
    }

    /**
     * Output the configured message above the login form.
     *
     * @param string $message Login message HTML.
     * @return string
     */
    public function output_login_message($message) {
        if (!$this->is_enabled()) {
            return $message;
        }

        $login_message = get_option('wpzoom_user_history_dashboard_login_message', '');

        if (!empty($login_message)) {
            $message .= '<p class="message">' . esc_html($login_message) . '</p>';
        }

        return $message;
    }
}
