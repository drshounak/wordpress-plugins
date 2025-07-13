<?php
/**
 * Plugin Name: WP Starter Addon by TechWeirdo
 * Plugin URI: https://github.com/drshounak/wordpress-plugins/tree/main/wp-starter-addon
 * Description: Complete WordPress toolkit with Custom Scripts Manager, SMTP Mailer, Image Optimizer, and Email 2FA - all in one powerful plugin.
 * Version: 1.0.0
 * Author: TechWeirdo
 * Author URI: https://twitter.com/drshounakpal
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-starter-addon
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

// Define plugin constants
define('WPSA_VERSION', '1.0.0');
define('WPSA_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WPSA_PLUGIN_PATH', plugin_dir_path(__FILE__));

/**
 * Main WP Starter Addon Class
 */
class WPStarterAddon {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // Initialize modules based on settings
        add_action('plugins_loaded', array($this, 'init_modules'));
        
        // Activation hook
        register_activation_hook(__FILE__, array($this, 'activate'));
    }
    
    public function init() {
        load_plugin_textdomain('wp-starter-addon', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }
    
    public function activate() {
        // Set default module settings
        $default_settings = array(
            'custom_scripts' => '1',
            'smtp_mailer' => '1',
            'image_optimizer' => '1',
            'email_2fa' => '1'
        );
        add_option('wpsa_modules', $default_settings);
        
        // Initialize default settings for each module
        $this->init_default_settings();
    }
    
    private function init_default_settings() {
        // Custom Scripts Manager defaults
        add_option('csm_global_head_scripts', '');
        add_option('csm_global_body_scripts', '');
        
        // SMTP Mailer defaults
        $smtp_defaults = array(
            'smtp_host' => '',
            'smtp_port' => '587',
            'smtp_encryption' => 'tls',
            'smtp_auth' => 1,
            'smtp_username' => '',
            'smtp_password' => '',
            'from_email' => get_option('admin_email'),
            'from_name' => get_option('blogname'),
            'debug_mode' => 0,
        );
        add_option('ssm_settings', $smtp_defaults);
        
        // Image Optimizer defaults
        $image_defaults = array(
            'max_width' => 2400,
            'max_height' => 2400,
            'output_format' => 'webp',
            'quality' => 85,
            'keep_original' => false,
            'auto_convert' => true
        );
        add_option('aic_settings', $image_defaults);
        
        // Email 2FA defaults
        add_option('email_otp_login_enabled', '1');
        add_option('email_otp_login_expiry', '10');
        add_option('email_otp_login_rate_limit', '60');
    }
    
    public function init_modules() {
        $modules = get_option('wpsa_modules', array());
        
        if (!empty($modules['custom_scripts'])) {
            new WPSA_CustomScriptsManager();
        }
        
        if (!empty($modules['smtp_mailer'])) {
            new WPSA_SMTPMailer();
        }
        
        if (!empty($modules['image_optimizer'])) {
            new WPSA_ImageOptimizer();
        }
        
        if (!empty($modules['email_2fa'])) {
            new WPSA_Email2FA();
        }
    }
    
    public function add_admin_menu() {
        add_menu_page(
            'WP Starter Addon',
            'WP Starter Addon',
            'manage_options',
            'wp-starter-addon',
            array($this, 'main_admin_page'),
            'dashicons-admin-tools',
            30
        );
        
        // Add submenus for each module
        $modules = get_option('wpsa_modules', array());
        
        if (!empty($modules['custom_scripts'])) {
            add_submenu_page(
                'wp-starter-addon',
                'Custom Scripts',
                'Custom Scripts',
                'manage_options',
                'wpsa-custom-scripts',
                array($this, 'custom_scripts_page')
            );
        }
        
        if (!empty($modules['smtp_mailer'])) {
            add_submenu_page(
                'wp-starter-addon',
                'SMTP Mailer',
                'SMTP Mailer',
                'manage_options',
                'wpsa-smtp-mailer',
                array($this, 'smtp_mailer_page')
            );
        }
        
        if (!empty($modules['image_optimizer'])) {
            add_submenu_page(
                'wp-starter-addon',
                'Image Optimizer',
                'Image Optimizer',
                'manage_options',
                'wpsa-image-optimizer',
                array($this, 'image_optimizer_page')
            );
        }
        
        if (!empty($modules['email_2fa'])) {
            add_submenu_page(
                'wp-starter-addon',
                'Email 2FA',
                'Email 2FA',
                'manage_options',
                'wpsa-email-2fa',
                array($this, 'email_2fa_page')
            );
        }
    }
    
    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'wp-starter-addon') !== false) {
            wp_enqueue_script('jquery');
            wp_enqueue_style('wpsa-admin-style', WPSA_PLUGIN_URL . 'assets/admin.css', array(), WPSA_VERSION);
        }
    }
    
    public function main_admin_page() {
        if (isset($_POST['submit'])) {
            check_admin_referer('wpsa_modules_settings');
            
            $modules = array(
                'custom_scripts' => isset($_POST['custom_scripts']) ? '1' : '0',
                'smtp_mailer' => isset($_POST['smtp_mailer']) ? '1' : '0',
                'image_optimizer' => isset($_POST['image_optimizer']) ? '1' : '0',
                'email_2fa' => isset($_POST['email_2fa']) ? '1' : '0'
            );
            
            update_option('wpsa_modules', $modules);
            echo '<div class="notice notice-success"><p>Settings saved successfully! Please refresh the page to see menu changes.</p></div>';
        }
        
        $modules = get_option('wpsa_modules', array());
        ?>
        <div class="wrap">
            <h1>WP Starter Addon by TechWeirdo</h1>
            <p>Welcome to WP Starter Addon - your complete WordPress toolkit! Enable or disable modules as needed.</p>
            
            <form method="post" action="">
                <?php wp_nonce_field('wpsa_modules_settings'); ?>
                
                <div class="wpsa-modules-grid">
                    <div class="wpsa-module-card">
                        <h3>🔧 Custom Scripts Manager</h3>
                        <p>Add custom scripts to head or body for entire site or specific pages/posts</p>
                        <label>
                            <input type="checkbox" name="custom_scripts" value="1" <?php checked(!empty($modules['custom_scripts'])); ?>>
                            Enable Custom Scripts Manager
                        </label>
                    </div>
                    
                    <div class="wpsa-module-card">
                        <h3>📧 SMTP Mailer</h3>
                        <p>Professional SMTP plugin with custom SMTP server settings for reliable email delivery</p>
                        <label>
                            <input type="checkbox" name="smtp_mailer" value="1" <?php checked(!empty($modules['smtp_mailer'])); ?>>
                            Enable SMTP Mailer
                        </label>
                    </div>
                    
                    <div class="wpsa-module-card">
                        <h3>🖼️ Image Optimizer</h3>
                        <p>Converts uploaded images to WebP format automatically with customizable compression</p>
                        <label>
                            <input type="checkbox" name="image_optimizer" value="1" <?php checked(!empty($modules['image_optimizer'])); ?>>
                            Enable Image Optimizer
                        </label>
                    </div>
                    
                    <div class="wpsa-module-card">
                        <h3>🔐 Email 2FA</h3>
                        <p>Adds two-factor authentication using email OTP codes for enhanced login security</p>
                        <label>
                            <input type="checkbox" name="email_2fa" value="1" <?php checked(!empty($modules['email_2fa'])); ?>>
                            Enable Email 2FA
                        </label>
                    </div>
                </div>
                
                <?php submit_button('Save Module Settings'); ?>
            </form>
            
            <div class="wpsa-info-section">
                <h2>📋 Plugin Information</h2>
                <p><strong>Version:</strong> <?php echo WPSA_VERSION; ?></p>
                <p><strong>Author:</strong> <a href="https://twitter.com/drshounakpal" target="_blank">TechWeirdo</a></p>
                <p><strong>Support:</strong> <a href="https://github.com/drshounak/wordpress-plugins" target="_blank">GitHub Repository</a></p>
            </div>
        </div>
        
        <style>
        .wpsa-modules-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }
        .wpsa-module-card {
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
            padding: 20px;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
        }
        .wpsa-module-card h3 {
            margin-top: 0;
            color: #23282d;
        }
        .wpsa-module-card p {
            color: #666;
            margin-bottom: 15px;
        }
        .wpsa-module-card label {
            font-weight: 600;
            color: #0073aa;
        }
        .wpsa-info-section {
            background: #f9f9f9;
            border: 1px solid #e5e5e5;
            border-radius: 4px;
            padding: 20px;
            margin-top: 30px;
        }
        </style>
        <?php
    }
    
    public function custom_scripts_page() {
        $scripts_manager = new WPSA_CustomScriptsManager();
        $scripts_manager->admin_page();
    }
    
    public function smtp_mailer_page() {
        $smtp_mailer = new WPSA_SMTPMailer();
        $smtp_mailer->admin_page();
    }
    
    public function image_optimizer_page() {
        $image_optimizer = new WPSA_ImageOptimizer();
        $image_optimizer->options_page();
    }
    
    public function email_2fa_page() {
        $email_2fa = new WPSA_Email2FA();
        $email_2fa->admin_page();
    }
}

