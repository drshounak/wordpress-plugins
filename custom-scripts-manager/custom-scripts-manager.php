<?php
/**
 * Plugin Name: 
 * Description: Add custom scripts to head or body for entire site or specific pages/posts
 * Version: 1.1
 * Author: TechWeirdo
 * Author URI: https://techweirdo.net
 */
/**
 * Plugin Name: Custom Scripts Manager by TechWeirdo
 * Plugin URI: https://github.com/drshounak/wordpress-plugins/simple-smtp-mailer
 * Description: Adds custom CSS functionality and message storage to Contact Form 7 forms
 * Version: 1.0.0
 * Author: TechWeirdo
 * Author URI: https://twitter.com/drshounakpal
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: cf7-enhanced
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

class CustomScriptsManager {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'init_settings'));
        add_action('wp_head', array($this, 'output_head_scripts'), 999);
        add_action('wp_footer', array($this, 'output_body_scripts'), 999);
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
        add_action('save_post', array($this, 'save_post_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }
    
    // Add admin menu
    public function add_admin_menu() {
        add_options_page(
            'Custom Scripts Manager',
            'Custom Scripts',
            'manage_options',
            'custom-scripts-manager',
            array($this, 'admin_page')
        );
    }
    
    // Initialize settings
    public function init_settings() {
        register_setting('custom_scripts_settings', 'csm_global_head_scripts');
        register_setting('custom_scripts_settings', 'csm_global_body_scripts');
    }
    
    // Add meta boxes to posts and pages
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
    
    // Meta box callback
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
        
        <p><small><strong>Note:</strong> These scripts will only apply to this specific post/page. For site-wide scripts, use the <a href="<?php echo admin_url('options-general.php?page=custom-scripts-manager'); ?>">Custom Scripts settings page</a>.</small></p>
        <?php
    }
    
    // Save post scripts
    public function save_post_scripts($post_id) {
        // Verify nonce
        if (!isset($_POST['custom_scripts_meta_box_nonce']) || 
            !wp_verify_nonce($_POST['custom_scripts_meta_box_nonce'], 'custom_scripts_meta_box_action')) {
            return;
        }
        
        // Check autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        // Check permissions
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        // Save head scripts
        if (isset($_POST['csm_head_scripts'])) {
            $head_scripts = wp_unslash($_POST['csm_head_scripts']);
            update_post_meta($post_id, '_csm_head_scripts', $head_scripts);
        } else {
            delete_post_meta($post_id, '_csm_head_scripts');
        }
        
        // Save body scripts
        if (isset($_POST['csm_body_scripts'])) {
            $body_scripts = wp_unslash($_POST['csm_body_scripts']);
            update_post_meta($post_id, '_csm_body_scripts', $body_scripts);
        } else {
            delete_post_meta($post_id, '_csm_body_scripts');
        }
    }
    
    // Enqueue admin scripts
    public function enqueue_admin_scripts($hook) {
        if ($hook == 'settings_page_custom-scripts-manager' || 
            $hook == 'post.php' || $hook == 'post-new.php') {
            wp_enqueue_script('jquery');
        }
    }
    
    // Admin page
    public function admin_page() {
        // Handle form submission
        if (isset($_POST['submit']) && check_admin_referer('csm_settings_action', 'csm_settings_nonce')) {
            $head_scripts = isset($_POST['csm_global_head_scripts']) ? wp_unslash($_POST['csm_global_head_scripts']) : '';
            $body_scripts = isset($_POST['csm_global_body_scripts']) ? wp_unslash($_POST['csm_global_body_scripts']) : '';
            
            update_option('csm_global_head_scripts', $head_scripts);
            update_option('csm_global_body_scripts', $body_scripts);
            
            echo '<div class="notice notice-success is-dismissible"><p><strong>Settings saved successfully!</strong></p></div>';
        }
        
        // Get current values
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
                <h3>Your Plausible Analytics (Head Section):</h3>
                <textarea readonly style="width: 100%; height: 80px; font-family: monospace; background: #f6f7f7;">&lt;script defer data-domain="drshounak.com" src="https://www.drshounak.com/stats/js/script.file-downloads.hash.outbound-links.pageview-props.revenue.tagged-events.js"&gt;&lt;/script&gt;
&lt;script&gt;window.plausible = window.plausible || function() { (window.plausible.q = window.plausible.q || []).push(arguments) }&lt;/script&gt;</textarea>
                
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
            // Add confirmation for form submission
            $('form').on('submit', function() {
                return confirm('Are you sure you want to save these script changes?');
            });
        });
        </script>
        <?php
    }
    
    // Output head scripts
    public function output_head_scripts() {
        // Global head scripts
        $global_head_scripts = get_option('csm_global_head_scripts', '');
        if (!empty(trim($global_head_scripts))) {
            echo "\n<!-- Custom Scripts Manager - Global Head -->\n";
            echo $global_head_scripts . "\n";
            echo "<!-- End Custom Scripts Manager - Global Head -->\n";
        }
        
        // Page-specific head scripts
        if (is_singular()) {
            global $post;
            if ($post) {
                $post_head_scripts = get_post_meta($post->ID, '_csm_head_scripts', true);
                if (!empty(trim($post_head_scripts))) {
                    echo "\n<!-- Custom Scripts Manager - Page Specific Head -->\n";
                    echo $post_head_scripts . "\n";
                    echo "<!-- End Custom Scripts Manager - Page Specific Head -->\n";
                }
            }
        }
    }
    
    // Output body scripts
    public function output_body_scripts() {
        // Global body scripts
        $global_body_scripts = get_option('csm_global_body_scripts', '');
        if (!empty(trim($global_body_scripts))) {
            echo "\n<!-- Custom Scripts Manager - Global Body -->\n";
            echo $global_body_scripts . "\n";
            echo "<!-- End Custom Scripts Manager - Global Body -->\n";
        }
        
        // Page-specific body scripts
        if (is_singular()) {
            global $post;
            if ($post) {
                $post_body_scripts = get_post_meta($post->ID, '_csm_body_scripts', true);
                if (!empty(trim($post_body_scripts))) {
                    echo "\n<!-- Custom Scripts Manager - Page Specific Body -->\n";
                    echo $post_body_scripts . "\n";
                    echo "<!-- End Custom Scripts Manager - Page Specific Body -->\n";
                }
            }
        }
    }
}

// Initialize the plugin
new CustomScriptsManager();

// Activation hook
register_activation_hook(__FILE__, 'csm_activation_notice');
function csm_activation_notice() {
    add_option('csm_activation_notice', true);
}

// Admin notice
add_action('admin_notices', 'csm_admin_notices');
function csm_admin_notices() {
    if (get_option('csm_activation_notice')) {
        ?>
        <div class="notice notice-success is-dismissible">
            <p><strong>Custom Scripts Manager</strong> activated! Go to <a href="<?php echo admin_url('options-general.php?page=custom-scripts-manager'); ?>">Settings → Custom Scripts</a> to add your scripts.</p>
        </div>
        <?php
        delete_option('csm_activation_notice');
    }
}

// Debug function - remove after testing
add_action('wp_footer', 'csm_debug_info');
function csm_debug_info() {
    if (current_user_can('manage_options') && isset($_GET['csm_debug'])) {
        $head_scripts = get_option('csm_global_head_scripts', '');
        $body_scripts = get_option('csm_global_body_scripts', '');
        echo '<div style="position: fixed; bottom: 0; right: 0; background: black; color: white; padding: 10px; z-index: 9999; max-width: 300px; font-size: 12px;">';
        echo '<strong>CSM Debug:</strong><br>';
        echo 'Head: ' . (empty($head_scripts) ? 'Empty' : strlen($head_scripts) . ' chars') . '<br>';
        echo 'Body: ' . (empty($body_scripts) ? 'Empty' : strlen($body_scripts) . ' chars') . '<br>';
        echo '<a href="' . remove_query_arg('csm_debug') . '" style="color: yellow;">Hide</a>';
        echo '</div>';
    }
}
?>
