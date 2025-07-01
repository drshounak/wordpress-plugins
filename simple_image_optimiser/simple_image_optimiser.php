<?php
 * Plugin Name: Simple Image Optimiser by TechWeirdo
 * Plugin URI: https://github.com/drshounak/wordpress-plugins/tree/main/cf7_enhanced_addon
 * Description: Converts uploaded images to WebP format automatically with customizable compression and resizing
 * Version: 1.0.0
 * Author: TechWeirdo
 * Author URI: https://twitter.com/drshounakpal
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: aio
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

class AdvancedImageConverter {
    
    private $options;
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'settings_init'));
        
        // Hook into upload process
        add_filter('wp_handle_upload', array($this, 'process_upload'), 10, 2);
        
        // Add bulk action for existing images
        add_filter('bulk_actions-upload', array($this, 'add_bulk_action'));
        add_filter('handle_bulk_actions-upload', array($this, 'handle_bulk_action'), 10, 3);
        
        // Add individual convert action
        add_filter('media_row_actions', array($this, 'add_media_row_action'), 10, 2);
        add_action('wp_ajax_convert_single_image', array($this, 'ajax_convert_single_image'));
        
        // Add admin scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
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
    
    public function add_admin_menu() {
        add_options_page(
            'Advanced Image Converter',
            'Image Converter',
            'manage_options',
            'advanced-image-converter',
            array($this, 'options_page')
        );
    }
    
    public function settings_init() {
        register_setting('aic_settings', 'aic_settings');
        
        add_settings_section(
            'aic_settings_section',
            'Image Conversion Settings',
            null,
            'aic_settings'
        );
        
        add_settings_field(
            'max_width',
            'Max Width (px)',
            array($this, 'max_width_render'),
            'aic_settings',
            'aic_settings_section'
        );
        
        add_settings_field(
            'max_height',
            'Max Height (px)',
            array($this, 'max_height_render'),
            'aic_settings',
            'aic_settings_section'
        );
        
        add_settings_field(
            'output_format',
            'Output Format',
            array($this, 'output_format_render'),
            'aic_settings',
            'aic_settings_section'
        );
        
        add_settings_field(
            'quality',
            'Quality (1-100)',
            array($this, 'quality_render'),
            'aic_settings',
            'aic_settings_section'
        );
        
        add_settings_field(
            'keep_original',
            'Keep Original Files',
            array($this, 'keep_original_render'),
            'aic_settings',
            'aic_settings_section'
        );
        
        add_settings_field(
            'auto_convert',
            'Auto Convert on Upload',
            array($this, 'auto_convert_render'),
            'aic_settings',
            'aic_settings_section'
        );
    }
    
    public function max_width_render() {
        echo '<input type="number" name="aic_settings[max_width]" value="' . $this->options['max_width'] . '" min="100" max="5000">';
    }
    
    public function max_height_render() {
        echo '<input type="number" name="aic_settings[max_height]" value="' . $this->options['max_height'] . '" min="100" max="5000">';
    }
    
    public function output_format_render() {
        echo '<select name="aic_settings[output_format]">';
        echo '<option value="webp"' . selected($this->options['output_format'], 'webp', false) . '>WebP</option>';
        echo '<option value="avif"' . selected($this->options['output_format'], 'avif', false) . '>AVIF</option>';
        echo '</select>';
    }
    
    public function quality_render() {
        echo '<input type="number" name="aic_settings[quality]" value="' . $this->options['quality'] . '" min="1" max="100">';
    }
    
    public function keep_original_render() {
        echo '<input type="checkbox" name="aic_settings[keep_original]" value="1"' . checked($this->options['keep_original'], 1, false) . '>';
        echo '<label>Keep original files on server</label>';
    }
    
    public function auto_convert_render() {
        echo '<input type="checkbox" name="aic_settings[auto_convert]" value="1"' . checked($this->options['auto_convert'], 1, false) . '>';
        echo '<label>Automatically convert images on upload</label>';
    }
    
    public function options_page() {
        ?>
        <div class="wrap">
            <h1>Advanced Image Converter Settings</h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('aic_settings');
                do_settings_sections('aic_settings');
                submit_button();
                ?>
            </form>
            
            <h2>Bulk Convert Existing Images</h2>
            <p>Go to Media Library and use the bulk action "Convert Images" to convert existing images.</p>
            
            <div id="aic-bulk-convert">
                <button type="button" id="aic-convert-all" class="button button-primary">Convert All Images</button>
                <div id="aic-progress" style="display:none;">
                    <div id="aic-progress-bar" style="width: 100%; background-color: #f0f0f0; border-radius: 3px; margin: 10px 0;">
                        <div id="aic-progress-fill" style="height: 20px; background-color: #0073aa; border-radius: 3px; width: 0%; transition: width 0.3s;"></div>
                    </div>
                    <div id="aic-progress-text">0% Complete</div>
                </div>
            </div>
        </div>
        <?php
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
            // Replace original with converted
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
        
        // Check if already converted (avoid duplication)
        $pathinfo = pathinfo($file_path);
        if (strpos($pathinfo['filename'], 'compressed_') === 0) {
            return false;
        }
        
        // Handle different image formats including HEIC
        $image_info = $this->get_image_info($file_path);
        if (!$image_info) {
            return false;
        }
        
        // Skip if image is already in target format
        $target_mime = 'image/' . $this->options['output_format'];
        if ($image_info['mime'] === $target_mime) {
            return false;
        }
        
        $image = $this->create_image_resource($file_path, $image_info['mime']);
        if (!$image) {
            return false;
        }
        
        // Calculate new dimensions preserving aspect ratio
        $new_dimensions = $this->calculate_dimensions(
            $image_info['width'],
            $image_info['height'],
            $this->options['max_width'],
            $this->options['max_height']
        );
        
        // Resize image
        $resized_image = imagecreatetruecolor($new_dimensions['width'], $new_dimensions['height']);
        
        // Preserve transparency for PNG/GIF
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
        
        // Generate new filename
        $upload_dir = wp_upload_dir();
        $relative_path = str_replace($upload_dir['basedir'], '', $file_path);
        $new_filename = 'compressed_' . $pathinfo['filename'] . '.' . $this->options['output_format'];
        $new_file_path = $pathinfo['dirname'] . '/' . $new_filename;
        $new_url = $upload_dir['baseurl'] . str_replace($pathinfo['basename'], $new_filename, $relative_path);
        
        // Save converted image
        $success = false;
        if ($this->options['output_format'] === 'webp' && function_exists('imagewebp')) {
            $success = imagewebp($resized_image, $new_file_path, $this->options['quality']);
        } elseif ($this->options['output_format'] === 'avif' && function_exists('imageavif')) {
            $success = imageavif($resized_image, $new_file_path, $this->options['quality']);
        }
        
        // Cleanup
        imagedestroy($image);
        imagedestroy($resized_image);
        
        if ($success) {
            // Update attachment metadata if provided
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
        
        // Handle HEIC files
        if ($mime_type === 'image/heic' || $mime_type === 'image/heif') {
            // HEIC requires special handling - you might need to install ImageMagick
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
                // Try to create from string for RAW and other formats
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
        
        // Update attachment URL
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
    
    public function enqueue_admin_scripts($hook) {
        if ($hook === 'upload.php' || $hook === 'settings_page_advanced-image-converter') {
            wp_enqueue_script('aic-admin', plugin_dir_url(__FILE__) . 'aic-admin.js', array('jquery'), '1.0.0', true);
            wp_localize_script('aic-admin', 'aic_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('convert_single_image')
            ));
        }
    }
}

// Initialize the plugin
new AdvancedImageConverter();

// JavaScript for admin functionality
function aic_admin_js() {
    ?>
    <script type="text/javascript">
    function convertSingleImage(attachmentId) {
        if (confirm('Convert this image?')) {
            jQuery.ajax({
                url: aic_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'convert_single_image',
                    attachment_id: attachmentId,
                    nonce: aic_ajax.nonce
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
                                    nonce: aic_ajax.nonce
                                },
                                complete: function() {
                                    converted++;
                                    var percent = (converted / total) * 100;
                                    $('#aic-progress-fill').css('width', percent + '%');
                                    $('#aic-progress-text').text(Math.round(percent) + '% Complete (' + converted + '/' + total + ')');
                                    
                                    setTimeout(convertNext, 100); // Small delay to prevent server overload
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
add_action('admin_footer', 'aic_admin_js');

// AJAX handler for getting all images
add_action('wp_ajax_get_all_images', 'aic_get_all_images');
function aic_get_all_images() {
    $images = get_posts(array(
        'post_type' => 'attachment',
        'post_mime_type' => 'image',
        'post_status' => 'inherit',
        'numberposts' => -1,
        'fields' => 'ids'
    ));
    
    wp_send_json_success($images);
}
?>