/**
 * Custom Scripts Manager Module
 */
class WPSA_CustomScriptsManager {
    
    public function __construct() {
        add_action('wp_head', array($this, 'output_head_scripts'), 999);
        add_action('wp_footer', array($this, 'output_body_scripts'), 999);
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
        add_action('save_post', array($this, 'save_post_scripts'));
    }
    
    public function add_meta_boxes() {
        $post_types = get_post_types(array('public' => true), 'names');
        foreach ($post_types as $post_type) {
            add_meta_box(
                'custom-scripts-meta-box',
                'Custom Scripts',
                array($this, 'meta_box_callback'),
                $post_type,
                'normal',
                'high'
            );
        }
    }
    
    public function meta_box_callback($post) {
        wp_nonce_field('custom_scripts_meta_box_action', 'custom_scripts_meta_box_nonce');
        
        $head_scripts = get_post_meta($post->ID, '_csm_head_scripts', true);
        $body_scripts = get_post_meta($post->ID, '_csm_body_scripts', true);
        
        ?>
        <style>
            .csm-textarea { width: 100%; height: 120px; font-family: monospace; }
            .csm-section { margin: 15px 0; }
            .csm-label { font-weight: bold; margin-bottom: 5px; display: block; }
        </style>
        
        <div class="csm-section">
            <label class="csm-label">Head Scripts (before &lt;/head&gt;)</label>
            <textarea name="csm_head_scripts" class="csm-textarea" placeholder="Enter scripts to be added in the head section..."><?php echo esc_textarea($head_scripts); ?></textarea>
        </div>
        
        <div class="csm-section">
            <label class="csm-label">Body Scripts (before &lt;/body&gt;)</label>
            <textarea name="csm_body_scripts" class="csm-textarea" placeholder="Enter scripts to be added before closing body tag..."><?php echo esc_textarea($body_scripts); ?></textarea>
        </div>
        
        <p><small><strong>Note:</strong> These scripts will only apply to this specific post/page. For site-wide scripts, use the <a href="<?php echo admin_url('admin.php?page=wpsa-custom-scripts'); ?>">Custom Scripts settings page</a>.</small></p>
        <?php
    }
    
    public function save_post_scripts($post_id) {
        if (!isset($_POST['custom_scripts_meta_box_nonce']) || 
            !wp_verify_nonce($_POST['custom_scripts_meta_box_nonce'], 'custom_scripts_meta_box_action')) {
            return;
        }
        
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        if (isset($_POST['csm_head_scripts'])) {
            $head_scripts = wp_unslash($_POST['csm_head_scripts']);
            update_post_meta($post_id, '_csm_head_scripts', $head_scripts);
        } else {
            delete_post_meta($post_id, '_csm_head_scripts');
        }
        
        if (isset($_POST['csm_body_scripts'])) {
            $body_scripts = wp_unslash($_POST['csm_body_scripts']);
            update_post_meta($post_id, '_csm_body_scripts', $body_scripts);
        } else {
            delete_post_meta($post_id, '_csm_body_scripts');
        }
    }
    
    public function output_head_scripts() {
        $global_head_scripts = get_option('csm_global_head_scripts', '');
        if (!empty(trim($global_head_scripts))) {
            echo "\n<!-- WP Starter Addon - Custom Scripts Manager - Global Head -->\n";
            echo $global_head_scripts . "\n";
            echo "<!-- End WP Starter Addon - Custom Scripts Manager - Global Head -->\n";
        }
        
        if (is_singular()) {
            global $post;
            if ($post) {
                $post_head_scripts = get_post_meta($post->ID, '_csm_head_scripts', true);
                if (!empty(trim($post_head_scripts))) {
                    echo "\n<!-- WP Starter Addon - Custom Scripts Manager - Page Specific Head -->\n";
                    echo $post_head_scripts . "\n";
                    echo "<!-- End WP Starter Addon - Custom Scripts Manager - Page Specific Head -->\n";
                }
            }
        }
    }
    
    public function output_body_scripts() {
        $global_body_scripts = get_option('csm_global_body_scripts', '');
        if (!empty(trim($global_body_scripts))) {
            echo "\n<!-- WP Starter Addon - Custom Scripts Manager - Global Body -->\n";
            echo $global_body_scripts . "\n";
            echo "<!-- End WP Starter Addon - Custom Scripts Manager - Global Body -->\n";
        }
        
        if (is_singular()) {
            global $post;
            if ($post) {
                $post_body_scripts = get_post_meta($post->ID, '_csm_body_scripts', true);
                if (!empty(trim($post_body_scripts))) {
                    echo "\n<!-- WP Starter Addon - Custom Scripts Manager - Page Specific Body -->\n";
                    echo $post_body_scripts . "\n";
                    echo "<!-- End WP Starter Addon - Custom Scripts Manager - Page Specific Body -->\n";
                }
            }
        }
    }
    
