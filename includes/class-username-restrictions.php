<?php
/**
 * Username restrictions for User History plugin.
 *
 * Ported from the "Restrict Usernames" plugin (restrict-usernames) and
 * adapted to this plugin's prefix conventions. Settings UI lives in
 * WPZOOM_User_History_Settings (Username Restrictions tab); this class only
 * enforces and powers the username test tool.
 *
 * @package UserHistory
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Restricts the usernames that can be chosen when registering.
 *
 * Rules: disallow spaces, min/max length, exact blocklist, partial (substring)
 * blocklist, and required substrings (with optional `^` start/end anchors).
 * Hooks into `validate_username` (used by wp-login.php registration, wp-admin
 * Add New User, WooCommerce and most registration plugins),
 * `illegal_user_logins` (used by wp_insert_user()), Multisite signup
 * validation and BuddyPress signup validation.
 */
class WPZOOM_User_History_Username_Restrictions {

    /**
     * Whether the last `validate_username` call rejected the username because
     * of one of this plugin's rules (as opposed to WordPress's own checks).
     *
     * @var bool
     */
    private $got_restricted = false;

    /**
     * Constructor — registers hooks.
     */
    public function __construct() {
        add_filter('validate_username', [$this, 'filter_validate_username'], 10, 2);
        add_filter('illegal_user_logins', [$this, 'filter_illegal_user_logins']);
        add_filter('registration_errors', [$this, 'filter_registration_errors']);
        add_action('user_profile_update_errors', [$this, 'filter_profile_update_errors'], 10, 2);
        add_filter('wpmu_validate_user_signup', [$this, 'filter_signup_result']);
        add_filter('bp_core_validate_user_signup', [$this, 'filter_signup_result']);
    }

    /**
     * Whether the username restrictions feature is enabled.
     *
     * @return bool
     */
    public function is_enabled() {
        return get_option('wpzoom_user_history_username_restrictions_enabled', '0') === '1';
    }

    /**
     * Whether the last validate_username() call was rejected by this plugin.
     *
     * @return bool
     */
    public function was_restricted() {
        return $this->got_restricted;
    }

    /**
     * Current settings as an array.
     *
     * @return array {
     *     @type bool     $disallow_spaces   Reject usernames containing spaces.
     *     @type int      $min_length        Minimum length (0 = no limit).
     *     @type int      $max_length        Maximum length (0 = no limit).
     *     @type string[] $blocklist         Exact usernames that cannot be used (lowercase).
     *     @type string[] $partial_blocklist Substrings that cannot appear anywhere in a username (lowercase).
     *     @type string[] $required_partials Substrings one of which must appear; `^` prefix/suffix anchors it.
     *     @type bool     $apply_to_admins   Also apply to users who can create users (wp-admin, username changes).
     * }
     */
    public function get_settings() {
        return [
            'disallow_spaces'   => get_option('wpzoom_user_history_username_disallow_spaces', '0') === '1',
            'min_length'        => (int) get_option('wpzoom_user_history_username_min_length', 0),
            'max_length'        => (int) get_option('wpzoom_user_history_username_max_length', 0),
            'blocklist'         => self::parse_list(get_option('wpzoom_user_history_username_blocklist', '')),
            'partial_blocklist' => self::parse_list(get_option('wpzoom_user_history_username_partial_blocklist', '')),
            'required_partials' => self::parse_list(get_option('wpzoom_user_history_username_required_partials', '')),
            'apply_to_admins'   => get_option('wpzoom_user_history_username_apply_to_admins', '0') === '1',
        ];
    }

    /**
     * Split a newline-separated option value into a clean lowercase array.
     *
     * @param mixed $value Stored option value.
     * @return string[]
     */
    public static function parse_list($value) {
        if (!is_string($value) || $value === '') {
            return [];
        }

        $items = [];
        foreach (preg_split('/\R/', $value) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $items[] = mb_strtolower($line);
            }
        }

