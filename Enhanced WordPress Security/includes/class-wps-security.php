<?php
class WPS_Security {
    private $options;
    private static $turnstile_script_loaded = false;
    private static $turnstile_login_added = false;
    private static $turnstile_lostpassword_added = false;
    private static $turnstile_comment_added = false;
	private static $turnstile_register_added = false;

    public function __construct() {
        $this->options = get_option('wps_security_options', array());
    }

    public function init() {
        // SVG Upload Support
        if ($this->get_option('enable_svg_upload')) {
            add_filter('upload_mimes', array($this, 'allow_svg_upload'));
            add_filter('wp_handle_upload_prefilter', array($this, 'sanitize_svg_upload'));
        }

        // REST API Restrictions
        if ($this->get_option('enable_rest_api_restrictions')) {
            add_filter('rest_authentication_errors', array($this, 'restrict_rest_api'));
            add_filter('rest_endpoints', array($this, 'restrict_user_endpoints'));
        }

        // Security Headers and Query Blocking
        if ($this->get_option('enable_security_headers')) {
            add_action('send_headers', array($this, 'add_security_headers'));
        }

        if ($this->get_option('enable_query_blocking')) {
            add_action('init', array($this, 'block_malicious_queries'));
        }

        // Comment Filtering
        if ($this->get_option('enable_comment_filtering')) {
            add_filter('preprocess_comment', array($this, 'block_url_comments'));
        }

        // Author Protection
        if ($this->get_option('enable_author_protection')) {
            add_action('wp', array($this, 'block_author_enumeration'));
            add_filter('wp_sitemaps_add_provider', array($this, 'remove_author_sitemap'), 10, 2);
        }

        // XML-RPC and Meta Tags
        if ($this->get_option('enable_xmlrpc_disable')) {
            add_filter('xmlrpc_enabled', '__return_false');
            add_filter('xmlrpc_methods', array($this, 'disable_xmlrpc_methods'));
            remove_action('wp_head', 'rsd_link');
            remove_action('wp_head', 'wlwmanifest_link');
            remove_action('wp_head', 'feed_links', 2);
            remove_action('wp_head', 'feed_links_extra', 3);
            remove_action('wp_head', 'adjacent_posts_rel_link_wp_head', 10, 0);
            remove_action('wp_head', 'wp_shortlink_wp_head', 10, 0);
        }

        // Disable File Editing
        if ($this->get_option('enable_file_edit_disable')) {
            if (!defined('DISALLOW_FILE_EDIT')) {
                define('DISALLOW_FILE_EDIT', true);
            }
            add_filter('wp_is_application_passwords_available', '__return_false');
        }

        // Sensitive File Protection
        if ($this->get_option('enable_sensitive_file_protection')) {
            add_action('init', array($this, 'block_sensitive_files'));
        }

        // Cloudflare Turnstile
        $this->init_turnstile();

        // File Integrity Monitoring
        if ($this->get_option('enable_file_integrity')) {
            add_action('wp_loaded', array($this, 'check_file_integrity'));
        }

        // Email Notifications
        if ($this->get_option('enable_email_notifications')) {
            add_action('wp_login', array($this, 'notify_successful_login'), 10, 2);
        }

        // Upload File Restrictions
        if ($this->get_option('enable_upload_restrictions')) {
            add_filter('wp_handle_upload_prefilter', array($this, 'restrict_upload_types'));
        }
    }

    private function init_turnstile() {
        $has_turnstile = $this->get_option('enable_turnstile_login') || 
                $this->get_option('enable_turnstile_lostpassword') || 
                $this->get_option('enable_turnstile_comments') ||
                $this->get_option('enable_turnstile_register');
        if (!$has_turnstile) {
            return;
        }

        // Load script only when needed
        if ($this->get_option('enable_turnstile_login')) {
            add_action('login_enqueue_scripts', array($this, 'enqueue_turnstile_script'));
            add_action('login_enqueue_scripts', array($this, 'login_style'));
            add_action('login_form', array($this, 'add_turnstile_to_login'));
            add_action('wp_authenticate_user', array($this, 'verify_turnstile_login'), 10, 2);
        }
        
        if ($this->get_option('enable_turnstile_lostpassword')) {
            add_action('login_enqueue_scripts', array($this, 'enqueue_turnstile_script'));
            add_action('lostpassword_form', array($this, 'add_turnstile_to_lostpassword'));
            add_action('lostpassword_post', array($this, 'verify_lostpassword_captcha'));
        }
        
        if ($this->get_option('enable_turnstile_comments')) {
            add_action('wp_enqueue_scripts', array($this, 'enqueue_turnstile_script_frontend'));
            add_filter('comment_form_submit_field', array($this, 'add_turnstile_to_comment_form'));
            add_filter('preprocess_comment', array($this, 'verify_comment_turnstile'));
        }
		
		if ($this->get_option('enable_turnstile_register')) {
    add_action('login_enqueue_scripts', array($this, 'enqueue_turnstile_script'));
    add_action('register_form', array($this, 'add_turnstile_to_register'));
    add_action('registration_errors', array($this, 'verify_turnstile_register'), 10, 3);
}
    }

