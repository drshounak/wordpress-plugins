<?php
/**
 * Plugin Name: Email 2FA by TechWeirdo
 * Plugin URI: https://github.com/drshounak/wordpress-plugins/tree/main/email_otp_2fa
 * Description: Adds two-factor authentication using email OTP codes for WordPress login security.
 * Version: 1.0.0
 * Author: TechWeirdo
 * Author URI: https://twitter.com/drshounakpal
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: email-otp-login
 * Domain Path: /languages
 * 
 * @package TechWeirdo
 * @author Dr. Shounak Pal
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main Plugin Class
 */
class EmailOTPLogin {
    
    /**
     * Plugin version
     */
    const VERSION = '1.0.0';
    
    /**
     * Plugin instance
     */
    private static $instance = null;
    
    /**
     * Get plugin instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Plugin activation/deactivation
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // Core functionality hooks
        add_action('login_init', array($this, 'custom_login_handler'));
        add_action('login_init', array($this, 'add_otp_verification_form'));
        add_action('login_init', array($this, 'handle_resend_otp'));
        add_action('login_errors', array($this, 'display_otp_error_messages'));
        
        // User profile hooks
        add_action('show_user_profile', array($this, 'otp_settings_fields'));
        add_action('edit_user_profile', array($this, 'otp_settings_fields'));
        add_action('personal_options_update', array($this, 'save_otp_settings'));
        add_action('edit_user_profile_update', array($this, 'save_otp_settings'));
        
        // Cleanup hook
        add_action('wp_scheduled_delete', array($this, 'cleanup_expired_otp_codes'));
        
        // Admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Load text domain
        add_action('plugins_loaded', array($this, 'load_textdomain'));
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Create options with default values
        add_option('email_otp_login_enabled', '1');
        add_option('email_otp_login_expiry', '10'); // minutes
        add_option('email_otp_login_rate_limit', '60'); // seconds
        
        // Schedule cleanup event if not already scheduled
        if (!wp_next_scheduled('email_otp_cleanup_event')) {
            wp_schedule_event(time(), 'daily', 'email_otp_cleanup_event');
        }
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clear scheduled events
        wp_clear_scheduled_hook('email_otp_cleanup_event');
    }
    
    /**
     * Load text domain for translations
     */
    public function load_textdomain() {
        load_plugin_textdomain('email-otp-login', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }
    
    /**
     * Generate a 6-digit OTP code
     */
    private function generate_otp_code() {
        return sprintf("%06d", mt_rand(100000, 999999));
    }
    
    /**
     * Store OTP in user meta with expiration
     */
    private function store_user_otp($user_id, $otp) {
        $expiry_minutes = get_option('email_otp_login_expiry', 10);
        $expiry = time() + ($expiry_minutes * 60);
        update_user_meta($user_id, 'login_otp_code', $otp);
        update_user_meta($user_id, 'login_otp_expiry', $expiry);
    }
    
    /**
     * Send OTP email to user
     */
    private function send_otp_email($user) {
        $otp = $this->generate_otp_code();
        $this->store_user_otp($user->ID, $otp);
        
        $subject = sprintf(__('Your login verification code - %s', 'email-otp-login'), get_bloginfo('name'));
        $message = '
        <html>
        <body style="font-family: Arial, sans-serif; color: #333; line-height: 1.6; max-width: 600px; margin: 0 auto; padding: 20px; background-color: #f9f9f9;">
            <div style="background-color: #ffffff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                <h2 style="color: #3498db; margin-top: 0;">' . __('Login Verification Code', 'email-otp-login') . '</h2>
                <p>' . sprintf(__('Hello %s,', 'email-otp-login'), esc_html($user->display_name)) . '</p>
                <p>' . sprintf(__('Your verification code to log in to %s is:', 'email-otp-login'), get_bloginfo('name')) . '</p>
                <div style="background: #f2f2f2; padding: 15px; font-size: 24px; text-align: center; letter-spacing: 5px; font-weight: bold; margin: 20px 0;">
                    ' . $otp . '
                </div>
                <p>' . sprintf(__('This code will expire in %d minutes.', 'email-otp-login'), get_option('email_otp_login_expiry', 10)) . '</p>
                <p>' . __('If you did not request this code, please ignore this email.', 'email-otp-login') . '</p>
                <hr style="border: 0; border-top: 1px solid #eee;">
                <p style="font-size: 12px; color: #777;">' . sprintf(__('This is an automated email from %s.', 'email-otp-login'), get_bloginfo('name')) . '</p>
            </div>
        </body>
        </html>
        ';
        
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            "From: " . get_bloginfo('name') . " <noreply@" . parse_url(home_url(), PHP_URL_HOST) . ">",
        );
        
        wp_mail($user->user_email, $subject, $message, $headers);
        return $otp;
    }
    
