<?php
/**
 * Plugin Name: Proxy Login Blocker by TechWeirdo
 * Plugin URI: https://github.com/drshounak/wordpress-plugins/tree/main/proxy_login_blocker
 * Description: Blocks proxy and hosting IPs from accessing WordPress login page with customizable API endpoints
 * Version: 1.0.0
 * Author: TechWeirdo
 * Author URI: https://twitter.com/drshounakpal
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: plb
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

class ProxyLoginBlocker {
    
    private $cache_key = 'plb_ip_cache';
    private $rate_limit_key = 'plb_rate_limit';
    private $last_check_key = 'plb_last_check';
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_init', array($this, 'admin_init'));
        add_action('wp_ajax_plb_check_rate_limit', array($this, 'ajax_check_rate_limit'));
        add_action('wp_ajax_plb_get_cached_ips', array($this, 'ajax_get_cached_ips'));
        
        // Schedule rate limit recovery check
        add_action('wp', array($this, 'schedule_rate_limit_recovery'));
        add_action('plb_rate_limit_recovery', array($this, 'check_rate_limit_recovery'));
        
        // Add dashboard widget if enabled
        add_action('wp_dashboard_setup', array($this, 'add_dashboard_widget'));

        // Add hidden token to login form
        add_action('login_form', array($this, 'add_token_to_form'));
        
        // Start session for bypass tracking
        if (!session_id()) {
            session_start();
        }
        
        // Hook into login page
        add_action('login_init', array($this, 'handle_login_check'));
        
        // Cleanup old cache entries
        add_action('wp_loaded', array($this, 'cleanup_old_cache'));
        
        // Add custom cron schedule
        add_filter('cron_schedules', array($this, 'add_cron_schedules'));
    }
    
    public function init() {
        // Initialize default options
        if (get_option('plb_api_endpoint') === false) {
            update_option('plb_api_endpoint', 'http://ip-api.com/json/{IP}?fields=16908288');
        }
        if (get_option('plb_api_key') === false) {
            update_option('plb_api_key', '');
        }
        if (get_option('plb_whitelist_ips') === false) {
            update_option('plb_whitelist_ips', '');
        }
        if (get_option('plb_proxy_field') === false) {
            update_option('plb_proxy_field', 'proxy');
        }
        if (get_option('plb_hosting_field') === false) {
            update_option('plb_hosting_field', 'hosting');
        }
        if (get_option('plb_enabled') === false) {
            update_option('plb_enabled', '1');
        }
        if (get_option('plb_allow_on_api_fail') === false) {
            update_option('plb_allow_on_api_fail', '0');
        }
        if (get_option('plb_show_dashboard_widget') === false) {
            update_option('plb_show_dashboard_widget', '0');
        }
        if (get_option('plb_show_cached_ips') === false) {
            update_option('plb_show_cached_ips', '1');
        }
    }
    
    public function add_cron_schedules($schedules) {
        $schedules['plb_ten_minutes'] = array(
            'interval' => 600, // 10 minutes
            'display' => 'Every 10 Minutes (PLB Rate Limit Check)'
        );
        return $schedules;
    }
    
    public function handle_login_check() {
    // Only handle wp-login.php requests
    if (!isset($_SERVER['SCRIPT_NAME']) || basename($_SERVER['SCRIPT_NAME']) !== 'wp-login.php') {
        return;
    }
    
    // Skip if plugin is disabled
    if (!get_option('plb_enabled')) {
        return;
    }
    
    // Handle proxy check page
    if (isset($_GET['proxy-check'])) {
        $this->show_proxy_check_page();
        exit;
    }
    
    // Handle POST requests (actual login attempts)
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $this->handle_post_request();
        return;
    }
    
    // Check if user has valid bypass token
    if (isset($_GET['plb_token']) && $this->verify_bypass_token($_GET['plb_token'])) {
        // Valid token, allow access and clean up
        $this->cleanup_bypass_token($_GET['plb_token']);
        return;
    }
    
    // Check if user already passed verification recently (session-based)
    if (isset($_SESSION['plb_verified']) && $_SESSION['plb_verified'] > time() - 3600) { // 1 hour
        return;
    }
    
    // Redirect to proxy check page
    $redirect_url = add_query_arg('proxy-check', '1', wp_login_url());
    wp_redirect($redirect_url);
    exit;
}

private function handle_post_request() {
    // Allow if user has valid session
    if (isset($_SESSION['plb_verified']) && $_SESSION['plb_verified'] > time() - 3600) {
        return; // Allow POST to proceed
    }
    
    // Allow if valid token in URL
    if (isset($_GET['plb_token']) && $this->verify_bypass_token($_GET['plb_token'])) {
        return; // Allow POST to proceed
    }
    
    // Allow if valid token in POST data
    if (isset($_POST['plb_token']) && $this->verify_bypass_token($_POST['plb_token'])) {
        return; // Allow POST to proceed
    }
    
    // Block unauthorized POST
    status_header(403);
    echo '<!DOCTYPE html>
    <html>
    <head>
        <title>Access Denied</title>
        <style>
            body { font-family: Arial, sans-serif; text-align: center; padding: 50px; }
            .container { max-width: 400px; margin: 0 auto; }
            .error { color: #e74c3c; }
        </style>
    </head>
    <body>
        <div class="container">
            <h1 class="error">Access Denied</h1>
            <p>Security verification required before login.</p>
            <a href="' . wp_login_url() . '">Return to Login</a>
        </div>
    </body>
    </html>';
    exit;
}
    


    public function add_token_to_form() {
    // Only add if we have a token in the URL
    if (isset($_GET['plb_token']) && $this->verify_bypass_token($_GET['plb_token'])) {
        echo '<input type="hidden" name="plb_token" value="' . esc_attr($_GET['plb_token']) . '">';
    }
    }
    
    
    private function show_proxy_check_page() {
        $user_ip = $this->get_user_ip();
        $check_result = $this->check_ip($user_ip);
        
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>Security Check - <?php bloginfo('name'); ?></title>
            <style>
                body { 
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                    background: #f1f1f1;
                    margin: 0;
                    padding: 20px;
                }
                .container {
                    max-width: 400px;
                    margin: 100px auto;
                    background: white;
                    padding: 40px;
                    border-radius: 8px;
                    box-shadow: 0 1px 3px rgba(0,0,0,0.13);
                    text-align: center;
                }
                .spinner {
                    border: 4px solid #f3f3f3;
                    border-top: 4px solid #3498db;
                    border-radius: 50%;
                    width: 50px;
                    height: 50px;
                    animation: spin 1s linear infinite;
                    margin: 20px auto;
                }
                @keyframes spin {
                    0% { transform: rotate(0deg); }
                    100% { transform: rotate(360deg); }
                }
                .success { color: #27ae60; }
                .error { color: #e74c3c; }
                .btn {
                    background: #0073aa;
                    color: white;
                    padding: 10px 20px;
                    border: none;
                    border-radius: 4px;
                    cursor: pointer;
                    text-decoration: none;
                    display: inline-block;
                    margin-top: 20px;
                }
                .btn:hover { background: #005a87; }
            </style>
        </head>
        <body>
            <div class="container">
                <?php if ($check_result === null): ?>
                    <h2>Security Check in Progress...</h2>
                    <div class="spinner"></div>
                    <p>Please wait while we verify your connection...</p>
                    <script>
                        setTimeout(function() {
                            window.location.reload();
                        }, 3000);
                    </script>
                <?php elseif ($check_result === true): ?>
                    <h2 class="success">✓ Access Granted</h2>
                    <p>Your connection has been verified. Redirecting to login...</p>
                    <script>
                        // Create bypass token and redirect
                        setTimeout(function() {
                            window.location.href = '<?php echo $this->create_bypass_url(); ?>';
                        }, 2000);
                    </script>
                    <?php 
                    // Set session flag for backup
                    $_SESSION['plb_verified'] = time();
                    ?>
                <?php else: ?>
                    <h2 class="error">⚠ Access Denied</h2>
                    <p><strong>Proxy/VPN/Hosting IP Detected</strong></p>
                    <p>Access to the login page is restricted for proxy, VPN, and hosting IP addresses for security reasons.</p>
                    <p>Your IP: <code><?php echo esc_html($user_ip); ?></code></p>
                    <a href="<?php echo home_url(); ?>" class="btn">Return to Homepage</a>
                <?php endif; ?>
            </div>
        </body>
        </html>
        <?php
    }
    
    private function check_ip($ip) {
        // Check whitelist first
        $whitelist = get_option('plb_whitelist_ips');
        if (!empty($whitelist)) {
            $whitelist_ips = array_map('trim', explode("\n", $whitelist));
            if (in_array($ip, $whitelist_ips)) {
                return true;
            }
        }
        
        // Check cache
        $cache = get_transient($this->cache_key);
        if ($cache && isset($cache[$ip])) {
            $cached_data = $cache[$ip];
            if (time() - $cached_data['timestamp'] < 14400) { // 4 hours
                return $cached_data['allowed'];
            }
        }
        
        // Check rate limit before making API call
        $rate_limit = get_transient($this->rate_limit_key);
        if ($rate_limit && $rate_limit['remaining'] <= 0 && time() < $rate_limit['reset_time']) {
            // Rate limited, check user preference
            if (get_option('plb_allow_on_api_fail')) {
                return true; // Allow access when API is rate limited
            } else {
                return false; // Deny access when API is rate limited
            }
        }
        
        // Make API call
        $api_result = $this->call_api($ip);
        
        if ($api_result === null) {
            // API call failed, check user preference
            if (get_option('plb_allow_on_api_fail')) {
                return true; // Allow access when API fails
            } else {
                return false; // Deny access when API fails
            }
        }
        
        // Cache the result with more details
        if (!$cache) $cache = array();
        $cache[$ip] = array(
            'allowed' => $api_result,
            'timestamp' => time(),
            'reason' => $api_result ? 'clean' : 'proxy/hosting detected',
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 100) : 'unknown'
        );
        set_transient($this->cache_key, $cache, 86400); // 24 hours
        
        return $api_result;
    }
    
    private function call_api($ip) {
        $endpoint = get_option('plb_api_endpoint');
        $api_key = get_option('plb_api_key');
        $proxy_field = get_option('plb_proxy_field');
        $hosting_field = get_option('plb_hosting_field');
        
        // Replace {IP} placeholder
        $url = str_replace('{IP}', $ip, $endpoint);
        
        // Add API key if provided
        if (!empty($api_key)) {
            $url = add_query_arg('key', $api_key, $url);
        }
        
        $response = wp_remote_get($url, array(
            'timeout' => 10,
            'headers' => array(
                'User-Agent' => 'WordPress Proxy Login Blocker/1.0.0'
            )
        ));
        
        if (is_wp_error($response)) {
            return null;
        }
        
        // Update rate limit info from headers
        $headers = wp_remote_retrieve_headers($response);
        $this->update_rate_limit_from_headers($headers);
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!$data) {
            return null;
        }
        
        // Check if it's a proxy or hosting IP
        $is_proxy = isset($data[$proxy_field]) && $data[$proxy_field];
        $is_hosting = isset($data[$hosting_field]) && $data[$hosting_field];
        
        // Alternative field names for different APIs
        if (!$is_proxy && isset($data['is_proxy'])) {
            $is_proxy = $data['is_proxy'];
        }
        
        return !($is_proxy || $is_hosting);
    }
    
    private function create_bypass_url() {
    $token = wp_generate_password(32, false);
    $tokens = get_transient('plb_bypass_tokens') ?: array();
    $tokens[$token] = time() + 300; // 5 minutes expiry
    set_transient('plb_bypass_tokens', $tokens, 3600);
    
    // Store token in session for form inclusion
    $_SESSION['plb_current_token'] = $token;
    
    return add_query_arg('plb_token', $token, wp_login_url());
}
    
    private function verify_bypass_token($token) {
        $tokens = get_transient('plb_bypass_tokens');
        if (!$tokens || !isset($tokens[$token])) {
            return false;
        }
        
        return $tokens[$token] > time();
    }
    
    private function cleanup_bypass_token($token) {
        $tokens = get_transient('plb_bypass_tokens');
        if ($tokens && isset($tokens[$token])) {
            unset($tokens[$token]);
            set_transient('plb_bypass_tokens', $tokens, 3600);
        }
    }
    
    private function update_rate_limit_from_headers($headers) {
        if (isset($headers['x-rl'])) {
            $remaining = intval($headers['x-rl']);
            $ttl = isset($headers['x-ttl']) ? intval($headers['x-ttl']) : 60;
            
            $rate_limit_data = array(
                'remaining' => $remaining,
                'reset_time' => time() + $ttl,
                'last_check' => time(),
                'last_api_call' => time()
            );
            
            set_transient($this->rate_limit_key, $rate_limit_data, $ttl + 60);
            
            // If we hit zero, schedule recovery checks
            if ($remaining <= 0) {
                $this->schedule_rate_limit_recovery();
            }
        }
    }
    
    public function schedule_rate_limit_recovery() {
        // Only schedule if not already scheduled
        if (!wp_next_scheduled('plb_rate_limit_recovery')) {
            wp_schedule_event(time() + 600, 'plb_ten_minutes', 'plb_rate_limit_recovery'); // Start in 10 minutes
        }
    }
    
    public function check_rate_limit_recovery() {
        $rate_limit = get_transient($this->rate_limit_key);
        
        // If no rate limit data or if we're past reset time, try a test call
        if (!$rate_limit || time() >= $rate_limit['reset_time']) {
            $this->make_test_api_call();
        }
        
        // Check if we still need to keep checking
        $rate_limit = get_transient($this->rate_limit_key);
        if (!$rate_limit || $rate_limit['remaining'] > 0 || time() >= $rate_limit['reset_time']) {
            // Rate limit recovered, clear the scheduled event
            wp_clear_scheduled_hook('plb_rate_limit_recovery');
        }
    }
    
    private function make_test_api_call() {
        // Use a known test IP to check rate limit status
        $test_ip = '8.8.8.8'; // Google DNS, known clean IP
        $endpoint = get_option('plb_api_endpoint');
        $api_key = get_option('plb_api_key');
        
        $url = str_replace('{IP}', $test_ip, $endpoint);
        if (!empty($api_key)) {
            $url = add_query_arg('key', $api_key, $url);
        }
        
        $response = wp_remote_get($url, array(
            'timeout' => 5,
            'headers' => array(
                'User-Agent' => 'WordPress Proxy Login Blocker/1.0.0 (Rate Limit Check)'
            )
        ));
        
        if (!is_wp_error($response)) {
            $headers = wp_remote_retrieve_headers($response);
            $this->update_rate_limit_from_headers($headers);
        }
    }
    
    public function add_dashboard_widget() {
        if (get_option('plb_show_dashboard_widget') && current_user_can('manage_options')) {
            wp_add_dashboard_widget(
                'plb_dashboard_widget',
                'Proxy Login Blocker Status',
                array($this, 'dashboard_widget_content')
            );
        }
    }
    
    public function dashboard_widget_content() {
        $rate_limit = get_transient($this->rate_limit_key);
        $cache = get_transient($this->cache_key);
        
        $total_cached = $cache ? count($cache) : 0;
        $blocked_count = 0;
        $allowed_count = 0;
        
        if ($cache) {
            foreach ($cache as $data) {
                if ($data['allowed']) {
                    $allowed_count++;
                } else {
                    $blocked_count++;
                }
            }
        }
        
        echo '<div style="display: flex; justify-content: space-between; margin-bottom: 15px;">';
        echo '<div><strong>Cached IPs:</strong> ' . $total_cached . '</div>';
        echo '<div><strong>Allowed:</strong> <span style="color: green;">' . $allowed_count . '</span></div>';
        echo '<div><strong>Blocked:</strong> <span style="color: red;">' . $blocked_count . '</span></div>';
        echo '</div>';
        
        if ($rate_limit) {
            echo '<div style="margin-bottom: 10px;">';
            echo '<strong>API Status:</strong> ';
            if ($rate_limit['remaining'] > 10) {
                echo '<span style="color: green;">Good (' . $rate_limit['remaining'] . ' requests left)</span>';
            } elseif ($rate_limit['remaining'] > 0) {
                echo '<span style="color: orange;">Low (' . $rate_limit['remaining'] . ' requests left)</span>';
            } else {
                echo '<span style="color: red;">Rate Limited (resets in ' . max(0, $rate_limit['reset_time'] - time()) . ' seconds)</span>';
            }
            echo '</div>';
        }
        
        echo '<div style="text-align: center; margin-top: 15px;">';
        echo '<a href="' . admin_url('options-general.php?page=proxy-login-blocker') . '" class="button button-small">View Details</a>';
        echo '</div>';
    }
    
    public function ajax_get_cached_ips() {
        check_ajax_referer('plb_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
        
        $cache = get_transient($this->cache_key);
        $formatted_cache = array();
        
        if ($cache) {
            foreach ($cache as $ip => $data) {
                $formatted_cache[] = array(
                    'ip' => $ip,
                    'status' => $data['allowed'] ? 'allowed' : 'blocked',
                    'reason' => isset($data['reason']) ? $data['reason'] : ($data['allowed'] ? 'clean' : 'proxy/hosting'),
                    'timestamp' => date('Y-m-d H:i:s', $data['timestamp']),
                    'expires_in' => max(0, ($data['timestamp'] + 14400) - time()), // 4 hours
                    'user_agent' => isset($data['user_agent']) ? $data['user_agent'] : 'unknown'
                );
            }
            
            // Sort by timestamp, newest first
            usort($formatted_cache, function($a, $b) {
                return strtotime($b['timestamp']) - strtotime($a['timestamp']);
            });
        }
        
        wp_send_json_success($formatted_cache);
    }
    
    private function get_user_ip() {
        $ip_keys = array('HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR');
        
        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                $ip = trim($_SERVER[$key]);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
                // Handle comma-separated IPs
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                        return $ip;
                    }
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }
    
    public function cleanup_old_cache() {
        $cache = get_transient($this->cache_key);
        if ($cache) {
            $current_time = time();
            $updated = false;
            
            foreach ($cache as $ip => $data) {
                if ($current_time - $data['timestamp'] > 14400) { // 4 hours
                    unset($cache[$ip]);
                    $updated = true;
                }
            }
            
            if ($updated) {
                set_transient($this->cache_key, $cache, 86400);
            }
        }
    }
    
    public function admin_menu() {
        add_options_page(
            'Proxy Login Blocker Settings',
            'Proxy Blocker',
            'manage_options',
            'proxy-login-blocker',
            array($this, 'admin_page')
        );
    }
    
    public function admin_init() {
        register_setting('plb_settings', 'plb_enabled');
        register_setting('plb_settings', 'plb_api_endpoint');
        register_setting('plb_settings', 'plb_api_key');
        register_setting('plb_settings', 'plb_proxy_field');
        register_setting('plb_settings', 'plb_hosting_field');
        register_setting('plb_settings', 'plb_whitelist_ips');
        register_setting('plb_settings', 'plb_allow_on_api_fail');
        register_setting('plb_settings', 'plb_show_dashboard_widget');
        register_setting('plb_settings', 'plb_show_cached_ips');
    }
    
    public function ajax_check_rate_limit() {
        check_ajax_referer('plb_nonce', 'nonce');
        
        $rate_limit = get_transient($this->rate_limit_key);
        
        if ($rate_limit) {
            wp_send_json_success(array(
                'remaining' => $rate_limit['remaining'],
                'reset_time' => $rate_limit['reset_time'],
                'last_check' => $rate_limit['last_check']
            ));
        } else {
            wp_send_json_success(array(
                'remaining' => 'Unknown',
                'reset_time' => 0,
                'last_check' => 0
            ));
        }
    }
    
    public function admin_page() {
        if (isset($_POST['submit'])) {
            update_option('plb_enabled', isset($_POST['plb_enabled']) ? '1' : '0');
            update_option('plb_api_endpoint', sanitize_text_field($_POST['plb_api_endpoint']));
            update_option('plb_api_key', sanitize_text_field($_POST['plb_api_key']));
            update_option('plb_proxy_field', sanitize_text_field($_POST['plb_proxy_field']));
            update_option('plb_hosting_field', sanitize_text_field($_POST['plb_hosting_field']));
            update_option('plb_whitelist_ips', sanitize_textarea_field($_POST['plb_whitelist_ips']));
            update_option('plb_allow_on_api_fail', isset($_POST['plb_allow_on_api_fail']) ? '1' : '0');
            update_option('plb_show_dashboard_widget', isset($_POST['plb_show_dashboard_widget']) ? '1' : '0');
            update_option('plb_show_cached_ips', isset($_POST['plb_show_cached_ips']) ? '1' : '0');
            
            echo '<div class="notice notice-success"><p>Settings saved!</p></div>';
        }
        
        $enabled = get_option('plb_enabled');
        $api_endpoint = get_option('plb_api_endpoint');
        $api_key = get_option('plb_api_key');
        $proxy_field = get_option('plb_proxy_field');
        $hosting_field = get_option('plb_hosting_field');
        $whitelist_ips = get_option('plb_whitelist_ips');
        $allow_on_api_fail = get_option('plb_allow_on_api_fail');
        $show_dashboard_widget = get_option('plb_show_dashboard_widget');
        $show_cached_ips = get_option('plb_show_cached_ips');
        
        ?>
        <div class="wrap">
            <h1>Proxy Login Blocker Settings</h1>
            
            <div id="rate-limit-info" style="background: #fff; padding: 15px; border: 1px solid #ddd; margin: 20px 0; border-radius: 5px;">
                <h3>📊 API Rate Limit Status</h3>
                <div style="display: flex; gap: 20px; align-items: center;">
                    <div>
                        <strong>Remaining:</strong> <span id="remaining-requests" style="font-size: 18px; font-weight: bold;">Loading...</span>
                    </div>
                    <div>
                        <strong>Last Check:</strong> <span id="last-check">Loading...</span>
                    </div>
                    <div>
                        <strong>Status:</strong> <span id="api-status">Loading...</span>
                    </div>
                </div>
                <div style="margin-top: 10px;">
                    <button type="button" id="refresh-rate-limit" class="button">🔄 Refresh Status</button>
                    <span id="auto-refresh" style="margin-left: 15px; color: #666;">Auto-refreshing every 30 seconds...</span>
                </div>
            </div>
            
            <form method="post">
                <table class="form-table">
                    <tr>
                        <th scope="row">Enable Plugin</th>
                        <td>
                            <input type="checkbox" name="plb_enabled" value="1" <?php checked($enabled, '1'); ?> />
                            <label>Enable proxy detection on login page</label>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">API Endpoint</th>
                        <td>
                            <input type="text" name="plb_api_endpoint" value="<?php echo esc_attr($api_endpoint); ?>" class="regular-text" />
                            <p class="description">
                                Use {IP} as placeholder for the IP address.<br>
                                <strong>Examples:</strong><br>
                                • <code>http://ip-api.com/json/{IP}?fields=16908288</code> (Free, 45/min limit)<br>
                                • <code>https://example.com/api/{IP}/proxy/json</code> (Custom API)<br>
                                • <code>https://api.example.com/geo/proxy?ip={IP}</code> (Custom API)
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">API Key</th>
                        <td>
                            <input type="text" name="plb_api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text" />
                            <p class="description">Leave empty if not required (like ip-api.com free version)</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Proxy Field Name</th>
                        <td>
                            <input type="text" name="plb_proxy_field" value="<?php echo esc_attr($proxy_field); ?>" class="regular-text" />
                            <p class="description">JSON field name for proxy detection (e.g., "proxy", "is_proxy")</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Hosting Field Name</th>
                        <td>
                            <input type="text" name="plb_hosting_field" value="<?php echo esc_attr($hosting_field); ?>" class="regular-text" />
                            <p class="description">JSON field name for hosting detection (e.g., "hosting", "is_hosting")</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Whitelisted IPs</th>
                        <td>
                            <textarea name="plb_whitelist_ips" rows="5" class="large-text"><?php echo esc_textarea($whitelist_ips); ?></textarea>
                            <p class="description">One IP per line. These IPs will always be allowed to login.</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Allow Access on API Failure</th>
                        <td>
                            <input type="checkbox" name="plb_allow_on_api_fail" value="1" <?php checked($allow_on_api_fail, '1'); ?> />
                            <label>Allow login when API is unavailable or rate limited</label>
                            <p class="description">If unchecked, users will be blocked when API fails or rate limit is exceeded (except whitelisted IPs and cached results).</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Dashboard Widget</th>
                        <td>
                            <input type="checkbox" name="plb_show_dashboard_widget" value="1" <?php checked($show_dashboard_widget, '1'); ?> />
                            <label>Show status widget on WordPress dashboard</label>
                            <p class="description">Displays quick stats about blocked/allowed IPs and API status.</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Show Cached IPs</th>
                        <td>
                            <input type="checkbox" name="plb_show_cached_ips" value="1" <?php checked($show_cached_ips, '1'); ?> />
                            <label>Display cached IP list below</label>
                            <p class="description">Shows all IPs currently stored in 4-hour cache with their status.</p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
            
            <?php if ($show_cached_ips): ?>
            <div id="cached-ips-section" style="margin-top: 30px;">
                <h2>🔍 Cached IP Monitoring</h2>
                <div style="background: #fff; padding: 20px; border: 1px solid #ddd; border-radius: 5px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                        <div>
                            <strong>Total Cached IPs:</strong> <span id="total-cached">0</span> |
                            <strong>Allowed:</strong> <span id="total-allowed" style="color: green;">0</span> |
                            <strong>Blocked:</strong> <span id="total-blocked" style="color: red;">0</span>
                        </div>
                        <button type="button" id="refresh-cached-ips" class="button">🔄 Refresh</button>
                    </div>
                    
                    <div id="cached-ips-table">
                        <div style="text-align: center; padding: 20px;">Loading cached IPs...</div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <div style="margin-top: 30px; padding: 20px; background: #f9f9f9; border: 1px solid #ddd;">
                <h3>How it works:</h3>
                <ol>
                    <li>When users visit wp-login.php, they're redirected to a security check page</li>
                    <li>The plugin checks their IP against whitelisted IPs first</li>
                    <li>Then checks the in-memory cache (4-hour expiry)</li>
                    <li>If not cached, makes an API call to check if IP is proxy/hosting</li>
                    <li>Results are cached for 4 hours and auto-cleaned up</li>
                    <li>Rate limiting is respected (45 requests/minute for ip-api.com)</li>
                    <li>Only blocks access to wp-login.php, not wp-admin (preserves cron, AJAX, etc.)</li>
                </ol>
                
                <h3>Rate Limit Notes:</h3>
                <p>• Free ip-api.com: 45 requests per minute</p>
                <p>• If rate limit reached, no new logins allowed for 10 minutes (except whitelisted IPs)</p>
                <p>• Cache reduces API calls significantly</p>
                <p>• Status checked every 30 minutes when no requests made</p>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            let autoRefreshInterval;
            
            function updateRateLimit() {
                $.post(ajaxurl, {
                    action: 'plb_check_rate_limit',
                    nonce: '<?php echo wp_create_nonce('plb_nonce'); ?>'
                }, function(response) {
                    if (response.success) {
                        const remaining = response.data.remaining;
                        $('#remaining-requests').text(remaining);
                        
                        // Update status indicator
                        let status = '';
                        let statusColor = '';
                        if (remaining === 'Unknown') {
                            status = 'Unknown';
                            statusColor = '#666';
                        } else if (remaining > 10) {
                            status = '✅ Good';
                            statusColor = 'green';
                        } else if (remaining > 0) {
                            status = '⚠️ Low';
                            statusColor = 'orange';
                        } else {
                            status = '🚫 Rate Limited';
                            statusColor = 'red';
                        }
                        $('#api-status').html('<span style="color: ' + statusColor + ';">' + status + '</span>');
                        
                        var lastCheck = response.data.last_check;
                        if (lastCheck > 0) {
                            var date = new Date(lastCheck * 1000);
                            $('#last-check').text(date.toLocaleString());
                        } else {
                            $('#last-check').text('Never');
                        }
                    }
                });
            }
            
            function updateCachedIPs() {
                <?php if ($show_cached_ips): ?>
                $.post(ajaxurl, {
                    action: 'plb_get_cached_ips',
                    nonce: '<?php echo wp_create_nonce('plb_nonce'); ?>'
                }, function(response) {
                    if (response.success) {
                        const ips = response.data;
                        let allowedCount = 0;
                        let blockedCount = 0;
                        
                        $('#total-cached').text(ips.length);
                        
                        if (ips.length === 0) {
                            $('#cached-ips-table').html('<div style="text-align: center; padding: 20px; color: #666;">No cached IPs found.</div>');
                            $('#total-allowed').text('0');
                            $('#total-blocked').text('0');
                            return;
                        }
                        
                        let tableHTML = '<table class="wp-list-table widefat fixed striped" style="margin-top: 10px;">';
                        tableHTML += '<thead><tr>';
                        tableHTML += '<th>IP Address</th>';
                        tableHTML += '<th>Status</th>';
                        tableHTML += '<th>Reason</th>';
                        tableHTML += '<th>Cached Time</th>';
                        tableHTML += '<th>Expires In</th>';
                        tableHTML += '<th>User Agent</th>';
                        tableHTML += '</tr></thead><tbody>';
                        
                        ips.forEach(function(ip) {
                            if (ip.status === 'allowed') allowedCount++;
                            else blockedCount++;
                            
                            const statusColor = ip.status === 'allowed' ? 'green' : 'red';
                            const statusIcon = ip.status === 'allowed' ? '✅' : '🚫';
                            const expiresIn = Math.floor(ip.expires_in / 60) + ' min';
                            
                            tableHTML += '<tr>';
                            tableHTML += '<td><code>' + ip.ip + '</code></td>';
                            tableHTML += '<td><span style="color: ' + statusColor + ';">' + statusIcon + ' ' + ip.status.toUpperCase() + '</span></td>';
                            tableHTML += '<td>' + ip.reason + '</td>';
                            tableHTML += '<td>' + ip.timestamp + '</td>';
                            tableHTML += '<td>' + expiresIn + '</td>';
                            tableHTML += '<td style="font-size: 11px; max-width: 200px; word-break: break-all;">' + ip.user_agent + '</td>';
                            tableHTML += '</tr>';
                        });
                        
                        tableHTML += '</tbody></table>';
                        $('#cached-ips-table').html(tableHTML);
                        $('#total-allowed').text(allowedCount);
                        $('#total-blocked').text(blockedCount);
                    }
                });
                <?php endif; ?>
            }
            
            // Event handlers
            $('#refresh-rate-limit').click(updateRateLimit);
            $('#refresh-cached-ips').click(updateCachedIPs);
            
            // Initial load
            updateRateLimit();
            updateCachedIPs();
            
            // Auto-refresh every 30 seconds
            autoRefreshInterval = setInterval(function() {
                updateRateLimit();
                updateCachedIPs();
            }, 30000);
            
            // Clear interval when page unloads
            $(window).on('beforeunload', function() {
                if (autoRefreshInterval) {
                    clearInterval(autoRefreshInterval);
                }
            });
        });
        </script>
        <?php
    }
}

// Initialize the plugin
new ProxyLoginBlocker();

// Activation hook to create initial options
register_activation_hook(__FILE__, function() {
    if (get_option('plb_api_endpoint') === false) {
        update_option('plb_api_endpoint', 'http://ip-api.com/json/{IP}?fields=16908288');
    }
});

// Deactivation hook to clean up transients
register_deactivation_hook(__FILE__, function() {
    delete_transient('plb_ip_cache');
    delete_transient('plb_rate_limit');
    delete_transient('plb_last_check');
});
?>