    private function get_option($key) {
        return isset($this->options[$key]) ? $this->options[$key] : false;
    }

    public function allow_svg_upload($mimes) {
        $mimes['svg'] = 'image/svg+xml';
        return $mimes;
    }

    public function sanitize_svg_upload($file) {
        if ($file['type'] === 'image/svg+xml') {
            if (!file_exists($file['tmp_name'])) {
                $file['error'] = 'File does not exist';
                return $file;
            }

            $svg_content = file_get_contents($file['tmp_name']);
            if ($svg_content === false) {
                $file['error'] = 'Unable to read SVG file';
                return $file;
            }

            // Validate SVG structure
            if (!$this->is_valid_svg($svg_content)) {
                $file['error'] = 'Invalid SVG file structure';
                return $file;
            }

            // Use DOMDocument for safer parsing
            $dom = new DOMDocument();
            $dom->formatOutput = false;
            $dom->preserveWhiteSpace = false;
            
            // Suppress warnings for malformed XML
            $previous_setting = libxml_use_internal_errors(true);
            libxml_clear_errors();
            
            if (!$dom->loadXML($svg_content, LIBXML_NOENT | LIBXML_DTDLOAD | LIBXML_DTDATTR)) {
                libxml_use_internal_errors($previous_setting);
                $file['error'] = 'Invalid SVG XML structure';
                return $file;
            }
            
            libxml_use_internal_errors($previous_setting);

            // Remove dangerous elements and attributes
            $this->sanitize_svg_dom($dom);

            $sanitized_content = $dom->saveXML();
            
            // Additional regex cleanup for edge cases
            $sanitized_content = $this->final_svg_cleanup($sanitized_content);

            if (file_put_contents($file['tmp_name'], $sanitized_content) === false) {
                $file['error'] = 'Unable to save sanitized SVG';
                return $file;
            }
        }
        return $file;
    }

    private function is_valid_svg($content) {
        // Basic SVG validation
        if (stripos($content, '<svg') === false) {
            return false;
        }
        
        // Check for obviously malicious content
        $dangerous_patterns = array(
            '/<\?php/i',
            '/<script/i',
            '/javascript:/i',
            '/vbscript:/i',
            '/data:text\/html/i',
            '/eval\s*\(/i',
            '/expression\s*\(/i'
        );
        
        foreach ($dangerous_patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return false;
            }
        }
        