    public function admin_page() {
        if (isset($_POST['submit']) && check_admin_referer('csm_settings_action', 'csm_settings_nonce')) {
            $head_scripts = isset($_POST['csm_global_head_scripts']) ? wp_unslash($_POST['csm_global_head_scripts']) : '';
            $body_scripts = isset($_POST['csm_global_body_scripts']) ? wp_unslash($_POST['csm_global_body_scripts']) : '';
            
            update_option('csm_global_head_scripts', $head_scripts);
            update_option('csm_global_body_scripts', $body_scripts);
            
            echo '<div class="notice notice-success is-dismissible"><p><strong>Settings saved successfully!</strong></p></div>';
        }
        
        $global_head_scripts = get_option('csm_global_head_scripts', '');
        $global_body_scripts = get_option('csm_global_body_scripts', '');
        
        ?>
        <div class="wrap">
            <h1>Custom Scripts Manager</h1>
            <p>Add custom scripts to your entire website or specific pages/posts. Scripts can be added to the head section or before the closing body tag.</p>
            
            <form method="post" action="">
                <?php wp_nonce_field('csm_settings_action', 'csm_settings_nonce'); ?>
                
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="csm_global_head_scripts">Global Head Scripts</label>
                            </th>
                            <td>
                                <textarea name="csm_global_head_scripts" id="csm_global_head_scripts" rows="10" cols="80" class="large-text code"><?php echo esc_textarea($global_head_scripts); ?></textarea>
                                <p class="description">These scripts will be added to the &lt;head&gt; section of every page (analytics, fonts, meta tags, etc.)</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="csm_global_body_scripts">Global Body Scripts</label>
                            </th>
                            <td>
                                <textarea name="csm_global_body_scripts" id="csm_global_body_scripts" rows="10" cols="80" class="large-text code"><?php echo esc_textarea($global_body_scripts); ?></textarea>
                                <p class="description">These scripts will be added before the closing &lt;/body&gt; tag of every page (tracking, chat widgets, etc.)</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
                
                <?php submit_button('Save Scripts'); ?>
            </form>
            
            <div style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin: 20px 0;">
                <h2>📋 Example Usage</h2>
                <h3>Plausible Analytics (Head Section):</h3>
                <textarea readonly style="width: 100%; height: 80px; font-family: monospace; background: #f6f7f7;">&lt;script defer data-domain="yourdomain.com" src="https://plausible.io/js/script.js"&gt;&lt;/script&gt;</textarea>
                
                <h3>Other Examples:</h3>
                <p><strong>Google Analytics:</strong> Add to Head</p>
                <p><strong>Facebook Pixel:</strong> Add to Head</p>
                <p><strong>Chat Widgets:</strong> Add to Body</p>
                <p><strong>Custom CSS:</strong> Add to Head using &lt;style&gt; tags</p>
            </div>
            
            <div style="background: #f0f6fc; border: 1px solid #0969da; padding: 15px; margin: 20px 0;">
                <h3>🔧 Current Status</h3>
                <p><strong>Global Head Scripts:</strong> <?php echo empty($global_head_scripts) ? '<span style="color: #d1242f;">Empty</span>' : '<span style="color: #0f5132;">Active (' . strlen($global_head_scripts) . ' characters)</span>'; ?></p>
                <p><strong>Global Body Scripts:</strong> <?php echo empty($global_body_scripts) ? '<span style="color: #d1242f;">Empty</span>' : '<span style="color: #0f5132;">Active (' . strlen($global_body_scripts) . ' characters)</span>'; ?></p>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('form').on('submit', function() {
                return confirm('Are you sure you want to save these script changes?');
            });
        });
        </script>
        <?php
    }
}

/**
 * SMTP Mailer Module
 */
