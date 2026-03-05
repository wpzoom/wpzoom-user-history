# WPZOOM User History

A WordPress plugin that tracks changes made to user accounts, monitors login activity, manages sessions, and allows admins to lock/unlock users and change usernames.

<img width="1547" height="1071" alt="image" src="https://github.com/user-attachments/assets/1ba77413-4e63-416b-8ca4-242e499acadb" />


## Features

### Profile Change Tracking
- **Track Profile Changes** - Automatically logs changes to username, email, display name, first/last name, nickname, website, bio, and role
- **Password Change Logging** - Records when passwords are changed (without storing any password data)
- **IP Address Tracking** - Records IP addresses for each change (can be disabled for GDPR compliance)
- **See Who Made Changes** - Each log entry shows whether the user changed their own profile or if an admin made the change
- **Search by Previous Values** - Find users on the All Users page by their old email or username

### Login & Session Monitoring
- **Login/Logout Tracking** - Records successful logins, logouts, and failed login attempts with date, IP address, and browser info
- **Failed Login Attempts** - Track failed login attempts for existing user accounts
- **Active Sessions** - View all active WordPress sessions for any user, including login time, IP address, browser, and expiry
- **Log Out Everywhere** - Destroy all active sessions for a user with one click
- **Browser & OS Detection** - Automatically detects and displays the browser and operating system from the user agent

### Lock/Unlock User Accounts
- **Lock User Accounts** - Prevent users from logging in by locking their account
- **Instant Session Termination** - Locked users are logged out immediately and all active sessions are destroyed
- **Application Password Blocking** - Locked users cannot authenticate via application passwords (REST API, XML-RPC)
- **Status Column** - See which users are locked at a glance with a status column on the All Users page
- **Bulk Lock/Unlock** - Lock or unlock multiple users at once from the All Users page
- **Row Actions** - Quickly lock or unlock individual users from the All Users list
- **Locked Users Filter** - Filter the All Users list to show only locked accounts
- **Custom Lock Message** - Set a custom message shown to locked users on the login screen
- **WP-CLI Access** - Locked users can still be managed via WP-CLI

### Admin Tools
- **Change Username** - Allows admins to change usernames directly from the user edit page (WordPress normally doesn't allow this)
- **Delete User Button** - Quick access button to delete a user directly from their profile page
- **Clear History** - Admins can clear the history log for any user
- **Clear All Logs** - Bulk delete all history logs for every user from the settings page
- **Data Retention** - Automatically delete old logs after a configurable number of days (default: 30 days)

### Privacy & Compliance
- **IP Tracking Toggle** - Enable or disable IP address recording for GDPR compliance
- **Configurable Retention** - Set how long logs are kept (1-365+ days, or keep forever)
- **Automatic Cleanup** - Daily cron job removes logs older than the configured retention period

### Compatibility
- **Multisite Compatible** - Works with WordPress multisite installations
- **Members Plugin Compatible** - Works with the Members plugin for multiple role assignments
- **Migration from Lock User Account plugin** - Automatically migrates locked users from the Lock User Account plugin

## Requirements

- WordPress 6.5+
- PHP 7.4+

## Installation

1. Download the plugin and upload to `/wp-content/plugins/wpzoom-user-history/`
2. Activate through the 'Plugins' menu in WordPress
3. Visit any user's edit page to see their Account History section
4. Configure settings at **Settings > User History**

## Usage

### Viewing History

Go to **Users > All Users**, click on any user to edit their profile, and scroll down to the "Account History" section. Use the tabs to switch between **Changes**, **Logins**, and **Sessions**.

### Changing a Username

On the user edit page, click the "Change" link next to the username field. Enter the new username and click "Change" to save.

### Locking a User Account

Lock a user from the user edit page (Account Status section), via row actions on the All Users page, or using bulk actions. Locked users are logged out immediately.

### Viewing Active Sessions

On the user edit page, click the "Sessions" tab in Account History. Click "Log Out Everywhere" to destroy all active sessions.

### Searching by Previous Values

On the All Users page, use the search box to search for any previous email, username, or name. Users who previously had matching values will appear in the results.

### Configuring Settings

Go to **Settings > User History** to configure:
- Custom locked account message
- IP address tracking (enable/disable)
- Data retention period (default: 30 days)
- Clear all logs

## Tracked Fields

| Field | Description |
|-------|-------------|
| Username | user_login |
| Email | user_email |
| Password | Change event only (no values stored) |
| Display Name | display_name |
| Nicename | user_nicename |
| Website URL | user_url |
| First Name | first_name meta |
| Last Name | last_name meta |
| Nickname | nickname meta |
| Biographical Info | description meta |
| Role | Capabilities (supports multiple roles) |

## Hooks

### Actions

```php
// Fires after a username has been changed
do_action('wpzoom_user_history_username_changed', $user_id, $old_username, $new_username);
```

## Changelog

### 1.2.0
- Added login/logout tracking with dedicated "Logins" tab
- Added "Sessions" tab showing all active WordPress sessions
- Added "Log Out Everywhere" button to destroy all active sessions
- Added IP address tracking for all changes and login events
- Added IP tracking toggle in Settings for GDPR compliance
- Added configurable data retention setting (default: 30 days) with automatic daily cleanup
- Added "Clear All Logs" button in Settings to delete all history logs at once
- Tabbed interface for Account History (Changes, Logins, Sessions)

### 1.1.1
- Minor fixes

### 1.1.0
- Added Lock User functionality

### 1.0.3
- Added Delete User button on user edit page for quick access
- Added Requires at least and Requires PHP headers to plugin file
- Code improvements for WordPress.org plugin directory compliance

### 1.0.2
- Added Clear Log button to delete history for a user
- Improved role change tracking for Members plugin compatibility
- Fixed false positive password change logging when saving without changes
- Fixed duplicate role change entries

### 1.0.1
- Added search functionality to find users by previous email/username
- Added database index for faster history searches
- Improved password change logging (no values stored)
- Added table existence check to prevent errors

### 1.0.0
- Initial release
- Track user profile changes
- Change username feature
- Account History display on user edit page

## License

GPL v2 or later - https://www.gnu.org/licenses/gpl-2.0.html

## Credits

Developed by [WPZOOM](https://www.wpzoom.com)
