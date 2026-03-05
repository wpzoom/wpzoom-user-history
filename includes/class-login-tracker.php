<?php
/**
 * Login/logout tracking for User History plugin.
 *
 * @package UserHistory
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles tracking of login, logout, and failed login events.
 */
class WPZOOM_User_History_Login_Tracker {

    /**
     * Reference to main plugin instance.
     *
     * @var WPZOOM_User_History
     */
    private $plugin;

    /**
     * Constructor.
     *
     * @param WPZOOM_User_History $plugin Main plugin instance.
     */
    public function __construct($plugin) {
        $this->plugin = $plugin;

        add_action('wp_login', [$this, 'log_login'], 10, 2);
        add_action('wp_logout', [$this, 'log_logout'], 10, 1);
        add_action('wp_login_failed', [$this, 'log_failed_login'], 10, 2);
    }

    /**
     * Log successful login.
     *
     * @param string  $user_login Username.
     * @param WP_User $user       User object.
     */
    public function log_login($user_login, $user) {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Stored as-is for browser detection display
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? wp_unslash($_SERVER['HTTP_USER_AGENT']) : '';

        $this->plugin->log_change(
            $user->ID,
            $user->ID,
            'user_session',
            'Login',
            '',
            $user_agent,
            'login'
        );
    }

    /**
     * Log logout.
     *
     * @param int $user_id User ID.
     */
    public function log_logout($user_id) {
        $this->plugin->log_change(
            $user_id,
            $user_id,
            'user_session',
            'Logout',
            '',
            '',
            'logout'
        );
    }

    /**
     * Log failed login attempt.
     *
     * @param string   $username Username that was attempted.
     * @param WP_Error $error    WP_Error object.
     */
    public function log_failed_login($username, $error = null) {
        // Only log for existing users
        $user = get_user_by('login', $username);
        if (!$user) {
            $user = get_user_by('email', $username);
        }

        if (!$user) {
            return;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Stored as-is for browser detection display
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? wp_unslash($_SERVER['HTTP_USER_AGENT']) : '';

        $this->plugin->log_change(
            $user->ID,
            0,
            'user_session',
            'Failed Login',
            $username,
            $user_agent,
            'login_failed'
        );
    }
}
