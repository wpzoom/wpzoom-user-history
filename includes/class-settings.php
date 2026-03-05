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
 */
class WPZOOM_User_History_Settings {

    /**
     * Constructor — registers hooks.
     */
    public function __construct() {
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('wp_ajax_wpzoom_user_history_clear_all', [$this, 'ajax_clear_all_logs']);
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
     * Register settings, sections, and fields.
     */
    public function register_settings() {
        register_setting('wpzoom_user_history_settings', 'wpzoom_user_history_locked_message', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ]);

        register_setting('wpzoom_user_history_settings', 'wpzoom_user_history_track_ip', [
            'type'              => 'string',
            'sanitize_callback' => [$this, 'sanitize_checkbox'],
            'default'           => '1',
        ]);

        register_setting('wpzoom_user_history_settings', 'wpzoom_user_history_retention_days', [
            'type'              => 'integer',
            'sanitize_callback' => [$this, 'sanitize_retention_days'],
            'default'           => 30,
        ]);

        add_settings_section(
            'wpzoom_user_history_lock_section',
            __('Lock Account', 'wpzoom-user-history'),
            [$this, 'render_lock_section_description'],
            'wpzoom-user-history'
        );

        add_settings_field(
            'wpzoom_user_history_locked_message',
            __('Locked Account Message', 'wpzoom-user-history'),
            [$this, 'render_locked_message_field'],
            'wpzoom-user-history',
            'wpzoom_user_history_lock_section'
        );

        add_settings_section(
            'wpzoom_user_history_privacy_section',
            __('Privacy', 'wpzoom-user-history'),
            [$this, 'render_privacy_section_description'],
            'wpzoom-user-history'
        );

        add_settings_field(
            'wpzoom_user_history_track_ip',
            __('IP Address Tracking', 'wpzoom-user-history'),
            [$this, 'render_track_ip_field'],
            'wpzoom-user-history',
            'wpzoom_user_history_privacy_section'
        );

        add_settings_section(
            'wpzoom_user_history_retention_section',
            __('Data Retention', 'wpzoom-user-history'),
            [$this, 'render_retention_section_description'],
            'wpzoom-user-history'
        );

        add_settings_field(
            'wpzoom_user_history_retention_days',
            __('Keep Logs For', 'wpzoom-user-history'),
            [$this, 'render_retention_days_field'],
            'wpzoom-user-history',
            'wpzoom_user_history_retention_section'
        );
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

    /**
     * Render the settings page.
     */
    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('User History Settings', 'wpzoom-user-history'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('wpzoom_user_history_settings');
                do_settings_sections('wpzoom-user-history');
                submit_button();
                ?>
            </form>

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
        </div>
        <?php
    }
}
