<?php
/**
 * Plugin Name: WP Starter Addon by TechWeirdo
 * Plugin URI: https://github.com/drshounak/wordpress-plugins/tree/main/wp-starter-addon
 * Description: Complete WordPress toolkit with Custom Scripts Manager, SMTP Mailer, Image Optimizer, and Email 2FA - all in one plugin
 * Version: 2.0.0
 * Author: TechWeirdo
 * Author URI: https://twitter.com/drshounakpal
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-starter-addon
 * Domain Path: /languages
 * 
 * @package TechWeirdo
 * @author Dr. Shounak Pal
 * @since 2.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WPSA_VERSION', '2.0.0');
define('WPSA_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WPSA_PLUGIN_PATH', plugin_dir_path(__FILE__));

/**
 * Main WP Starter Addon Class
 */
class WPStarterAddon {
    
    private static $instance = null;
    private $modules = array();
    
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
        $this->load_modules();
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        add_action('plugins_loaded', array($this, 'load_textdomain'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('admin_init', array($this, 'migrate_old_settings'));
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Set default module states
        $default_modules = array(
            'custom_scripts' => true,
            'smtp_mailer' => true,
            'image_optimizer' => true,
            'email_2fa' => true
        );
        
        add_option('wpsa_modules', $default_modules);
        add_option('wpsa_version', WPSA_VERSION);
        
        // Migrate settings from old plugins if they exist
        $this->migrate_old_settings();
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clean up scheduled events
        wp_clear_scheduled_hook('email_otp_cleanup_event');
    }
    
    /**
     * Load text domain
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'wp-starter-addon', 
            false, 
            dirname(plugin_basename(__FILE__)) . '/languages'
        );
    }
    
    /**
     * Migrate settings from old plugins
     */
    public function migrate_old_settings() {
        // Only run migration once
        if (get_option('wpsa_migration_done')) {
            return;
        }
        
        // Migrate Custom Scripts Manager settings
        if (get_option('csm_global_head_scripts') !== false) {
            update_option('wpsa_csm_global_head_scripts', get_option('csm_global_head_scripts'));
            update_option('wpsa_csm_global_body_scripts', get_option('csm_global_body_scripts'));
        }
        
        // Migrate SMTP settings
        if (get_option('ssm_settings') !== false) {
            update_option('wpsa_smtp_settings', get_option('ssm_settings'));
        }
        
        // Migrate Image Optimizer settings
        if (get_option('aic_settings') !== false) {
            update_option('wpsa_image_settings', get_option('aic_settings'));
        }
        
        // Migrate Email 2FA settings
        if (get_option('email_otp_login_enabled') !== false) {
            $otp_settings = array(
                'enabled' => get_option('email_otp_login_enabled', '1'),
                'expiry' => get_option('email_otp_login_expiry', '10'),
                'rate_limit' => get_option('email_otp_login_rate_limit', '60')
            );
            update_option('wpsa_otp_settings', $otp_settings);
        }
        
        update_option('wpsa_migration_done', true);
    }
    
    /**
     * Load modules based on settings
     */
    private function load_modules() {
        $enabled_modules = get_option('wpsa_modules', array());
        
        if (!empty($enabled_modules['custom_scripts'])) {
            $this->modules['custom_scripts'] = new WPSA_CustomScripts();
        }
        
        if (!empty($enabled_modules['smtp_mailer'])) {
            $this->modules['smtp_mailer'] = new WPSA_SMTPMailer();
        }
        
        if (!empty($enabled_modules['image_optimizer'])) {
            $this->modules['image_optimizer'] = new WPSA_ImageOptimizer();
        }
        
        if (!empty($enabled_modules['email_2fa'])) {
            $this->modules['email_2fa'] = new WPSA_Email2FA();
        }
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            __('WP Starter Addon', 'wp-starter-addon'),
            __('WP Starter Addon', 'wp-starter-addon'),
            'manage_options',
            'wp-starter-addon',
            array($this, 'admin_dashboard'),
            'dashicons-admin-tools',
            30
        );
        
        // Add submenus for each module
        $enabled_modules = get_option('wpsa_modules', array());
        
        if (!empty($enabled_modules['custom_scripts'])) {
            add_submenu_page(
                'wp-starter-addon',
                __('Custom Scripts', 'wp-starter-addon'),
                __('Custom Scripts', 'wp-starter-addon'),
                'manage_options',
                'wpsa-custom-scripts',
                array($this, 'custom_scripts_page')
            );
        }
        
        if (!empty($enabled_modules['smtp_mailer'])) {
            add_submenu_page(
                'wp-starter-addon',
                __('SMTP Settings', 'wp-starter-addon'),
                __('SMTP Settings', 'wp-starter-addon'),
                'manage_options',
                'wpsa-smtp-settings',
                array($this, 'smtp_settings_page')
            );
        }
        
        if (!empty($enabled_modules['image_optimizer'])) {
            add_submenu_page(
                'wp-starter-addon',
                __('Image Optimizer', 'wp-starter-addon'),
                __('Image Optimizer', 'wp-starter-addon'),
                'manage_options',
                'wpsa-image-optimizer',
                array($this, 'image_optimizer_page')
            );
        }
        
        if (!empty($enabled_modules['email_2fa'])) {
            add_submenu_page(
                'wp-starter-addon',
                __('Email 2FA', 'wp-starter-addon'),
                __('Email 2FA', 'wp-starter-addon'),
                'manage_options',
                'wpsa-email-2fa',
                array($this, 'email_2fa_page')
            );
        }
        
        // Settings submenu
        add_submenu_page(
            'wp-starter-addon',
            __('Module Settings', 'wp-starter-addon'),
            __('Module Settings', 'wp-starter-addon'),
            'manage_options',
            'wpsa-module-settings',
            array($this, 'module_settings_page')
        );
    }
    
    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'wp-starter-addon') !== false) {
            wp_enqueue_style(
                'wpsa-admin-style',
                WPSA_PLUGIN_URL . 'assets/admin.css',
                array(),
                WPSA_VERSION
            );
            
            wp_enqueue_script(
                'wpsa-admin-script',
                WPSA_PLUGIN_URL . 'assets/admin.js',
                array('jquery'),
                WPSA_VERSION,
                true
            );
        }
    }
    
    /**
     * Main dashboard page
     */
    public function admin_dashboard() {
        $enabled_modules = get_option('wpsa_modules', array());
        ?>
        <div class="wrap wpsa-dashboard">
            <h1><?php _e('WP Starter Addon by TechWeirdo', 'wp-starter-addon'); ?></h1>
            
            <div class="wpsa-dashboard-grid">
                <div class="wpsa-module-card <?php echo !empty($enabled_modules['custom_scripts']) ? 'enabled' : 'disabled'; ?>">
                    <div class="wpsa-module-icon">📝</div>
                    <h3><?php _e('Custom Scripts Manager', 'wp-starter-addon'); ?></h3>
                    <p><?php _e('Add custom scripts to head or body for entire site or specific pages/posts', 'wp-starter-addon'); ?></p>
                    <div class="wpsa-module-actions">
                        <?php if (!empty($enabled_modules['custom_scripts'])): ?>
                            <a href="<?php echo admin_url('admin.php?page=wpsa-custom-scripts'); ?>" class="button button-primary"><?php _e('Configure', 'wp-starter-addon'); ?></a>
                        <?php else: ?>
                            <span class="wpsa-disabled-text"><?php _e('Module Disabled', 'wp-starter-addon'); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="wpsa-module-card <?php echo !empty($enabled_modules['smtp_mailer']) ? 'enabled' : 'disabled'; ?>">
                    <div class="wpsa-module-icon">📧</div>
                    <h3><?php _e('SMTP Mailer', 'wp-starter-addon'); ?></h3>
                    <p><?php _e('Professional SMTP plugin with custom server settings for reliable email delivery', 'wp-starter-addon'); ?></p>
                    <div class="wpsa-module-actions">
                        <?php if (!empty($enabled_modules['smtp_mailer'])): ?>
                            <a href="<?php echo admin_url('admin.php?page=wpsa-smtp-settings'); ?>" class="button button-primary"><?php _e('Configure', 'wp-starter-addon'); ?></a>
                        <?php else: ?>
                            <span class="wpsa-disabled-text"><?php _e('Module Disabled', 'wp-starter-addon'); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="wpsa-module-card <?php echo !empty($enabled_modules['image_optimizer']) ? 'enabled' : 'disabled'; ?>">
                    <div class="wpsa-module-icon">🖼️</div>
                    <h3><?php _e('Image Optimizer', 'wp-starter-addon'); ?></h3>
                    <p><?php _e('Converts uploaded images to WebP format automatically with customizable compression', 'wp-starter-addon'); ?></p>
                    <div class="wpsa-module-actions">
                        <?php if (!empty($enabled_modules['image_optimizer'])): ?>
                            <a href="<?php echo admin_url('admin.php?page=wpsa-image-optimizer'); ?>" class="button button-primary"><?php _e('Configure', 'wp-starter-addon'); ?></a>
                        <?php else: ?>
                            <span class="wpsa-disabled-text"><?php _e('Module Disabled', 'wp-starter-addon'); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="wpsa-module-card <?php echo !empty($enabled_modules['email_2fa']) ? 'enabled' : 'disabled'; ?>">
                    <div class="wpsa-module-icon">🔐</div>
                    <h3><?php _e('Email 2FA', 'wp-starter-addon'); ?></h3>
                    <p><?php _e('Adds two-factor authentication using email OTP codes for WordPress login security', 'wp-starter-addon'); ?></p>
                    <div class="wpsa-module-actions">
                        <?php if (!empty($enabled_modules['email_2fa'])): ?>
                            <a href="<?php echo admin_url('admin.php?page=wpsa-email-2fa'); ?>" class="button button-primary"><?php _e('Configure', 'wp-starter-addon'); ?></a>
                        <?php else: ?>
                            <span class="wpsa-disabled-text"><?php _e('Module Disabled', 'wp-starter-addon'); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="wpsa-quick-actions">
                <h2><?php _e('Quick Actions', 'wp-starter-addon'); ?></h2>
                <a href="<?php echo admin_url('admin.php?page=wpsa-module-settings'); ?>" class="button button-secondary"><?php _e('Enable/Disable Modules', 'wp-starter-addon'); ?></a>
            </div>
            
            <div class="wpsa-info-box">
                <h3><?php _e('About WP Starter Addon', 'wp-starter-addon'); ?></h3>
                <p><?php _e('This comprehensive WordPress toolkit combines four essential plugins into one powerful solution. Each module can be enabled or disabled independently based on your needs.', 'wp-starter-addon'); ?></p>
                <p><strong><?php _e('Version:', 'wp-starter-addon'); ?></strong> <?php echo WPSA_VERSION; ?></p>
                <p><strong><?php _e('Author:', 'wp-starter-addon'); ?></strong> <a href="https://twitter.com/drshounakpal" target="_blank">TechWeirdo</a></p>
            </div>
        </div>
        
        <style>
        .wpsa-dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }
        
        .wpsa-module-card {
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            transition: all 0.3s ease;
        }
        
        .wpsa-module-card.enabled {
            border-color: #00a32a;
            box-shadow: 0 2px 8px rgba(0, 163, 42, 0.1);
        }
        
        .wpsa-module-card.disabled {
            opacity: 0.6;
            background: #f6f7f7;
        }
        
        .wpsa-module-icon {
            font-size: 48px;
            margin-bottom: 15px;
        }
        
        .wpsa-module-card h3 {
            margin: 0 0 10px 0;
            color: #1d2327;
        }
        
        .wpsa-module-card p {
            color: #646970;
            margin-bottom: 20px;
            line-height: 1.5;
        }
        
        .wpsa-disabled-text {
            color: #d63638;
            font-weight: 500;
        }
        
        .wpsa-quick-actions {
            margin: 30px 0;
            padding: 20px;
            background: #f0f6fc;
            border: 1px solid #c3c4c7;
            border-radius: 4px;
        }
        
        .wpsa-info-box {
            margin: 30px 0;
            padding: 20px;
            background: #fff;
            border: 1px solid #c3c4c7;
            border-radius: 4px;
        }
        </style>
        <?php
    }
    
    /**
     * Module settings page
     */
    public function module_settings_page() {
        if (isset($_POST['submit'])) {
            check_admin_referer('wpsa_module_settings');
            
            $modules = array(
                'custom_scripts' => isset($_POST['custom_scripts']),
                'smtp_mailer' => isset($_POST['smtp_mailer']),
                'image_optimizer' => isset($_POST['image_optimizer']),
                'email_2fa' => isset($_POST['email_2fa'])
            );
            
            update_option('wpsa_modules', $modules);
            
            echo '<div class="notice notice-success"><p>' . __('Module settings saved successfully!', 'wp-starter-addon') . '</p></div>';
            
            // Reload modules
            $this->load_modules();
        }
        
        $enabled_modules = get_option('wpsa_modules', array());
        ?>
        <div class="wrap">
            <h1><?php _e('Module Settings', 'wp-starter-addon'); ?></h1>
            <p><?php _e('Enable or disable individual modules based on your needs. Disabled modules will not load, improving performance.', 'wp-starter-addon'); ?></p>
            
            <form method="post" action="">
                <?php wp_nonce_field('wpsa_module_settings'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Custom Scripts Manager', 'wp-starter-addon'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="custom_scripts" value="1" <?php checked(!empty($enabled_modules['custom_scripts'])); ?>>
                                <?php _e('Enable Custom Scripts Manager', 'wp-starter-addon'); ?>
                            </label>
                            <p class="description"><?php _e('Add custom scripts to head or body for entire site or specific pages/posts', 'wp-starter-addon'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php _e('SMTP Mailer', 'wp-starter-addon'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="smtp_mailer" value="1" <?php checked(!empty($enabled_modules['smtp_mailer'])); ?>>
                                <?php _e('Enable SMTP Mailer', 'wp-starter-addon'); ?>
                            </label>
                            <p class="description"><?php _e('Professional SMTP plugin with custom server settings for reliable email delivery', 'wp-starter-addon'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php _e('Image Optimizer', 'wp-starter-addon'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="image_optimizer" value="1" <?php checked(!empty($enabled_modules['image_optimizer'])); ?>>
                                <?php _e('Enable Image Optimizer', 'wp-starter-addon'); ?>
                            </label>
                            <p class="description"><?php _e('Converts uploaded images to WebP format automatically with customizable compression', 'wp-starter-addon'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php _e('Email 2FA', 'wp-starter-addon'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="email_2fa" value="1" <?php checked(!empty($enabled_modules['email_2fa'])); ?>>
                                <?php _e('Enable Email 2FA', 'wp-starter-addon'); ?>
                            </label>
                            <p class="description"><?php _e('Adds two-factor authentication using email OTP codes for WordPress login security', 'wp-starter-addon'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
    
    /**
     * Custom Scripts page
     */
    public function custom_scripts_page() {
        if (isset($this->modules['custom_scripts'])) {
            $this->modules['custom_scripts']->admin_page();
        }
    }
    
    /**
     * SMTP Settings page
     */
    public function smtp_settings_page() {
        if (isset($this->modules['smtp_mailer'])) {
            $this->modules['smtp_mailer']->admin_page();
        }
    }
    
    /**
     * Image Optimizer page
     */
    public function image_optimizer_page() {
        if (isset($this->modules['image_optimizer'])) {
            $this->modules['image_optimizer']->options_page();
        }
    }
    
    /**
     * Email 2FA page
     */
    public function email_2fa_page() {
        if (isset($this->modules['email_2fa'])) {
            $this->modules['email_2fa']->admin_page();
        }
    }
}

/**
 * Custom Scripts Module
 */
class WPSA_CustomScripts {
    
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
                'wpsa-custom-scripts-meta-box',
                'Custom Scripts',
                array($this, 'meta_box_callback'),
                $post_type,
                'normal',
                'high'
            );
        }
    }
    
    public function meta_box_callback($post) {
        wp_nonce_field('wpsa_custom_scripts_meta_box_action', 'wpsa_custom_scripts_meta_box_nonce');
        
        $head_scripts = get_post_meta($post->ID, '_wpsa_csm_head_scripts', true);
        $body_scripts = get_post_meta($post->ID, '_wpsa_csm_body_scripts', true);
        
        // Check for old meta keys for backward compatibility
        if (empty($head_scripts)) {
            $head_scripts = get_post_meta($post->ID, '_csm_head_scripts', true);
        }
        if (empty($body_scripts)) {
            $body_scripts = get_post_meta($post->ID, '_csm_body_scripts', true);
        }
        
        ?>
        <style>
            .wpsa-textarea { width: 100%; height: 120px; font-family: monospace; }
            .wpsa-section { margin: 15px 0; }
            .wpsa-label { font-weight: bold; margin-bottom: 5px; display: block; }
        </style>
        
        <div class="wpsa-section">
            <label class="wpsa-label">Head Scripts (before &lt;/head&gt;)</label>
            <textarea name="wpsa_csm_head_scripts" class="wpsa-textarea" placeholder="Enter scripts to be added in the head section..."><?php echo esc_textarea($head_scripts); ?></textarea>
        </div>
        
        <div class="wpsa-section">
            <label class="wpsa-label">Body Scripts (before &lt;/body&gt;)</label>
            <textarea name="wpsa_csm_body_scripts" class="wpsa-textarea" placeholder="Enter scripts to be added before closing body tag..."><?php echo esc_textarea($body_scripts); ?></textarea>
        </div>
        
        <p><small><strong>Note:</strong> These scripts will only apply to this specific post/page. For site-wide scripts, use the <a href="<?php echo admin_url('admin.php?page=wpsa-custom-scripts'); ?>">Custom Scripts settings page</a>.</small></p>
        <?php
    }
    
    public function save_post_scripts($post_id) {
        if (!isset($_POST['wpsa_custom_scripts_meta_box_nonce']) || 
            !wp_verify_nonce($_POST['wpsa_custom_scripts_meta_box_nonce'], 'wpsa_custom_scripts_meta_box_action')) {
            return;
        }
        
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        if (isset($_POST['wpsa_csm_head_scripts'])) {
            $head_scripts = wp_unslash($_POST['wpsa_csm_head_scripts']);
            update_post_meta($post_id, '_wpsa_csm_head_scripts', $head_scripts);
        } else {
            delete_post_meta($post_id, '_wpsa_csm_head_scripts');
        }
        
        if (isset($_POST['wpsa_csm_body_scripts'])) {
            $body_scripts = wp_unslash($_POST['wpsa_csm_body_scripts']);
            update_post_meta($post_id, '_wpsa_csm_body_scripts', $body_scripts);
        } else {
            delete_post_meta($post_id, '_wpsa_csm_body_scripts');
        }
    }
    
    public function output_head_scripts() {
        // Global head scripts
        $global_head_scripts = get_option('wpsa_csm_global_head_scripts', '');
        
        // Check old option for backward compatibility
        if (empty($global_head_scripts)) {
            $global_head_scripts = get_option('csm_global_head_scripts', '');
        }
        
        if (!empty(trim($global_head_scripts))) {
            echo "\n<!-- WP Starter Addon - Global Head Scripts -->\n";
            echo $global_head_scripts . "\n";
            echo "<!-- End WP Starter Addon - Global Head Scripts -->\n";
        }
        
        // Page-specific head scripts
        if (is_singular()) {
            global $post;
            if ($post) {
                $post_head_scripts = get_post_meta($post->ID, '_wpsa_csm_head_scripts', true);
                
                // Check old meta key for backward compatibility
                if (empty($post_head_scripts)) {
                    $post_head_scripts = get_post_meta($post->ID, '_csm_head_scripts', true);
                }
                
                if (!empty(trim($post_head_scripts))) {
                    echo "\n<!-- WP Starter Addon - Page Specific Head Scripts -->\n";
                    echo $post_head_scripts . "\n";
                    echo "<!-- End WP Starter Addon - Page Specific Head Scripts -->\n";
                }
            }
        }
    }
    
    public function output_body_scripts() {
        // Global body scripts
        $global_body_scripts = get_option('wpsa_csm_global_body_scripts', '');
        
        // Check old option for backward compatibility
        if (empty($global_body_scripts)) {
            $global_body_scripts = get_option('csm_global_body_scripts', '');
        }
        
        if (!empty(trim($global_body_scripts))) {
            echo "\n<!-- WP Starter Addon - Global Body Scripts -->\n";
            echo $global_body_scripts . "\n";
            echo "<!-- End WP Starter Addon - Global Body Scripts -->\n";
        }
        
        // Page-specific body scripts
        if (is_singular()) {
            global $post;
            if ($post) {
                $post_body_scripts = get_post_meta($post->ID, '_wpsa_csm_body_scripts', true);
                
                // Check old meta key for backward compatibility
                if (empty($post_body_scripts)) {
                    $post_body_scripts = get_post_meta($post->ID, '_csm_body_scripts', true);
                }
                
                if (!empty(trim($post_body_scripts))) {
                    echo "\n<!-- WP Starter Addon - Page Specific Body Scripts -->\n";
                    echo $post_body_scripts . "\n";
                    echo "<!-- End WP Starter Addon - Page Specific Body Scripts -->\n";
                }
            }
        }
    }
    
    public function admin_page() {
        if (isset($_POST['submit']) && check_admin_referer('wpsa_csm_settings_action', 'wpsa_csm_settings_nonce')) {
            $head_scripts = isset($_POST['wpsa_csm_global_head_scripts']) ? wp_unslash($_POST['wpsa_csm_global_head_scripts']) : '';
            $body_scripts = isset($_POST['wpsa_csm_global_body_scripts']) ? wp_unslash($_POST['wpsa_csm_global_body_scripts']) : '';
            
            update_option('wpsa_csm_global_head_scripts', $head_scripts);
            update_option('wpsa_csm_global_body_scripts', $body_scripts);
            
            echo '<div class="notice notice-success is-dismissible"><p><strong>Settings saved successfully!</strong></p></div>';
        }
        
        // Get current values with backward compatibility
        $global_head_scripts = get_option('wpsa_csm_global_head_scripts', '');
        if (empty($global_head_scripts)) {
            $global_head_scripts = get_option('csm_global_head_scripts', '');
        }
        
        $global_body_scripts = get_option('wpsa_csm_global_body_scripts', '');
        if (empty($global_body_scripts)) {
            $global_body_scripts = get_option('csm_global_body_scripts', '');
        }
        
        ?>
        <div class="wrap">
            <h1>Custom Scripts Manager</h1>
            <p>Add custom scripts to your entire website or specific pages/posts. Scripts can be added to the head section or before the closing body tag.</p>
            
            <form method="post" action="">
                <?php wp_nonce_field('wpsa_csm_settings_action', 'wpsa_csm_settings_nonce'); ?>
                
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="wpsa_csm_global_head_scripts">Global Head Scripts</label>
                            </th>
                            <td>
                                <textarea name="wpsa_csm_global_head_scripts" id="wpsa_csm_global_head_scripts" rows="10" cols="80" class="large-text code"><?php echo esc_textarea($global_head_scripts); ?></textarea>
                                <p class="description">These scripts will be added to the &lt;head&gt; section of every page (analytics, fonts, meta tags, etc.)</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="wpsa_csm_global_body_scripts">Global Body Scripts</label>
                            </th>
                            <td>
                                <textarea name="wpsa_csm_global_body_scripts" id="wpsa_csm_global_body_scripts" rows="10" cols="80" class="large-text code"><?php echo esc_textarea($global_body_scripts); ?></textarea>
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
            
            <hr>
            
            <h2>Page/Post Specific Scripts</h2>
            <p>To add scripts to specific pages or posts, edit the page/post and look for the "Custom Scripts" meta box.</p>
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
        add_action('admin_init', array($this, 'admin_init'));
        add_action('phpmailer_init', array($this, 'configure_phpmailer'));
        add_action('wp_ajax_wpsa_test_email', array($this, 'test_email'));
    }

    public function init() {
        $this->options = get_option('wpsa_smtp_settings', array());
        
        // Check for old settings for backward compatibility
        if (empty($this->options)) {
            $old_settings = get_option('ssm_settings', array());
            if (!empty($old_settings)) {
                $this->options = $old_settings;
                update_option('wpsa_smtp_settings', $old_settings);
            }
        }
        
        // Set defaults if still empty
        if (empty($this->options)) {
            $this->options = array(
                'smtp_host'      => '',
                'smtp_port'      => '587',
                'smtp_encryption'=> 'tls',
                'smtp_auth'      => 1,
                'smtp_username'  => '',
                'smtp_password'  => '',
                'from_email'     => get_option('admin_email'),
                'from_name'      => get_option('blogname'),
                'debug_mode'     => 0,
            );
        }
    }

    public function admin_init() {
        register_setting('wpsa_smtp_settings_group', 'wpsa_smtp_settings', array($this, 'sanitize_settings'));
    }

    public function sanitize_settings($input) {
        $sanitized = array();
        $sanitized['smtp_host']       = sanitize_text_field($input['smtp_host']);
        $sanitized['smtp_port']       = intval($input['smtp_port']);
        $sanitized['smtp_encryption'] = sanitize_text_field($input['smtp_encryption']);
        $sanitized['smtp_username']   = sanitize_text_field($input['smtp_username']);
        $sanitized['smtp_password']   = $input['smtp_password'];
        $sanitized['from_email']      = sanitize_email($input['from_email']);
        $sanitized['from_name']       = sanitize_text_field($input['from_name']);
        $sanitized['debug_mode']      = isset($input['debug_mode']) ? 1 : 0;
        return $sanitized;
    }

    public function configure_phpmailer($phpmailer) {
        if (empty($this->options['smtp_host'])) {
            return;
        }
        
        $phpmailer->isSMTP();
        $phpmailer->Host       = $this->options['smtp_host'];
        $phpmailer->SMTPAuth   = true;
        $phpmailer->Port       = $this->options['smtp_port'];
        $phpmailer->SMTPSecure = $this->options['smtp_encryption'];
        $phpmailer->Username   = $this->options['smtp_username'];
        $phpmailer->Password   = $this->options['smtp_password'];
        $phpmailer->setFrom(
            $this->options['from_email'],
            $this->options['from_name']
        );
        if (!empty($this->options['debug_mode'])) {
            $phpmailer->SMTPDebug = 2;
        }
    }

    public function test_email() {
        check_ajax_referer('wpsa_test_email', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        $to      = sanitize_email($_POST['test_email']);
        $subject = 'Test Email from WP Starter Addon SMTP Mailer';
        $message = 'This is a test email to verify your SMTP configuration.';
        $result  = wp_mail($to, $subject, $message);
        if ($result) {
            wp_send_json_success('Test email sent successfully!');
        } else {
            wp_send_json_error('Failed to send test email. Please check your settings.');
        }
    }

    public function admin_page() {
        if (isset($_POST['submit'])) {
            check_admin_referer('wpsa_smtp_settings_group-options');
            
            $settings = $this->sanitize_settings($_POST['wpsa_smtp_settings']);
            update_option('wpsa_smtp_settings', $settings);
            $this->options = $settings;
            
            echo '<div class="notice notice-success"><p>SMTP settings saved successfully!</p></div>';
        }
        ?>
        <div class="wrap">
            <h1>SMTP Mailer Settings</h1>
            <form method="post" action="">
                <?php settings_fields('wpsa_smtp_settings_group'); ?>
                
                <h2>General Settings</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">From Email</th>
                        <td>
                            <input type="email" name="wpsa_smtp_settings[from_email]" value="<?php echo esc_attr($this->options['from_email'] ?? get_option('admin_email')); ?>" class="regular-text" required />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">From Name</th>
                        <td>
                            <input type="text" name="wpsa_smtp_settings[from_name]" value="<?php echo esc_attr($this->options['from_name'] ?? get_option('blogname')); ?>" class="regular-text" />
                        </td>
                    </tr>
                </table>
                
                <h2>SMTP Settings</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">SMTP Host</th>
                        <td>
                            <input type="text" name="wpsa_smtp_settings[smtp_host]" value="<?php echo esc_attr($this->options['smtp_host'] ?? ''); ?>" class="regular-text" required />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">SMTP Port</th>
                        <td>
                            <input type="number" name="wpsa_smtp_settings[smtp_port]" value="<?php echo esc_attr($this->options['smtp_port'] ?? '587'); ?>" class="small-text" required />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Encryption</th>
                        <td>
                            <select name="wpsa_smtp_settings[smtp_encryption]">
                                <option value="none" <?php selected($this->options['smtp_encryption'] ?? 'tls', 'none'); ?>>None</option>
                                <option value="ssl" <?php selected($this->options['smtp_encryption'] ?? 'tls', 'ssl'); ?>>SSL</option>
                                <option value="tls" <?php selected($this->options['smtp_encryption'] ?? 'tls', 'tls'); ?>>TLS</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Username</th>
                        <td>
                            <input type="text" name="wpsa_smtp_settings[smtp_username]" value="<?php echo esc_attr($this->options['smtp_username'] ?? ''); ?>" class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Password</th>
                        <td>
                            <input type="password" name="wpsa_smtp_settings[smtp_password]" value="<?php echo esc_attr($this->options['smtp_password'] ?? ''); ?>" class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Debug Mode</th>
                        <td>
                            <label>
                                <input type="checkbox" name="wpsa_smtp_settings[debug_mode]" value="1" <?php checked(!empty($this->options['debug_mode'])); ?> />
                                Enable debug output
                            </label>
                        </td>
                    </tr>
                </table>
                
                <h2>Send Test Email</h2>
                <input type="email" id="test-email-address" placeholder="Enter test email address" class="regular-text" />
                <button type="button" id="send-test-email" class="button button-primary">Send Test Email</button>
                <div id="test-result" style="margin-top:10px;font-weight:bold;"></div>
                
                <?php submit_button(); ?>
            </form>
        </div>
        <script>
        document.getElementById('send-test-email').addEventListener('click', function() {
            var email  = document.getElementById('test-email-address').value;
            var result = document.getElementById('test-result');
            if (!email) {
                result.innerHTML = '<span style="color:red;">Please enter an email address.</span>';
                return;
            }
            this.disabled = true;
            this.textContent = 'Sending...';
            var data = new FormData();
            data.append('action', 'wpsa_test_email');
            data.append('test_email', email);
            data.append('nonce', '<?php echo wp_create_nonce('wpsa_test_email'); ?>');
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
        add_action('wp_ajax_wpsa_convert_single_image', array($this, 'ajax_convert_single_image'));
    }
    
    public function init() {
        $this->options = get_option('wpsa_image_settings', array());
        
        // Check for old settings for backward compatibility
        if (empty($this->options)) {
            $old_settings = get_option('aic_settings', array());
            if (!empty($old_settings)) {
                $this->options = $old_settings;
                update_option('wpsa_image_settings', $old_settings);
            }
        }
        
        // Set defaults if still empty
        if (empty($this->options)) {
            $this->options = array(
                'max_width' => 2400,
                'max_height' => 2400,
                'output_format' => 'webp',
                'quality' => 85,
                'keep_original' => false,
                'auto_convert' => true
            );
        }
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
        $bulk_actions['wpsa_convert_images'] = 'Convert Images';
        return $bulk_actions;
    }
    
    public function handle_bulk_action($redirect_to, $doaction, $post_ids) {
        if ($doaction !== 'wpsa_convert_images') {
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
            $actions['wpsa_convert'] = '<a href="#" onclick="wpsaConvertSingleImage(' . $post->ID . '); return false;">Convert Image</a>';
        }
        return $actions;
    }
    
    public function ajax_convert_single_image() {
        check_ajax_referer('wpsa_convert_single_image', 'nonce');
        
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
            check_admin_referer('wpsa_image_settings');
            
            $settings = array(
                'max_width' => max(100, min(5000, intval($_POST['max_width']))),
                'max_height' => max(100, min(5000, intval($_POST['max_height']))),
                'output_format' => sanitize_text_field($_POST['output_format']),
                'quality' => max(1, min(100, intval($_POST['quality']))),
                'keep_original' => isset($_POST['keep_original']),
                'auto_convert' => isset($_POST['auto_convert'])
            );
            
            update_option('wpsa_image_settings', $settings);
            $this->options = $settings;
            
            echo '<div class="notice notice-success"><p>Image optimizer settings saved successfully!</p></div>';
        }
        ?>
        <div class="wrap">
            <h1>Image Optimizer Settings</h1>
            <form method="post" action="">
                <?php wp_nonce_field('wpsa_image_settings'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">Max Width (px)</th>
                        <td>
                            <input type="number" name="max_width" value="<?php echo esc_attr($this->options['max_width']); ?>" min="100" max="5000" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Max Height (px)</th>
                        <td>
                            <input type="number" name="max_height" value="<?php echo esc_attr($this->options['max_height']); ?>" min="100" max="5000" />
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
                            <input type="number" name="quality" value="<?php echo esc_attr($this->options['quality']); ?>" min="1" max="100" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Keep Original Files</th>
                        <td>
                            <label>
                                <input type="checkbox" name="keep_original" value="1" <?php checked($this->options['keep_original']); ?> />
                                Keep original files on server
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Auto Convert on Upload</th>
                        <td>
                            <label>
                                <input type="checkbox" name="auto_convert" value="1" <?php checked($this->options['auto_convert']); ?> />
                                Automatically convert images on upload
                            </label>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
            
            <h2>Bulk Convert Existing Images</h2>
            <p>Go to Media Library and use the bulk action "Convert Images" to convert existing images.</p>
            
            <div id="wpsa-bulk-convert">
                <button type="button" id="wpsa-convert-all" class="button button-primary">Convert All Images</button>
                <div id="wpsa-progress" style="display:none;">
                    <div id="wpsa-progress-bar" style="width: 100%; background-color: #f0f0f0; border-radius: 3px; margin: 10px 0;">
                        <div id="wpsa-progress-fill" style="height: 20px; background-color: #0073aa; border-radius: 3px; width: 0%; transition: width 0.3s;"></div>
                    </div>
                    <div id="wpsa-progress-text">0% Complete</div>
                </div>
            </div>
        </div>
        
        <script>
        function wpsaConvertSingleImage(attachmentId) {
            if (confirm('Convert this image?')) {
                jQuery.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wpsa_convert_single_image',
                        attachment_id: attachmentId,
                        nonce: '<?php echo wp_create_nonce('wpsa_convert_single_image'); ?>'
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
        
        jQuery(document).ready(function($) {
            $('#wpsa-convert-all').click(function() {
                if (confirm('This will convert all images in your media library. This may take a while. Continue?')) {
                    convertAllImages();
                }
            });
            
            function convertAllImages() {
                $('#wpsa-progress').show();
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wpsa_get_all_images'
                    },
                    success: function(response) {
                        if (response.success) {
                            var images = response.data;
                            var total = images.length;
                            var converted = 0;
                            
                            function convertNext() {
                                if (converted >= total) {
                                    alert('All images converted!');
                                    $('#wpsa-progress').hide();
                                    return;
                                }
                                
                                var imageId = images[converted];
                                $.ajax({
                                    url: ajaxurl,
                                    type: 'POST',
                                    data: {
                                        action: 'wpsa_convert_single_image',
                                        attachment_id: imageId,
                                        nonce: '<?php echo wp_create_nonce('wpsa_convert_single_image'); ?>'
                                    },
                                    complete: function() {
                                        converted++;
                                        var percent = (converted / total) * 100;
                                        $('#wpsa-progress-fill').css('width', percent + '%');
                                        $('#wpsa-progress-text').text(Math.round(percent) + '% Complete (' + converted + '/' + total + ')');
                                        
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

/**
 * Email 2FA Module
 */
class WPSA_Email2FA {
    
    private $options;
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('login_init', array($this, 'custom_login_handler'));
        add_action('login_init', array($this, 'add_otp_verification_form'));
        add_action('login_init', array($this, 'handle_resend_otp'));
        add_action('login_errors', array($this, 'display_otp_error_messages'));
        add_action('show_user_profile', array($this, 'otp_settings_fields'));
        add_action('edit_user_profile', array($this, 'otp_settings_fields'));
        add_action('personal_options_update', array($this, 'save_otp_settings'));
        add_action('edit_user_profile_update', array($this, 'save_otp_settings'));
        add_action('wp_scheduled_delete', array($this, 'cleanup_expired_otp_codes'));
        
        // Schedule cleanup if not already scheduled
        if (!wp_next_scheduled('email_otp_cleanup_event')) {
            wp_schedule_event(time(), 'daily', 'email_otp_cleanup_event');
        }
        add_action('email_otp_cleanup_event', array($this, 'cleanup_expired_otp_codes'));
    }
    
    public function init() {
        $this->options = get_option('wpsa_otp_settings', array());
        
        // Check for old settings for backward compatibility
        if (empty($this->options)) {
            $old_enabled = get_option('email_otp_login_enabled', '1');
            $old_expiry = get_option('email_otp_login_expiry', '10');
            $old_rate_limit = get_option('email_otp_login_rate_limit', '60');
            
            if ($old_enabled !== false) {
                $this->options = array(
                    'enabled' => $old_enabled,
                    'expiry' => $old_expiry,
                    'rate_limit' => $old_rate_limit
                );
                update_option('wpsa_otp_settings', $this->options);
            }
        }
        
        // Set defaults if still empty
        if (empty($this->options)) {
            $this->options = array(
                'enabled' => '1',
                'expiry' => '10',
                'rate_limit' => '60'
            );
        }
    }
    
    private function generate_otp_code() {
        return sprintf("%06d", mt_rand(100000, 999999));
    }
    
    private function store_user_otp($user_id, $otp) {
        $expiry_minutes = $this->options['expiry'];
        $expiry = time() + ($expiry_minutes * 60);
        update_user_meta($user_id, 'wpsa_login_otp_code', $otp);
        update_user_meta($user_id, 'wpsa_login_otp_expiry', $expiry);
    }
    
    private function send_otp_email($user) {
        $otp = $this->generate_otp_code();
        $this->store_user_otp($user->ID, $otp);
        
        $subject = sprintf(__('Your login verification code - %s', 'wp-starter-addon'), get_bloginfo('name'));
        $message = '
        <html>
        <body style="font-family: Arial, sans-serif; color: #333; line-height: 1.6; max-width: 600px; margin: 0 auto; padding: 20px; background-color: #f9f9f9;">
            <div style="background-color: #ffffff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                <h2 style="color: #3498db; margin-top: 0;">' . __('Login Verification Code', 'wp-starter-addon') . '</h2>
                <p>' . sprintf(__('Hello %s,', 'wp-starter-addon'), esc_html($user->display_name)) . '</p>
                <p>' . sprintf(__('Your verification code to log in to %s is:', 'wp-starter-addon'), get_bloginfo('name')) . '</p>
                <div style="background: #f2f2f2; padding: 15px; font-size: 24px; text-align: center; letter-spacing: 5px; font-weight: bold; margin: 20px 0;">
                    ' . $otp . '
                </div>
                <p>' . sprintf(__('This code will expire in %d minutes.', 'wp-starter-addon'), $this->options['expiry']) . '</p>
                <p>' . __('If you did not request this code, please ignore this email.', 'wp-starter-addon') . '</p>
                <hr style="border: 0; border-top: 1px solid #eee;">
                <p style="font-size: 12px; color: #777;">' . sprintf(__('This is an automated email from %s.', 'wp-starter-addon'), get_bloginfo('name')) . '</p>
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
        $disabled = get_user_meta($user_id, 'wpsa_disable_login_otp', true);
        
        // Check old meta key for backward compatibility
        if (empty($disabled)) {
            $disabled = get_user_meta($user_id, 'disable_login_otp', true);
        }
        
        return $disabled === '1';
    }
    
    public function custom_login_handler() {
        if ($this->options['enabled'] !== '1') {
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
        
        $_SESSION['wpsa_otp_user_id'] = $user->ID;
        $_SESSION['wpsa_otp_remember'] = $remember;
        
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
        
        if (!isset($_SESSION['wpsa_otp_user_id'])) {
            $this->close_otp_session();
            wp_redirect(wp_login_url());
            exit;
        }
        
        $user = get_user_by('ID', $_SESSION['wpsa_otp_user_id']);
        if (!$user) {
            $this->close_otp_session();
            wp_redirect(wp_login_url());
            exit;
        }
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['otp_code'])) {
            $entered_otp = preg_replace('/[^0-9]/', '', $_POST['otp_code']);
            $stored_otp = get_user_meta($user->ID, 'wpsa_login_otp_code', true);
            $expiry = (int) get_user_meta($user->ID, 'wpsa_login_otp_expiry', true);
            
            // Check old meta keys for backward compatibility
            if (empty($stored_otp)) {
                $stored_otp = get_user_meta($user->ID, 'login_otp_code', true);
                $expiry = (int) get_user_meta($user->ID, 'login_otp_expiry', true);
            }
            
            if ($entered_otp === $stored_otp && time() < $expiry) {
                delete_user_meta($user->ID, 'wpsa_login_otp_code');
                delete_user_meta($user->ID, 'wpsa_login_otp_expiry');
                
                // Clean up old meta keys too
                delete_user_meta($user->ID, 'login_otp_code');
                delete_user_meta($user->ID, 'login_otp_expiry');
                
                wp_set_auth_cookie($user->ID, $_SESSION['wpsa_otp_remember']);
                
                unset($_SESSION['wpsa_otp_user_id']);
                unset($_SESSION['wpsa_otp_remember']);
                $this->close_otp_session();
                
                $redirect_to = isset($_REQUEST['redirect_to']) ? $_REQUEST['redirect_to'] : admin_url();
                wp_safe_redirect($redirect_to);
                exit;
            } else {
                $error_message = time() >= $expiry ? 
                    __('OTP has expired. Please try again.', 'wp-starter-addon') : 
                    __('Invalid verification code. Please try again.', 'wp-starter-addon');
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
            <title><?php printf(__('OTP Verification - %s', 'wp-starter-addon'), get_bloginfo('name')); ?></title>
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
                <h1><?php _e('Verification Required', 'wp-starter-addon'); ?></h1>
                <p><?php printf(__('We\'ve sent a verification code to <strong>%s</strong>. Please enter it below to continue.', 'wp-starter-addon'), esc_html(substr_replace($user->user_email, '***', 3, strpos($user->user_email, '@') - 5))); ?></p>
                
                <?php if ($error_message): ?>
                    <div class="error-message"><?php echo esc_html($error_message); ?></div>
                <?php endif; ?>
                
                <form method="post" action="<?php echo esc_url(add_query_arg('otp_verification', '1', wp_login_url())); ?>">
                    <input type="hidden" name="otp_verification" value="1">
                    <input type="text" name="otp_code" class="otp-field" maxlength="6" placeholder="------" required autofocus>
                    <button type="submit" class="otp-submit"><?php _e('Verify', 'wp-starter-addon'); ?></button>
                    
                    <?php if (isset($_REQUEST['redirect_to'])): ?>
                        <input type="hidden" name="redirect_to" value="<?php echo esc_url($_REQUEST['redirect_to']); ?>">
                    <?php endif; ?>
                </form>
                
                <div class="resend-link">
                    <a href="<?php echo esc_url(add_query_arg('resend_otp', '1', add_query_arg('otp_verification', '1', wp_login_url()))); ?>"><?php _e('Resend verification code', 'wp-starter-addon'); ?></a>
                </div>
                
                <div class="resend-link">
                    <a href="<?php echo esc_url(wp_login_url()); ?>"><?php _e('Back to login', 'wp-starter-addon'); ?></a>
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
        
        if (!isset($_SESSION['wpsa_otp_user_id'])) {
            $this->close_otp_session();
            wp_redirect(wp_login_url());
            exit;
        }
        
        $user = get_user_by('ID', $_SESSION['wpsa_otp_user_id']);
        if (!$user) {
            $this->close_otp_session();
            wp_redirect(wp_login_url());
            exit;
        }
        
        $rate_limit_seconds = $this->options['rate_limit'];
        $last_sent = get_user_meta($user->ID, 'wpsa_login_otp_last_sent', true);
        
        // Check old meta key for backward compatibility
        if (empty($last_sent)) {
            $last_sent = get_user_meta($user->ID, 'login_otp_last_sent', true);
        }
        
        if ($last_sent && (time() - $last_sent) < $rate_limit_seconds) {
            $this->close_otp_session();
            wp_redirect(add_query_arg('otp_error', 'rate_limit', add_query_arg('otp_verification', '1', wp_login_url())));
            exit;
        }
        
        $this->close_otp_session();
        
        $this->send_otp_email($user);
        update_user_meta($user->ID, 'wpsa_login_otp_last_sent', time());
        
        wp_redirect(add_query_arg('otp_verification', '1', wp_login_url()));
        exit;
    }
    
    public function display_otp_error_messages() {
        if (isset($_GET['otp_error']) && $_GET['otp_error'] == 'rate_limit') {
            $rate_limit_minutes = ceil($this->options['rate_limit'] / 60);
            ?>
            <div id="login_error">
                <?php printf(__('Please wait at least %d minute(s) before requesting another verification code.', 'wp-starter-addon'), $rate_limit_minutes); ?>
            </div>
            <?php
        }
    }
    
    public function cleanup_expired_otp_codes() {
        $users = get_users(array('fields' => 'ID'));
        foreach ($users as $user_id) {
            $expiry = (int) get_user_meta($user_id, 'wpsa_login_otp_expiry', true);
            if ($expiry && time() > $expiry) {
                delete_user_meta($user_id, 'wpsa_login_otp_code');
                delete_user_meta($user_id, 'wpsa_login_otp_expiry');
            }
            
            // Clean up old meta keys too
            $old_expiry = (int) get_user_meta($user_id, 'login_otp_expiry', true);
            if ($old_expiry && time() > $old_expiry) {
                delete_user_meta($user_id, 'login_otp_code');
                delete_user_meta($user_id, 'login_otp_expiry');
            }
        }
    }
    
    public function otp_settings_fields($user) {
        $disable_otp = get_user_meta($user->ID, 'wpsa_disable_login_otp', true);
        
        // Check old meta key for backward compatibility
        if (empty($disable_otp)) {
            $disable_otp = get_user_meta($user->ID, 'disable_login_otp', true);
        }
        
        ?>
        <h3><?php _e('Login Security', 'wp-starter-addon'); ?></h3>
        <table class="form-table">
            <tr>
                <th><label for="wpsa_disable_login_otp"><?php _e('Two-Factor Authentication', 'wp-starter-addon'); ?></label></th>
                <td>
                    <input type="checkbox" name="wpsa_disable_login_otp" id="wpsa_disable_login_otp" value="1" <?php checked($disable_otp, '1'); ?>>
                    <label for="wpsa_disable_login_otp"><?php _e('Disable email verification code during login', 'wp-starter-addon'); ?></label>
                    <p class="description"><?php _e('Not recommended. Two-factor authentication adds an extra layer of security to your account.', 'wp-starter-addon'); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }
    
    public function save_otp_settings($user_id) {
        if (!current_user_can('edit_user', $user_id)) {
            return false;
        }
        
        $disable_otp = isset($_POST['wpsa_disable_login_otp']) ? '1' : '0';
        update_user_meta($user_id, 'wpsa_disable_login_otp', $disable_otp);
    }
    
    public function admin_page() {
        if (isset($_POST['submit'])) {
            check_admin_referer('wpsa_email_2fa_settings');
            
            $settings = array(
                'enabled' => isset($_POST['enabled']) ? '1' : '0',
                'expiry' => max(1, min(60, (int) $_POST['expiry'])),
                'rate_limit' => max(30, min(300, (int) $_POST['rate_limit']))
            );
            
            update_option('wpsa_otp_settings', $settings);
            $this->options = $settings;
            
            echo '<div class="notice notice-success"><p>' . __('Email 2FA settings saved successfully!', 'wp-starter-addon') . '</p></div>';
        }
        ?>
        <div class="wrap">
            <h1><?php _e('Email 2FA Settings', 'wp-starter-addon'); ?></h1>
            
            <form method="post" action="">
                <?php wp_nonce_field('wpsa_email_2fa_settings'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Enable Email 2FA', 'wp-starter-addon'); ?></th>
                        <td>
                            <input type="checkbox" name="enabled" id="enabled" value="1" <?php checked($this->options['enabled'], '1'); ?>>
                            <label for="enabled"><?php _e('Enable email OTP verification for login', 'wp-starter-addon'); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('OTP Expiry Time', 'wp-starter-addon'); ?></th>
                        <td>
                            <input type="number" name="expiry" id="expiry" value="<?php echo esc_attr($this->options['expiry']); ?>" min="1" max="60" class="small-text">
                            <label for="expiry"><?php _e('minutes', 'wp-starter-addon'); ?></label>
                            <p class="description"><?php _e('How long the OTP code remains valid (1-60 minutes)', 'wp-starter-addon'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Rate Limit', 'wp-starter-addon'); ?></th>
                        <td>
                            <input type="number" name="rate_limit" id="rate_limit" value="<?php echo esc_attr($this->options['rate_limit']); ?>" min="30" max="300" class="small-text">
                            <label for="rate_limit"><?php _e('seconds', 'wp-starter-addon'); ?></label>
                            <p class="description"><?php _e('Minimum time between OTP requests (30-300 seconds)', 'wp-starter-addon'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
            
            <hr>
            
            <h2><?php _e('Plugin Information', 'wp-starter-addon'); ?></h2>
            <p><?php _e('This module adds two-factor authentication to WordPress login using email OTP codes.', 'wp-starter-addon'); ?></p>
            <p><?php _e('Users can disable OTP for their accounts in their profile settings (not recommended).', 'wp-starter-addon'); ?></p>
        </div>
        <?php
    }
}

// AJAX handler for getting all images (for bulk conversion)
add_action('wp_ajax_wpsa_get_all_images', 'wpsa_get_all_images');
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

// Initialize the main plugin
WPStarterAddon::get_instance();

// Email logger for SMTP debugging
add_action('wp_mail', function($args) {
    $enabled_modules = get_option('wpsa_modules', array());
    if (!empty($enabled_modules['smtp_mailer'])) {
        $smtp_opts = get_option('wpsa_smtp_settings', array());
        if (!empty($smtp_opts['debug_mode'])) {
            error_log(sprintf("WPSA SMTP Log: To=%s Subject=%s", 
                is_array($args['to']) ? implode(',', $args['to']) : $args['to'], 
                $args['subject']
            ));
        }
    }
});

// Add activation notice
add_action('admin_notices', 'wpsa_activation_notice');
function wpsa_activation_notice() {
    if (get_option('wpsa_activation_notice')) {
        ?>
        <div class="notice notice-success is-dismissible">
            <p><strong>WP Starter Addon</strong> activated successfully! Go to <a href="<?php echo admin_url('admin.php?page=wp-starter-addon'); ?>">WP Starter Addon</a> to configure your modules.</p>
        </div>
        <?php
        delete_option('wpsa_activation_notice');
    }
}

// Set activation notice on plugin activation
register_activation_hook(__FILE__, function() {
    add_option('wpsa_activation_notice', true);
});

// Debug function for Custom Scripts (remove after testing)
add_action('wp_footer', 'wpsa_csm_debug_info');
function wpsa_csm_debug_info() {
    $enabled_modules = get_option('wpsa_modules', array());
    if (!empty($enabled_modules['custom_scripts']) && current_user_can('manage_options') && isset($_GET['wpsa_debug'])) {
        $head_scripts = get_option('wpsa_csm_global_head_scripts', '');
        $body_scripts = get_option('wpsa_csm_global_body_scripts', '');
        echo '<div style="position: fixed; bottom: 0; right: 0; background: black; color: white; padding: 10px; z-index: 9999; max-width: 300px; font-size: 12px;">';
        echo '<strong>WPSA CSM Debug:</strong><br>';
        echo 'Head: ' . (empty($head_scripts) ? 'Empty' : strlen($head_scripts) . ' chars') . '<br>';
        echo 'Body: ' . (empty($body_scripts) ? 'Empty' : strlen($body_scripts) . ' chars') . '<br>';
        echo '<a href="' . remove_query_arg('wpsa_debug') . '" style="color: yellow;">Hide</a>';
        echo '</div>';
    }
}
?>
