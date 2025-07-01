<?php
/**
 * Plugin Name: TW Fastly Cache Purger
 * Plugin URI: https://github.com/techweirdo/tw-fastly-cache-purger
 * Description: Advanced Fastly CDN cache purger with support for all purge methods
 * Version: 2.2.0
 * Author: Tech Weirdo
 * Author URI: https://www.techweirdo.net
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: tw-fastly-cache-purger
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('TWFCP_VERSION', '2.2.0');
define('TWFCP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('TWFCP_PLUGIN_PATH', plugin_dir_path(__FILE__));

// Add admin menu item
add_action('admin_menu', 'twfcp_add_admin_menu');
function twfcp_add_admin_menu() {
    add_options_page(
        'TW Fastly Cache Purger',
        'TW Fastly Cache Purger',
        'manage_options',
        'tw-fastly-cache-purger',
        'twfcp_admin_page'
    );
}

// Register settings
add_action('admin_init', 'twfcp_register_settings');
function twfcp_register_settings() {
    register_setting('twfcp_settings', 'twfcp_fastly_api_token');
    register_setting('twfcp_settings', 'twfcp_fastly_service_id');
    register_setting('twfcp_settings', 'twfcp_fastly_surrogate_keys');
    register_setting('twfcp_settings', 'twfcp_fastly_default_key');
    register_setting('twfcp_settings', 'twfcp_fastly_enabled');
}

// Add Purge Cache button to admin bar
add_action('admin_bar_menu', function($wp_admin_bar) {
    if (!current_user_can('manage_options')) {
        return;
    }
    $wp_admin_bar->add_node(array(
        'id'    => 'tw-purge-fastly-cache',
        'title' => '🚀 Purge Fastly Cache',
        'href'  => wp_nonce_url(admin_url('options-general.php?page=tw-fastly-cache-purger&purge_cache=1'), 'twfcp_purge_cache_action'),
    ));
}, 100);

// Fastly cache purge functions
function twfcp_purge_fastly_all() {
    $api_token = get_option('twfcp_fastly_api_token');
    $service_id = get_option('twfcp_fastly_service_id');
    
    if (empty($api_token) || empty($service_id)) {
        return new WP_Error('missing_credentials', 'Fastly API token or Service ID is missing');
    }
    
    $response = wp_remote_post("https://api.fastly.com/service/{$service_id}/purge_all", array(
        'method' => 'POST',
        'headers' => array(
            'Fastly-Key' => $api_token,
            'Accept' => 'application/json',
        ),
        'timeout' => 30,
    ));
    
    if (is_wp_error($response)) {
        error_log('Fastly purge all error: ' . $response->get_error_message());
        return $response;
    }
    
    $response_code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    
    if ($response_code === 200) {
        error_log('Fastly purge all successful: ' . $body);
        return true;
    } else {
        $error_message = 'HTTP ' . $response_code . ': ' . $body;
        error_log('Fastly purge all failed: ' . $error_message);
        return new WP_Error('fastly_error', $error_message);
    }
}

function twfcp_purge_fastly_surrogate_key($surrogate_key) {
    $api_token = get_option('twfcp_fastly_api_token');
    $service_id = get_option('twfcp_fastly_service_id');
    
    if (empty($api_token) || empty($service_id) || empty($surrogate_key)) {
        return new WP_Error('missing_credentials', 'Fastly API token, Service ID, or surrogate key is missing');
    }
    
    $response = wp_remote_post("https://api.fastly.com/service/{$service_id}/purge/{$surrogate_key}", array(
        'method' => 'POST',
        'headers' => array(
            'Fastly-Key' => $api_token,
            'Accept' => 'application/json',
        ),
        'timeout' => 30,
    ));
    
    if (is_wp_error($response)) {
        error_log('Fastly surrogate key purge error: ' . $response->get_error_message());
        return $response;
    }
    
    $response_code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    
    if ($response_code === 200) {
        error_log('Fastly surrogate key purge successful: ' . $surrogate_key . ' - Response: ' . $body);
        return true;
    } else {
        $error_message = 'HTTP ' . $response_code . ': ' . $body;
        error_log('Fastly surrogate key purge failed for key "' . $surrogate_key . '": ' . $error_message);
        return new WP_Error('fastly_error', $error_message);
    }
}

function twfcp_purge_fastly_multiple_keys($surrogate_keys, $soft_purge = false) {
    $api_token = get_option('twfcp_fastly_api_token');
    $service_id = get_option('twfcp_fastly_service_id');
    
    if (empty($api_token) || empty($service_id) || empty($surrogate_keys)) {
        return new WP_Error('missing_credentials', 'Fastly API token, Service ID, or surrogate keys are missing');
    }
    
    $keys_array = is_array($surrogate_keys) ? $surrogate_keys : array($surrogate_keys);
    
    $headers = array(
        'Fastly-Key' => $api_token,
        'Accept' => 'application/json',
        'Content-Type' => 'application/json',
        'surrogate-key' => implode(' ', $keys_array)
    );
    
    if ($soft_purge) {
        $headers['fastly-soft-purge'] = '1';
    }
    
    $body = json_encode(array(
        'surrogate_keys' => $keys_array
    ));
    
    $response = wp_remote_post("https://api.fastly.com/service/{$service_id}/purge", array(
        'method' => 'POST',
        'headers' => $headers,
        'body' => $body,
        'timeout' => 30,
    ));
    
    if (is_wp_error($response)) {
        error_log('Fastly multiple keys purge error: ' . $response->get_error_message());
        return $response;
    }
    
    $response_code = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);
    
    if ($response_code === 200) {
        $purge_type = $soft_purge ? 'soft purge' : 'purge';
        error_log('Fastly multiple keys ' . $purge_type . ' successful: ' . implode(', ', $keys_array) . ' - Response: ' . $response_body);
        return true;
    } else {
        $error_message = 'HTTP ' . $response_code . ': ' . $response_body;
        error_log('Fastly multiple keys purge failed for keys "' . implode(', ', $keys_array) . '": ' . $error_message);
        return new WP_Error('fastly_error', $error_message);
    }
}

// Handle cache purge logic
function twfcp_handle_cache_purge() {
    if (!current_user_can('manage_options')) {
        return;
    }
    
    $is_admin_page = (is_admin() && isset($_GET['page']) && $_GET['page'] === 'tw-fastly-cache-purger');
    $is_admin_bar = isset($_GET['purge_cache']) && $_GET['purge_cache'] === '1' && check_admin_referer('twfcp_purge_cache_action');
    $is_form_submit = (isset($_POST['purge_cache']) || isset($_POST['purge_fastly_all']) || isset($_POST['purge_fastly_key']) || isset($_POST['purge_fastly_multiple'])) && check_admin_referer('twfcp_purge_cache_action');
    
    if ($is_admin_page && ($is_admin_bar || $is_form_submit)) {
        $results = array();
        $has_error = false;
        
        if (!get_option('twfcp_fastly_enabled')) {
            $results['error'] = new WP_Error('service_disabled', 'Fastly cache purging is disabled. Please enable it in settings.');
            $has_error = true;
        } else {
            // Handle individual Fastly surrogate key purge
            if (isset($_POST['purge_fastly_key']) && isset($_POST['surrogate_key'])) {
                $surrogate_key = sanitize_text_field($_POST['surrogate_key']);
                if (!empty($surrogate_key)) {
                    $results['fastly_key'] = twfcp_purge_fastly_surrogate_key($surrogate_key);
                    if (is_wp_error($results['fastly_key'])) {
                        $has_error = true;
                    }
                }
            }
            
            // Handle multiple keys purge
            if (isset($_POST['purge_fastly_multiple']) && isset($_POST['selected_keys'])) {
                $selected_keys = array_map('sanitize_text_field', $_POST['selected_keys']);
                $soft_purge = isset($_POST['soft_purge']);
                if (!empty($selected_keys)) {
                    $results['fastly_multiple'] = twfcp_purge_fastly_multiple_keys($selected_keys, $soft_purge);
                    if (is_wp_error($results['fastly_multiple'])) {
                        $has_error = true;
                    }
                }
            }
            
            // Handle purge all
            if (isset($_POST['purge_cache']) || isset($_POST['purge_fastly_all']) || $is_admin_bar) {
                $results['fastly_all'] = twfcp_purge_fastly_all();
                if (is_wp_error($results['fastly_all'])) {
                    $has_error = true;
                }
            }
        }
        
        // Build result message
        $notice_message = twfcp_build_notice_message($results);
        $notice_class = $has_error ? 'error' : 'success';
        
        // Store notice in transient for display
        set_transient('twfcp_purge_cache_notice', array('class' => $notice_class, 'message' => $notice_message), 60);
        
        // Redirect to avoid resubmission
        if ($is_admin_bar) {
            wp_safe_redirect(admin_url('options-general.php?page=tw-fastly-cache-purger'));
            exit;
        }
    }
}

// Build notice message from results
function twfcp_build_notice_message($results) {
    $messages = array();
    
    foreach ($results as $service => $result) {
        switch ($service) {
            case 'fastly_all':
                $status = is_wp_error($result) ? 'Failed - ' . $result->get_error_message() : 'Success';
                $messages[] = '<strong>Fastly (Purge All):</strong> ' . $status;
                break;
                
            case 'fastly_key':
                $status = is_wp_error($result) ? 'Failed - ' . $result->get_error_message() : 'Success';
                $messages[] = '<strong>Fastly (Surrogate Key):</strong> ' . $status;
                break;
                
            case 'fastly_multiple':
                $status = is_wp_error($result) ? 'Failed - ' . $result->get_error_message() : 'Success';
                $messages[] = '<strong>Fastly (Multiple Keys):</strong> ' . $status;
                break;
                
            case 'error':
                $status = is_wp_error($result) ? $result->get_error_message() : 'Unknown error';
                $messages[] = '<strong>Error:</strong> ' . $status;
                break;
        }
    }
    
    if (empty($messages)) {
        return '<p><strong>No cache purge operations were performed.</strong></p>';
    }
    
    return '<p><strong>Cache Purge Results:</strong></p><ul><li>' . implode('</li><li>', $messages) . '</li></ul>';
}

// Admin page
function twfcp_admin_page() {
    // Handle settings form submission
    if (isset($_POST['submit']) && isset($_POST['twfcp_fastly_api_token'])) {
        // WordPress will handle this automatically via register_setting
    }
    
    // Handle purge if triggered
    twfcp_handle_cache_purge();
    
    // Display any stored notice
    if ($notice = get_transient('twfcp_purge_cache_notice')) {
        echo '<div class="notice notice-' . esc_attr($notice['class']) . ' is-dismissible">' . $notice['message'] . '</div>';
        delete_transient('twfcp_purge_cache_notice');
    }
    
    // Get current tab
    $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'purge';
    ?>
    <div class="wrap">
        <h1>TW Fastly Cache Purger</h1>
        
        <!-- Tab Navigation -->
        <nav class="nav-tab-wrapper">
            <a href="?page=tw-fastly-cache-purger&tab=purge" class="nav-tab <?php echo $active_tab == 'purge' ? 'nav-tab-active' : ''; ?>">
                Purge Cache
            </a>
            <a href="?page=tw-fastly-cache-purger&tab=settings" class="nav-tab <?php echo $active_tab == 'settings' ? 'nav-tab-active' : ''; ?>">
                Settings
            </a>
        </nav>
        
        <?php if ($active_tab == 'purge'): ?>
            <!-- Purge Tab Content -->
            <div class="tab-content">
                <h2>Fastly Cache Purging</h2>
                
                <?php if (!get_option('twfcp_fastly_enabled')): ?>
                <div class="notice notice-warning">
                    <p><strong>Warning:</strong> Fastly cache purging is disabled. <a href="?page=tw-fastly-cache-purger&tab=settings">Enable it in settings</a> to use purge functions.</p>
                </div>
                <?php endif; ?>
                
                <!-- Quick Purge All -->
                <div class="card">
                    <h3>Purge All Cache</h3>
                    <p>Purge all cached content from your Fastly service. <strong>Use with caution!</strong></p>
                    <form method="post" action="">
                        <?php wp_nonce_field('twfcp_purge_cache_action'); ?>
                        <p class="submit">
                            <input type="submit" name="purge_fastly_all" class="button button-primary" value="Purge All Fastly Cache" onclick="return confirm('Are you sure you want to purge ALL cached content? This cannot be undone.');">
                        </p>
                    </form>
                </div>
                
                <!-- Individual Key Purging -->
                <div class="card">
                    <h3>Individual Surrogate Key Purging</h3>
                    
                    <table class="form-table">
                        <!-- Predefined Surrogate Keys -->
                        <?php 
                        $surrogate_keys = get_option('twfcp_fastly_surrogate_keys', '');
                        $keys_array = array_filter(array_map('trim', explode("\n", $surrogate_keys)));
                        if (!empty($keys_array)): 
                        ?>
                        <tr>
                            <th scope="row">Predefined Keys</th>
                            <td>
                                <form method="post" action="">
                                    <?php wp_nonce_field('twfcp_purge_cache_action'); ?>
                                    <div style="margin-bottom: 15px;">
                                        <?php foreach ($keys_array as $key): ?>
                                        <label style="display: block; margin-bottom: 5px;">
                                            <input type="checkbox" name="selected_keys[]" value="<?php echo esc_attr($key); ?>">
                                            <?php echo esc_html($key); ?>
                                        </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <div style="margin-bottom: 15px;">
                                        <label>
                                            <input type="checkbox" name="soft_purge" value="1">
                                            Soft purge (marks content as stale instead of removing it)
                                        </label>
                                    </div>
                                    <input type="submit" name="purge_fastly_multiple" class="button" value="Purge Selected Keys">
                                </form>
                                
                                <hr style="margin: 20px 0;">
                                
                                <!-- Individual key buttons -->
                                <?php foreach ($keys_array as $key): ?>
                                <form method="post" action="" style="display: inline; margin-right: 10px; margin-bottom: 5px;">
                                    <?php wp_nonce_field('twfcp_purge_cache_action'); ?>
                                    <input type="hidden" name="surrogate_key" value="<?php echo esc_attr($key); ?>">
                                    <input type="submit" name="purge_fastly_key" class="button" value="Purge: <?php echo esc_html($key); ?>">
                                </form>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                        
                        <!-- Custom Surrogate Key -->
                        <tr>
                            <th scope="row">Custom Key</th>
                            <td>
                                <form method="post" action="" style="display: inline;">
                                    <?php wp_nonce_field('twfcp_purge_cache_action'); ?>
                                    <input type="text" name="surrogate_key" placeholder="Enter surrogate key" style="margin-right: 10px;" required>
                                    <input type="submit" name="purge_fastly_key" class="button" value="Purge Key">
                                </form>
                                <p class="description">Enter any surrogate key to purge specific content.</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- Service Status -->
                <div class="card">
                    <h3>Service Status</h3>
                    <table class="form-table">
                        <tr>
                            <th scope="row">Fastly Service</th>
                            <td>
                                <?php 
                                $enabled = get_option('twfcp_fastly_enabled');
                                $has_token = !empty(get_option('twfcp_fastly_api_token'));
                                $has_service = !empty(get_option('twfcp_fastly_service_id'));
                                
                                if ($enabled && $has_token && $has_service): ?>
                                    <span style="color: green;">✓ Enabled & Configured</span>
                                <?php else: ?>
                                    <span style="color: #d63638;">○ <?php 
                                        if (!$enabled) echo 'Disabled';
                                        elseif (!$has_token) echo 'Missing API Token';
                                        elseif (!$has_service) echo 'Missing Service ID';
                                    ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php if ($has_service): ?>
                        <tr>
                            <th scope="row">Service ID</th>
                            <td><code><?php echo esc_html(get_option('twfcp_fastly_service_id')); ?></code></td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
            
        <?php else: ?>
            <!-- Settings Tab Content -->
            <div class="tab-content">
                <form method="post" action="options.php">
                    <?php 
                    settings_fields('twfcp_settings');
                    do_settings_sections('twfcp_settings');
                    ?>
                    
                    <!-- Fastly Settings -->
                    <div class="card">
                        <h3>Fastly Settings</h3>
                        <table class="form-table">
                            <tr>
                                <th scope="row">Enable Fastly</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="twfcp_fastly_enabled" value="1" <?php checked(get_option('twfcp_fastly_enabled')); ?> />
                                        Enable Fastly cache purging
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">API Token <span style="color: red;">*</span></th>
                                <td>
                                    <input type="password" name="twfcp_fastly_api_token" value="<?php echo esc_attr(get_option('twfcp_fastly_api_token')); ?>" class="regular-text" required />
                                    <p class="description">Your Fastly API Token (required). Get it from your <a href="https://manage.fastly.com/account/personal/tokens" target="_blank">Fastly account</a>.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Service ID <span style="color: red;">*</span></th>
                                <td>
                                    <input type="text" name="twfcp_fastly_service_id" value="<?php echo esc_attr(get_option('twfcp_fastly_service_id')); ?>" class="regular-text" required />
                                    <p class="description">Your Fastly Service ID (required). Find it in your Fastly dashboard.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Default Surrogate Key</th>
                                <td>
                                    <input type="text" name="twfcp_fastly_default_key" value="<?php echo esc_attr(get_option('twfcp_fastly_default_key')); ?>" class="regular-text" />
                                    <p class="description">Default surrogate key for quick purge (optional)</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Predefined Surrogate Keys</th>
                                <td>
                                    <textarea name="twfcp_fastly_surrogate_keys" rows="8" class="large-text"><?php echo esc_textarea(get_option('twfcp_fastly_surrogate_keys')); ?></textarea>
                                    <p class="description">Enter surrogate keys (one per line) for individual purge buttons. Examples:<br>
                                    <code>homepage<br>blog<br>products<br>category-electronics<br>user-123</code></p>
                                </td>
                            </tr>
                        </table>
                    </div>
                    
                    <div class="card">
                        <h3>About Surrogate Keys</h3>
                        <p>Surrogate keys (also called cache tags) allow you to purge specific groups of cached content instead of purging everything. This is more efficient and provides better performance.</p>
                        <p><strong>Common surrogate key patterns:</strong></p>
                        <ul>
                            <li><code>homepage</code> - for your site's homepage</li>
                            <li><code>post-{ID}</code> - for individual posts (e.g., post-123)</li>
                            <li><code>category-{slug}</code> - for category pages</li>
                            <li><code>user-{ID}</code> - for user-specific content</li>
                            <li><code>product-{ID}</code> - for e-commerce products</li>
                        </ul>
                        <p>You'll need to add these surrogate keys to your HTTP responses using the <code>Surrogate-Key</code> header for them to work effectively.</p>
                    </div>
                    
                    <?php submit_button('Save Settings'); ?>
                </form>
            </div>
        <?php endif; ?>
    </div>
    
    <style>
    .card {
        background: #fff;
        border: 1px solid #ccd0d4;
        box-shadow: 0 1px 1px rgba(0,0,0,.04);
        margin: 20px 0;
        padding: 20px;
    }
    .card h3 {
        margin-top: 0;
    }
    .tab-content {
        margin-top: 20px;
    }
    </style>
    <?php
}

// Trigger purge handling on admin init
add_action('admin_init', 'twfcp_handle_cache_purge');

// Add plugin action links
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'twfcp_add_action_links');
function twfcp_add_action_links($links) {
    $plugin_links = array(
        '<a href="' . admin_url('options-general.php?page=tw-fastly-cache-purger') . '">Settings</a>',
        '<a href="' . admin_url('options-general.php?page=tw-fastly-cache-purger&tab=purge') . '">Purge Cache</a>',
    );
    return array_merge($plugin_links, $links);
}

// Plugin activation hook
register_activation_hook(__FILE__, 'twfcp_activate');
function twfcp_activate() {
    // Set default options
    add_option('twfcp_fastly_enabled', 0);
    add_option('twfcp_fastly_surrogate_keys', "homepage\nblog\nproducts");
}

// Plugin deactivation hook
register_deactivation_hook(__FILE__, 'twfcp_deactivate');
function twfcp_deactivate() {
    // Clean up transients
    delete_transient('twfcp_purge_cache_notice');
}

// Security: Prevent direct execution
if (!function_exists('add_action')) {
    exit('Direct access denied.');
}

?>