class WPSA_SMTPMailer {
    private $options;

    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('phpmailer_init', array($this, 'configure_phpmailer'));
        add_action('wp_ajax_ssm_test_email', array($this, 'test_email'));
    }

    public function init() {
        $this->options = get_option('ssm_settings', array());
    }

    public function configure_phpmailer($phpmailer) {
        if (empty($this->options['smtp_host'])) {
            return;
        }
        
        $phpmailer->isSMTP();
        $phpmailer->Host = $this->options['smtp_host'];
        $phpmailer->SMTPAuth = true;
        $phpmailer->Port = $this->options['smtp_port'];
        $phpmailer->SMTPSecure = $this->options['smtp_encryption'];
        $phpmailer->Username = $this->options['smtp_username'];
        $phpmailer->Password = $this->options['smtp_password'];
        $phpmailer->setFrom(
            $this->options['from_email'],
            $this->options['from_name']
        );
        if (!empty($this->options['debug_mode'])) {
            $phpmailer->SMTPDebug = 2;
        }
    }

    public function test_email() {
        check_ajax_referer('ssm_test_email', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        $to = sanitize_email($_POST['test_email']);
        $subject = 'Test Email from WP Starter Addon SMTP Mailer';
        $message = 'This is a test email to verify your SMTP configuration.';
        $result = wp_mail($to, $subject, $message);
        if ($result) {
            wp_send_json_success('Test email sent successfully!');
        } else {
            wp_send_json_error('Failed to send test email. Please check your settings.');
        }
    }

    public function admin_page() {
        if (isset($_POST['submit'])) {
            check_admin_referer('ssm_settings_action', 'ssm_settings_nonce');
            
            $settings = array(
                'smtp_host' => sanitize_text_field($_POST['smtp_host']),
                'smtp_port' => intval($_POST['smtp_port']),
                'smtp_encryption' => sanitize_text_field($_POST['smtp_encryption']),
                'smtp_username' => sanitize_text_field($_POST['smtp_username']),
                'smtp_password' => $_POST['smtp_password'],
                'from_email' => sanitize_email($_POST['from_email']),
                'from_name' => sanitize_text_field($_POST['from_name']),
                'debug_mode' => isset($_POST['debug_mode']) ? 1 : 0,
            );
            
            update_option('ssm_settings', $settings);
            $this->options = $settings;
            
            echo '<div class="notice notice-success"><p>SMTP settings saved successfully!</p></div>';
        }
        
        ?>
        <div class="wrap">
            <h1>SMTP Mailer Settings</h1>
            <p>Configure your SMTP server settings for reliable email delivery.</p>
            
            <form method="post" action="">
                <?php wp_nonce_field('ssm_settings_action', 'ssm_settings_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">From Email</th>
                        <td>
                            <input type="email" name="from_email" value="<?php echo esc_attr($this->options['from_email'] ?? get_option('admin_email')); ?>" class="regular-text" required />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">From Name</th>
                        <td>
                            <input type="text" name="from_name" value="<?php echo esc_attr($this->options['from_name'] ?? get_option('blogname')); ?>" class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">SMTP Host</th>
                        <td>
                            <input type="text" name="smtp_host" value="<?php echo esc_attr($this->options['smtp_host'] ?? ''); ?>" class="regular-text" required />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">SMTP Port</th>
                        <td>
                            <input type="number" name="smtp_port" value="<?php echo esc_attr($this->options['smtp_port'] ?? '587'); ?>" class="small-text" required />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Encryption</th>
                        <td>
                            <select name="smtp_encryption">
                                <option value="none" <?php selected($this->options['smtp_encryption'] ?? 'tls', 'none'); ?>>None</option>
                                <option value="ssl" <?php selected($this->options['smtp_encryption'] ?? 'tls', 'ssl'); ?>>SSL</option>
                                <option value="tls" <?php selected($this->options['smtp_encryption'] ?? 'tls', 'tls'); ?>>TLS</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Username</th>
                        <td>
                            <input type="text" name="smtp_username" value="<?php echo esc_attr($this->options['smtp_username'] ?? ''); ?>" class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Password</th>
                        <td>
                            <input type="password" name="smtp_password" value="<?php echo esc_attr($this->options['smtp_password'] ?? ''); ?>" class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Debug Mode</th>
                        <td>
                            <label>
                                <input type="checkbox" name="debug_mode" <?php checked(!empty($this->options['debug_mode'])); ?> />
                                Enable debug output
                            </label>
                        </td>
                    </tr>
                </table>
                
                <h2>Send Test Email</h2>
                <input type="email" id="test-email-address" placeholder="Enter test email address" class="regular-text" />
                <button type="button" id="send-test-email" class="button button-primary">Send Test Email</button>
                <div id="test-result" style="margin-top:10px;font-weight:bold;"></div>
                
                <?php submit_button('Save SMTP Settings'); ?>
            </form>
        </div>
        
        <script>
        document.getElementById('send-test-email').addEventListener('click', function() {
            var email = document.getElementById('test-email-address').value;
            var result = document.getElementById('test-result');
            if (!email) {
                result.innerHTML = '<span style="color:red;">Please enter an email address.</span>';
                return;
            }
            this.disabled = true;
            this.textContent = 'Sending...';
            var data = new FormData();
            data.append('action', 'ssm_test_email');
            data.append('test_email', email);
            data.append('nonce', '<?php echo wp_create_nonce('ssm_test_email'); ?>');
            fetch(ajaxurl, { method:'POST', body:data })
                .then(res => res.json())
                .then(data => {
                    document.getElementById('send-test-email').disabled = false;
                    document.getElementById('send-test-email').textContent = 'Send Test Email';
                    result.innerHTML = data.success ? '<span style="color:green;">'+data.data+'</span>' : '<span style="color:red;">'+data.data+'</span>';
                }).catch(() => {
                    document.getElementById('send-test-email').disabled = false;
                    document.getElementById('send-test-email').textContent = 'Send Test Email';
                    result.innerHTML = '<span style="color:red;">Error sending test email.</span>';
                });
        });
        </script>
        <?php
    }
}

/**
 * Image Optimizer Module
 */
class WPSA_ImageOptimizer {
    
    private $options;
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_filter('wp_handle_upload', array($this, 'process_upload'), 10, 2);
        add_filter('bulk_actions-upload', array($this, 'add_bulk_action'));
        add_filter('handle_bulk_actions-upload', array($this, 'handle_bulk_action'), 10, 3);
        add_filter('media_row_actions', array($this, 'add_media_row_action'), 10, 2);
        add_action('wp_ajax_convert_single_image', array($this, 'ajax_convert_single_image'));
    }
    
    public function init() {
        $this->options = get_option('aic_settings', array(
            'max_width' => 2400,
            'max_height' => 2400,
            'output_format' => 'webp',
            'quality' => 85,
            'keep_original' => false,
            'auto_convert' => true
        ));
    }
    
    public function process_upload($upload, $context) {
        if (!$this->options['auto_convert']) {
            return $upload;
        }
        
        if (!isset($upload['file']) || !$this->is_image($upload['file'])) {
            return $upload;
        }
        
        $converted = $this->convert_image($upload['file']);
        
        if ($converted && !$this->options['keep_original']) {
            $upload['file'] = $converted['file'];
            $upload['url'] = $converted['url'];
            $upload['type'] = $converted['type'];
        }
        
        return $upload;
    }
    
    public function convert_image($file_path, $attachment_id = null) {
        if (!file_exists($file_path)) {
            return false;
        }
        
        $pathinfo = pathinfo($file_path);
        if (strpos($pathinfo['filename'], 'compressed_') === 0) {
            return false;
        }
        
        $image_info = $this->get_image_info($file_path);
        if (!$image_info) {
            return false;
        }
        
        $target_mime = 'image/' . $this->options['output_format'];
        if ($image_info['mime'] === $target_mime) {
            return false;
        }
        
        $image = $this->create_image_resource($file_path, $image_info['mime']);
        if (!$image) {
            return false;
        }
        
        $new_dimensions = $this->calculate_dimensions(
            $image_info['width'],
            $image_info['height'],
            $this->options['max_width'],
            $this->options['max_height']
        );
        
        $resized_image = imagecreatetruecolor($new_dimensions['width'], $new_dimensions['height']);
        
        if ($image_info['mime'] === 'image/png' || $image_info['mime'] === 'image/gif') {
            imagealphablending($resized_image, false);
            imagesavealpha($resized_image, true);
            $transparent = imagecolorallocatealpha($resized_image, 255, 255, 255, 127);
            imagefill($resized_image, 0, 0, $transparent);
        }
        
        imagecopyresampled(
            $resized_image,
            $image,
            0, 0, 0, 0,
            $new_dimensions['width'],
            $new_dimensions['height'],
            $image_info['width'],
            $image_info['height']
        );
        
        $upload_dir = wp_upload_dir();
        $relative_path = str_replace($upload_dir['basedir'], '', $file_path);
        $new_filename = 'compressed_' . $pathinfo['filename'] . '.' . $this->options['output_format'];
        $new_file_path = $pathinfo['dirname'] . '/' . $new_filename;
        $new_url = $upload_dir['baseurl'] . str_replace($pathinfo['basename'], $new_filename, $relative_path);
        
        $success = false;
        if ($this->options['output_format'] === 'webp' && function_exists('imagewebp')) {
            $success = imagewebp($resized_image, $new_file_path, $this->options['quality']);
        } elseif ($this->options['output_format'] === 'avif' && function_exists('imageavif')) {
            $success = imageavif($resized_image, $new_file_path, $this->options['quality']);
        }
        
        imagedestroy($image);
        imagedestroy($resized_image);
        
        if ($success) {
            if ($attachment_id) {
                $this->update_attachment_metadata($attachment_id, $new_file_path, $new_url);
            }
            
            return array(
                'file' => $new_file_path,
                'url' => $new_url,
                'type' => 'image/' . $this->options['output_format']
            );
        }
        
        return false;
    }
    
    private function get_image_info($file_path) {
        $mime_type = mime_content_type($file_path);
        
        if ($mime_type === 'image/heic' || $mime_type === 'image/heif') {
            if (extension_loaded('imagick')) {
                try {
                    $imagick = new Imagick($file_path);
                    return array(
                        'width' => $imagick->getImageWidth(),
                        'height' => $imagick->getImageHeight(),
                        'mime' => $mime_type
                    );
                } catch (Exception $e) {
                    return false;
                }
            }
            return false;
        }
        
        $image_info = getimagesize($file_path);
        if ($image_info) {
            return array(
                'width' => $image_info[0],
                'height' => $image_info[1],
                'mime' => $image_info['mime']
            );
        }
        
        return false;
    }
    
    private function create_image_resource($file_path, $mime_type) {
        switch ($mime_type) {
            case 'image/jpeg':
                return imagecreatefromjpeg($file_path);
            case 'image/png':
                return imagecreatefrompng($file_path);
            case 'image/gif':
                return imagecreatefromgif($file_path);
            case 'image/webp':
                return imagecreatefromwebp($file_path);
            case 'image/heic':
            case 'image/heif':
                if (extension_loaded('imagick')) {
                    try {
                        $imagick = new Imagick($file_path);
                        $imagick->setImageFormat('png');
                        $blob = $imagick->getImageBlob();
                        return imagecreatefromstring($blob);
                    } catch (Exception $e) {
                        return false;
                    }
                }
                return false;
            default:
                if (extension_loaded('imagick')) {
                    try {
                        $imagick = new Imagick($file_path);
                        $imagick->setImageFormat('png');
                        $blob = $imagick->getImageBlob();
                        return imagecreatefromstring($blob);
                    } catch (Exception $e) {
                        return false;
                    }
                }
                return false;
        }
    }
    
    private function calculate_dimensions($orig_width, $orig_height, $max_width, $max_height) {
        $ratio = min($max_width / $orig_width, $max_height / $orig_height);
        
        if ($ratio >= 1) {
            return array('width' => $orig_width, 'height' => $orig_height);
        }
        
        return array(
            'width' => round($orig_width * $ratio),
            'height' => round($orig_height * $ratio)
        );
    }
    
    private function is_image($file_path) {
        $mime_type = mime_content_type($file_path);
        return strpos($mime_type, 'image/') === 0;
    }
    
    private function update_attachment_metadata($attachment_id, $new_file_path, $new_url) {
        $metadata = wp_get_attachment_metadata($attachment_id);
        if ($metadata) {
            $metadata['file'] = str_replace(wp_upload_dir()['basedir'] . '/', '', $new_file_path);
            wp_update_attachment_metadata($attachment_id, $metadata);
        }
        
        update_post_meta($attachment_id, '_wp_attached_file', str_replace(wp_upload_dir()['basedir'] . '/', '', $new_file_path));
    }
    
    public function add_bulk_action($bulk_actions) {
        $bulk_actions['convert_images'] = 'Convert Images';
        return $bulk_actions;
    }
    
    public function handle_bulk_action($redirect_to, $doaction, $post_ids) {
        if ($doaction !== 'convert_images') {
            return $redirect_to;
        }
        
        $converted = 0;
        foreach ($post_ids as $post_id) {
            $file_path = get_attached_file($post_id);
            if ($file_path && $this->convert_image($file_path, $post_id)) {
                $converted++;
            }
        }
        
        $redirect_to = add_query_arg('converted', $converted, $redirect_to);
        return $redirect_to;
    }
    
    public function add_media_row_action($actions, $post) {
        if (wp_attachment_is_image($post->ID)) {
            $actions['convert'] = '<a href="#" onclick="convertSingleImage(' . $post->ID . '); return false;">Convert Image</a>';
        }
        return $actions;
    }
    
    public function ajax_convert_single_image() {
        check_ajax_referer('convert_single_image', 'nonce');
        
        $attachment_id = intval($_POST['attachment_id']);
        $file_path = get_attached_file($attachment_id);
        
        if ($file_path && $this->convert_image($file_path, $attachment_id)) {
            wp_send_json_success('Image converted successfully');
        } else {
            wp_send_json_error('Failed to convert image');
        }
    }
    
    public function options_page() {
        if (isset($_POST['submit'])) {
            check_admin_referer('aic_settings_action', 'aic_settings_nonce');
            
            $settings = array(
                'max_width' => max(100, min(5000, intval($_POST['max_width']))),
                'max_height' => max(100, min(5000, intval($_POST['max_height']))),
                'output_format' => sanitize_text_field($_POST['output_format']),
                'quality' => max(1, min(100, intval($_POST['quality']))),
                'keep_original' => isset($_POST['keep_original']) ? true : false,
                'auto_convert' => isset($_POST['auto_convert']) ? true : false
            );
            
            update_option('aic_settings', $settings);
            $this->options = $settings;
            
            echo '<div class="notice notice-success"><p>Image optimizer settings saved successfully!</p></div>';
        }
        
        ?>
        <div class="wrap">
            <h1>Image Optimizer Settings</h1>
            <p>Configure automatic image optimization and conversion settings.</p>
            
            <form method="post" action="">
                <?php wp_nonce_field('aic_settings_action', 'aic_settings_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">Max Width (px)</th>
                        <td>
                            <input type="number" name="max_width" value="<?php echo esc_attr($this->options['max_width']); ?>" min="100" max="5000" class="small-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Max Height (px)</th>
                        <td>
                            <input type="number" name="max_height" value="<?php echo esc_attr($this->options['max_height']); ?>" min="100" max="5000" class="small-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Output Format</th>
                        <td>
                            <select name="output_format">
                                <option value="webp" <?php selected($this->options['output_format'], 'webp'); ?>>WebP</option>
                                <option value="avif" <?php selected($this->options['output_format'], 'avif'); ?>>AVIF</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Quality (1-100)</th>
                        <td>
                            <input type="number" name="quality" value="<?php echo esc_attr($this->options['quality']); ?>" min="1" max="100" class="small-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Keep Original Files</th>
                        <td>
                            <label>
                                <input type="checkbox" name="keep_original" <?php checked($this->options['keep_original']); ?>>
                                Keep original files on server
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Auto Convert on Upload</th>
                        <td>
                            <label>
                                <input type="checkbox" name="auto_convert" <?php checked($this->options['auto_convert']); ?>>
                                Automatically convert images on upload
                            </label>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button('Save Image Optimizer Settings'); ?>
            </form>
            
            <h2>Bulk Convert Existing Images</h2>
            <p>Go to Media Library and use the bulk action "Convert Images" to convert existing images.</p>
        </div>
        <?php
    }
}

/**
 * Email 2FA Module
 */
class WPSA_Email2FA {
    
    const VERSION = '1.0.0';
    
    public function __construct() {
        add_action('login_init', array($this, 'custom_login_handler'));
        add_action('login_init', array($this, 'add_otp_verification_form'));
        add_action('login_init', array($this, 'handle_resend_otp'));
        add_action('login_errors', array($this, 'display_otp_error_messages'));
        add_action('show_user_profile', array($this, 'otp_settings_fields'));
        add_action('edit_user_profile', array($this, 'otp_settings_fields'));
        add_action('personal_options_update', array($this, 'save_otp_settings'));
        add_action('edit_user_profile_update', array($this, 'save_otp_settings'));
        add_action('wp_scheduled_delete', array($this, 'cleanup_expired_otp_codes'));
    }
    
    private function generate_otp_code() {
        return sprintf("%06d", mt_rand(100000, 999999));
    }
    
    private function store_user_otp($user_id, $otp) {
        $expiry_minutes = get_option('email_otp_login_expiry', 10);
        $expiry = time() + ($expiry_minutes * 60);
        update_user_meta($user_id, 'login_otp_code', $otp);
        update_user_meta($user_id, 'login_otp_expiry', $expiry);
    }
    
    private function send_otp_email($user) {
        $otp = $this->generate_otp_code();
        $this->store_user_otp($user->ID, $otp);
        
        $subject = sprintf('Your login verification code - %s', get_bloginfo('name'));
        $message = '
        <html>
        <body style="font-family: Arial, sans-serif; color: #333; line-height: 1.6; max-width: 600px; margin: 0 auto; padding: 20px; background-color: #f9f9f9;">
            <div style="background-color: #ffffff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                <h2 style="color: #3498db; margin-top: 0;">Login Verification Code</h2>
                <p>Hello ' . esc_html($user->display_name) . ',</p>
                <p>Your verification code to log in to ' . get_bloginfo('name') . ' is:</p>
                <div style="background: #f2f2f2; padding: 15px; font-size: 24px; text-align: center; letter-spacing: 5px; font-weight: bold; margin: 20px 0;">
                    ' . $otp . '
                </div>
                <p>This code will expire in ' . get_option('email_otp_login_expiry', 10) . ' minutes.</p>
                <p>If you did not request this code, please ignore this email.</p>
                <hr style="border: 0; border-top: 1px solid #eee;">
                <p style="font-size: 12px; color: #777;">This is an automated email from ' . get_bloginfo('name') . '.</p>
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
    
    private function start_otp_session() {
        if (!session_id()) {
            session_start();
        }
    }
    
    private function close_otp_session() {
        if (session_id()) {
            session_write_close();
        }
    }
    
    private function is_otp_disabled_for_user($user_id) {
        return get_user_meta($user_id, 'disable_login_otp', true) === '1';
    }
    
    public function custom_login_handler() {
        if (get_option('email_otp_login_enabled', '1') !== '1') {
            return;
        }
        
        if (isset($_POST['otp_verification'])) {
            return;
        }
        
        if ((isset($_GET['action']) && $_GET['action'] != 'login' && $_GET['action'] != '') || isset($_GET['loggedout'])) {
            return;
        }
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['log']) || !isset($_POST['pwd'])) {
            return;
        }
        
        $username = $_POST['log'];
        $password = $_POST['pwd'];
        $remember = isset($_POST['rememberme']) ? true : false;
        
        $user = wp_authenticate($username, $password);
        
        if (is_wp_error($user)) {
            return;
        }
        
        if ($this->is_otp_disabled_for_user($user->ID)) {
            return;
        }
        
        $this->start_otp_session();
        
        $_SESSION['otp_user_id'] = $user->ID;
        $_SESSION['otp_remember'] = $remember;
        
        $this->close_otp_session();
        
        $this->send_otp_email($user);
        
        wp_redirect(add_query_arg('otp_verification', '1', wp_login_url()));
        exit;
    }
    
    public function add_otp_verification_form() {
        if (!isset($_GET['otp_verification']) || $_GET['otp_verification'] != '1') {
            return;
        }
        
        $this->start_otp_session();
        
        if (!isset($_SESSION['otp_user_id'])) {
            $this->close_otp_session();
            wp_redirect(wp_login_url());
            exit;
        }
        
        $user = get_user_by('ID', $_SESSION['otp_user_id']);
        if (!$user) {
            $this->close_otp_session();
            wp_redirect(wp_login_url());
            exit;
        }
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['otp_code'])) {
            $entered_otp = preg_replace('/[^0-9]/', '', $_POST['otp_code']);
            $stored_otp = get_user_meta($user->ID, 'login_otp_code', true);
            $expiry = (int) get_user_meta($user->ID, 'login_otp_expiry', true);
            
            if ($entered_otp === $stored_otp && time() < $expiry) {
                delete_user_meta($user->ID, 'login_otp_code');
                delete_user_meta($user->ID, 'login_otp_expiry');
                
                wp_set_auth_cookie($user->ID, $_SESSION['otp_remember']);
                
                unset($_SESSION['otp_user_id']);
                unset($_SESSION['otp_remember']);
                $this->close_otp_session();
                
                $redirect_to = isset($_REQUEST['redirect_to']) ? $_REQUEST['redirect_to'] : admin_url();
                wp_safe_redirect($redirect_to);
                exit;
            } else {
                $error_message = time() >= $expiry ? 
                    'OTP has expired. Please try again.' : 
                    'Invalid verification code. Please try again.';
            }
        }
        
        $this->close_otp_session();
        
        $this->render_otp_form($user, isset($error_message) ? $error_message : null);
        exit;
    }
    
    private function render_otp_form($user, $error_message = null) {
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>OTP Verification - <?php echo get_bloginfo('name'); ?></title>
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
                <h1>Verification Required</h1>
                <p>We've sent a verification code to <strong><?php echo esc_html(substr_replace($user->user_email, '***', 3, strpos($user->user_email, '@') - 5)); ?></strong>. Please enter it below to continue.</p>
                
                <?php if ($error_message): ?>
                    <div class="error-message"><?php echo esc_html($error_message); ?></div>
                <?php endif; ?>
                
                <form method="post" action="<?php echo esc_url(add_query_arg('otp_verification', '1', wp_login_url())); ?>">
                    <input type="hidden" name="otp_verification" value="1">
                    <input type="text" name="otp_code" class="otp-field" maxlength="6" placeholder="------" required autofocus>
                    <button type="submit" class="otp-submit">Verify</button>
                    
                    <?php if (isset($_REQUEST['redirect_to'])): ?>
                        <input type="hidden" name="redirect_to" value="<?php echo esc_url($_REQUEST['redirect_to']); ?>">
                    <?php endif; ?>
                </form>
                
                <div class="resend-link">
                    <a href="<?php echo esc_url(add_query_arg('resend_otp', '1', add_query_arg('otp_verification', '1', wp_login_url()))); ?>">Resend verification code</a>
                </div>
                
                <div class="resend-link">
                    <a href="<?php echo esc_url(wp_login_url()); ?>">Back to login</a>
                </div>
            </div>
            <?php wp_footer(); ?>
        </body>
        </html>
        <?php
    }
    
    public function handle_resend_otp() {
        if (!isset($_GET['resend_otp']) || $_GET['resend_otp'] != '1' || !isset($_GET['otp_verification'])) {
            return;
        }
        
        $this->start_otp_session();
        
        if (!isset($_SESSION['otp_user_id'])) {
            $this->close_otp_session();
            wp_redirect(wp_login_url());
            exit;
        }
        
        $user = get_user_by('ID', $_SESSION['otp_user_id']);
        if (!$user) {
            $this->close_otp_session();
            wp_redirect(wp_login_url());
            exit;
        }
        
        $rate_limit_seconds = get_option('email_otp_login_rate_limit', 60);
        $last_sent = get_user_meta($user->ID, 'login_otp_last_sent', true);
        if ($last_sent && (time() - $last_sent) < $rate_limit_seconds) {
            $this->close_otp_session();
            wp_redirect(add_query_arg('otp_error', 'rate_limit', add_query_arg('otp_verification', '1', wp_login_url())));
            exit;
        }
        
        $this->close_otp_session();
        
        $this->send_otp_email($user);
        update_user_meta($user->ID, 'login_otp_last_sent', time());
        
        wp_redirect(add_query_arg('otp_verification', '1', wp_login_url()));
        exit;
    }
    
    public function display_otp_error_messages() {
        if (isset($_GET['otp_error']) && $_GET['otp_error'] == 'rate_limit') {
            $rate_limit_minutes = ceil(get_option('email_otp_login_rate_limit', 60) / 60);
            ?>
            <div id="login_error">
                Please wait at least <?php echo $rate_limit_minutes; ?> minute(s) before requesting another verification code.
            </div>
            <?php
        }
    }
    
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
    
    public function otp_settings_fields($user) {
        $disable_otp = get_user_meta($user->ID, 'disable_login_otp', true);
        ?>
        <h3>Login Security</h3>
        <table class="form-table">
            <tr>
                <th><label for="disable_login_otp">Two-Factor Authentication</label></th>
                <td>
                    <input type="checkbox" name="disable_login_otp" id="disable_login_otp" value="1" <?php checked($disable_otp, '1'); ?>>
                    <label for="disable_login_otp">Disable email verification code during login</label>
                    <p class="description">Not recommended. Two-factor authentication adds an extra layer of security to your account.</p>
                </td>
            </tr>
			</table>
        <?php
    }
    
    public function save_otp_settings($user_id) {
        if (!current_user_can('edit_user', $user_id)) {
            return false;
        }
        
        $disable_otp = isset($_POST['disable_login_otp']) ? '1' : '0';
        update_user_meta($user_id, 'disable_login_otp', $disable_otp);
    }
    
    public function admin_page() {
        if (isset($_POST['submit'])) {
            check_admin_referer('email_otp_login_settings');
            
            update_option('email_otp_login_enabled', isset($_POST['email_otp_login_enabled']) ? '1' : '0');
            update_option('email_otp_login_expiry', max(1, min(60, (int) $_POST['email_otp_login_expiry'])));
            update_option('email_otp_login_rate_limit', max(30, min(300, (int) $_POST['email_otp_login_rate_limit'])));
            
            echo '<div class="notice notice-success"><p>Email 2FA settings saved successfully!</p></div>';
        }
        
        $enabled = get_option('email_otp_login_enabled', '1');
        $expiry = get_option('email_otp_login_expiry', 10);
        $rate_limit = get_option('email_otp_login_rate_limit', 60);
        ?>
        <div class="wrap">
            <h1>Email 2FA Settings</h1>
            <p>Configure two-factor authentication settings for enhanced login security.</p>
            
            <form method="post" action="">
                <?php wp_nonce_field('email_otp_login_settings'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">Enable OTP Login</th>
                        <td>
                            <input type="checkbox" name="email_otp_login_enabled" id="email_otp_login_enabled" value="1" <?php checked($enabled, '1'); ?>>
                            <label for="email_otp_login_enabled">Enable email OTP verification for login</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">OTP Expiry Time</th>
                        <td>
                            <input type="number" name="email_otp_login_expiry" id="email_otp_login_expiry" value="<?php echo esc_attr($expiry); ?>" min="1" max="60" class="small-text">
                            <label for="email_otp_login_expiry">minutes</label>
                            <p class="description">How long the OTP code remains valid (1-60 minutes)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Rate Limit</th>
                        <td>
                            <input type="number" name="email_otp_login_rate_limit" id="email_otp_login_rate_limit" value="<?php echo esc_attr($rate_limit); ?>" min="30" max="300" class="small-text">
                            <label for="email_otp_login_rate_limit">seconds</label>
                            <p class="description">Minimum time between OTP requests (30-300 seconds)</p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button('Save Email 2FA Settings'); ?>
            </form>
            
            <hr>
            
            <h2>Plugin Information</h2>
            <p>This module adds two-factor authentication to WordPress login using email OTP codes.</p>
            <p>Users can disable OTP for their accounts in their profile settings (not recommended).</p>
            <p>Module Version: <?php echo self::VERSION; ?></p>
        </div>
        <?php
    }
}

