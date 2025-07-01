<?php
/**
 * Plugin Name: TW Cache Purger for Bunny CDN
 * Plugin URI: https://github.com/drshounak/wordpress-plugins/tree/main/Cache%20Purger%20Plugins/bunnycdn_cache_purger
 * Description: Simple cache purger for Varnish and Bunny CDN with folder and tag support
 * Version: 2.2.1
 * Author: TechWeirdo
 * Author URI: https://twitter.com/drshounakpal
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: tw-cache-purger
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
define('TWCP_VERSION', '2.2.1');
define('TWCP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('TWCP_PLUGIN_PATH', plugin_dir_path(__FILE__));

// Add admin menu item
add_action('admin_menu', 'twcp_add_admin_menu');
function twcp_add_admin_menu() {
    add_options_page(
        'TW Cache Purger',
        'TW Cache Purger',
        'manage_options',
        'tw-cache-purger',
        'twcp_admin_page'
    );
}

// Register settings
add_action('admin_init', 'twcp_register_settings');
function twcp_register_settings() {
    // Varnish settings
    register_setting('twcp_settings', 'twcp_varnish_url');
    register_setting('twcp_settings', 'twcp_varnish_enabled');
    
    // Bunny CDN settings
    register_setting('twcp_settings', 'twcp_bunny_api_key');
    register_setting('twcp_settings', 'twcp_bunny_pullzone_id');
    register_setting('twcp_settings', 'twcp_bunny_storage_zone');
    register_setting('twcp_settings', 'twcp_bunny_storage_password');
    register_setting('twcp_settings', 'twcp_bunny_enabled');
    
    // Cache tags and folders settings
    register_setting('twcp_settings', 'twcp_cache_tags');
    register_setting('twcp_settings', 'twcp_cache_folders');
    register_setting('twcp_settings', 'twcp_default_cache_tag');
}

// Add Purge Cache button to admin bar (now uses default tag + varnish)
add_action('admin_bar_menu', function($wp_admin_bar) {
    if (!current_user_can('manage_options')) {
        return;
    }
    $wp_admin_bar->add_node(array(
        'id'    => 'tw-purge-cache',
        'title' => 'Purge Cache',
        'href'  => wp_nonce_url(admin_url('options-general.php?page=tw-cache-purger&purge_standard=1'), 'twcp_purge_cache_action'),
    ));
}, 100);

// Get predefined WordPress folders
function twcp_get_wp_folders() {
    $base_url = home_url();
    $default_folders = array(
        'wp-content' => $base_url . '/wp-content/',
        'uploads' => $base_url . '/wp-content/uploads/',
        'themes' => $base_url . '/wp-content/themes/',
        'wp-includes' => $base_url . '/wp-includes/'
    );
    
    $custom_folders = get_option('twcp_cache_folders', array());
    return array_merge($default_folders, $custom_folders);
}

// Get cache tags
function twcp_get_cache_tags() {
    $tags = get_option('twcp_cache_tags', array());
    if (empty($tags)) {
        // Default tags
        $tags = array(
            'dynamic' => 'dynamic',
            'static' => 'static'
        );
    }
    return $tags;
}

// Bunny CDN folder purge function
function twcp_purge_bunny_folder($folder_url) {
    $api_key = get_option('twcp_bunny_api_key');
    
    if (empty($api_key) || empty($folder_url)) {
        return new WP_Error('missing_config', 'API key or folder URL missing');
    }
    
    // Encode the URL and add wildcard
    $encoded_url = urlencode($folder_url . '*');
    
    $response = wp_remote_post("https://api.bunny.net/purge?url={$encoded_url}&async=false", array(
        'method' => 'POST',
        'headers' => array(
            'AccessKey' => $api_key
        ),
        'timeout' => 30,
    ));
    
    if (is_wp_error($response)) {
        return $response;
    }
    
    $response_code = wp_remote_retrieve_response_code($response);
    if ($response_code === 200 || $response_code === 204) {
        return true;
    } else {
        $body = wp_remote_retrieve_body($response);
        $error_message = 'Status ' . $response_code . ': ' . $body;
        return new WP_Error('bunny_folder_error', $error_message);
    }
}

// Bunny CDN cache tag purge function
function twcp_purge_bunny_cache_tag($cache_tag) {
    $api_key = get_option('twcp_bunny_api_key');
    $pullzone_id = get_option('twcp_bunny_pullzone_id');
    
    if (empty($api_key) || empty($pullzone_id) || empty($cache_tag)) {
        return new WP_Error('missing_config', 'API key, pull zone ID, or cache tag missing');
    }
    
    $response = wp_remote_post("https://api.bunny.net/pullzone/{$pullzone_id}/purgeCache", array(
        'method' => 'POST',
        'headers' => array(
            'AccessKey' => $api_key,
            'Content-Type' => 'application/json'
        ),
        'body' => json_encode(array(
            'CacheTag' => $cache_tag
        )),
        'timeout' => 30,
    ));
    
    if (is_wp_error($response)) {
        return $response;
    }
    
    $response_code = wp_remote_retrieve_response_code($response);
    if ($response_code === 200 || $response_code === 204) {
        return true;
    } else {
        $body = wp_remote_retrieve_body($response);
        $error_message = 'Status ' . $response_code . ': ' . $body;
        return new WP_Error('bunny_tag_error', $error_message);
    }
}

// Bunny CDN one-time purge function (for unsaved tag or URL)
function twcp_purge_bunny_one_time($type, $value) {
    $api_key = get_option('twcp_bunny_api_key');
    $pullzone_id = get_option('twcp_bunny_pullzone_id');
    
    if (empty($api_key) || empty($pullzone_id)) {
        return new WP_Error('missing_config', 'API key or pull zone ID missing');
    }
    
    if ($type === 'url') {
        $encoded_url = urlencode($value . '*');
        $url = "https://api.bunny.net/purge?url={$encoded_url}&async=false";
        $args = array(
            'method' => 'POST',
            'headers' => array(
                'AccessKey' => $api_key
            ),
            'timeout' => 30,
        );
    } else {
        $url = "https://api.bunny.net/pullzone/{$pullzone_id}/purgeCache";
        $args = array(
            'method' => 'POST',
            'headers' => array(
                'AccessKey' => $api_key,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode(array(
                'CacheTag' => $value
            )),
            'timeout' => 30,
        );
    }
    
    $response = wp_remote_post($url, $args);
    
    if (is_wp_error($response)) {
        return $response;
    }
    
    $response_code = wp_remote_retrieve_response_code($response);
    if ($response_code === 200 || $response_code === 204) {
        return true;
    } else {
        $body = wp_remote_retrieve_body($response);
        $error_message = 'Status ' . $response_code . ': ' . $body;
        return new WP_Error('bunny_one_time_error', $error_message);
    }
}

// Bunny CDN full zone cache purge function
function twcp_purge_bunny_full() {
    $api_key = get_option('twcp_bunny_api_key');
    $pullzone_id = get_option('twcp_bunny_pullzone_id');
    $storage_zone = get_option('twcp_bunny_storage_zone');
    $storage_password = get_option('twcp_bunny_storage_password');
    
    $results = array();
    
    // Purge CDN Cache if pullzone ID is provided
    if (!empty($api_key) && !empty($pullzone_id)) {
        $response = wp_remote_post("https://api.bunny.net/pullzone/{$pullzone_id}/purgeCache", array(
            'method' => 'POST',
            'headers' => array(
                'Content-Type' => 'application/json',
                'AccessKey' => $api_key
            ),
            'timeout' => 30,
        ));
        
        if (is_wp_error($response)) {
            $results['cdn'] = $response;
        } else {
            $response_code = wp_remote_retrieve_response_code($response);
            if ($response_code === 200 || $response_code === 204) {
                $results['cdn'] = true;
            } else {
                $body = wp_remote_retrieve_body($response);
                $error_message = 'Status ' . $response_code . ': ' . $body;
                $results['cdn'] = new WP_Error('bunny_cdn_error', $error_message);
            }
        }
    }
    
    // Purge Storage Zone if storage zone is provided
    if (!empty($storage_zone) && !empty($storage_password)) {
        $response = wp_remote_request("https://storage.bunnycdn.com/{$storage_zone}/__bcdn_perma_cache__/", array(
            'method' => 'DELETE',
            'headers' => array(
                'AccessKey' => $storage_password
            ),
            'timeout' => 30,
        ));
        
        if (is_wp_error($response)) {
            $results['storage'] = $response;
        } else {
            $response_code = wp_remote_retrieve_response_code($response);
            if ($response_code === 200 || $response_code === 204) {
                $results['storage'] = true;
            } else {
                $body = wp_remote_retrieve_body($response);
                $error_message = 'Status ' . $response_code . ': ' . $body;
                $results['storage'] = new WP_Error('bunny_storage_error', $error_message);
            }
        }
    }
    
    return $results;
}

// Varnish cache purge function
function twcp_purge_varnish() {
    $varnish_url = get_option('twcp_varnish_url', 'http://127.0.0.1:6081');
    
    $response = wp_remote_request($varnish_url, array(
        'method'  => 'PURGE',
        'headers' => array(
            'Host' => parse_url(home_url(), PHP_URL_HOST),
        ),
        'timeout' => 30,
    ));
    
    return $response;
}

// Handle cache purge logic
function twcp_handle_cache_purge() {
    if (!current_user_can('manage_options')) {
        return;
    }
    
    $is_admin_page = (is_admin() && isset($_GET['page']) && $_GET['page'] === 'tw-cache-purger');
    $is_admin_bar = isset($_GET['purge_standard']) && $_GET['purge_standard'] === '1' && check_admin_referer('twcp_purge_cache_action');
    $is_form_submit = isset($_POST['purge_action']) && check_admin_referer('twcp_purge_cache_action');
    $is_one_time_purge = isset($_POST['one_time_purge']) && check_admin_referer('twcp_one_time_purge_action');
    
    if ($is_admin_page && ($is_admin_bar || $is_form_submit || $is_one_time_purge)) {
        $messages = array();
        
        // Handle one-time purge
        if ($is_one_time_purge) {
            $type = sanitize_text_field($_POST['purge_type']);
            $value = sanitize_text_field($_POST['purge_value']);
            if (!empty($type) && !empty($value) && get_option('twcp_bunny_enabled')) {
                $result = twcp_purge_bunny_one_time($type, $value);
                if (is_wp_error($result)) {
                    $messages[] = 'Bunny One-Time Purge (' . $type . '): Failed - ' . $result->get_error_message();
                } else {
                    $messages[] = 'Bunny One-Time Purge (' . $type . '): Cache purged successfully';
                }
                twcp_purge_bunny_storage_if_enabled($messages);
            }
        }
        
        // Handle admin bar standard purge or form submit
        if ($is_admin_bar || $is_form_submit) {
            if ($is_admin_bar) {
                $action = 'standard';
            } else {
                $action = sanitize_text_field($_POST['purge_action']);
            }
            
            switch ($action) {
                case 'standard':
                    // Default tag + Varnish
                    if (get_option('twcp_bunny_enabled')) {
                        $default_tag = get_option('twcp_default_cache_tag', 'dynamic');
                        $cache_tags = twcp_get_cache_tags();
                        if (isset($cache_tags[$default_tag])) {
                            $result = twcp_purge_bunny_cache_tag($cache_tags[$default_tag]);
                            if (is_wp_error($result)) {
                                $messages[] = 'Bunny Tag (' . $default_tag . '): Failed - ' . $result->get_error_message();
                            } else {
                                $messages[] = 'Bunny Tag (' . $default_tag . '): Cache purged successfully';
                            }
                        }
                        twcp_purge_bunny_storage_if_enabled($messages);
                    }
                    if (get_option('twcp_varnish_enabled')) {
                        $result = twcp_purge_varnish();
                        if (is_wp_error($result)) {
                            $messages[] = 'Varnish: Failed - ' . $result->get_error_message();
                        } else {
                            $messages[] = 'Varnish: Cache purged successfully';
                        }
                    }
                    break;
                    
                case 'nuke':
                    // Full Bunny + Full Varnish
                    if (get_option('twcp_bunny_enabled')) {
                        $bunny_results = twcp_purge_bunny_full();
                        if (!empty($bunny_results)) {
                            if (isset($bunny_results['cdn'])) {
                                if (is_wp_error($bunny_results['cdn'])) {
                                    $messages[] = 'Bunny CDN Full: Failed - ' . $bunny_results['cdn']->get_error_message();
                                } else {
                                    $messages[] = 'Bunny CDN Full: Cache purged successfully';
                                }
                            }
                            if (isset($bunny_results['storage'])) {
                                if (is_wp_error($bunny_results['storage'])) {
                                    $messages[] = 'Bunny Storage: Failed - ' . $bunny_results['storage']->get_error_message();
                                } else {
                                    $messages[] = 'Bunny Storage: Cache purged successfully';
                                }
                            }
                        }
                    }
                    if (get_option('twcp_varnish_enabled')) {
                        $result = twcp_purge_varnish();
                        if (is_wp_error($result)) {
                            $messages[] = 'Varnish Full: Failed - ' . $result->get_error_message();
                        } else {
                            $messages[] = 'Varnish Full: Cache purged successfully';
                        }
                    }
                    break;
                    
                case 'varnish_full':
                    if (get_option('twcp_varnish_enabled')) {
                        $result = twcp_purge_varnish();
                        if (is_wp_error($result)) {
                            $messages[] = 'Varnish: Failed - ' . $result->get_error_message();
                        } else {
                            $messages[] = 'Varnish: Cache purged successfully';
                        }
                    }
                    break;
                    
                case 'bunny_full':
                    if (get_option('twcp_bunny_enabled')) {
                        $bunny_results = twcp_purge_bunny_full();
                        if (!empty($bunny_results)) {
                            if (isset($bunny_results['cdn'])) {
                                if (is_wp_error($bunny_results['cdn'])) {
                                    $messages[] = 'Bunny CDN: Failed - ' . $bunny_results['cdn']->get_error_message();
                                } else {
                                    $messages[] = 'Bunny CDN: Cache purged successfully';
                                }
                            }
                            if (isset($bunny_results['storage'])) {
                                if (is_wp_error($bunny_results['storage'])) {
                                    $messages[] = 'Bunny Storage: Failed - ' . $bunny_results['storage']->get_error_message();
                                } else {
                                    $messages[] = 'Bunny Storage: Cache purged successfully';
                                }
                            }
                        }
                    }
                    break;
                    
                default:
                    // Handle folder purges
                    if (strpos($action, 'folder_') === 0) {
                        $folder_key = str_replace('folder_', '', $action);
                        $folders = twcp_get_wp_folders();
                        if (isset($folders[$folder_key]) && get_option('twcp_bunny_enabled')) {
                            $result = twcp_purge_bunny_folder($folders[$folder_key]);
                            if (is_wp_error($result)) {
                                $messages[] = 'Bunny Folder (' . $folder_key . '): Failed - ' . $result->get_error_message();
                            } else {
                                $messages[] = 'Bunny Folder (' . $folder_key . '): Cache purged successfully';
                            }
                            twcp_purge_bunny_storage_if_enabled($messages);
                        }
                    }
                    // Handle tag purges
                    elseif (strpos($action, 'tag_') === 0) {
                        $tag_key = str_replace('tag_', '', $action);
                        $cache_tags = twcp_get_cache_tags();
                        if (isset($cache_tags[$tag_key]) && get_option('twcp_bunny_enabled')) {
                            $result = twcp_purge_bunny_cache_tag($cache_tags[$tag_key]);
                            if (is_wp_error($result)) {
                                $messages[] = 'Bunny Tag (' . $tag_key . '): Failed - ' . $result->get_error_message();
                            } else {
                                $messages[] = 'Bunny Tag (' . $tag_key . '): Cache purged successfully';
                            }
                            twcp_purge_bunny_storage_if_enabled($messages);
                        }
                    }
                    break;
            }
        }
        
        // Store messages for display
        if (!empty($messages)) {
            set_transient('twcp_purge_messages', $messages, 60);
        }
        
        // Redirect to avoid resubmission
        if ($is_admin_bar || $is_form_submit || $is_one_time_purge) {
            wp_safe_redirect(admin_url('options-general.php?page=tw-cache-purger'));
            exit;
        }
    }
}

// Helper function to purge Bunny storage if enabled
function twcp_purge_bunny_storage_if_enabled(&$messages) {
    $storage_zone = get_option('twcp_bunny_storage_zone');
    $storage_password = get_option('twcp_bunny_storage_password');
    
    if (!empty($storage_zone) && !empty($storage_password)) {
        $response = wp_remote_request("https://storage.bunnycdn.com/{$storage_zone}/__bcdn_perma_cache__/", array(
            'method' => 'DELETE',
            'headers' => array(
                'AccessKey' => $storage_password
            ),
            'timeout' => 30,
        ));
        
        if (is_wp_error($response)) {
            $messages[] = 'Bunny Storage: Failed - ' . $response->get_error_message();
        } else {
            $response_code = wp_remote_retrieve_response_code($response);
            if ($response_code === 200 || $response_code === 204) {
                $messages[] = 'Bunny Storage: Cache purged successfully';
            } else {
                $body = wp_remote_retrieve_body($response);
                $error_message = 'Status ' . $response_code . ': ' . $body;
                $messages[] = 'Bunny Storage: Failed - ' . $error_message;
            }
        }
    }
}

// Admin page
function twcp_admin_page() {
    // Handle purge if triggered
    twcp_handle_cache_purge();
    
    // Handle cache tags update
    if (isset($_POST['update_cache_tags']) && check_admin_referer('twcp_cache_tags_action')) {
        $cache_tags = array();
        if (isset($_POST['tag_names']) && isset($_POST['tag_values'])) {
            $tag_names = array_map('sanitize_text_field', $_POST['tag_names']);
            $tag_values = array_map('sanitize_text_field', $_POST['tag_values']);
            
            for ($i = 0; $i < count($tag_names); $i++) {
                if (!empty($tag_names[$i]) && !empty($tag_values[$i])) {
                    $cache_tags[$tag_names[$i]] = $tag_values[$i];
                }
            }
        }
        update_option('twcp_cache_tags', $cache_tags);
        
        $default_tag = sanitize_text_field($_POST['twcp_default_cache_tag']);
        update_option('twcp_default_cache_tag', $default_tag);
        
        echo '<div class="notice notice-success is-dismissible"><p>Cache tags updated successfully!</p></div>';
    }
    
    // Handle custom folders update
    if (isset($_POST['update_cache_folders']) && check_admin_referer('twcp_cache_folders_action')) {
        $cache_folders = array();
        if (isset($_POST['folder_names']) && isset($_POST['folder_urls'])) {
            $folder_names = array_map('sanitize_text_field', $_POST['folder_names']);
            $folder_urls = array_map('sanitize_text_field', $_POST['folder_urls']);
            
            for ($i = 0; $i < count($folder_names); $i++) {
                if (!empty($folder_names[$i]) && !empty($folder_urls[$i])) {
                    $cache_folders[$folder_names[$i]] = $folder_urls[$i];
                }
            }
        }
        update_option('twcp_cache_folders', $cache_folders);
        
        echo '<div class="notice notice-success is-dismissible"><p>Folders updated successfully!</p></div>';
    }
    
    // Display messages
    if ($messages = get_transient('twcp_purge_messages')) {
        echo '<div class="notice notice-info is-dismissible"><ul>';
        foreach ($messages as $message) {
            echo '<li>' . esc_html($message) . '</li>';
        }
        echo '</ul></div>';
        delete_transient('twcp_purge_messages');
    }
    
    $cache_tags = twcp_get_cache_tags();
    $folders = twcp_get_wp_folders();
    $default_tag = get_option('twcp_default_cache_tag', 'dynamic');
    $custom_folders = get_option('twcp_cache_folders', array());
    ?>
    <div class="wrap">
        <h1>TW Cache Purger</h1>
        
        <div class="card" style="max-width: 800px;">
            <h2>Cache Purge Actions</h2>
            <p>Choose from different purge options based on your needs.</p>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                <div>
                    <h3>Quick Actions</h3>
                    <form method="post" action="" style="margin-bottom: 10px;">
                        <?php wp_nonce_field('twcp_purge_cache_action'); ?>
                        <input type="hidden" name="purge_action" value="standard">
                        <input type="submit" class="button button-primary" value="Standard Purge" style="width: 100%;">
                        <p class="description">Default tag + Full Varnish</p>
                    </form>
                    
                    <form method="post" action="">
                        <?php wp_nonce_field('twcp_purge_cache_action'); ?>
                        <input type="hidden" name="purge_action" value="nuke">
                        <input type="submit" class="button button-secondary" value="🚀 Nuke Cache" style="width: 100%;">
                        <p class="description">Full Bunny + Full Varnish</p>
                    </form>
                </div>
                
                <div>
                    <h3>Service-Specific</h3>
                    <form method="post" action="" style="margin-bottom: 10px;">
                        <?php wp_nonce_field('twcp_purge_cache_action'); ?>
                        <input type="hidden" name="purge_action" value="varnish_full">
                        <input type="submit" class="button" value="Purge Full Varnish" style="width: 100%;">
                    </form>
                    
                    <form method="post" action="">
                        <?php wp_nonce_field('twcp_purge_cache_action'); ?>
                        <input type="hidden" name="purge_action" value="bunny_full">
                        <input type="submit" class="button" value="Purge Full Bunny CDN" style="width: 100%;">
                    </form>
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div>
                    <h3>Folder Purges</h3>
                    <?php foreach ($folders as $key => $url): ?>
                        <form method="post" action="" style="margin-bottom: 5px;">
                            <?php wp_nonce_field('twcp_purge_cache_action'); ?>
                            <input type="hidden" name="purge_action" value="folder_<?php echo esc_attr($key); ?>">
                            <input type="submit" class="button button-small" value="Purge <?php echo esc_html(ucfirst($key)); ?>" style="width: 100%;">
                        </form>
                    <?php endforeach; ?>
                </div>
                
                <div>
                    <h3>Tag Purges</h3>
                    <?php foreach ($cache_tags as $key => $tags): ?>
                        <form method="post" action="" style="margin-bottom: 5px;">
                            <?php wp_nonce_field('twcp_purge_cache_action'); ?>
                            <input type="hidden" name="purge_action" value="tag_<?php echo esc_attr($key); ?>">
                            <input type="submit" class="button button-small" value="Purge <?php echo esc_html(ucfirst($key)); ?>" style="width: 100%;">
                        </form>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div style="margin-top: 20px;">
                <h3>One-Time Purge</h3>
                <form method="post" action="">
                    <?php wp_nonce_field('twcp_one_time_purge_action'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row">Purge Type</th>
                            <td>
                                <select name="purge_type" required>
                                    <option value="tag">Cache Tag</option>
                                    <option value="url">URL</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Value</th>
                            <td>
                                <input type="text" name="purge_value" class="regular-text" required />
                                <p class="description">Enter a cache tag or full URL to purge (will not be saved)</p>
                            </td>
                        </tr>
                    </table>
                    <input type="submit" name="one_time_purge" class="button" value="Purge Now" />
                </form>
            </div>
        </div>
        
        <div class="card" style="max-width: 800px; margin-top: 20px;">
            <h2>Service Configuration</h2>
            
            <form method="post" action="options.php">
                <?php 
                settings_fields('twcp_settings');
                do_settings_sections('twcp_settings');
                ?>
                
                <h3>Varnish Settings</h3>
                <table class="form-table">
                    <tr>
                        <th scope="row">Enable Varnish</th>
                        <td>
                            <label>
                                <input type="checkbox" name="twcp_varnish_enabled" value="1" <?php checked(get_option('twcp_varnish_enabled')); ?> />
                                Enable Varnish cache purging
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Varnish URL</th>
                        <td>
                            <input type="text" name="twcp_varnish_url" value="<?php echo esc_attr(get_option('twcp_varnish_url', 'http://127.0.0.1:6081')); ?>" class="regular-text" />
                            <p class="description">Default: http://127.0.0.1:6081</p>
                        </td>
                    </tr>
                </table>
                
                <h3>Bunny CDN Settings</h3>
                <table class="form-table">
                    <tr>
                        <th scope="row">Enable Bunny CDN</th>
                        <td>
                            <label>
                                <input type="checkbox" name="twcp_bunny_enabled" value="1" <?php checked(get_option('twcp_bunny_enabled')); ?> />
                                Enable Bunny CDN cache purging
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">API Key</th>
                        <td>
                            <input type="password" name="twcp_bunny_api_key" value="<?php echo esc_attr(get_option('twcp_bunny_api_key')); ?>" class="regular-text" />
                            <p class="description">Your Bunny CDN API Key (required for folder and tag purging)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Pull Zone ID</th>
                        <td>
                            <input type="text" name="twcp_bunny_pullzone_id" value="<?php echo esc_attr(get_option('twcp_bunny_pullzone_id')); ?>" class="regular-text" />
                            <p class="description">Your Bunny CDN Pull Zone ID (required for full zone and tag purging)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Storage Zone Name</th>
                        <td>
                            <input type="text" name="twcp_bunny_storage_zone" value="<?php echo esc_attr(get_option('twcp_bunny_storage_zone')); ?>" class="regular-text" />
                            <p class="description">Your Bunny Storage Zone name (optional, purged with all Bunny operations when enabled)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Storage Zone Password</th>
                        <td>
                            <input type="password" name="twcp_bunny_storage_password" value="<?php echo esc_attr(get_option('twcp_bunny_storage_password')); ?>" class="regular-text" />
                            <p class="description">Your Bunny Storage Zone password (required if using storage zone)</p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button('Save Settings'); ?>
            </form>
        </div>
        
        <div class="card" style="max-width: 800px; margin-top: 20px;">
            <h2>Cache Tags Management</h2>
            <p>Configure cache tags for selective purging.</p>
            
            <form method="post" action="">
                <?php wp_nonce_field('twcp_cache_tags_action'); ?>
                
                <table class="widefat" style="margin-bottom: 20px;">
                    <thead>
 двад

                        <tr>
                            <th style="width: 200px;">Tag Name</th>
                            <th>Cache Tag</th>
                            <th style="width: 80px;">Default</th>
                            <th style="width: 80px;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="cache-tags-tbody">
                        <?php foreach ($cache_tags as $key => $tag): ?>
                            <tr>
                                <td><input type="text" name="tag_names[]" value="<?php echo esc_attr($key); ?>" class="regular-text" /></td>
                                <td><input type="text" name="tag_values[]" value="<?php echo esc_attr($tag); ?>" style="width: 100%;" /></td>
                                <td><input type="radio" name="twcp_default_cache_tag" value="<?php echo esc_attr($key); ?>" <?php checked($default_tag, $key); ?> /></td>
                                <td><button type="button" class="button-link-delete remove-tag">Remove</button></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($cache_tags)): ?>
                            <tr>
                                <td><input type="text" name="tag_names[]" value="" class="regular-text" /></td>
                                <td><input type="text" name="tag_values[]" value="" style="width: 100%;" /></td>
                                <td><input type="radio" name="twcp_default_cache_tag" value="" /></td>
                                <td><button type="button" class="button-link-delete remove-tag">Remove</button></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                
                <p>
                    <button type="button" id="add-cache-tag" class="button">Add New Tag</button>
                    <input type="submit" name="update_cache_tags" class="button button-primary" value="Update Cache Tags" style="margin-left: 10px;">
                </p>
            </form>
        </div>
        
        <div class="card" style="max-width: 800px; margin-top: 20px;">
            <h2>Folders Management</h2>
            <p>Configure custom folders for selective purging. Default folders (wp-content, uploads, themes, wp-includes) are always included.</p>
            
            <form method="post" action="">
                <?php wp_nonce_field('twcp_cache_folders_action'); ?>
                
                <table class="widefat" style="margin-bottom: 20px;">
                    <thead>
                        <tr>
                            <th style="width: 200px;">Folder Name</th>
                            <th>Folder URL</th>
                            <th style="width: 80px;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="cache-folders-tbody">
                        <?php foreach ($custom_folders as $key => $url): ?>
                            <tr>
                                <td><input type="text" name="folder_names[]" value="<?php echo esc_attr($key); ?>" class="regular-text" /></td>
                                <td><input type="text" name="folder_urls[]" value="<?php echo esc_attr($url); ?>" style="width: 100%;" /></td>
                                <td><button type="button" class="button-link-delete remove-folder">Remove</button></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr>
                            <td><input type="text" name="folder_names[]" value="" class="regular-text" /></td>
                            <td><input type="text" name="folder_urls[]" value="" style="width: 100%;" /></td>
                            <td><button type="button" class="button-link-delete remove-folder">Remove</button></td>
                        </tr>
                    </tbody>
                </table>
                
                <p>
                    <button type="button" id="add-cache-folder" class="button">Add New Folder</button>
                    <input type="submit" name="update_cache_folders" class="button button-primary" value="Update Folders" style="margin-left: 10px;">
                </p>
            </form>
            
            <div style="margin-top: 20px; padding: 15px; background: #f0f0f1; border-radius: 4px;">
                <h4>Default WordPress Folders:</h4>
                <ul style="margin: 0; columns: 2;">
                    <?php foreach (array_diff_key($folders, $custom_folders) as $key => $url): ?>
                        <li><strong><?php echo esc_html(ucfirst($key)); ?>:</strong> <?php echo esc_html($url); ?>*</li>
                    <?php endforeach; ?>
                </ul>
                <p style="margin-top: 10px; font-style: italic;">Folder purges use the Bunny CDN URL-based purge API and automatically append /* to purge all files in the folder.</p>
            </div>
        </div>
        
        <div class="card" style="max-width: 800px; margin-top: 20px;">
            <h2>Service Status</h2>
            <table class="widefat">
                <thead>
                    <tr>
                        <th>Service</th>
                        <th>Status</th>
                        <th>Configuration</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>Varnish</strong></td>
                        <td>
                            <?php if (get_option('twcp_varnish_enabled')): ?>
                                <span style="color: green;">✓ Enabled</span>
                            <?php else: ?>
                                <span style="color: #666;">○ Disabled</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (get_option('twcp_varnish_enabled')): ?>
                                URL: <?php echo esc_html(get_option('twcp_varnish_url', 'http://127.0.0.1:6081')); ?>
                            <?php else: ?>
                                <em>Not configured</em>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>Bunny CDN</strong></td>
                        <td>
                            <?php if (get_option('twcp_bunny_enabled')): ?>
                                <span style="color: green;">✓ Enabled</span>
                            <?php else: ?>
                                <span style="color: #666;">○ Disabled</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (get_option('twcp_bunny_enabled')): ?>
                                API Key: <?php echo get_option('twcp_bunny_api_key') ? '<span style="color: green;">✓ Set</span>' : '<span style="color: red;">✗ Missing</span>'; ?><br>
                                Pull Zone ID: <?php echo get_option('twcp_bunny_pullzone_id') ? '<span style="color: green;">✓ Set</span>' : '<span style="color: orange;">○ Optional</span>'; ?>
                            <?php else: ?>
                                <em>Not configured</em>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>Bunny Storage</strong></td>
                        <td>
                            <?php if (get_option('twcp_bunny_enabled') && get_option('twcp_bunny_storage_zone') && get_option('twcp_bunny_storage_password')): ?>
                                <span style="color: green;">✓ Enabled</span>
                            <?php else: ?>
                                <span style="color: #666;">○ Disabled</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (get_option('twcp_bunny_enabled')): ?>
                                Storage Zone: <?php echo get_option('twcp_bunny_storage_zone') ? '<span style="color: green;">✓ Set</span>' : '<span style="color: orange;">○ Optional</span>'; ?><br>
                                Storage Password: <?php echo get_option('twcp_bunny_storage_password') ? '<span style="color: green;">✓ Set</span>' : '<span style="color: orange;">○ Optional</span>'; ?>
                            <?php else: ?>
                                <em>Not configured</em>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>Default Cache Tag</strong></td>
                        <td>
                            <?php if ($default_tag): ?>
                                <span style="color: green;">✓ Set</span>
                            <?php else: ?>
                                <span style="color: orange;">○ Not set</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($default_tag): ?>
                                <strong><?php echo esc_html($default_tag); ?>:</strong> <?php echo esc_html($cache_tags[$default_tag] ?? 'Tag not found'); ?>
                            <?php else: ?>
                                <em>No default tag selected</em>
                            <?php endif; ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        
        <div class="card" style="max-width: 800px; margin-top: 20px;">
            <h2>About</h2>
            <p><strong>TW Cache Purger</strong> - Version <?php echo TWCP_VERSION; ?></p>
            <p>A comprehensive WordPress plugin for managing Varnish and Bunny CDN cache with support for folder-based and tag-based purging.</p>
            
            <h3>Features</h3>
            <ul>
                <li><strong>Standard Purge:</strong> Purges default cache tag via Bunny CDN + full Varnish cache</li>
                <li><strong>Nuke Cache:</strong> Complete cache purge for both Bunny CDN and Varnish</li>
                <li><strong>Folder Purging:</strong> Selective purging of WordPress folders (wp-content, uploads, themes, etc.)</li>
                <li><strong>Tag-based Purging:</strong> Customizable cache tags for granular control</li>
                <li><strong>One-Time Purge:</strong> Purge specific tags or URLs without saving</li>
                <li><strong>Storage Zone Support:</strong> Automatic Bunny Storage Zone purging when enabled</li>
                <li><strong>Admin Bar Integration:</strong> Quick access to standard purge from admin bar</li>
            </ul>
            
            <h3>Purge Methods</h3>
            <ul>
                <li><strong>Bunny CDN Folder Purge:</strong> Uses <code>https://api.bunny.net/purge?url=ENCODED_URL/*</code></li>
                <li><strong>Bunny CDN Tag Purge:</strong> Uses <code>https://api.bunny.net/pullzone/ID/purgeCache</code> with JSON payload</li>
                <li><strong>Bunny CDN Full Purge:</strong> Uses <code>https://api.bunny.net/pullzone/ID/purgeCache</code></li>
                <li><strong>Varnish Purge:</strong> Uses PURGE method to configured Varnish URL</li>
                <li><strong>Storage Zone Purge:</strong> Deletes <code>__bcdn_perma_cache__</code> folder</li>
            </ul>
            
            <p>
                <a href="https://www.techweirdo.net" target="_blank">Tech Weirdo</a> | 
                <a href="https://github.com/techweirdo/tw-cache-purger" target="_blank">GitHub</a>
            </p>
        </div>
    </div>
    
    <script>
        jQuery(document).ready(function($) {
            // Add new cache tag row
            $('#add-cache-tag').on('click', function() {
                var newRow = '<tr>' +
                    '<td><input type="text" name="tag_names[]" value="" class="regular-text" /></td>' +
                    '<td><input type="text" name="tag_values[]" value="" style="width: 100%;" /></td>' +
                    '<td><input type="radio" name="twcp_default_cache_tag" value="" /></td>' +
                    '<td><button type="button" class="button-link-delete remove-tag">Remove</button></td>' +
                    '</tr>';
                $('#cache-tags-tbody').append(newRow);
            });
            
            // Add new folder row
            $('#add-cache-folder').on('click', function() {
                var newRow = '<tr>' +
                    '<td><input type="text" name="folder_names[]" value="" class="regular-text" /></td>' +
                    '<td><input type="text" name="folder_urls[]" value="" style="width: 100%;" /></td>' +
                    '<td><button type="button" class="button-link-delete remove-folder">Remove</button></td>' +
                    '</tr>';
                $('#cache-folders-tbody').append(newRow);
            });
            
            // Remove cache tag row
            $(document).on('click', '.remove-tag', function() {
                $(this).closest('tr').remove();
            });
            
            // Remove folder row
            $(document).on('click', '.remove-folder', function() {
                $(this).closest('tr').remove();
            });
            
            // Update radio button values when tag names change
            $(document).on('input', 'input[name="tag_names[]"]', function() {
                var $row = $(this).closest('tr');
                var tagName = $(this).val();
                $row.find('input[name="twcp_default_cache_tag"]').val(tagName);
            });
        });
    </script>
    
    <style>
        .card h3 {
            margin-top: 0;
            margin-bottom: 15px;
            border-bottom: 1px solid #ddd;
            padding-bottom: 10px;
        }
        
        .button-link-delete {
            color: #a00;
            text-decoration: none;
            font-size: 13px;
            line-height: 2;
            padding: 2px 0;
            cursor: pointer;
            border: none;
            background: none;
        }
        
        .button-link-delete:hover {
            color: #dc3232;
        }
        
        .widefat th {
            padding: 10px;
        }
        
        .widefat td {
            padding: 10px;
            vertical-align: top;
        }
        
        .form-table th {
            width: 200px;
        }
        
        .notice ul {
            margin: 0.5em 0;
            padding-left: 2em;
        }
        
        .notice li {
            margin: 0.25em 0;
        }
        
        .grid-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        @media (max-width: 768px) {
            .grid-container {
                grid-template-columns: 1fr;
            }
        }
    </style>
    <?php
}

// Trigger purge handling on admin init
add_action('admin_init', 'twcp_handle_cache_purge');

// Add plugin action links
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'twcp_add_action_links');
function twcp_add_action_links($links) {
    $plugin_links = array(
        '<a href="' . admin_url('options-general.php?page=tw-cache-purger') . '">Settings</a>',
    );
    return array_merge($plugin_links, $links);
}

// Plugin activation hook
register_activation_hook(__FILE__, 'twcp_activate');
function twcp_activate() {
    // Set default options
    add_option('twcp_varnish_url', 'http://127.0.0.1:6081');
    add_option('twcp_varnish_enabled', 1);
    add_option('twcp_bunny_enabled', 0);
    
    // Set default cache tags
    $default_tags = array(
        'dynamic' => 'dynamic',
        'static' => 'static'
    );
    add_option('twcp_cache_tags', $default_tags);
    add_option('twcp_default_cache_tag', 'dynamic');
    
    // Set default custom folders (empty initially)
    add_option('twcp_cache_folders', array());
}

// Plugin deactivation hook
register_deactivation_hook(__FILE__, 'twcp_deactivate');
function twcp_deactivate() {
    // Clean up transients
    delete_transient('twcp_purge_messages');
}
?>