    /**
     * Start session only when needed and close it properly
     */
    private function start_otp_session() {
        if (!session_id()) {
            session_start();
        }
    }
    
    /**
     * Close session
     */
    private function close_otp_session() {
        if (session_id()) {
            session_write_close();
        }
    }
    
    /**
     * Check if OTP is disabled for user
     */
    private function is_otp_disabled_for_user($user_id) {
        return get_user_meta($user_id, 'disable_login_otp', true) === '1';
    }
    
    /**
     * Custom login handler
     */
    public function custom_login_handler() {
        // Skip if plugin is disabled
        if (get_option('email_otp_login_enabled', '1') !== '1') {
            return;
        }
        
        // Skip this for OTP verification step
        if (isset($_POST['otp_verification'])) {
            return;
        }
        
        // Skip this for logout and other WP actions
        if ((isset($_GET['action']) && $_GET['action'] != 'login' && $_GET['action'] != '') || isset($_GET['loggedout'])) {
            return;
        }
        
        // Skip if not a POST login request
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['log']) || !isset($_POST['pwd'])) {
            return;
        }
        
        // Validate username/password first
        $username = $_POST['log'];
        $password = $_POST['pwd'];
        $remember = isset($_POST['rememberme']) ? true : false;
        
        $user = wp_authenticate($username, $password);
        
        if (is_wp_error($user)) {
            // Regular WordPress authentication failed, let WP handle the error
            return;
        }
        
        // Check if OTP is disabled for this user
        if ($this->is_otp_disabled_for_user($user->ID)) {
            return; // Let WordPress handle normal login
        }
        
        // Start session only when needed
        $this->start_otp_session();
        
        // Set up session variables
        $_SESSION['otp_user_id'] = $user->ID;
        $_SESSION['otp_remember'] = $remember;
        
        // Close session before making HTTP requests
        $this->close_otp_session();
        
        // Send OTP to user email
        $this->send_otp_email($user);
        
        // Redirect to OTP verification page
        wp_redirect(add_query_arg('otp_verification', '1', wp_login_url()));
        exit;
    }
    
    /**
     * Add OTP verification form
     */
    public function add_otp_verification_form() {
        // Only show OTP form if we have a verification request
        if (!isset($_GET['otp_verification']) || $_GET['otp_verification'] != '1') {
            return;
        }
        
        // Start session if not started
        $this->start_otp_session();
        
        // Make sure we have user ID
        if (!isset($_SESSION['otp_user_id'])) {
            $this->close_otp_session();
            wp_redirect(wp_login_url());
            exit;
        }
        
        // Get user information
        $user = get_user_by('ID', $_SESSION['otp_user_id']);
        if (!$user) {
            $this->close_otp_session();
            wp_redirect(wp_login_url());
            exit;
        }
        
        // Handle OTP verification submission
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['otp_code'])) {
            $entered_otp = preg_replace('/[^0-9]/', '', $_POST['otp_code']);
            $stored_otp = get_user_meta($user->ID, 'login_otp_code', true);
            $expiry = (int) get_user_meta($user->ID, 'login_otp_expiry', true);
            
            // Check if OTP is valid and not expired
            if ($entered_otp === $stored_otp && time() < $expiry) {
                // Clear OTP data
                delete_user_meta($user->ID, 'login_otp_code');
                delete_user_meta($user->ID, 'login_otp_expiry');
                
                // Log user in
                wp_set_auth_cookie($user->ID, $_SESSION['otp_remember']);
                
                // Clean up session
                unset($_SESSION['otp_user_id']);
                unset($_SESSION['otp_remember']);
                $this->close_otp_session();
                
                // Redirect to admin dashboard or requested redirect
                $redirect_to = isset($_REQUEST['redirect_to']) ? $_REQUEST['redirect_to'] : admin_url();
                wp_safe_redirect($redirect_to);
                exit;
            } else {
                $error_message = time() >= $expiry ? 
                    __('OTP has expired. Please try again.', 'email-otp-login') : 
                    __('Invalid verification code. Please try again.', 'email-otp-login');
            }
        }
        
        // Close session before rendering HTML
        $this->close_otp_session();
        
        // Custom OTP verification form
        $this->render_otp_form($user, isset($error_message) ? $error_message : null);
        exit;
    }
    
    /**
     * Render OTP verification form
     */
    private function render_otp_form($user, $error_message = null) {
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php printf(__('OTP Verification - %s', 'email-otp-login'), get_bloginfo('name')); ?></title>
            <?php wp_head(); ?>
            <style>
                body {
                    background: #f1f1f1;
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
                }
                .otp-container {
                    max-width: 350px;
                    margin: 100px auto 0;
                    padding: 40px;
                    background: #fff;
                    border-radius: 5px;
                    box-shadow: 0 1px 3px rgba(0,0,0,.13);
                }
                .otp-container h1 {
                    margin-top: 0;
                    margin-bottom: 20px;
                    font-size: 24px;
                    text-align: center;
                }
                .otp-container p {
                    margin-bottom: 20px;
                    font-size: 14px;
                }
                .otp-field {
                    width: 100%;
                    padding: 12px;
                    font-size: 24px;
                    text-align: center;
                    letter-spacing: 10px;
                    border: 1px solid #ddd;
                    border-radius: 4px;
                    box-sizing: border-box;
                    margin-bottom: 20px;
                }
                .otp-submit {
                    width: 100%;
                    padding: 12px;
                    background: #2271b1;
                    color: #fff;
                    border: none;
                    border-radius: 4px;
                    cursor: pointer;
                    font-size: 15px;
                    font-weight: 500;
                }
                .otp-submit:hover {
                    background: #135e96;
                }
                .resend-link {
                    text-align: center;
                    margin-top: 20px;
                }
                .resend-link a {
                    color: #2271b1;
                    text-decoration: none;
                }
                .error-message {
                    background: #f8d7da;
                    color: #721c24;
                    padding: 10px;
                    border-radius: 4px;
                    margin-bottom: 20px;
                }
            </style>
        </head>
        <body>
            <div class="otp-container">
                <h1><?php _e('Verification Required', 'email-otp-login'); ?></h1>
                <p><?php printf(__('We\'ve sent a verification code to <strong>%s</strong>. Please enter it below to continue.', 'email-otp-login'), esc_html(substr_replace($user->user_email, '***', 3, strpos($user->user_email, '@') - 5))); ?></p>
                
                <?php if ($error_message): ?>
                    <div class="error-message"><?php echo esc_html($error_message); ?></div>
                <?php endif; ?>
                
                <form method="post" action="<?php echo esc_url(add_query_arg('otp_verification', '1', wp_login_url())); ?>">
                    <input type="hidden" name="otp_verification" value="1">
                    <input type="text" name="otp_code" class="otp-field" maxlength="6" placeholder="------" required autofocus>
                    <button type="submit" class="otp-submit"><?php _e('Verify', 'email-otp-login'); ?></button>
                    
                    <?php if (isset($_REQUEST['redirect_to'])): ?>
                        <input type="hidden" name="redirect_to" value="<?php echo esc_url($_REQUEST['redirect_to']); ?>">
                    <?php endif; ?>
                </form>
                
                <div class="resend-link">
                    <a href="<?php echo esc_url(add_query_arg('resend_otp', '1', add_query_arg('otp_verification', '1', wp_login_url()))); ?>"><?php _e('Resend verification code', 'email-otp-login'); ?></a>
                </div>
                
                <div class="resend-link">
                    <a href="<?php echo esc_url(wp_login_url()); ?>"><?php _e('Back to login', 'email-otp-login'); ?></a>
                </div>
            </div>
            <?php wp_footer(); ?>
        </body>
        </html>
        <?php
    }
    
    /**
     * Handle resend OTP request
     */
    public function handle_resend_otp() {
        if (!isset($_GET['resend_otp']) || $_GET['resend_otp'] != '1' || !isset($_GET['otp_verification'])) {
            return;
        }
        
        // Start session if not started
        $this->start_otp_session();
        
        // Make sure we have user ID
        if (!isset($_SESSION['otp_user_id'])) {
            $this->close_otp_session();
            wp_redirect(wp_login_url());
            exit;
        }
        
        // Get user information
        $user = get_user_by('ID', $_SESSION['otp_user_id']);
        if (!$user) {
            $this->close_otp_session();
            wp_redirect(wp_login_url());
            exit;
        }
        
        // Rate limit check - prevent OTP spam
        $rate_limit_seconds = get_option('email_otp_login_rate_limit', 60);
        $last_sent = get_user_meta($user->ID, 'login_otp_last_sent', true);
        if ($last_sent && (time() - $last_sent) < $rate_limit_seconds) {
            $this->close_otp_session();
            // Redirect back with error
            wp_redirect(add_query_arg('otp_error', 'rate_limit', add_query_arg('otp_verification', '1', wp_login_url())));
            exit;
        }
        
        // Close session before sending email
        $this->close_otp_session();
        
        // Send new OTP
        $this->send_otp_email($user);
        update_user_meta($user->ID, 'login_otp_last_sent', time());
        
        // Redirect back to OTP verification page
        wp_redirect(add_query_arg('otp_verification', '1', wp_login_url()));
        exit;
    }
    
    /**
     * Handle OTP error messages
     */
    public function display_otp_error_messages() {
        if (isset($_GET['otp_error']) && $_GET['otp_error'] == 'rate_limit') {
            $rate_limit_minutes = ceil(get_option('email_otp_login_rate_limit', 60) / 60);
            ?>
            <div id="login_error">
                <?php printf(__('Please wait at least %d minute(s) before requesting another verification code.', 'email-otp-login'), $rate_limit_minutes); ?>
            </div>
            <?php
        }
    }
    
    /**
     * Cleanup old OTP codes daily
     */
    public function cleanup_expired_otp_codes() {
        $users = get_users(array('fields' => 'ID'));
        foreach ($users as $user_id) {
            $expiry = (int) get_user_meta($user_id, 'login_otp_expiry', true);
            if ($expiry && time() > $expiry) {
                delete_user_meta($user_id, 'login_otp_code');
                delete_user_meta($user_id, 'login_otp_expiry');
            }
        }
    }
    
    /**
     * Add settings option to disable OTP for specific users
     */
    public function otp_settings_fields($user) {
        $disable_otp = get_user_meta($user->ID, 'disable_login_otp', true);
        ?>
        <h3><?php _e('Login Security', 'email-otp-login'); ?></h3>
        <table class="form-table">
            <tr>
                <th><label for="disable_login_otp"><?php _e('Two-Factor Authentication', 'email-otp-login'); ?></label></th>
                <td>
                    <input type="checkbox" name="disable_login_otp" id="disable_login_otp" value="1" <?php checked($disable_otp, '1'); ?>>
                    <label for="disable_login_otp"><?php _e('Disable email verification code during login', 'email-otp-login'); ?></label>
                    <p class="description"><?php _e('Not recommended. Two-factor authentication adds an extra layer of security to your account.', 'email-otp-login'); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }
    
    /**
     * Save user OTP settings
     */
    public function save_otp_settings($user_id) {
        if (!current_user_can('edit_user', $user_id)) {
            return false;
        }
        
        $disable_otp = isset($_POST['disable_login_otp']) ? '1' : '0';
        update_user_meta($user_id, 'disable_login_otp', $disable_otp);
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_options_page(
            __('Email OTP Login Settings', 'email-otp-login'),
            __('Email OTP Login', 'email-otp-login'),
            'manage_options',
            'email-otp-login',
            array($this, 'admin_page')
        );
    }
    
    /**
     * Admin settings page
     */
    public function admin_page() {
        if (isset($_POST['submit'])) {
            check_admin_referer('email_otp_login_settings');
            
            update_option('email_otp_login_enabled', isset($_POST['email_otp_login_enabled']) ? '1' : '0');
            update_option('email_otp_login_expiry', max(1, min(60, (int) $_POST['email_otp_login_expiry'])));
            update_option('email_otp_login_rate_limit', max(30, min(300, (int) $_POST['email_otp_login_rate_limit'])));
            
            echo '<div class="notice notice-success"><p>' . __('Settings saved successfully!', 'email-otp-login') . '</p></div>';
        }
        
        $enabled = get_option('email_otp_login_enabled', '1');
        $expiry = get_option('email_otp_login_expiry', 10);
        $rate_limit = get_option('email_otp_login_rate_limit', 60);
        ?>
        <div class="wrap">
            <h1><?php _e('Email OTP Login Settings', 'email-otp-login'); ?></h1>
            
            <form method="post" action="">
                <?php wp_nonce_field('email_otp_login_settings'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Enable OTP Login', 'email-otp-login'); ?></th>
                        <td>
                            <input type="checkbox" name="email_otp_login_enabled" id="email_otp_login_enabled" value="1" <?php checked($enabled, '1'); ?>>
                            <label for="email_otp_login_enabled"><?php _e('Enable email OTP verification for login', 'email-otp-login'); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('OTP Expiry Time', 'email-otp-login'); ?></th>
                        <td>
                            <input type="number" name="email_otp_login_expiry" id="email_otp_login_expiry" value="<?php echo esc_attr($expiry); ?>" min="1" max="60" class="small-text">
                            <label for="email_otp_login_expiry"><?php _e('minutes', 'email-otp-login'); ?></label>
                            <p class="description"><?php _e('How long the OTP code remains valid (1-60 minutes)', 'email-otp-login'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Rate Limit', 'email-otp-login'); ?></th>
                        <td>
                            <input type="number" name="email_otp_login_rate_limit" id="email_otp_login_rate_limit" value="<?php echo esc_attr($rate_limit); ?>" min="30" max="300" class="small-text">
                            <label for="email_otp_login_rate_limit"><?php _e('seconds', 'email-otp-login'); ?></label>
                            <p class="description"><?php _e('Minimum time between OTP requests (30-300 seconds)', 'email-otp-login'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
            
            <hr>
            
            <h2><?php _e('Plugin Information', 'email-otp-login'); ?></h2>
            <p><?php _e('This plugin adds two-factor authentication to WordPress login using email OTP codes.', 'email-otp-login'); ?></p>
            <p><?php _e('Users can disable OTP for their accounts in their profile settings (not recommended).', 'email-otp-login'); ?></p>
            <p><?php printf(__('Plugin Version: %s', 'email-otp-login'), self::VERSION); ?></p>
        </div>
        <?php
    }
}

// Initialize the plugin
EmailOTPLogin::get_instance();