// Initialize the main plugin
WPStarterAddon::get_instance();

// Activation notice
add_action('admin_notices', 'wpsa_activation_notice');
function wpsa_activation_notice() {
    if (get_option('wpsa_activation_notice')) {
        ?>
        <div class="notice notice-success is-dismissible">
            <p><strong>WP Starter Addon</strong> activated! Go to <a href="<?php echo admin_url('admin.php?page=wp-starter-addon'); ?>">WP Starter Addon</a> to configure your modules.</p>
        </div>
        <?php
        delete_option('wpsa_activation_notice');
    }
}

// Set activation notice on plugin activation
register_activation_hook(__FILE__, function() {
    add_option('wpsa_activation_notice', true);
});

// Add JavaScript for admin functionality
add_action('admin_footer', 'wpsa_admin_js');
function wpsa_admin_js() {
    $screen = get_current_screen();
    if (strpos($screen->id, 'wp-starter-addon') !== false || $screen->id === 'upload') {
        ?>
        <script type="text/javascript">
        // Image Optimizer JavaScript
        function convertSingleImage(attachmentId) {
            if (confirm('Convert this image?')) {
                jQuery.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'convert_single_image',
                        attachment_id: attachmentId,
                        nonce: '<?php echo wp_create_nonce('convert_single_image'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('Image converted successfully!');
                            location.reload();
                        } else {
                            alert('Failed to convert image: ' + response.data);
                        }
                    },
                    error: function() {
                        alert('An error occurred while converting the image.');
                    }
                });
            }
        }
        
        // Bulk convert functionality
        jQuery(document).ready(function($) {
            $('#aic-convert-all').click(function() {
                if (confirm('This will convert all images in your media library. This may take a while. Continue?')) {
                    convertAllImages();
                }
            });
            
            function convertAllImages() {
                $('#aic-progress').show();
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'get_all_images'
                    },
                    success: function(response) {
                        if (response.success) {
                            var images = response.data;
                            var total = images.length;
                            var converted = 0;
                            
                            function convertNext() {
                                if (converted >= total) {
                                    alert('All images converted!');
                                    $('#aic-progress').hide();
                                    return;
                                }
                                
                                var imageId = images[converted];
                                $.ajax({
                                    url: ajaxurl,
                                    type: 'POST',
                                    data: {
                                        action: 'convert_single_image',
                                        attachment_id: imageId,
                                        nonce: '<?php echo wp_create_nonce('convert_single_image'); ?>'
                                    },
                                    complete: function() {
                                        converted++;
                                        var percent = (converted / total) * 100;
                                        $('#aic-progress-fill').css('width', percent + '%');
                                        $('#aic-progress-text').text(Math.round(percent) + '% Complete (' + converted + '/' + total + ')');
                                        
                                        setTimeout(convertNext, 100);
                                    }
                                });
                            }
                            
                            convertNext();
                        }
                    }
                });
            }
        });
        </script>
        <?php
    }
}

