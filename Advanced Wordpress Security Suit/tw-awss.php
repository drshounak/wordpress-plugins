<?php
/**
 * Plugin Name: Advanced WP Security Suite by TechWeirdo
 * Plugin URI: https://github.com/techweirdo/advanced-wp-security
 * Description: Comprehensive security plugin with brute force protection, proxy/VPN detection, IP reputation checking, and geo-blocking
 * Version: 3.1.0
 * Author: TechWeirdo
 * Author URI: https://twitter.com/drshounakpal
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: awss
 * Domain Path: /languages
 * 
 * @package TechWeirdo
 * @author Dr. Shounak Pal
 * @since 3.1.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class AdvancedWordPressSecurity {
    
    private $version = '3.1.0';
    private $cache_key = 'awss_ip_cache';
    private $rate_limit_key = 'awss_rate_limit';
    private $brute_force_key = 'awss_brute_force';
    private $session_key = 'awss_session';
    private $blocked_ips_key = 'awss_blocked_ips';
    
    // Default settings
    private $default_settings = array(
        'enabled' => true,
        'brute_force_enabled' => true,
        'proxy_detection_enabled' => true,
        'geo_blocking_enabled' => false,
        'ip_reputation_enabled' => false,
        'asn_blocking_enabled' => false,
        'max_login_attempts' => 5,
        'lockout_duration' => 1800, // 30 minutes
        'long_lockout_duration' => 86400, // 24 hours
        'rate_limit_window' => 60,
        'max_attempts_per_minute' => 3,
        'session_duration' => 3600, // 1 hour
        'cache_duration' => 14400, // 4 hours
        'allow_on_api_fail' => false,
        'show_dashboard_widget' => true,
        'whitelist_ips' => '',
        'blacklist_ips' => '',
        'blocked_countries' => '',
        'blocked_asns' => '',
        'min_abuse_confidence' => 75,
        'ipinfo_api_key' => '',
        'ipinfo_custom_url' => '',
        'ipinfo_custom_headers' => '',
        'abuseipdb_api_key' => '',
        'abuseipdb_custom_url' => '',
        'abuseipdb_custom_headers' => '',
        'proxycheck_api_key' => '',
        'ipapi_api_key' => '',
        'proxy_service' => 'ip-api', // Default to ip-api.com
        'proxy_custom_url' => '',
        'proxy_custom_headers' => '',
        'reputation_service' => 'abuseipdb',
        'reputation_custom_url' => '',
        'reputation_custom_headers' => '',
        'geo_service' => 'ipinfo',
        'geo_custom_url' => '',
        'geo_custom_headers' => '',
        'custom_block_message' => 'Access denied for security reasons.',
        'log_attempts' => true,
        'cleanup_logs_days' => 30,
        'enable_404_monitoring' => false,
        'max_404_per_minute' => 10,
        'block_detected_threats' => true // Block IPs that fail security checks
    );
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_init', array($this, 'admin_init'));
        add_action('wp_dashboard_setup', array($this, 'add_dashboard_widget'));
        
        // Login hooks
        add_action('login_init', array($this, 'handle_login_security'));
        add_action('wp_login_failed', array($this, 'log_failed_attempt'));
        add_action('wp_login', array($this, 'clear_attempts'), 10, 2);
        add_filter('authenticate', array($this, 'check_brute_force'), 30, 3);
        add_action('login_form', array($this, 'add_security_token'));
        
        // AJAX handlers
        add_action('wp_ajax_awss_get_ip_data', array($this, 'ajax_get_ip_data'));
        add_action('wp_ajax_awss_unlock_ip', array($this, 'ajax_unlock_ip'));
        add_action('wp_ajax_awss_block_ip', array($this, 'ajax_block_ip'));
        add_action('wp_ajax_awss_test_api', array($this, 'ajax_test_api'));
        add_action('wp_ajax_awss_clear_cache', array($this, 'ajax_clear_cache'));
        
        // 404 monitoring
        add_action('wp', array($this, 'monitor_404'));
        
        // Cleanup hooks
        add_action('wp_loaded', array($this, 'cleanup_old_data'));
        add_filter('cron_schedules', array($this, 'add_cron_schedules'));
        
        // Session management
        if (!session_id() && !headers_sent()) {
            session_start();
        }
        
        // Create database tables
        register_activation_hook(__FILE__, array($this, 'create_tables'));
        register_deactivation_hook(__FILE__, array($this, 'cleanup_on_deactivation'));
    }
    
    public function init() {
        // Initialize default settings
        $settings = get_option('awss_settings', array());
        $settings = array_merge($this->default_settings, $settings);
        update_option('awss_settings', $settings);
        
        // Load text domain
        load_plugin_textdomain('awss', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }
    
    public function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // IP logs table
        $table_name = $wpdb->prefix . 'awss_ip_logs';
        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            ip_address varchar(45) NOT NULL,
            action varchar(50) NOT NULL,
            reason text,
            user_agent text,
            timestamp datetime DEFAULT CURRENT_TIMESTAMP,
            additional_data longtext,
            PRIMARY KEY (id),
            KEY ip_address (ip_address),
            KEY timestamp (timestamp),
            KEY action (action)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Blocked IPs table
        $table_name = $wpdb->prefix . 'awss_blocked_ips';
        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            ip_address varchar(45) NOT NULL,
            block_type varchar(20) NOT NULL,
            reason text,
            blocked_until datetime,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY ip_address (ip_address),
            KEY block_type (block_type),
            KEY blocked_until (blocked_until)
        ) $charset_collate;";
        
        dbDelta($sql);
    }
    
    public function add_cron_schedules($schedules) {
        $schedules['awss_five_minutes'] = array(
            'interval' => 300,
            'display' => 'Every 5 Minutes (AWSS Cleanup)'
        );
        return $schedules;
    }
    
    public function handle_login_security() {
        // Only handle wp-login.php requests
        if (!isset($_SERVER['SCRIPT_NAME']) || basename($_SERVER['SCRIPT_NAME']) !== 'wp-login.php') {
            return;
        }
        
        $settings = get_option('awss_settings', $this->default_settings);
        
        // Skip if plugin is disabled
        if (!$settings['enabled']) {
            return;
        }
        
        $user_ip = $this->get_user_ip();
        
        // Handle security check page
        if (isset($_GET['advanced-security-check'])) {
            $this->show_security_check_page($user_ip);
            exit;
        }
        
        // Handle POST requests (login attempts)
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->handle_login_post($user_ip);
            return;
        }
        
        // Check if user has valid session
        if ($this->has_valid_session()) {
            return;
        }
        
        // Check if IP has valid bypass token
        if (isset($_GET['awss_token']) && $this->verify_security_token($_GET['awss_token'])) {
            $this->set_valid_session();
            return;
        }
        
        // Redirect to security check
        $redirect_url = add_query_arg('advanced-security-check', '1', wp_login_url());
        wp_redirect($redirect_url);
        exit;
    }
    
    private function handle_login_post($user_ip) {
        // Check for valid session or token
        if ($this->has_valid_session()) {
            return;
        }
        
        if (isset($_POST['awss_token']) && $this->verify_security_token($_POST['awss_token'])) {
            $this->set_valid_session();
            return;
        }
        
        // Block unauthorized POST
        $this->log_security_event($user_ip, 'blocked_post', 'Unauthorized POST request without valid session');
        $this->show_access_denied('Unauthorized login attempt. Please use a web browser.');
    }
    
    private function show_security_check_page($user_ip) {
        $check_result = $this->perform_security_check($user_ip);
        
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>Security Verification - <?php bloginfo('name'); ?></title>
            <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body {
                    font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                    background: #f8f9fa;
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    padding: 20px;
                    color: #2c3e50;
                }
                .security-container {
                    max-width: 500px;
                    width: 100%;
                    background: white;
                    border-radius: 12px;
                    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
                    overflow: hidden;
                    animation: slideUp 0.6s ease-out;
                    border: 1px solid #e9ecef;
                }
                @keyframes slideUp {
                    from { opacity: 0; transform: translateY(30px); }
                    to { opacity: 1; transform: translateY(0); }
                }
                .header {
                    background: #ffffff;
                    border-bottom: 2px solid #e9ecef;
                    color: #2c3e50;
                    padding: 30px;
                    text-align: center;
                }
                .header h1 {
                    font-size: 24px;
                    font-weight: 700;
                    margin-bottom: 8px;
                    color: #2c3e50;
                }
                .header p {
                    color: #6c757d;
                    font-size: 14px;
                }
                .content {
                    padding: 40px 30px;
                    text-align: center;
                }
                .status-icon {
                    font-size: 64px;
                    margin-bottom: 20px;
                    display: block;
                }
                .status-title {
                    font-size: 20px;
                    font-weight: 600;
                    margin-bottom: 12px;
                    color: #2d3748;
                }
                .status-message {
                    color: #4a5568;
                    line-height: 1.6;
                    margin-bottom: 20px;
                }
                .ip-info {
                    background: #f8f9fa;
                    border: 1px solid #dee2e6;
                    border-radius: 8px;
                    padding: 16px;
                    margin: 20px 0;
                    font-family: 'Monaco', 'Menlo', monospace;
                    font-size: 14px;
                    color: #495057;
                }
                .progress-bar {
                    width: 100%;
                    height: 6px;
                    background: #e9ecef;
                    border-radius: 3px;
                    overflow: hidden;
                    margin: 20px 0;
                }
                .progress-fill {
                    height: 100%;
                    background: linear-gradient(90deg, #007bff, #0056b3);
                    border-radius: 3px;
                    animation: progress 3s ease-in-out;
                }
                @keyframes progress {
                    from { width: 0%; }
                    to { width: 100%; }
                }
                .btn {
                    display: inline-block;
                    background: #007bff;
                    color: white;
                    padding: 12px 24px;
                    border-radius: 6px;
                    text-decoration: none;
                    font-weight: 500;
                    transition: all 0.2s ease;
                    border: none;
                    cursor: pointer;
                    font-size: 14px;
                }
                .btn:hover {
                    background: #0056b3;
                    transform: translateY(-1px);
                    box-shadow: 0 4px 12px rgba(0, 123, 255, 0.3);
                }
                .error { color: #dc3545; }
                .success { color: #28a745; }
                .warning { color: #ffc107; }
                .checking { color: #007bff; }
                .reason-list {
                    text-align: left;
                    background: #f8d7da;
                    border: 1px solid #f5c6cb;
                    border-radius: 8px;
                    padding: 16px;
                    margin: 20px 0;
                }
                .reason-list h4 {
                    color: #721c24;
                    margin-bottom: 8px;
                    font-size: 14px;
                    font-weight: 600;
                }
                .reason-list ul {
                    list-style: none;
                    padding: 0;
                }
                .reason-list li {
                    color: #721c24;
                    font-size: 13px;
                    margin-bottom: 4px;
                    padding-left: 16px;
                    position: relative;
                }
                .reason-list li:before {
                    content: "•";
                    color: #dc3545;
                    position: absolute;
                    left: 0;
                }
                .security-badge {
                    display: inline-flex;
                    align-items: center;
                    gap: 8px;
                    background: #e3f2fd;
                    color: #1565c0;
                    padding: 8px 16px;
                    border-radius: 20px;
                    font-size: 12px;
                    font-weight: 500;
                    margin-bottom: 20px;
                }
            </style>
        </head>
        <body>
            <div class="security-container">
                <div class="header">
                    <div class="security-badge">
                        🛡️ Security Check
                    </div>
                    <h1>Access Verification</h1>
                    <p>Protecting <?php bloginfo('name'); ?> from unauthorized access</p>
                </div>
                
                <div class="content">
                    <?php if ($check_result === null): ?>
                        <span class="status-icon checking">🔍</span>
                        <h2 class="status-title checking">Analyzing Your Connection...</h2>
                        <p class="status-message">Please wait while we verify your IP address and connection security.</p>
                        <div class="progress-bar">
                            <div class="progress-fill"></div>
                        </div>
                        <div class="ip-info">Your IP: <?php echo esc_html($user_ip); ?></div>
                        <script>
                            setTimeout(function() {
                                window.location.reload();
                            }, 4000);
                        </script>
                    
                    <?php elseif ($check_result['allowed']): ?>
                        <span class="status-icon success">✅</span>
                        <h2 class="status-title success">Access Granted</h2>
                        <p class="status-message">Your connection has been verified as secure. Redirecting to login...</p>
                        <div class="ip-info">Your IP: <?php echo esc_html($user_ip); ?></div>
                        <script>
                            setTimeout(function() {
                                window.location.href = '<?php echo $this->create_security_bypass_url(); ?>';
                            }, 2000);
                        </script>
                    
                    <?php else: ?>
                        <span class="status-icon error">🚫</span>
                        <h2 class="status-title error">Access Denied</h2>
                        <p class="status-message">Your connection has been blocked for security reasons.</p>
                        
                        <div class="ip-info">Your IP: <?php echo esc_html($user_ip); ?></div>
                        
                        <?php if (!empty($check_result['reasons'])): ?>
                        <div class="reason-list">
                            <h4>Blocking Reasons:</h4>
                            <ul>
                                <?php foreach ($check_result['reasons'] as $reason): ?>
                                    <li><?php echo esc_html($reason); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <?php endif; ?>
                        
                        <p style="font-size: 13px; color: #6c757d; margin-top: 20px;">
                            If you believe this is an error, please contact the site administrator.
                        </p>
                        
                        <a href="<?php echo home_url(); ?>" class="btn">Return to Homepage</a>
                    <?php endif; ?>
                </div>
            </div>
        </body>
        </html>
        <?php
    }
    
    private function perform_security_check($user_ip) {
        $settings = get_option('awss_settings', $this->default_settings);
        $reasons = array();
        
        // Check whitelist first
        if ($this->is_whitelisted($user_ip)) {
            $this->log_security_event($user_ip, 'allowed_whitelist', 'IP is whitelisted');
            return array('allowed' => true, 'reasons' => array('Whitelisted IP'));
        }
        
        // Check permanent blacklist
        if ($this->is_blacklisted($user_ip)) {
            $reasons[] = 'IP is permanently blacklisted';
            $this->log_security_event($user_ip, 'blocked_blacklist', 'IP is blacklisted');
            return array('allowed' => false, 'reasons' => $reasons);
        }
        
        // Check temporary blocks
        if ($this->is_temporarily_blocked($user_ip)) {
            $reasons[] = 'IP is temporarily blocked due to suspicious activity';
            $this->log_security_event($user_ip, 'blocked_temporary', 'IP is temporarily blocked');
            return array('allowed' => false, 'reasons' => $reasons);
        }
        
        // Check cache first
        $cached_result = $this->get_cached_result($user_ip);
        if ($cached_result !== null) {
            // If cached result shows blocked, also add to blocked IPs table
            if (!$cached_result['allowed'] && $settings['block_detected_threats']) {
                $this->block_ip_temporarily($user_ip, $settings['cache_duration'], 'Security check failed (cached)');
            }
            return $cached_result;
        }
        
        // Perform live checks in order of cost/priority
        $check_result = array('allowed' => true, 'reasons' => array());
        
        // 1. Geo-blocking (cheapest)
        if ($settings['geo_blocking_enabled']) {
            $geo_result = $this->check_geo_blocking($user_ip);
            if (!$geo_result['allowed']) {
                $check_result['allowed'] = false;
                $check_result['reasons'] = array_merge($check_result['reasons'], $geo_result['reasons']);
            }
        }
        
        // 2. ASN blocking
        if ($check_result['allowed'] && $settings['asn_blocking_enabled']) {
            $asn_result = $this->check_asn_blocking($user_ip);
            if (!$asn_result['allowed']) {
                $check_result['allowed'] = false;
                $check_result['reasons'] = array_merge($check_result['reasons'], $asn_result['reasons']);
            }
        }
        
        // 3. Proxy/VPN detection
        if ($check_result['allowed'] && $settings['proxy_detection_enabled']) {
            $proxy_result = $this->check_proxy_detection($user_ip);
            if (!$proxy_result['allowed']) {
                $check_result['allowed'] = false;
                $check_result['reasons'] = array_merge($check_result['reasons'], $proxy_result['reasons']);
            }
        }
        
        // 4. IP reputation (most expensive)
        if ($check_result['allowed'] && $settings['ip_reputation_enabled']) {
            $reputation_result = $this->check_ip_reputation($user_ip);
            if (!$reputation_result['allowed']) {
                $check_result['allowed'] = false;
                $check_result['reasons'] = array_merge($check_result['reasons'], $reputation_result['reasons']);
            }
        }
        
        // Cache the result
        $this->cache_security_result($user_ip, $check_result);
        
        // If blocked and setting enabled, add to blocked IPs table
        if (!$check_result['allowed'] && $settings['block_detected_threats']) {
            $reason = implode(', ', $check_result['reasons']);
            $this->block_ip_temporarily($user_ip, $settings['cache_duration'], $reason);
        }
        
        // Log the result
        $action = $check_result['allowed'] ? 'allowed_security_check' : 'blocked_security_check';
        $reason = $check_result['allowed'] ? 'Passed all security checks' : implode(', ', $check_result['reasons']);
        $this->log_security_event($user_ip, $action, $reason);
        
        return $check_result;
    }
    
    private function check_geo_blocking($user_ip) {
        $settings = get_option('awss_settings', $this->default_settings);
        $blocked_countries = array_filter(array_map('trim', explode(',', strtoupper($settings['blocked_countries']))));
        
        if (empty($blocked_countries)) {
            return array('allowed' => true, 'reasons' => array());
        }
        
        $geo_data = $this->get_geo_data($user_ip);
        if (!$geo_data) {
            return $settings['allow_on_api_fail'] ? 
                array('allowed' => true, 'reasons' => array()) : 
                array('allowed' => false, 'reasons' => array('Unable to verify location'));
        }
        
        $country_code = strtoupper($geo_data['country_code'] ?? '');
        if (in_array($country_code, $blocked_countries)) {
            return array('allowed' => false, 'reasons' => array("Country blocked: {$geo_data['country']} ({$country_code})"));
        }
        
        return array('allowed' => true, 'reasons' => array());
    }
    
    private function check_asn_blocking($user_ip) {
        $settings = get_option('awss_settings', $this->default_settings);
        $blocked_asns = array_filter(array_map('trim', explode(',', strtoupper($settings['blocked_asns']))));
        
        if (empty($blocked_asns)) {
            return array('allowed' => true, 'reasons' => array());
        }
        
        $geo_data = $this->get_geo_data($user_ip);
        if (!$geo_data) {
            return $settings['allow_on_api_fail'] ? 
                array('allowed' => true, 'reasons' => array()) : 
                array('allowed' => false, 'reasons' => array('Unable to verify ASN'));
        }
        
        $asn = strtoupper($geo_data['asn'] ?? '');
        if (in_array($asn, $blocked_asns)) {
            return array('allowed' => false, 'reasons' => array("ASN blocked: {$asn} ({$geo_data['as_name']})"));
        }
        
        return array('allowed' => true, 'reasons' => array());
    }
    
    private function check_proxy_detection($user_ip) {
        $proxy_data = $this->get_proxy_data($user_ip);
        if (!$proxy_data) {
            $settings = get_option('awss_settings', $this->default_settings);
            return $settings['allow_on_api_fail'] ? 
                array('allowed' => true, 'reasons' => array()) : 
                array('allowed' => false, 'reasons' => array('Unable to verify proxy status'));
        }
        
        $reasons = array();
        
        // Handle different response formats
        $proxy_detected = $this->normalize_boolean_response($proxy_data['proxy'] ?? false);
        $vpn_detected = $this->normalize_boolean_response($proxy_data['vpn'] ?? false);
        $tor_detected = $this->normalize_boolean_response($proxy_data['tor'] ?? false);
        $hosting_detected = $this->normalize_boolean_response($proxy_data['hosting'] ?? false);
        
        if ($proxy_detected) {
            $reasons[] = 'Proxy server detected';
        }
        
        if ($vpn_detected) {
            $reasons[] = 'VPN service detected';
        }
        
        if ($tor_detected) {
            $reasons[] = 'Tor network detected';
        }
        
        if ($hosting_detected) {
            $reasons[] = 'Hosting provider detected';
        }
        
        if (!empty($reasons)) {
            return array('allowed' => false, 'reasons' => $reasons);
        }
        
        return array('allowed' => true, 'reasons' => array());
    }
    
    private function check_ip_reputation($user_ip) {
        $settings = get_option('awss_settings', $this->default_settings);
        $reputation_data = $this->get_reputation_data($user_ip);
        
        if (!$reputation_data) {
            return $settings['allow_on_api_fail'] ? 
                array('allowed' => true, 'reasons' => array()) : 
                array('allowed' => false, 'reasons' => array('Unable to verify IP reputation'));
        }
        
        $confidence_score = intval($reputation_data['abuseConfidenceScore'] ?? 0);
        if ($confidence_score >= $settings['min_abuse_confidence']) {
            return array('allowed' => false, 'reasons' => array("High abuse confidence: {$confidence_score}%"));
        }
        
        return array('allowed' => true, 'reasons' => array());
    }
    
    private function normalize_boolean_response($value) {
        if (is_bool($value)) {
            return $value;
        }
        
        $value = strtolower(trim($value));
        return in_array($value, array('true', 'yes', '1', 'on'));
    }
    
    private function get_geo_data($user_ip) {
        $settings = get_option('awss_settings', $this->default_settings);
        $service = $settings['geo_service'];
        
        switch ($service) {
            case 'ipinfo':
                return $this->get_geo_data_ipinfo($user_ip);
            case 'custom':
                return $this->get_geo_data_custom($user_ip);
            default:
                return $this->get_geo_data_ipinfo($user_ip);
        }
    }
    
    private function get_geo_data_ipinfo($user_ip) {
        $settings = get_option('awss_settings', $this->default_settings);
        $api_key = $settings['ipinfo_api_key'];
        
        if (empty($api_key)) {
            return null;
        }
        
        $url = "https://api.ipinfo.io/lite/{$user_ip}?token={$api_key}";
        
        $headers = array(
            'User-Agent' => 'WordPress AWSS/' . $this->version
        );
        
        // Add custom headers if specified
        if (!empty($settings['ipinfo_custom_headers'])) {
            $custom_headers = $this->parse_custom_headers($settings['ipinfo_custom_headers']);
            $headers = array_merge($headers, $custom_headers);
        }
        
        $response = wp_remote_get($url, array(
            'timeout' => 10,
            'headers' => $headers
        ));
        
        if (is_wp_error($response)) {
            return null;
        }
        
        $body = wp_remote_retrieve_body($response);
        return json_decode($body, true);
    }
    
    private function get_geo_data_custom($user_ip) {
        $settings = get_option('awss_settings', $this->default_settings);
        $custom_url = $settings['geo_custom_url'];
        
        if (empty($custom_url)) {
            return null;
        }
        
        // Replace {IP} placeholder
        $url = str_replace('{IP}', $user_ip, $custom_url);
        
        $headers = array(
            'User-Agent' => 'WordPress AWSS/' . $this->version
        );
        
        // Add custom headers if specified
        if (!empty($settings['geo_custom_headers'])) {
            $custom_headers = $this->parse_custom_headers($settings['geo_custom_headers']);
            $headers = array_merge($headers, $custom_headers);
        }
        
        $response = wp_remote_get($url, array(
            'timeout' => 10,
            'headers' => $headers
        ));
        
        if (is_wp_error($response)) {
            return null;
        }
        
        $body = wp_remote_retrieve_body($response);
        return json_decode($body, true);
    }
    
    private function get_proxy_data($user_ip) {
        $settings = get_option('awss_settings', $this->default_settings);
        $service = $settings['proxy_service'];
        
        switch ($service) {
            case 'ip-api':
                return $this->get_proxy_data_ipapi($user_ip);
            case 'proxycheck':
                return $this->get_proxy_data_proxycheck($user_ip);
            case 'custom':
                return $this->get_proxy_data_custom($user_ip);
            default:
                return $this->get_proxy_data_ipapi($user_ip);
        }
    }
    
    private function get_proxy_data_ipapi($user_ip) {
        $settings = get_option('awss_settings', $this->default_settings);
        
        // Using fields parameter for proxy and hosting detection
        $url = "http://ip-api.com/json/{$user_ip}?fields=16924672"; // proxy + hosting fields
        
        // Add API key if provided
        if (!empty($settings['ipapi_api_key'])) {
            $url .= "&key=" . $settings['ipapi_api_key'];
        }
        
        $headers = array(
            'User-Agent' => 'WordPress AWSS/' . $this->version
        );
        
        // Add custom headers if specified
        if (!empty($settings['proxy_custom_headers'])) {
            $custom_headers = $this->parse_custom_headers($settings['proxy_custom_headers']);
            $headers = array_merge($headers, $custom_headers);
        }
        
        $response = wp_remote_get($url, array(
            'timeout' => 10,
            'headers' => $headers
        ));
        
        if (is_wp_error($response)) {
            return null;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!$data || $data['status'] !== 'success') {
            return null;
        }
        
        return $data;
    }
    
    private function get_proxy_data_proxycheck($user_ip) {
        $settings = get_option('awss_settings', $this->default_settings);
        $api_key = $settings['proxycheck_api_key'];
        
        $url = "https://proxycheck.io/v2/{$user_ip}?vpn=1&asn=1&risk=1";
        if (!empty($api_key)) {
            $url .= "&key={$api_key}";
        }
        
        $headers = array(
            'User-Agent' => 'WordPress AWSS/' . $this->version
        );
        
        // Add custom headers if specified
        if (!empty($settings['proxy_custom_headers'])) {
            $custom_headers = $this->parse_custom_headers($settings['proxy_custom_headers']);
            $headers = array_merge($headers, $custom_headers);
        }
        
        $response = wp_remote_get($url, array(
            'timeout' => 10,
            'headers' => $headers
        ));
        
        if (is_wp_error($response)) {
            return null;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        return isset($data[$user_ip]) ? $data[$user_ip] : null;
    }
    
    private function get_proxy_data_custom($user_ip) {
        $settings = get_option('awss_settings', $this->default_settings);
        $custom_url = $settings['proxy_custom_url'];
        
        if (empty($custom_url)) {
            return null;
        }
        
        // Replace {IP} placeholder
        $url = str_replace('{IP}', $user_ip, $custom_url);
        
        $headers = array(
            'User-Agent' => 'WordPress AWSS/' . $this->version
        );
        
        // Add custom headers if specified
        if (!empty($settings['proxy_custom_headers'])) {
            $custom_headers = $this->parse_custom_headers($settings['proxy_custom_headers']);
            $headers = array_merge($headers, $custom_headers);
        }
        
        $response = wp_remote_get($url, array(
            'timeout' => 10,
            'headers' => $headers
        ));
        
        if (is_wp_error($response)) {
            return null;
        }
        
        $body = wp_remote_retrieve_body($response);
        return json_decode($body, true);
    }
    
    private function get_reputation_data($user_ip) {
        $settings = get_option('awss_settings', $this->default_settings);
        $service = $settings['reputation_service'];
        
        switch ($service) {
            case 'abuseipdb':
                return $this->get_reputation_data_abuseipdb($user_ip);
            case 'custom':
                return $this->get_reputation_data_custom($user_ip);
            default:
                return $this->get_reputation_data_abuseipdb($user_ip);
        }
    }
    
    private function get_reputation_data_abuseipdb($user_ip) {
        $settings = get_option('awss_settings', $this->default_settings);
        $api_key = $settings['abuseipdb_api_key'];
        
        if (empty($api_key)) {
            return null;
        }
        
        $url = add_query_arg(array(
            'ipAddress' => $user_ip,
            'maxAgeInDays' => 90,
            'verbose' => ''
        ), 'https://api.abuseipdb.com/api/v2/check');
        
        $headers = array(
            'Key' => $api_key,
            'Accept' => 'application/json',
            'User-Agent' => 'WordPress AWSS/' . $this->version
        );
        
        // Add custom headers if specified
        if (!empty($settings['abuseipdb_custom_headers'])) {
            $custom_headers = $this->parse_custom_headers($settings['abuseipdb_custom_headers']);
            $headers = array_merge($headers, $custom_headers);
        }
        
        $response = wp_remote_get($url, array(
            'timeout' => 10,
            'headers' => $headers
        ));
        
        if (is_wp_error($response)) {
            return null;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        return isset($data['data']) ? $data['data'] : null;
    }
    
    private function get_reputation_data_custom($user_ip) {
        $settings = get_option('awss_settings', $this->default_settings);
        $custom_url = $settings['reputation_custom_url'];
        
        if (empty($custom_url)) {
            return null;
        }
        
        // Replace {IP} placeholder
        $url = str_replace('{IP}', $user_ip, $custom_url);
        
        $headers = array(
            'User-Agent' => 'WordPress AWSS/' . $this->version
        );
        
        // Add custom headers if specified
        if (!empty($settings['reputation_custom_headers'])) {
            $custom_headers = $this->parse_custom_headers($settings['reputation_custom_headers']);
            $headers = array_merge($headers, $custom_headers);
        }
        
        $response = wp_remote_get($url, array(
            'timeout' => 10,
            'headers' => $headers
        ));
        
        if (is_wp_error($response)) {
            return null;
        }
        
        $body = wp_remote_retrieve_body($response);
        return json_decode($body, true);
    }
    
    private function parse_custom_headers($headers_string) {
        $headers = array();
        $lines = explode("\n", $headers_string);
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers[trim($parts[0])] = trim($parts[1]);
            }
        }
        
        return $headers;
    }
    
    // Brute force protection methods
    public function log_failed_attempt($username) {
        $settings = get_option('awss_settings', $this->default_settings);
        
        if (!$settings['brute_force_enabled']) {
            return;
        }
        
        $user_ip = $this->get_user_ip();
        
        if ($this->is_whitelisted($user_ip)) {
            return;
        }
        
        // Check rate limiting
        if (!$this->check_rate_limit($user_ip)) {
            $this->log_security_event($user_ip, 'rate_limited', 'Too many requests per minute');
            return;
        }
        
        $data = get_transient($this->brute_force_key) ?: array();
        $current_time = time();
        
        if (!isset($data[$user_ip])) {
            $data[$user_ip] = array(
                'attempts' => array(),
                'usernames' => array()
            );
        }
        
        // Add attempt
        $data[$user_ip]['attempts'][] = $current_time;
        
        // Add username
        if (!in_array($username, $data[$user_ip]['usernames'])) {
            $data[$user_ip]['usernames'][] = sanitize_user($username);
        }
        
        // Clean old attempts
        $data[$user_ip]['attempts'] = array_filter($data[$user_ip]['attempts'], function($time) use ($current_time, $settings) {
            return ($current_time - $time) < $settings['long_lockout_duration'];
        });
        
        // Check for lockout
        $recent_attempts = array_filter($data[$user_ip]['attempts'], function($time) use ($current_time, $settings) {
            return ($current_time - $time) < $settings['lockout_duration'];
        });
        
        if (count($recent_attempts) >= $settings['max_login_attempts']) {
            $lockout_duration = $settings['lockout_duration'];
            
            // Increase lockout for repeat offenders
            if (count($data[$user_ip]['attempts']) > $settings['max_login_attempts'] * 2) {
                $lockout_duration = $settings['long_lockout_duration'];
            }
            
            $this->block_ip_temporarily($user_ip, $lockout_duration, 'Brute force attempt');
            $this->log_security_event($user_ip, 'blocked_brute_force', "Locked out for {$lockout_duration} seconds after " . count($recent_attempts) . " failed attempts");
        }
        
        set_transient($this->brute_force_key, $data, $settings['long_lockout_duration']);
        $this->log_security_event($user_ip, 'failed_login', "Failed login attempt for user: {$username}");
    }
    
    public function check_brute_force($user, $username, $password) {
        if (is_wp_error($user) || empty($username) || empty($password)) {
            return $user;
        }
        
        $user_ip = $this->get_user_ip();
        
        if ($this->is_whitelisted($user_ip)) {
            return $user;
        }
        
        if ($this->is_temporarily_blocked($user_ip)) {
            $block_info = $this->get_block_info($user_ip);
            $remaining = $block_info ? max(0, strtotime($block_info['blocked_until']) - time()) : 0;
            $minutes = ceil($remaining / 60);
            
            return new WP_Error('too_many_attempts', 
                "Too many failed attempts. Try again in {$minutes} minutes.", 
                array('remaining' => $remaining)
            );
        }
        
        return $user;
    }
    
    public function clear_attempts($user_login, $user) {
        $user_ip = $this->get_user_ip();
        
        // Clear brute force data
        $data = get_transient($this->brute_force_key) ?: array();
        unset($data[$user_ip]);
        set_transient($this->brute_force_key, $data, get_option('awss_settings', $this->default_settings)['long_lockout_duration']);
        
        // Remove temporary blocks
        $this->unblock_ip($user_ip);
        
        $this->log_security_event($user_ip, 'successful_login', "Successful login for user: {$user_login}");
    }
    
    // 404 monitoring
    public function monitor_404() {
        $settings = get_option('awss_settings', $this->default_settings);
        
        if (!$settings['enable_404_monitoring'] || !is_404()) {
            return;
        }
        
        $user_ip = $this->get_user_ip();
        
        if ($this->is_whitelisted($user_ip)) {
            return;
        }
        
        $data = get_transient('awss_404_monitor') ?: array();
        $current_time = time();
        
        if (!isset($data[$user_ip])) {
            $data[$user_ip] = array();
        }
        
        $data[$user_ip][] = $current_time;
        
        // Clean old entries
        $data[$user_ip] = array_filter($data[$user_ip], function($time) use ($current_time) {
            return ($current_time - $time) < 60; // 1 minute window
        });
        
        // Check threshold
        if (count($data[$user_ip]) >= $settings['max_404_per_minute']) {
            $this->block_ip_temporarily($user_ip, 3600, '404 abuse detected'); // 1 hour block
            $this->log_security_event($user_ip, 'blocked_404_abuse', 'Too many 404 requests');
        }
        
        set_transient('awss_404_monitor', $data, 300); // 5 minutes
    }
    
    // Utility methods
    private function get_user_ip() {
        $ip_keys = array(
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        );
        
        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                $ip = trim($_SERVER[$key]);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
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
    
    private function is_whitelisted($ip) {
        $settings = get_option('awss_settings', $this->default_settings);
        $whitelist = array_filter(array_map('trim', explode("\n", $settings['whitelist_ips'])));
        $whitelist[] = '127.0.0.1';
        $whitelist[] = '::1';
        
        return in_array($ip, $whitelist);
    }
    
    private function is_blacklisted($ip) {
        $settings = get_option('awss_settings', $this->default_settings);
        $blacklist = array_filter(array_map('trim', explode("\n", $settings['blacklist_ips'])));
        
        return in_array($ip, $blacklist);
    }
    
    private function is_temporarily_blocked($ip) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'awss_blocked_ips';
        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE ip_address = %s AND (blocked_until IS NULL OR blocked_until > NOW())",
            $ip
        ));
        
        return $result !== null;
    }
    
    private function get_block_info($ip) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'awss_blocked_ips';
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE ip_address = %s",
            $ip
        ), ARRAY_A);
    }
    
    private function block_ip_temporarily($ip, $duration, $reason) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'awss_blocked_ips';
        $blocked_until = date('Y-m-d H:i:s', time() + $duration);
        
        $wpdb->replace($table_name, array(
            'ip_address' => $ip,
            'block_type' => 'temporary',
            'reason' => $reason,
            'blocked_until' => $blocked_until,
            'created_at' => current_time('mysql')
        ));
    }
    
    private function unblock_ip($ip) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'awss_blocked_ips';
        $wpdb->delete($table_name, array('ip_address' => $ip));
    }
    
    private function check_rate_limit($ip) {
        $settings = get_option('awss_settings', $this->default_settings);
        $data = get_transient('awss_rate_limit') ?: array();
        $current_time = time();
        
        if (!isset($data[$ip])) {
            $data[$ip] = array();
        }
        
        // Clean old entries
        $data[$ip] = array_filter($data[$ip], function($time) use ($current_time, $settings) {
            return ($current_time - $time) < $settings['rate_limit_window'];
        });
        
        // Check limit
        if (count($data[$ip]) >= $settings['max_attempts_per_minute']) {
            return false;
        }
        
        // Add current attempt
        $data[$ip][] = $current_time;
        set_transient('awss_rate_limit', $data, $settings['rate_limit_window'] * 2);
        
        return true;
    }
    
    private function has_valid_session() {
        $settings = get_option('awss_settings', $this->default_settings);
        return isset($_SESSION['awss_verified']) && $_SESSION['awss_verified'] > time() - $settings['session_duration'];
    }
    
    private function set_valid_session() {
        $_SESSION['awss_verified'] = time();
    }
    
    private function create_security_bypass_url() {
        $token = wp_generate_password(32, false);
        $tokens = get_transient('awss_bypass_tokens') ?: array();
        $tokens[$token] = time() + 300; // 5 minutes
        set_transient('awss_bypass_tokens', $tokens, 3600);
        
        return add_query_arg('awss_token', $token, wp_login_url());
    }
    
    private function verify_security_token($token) {
        $tokens = get_transient('awss_bypass_tokens') ?: array();
        
        if (!isset($tokens[$token])) {
            return false;
        }
        
        if ($tokens[$token] < time()) {
            unset($tokens[$token]);
            set_transient('awss_bypass_tokens', $tokens, 3600);
            return false;
        }
        
        return true;
    }
    
    public function add_security_token() {
        if (isset($_GET['awss_token']) && $this->verify_security_token($_GET['awss_token'])) {
            echo '<input type="hidden" name="awss_token" value="' . esc_attr($_GET['awss_token']) . '">';
        }
    }
    
    private function get_cached_result($ip) {
        $cache = get_transient($this->cache_key) ?: array();
        
        if (isset($cache[$ip])) {
            $cached_data = $cache[$ip];
            $settings = get_option('awss_settings', $this->default_settings);
            
            if (time() - $cached_data['timestamp'] < $settings['cache_duration']) {
                return array(
                    'allowed' => $cached_data['allowed'],
                    'reasons' => $cached_data['reasons']
                );
            }
        }
        
        return null;
    }
    
    private function cache_security_result($ip, $result) {
        $cache = get_transient($this->cache_key) ?: array();
        $settings = get_option('awss_settings', $this->default_settings);
        
        $cache[$ip] = array(
            'allowed' => $result['allowed'],
            'reasons' => $result['reasons'],
            'timestamp' => time(),
            'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 100)
        );
        
        set_transient($this->cache_key, $cache, $settings['cache_duration'] * 2);
    }
    
    private function log_security_event($ip, $action, $reason) {
        global $wpdb;
        
        $settings = get_option('awss_settings', $this->default_settings);
        
        if (!$settings['log_attempts']) {
            return;
        }
        
        $table_name = $wpdb->prefix . 'awss_ip_logs';
        
        $wpdb->insert($table_name, array(
            'ip_address' => $ip,
            'action' => $action,
            'reason' => $reason,
            'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 500),
            'timestamp' => current_time('mysql'),
            'additional_data' => json_encode(array(
                'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
                'referer' => $_SERVER['HTTP_REFERER'] ?? '',
                'method' => $_SERVER['REQUEST_METHOD'] ?? ''
            ))
        ));
    }
    
    private function show_access_denied($message) {
        status_header(403);
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title>Access Denied</title>
            <style>
                body { 
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; 
                    text-align: center; 
                    padding: 50px; 
                    background: #f8f9fa; 
                    color: #2c3e50;
                }
                .container { 
                    max-width: 400px; 
                    margin: 0 auto; 
                    background: white; 
                    padding: 40px; 
                    border-radius: 12px; 
                    box-shadow: 0 4px 20px rgba(0,0,0,0.08); 
                    border: 1px solid #e9ecef;
                }
                .error { color: #dc3545; font-size: 48px; margin-bottom: 20px; }
                h1 { color: #2c3e50; margin-bottom: 20px; font-weight: 600; }
                p { color: #6c757d; line-height: 1.6; }
                .btn { 
                    display: inline-block; 
                    background: #007bff; 
                    color: white; 
                    padding: 12px 24px; 
                    text-decoration: none; 
                    border-radius: 6px; 
                    margin-top: 20px; 
                    font-weight: 500;
                    transition: all 0.2s ease;
                }
                .btn:hover {
                    background: #0056b3;
                    transform: translateY(-1px);
                }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="error">🚫</div>
                <h1>Access Denied</h1>
                <p><?php echo esc_html($message); ?></p>
                <a href="<?php echo home_url(); ?>" class="btn">Return to Homepage</a>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
    
    public function cleanup_old_data() {
        global $wpdb;
        
        $settings = get_option('awss_settings', $this->default_settings);
        
        // Clean old logs
        if ($settings['cleanup_logs_days'] > 0) {
            $table_name = $wpdb->prefix . 'awss_ip_logs';
            $wpdb->query($wpdb->prepare(
                "DELETE FROM $table_name WHERE timestamp < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $settings['cleanup_logs_days']
            ));
        }
        
        // Clean expired blocks
        $table_name = $wpdb->prefix . 'awss_blocked_ips';
        $wpdb->query("DELETE FROM $table_name WHERE blocked_until IS NOT NULL AND blocked_until < NOW()");
        
        // Clean old cache
        $cache = get_transient($this->cache_key) ?: array();
        $current_time = time();
        $updated = false;
        
        foreach ($cache as $ip => $data) {
            if ($current_time - $data['timestamp'] > $settings['cache_duration']) {
                unset($cache[$ip]);
                $updated = true;
            }
        }
        
        if ($updated) {
            set_transient($this->cache_key, $cache, $settings['cache_duration'] * 2);
        }
    }
    
    // Dashboard widget
    public function add_dashboard_widget() {
        $settings = get_option('awss_settings', $this->default_settings);
        
        if ($settings['show_dashboard_widget'] && current_user_can('manage_options')) {
            wp_add_dashboard_widget(
                'awss_dashboard_widget',
                'Advanced Security Status',
                array($this, 'dashboard_widget_content')
            );
        }
    }
    
    public function dashboard_widget_content() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'awss_blocked_ips';
        $active_blocks = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE blocked_until IS NULL OR blocked_until > NOW()");
        
        $table_name = $wpdb->prefix . 'awss_ip_logs';
        $recent_blocks = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE action LIKE 'blocked_%' AND timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        
        $cache = get_transient($this->cache_key) ?: array();
        $cached_ips = count($cache);
        
        ?>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
            <div style="text-align: center; padding: 15px; background: #f8f9fa; border-radius: 6px;">
                <div style="font-size: 24px; font-weight: bold; color: #dc3545;"><?php echo $active_blocks; ?></div>
                <div style="font-size: 12px; color: #6c757d;">Active Blocks</div>
            </div>
            <div style="text-align: center; padding: 15px; background: #f8f9fa; border-radius: 6px;">
                <div style="font-size: 24px; font-weight: bold; color: #fd7e14;"><?php echo $recent_blocks; ?></div>
                <div style="font-size: 12px; color: #6c757d;">Blocks (24h)</div>
            </div>
        </div>
        
        <div style="text-align: center; margin-bottom: 15px;">
            <strong>Cached IPs:</strong> <?php echo $cached_ips; ?>
        </div>
        
        <div style="text-align: center;">
            <a href="<?php echo admin_url('options-general.php?page=awss-settings'); ?>" class="button button-primary button-small">
                View Security Dashboard
            </a>
        </div>
        <?php
    }
    
    // Admin interface
    public function admin_menu() {
        add_options_page(
            'Advanced Security Settings',
            'Advanced Security',
            'manage_options',
            'awss-settings',
            array($this, 'admin_page')
        );
    }
    
    public function admin_init() {
        register_setting('awss_settings', 'awss_settings');
    }
    
    // AJAX handlers
    public function ajax_get_ip_data() {
        check_ajax_referer('awss_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        global $wpdb;
        
        $page = intval($_POST['page'] ?? 1);
        $per_page = 20;
        $offset = ($page - 1) * $per_page;
        
        // Get logs
        $table_name = $wpdb->prefix . 'awss_ip_logs';
        $logs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name ORDER BY timestamp DESC LIMIT %d OFFSET %d",
            $per_page, $offset
        ), ARRAY_A);
        
        $total = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        
        // Get blocked IPs
        $table_name = $wpdb->prefix . 'awss_blocked_ips';
        $blocked_ips = $wpdb->get_results(
            "SELECT * FROM $table_name WHERE blocked_until IS NULL OR blocked_until > NOW() ORDER BY created_at DESC",
            ARRAY_A
        );
        
        // Get cached IPs
        $cache = get_transient($this->cache_key) ?: array();
        $cached_data = array();
        
        foreach ($cache as $ip => $data) {
            $cached_data[] = array(
                'ip' => $ip,
                'allowed' => $data['allowed'],
                'reasons' => implode(', ', $data['reasons']),
                'timestamp' => date('Y-m-d H:i:s', $data['timestamp']),
                'expires_in' => max(0, ($data['timestamp'] + get_option('awss_settings', $this->default_settings)['cache_duration']) - time())
            );
        }
        
        wp_send_json_success(array(
            'logs' => $logs,
            'blocked_ips' => $blocked_ips,
            'cached_ips' => $cached_data,
            'total_logs' => $total,
            'current_page' => $page,
            'total_pages' => ceil($total / $per_page)
        ));
    }
    
    public function ajax_unlock_ip() {
        check_ajax_referer('awss_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $ip = sanitize_text_field($_POST['ip']);
        
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            wp_send_json_error('Invalid IP address');
        }
        
        $this->unblock_ip($ip);
        $this->log_security_event($ip, 'manual_unblock', 'Manually unblocked by admin');
        
        wp_send_json_success('IP unblocked successfully');
    }
    
    public function ajax_block_ip() {
        check_ajax_referer('awss_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $ip = sanitize_text_field($_POST['ip']);
        $duration = intval($_POST['duration']);
        $reason = sanitize_text_field($_POST['reason']);
        
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            wp_send_json_error('Invalid IP address');
        }
        
        if ($duration > 0) {
            $this->block_ip_temporarily($ip, $duration, $reason);
        } else {
            // Permanent block - add to blacklist
            $settings = get_option('awss_settings', $this->default_settings);
            $blacklist = $settings['blacklist_ips'];
            if (!empty($blacklist)) {
                $blacklist .= "\n";
            }
            $blacklist .= $ip;
            $settings['blacklist_ips'] = $blacklist;
            update_option('awss_settings', $settings);
        }
        
        $this->log_security_event($ip, 'manual_block', "Manually blocked by admin: {$reason}");
        
        wp_send_json_success('IP blocked successfully');
    }
    
    public function ajax_test_api() {
        check_ajax_referer('awss_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $api_type = sanitize_text_field($_POST['api_type']);
        $test_ip = '8.8.8.8'; // Google DNS for testing
        
        $result = array();
        
        switch ($api_type) {
            case 'ipinfo':
                $data = $this->get_geo_data_ipinfo($test_ip);
                $result = array(
                    'success' => $data !== null,
                    'data' => $data,
                    'message' => $data ? 'IPInfo API working correctly' : 'IPInfo API failed or invalid key'
                );
                break;
                
            case 'ip-api':
                $data = $this->get_proxy_data_ipapi($test_ip);
                $result = array(
                    'success' => $data !== null,
                    'data' => $data,
                    'message' => $data ? 'IP-API working correctly' : 'IP-API failed'
                );
                break;
                
            case 'proxycheck':
                $data = $this->get_proxy_data_proxycheck($test_ip);
                $result = array(
                    'success' => $data !== null,
                    'data' => $data,
                    'message' => $data ? 'ProxyCheck API working correctly' : 'ProxyCheck API failed'
                );
                break;
                
            case 'abuseipdb':
                $data = $this->get_reputation_data_abuseipdb($test_ip);
                $result = array(
                    'success' => $data !== null,
                    'data' => $data,
                    'message' => $data ? 'AbuseIPDB API working correctly' : 'AbuseIPDB API failed or invalid key'
                );
                break;
                
            default:
                wp_send_json_error('Invalid API type');
        }
        
        wp_send_json_success($result);
    }
    
    public function ajax_clear_cache() {
        check_ajax_referer('awss_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        delete_transient($this->cache_key);
        delete_transient('awss_rate_limit');
        delete_transient('awss_404_monitor');
        delete_transient($this->brute_force_key);
        
        wp_send_json_success('Cache cleared successfully');
    }
    
    public function admin_page() {
        $settings = get_option('awss_settings', $this->default_settings);
        
        if (isset($_POST['submit'])) {
            $new_settings = array();
            
            // Boolean settings
            $boolean_fields = array('enabled', 'brute_force_enabled', 'proxy_detection_enabled', 
                                  'geo_blocking_enabled', 'ip_reputation_enabled', 'asn_blocking_enabled',
                                  'allow_on_api_fail', 'show_dashboard_widget', 'log_attempts', 'enable_404_monitoring', 'block_detected_threats');
            
            foreach ($boolean_fields as $field) {
                $new_settings[$field] = isset($_POST[$field]);
            }
            
            // Integer settings
            $integer_fields = array('max_login_attempts', 'lockout_duration', 'long_lockout_duration',
                                  'rate_limit_window', 'max_attempts_per_minute', 'session_duration',
                                  'cache_duration', 'min_abuse_confidence', 'cleanup_logs_days', 'max_404_per_minute');
            
            foreach ($integer_fields as $field) {
                $new_settings[$field] = intval($_POST[$field] ?? $this->default_settings[$field]);
            }
            
            // Text settings
            $text_fields = array('whitelist_ips', 'blacklist_ips', 'blocked_countries', 'blocked_asns',
                               'ipinfo_api_key', 'ipinfo_custom_url', 'ipinfo_custom_headers',
                               'abuseipdb_api_key', 'abuseipdb_custom_url', 'abuseipdb_custom_headers',
                               'proxycheck_api_key', 'ipapi_api_key', 'custom_block_message',
                               'proxy_service', 'proxy_custom_url', 'proxy_custom_headers',
                               'reputation_service', 'reputation_custom_url', 'reputation_custom_headers',
                               'geo_service', 'geo_custom_url', 'geo_custom_headers');
            
            foreach ($text_fields as $field) {
                $new_settings[$field] = sanitize_textarea_field($_POST[$field] ?? '');
            }
            
            update_option('awss_settings', $new_settings);
            echo '<div class="notice notice-success"><p>Settings saved successfully!</p></div>';
            $settings = $new_settings;
        }
        
        ?>
        <div class="wrap">
            <h1>🛡️ Advanced WordPress Security Suite v<?php echo $this->version; ?></h1>
            
            <div id="awss-dashboard" style="margin: 20px 0;">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px;">
                    <div class="awss-stat-card">
                        <div class="stat-number" id="active-blocks">-</div>
                        <div class="stat-label">Active Blocks</div>
                    </div>
                    <div class="awss-stat-card">
                        <div class="stat-number" id="recent-blocks">-</div>
                        <div class="stat-label">Blocks (24h)</div>
                    </div>
                    <div class="awss-stat-card">
                        <div class="stat-number" id="cached-ips">-</div>
                        <div class="stat-label">Cached IPs</div>
                    </div>
                    <div class="awss-stat-card">
                        <div class="stat-number" id="total-logs">-</div>
                        <div class="stat-label">Total Logs</div>
                    </div>
                </div>
                
                <div style="margin-bottom: 20px;">
                    <button type="button" id="refresh-data" class="button">🔄 Refresh Data</button>
                    <button type="button" id="clear-cache" class="button">🗑️ Clear Cache</button>
                    <button type="button" id="test-apis" class="button">🧪 Test APIs</button>
                </div>
            </div>
            
            <div class="nav-tab-wrapper">
                <a href="#general" class="nav-tab nav-tab-active">General Settings</a>
                <a href="#brute-force" class="nav-tab">Brute Force Protection</a>
                <a href="#proxy-detection" class="nav-tab">Proxy/VPN Detection</a>
                <a href="#geo-blocking" class="nav-tab">Geo Blocking</a>
                <a href="#ip-reputation" class="nav-tab">IP Reputation</a>
                <a href="#monitoring" class="nav-tab">Monitoring & Logs</a>
                <a href="#ip-management" class="nav-tab">IP Management</a>
            </div>
            
            <form method="post" id="awss-settings-form">
                <div id="general" class="tab-content active">
                    <h2>General Settings</h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row">Enable Security Suite</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="enabled" value="1" <?php checked($settings['enabled']); ?>>
                                    Enable all security features
                                </label>
                                <p class="description">Master switch for the entire security suite.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Block Detected Threats</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="block_detected_threats" value="1" <?php checked($settings['block_detected_threats']); ?>>
                                    Automatically block IPs that fail security checks
                                </label>
                                <p class="description">When enabled, IPs that fail security checks will be temporarily blocked for the cache duration.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Session Duration</th>
                            <td>
                                <input type="number" name="session_duration" value="<?php echo $settings['session_duration']; ?>" min="300" max="86400">
                                <p class="description">How long (in seconds) a verified session lasts. Default: 3600 (1 hour)</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Cache Duration</th>
                            <td>
                                <input type="number" name="cache_duration" value="<?php echo $settings['cache_duration']; ?>" min="300" max="86400">
                                <p class="description">How long (in seconds) to cache IP check results. Default: 14400 (4 hours)</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Allow on API Failure</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="allow_on_api_fail" value="1" <?php checked($settings['allow_on_api_fail']); ?>>
                                    Allow access when API calls fail
                                </label>
                                <p class="description">If unchecked, users will be blocked when external APIs are unavailable.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Custom Block Message</th>
                            <td>
                                <textarea name="custom_block_message" rows="3" class="large-text"><?php echo esc_textarea($settings['custom_block_message']); ?></textarea>
                                <p class="description">Message shown to blocked users.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Show Dashboard Widget</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="show_dashboard_widget" value="1" <?php checked($settings['show_dashboard_widget']); ?>>
                                    Show security status widget on WordPress dashboard
                                </label>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <div id="brute-force" class="tab-content">
                    <h2>Brute Force Protection</h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row">Enable Brute Force Protection</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="brute_force_enabled" value="1" <?php checked($settings['brute_force_enabled']); ?>>
                                    Protect against brute force login attempts
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Max Login Attempts</th>
                            <td>
                                <input type="number" name="max_login_attempts" value="<?php echo $settings['max_login_attempts']; ?>" min="3" max="20">
                                <p class="description">Maximum failed login attempts before lockout. Default: 5</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Lockout Duration</th>
                            <td>
                                <input type="number" name="lockout_duration" value="<?php echo $settings['lockout_duration']; ?>" min="300" max="86400">
                                <p class="description">Initial lockout duration in seconds. Default: 1800 (30 minutes)</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Long Lockout Duration</th>
                            <td>
                                <input type="number" name="long_lockout_duration" value="<?php echo $settings['long_lockout_duration']; ?>" min="3600" max="604800">
                                <p class="description">Extended lockout for repeat offenders in seconds. Default: 86400 (24 hours)</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Rate Limiting</th>
                            <td>
                                <input type="number" name="max_attempts_per_minute" value="<?php echo $settings['max_attempts_per_minute']; ?>" min="1" max="10"> attempts per 
                                <input type="number" name="rate_limit_window" value="<?php echo $settings['rate_limit_window']; ?>" min="30" max="300"> seconds
                                <p class="description">Rate limiting to prevent rapid-fire attacks.</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <div id="proxy-detection" class="tab-content">
                    <h2>Proxy/VPN Detection</h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row">Enable Proxy Detection</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="proxy_detection_enabled" value="1" <?php checked($settings['proxy_detection_enabled']); ?>>
                                    Block proxy, VPN, and Tor connections
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Proxy Detection Service</th>
                            <td>
                                <select name="proxy_service" id="proxy-service">
                                    <option value="ip-api" <?php selected($settings['proxy_service'], 'ip-api'); ?>>IP-API.com (Free - 1000/day)</option>
                                    <option value="proxycheck" <?php selected($settings['proxy_service'], 'proxycheck'); ?>>ProxyCheck.io (100/day free, API key for more)</option>
                                    <option value="custom" <?php selected($settings['proxy_service'], 'custom'); ?>>Custom API</option>
                                </select>
                                <p class="description">Choose your preferred proxy detection service.</p>
                            </td>
                        </tr>
                        <tr id="ipapi-key-row" style="<?php echo $settings['proxy_service'] === 'ip-api' ? '' : 'display:none;'; ?>">
                            <th scope="row">IP-API.com API Key</th>
                            <td>
                                <input type="text" name="ipapi_api_key" value="<?php echo esc_attr($settings['ipapi_api_key']); ?>" class="regular-text">
                                <button type="button" class="button test-api-btn" data-api="ip-api">Test API</button>
                                <p class="description">
                                    Optional API key from <a href="https://ip-api.com/" target="_blank">IP-API.com</a><br>
                                    Free tier: 1000 queries/day without key, unlimited with paid plans
                                </p>
                            </td>
                        </tr>
                        <tr id="proxycheck-key-row" style="<?php echo $settings['proxy_service'] === 'proxycheck' ? '' : 'display:none;'; ?>">
                            <th scope="row">ProxyCheck.io API Key</th>
                            <td>
                                <input type="text" name="proxycheck_api_key" value="<?php echo esc_attr($settings['proxycheck_api_key']); ?>" class="regular-text">
                                <button type="button" class="button test-api-btn" data-api="proxycheck">Test API</button>
                                <p class="description">
                                    Get your free API key from <a href="https://proxycheck.io/" target="_blank">ProxyCheck.io</a><br>
                                    Free tier: 100 queries/day without key, 1000/day with free key
                                </p>
                            </td>
                        </tr>
                        <tr id="custom-proxy-row" style="<?php echo $settings['proxy_service'] === 'custom' ? '' : 'display:none;'; ?>">
                            <th scope="row">Custom Proxy API</th>
                            <td>
                                <input type="url" name="proxy_custom_url" value="<?php echo esc_attr($settings['proxy_custom_url']); ?>" class="large-text" placeholder="https://api.example.com/check/{IP}">
                                <p class="description">Custom API URL. Use {IP} as placeholder for the IP address.</p>
                                <textarea name="proxy_custom_headers" rows="3" class="large-text" placeholder="Authorization: Bearer your-token&#10;X-API-Key: your-key"><?php echo esc_textarea($settings['proxy_custom_headers']); ?></textarea>
                                <p class="description">Custom headers (one per line, format: Header-Name: Value)</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <div id="geo-blocking" class="tab-content">
                    <h2>Geographic Blocking</h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row">Enable Geo Blocking</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="geo_blocking_enabled" value="1" <?php checked($settings['geo_blocking_enabled']); ?>>
                                    Block specific countries and ASNs
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Geo Service</th>
                            <td>
                                <select name="geo_service" id="geo-service">
                                    <option value="ipinfo" <?php selected($settings['geo_service'], 'ipinfo'); ?>>IPInfo.io</option>
                                    <option value="custom" <?php selected($settings['geo_service'], 'custom'); ?>>Custom API</option>
                                </select>
                                <p class="description">Choose your preferred geolocation service.</p>
                            </td>
                        </tr>
                        <tr id="ipinfo-settings" style="<?php echo $settings['geo_service'] === 'ipinfo' ? '' : 'display:none;'; ?>">
                            <th scope="row">IPInfo.io Settings</th>
                            <td>
                                <input type="text" name="ipinfo_api_key" value="<?php echo esc_attr($settings['ipinfo_api_key']); ?>" class="regular-text" placeholder="API Key">
                                <button type="button" class="button test-api-btn" data-api="ipinfo">Test API</button>
                                <p class="description">
                                    Get your API key from <a href="https://ipinfo.io/" target="_blank">IPInfo.io</a><br>
                                    <strong>Note:</strong> The lite version provides unlimited queries for basic location data.
                                </p>
                                <input type="url" name="ipinfo_custom_url" value="<?php echo esc_attr($settings['ipinfo_custom_url']); ?>" class="large-text" placeholder="Custom URL (optional)">
                                <p class="description">Custom API URL (leave empty for default)</p>
                                <textarea name="ipinfo_custom_headers" rows="2" class="large-text" placeholder="Authorization: Bearer token&#10;X-Custom-Header: value"><?php echo esc_textarea($settings['ipinfo_custom_headers']); ?></textarea>
                                <p class="description">Custom headers (one per line, format: Header-Name: Value)</p>
                            </td>
                        </tr>
                        <tr id="custom-geo-row" style="<?php echo $settings['geo_service'] === 'custom' ? '' : 'display:none;'; ?>">
                            <th scope="row">Custom Geo API</th>
                            <td>
                                <input type="url" name="geo_custom_url" value="<?php echo esc_attr($settings['geo_custom_url']); ?>" class="large-text" placeholder="https://api.example.com/geo/{IP}">
                                <p class="description">Custom API URL. Use {IP} as placeholder for the IP address.</p>
                                <textarea name="geo_custom_headers" rows="3" class="large-text" placeholder="Authorization: Bearer your-token&#10;X-API-Key: your-key"><?php echo esc_textarea($settings['geo_custom_headers']); ?></textarea>
                                <p class="description">Custom headers (one per line, format: Header-Name: Value)</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Blocked Countries</th>
                            <td>
                                <input type="text" name="blocked_countries" value="<?php echo esc_attr($settings['blocked_countries']); ?>" class="large-text">
                                <p class="description">
                                    Comma-separated country codes (e.g., CN,RU,IR,KP)<br>
                                    Use ISO 3166-1 alpha-2 codes
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Blocked ASNs</th>
                            <td>
                                <textarea name="blocked_asns" rows="3" class="large-text"><?php echo esc_textarea($settings['blocked_asns']); ?></textarea>
                                <p class="description">
                                    Comma-separated ASN numbers (e.g., AS13335,AS15169)<br>
                                    Block specific hosting providers or networks
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Enable ASN Blocking</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="asn_blocking_enabled" value="1" <?php checked($settings['asn_blocking_enabled']); ?>>
                                    Block specified ASNs
                                </label>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <div id="ip-reputation" class="tab-content">
                    <h2>IP Reputation Checking</h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row">Enable IP Reputation</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="ip_reputation_enabled" value="1" <?php checked($settings['ip_reputation_enabled']); ?>>
                                    Check IP reputation against abuse databases
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Reputation Service</th>
                            <td>
                                <select name="reputation_service" id="reputation-service">
                                    <option value="abuseipdb" <?php selected($settings['reputation_service'], 'abuseipdb'); ?>>AbuseIPDB (1000/day free)</option>
                                    <option value="custom" <?php selected($settings['reputation_service'], 'custom'); ?>>Custom API</option>
                                </select>
                                <p class="description">Choose your preferred IP reputation service.</p>
                            </td>
                        </tr>
                        <tr id="abuseipdb-settings" style="<?php echo $settings['reputation_service'] === 'abuseipdb' ? '' : 'display:none;'; ?>">
                            <th scope="row">AbuseIPDB Settings</th>
                            <td>
                                <input type="text" name="abuseipdb_api_key" value="<?php echo esc_attr($settings['abuseipdb_api_key']); ?>" class="regular-text" placeholder="API Key">
                                <button type="button" class="button test-api-btn" data-api="abuseipdb">Test API</button>
                                <p class="description">
                                    Get your free API key from <a href="https://www.abuseipdb.com/" target="_blank">AbuseIPDB</a><br>
                                    Free tier: 1000 queries/day
                                </p>
                                <input type="url" name="abuseipdb_custom_url" value="<?php echo esc_attr($settings['abuseipdb_custom_url']); ?>" class="large-text" placeholder="Custom URL (optional)">
                                <p class="description">Custom API URL (leave empty for default)</p>
                                <textarea name="abuseipdb_custom_headers" rows="2" class="large-text" placeholder="Authorization: Bearer token&#10;X-Custom-Header: value"><?php echo esc_textarea($settings['abuseipdb_custom_headers']); ?></textarea>
                                <p class="description">Custom headers (one per line, format: Header-Name: Value)</p>
                            </td>
                        </tr>
                        <tr id="custom-reputation-row" style="<?php echo $settings['reputation_service'] === 'custom' ? '' : 'display:none;'; ?>">
                            <th scope="row">Custom Reputation API</th>
                            <td>
                                <input type="url" name="reputation_custom_url" value="<?php echo esc_attr($settings['reputation_custom_url']); ?>" class="large-text" placeholder="https://api.example.com/reputation/{IP}">
                                <p class="description">Custom API URL. Use {IP} as placeholder for the IP address.</p>
                                <textarea name="reputation_custom_headers" rows="3" class="large-text" placeholder="Authorization: Bearer your-token&#10;X-API-Key: your-key"><?php echo esc_textarea($settings['reputation_custom_headers']); ?></textarea>
                                <p class="description">Custom headers (one per line, format: Header-Name: Value)</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Minimum Abuse Confidence</th>
                            <td>
                                <input type="number" name="min_abuse_confidence" value="<?php echo $settings['min_abuse_confidence']; ?>" min="0" max="100">%
                                <p class="description">Block IPs with abuse confidence above this threshold. Default: 75%</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <div id="monitoring" class="tab-content">
                    <h2>Monitoring & Logging</h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row">Enable Logging</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="log_attempts" value="1" <?php checked($settings['log_attempts']); ?>>
                                    Log all security events
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Log Retention</th>
                            <td>
                                <input type="number" name="cleanup_logs_days" value="<?php echo $settings['cleanup_logs_days']; ?>" min="1" max="365"> days
                                <p class="description">Automatically delete logs older than this many days. Default: 30</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">404 Monitoring</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="enable_404_monitoring" value="1" <?php checked($settings['enable_404_monitoring']); ?>>
                                    Monitor and block excessive 404 requests
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">404 Threshold</th>
                            <td>
                                <input type="number" name="max_404_per_minute" value="<?php echo $settings['max_404_per_minute']; ?>" min="5" max="50"> requests per minute
                                <p class="description">Block IPs that generate too many 404 errors. Default: 10</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <div id="ip-management" class="tab-content">
                    <h2>IP Management</h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row">Whitelisted IPs</th>
                            <td>
                                <textarea name="whitelist_ips" rows="5" class="large-text"><?php echo esc_textarea($settings['whitelist_ips']); ?></textarea>
                                <p class="description">One IP per line. These IPs will always be allowed access.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Blacklisted IPs</th>
                            <td>
                                <textarea name="blacklist_ips" rows="5" class="large-text"><?php echo esc_textarea($settings['blacklist_ips']); ?></textarea>
                                <p class="description">One IP per line. These IPs will always be blocked.</p>
                            </td>
                        </tr>
                    </table>
                    
                    <h3>Manual IP Management</h3>
                    <div style="background: #f9f9f9; padding: 20px; border: 1px solid #ddd; border-radius: 5px;">
                        <div style="display: flex; gap: 10px; align-items: center; margin-bottom: 15px;">
                            <input type="text" id="manual-ip" placeholder="Enter IP address" style="width: 200px;">
                            <select id="block-duration">
                                <option value="3600">1 Hour</option>
                                <option value="86400">24 Hours</option>
                                <option value="604800">7 Days</option>
                                <option value="0">Permanent</option>
                            </select>
                            <input type="text" id="block-reason" placeholder="Reason" style="width: 200px;">
                            <button type="button" id="block-ip-btn" class="button">Block IP</button>
                            <button type="button" id="unblock-ip-btn" class="button">Unblock IP</button>
                        </div>
                    </div>
                </div>
                
                <?php submit_button('Save Settings', 'primary', 'submit'); ?>
            </form>
            
            <!-- Data Tables -->
            <div style="margin-top: 40px;">
                <div class="nav-tab-wrapper">
                    <a href="#blocked-ips-tab" class="nav-tab nav-tab-active">Blocked IPs</a>
                    <a href="#cached-ips-tab" class="nav-tab">Cached Results</a>
                    <a href="#security-logs-tab" class="nav-tab">Security Logs</a>
                </div>
                
                <div id="blocked-ips-tab" class="tab-content active">
                    <h3>Currently Blocked IPs</h3>
                    <div id="blocked-ips-table">Loading...</div>
                </div>
                
                <div id="cached-ips-tab" class="tab-content">
                    <h3>Cached IP Results</h3>
                    <div id="cached-ips-table">Loading...</div>
                </div>
                
                <div id="security-logs-tab" class="tab-content">
                    <h3>Security Event Logs</h3>
                    <div id="security-logs-table">Loading...</div>
                    <div id="logs-pagination" style="margin-top: 20px; text-align: center;"></div>
                </div>
            </div>
        </div>
        
        <!-- API Test Modal -->
        <div id="api-test-modal" style="display: none;">
            <div style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 100000;">
                <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 30px; border-radius: 8px; max-width: 600px; width: 90%;">
                    <h3>API Test Results</h3>
                    <div id="api-test-content">Testing...</div>
                    <div style="text-align: right; margin-top: 20px;">
                        <button type="button" id="close-modal" class="button">Close</button>
                    </div>
                </div>
            </div>
        </div>
        
        <style>
        .awss-stat-card {
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .stat-number {
            font-size: 32px;
            font-weight: bold;
            color: #2271b1;
            margin-bottom: 5px;
        }
        .stat-label {
            font-size: 14px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .tab-content {
            display: none;
            background: white;
            padding: 20px;
            border: 1px solid #ddd;
            border-top: none;
        }
        .tab-content.active {
            display: block;
        }
        .nav-tab.nav-tab-active {
            background: white;
            border-bottom: 1px solid white;
        }
        .awss-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        .awss-table th,
        .awss-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        .awss-table th {
            background: #f9f9f9;
            font-weight: 600;
        }
        .awss-table tr:hover {
            background: #f5f5f5;
        }
        .status-allowed {
            color: #46b450;
            font-weight: 600;
        }
        .status-blocked {
            color: #dc3232;
            font-weight: 600;
        }
        .action-btn {
            padding: 4px 8px;
            font-size: 12px;
            margin: 0 2px;
        }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            let currentPage = 1;
            
            // Tab switching
            $('.nav-tab').click(function(e) {
                e.preventDefault();
                const target = $(this).attr('href');
                
                // Update nav tabs
                $(this).siblings().removeClass('nav-tab-active');
                $(this).addClass('nav-tab-active');
                
                // Update content
                $('.tab-content').removeClass('active');
                $(target).addClass('active');
            });
            
            // Service selection handlers
            $('#proxy-service').change(function() {
                const service = $(this).val();
                $('#ipapi-key-row').toggle(service === 'ip-api');
                $('#proxycheck-key-row').toggle(service === 'proxycheck');
                $('#custom-proxy-row').toggle(service === 'custom');
            });
            
            $('#geo-service').change(function() {
                const service = $(this).val();
                $('#ipinfo-settings').toggle(service === 'ipinfo');
                $('#custom-geo-row').toggle(service === 'custom');
            });
            
            $('#reputation-service').change(function() {
                const service = $(this).val();
                $('#abuseipdb-settings').toggle(service === 'abuseipdb');
                $('#custom-reputation-row').toggle(service === 'custom');
            });
            
            // Load initial data
            loadDashboardData();
            
            // Refresh data button
            $('#refresh-data').click(function() {
                loadDashboardData();
            });
            
            // Clear cache button
            $('#clear-cache').click(function() {
                if (confirm('Are you sure you want to clear all cached data?')) {
                    $.post(ajaxurl, {
                        action: 'awss_clear_cache',
                        nonce: '<?php echo wp_create_nonce('awss_nonce'); ?>'
                    }, function(response) {
                        if (response.success) {
                            alert('Cache cleared successfully');
                            loadDashboardData();
                        } else {
                            alert('Error: ' + response.data);
                        }
                    });
                }
            });
            
            // API testing
            $('.test-api-btn').click(function() {
                const apiType = $(this).data('api');
                testAPI(apiType);
            });
            
            $('#test-apis').click(function() {
                testAPI('all');
            });
            
            // Manual IP management
            $('#block-ip-btn').click(function() {
                const ip = $('#manual-ip').val();
                const duration = $('#block-duration').val();
                const reason = $('#block-reason').val() || 'Manually blocked';
                
                if (!ip) {
                    alert('Please enter an IP address');
                    return;
                }
                
                $.post(ajaxurl, {
                    action: 'awss_block_ip',
                    nonce: '<?php echo wp_create_nonce('awss_nonce'); ?>',
                    ip: ip,
                    duration: duration,
                    reason: reason
                }, function(response) {
                    if (response.success) {
                        alert('IP blocked successfully');
                        $('#manual-ip, #block-reason').val('');
                        loadDashboardData();
                    } else {
                        alert('Error: ' + response.data);
                    }
                });
            });
            
            $('#unblock-ip-btn').click(function() {
                const ip = $('#manual-ip').val();
                
                if (!ip) {
                    alert('Please enter an IP address');
                    return;
                }
                
                $.post(ajaxurl, {
                    action: 'awss_unlock_ip',
                    nonce: '<?php echo wp_create_nonce('awss_nonce'); ?>',
                    ip: ip
                }, function(response) {
                    if (response.success) {
                        alert('IP unblocked successfully');
                        $('#manual-ip').val('');
                        loadDashboardData();
                    } else {
                        alert('Error: ' + response.data);
                    }
                });
            });
            
            // Modal handling
            $('#close-modal').click(function() {
                $('#api-test-modal').hide();
            });
            
            function loadDashboardData() {
                $.post(ajaxurl, {
                    action: 'awss_get_ip_data',
                    nonce: '<?php echo wp_create_nonce('awss_nonce'); ?>',
                    page: currentPage
                }, function(response) {
                    if (response.success) {
                        const data = response.data;
                        
                        // Update stats
                        $('#active-blocks').text(data.blocked_ips.length);
                        $('#recent-blocks').text(data.logs.filter(log => 
                            log.action.includes('blocked') && 
                            new Date(log.timestamp) > new Date(Date.now() - 24*60*60*1000)
                        ).length);
                        $('#cached-ips').text(data.cached_ips.length);
                        $('#total-logs').text(data.total_logs);
                        
                        // Update tables
                        updateBlockedIPsTable(data.blocked_ips);
                        updateCachedIPsTable(data.cached_ips);
                        updateSecurityLogsTable(data.logs);
                        updatePagination(data.current_page, data.total_pages);
                    }
                });
            }
            
            function updateBlockedIPsTable(blockedIPs) {
                let html = '<table class="awss-table">';
                html += '<thead><tr><th>IP Address</th><th>Block Type</th><th>Reason</th><th>Blocked Until</th><th>Actions</th></tr></thead><tbody>';
                
                if (blockedIPs.length === 0) {
                    html += '<tr><td colspan="5" style="text-align: center; color: #666;">No blocked IPs</td></tr>';
                } else {
                    blockedIPs.forEach(function(ip) {
                        const blockedUntil = ip.blocked_until ? new Date(ip.blocked_until).toLocaleString() : 'Permanent';
                        html += `<tr>
                            <td><code>${ip.ip_address}</code></td>
                            <td>${ip.block_type}</td>
                            <td>${ip.reason}</td>
                            <td>${blockedUntil}</td>
                            <td>
                                <button class="button action-btn unblock-btn" data-ip="${ip.ip_address}">Unblock</button>
                            </td>
                        </tr>`;
                    });
                }
                
                html += '</tbody></table>';
                $('#blocked-ips-table').html(html);
                
                // Bind unblock buttons
                $('.unblock-btn').click(function() {
                    const ip = $(this).data('ip');
                    if (confirm(`Are you sure you want to unblock ${ip}?`)) {
                        $.post(ajaxurl, {
                            action: 'awss_unlock_ip',
                            nonce: '<?php echo wp_create_nonce('awss_nonce'); ?>',
                            ip: ip
                        }, function(response) {
                            if (response.success) {
                                loadDashboardData();
                            } else {
                                alert('Error: ' + response.data);
                            }
                        });
                    }
                });
            }
            
            function updateCachedIPsTable(cachedIPs) {
                let html = '<table class="awss-table">';
                html += '<thead><tr><th>IP Address</th><th>Status</th><th>Reasons</th><th>Cached Time</th><th>Expires In</th></tr></thead><tbody>';
                
                if (cachedIPs.length === 0) {
                    html += '<tr><td colspan="5" style="text-align: center; color: #666;">No cached IPs</td></tr>';
                } else {
                    cachedIPs.forEach(function(ip) {
                        const statusClass = ip.allowed ? 'status-allowed' : 'status-blocked';
                        const statusText = ip.allowed ? '✅ Allowed' : '🚫 Blocked';
                        const expiresIn = Math.floor(ip.expires_in / 60) + ' min';
                        
                        html += `<tr>
                            <td><code>${ip.ip}</code></td>
                            <td class="${statusClass}">${statusText}</td>
                            <td>${ip.reasons}</td>
                            <td>${ip.timestamp}</td>
                            <td>${expiresIn}</td>
                        </tr>`;
                    });
                }
                
                html += '</tbody></table>';
                $('#cached-ips-table').html(html);
            }
            
            function updateSecurityLogsTable(logs) {
                let html = '<table class="awss-table">';
                html += '<thead><tr><th>IP Address</th><th>Action</th><th>Reason</th><th>Timestamp</th><th>User Agent</th></tr></thead><tbody>';
                
                if (logs.length === 0) {
                    html += '<tr><td colspan="5" style="text-align: center; color: #666;">No logs found</td></tr>';
                } else {
                    logs.forEach(function(log) {
                        const actionClass = log.action.includes('blocked') ? 'status-blocked' : 
                                          log.action.includes('allowed') ? 'status-allowed' : '';
                        
                        html += `<tr>
                            <td><code>${log.ip_address}</code></td>
                            <td class="${actionClass}">${log.action}</td>
                            <td>${log.reason}</td>
                            <td>${new Date(log.timestamp).toLocaleString()}</td>
                            <td style="font-size: 11px; max-width: 200px; word-break: break-all;">${log.user_agent.substring(0, 50)}...</td>
                        </tr>`;
                    });
                }
                
                html += '</tbody></table>';
                $('#security-logs-table').html(html);
            }
            
            function updatePagination(current, total) {
                if (total <= 1) {
                    $('#logs-pagination').html('');
                    return;
                }
                
                let html = '';
                
                if (current > 1) {
                    html += `<button class="button page-btn" data-page="${current - 1}">« Previous</button> `;
                }
                
                for (let i = Math.max(1, current - 2); i <= Math.min(total, current + 2); i++) {
                    const activeClass = i === current ? 'button-primary' : '';
                    html += `<button class="button page-btn ${activeClass}" data-page="${i}">${i}</button> `;
                }
                
                if (current < total) {
                    html += `<button class="button page-btn" data-page="${current + 1}">Next »</button>`;
                }
                
                $('#logs-pagination').html(html);
                
                $('.page-btn').click(function() {
                    currentPage = parseInt($(this).data('page'));
                    loadDashboardData();
                });
            }
            
            function testAPI(apiType) {
                $('#api-test-modal').show();
                $('#api-test-content').html('Testing API...');
                
                if (apiType === 'all') {
                    testAllAPIs();
                } else {
                    $.post(ajaxurl, {
                        action: 'awss_test_api',
                        nonce: '<?php echo wp_create_nonce('awss_nonce'); ?>',
                        api_type: apiType
                    }, function(response) {
                        if (response.success) {
                            const result = response.data;
                            let html = `<h4>${apiType.toUpperCase()} API Test</h4>`;
                            html += `<p><strong>Status:</strong> ${result.success ? '✅ Success' : '❌ Failed'}</p>`;
                            html += `<p><strong>Message:</strong> ${result.message}</p>`;
                            
                            if (result.data) {
                                html += '<h5>Sample Response:</h5>';
                                html += `<pre style="background: #f5f5f5; padding: 10px; border-radius: 4px; max-height: 200px; overflow-y: auto;">${JSON.stringify(result.data, null, 2)}</pre>`;
                            }
                            
                            $('#api-test-content').html(html);
                        } else {
                            $('#api-test-content').html(`<p style="color: red;">Error: ${response.data}</p>`);
                        }
                    });
                }
            }
            
            function testAllAPIs() {
                const apis = ['ipinfo', 'ip-api', 'proxycheck', 'abuseipdb'];
                let results = {};
                let completed = 0;
                
                $('#api-test-content').html('<h4>Testing All APIs...</h4><div id="test-progress"></div>');
                
                apis.forEach(function(api) {
                    $.post(ajaxurl, {
                        action: 'awss_test_api',
                        nonce: '<?php echo wp_create_nonce('awss_nonce'); ?>',
                        api_type: api
                    }, function(response) {
                        completed++;
                        results[api] = response.success ? response.data : {success: false, message: response.data};
                        
                        $('#test-progress').html(`Progress: ${completed}/${apis.length}`);
                        
                        if (completed === apis.length) {
                            displayAllAPIResults(results);
                        }
                    });
                });
            }
            
            function displayAllAPIResults(results) {
                let html = '<h4>API Test Results</h4>';
                
                Object.keys(results).forEach(function(api) {
                    const result = results[api];
                    html += `<div style="margin-bottom: 20px; padding: 15px; border: 1px solid #ddd; border-radius: 4px;">`;
                    html += `<h5>${api.toUpperCase()} API</h5>`;
                    html += `<p><strong>Status:</strong> ${result.success ? '✅ Success' : '❌ Failed'}</p>`;
                    html += `<p><strong>Message:</strong> ${result.message}</p>`;
                    html += '</div>';
                });
                
                $('#api-test-content').html(html);
            }
            
            // Auto-refresh every 30 seconds
            setInterval(function() {
                loadDashboardData();
            }, 30000);
        });
        </script>
        <?php
    }
    
    public function cleanup_on_deactivation() {
        // Clear scheduled events
        wp_clear_scheduled_hook('awss_cleanup_hook');
        
        // Clear transients
        delete_transient($this->cache_key);
        delete_transient($this->rate_limit_key);
        delete_transient($this->brute_force_key);
        delete_transient('awss_rate_limit');
        delete_transient('awss_404_monitor');
        delete_transient('awss_bypass_tokens');
    }
}

// Initialize the plugin
new AdvancedWordPressSecurity();

// Activation hook
register_activation_hook(__FILE__, function() {
    // Create tables and set default options
    $plugin = new AdvancedWordPressSecurity();
    $plugin->create_tables();
});

// Deactivation hook
register_deactivation_hook(__FILE__, function() {
    $plugin = new AdvancedWordPressSecurity();
    $plugin->cleanup_on_deactivation();
});

// Add custom login styling
add_action('login_head', function() {
    echo '<style>
        .login-error { 
            border-left: 4px solid #dc3232; 
            padding: 12px; 
            margin-left: 0; 
            margin-bottom: 20px; 
            background-color: #fff; 
            box-shadow: 0 1px 1px 0 rgba(0,0,0,.1); 
        }
        .awss-security-notice {
            background: #ffffff;
            border: 1px solid #e9ecef;
            color: #2c3e50;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            text-align: center;
            font-size: 14px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
    </style>';
});

?>