        return array_values(array_unique($items));
    }

    /**
     * Error message shown when a username is rejected.
     *
     * @return string
     */
    public function get_error_message() {
        $message = get_option('wpzoom_user_history_username_error_message', '');
        if ($message === '') {
            $message = __('This username is not allowed. Please choose another.', 'wpzoom-user-history');
        }

        /**
         * Filter the error message shown for restricted usernames.
         *
         * @param string $message Error message (plain text).
         */
        return apply_filters('wpzoom_user_history_username_restricted_message', $message);
    }

    /**
     * Whether checks should be skipped in the current request context.
     *
     * Restrictions are meant for self-registration. Unless "apply to
     * administrators" is enabled, users who can create accounts (admins in
     * wp-admin, WP-CLI) bypass them.
     *
     * @return bool
     */
    private function should_skip() {
        if (!$this->is_enabled()) {
            return true;
        }

        if (defined('WP_CLI') && WP_CLI) {
            return true;
        }

        $settings = $this->get_settings();
        if (!$settings['apply_to_admins'] && is_user_logged_in() && current_user_can('create_users')) {
            return true;
        }

        return false;
    }

    /**
     * Evaluate a username against the configured rules.
     *
     * Pure check — ignores request context (enabled flag, admin bypass), so
     * the settings page test tool can call it directly.
     *
     * @param string $username Username to check.
     * @return string|null Human-readable reason the username is restricted, or null if it passes.
     */
    public function get_restriction_reason($username) {
        $settings = $this->get_settings();
        $username = mb_strtolower(trim((string) $username));
        $reason   = null;

        if ($settings['disallow_spaces'] && strpos($username, ' ') !== false) {
            $reason = __('contains spaces', 'wpzoom-user-history');
        } elseif ($settings['min_length'] > 0 && mb_strlen($username) < $settings['min_length']) {
            /* translators: %d: minimum number of characters */
            $reason = sprintf(_n('is shorter than %d character', 'is shorter than %d characters', $settings['min_length'], 'wpzoom-user-history'), $settings['min_length']);
        } elseif ($settings['max_length'] > 0 && mb_strlen($username) > $settings['max_length']) {
            /* translators: %d: maximum number of characters */
            $reason = sprintf(_n('is longer than %d character', 'is longer than %d characters', $settings['max_length'], 'wpzoom-user-history'), $settings['max_length']);
        } elseif (in_array($username, $settings['blocklist'], true)) {
            $reason = __('is on the blocked usernames list', 'wpzoom-user-history');
        } else {
            foreach ($settings['partial_blocklist'] as $partial) {
                if (strpos($username, $partial) !== false) {
                    /* translators: %s: the blocked text fragment */
                    $reason = sprintf(__('contains the blocked text "%s"', 'wpzoom-user-history'), $partial);
                    break;
                }
            }

            if ($reason === null && !empty($settings['required_partials']) && !$this->matches_required_partial($username, $settings['required_partials'])) {
                $reason = __('does not include any of the required text fragments', 'wpzoom-user-history');
            }
        }

        $valid = ($reason === null);

        /**
         * Filter the plugin's assessment of a username.
         *
         * Return false to reject a username the built-in rules would allow, or
         * true to allow one they would reject. Compatible with the
         * `c2c_restrict_usernames-validate` filter of the Restrict Usernames plugin.
         *
         * @param bool   $valid    True if the username passes all rules.
         * @param string $username The username being checked (lowercased).
         * @param array  $settings Current username restriction settings.
         */
        $filtered = (bool) apply_filters('wpzoom_user_history_validate_username', $valid, $username, $settings);

        if ($filtered !== $valid) {
            $reason = $filtered ? null : __('was rejected by a custom rule', 'wpzoom-user-history');
        }

        return $reason;
    }

    /**
     * Whether a username satisfies at least one required partial.
     *
     * A partial prefixed with `^` must be at the start, suffixed with `^` must
     * be at the end; otherwise it may appear anywhere.
     *
     * @param string   $username Lowercased username.
     * @param string[] $partials Required partials.
     * @return bool
     */
    private function matches_required_partial($username, $partials) {
        foreach ($partials as $partial) {
            if ($partial === '' || $partial === '^') {
                continue;
            }

            if ($partial[0] === '^') {
                $needle = substr($partial, 1);
                if ($needle !== '' && strpos($username, $needle) === 0) {
                    return true;
                }
            } elseif (substr($partial, -1) === '^') {
                $needle = substr($partial, 0, -1);
                if ($needle !== '' && substr($username, -strlen($needle)) === $needle) {
                    return true;
                }
            } elseif (strpos($username, $partial) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a username is restricted (context-aware).
     *
     * @param string $username Username to check.
     * @return bool
     */
    public function is_restricted($username) {
        if ($this->should_skip()) {
            return false;
        }

        return $this->get_restriction_reason($username) !== null;
    }

    // =========================================================================
    // Hooks
    // =========================================================================

    /**
     * `validate_username` filter: reject restricted usernames.
     *
     * @param bool   $valid    WordPress's own assessment.
     * @param string $username Username being validated.
     * @return bool
     */
    public function filter_validate_username($valid, $username) {
        $this->got_restricted = false;

        if (!$valid) {
            return $valid;
        }

        if ($this->is_restricted($username)) {
            $this->got_restricted = true;
            return false;
        }

        return $valid;
    }

    /**
     * `illegal_user_logins` filter: add the exact blocklist.
     *
     * wp_insert_user() applies this filter on updates as well as inserts, so
     * names that already belong to an account are left out — otherwise those
     * users could no longer save their profile.
     *
     * @param array $illegal_user_logins Existing list.
     * @return array
     */
    public function filter_illegal_user_logins($illegal_user_logins) {
        if ($this->should_skip()) {
            return $illegal_user_logins;
        }

        $settings = $this->get_settings();
        if (empty($settings['blocklist'])) {
            return $illegal_user_logins;
        }

        foreach ($settings['blocklist'] as $login) {
            if (!username_exists($login)) {
                $illegal_user_logins[] = $login;
            }
        }

        return array_values(array_unique((array) $illegal_user_logins));
    }

    /**
     * `registration_errors` filter (wp-login.php?action=register): replace the
     * generic "illegal characters" error with this plugin's message.
     *
     * @param WP_Error $errors Registration errors.
     * @return WP_Error
     */
    public function filter_registration_errors($errors) {
        if ($this->got_restricted && is_wp_error($errors)) {
            $errors->remove('invalid_username');
            $errors->add(
                'invalid_username',
                '<strong>' . __('Error:', 'wpzoom-user-history') . '</strong> ' . esc_html($this->get_error_message()),
                'invalid_username'
            );
        }

        return $errors;
    }

    /**
     * `user_profile_update_errors` action (wp-admin Add New User): replace the
     * generic "illegal characters" error with this plugin's message.
     *
     * @param WP_Error $errors Errors object.
     * @param bool     $update Whether this is an existing user update.
     */
    public function filter_profile_update_errors($errors, $update) {
        if ($update || !$this->got_restricted || !is_wp_error($errors)) {
            return;
        }

        $errors->remove('user_login');
        $errors->add(
            'user_login',
            '<strong>' . __('Error:', 'wpzoom-user-history') . '</strong> ' . esc_html($this->get_error_message()),
            ['form-field' => 'user_login']
        );
    }

    /**
     * Multisite / BuddyPress signup validation.
     *
     * Neither `wpmu_validate_user_signup()` nor BuddyPress surface a useful
     * error when `validate_username` fails, so the check is repeated here and
     * a `user_name` error added with this plugin's message.
     *
     * @param array $result Signup result with 'user_name' and 'errors' (WP_Error).
     * @return array
     */
    public function filter_signup_result($result) {
        if (!is_array($result) || empty($result['user_name']) || !isset($result['errors']) || !is_wp_error($result['errors'])) {
            return $result;
        }

        if (!$this->is_restricted($result['user_name'])) {
            return $result;
        }

        // Prefer this plugin's message over BuddyPress's generic one, which is
        // triggered by our validate_username filter.
        $result['errors']->remove('user_name');
        $result['errors']->add('user_name', $this->get_error_message());

        return $result;
    }
}