// AJAX handler for getting all images (Image Optimizer)
add_action('wp_ajax_get_all_images', 'wpsa_get_all_images');
function wpsa_get_all_images() {
    $images = get_posts(array(
        'post_type' => 'attachment',
        'post_mime_type' => 'image',
        'post_status' => 'inherit',
        'numberposts' => -1,
        'fields' => 'ids'
    ));
    
    wp_send_json_success($images);
}

// Email logger for SMTP debugging
add_action('wp_mail', function($args) {
    $modules = get_option('wpsa_modules', array());
    if (!empty($modules['smtp_mailer'])) {
        $opts = get_option('ssm_settings');
        if (!empty($opts['debug_mode'])) {
            error_log(sprintf("WP Starter Addon SMTP Log: To=%s Subject=%s", 
                is_array($args['to']) ? implode(',', $args['to']) : $args['to'], 
                $args['subject']
            ));
        }
    }
});

// Debug function for Custom Scripts Manager
add_action('wp_footer', 'wpsa_csm_debug_info');
function wpsa_csm_debug_info() {
    $modules = get_option('wpsa_modules', array());
    if (!empty($modules['custom_scripts']) && current_user_can('manage_options') && isset($_GET['csm_debug'])) {
        $head_scripts = get_option('csm_global_head_scripts', '');
        $body_scripts = get_option('csm_global_body_scripts', '');
        echo '<div style="position: fixed; bottom: 0; right: 0; background: black; color: white; padding: 10px; z-index: 9999; max-width: 300px; font-size: 12px;">';
        echo '<strong>WP Starter Addon CSM Debug:</strong><br>';
        echo 'Head: ' . (empty($head_scripts) ? 'Empty' : strlen($head_scripts) . ' chars') . '<br>';
        echo 'Body: ' . (empty($body_scripts) ? 'Empty' : strlen($body_scripts) . ' chars') . '<br>';
        echo '<a href="' . remove_query_arg('csm_debug') . '" style="color: yellow;">Hide</a>';
        echo '</div>';
    }
}

