<?php
/*
Plugin Name: Enhanced WordPress Security
Description: Comprehensive WordPress security plugin with SVG upload support, REST API restrictions, security headers, and Cloudflare Turnstile.
Version: 1.1.0
Author: Your Name
License: GPL-2.0+
*/

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WPS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WPS_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include required files
require_once WPS_PLUGIN_DIR . 'includes/class-wps-security.php';
require_once WPS_PLUGIN_DIR . 'includes/class-wps-admin.php';

// Initialize the plugin
function wps_security_init() {
    $security = new WPS_Security();
    $security->init();
    
    if (is_admin()) {
        $admin = new WPS_Admin();
        $admin->init();
    }
}
add_action('plugins_loaded', 'wps_security_init');

// Register activation hook
register_activation_hook(__FILE__, function() {
    // Set default options
    $default_options = array(
        'enable_svg_upload' => true,
        'enable_rest_api_restrictions' => true,
        'enable_security_headers' => true,
        'enable_query_blocking' => true,
        'enable_comment_filtering' => true,
        'enable_author_protection' => true,
        'enable_xmlrpc_disable' => true,
        'enable_file_edit_disable' => true,
        'enable_sensitive_file_protection' => true,
        'enable_turnstile_login' => false,
		'enable_turnstile_register' => false,
        'enable_turnstile_lostpassword' => false,
        'enable_turnstile_comments' => false,
        'enable_file_integrity' => true,
        'enable_email_notifications' => true,
        'enable_upload_restrictions' => true,
        'notification_email' => '',
        'allowed_upload_types' => array('jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx'),
        'turnstile_site_key' => '',
        'turnstile_secret_key' => '',
        'custom_headers' => array(
            'X-Content-Type-Options: nosniff',
            'X-Frame-Options: SAMEORIGIN',
            'X-XSS-Protection: 1; mode=block',
            'Referrer-Policy: strict-origin-when-cross-origin',
            'Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(), usb=(), magnetometer=(), gyroscope=()',
            'Strict-Transport-Security: max-age=31536000; includeSubDomains; preload'
        )
    );
    
    if (!get_option('wps_security_options')) {
        update_option('wps_security_options', $default_options);
    }
});
?>
