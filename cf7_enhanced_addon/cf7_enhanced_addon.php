<?php
/**
 * Plugin Name: Contact Form 7 Enhanced
 * Plugin URI: https://github.com/drshounak/wordpress-plugins/tree/main/cf7_enhanced_addon
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

// Define plugin constants
define('CF7_ENHANCED_VERSION', '1.0.2');
define('CF7_ENHANCED_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CF7_ENHANCED_PLUGIN_URL', plugin_dir_url(__FILE__));

class CF7_Enhanced {
    
    public function __construct() {
        add_action('plugins_loaded', array($this, 'init'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    public function init() {
        // Check if Contact Form 7 is active
        if (!class_exists('WPCF7')) {
            add_action('admin_notices', array($this, 'cf7_missing_notice'));
            return;
        }
        
        // Initialize plugin features
        $this->create_database_table();
        $this->add_hooks();
    }
    
    public function activate() {
        $this->create_database_table();
    }
    
    public function deactivate() {
        // Clean up if needed
    }
    
    public function cf7_missing_notice() {
        ?>
        <div class="notice notice-error">
            <p><?php _e('Contact Form 7 Enhanced requires Contact Form 7 plugin to be installed and activated.', 'cf7-enhanced'); ?></p>
        </div>
        <?php
    }
    
    private function add_hooks() {
        // Add custom CSS functionality - using the correct CF7 hooks
        add_action('wpcf7_admin_init', array($this, 'setup_admin_hooks'));
        add_action('wp_head', array($this, 'output_custom_css'));
        
        // Add message storage functionality
        add_action('wpcf7_before_send_mail', array($this, 'store_form_submission'));
        
        // Add admin menu for viewing stored messages
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Add AJAX handler for deleting messages
        add_action('wp_ajax_delete_cf7_message', array($this, 'delete_message'));
        
        // Enqueue admin scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }
    
    public function setup_admin_hooks() {
        // Hook into CF7's admin interface
        add_action('wpcf7_admin_after_additional_settings', array($this, 'display_custom_css_section'));
        add_action('wpcf7_save_contact_form', array($this, 'save_custom_css'));
        
        // Also try alternative hook for older versions
        add_filter('wpcf7_editor_panels', array($this, 'add_css_panel'));
    }
    
    // Add CSS panel to CF7 editor (alternative method)
    public function add_css_panel($panels) {
        $panels['cf7-enhanced-css-panel'] = array(
            'title' => __('Custom CSS', 'cf7-enhanced'),
            'callback' => array($this, 'css_panel_content')
        );
        return $panels;
    }
    
    public function css_panel_content($contact_form) {
        $custom_css = get_post_meta($contact_form->id(), '_cf7_enhanced_custom_css', true);
        ?>
        <h2><?php _e('Custom CSS', 'cf7-enhanced'); ?></h2>
        <fieldset>
            <legend><?php _e('Add custom CSS styling for this form', 'cf7-enhanced'); ?></legend>
            <textarea 
                id="cf7-enhanced-custom-css" 
                name="cf7_enhanced_custom_css" 
                rows="12" 
                cols="100" 
                style="width: 100%; font-family: Consolas, Monaco, monospace; font-size: 13px; background: #f9f9f9; border: 1px solid #ddd; padding: 10px;"
                placeholder="/* Add your custom CSS here - Example: */&#10;.wpcf7-form-<?php echo $contact_form->id(); ?> {&#10;    background: #f9f9f9;&#10;    padding: 20px;&#10;    border-radius: 8px;&#10;    border: 1px solid #ddd;&#10;}&#10;&#10;.wpcf7-form-<?php echo $contact_form->id(); ?> input[type='text'],&#10;.wpcf7-form-<?php echo $contact_form->id(); ?> input[type='email'],&#10;.wpcf7-form-<?php echo $contact_form->id(); ?> textarea {&#10;    border: 2px solid #007cba;&#10;    padding: 10px;&#10;    border-radius: 4px;&#10;    width: 100%;&#10;    margin-bottom: 10px;&#10;}&#10;&#10;.wpcf7-form-<?php echo $contact_form->id(); ?> input[type='submit'] {&#10;    background: #007cba;&#10;    color: white;&#10;    padding: 12px 24px;&#10;    border: none;&#10;    border-radius: 4px;&#10;    cursor: pointer;&#10;}&#10;&#10;.wpcf7-form-<?php echo $contact_form->id(); ?> input[type='submit']:hover {&#10;    background: #005a87;&#10;}"
            ><?php echo esc_textarea($custom_css); ?></textarea>
            <p class="description">
                <strong><?php printf(__('Form ID: %d', 'cf7-enhanced'), $contact_form->id()); ?></strong><br>
                <?php printf(__('Main selector: <code>.wpcf7-form-%d</code>', 'cf7-enhanced'), $contact_form->id()); ?><br>
                <?php _e('This CSS will only apply to this specific contact form.', 'cf7-enhanced'); ?>
            </p>
        </fieldset>
        <?php
    }
    
    public function display_custom_css_section($contact_form) {
        $custom_css = get_post_meta($contact_form->id(), '_cf7_enhanced_custom_css', true);
        ?>
        <h2><?php _e('Custom CSS', 'cf7-enhanced'); ?></h2>
        <fieldset>
            <legend><?php _e('Add custom CSS styling for this form', 'cf7-enhanced'); ?></legend>
            <textarea 
                id="cf7-enhanced-custom-css" 
                name="cf7_enhanced_custom_css" 
                rows="12" 
                cols="100" 
                style="width: 100%; font-family: Consolas, Monaco, monospace; font-size: 13px; background: #f9f9f9; border: 1px solid #ddd; padding: 10px;"
                placeholder="/* Add your custom CSS here - Example: */&#10;.wpcf7-form-<?php echo $contact_form->id(); ?> {&#10;    background: #f9f9f9;&#10;    padding: 20px;&#10;    border-radius: 8px;&#10;    border: 1px solid #ddd;&#10;}&#10;&#10;.wpcf7-form-<?php echo $contact_form->id(); ?> input[type='text'],&#10;.wpcf7-form-<?php echo $contact_form->id(); ?> input[type='email'],&#10;.wpcf7-form-<?php echo $contact_form->id(); ?> textarea {&#10;    border: 2px solid #007cba;&#10;    padding: 10px;&#10;    border-radius: 4px;&#10;    width: 100%;&#10;    margin-bottom: 10px;&#10;}&#10;&#10;.wpcf7-form-<?php echo $contact_form->id(); ?> input[type='submit'] {&#10;    background: #007cba;&#10;    color: white;&#10;    padding: 12px 24px;&#10;    border: none;&#10;    border-radius: 4px;&#10;    cursor: pointer;&#10;}&#10;&#10;.wpcf7-form-<?php echo $contact_form->id(); ?> input[type='submit']:hover {&#10;    background: #005a87;&#10;}"
            ><?php echo esc_textarea($custom_css); ?></textarea>
            <p class="description">
                <strong><?php printf(__('Form ID: %d', 'cf7-enhanced'), $contact_form->id()); ?></strong><br>
                <?php printf(__('Main selector: <code>.wpcf7-form-%d</code>', 'cf7-enhanced'), $contact_form->id()); ?><br>
                <?php _e('This CSS will only apply to this specific contact form.', 'cf7-enhanced'); ?>
            </p>
        </fieldset>
        <hr>
        <?php
    }
    
    public function save_custom_css($contact_form) {
        if (isset($_POST['cf7_enhanced_custom_css'])) {
            $custom_css = sanitize_textarea_field($_POST['cf7_enhanced_custom_css']);
            update_post_meta(
                $contact_form->id(),
                '_cf7_enhanced_custom_css',
                $custom_css
            );
        }
    }
    
    public function output_custom_css() {
        if (!class_exists('WPCF7')) return;
        
        // Get all contact forms on the current page
        global $post;
        if (!$post) return;
        
        $custom_css_output = '';
        
        // Check if there are any CF7 shortcodes in the content
        if (has_shortcode($post->post_content, 'contact-form-7')) {
            // Extract form IDs from shortcodes
            preg_match_all('/\[contact-form-7[^\]]*id[=:]["\']?(\d+)["\']?[^\]]*\]/', $post->post_content, $matches);
            
            if (!empty($matches[1])) {
                foreach ($matches[1] as $form_id) {
                    $custom_css = get_post_meta($form_id, '_cf7_enhanced_custom_css', true);
                    if (!empty($custom_css)) {
                        $custom_css_output .= "\n/* Custom CSS for Contact Form 7 - Form ID: {$form_id} */\n";
                        $custom_css_output .= $custom_css . "\n";
                    }
                }
            }
        }
        
        // Also check for CF7 forms loaded via widgets or other methods
        $cf7_forms = get_posts(array(
            'post_type' => 'wpcf7_contact_form',
            'numberposts' => -1,
            'post_status' => 'publish'
        ));
        
        foreach ($cf7_forms as $form) {
            $custom_css = get_post_meta($form->ID, '_cf7_enhanced_custom_css', true);
            if (!empty($custom_css) && strpos($custom_css_output, "Form ID: {$form->ID}") === false) {
                $custom_css_output .= "\n/* Custom CSS for Contact Form 7 - Form ID: {$form->ID} */\n";
                $custom_css_output .= $custom_css . "\n";
            }
        }
        
        if (!empty($custom_css_output)) {
            echo '<style type="text/css" id="cf7-enhanced-custom-css">' . $custom_css_output . '</style>';
        }
    }
    
    private function create_database_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'cf7_enhanced_submissions';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            form_id mediumint(9) NOT NULL,
            form_title varchar(255) NOT NULL,
            submission_data longtext NOT NULL,
            submitted_at datetime DEFAULT CURRENT_TIMESTAMP,
            ip_address varchar(45),
            user_agent text,
            PRIMARY KEY (id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    // Message Storage Functionality
    public function store_form_submission($contact_form) {
        global $wpdb;
        
        $submission = WPCF7_Submission::get_instance();
        if (!$submission) return;
        
        $posted_data = $submission->get_posted_data();
        $form_id = $contact_form->id();
        $form_title = $contact_form->title();
        
        // Remove sensitive data or files if needed
        $filtered_data = array();
        foreach ($posted_data as $key => $value) {
            if (!is_array($value)) {
                $filtered_data[$key] = sanitize_text_field($value);
            } else {
                $filtered_data[$key] = array_map('sanitize_text_field', $value);
            }
        }
        
        $table_name = $wpdb->prefix . 'cf7_enhanced_submissions';
        
        $wpdb->insert(
            $table_name,
            array(
                'form_id' => $form_id,
                'form_title' => $form_title,
                'submission_data' => json_encode($filtered_data),
                'ip_address' => $this->get_client_ip(),
                'user_agent' => sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''),
            ),
            array('%d', '%s', '%s', '%s', '%s')
        );
    }
    
    private function get_client_ip() {
        $ip_keys = array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR');
        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                        return $ip;
                    }
                }
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? '';
    }
    
    // Admin Menu for Viewing Messages
    public function add_admin_menu() {
        add_submenu_page(
            'wpcf7',
            __('Form Submissions', 'cf7-enhanced'),
            __('Form Submissions', 'cf7-enhanced'),
            'manage_options',
            'cf7-enhanced-submissions',
            array($this, 'submissions_page')
        );
    }
    
    public function submissions_page() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'cf7_enhanced_submissions';
        
        // Handle pagination
        $per_page = 20;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $per_page;
        
        // Get form filter
        $form_filter = isset($_GET['form_id']) ? intval($_GET['form_id']) : 0;
        
        // Build query
        $where_clause = '';
        if ($form_filter > 0) {
            $where_clause = $wpdb->prepare("WHERE form_id = %d", $form_filter);
        }
        
        // Get total count
        $total_items = $wpdb->get_var("SELECT COUNT(*) FROM $table_name $where_clause");
        
        // Get submissions
        $submissions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name $where_clause ORDER BY submitted_at DESC LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ));
        
        // Get all forms for filter dropdown
        $forms = $wpdb->get_results("SELECT DISTINCT form_id, form_title FROM $table_name ORDER BY form_title");
        
        ?>
        <div class="wrap">
            <h1><?php _e('Contact Form 7 Submissions', 'cf7-enhanced'); ?></h1>
            
            <!-- Filter Form -->
            <form method="get" action="">
                <input type="hidden" name="page" value="cf7-enhanced-submissions">
                <select name="form_id" onchange="this.form.submit()">
                    <option value="0"><?php _e('All Forms', 'cf7-enhanced'); ?></option>
                    <?php foreach ($forms as $form): ?>
                        <option value="<?php echo $form->form_id; ?>" <?php selected($form_filter, $form->form_id); ?>>
                            <?php echo esc_html($form->form_title); ?> (ID: <?php echo $form->form_id; ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
            
            <br>
            
            <!-- Submissions Table -->
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('ID', 'cf7-enhanced'); ?></th>
                        <th><?php _e('Form', 'cf7-enhanced'); ?></th>
                        <th><?php _e('Submission Data', 'cf7-enhanced'); ?></th>
                        <th><?php _e('Submitted At', 'cf7-enhanced'); ?></th>
                        <th><?php _e('IP Address', 'cf7-enhanced'); ?></th>
                        <th><?php _e('Actions', 'cf7-enhanced'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($submissions)): ?>
                        <tr>
                            <td colspan="6"><?php _e('No submissions found.', 'cf7-enhanced'); ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($submissions as $submission): ?>
                            <tr id="submission-<?php echo $submission->id; ?>">
                                <td><?php echo $submission->id; ?></td>
                                <td>
                                    <strong><?php echo esc_html($submission->form_title); ?></strong><br>
                                    <small>ID: <?php echo $submission->form_id; ?></small>
                                </td>
                                <td>
                                    <?php
                                    $data = json_decode($submission->submission_data, true);
                                    if ($data) {
                                        echo '<div class="cf7-submission-data">';
                                        foreach ($data as $key => $value) {
                                            if (is_array($value)) {
                                                $value = implode(', ', $value);
                                            }
                                            echo '<strong>' . esc_html($key) . ':</strong> ' . esc_html($value) . '<br>';
                                        }
                                        echo '</div>';
                                    }
                                    ?>
                                </td>
                                <td><?php echo date('Y-m-d H:i:s', strtotime($submission->submitted_at)); ?></td>
                                <td><?php echo esc_html($submission->ip_address); ?></td>
                                <td>
                                    <button class="button button-small delete-submission" data-id="<?php echo $submission->id; ?>">
                                        <?php _e('Delete', 'cf7-enhanced'); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <!-- Pagination -->
            <?php
            $total_pages = ceil($total_items / $per_page);
            if ($total_pages > 1) {
                echo '<div class="tablenav"><div class="tablenav-pages">';
                echo paginate_links(array(
                    'base' => add_query_arg('paged', '%#%'),
                    'format' => '',
                    'prev_text' => __('&laquo;'),
                    'next_text' => __('&raquo;'),
                    'total' => $total_pages,
                    'current' => $current_page
                ));
                echo '</div></div>';
            }
            ?>
        </div>
        
        <style>
            .cf7-submission-data {
                max-width: 300px;
                max-height: 150px;
                overflow-y: auto;
                font-size: 12px;
                line-height: 1.4;
            }
            .delete-submission.loading {
                opacity: 0.5;
                pointer-events: none;
            }
        </style>
        <?php
    }
    
    public function enqueue_admin_scripts($hook) {
        // Check if we're on the submissions page
        if (strpos($hook, 'cf7-enhanced-submissions') === false) return;
        
        wp_enqueue_script('jquery');
        
        // Add inline script with proper localization
        $script = "
        jQuery(document).ready(function($) {
            $('.delete-submission').on('click', function(e) {
                e.preventDefault();
                
                if (!confirm('" . esc_js(__('Are you sure you want to delete this submission?', 'cf7-enhanced')) . "')) {
                    return;
                }
                
                var \$button = $(this);
                var submissionId = \$button.data('id');
                var \$row = \$button.closest('tr');
                
                // Add loading state
                \$button.addClass('loading').text('" . esc_js(__('Deleting...', 'cf7-enhanced')) . "');
                
                $.ajax({
                    url: '" . admin_url('admin-ajax.php') . "',
                    type: 'POST',
                    data: {
                        action: 'delete_cf7_message',
                        submission_id: submissionId,
                        nonce: '" . wp_create_nonce('delete_cf7_message') . "'
                    },
                    success: function(response) {
                        if (response.success) {
                            \$row.fadeOut(300, function() {
                                $(this).remove();
                                // Check if table is empty
                                if ($('tbody tr').length === 0) {
                                    $('tbody').html('<tr><td colspan=\"6\">" . esc_js(__('No submissions found.', 'cf7-enhanced')) . "</td></tr>');
                                }
                            });
                        } else {
                            alert('" . esc_js(__('Error deleting submission: ', 'cf7-enhanced')) . "' + (response.data || '" . esc_js(__('Unknown error', 'cf7-enhanced')) . "'));
                            \$button.removeClass('loading').text('" . esc_js(__('Delete', 'cf7-enhanced')) . "');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.log('AJAX Error:', xhr.responseText);
                        alert('" . esc_js(__('Network error occurred while deleting submission.', 'cf7-enhanced')) . "');
                        \$button.removeClass('loading').text('" . esc_js(__('Delete', 'cf7-enhanced')) . "');
                    }
                });
            });
        });
        ";
        
        wp_add_inline_script('jquery', $script);
    }
    
    public function delete_message() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'delete_cf7_message')) {
            wp_send_json_error(__('Security check failed.', 'cf7-enhanced'));
            return;
        }
        
        // Check user permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('You do not have permission to perform this action.', 'cf7-enhanced'));
            return;
        }
        
        // Get and validate submission ID
        $submission_id = intval($_POST['submission_id'] ?? 0);
        if ($submission_id <= 0) {
            wp_send_json_error(__('Invalid submission ID.', 'cf7-enhanced'));
            return;
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'cf7_enhanced_submissions';
        
        // Check if submission exists
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE id = %d",
            $submission_id
        ));
        
        if (!$exists) {
            wp_send_json_error(__('Submission not found.', 'cf7-enhanced'));
            return;
        }
        
        // Delete the submission
        $result = $wpdb->delete(
            $table_name,
            array('id' => $submission_id),
            array('%d')
        );
        
        if ($result !== false) {
            wp_send_json_success(__('Submission deleted successfully.', 'cf7-enhanced'));
        } else {
            wp_send_json_error(__('Database error occurred while deleting submission.', 'cf7-enhanced'));
        }
    }
}

// Initialize the plugin
new CF7_Enhanced();