// Schedule cleanup for Email 2FA
add_action('wp', 'wpsa_schedule_cleanup');
function wpsa_schedule_cleanup() {
    if (!wp_next_scheduled('email_otp_cleanup_event')) {
        wp_schedule_event(time(), 'daily', 'email_otp_cleanup_event');
    }
}

add_action('email_otp_cleanup_event', function() {
    $modules = get_option('wpsa_modules', array());
    if (!empty($modules['email_2fa'])) {
        $email_2fa = new WPSA_Email2FA();
        $email_2fa->cleanup_expired_otp_codes();
    }
});

// Uninstall cleanup
register_uninstall_hook(__FILE__, 'wpsa_uninstall_cleanup');
function wpsa_uninstall_cleanup() {
    // Remove all plugin options
    delete_option('wpsa_modules');
    delete_option('csm_global_head_scripts');
    delete_option('csm_global_body_scripts');
    delete_option('ssm_settings');
    delete_option('aic_settings');
    delete_option('email_otp_login_enabled');
    delete_option('email_otp_login_expiry');
    delete_option('email_otp_login_rate_limit');
    delete_option('wpsa_activation_notice');
    
    // Clear scheduled events
    wp_clear_scheduled_hook('email_otp_cleanup_event');
    
    // Clean up user meta for Email 2FA
    $users = get_users(array('fields' => 'ID'));
    foreach ($users as $user_id) {
        delete_user_meta($user_id, 'login_otp_code');
        delete_user_meta($user_id, 'login_otp_expiry');
        delete_user_meta($user_id, 'login_otp_last_sent');
        delete_user_meta($user_id, 'disable_login_otp');
        delete_user_meta($user_id, '_csm_head_scripts');
        delete_user_meta($user_id, '_csm_body_scripts');
    }
}