        return true;
    }

    private function sanitize_svg_dom($dom) {
        $xpath = new DOMXPath($dom);
        
        // Remove dangerous elements
        $dangerous_elements = array('script', 'object', 'embed', 'iframe', 'meta', 'link', 'style', 'foreignObject');
        foreach ($dangerous_elements as $element) {
            $nodes = $xpath->query('//' . $element);
            for ($i = $nodes->length - 1; $i >= 0; $i--) {
                $nodes->item($i)->parentNode->removeChild($nodes->item($i));
            }
        }

        // Remove dangerous attributes from all elements
        $dangerous_attributes = array(
            'onload', 'onerror', 'onclick', 'onmouseover', 'onmouseout', 'onfocus', 
            'onblur', 'onchange', 'onsubmit', 'onreset', 'onkeydown', 'onkeyup', 
            'onkeypress', 'onmousedown', 'onmouseup', 'onmousemove', 'onwheel',
            'ondblclick', 'oncontextmenu', 'ondrag', 'ondragend', 'ondragenter',
            'ondragleave', 'ondragover', 'ondragstart', 'ondrop'
        );

        $all_elements = $xpath->query('//*');
        foreach ($all_elements as $element) {
            foreach ($dangerous_attributes as $attr) {
                if ($element->hasAttribute($attr)) {
                    $element->removeAttribute($attr);
                }
            }
            
            // Check href and xlink:href attributes for dangerous protocols
            if ($element->hasAttribute('href')) {
                $href = $element->getAttribute('href');
                if (preg_match('/^(javascript|vbscript|data):/i', $href)) {
                    $element->removeAttribute('href');
                }
            }
            
            if ($element->hasAttribute('xlink:href')) {
                $href = $element->getAttribute('xlink:href');
                if (preg_match('/^(javascript|vbscript|data):/i', $href)) {
                    $element->removeAttribute('xlink:href');
                }
            }
        }
    }

    private function final_svg_cleanup($content) {
        // Remove any remaining dangerous patterns
        $patterns = array(
            '/(?:javascript|vbscript|data:text\/html)[^"\'\s>]*/i',
            '/expression\s*\([^)]*\)/i',
            '/url\s*\(\s*["\']?\s*(?:javascript|vbscript|data:)[^)]*\)/i'
        );
        
        foreach ($patterns as $pattern) {
            $content = preg_replace($pattern, '', $content);
        }
        
        return $content;
    }

    public function restrict_rest_api($result) {
        if (true === $result || is_wp_error($result)) {
            return $result;
        }
        
        // Allow access for logged-in users
        if (is_user_logged_in()) {
            return $result;
        }
        
        // Allow specific endpoints that are commonly needed
        $allowed_endpoints = array(
            '/wp/v2/posts',
            '/wp/v2/pages',
            '/wp/v2/categories',
            '/wp/v2/tags',
            '/wp/v2/media',
            '/contact-form-7/v1'
        );
        
        $current_route = isset($GLOBALS['wp']->query_vars['rest_route']) ? $GLOBALS['wp']->query_vars['rest_route'] : '';
        
        foreach ($allowed_endpoints as $endpoint) {
            if (strpos($current_route, $endpoint) === 0) {
                return $result;
            }
        }
        
        return new WP_Error(
            'rest_not_logged_in',
            __('You are not currently logged in.'),
            array('status' => 401)
        );
    }

    public function restrict_user_endpoints($endpoints) {
        // Remove user enumeration endpoints
        $user_endpoints = array(
            '/wp/v2/users',
            '/wp/v2/users/(?P<id>[\d]+)',
            '/wp/v2/users/me'
        );
        
        foreach ($user_endpoints as $endpoint) {
            if (isset($endpoints[$endpoint])) {
                unset($endpoints[$endpoint]);
            }
        }
        
        return $endpoints;
    }

    public function add_security_headers() {
        $headers = $this->get_option('custom_headers');
        if (is_array($headers)) {
            foreach ($headers as $header) {
                if (is_string($header) && !empty(trim($header))) {
                    // Validate header format (basic check)
                    if (strpos($header, ':') !== false && !headers_sent()) {
                        header(sanitize_text_field($header));
                    }
                }
            }
        }
    }

    public function block_malicious_queries() {
        // More targeted patterns - removed overly restrictive ones
        $blocked_patterns = array(
            '/eval\s*\(/i',
            '/phpinfo\s*\(/i',
            '/shell_exec/i',
            '/exec\s*\(/i',
            '/passthru\s*\(/i',
            '/\.\.\.[\/\\\\].*\.(php|phtml|php3|php4|php5|phar)/i', // More specific path traversal
            '/union\s+select/i',
            '/<script[^>]*>/i',
            '/javascript:\s*[^;]*/i'
        );

        $query_string = isset($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : '';
        $request_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';

        // Don't check user agent - too restrictive
        $content_to_check = $query_string . ' ' . $request_uri;

        foreach ($blocked_patterns as $pattern) {
            if (preg_match($pattern, $content_to_check)) {
                // Log the attempt
                error_log('WPS Security: Blocked malicious request - Pattern: ' . $pattern);
                
                wp_die(
                    'Suspicious request detected',
                    'Security Error',
                    array('response' => 403, 'back_link' => false)
                );
            }
        }
    }

    public function block_url_comments($commentdata) {
        if (!is_array($commentdata) || !isset($commentdata['comment_content'])) {
            return $commentdata;
        }

        $comment_content = $commentdata['comment_content'];
        
        // Skip checks for logged-in users with appropriate capabilities
        if (is_user_logged_in() && current_user_can('moderate_comments')) {
            return $commentdata;
        }
        
        // Get the current site's domain
        $site_url = get_site_url();
        $site_domain = parse_url($site_url, PHP_URL_HOST);
        
        // Remove www. prefix if present for comparison
        $site_domain = preg_replace('/^www\./', '', $site_domain);
        
        // Check for URLs and only allow the current site's domain
        if (preg_match_all('/(?:https?:\/\/|www\.)([a-z0-9][-a-z0-9]*[a-z0-9]*\.[a-z]{2,})/i', $comment_content, $matches)) {
            foreach ($matches[1] as $domain) {
                // Remove www. prefix from found domain for comparison
                $clean_domain = preg_replace('/^www\./', '', $domain);
                
                // Block if domain doesn't match the site's domain
                if ($clean_domain !== $site_domain) {
                    wp_die('Comments containing external URLs are not allowed.', 'Comment Blocked', array('response' => 403, 'back_link' => true));
                }
            }
        }

        // Block suspicious patterns (same as before)
        $suspicious_patterns = array(
            '/\[url=/i',
            '/\[link=/i',
            '/<a\s+href/i', 
            '/onclick\s*=/i',
            '/javascript:\s*[^;]/i',
            '/vbscript:/i',
            '/data:text\/html/i',
            '/eval\s*\(/i',
            '/<script[^>]*>/i',
            '/<iframe/i',
            '/<object/i',
            '/<embed/i',
            '/phpinfo\s*\(/i'
        );

        foreach ($suspicious_patterns as $pattern) {
            if (preg_match($pattern, $comment_content)) {
                wp_die('Comment blocked due to suspicious content.', 'Security Error', array('response' => 403, 'back_link' => true));
            }
        }
        
        return $commentdata;
    }

    public function block_author_enumeration() {
        // Block author pages for non-logged-in users
        if (is_author() && !is_user_logged_in()) {
            wp_redirect(home_url('/'), 301);
            exit;
        }
        
        // Block author enumeration via ?author= parameter
        if (!is_admin() && isset($_GET['author']) && !is_user_logged_in()) {
            wp_redirect(home_url('/'), 301);
            exit;
        }
    }

    public function remove_author_sitemap($provider, $name) {
        if ($name === 'users') {
            return false;
        }
        return $provider;
    }

    public function disable_xmlrpc_methods($methods) {
        // Remove potentially dangerous methods
        $dangerous_methods = array(
            'pingback.ping',
            'pingback.extensions.getPingbacks',
            'system.multicall',
            'system.listMethods',
            'system.getCapabilities'
        );
        
        foreach ($dangerous_methods as $method) {
            unset($methods[$method]);
        }
        
        return $methods;
    }

    public function block_sensitive_files() {
        $blocked_files = array(
            'wp-config.php', 'wp-config-sample.php', '.htaccess', '.htpasswd',
            'wp-settings.php', 'wp-load.php', 'install.php', 'wp-admin/install.php',
            'readme.html', 'readme.txt', 'license.txt',
            'error_log', 'debug.log', '.env'
        );

        $request_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        $parsed_uri = parse_url($request_uri, PHP_URL_PATH);
        
        if ($parsed_uri) {
            $file = basename($parsed_uri);
            
            // Check for blocked files
            if (in_array($file, $blocked_files)) {
                wp_die('Access denied', 'Security Error', array('response' => 403));
            }
            
            // Block access to hidden files/directories (but be more specific)
            if (preg_match('/^\.(?:git|svn|env|htaccess|htpasswd)/', $file)) {
                wp_die('Access denied', 'Security Error', array('response' => 403));
            }
        }
    }

    private function get_turnstile_keys() {
        $site_key = $this->get_option('turnstile_site_key');
        $secret_key = $this->get_option('turnstile_secret_key');
        
        return array(
            'site' => sanitize_text_field($site_key),
            'secret' => sanitize_text_field($secret_key)
        );
    }

    private function is_valid_captcha($captcha) {
        if (empty($captcha)) {
            return false;
        }

        $keys = $this->get_turnstile_keys();
        $secret_key = $keys['secret'];
        
        if (empty($secret_key)) {
            error_log('WPS Security: Turnstile secret key not configured');
            return false;
        }

        $url = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
        
        $response = wp_remote_post($url, array(
            'timeout' => 30,
            'sslverify' => true,
            'body' => array(
                'secret' => $secret_key,
                'response' => sanitize_text_field($captcha)
            )
        ));

        if (is_wp_error($response)) {
            error_log('WPS Security: Turnstile verification failed - ' . $response->get_error_message());
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);

        if (!is_array($result) || !isset($result['success'])) {
            error_log('WPS Security: Invalid Turnstile response format');
            return false;
        }

        if (!$result['success'] && isset($result['error-codes'])) {
            error_log('WPS Security: Turnstile verification failed - ' . implode(', ', $result['error-codes']));
        }

        return (bool) $result['success'];
    }

    public function enqueue_turnstile_script() {
        if (!self::$turnstile_script_loaded) {
            wp_enqueue_script('cloudflare-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', array(), null, true);
            self::$turnstile_script_loaded = true;
        }
    }

    public function enqueue_turnstile_script_frontend() {
        // Only load on pages where comments are enabled
        if (is_single() || is_page()) {
            if (comments_open() && !self::$turnstile_script_loaded) {
                wp_enqueue_script('cloudflare-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', array(), null, true);
                self::$turnstile_script_loaded = true;
            }
        }
    }

    public function login_style() {
        echo "<style>p.submit, p.forgetmenot {margin-top: 10px!important;}.login form{width: 303px;} div#login_error {width: 322px;}</style>";
    }

    public function add_turnstile_to_login() {
        // Prevent duplicate widget rendering
        if (self::$turnstile_login_added) {
            return;
        }
        
        $keys = $this->get_turnstile_keys();
        if (!empty($keys['site'])) {
            echo '<div class="cf-turnstile" data-sitekey="' . esc_attr($keys['site']) . '" data-callback="loginTurnstileCallback" id="login-turnstile"></div>';
            echo '<script>
                function loginTurnstileCallback(token) {
                    document.getElementById("login-turnstile").style.marginBottom = "10px";
                }
            </script>';
            self::$turnstile_login_added = true;
        }
    }

    public function verify_turnstile_login($user, $password) {
        if (is_wp_error($user)) {
            return $user;
        }

        $captcha = isset($_POST['cf-turnstile-response']) ? sanitize_text_field($_POST['cf-turnstile-response']) : '';
        
        if (!$this->is_valid_captcha($captcha)) {
            return new WP_Error('captcha_invalid', __('<center>Captcha verification failed. Please try again.</center>'));
        }
        
        return $user;
    }

    public function add_turnstile_to_lostpassword() {
        // Prevent duplicate widget rendering
        if (self::$turnstile_lostpassword_added) {
            return;
        }
        
        $keys = $this->get_turnstile_keys();
        if (!empty($keys['site'])) {
            echo '<div class="cf-turnstile" data-sitekey="' . esc_attr($keys['site']) . '" id="lostpw-turnstile"></div>';
            self::$turnstile_lostpassword_added = true;
        }
    }

    public function verify_lostpassword_captcha() {
        $captcha = isset($_POST['cf-turnstile-response']) ? sanitize_text_field($_POST['cf-turnstile-response']) : '';
        
        if (empty($captcha)) {
            wp_die(__('Please complete the CAPTCHA verification.'), 'CAPTCHA Required', array('response' => 400, 'back_link' => true));
        }
        
        if (!$this->is_valid_captcha($captcha)) {
            wp_die(__('CAPTCHA verification failed. Please try again.'), 'CAPTCHA Invalid', array('response' => 400, 'back_link' => true));
        }
    }

    public function add_turnstile_to_comment_form($submit_field) {
        // Prevent duplicate widget rendering
        if (self::$turnstile_comment_added) {
            return $submit_field;
        }
        
        $keys = $this->get_turnstile_keys();
        if (!empty($keys['site'])) {
            $turnstile_field = '<div class="cf-turnstile" data-sitekey="' . esc_attr($keys['site']) . '" style="margin-bottom: 10px;" id="comment-turnstile"></div>';
            self::$turnstile_comment_added = true;
            return $turnstile_field . $submit_field;
        }
        return $submit_field;
    }

    public function verify_comment_turnstile($commentdata) {
        // Skip verification for logged-in users with moderation capabilities
        if (is_user_logged_in() && current_user_can('moderate_comments')) {
            return $commentdata;
        }

        $captcha = isset($_POST['cf-turnstile-response']) ? sanitize_text_field($_POST['cf-turnstile-response']) : '';
        
        if (!$this->is_valid_captcha($captcha)) {
            wp_die(
                'CAPTCHA verification failed. Please complete the verification and try again.',
                'Comment Submission Error',
                array('response' => 403, 'back_link' => true)
            );
        }
        
        return $commentdata;
    }
	
	public function add_turnstile_to_register() {
    // Prevent duplicate widget rendering
    if (self::$turnstile_register_added) {
        return;
    }
    
    $keys = $this->get_turnstile_keys();
    if (!empty($keys['site'])) {
        echo '<div class="cf-turnstile" data-sitekey="' . esc_attr($keys['site']) . '" data-callback="registerTurnstileCallback" id="register-turnstile"></div>';
        echo '<script>
            function registerTurnstileCallback(token) {
                document.getElementById("register-turnstile").style.marginBottom = "10px";
            }
        </script>';
        self::$turnstile_register_added = true;
    }
}

public function verify_turnstile_register($errors, $sanitized_user_login, $user_email) {
    $captcha = isset($_POST['cf-turnstile-response']) ? sanitize_text_field($_POST['cf-turnstile-response']) : '';
    
    if (!$this->is_valid_captcha($captcha)) {
        $errors->add('captcha_invalid', __('<center>Captcha verification failed. Please try again.</center>'));
    }
    
    return $errors;
}

    public function check_file_integrity() {
        $core_files = array(
            ABSPATH . 'wp-config.php',
            ABSPATH . 'wp-settings.php',
            ABSPATH . 'wp-load.php',
            ABSPATH . '.htaccess'
        );
        
        $stored_hashes = get_option('wps_file_hashes', array());
        $current_hashes = array();
        $changes_detected = false;
        
        foreach ($core_files as $file) {
            if (file_exists($file)) {
                $current_hash = md5_file($file);
                $current_hashes[basename($file)] = $current_hash;
                
                if (isset($stored_hashes[basename($file)]) && 
                    $stored_hashes[basename($file)] !== $current_hash) {
                    $this->send_critical_alert('File Modified: ' . basename($file), 
                        'Critical file ' . basename($file) . ' has been modified.');
                    $changes_detected = true;
                }
            }
        }
        
        if ($changes_detected || empty($stored_hashes)) {
            update_option('wps_file_hashes', $current_hashes);
        }
    }

    public function notify_successful_login($user_login, $user) {
        $user_ip = $this->get_user_ip();
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'Unknown';
        
        $message = "Successful login detected:\n\n";
        $message .= "Username: " . $user_login . "\n";
        $message .= "IP Address: " . $user_ip . "\n";
        $message .= "User Agent: " . $user_agent . "\n";
        $message .= "Time: " . current_time('mysql') . "\n";
        $message .= "Site: " . get_site_url() . "\n";
        
        $this->send_critical_alert('Successful Login: ' . $user_login, $message);
    }

    public function restrict_upload_types($file) {
        $allowed_types = $this->get_option('allowed_upload_types');
        if (empty($allowed_types)) {
            return $file;
        }
        
        $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!in_array($file_extension, $allowed_types)) {
            $file['error'] = 'File type not allowed. Allowed types: ' . implode(', ', $allowed_types);
        }
        
        return $file;
    }

    private function send_critical_alert($subject, $message) {
        $admin_email = $this->get_option('notification_email');
        if (empty($admin_email)) {
            $admin_email = get_option('admin_email');
        }
        
        $full_subject = '[WP Security Alert] ' . $subject . ' - ' . get_bloginfo('name');
        
        wp_mail($admin_email, $full_subject, $message);
    }

    private function get_user_ip() {
        $ip_headers = array('HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR');
        
        foreach ($ip_headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'Unknown';
    }
}

// Initialize the plugin
if (class_exists('WPS_Security')) {
    $wps_security = new WPS_Security();
    add_action('init', array($wps_security, 'init'));
}
?>