// Add settings link to plugins page
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'wpsa_add_settings_link');
function wpsa_add_settings_link($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=wp-starter-addon') . '">Settings</a>';
    array_unshift($links, $settings_link);
    return $links;
}

// Add plugin meta links
add_filter('plugin_row_meta', 'wpsa_add_plugin_meta_links', 10, 2);
function wpsa_add_plugin_meta_links($links, $file) {
    if (plugin_basename(__FILE__) === $file) {
        $meta_links = array(
            '<a href="https://github.com/drshounak/wordpress-plugins" target="_blank">GitHub</a>',
            '<a href="https://twitter.com/drshounakpal" target="_blank">Support</a>'
        );
        return array_merge($links, $meta_links);
    }
    return $links;
}

// Add admin CSS for better styling
add_action('admin_head', 'wpsa_admin_css');
function wpsa_admin_css() {
    $screen = get_current_screen();
    if (strpos($screen->id, 'wp-starter-addon') !== false) {
        ?>
        <style>
        .wpsa-modules-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }
        .wpsa-module-card {
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
            padding: 20px;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
            transition: box-shadow 0.3s ease;
        }
        .wpsa-module-card:hover {
            box-shadow: 0 2px 8px rgba(0,0,0,.1);
        }
        .wpsa-module-card h3 {
            margin-top: 0;
            color: #23282d;
            font-size: 18px;
        }
        .wpsa-module-card p {
            color: #666;
            margin-bottom: 15px;
            line-height: 1.5;
        }
        .wpsa-module-card label {
            font-weight: 600;
            color: #0073aa;
            cursor: pointer;
        }
        .wpsa-module-card input[type="checkbox"] {
            margin-right: 8px;
        }
        .wpsa-info-section {
            background: #f9f9f9;
            border: 1px solid #e5e5e5;
            border-radius: 4px;
            padding: 20px;
            margin-top: 30px;
        }
        .wpsa-info-section h2 {
            margin-top: 0;
            color: #23282d;
        }
        .wpsa-info-section p {
            margin-bottom: 10px;
        }
        .wpsa-info-section a {
            color: #0073aa;
            text-decoration: none;
        }
        .wpsa-info-section a:hover {
            text-decoration: underline;
        }
        
        /* Progress bar styles for Image Optimizer */
        #aic-progress {
            margin: 20px 0;
            padding: 15px;
            background: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        #aic-progress-bar {
            width: 100%;
            background-color: #f0f0f0;
            border-radius: 3px;
            margin: 10px 0;
            overflow: hidden;
        }
        #aic-progress-fill {
            height: 20px;
            background-color: #0073aa;
            border-radius: 3px;
            width: 0%;
            transition: width 0.3s ease;
        }
        #aic-progress-text {
            text-align: center;
            font-weight: 600;
            color: #23282d;
        }
        
        /* Custom Scripts Manager styles */
        .csm-textarea {
            width: 100%;
            height: 120px;
            font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
            font-size: 13px;
            line-height: 1.4;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 10px;
            background: #f9f9f9;
        }
        .csm-section {
            margin: 15px 0;
        }
        .csm-label {
            font-weight: bold;
            margin-bottom: 5px;
            display: block;
            color: #23282d;
        }
        
        /* Responsive design */
        @media (max-width: 768px) {
            .wpsa-modules-grid {
                grid-template-columns: 1fr;
            }
        }
        </style>
        <?php
    }
}

// Add contextual help
add_action('load-toplevel_page_wp-starter-addon', 'wpsa_add_help_tab');
function wpsa_add_help_tab() {
    $screen = get_current_screen();
    
    $screen->add_help_tab(array(
        'id' => 'wpsa_overview',
        'title' => 'Overview',
        'content' => '
            <h3>WP Starter Addon Overview</h3>
            <p>WP Starter Addon is a comprehensive WordPress toolkit that combines four powerful modules:</p>
            <ul>
                <li><strong>Custom Scripts Manager:</strong> Add custom scripts to head or body sections</li>
                <li><strong>SMTP Mailer:</strong> Configure reliable email delivery with SMTP</li>
                <li><strong>Image Optimizer:</strong> Automatically convert and optimize images</li>
                <li><strong>Email 2FA:</strong> Add two-factor authentication for enhanced security</li>
            </ul>
            <p>You can enable or disable any module as needed from the main settings page.</p>
        '
    ));
    
    $screen->add_help_tab(array(
        'id' => 'wpsa_modules',
        'title' => 'Module Details',
        'content' => '
            <h3>Module Details</h3>
            <h4>Custom Scripts Manager</h4>
            <p>Add analytics codes, custom CSS, JavaScript, and other scripts to your website globally or per page/post.</p>
            
            <h4>SMTP Mailer</h4>
            <p>Configure SMTP settings for reliable email delivery. Supports all major email providers.</p>
            
            <h4>Image Optimizer</h4>
            <p>Automatically converts uploaded images to WebP or AVIF format with customizable compression and resizing.</p>
            
            <h4>Email 2FA</h4>
            <p>Adds an extra layer of security by requiring email verification codes during login.</p>
        '
    ));
    
    $screen->set_help_sidebar('
        <p><strong>For more information:</strong></p>
        <p><a href="https://github.com/drshounak/wordpress-plugins" target="_blank">GitHub Repository</a></p>
        <p><a href="https://twitter.com/drshounakpal" target="_blank">Support on Twitter</a></p>
    ');
}

// Version check and update notice
add_action('admin_notices', 'wpsa_version_check');
function wpsa_version_check() {
    $current_version = get_option('wpsa_version', '0.0.0');
    if (version_compare($current_version, WPSA_VERSION, '<')) {
        update_option('wpsa_version', WPSA_VERSION);
        ?>
        <div class="notice notice-info is-dismissible">
            <p><strong>WP Starter Addon</strong> has been updated to version <?php echo WPSA_VERSION; ?>. <a href="<?php echo admin_url('admin.php?page=wp-starter-addon'); ?>">Review your settings</a>.</p>
        </div>
        <?php
    }
}

?>
