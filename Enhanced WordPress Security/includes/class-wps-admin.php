<?php
class WPS_Admin {
    public function init() {
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));
    }

    public function add_settings_page() {
        add_options_page(
            'WP Security Settings',
            'WP Security',
            'manage_options',
            'wps-security',
            array($this, 'render_settings_page')
        );
    }

    public function register_settings() {
        register_setting('wps_security_group', 'wps_security_options', array($this, 'sanitize_options'));

        add_settings_section(
            'wps_security_main',
            'Security Settings',
            array($this, 'section_callback'),
            'wps-security'
        );

        $fields = array(
            'enable_svg_upload' => 'Enable SVG Upload Support',
            'enable_rest_api_restrictions' => 'Enable REST API Restrictions',
            'enable_security_headers' => 'Enable Security Headers',
            'enable_query_blocking' => 'Enable Suspicious Query Blocking',
            'enable_comment_filtering' => 'Enable Comment Filtering',
            'enable_author_protection' => 'Enable Author Protection',
            'enable_xmlrpc_disable' => 'Disable XML-RPC and Meta Tags',
            'enable_file_edit_disable' => 'Disable File Editing in Admin',
            'enable_sensitive_file_protection' => 'Protect Sensitive Files',
            'enable_turnstile_login' => 'Enable Turnstile for Login',
			'enable_turnstile_register' => 'Enable Turnstile for Registration',
            'enable_turnstile_lostpassword' => 'Enable Turnstile for Lost Password',
            'enable_turnstile_comments' => 'Enable Turnstile for Comments',
            'enable_file_integrity' => 'Enable File Integrity Monitoring',
            'enable_email_notifications' => 'Enable Email Notifications',
            'enable_upload_restrictions' => 'Enable Upload File Type Restrictions',
            'notification_email' => 'Notification Email Address',
            'allowed_upload_types' => 'Allowed Upload File Types',
            'turnstile_site_key' => 'Cloudflare Turnstile Site Key',
            'turnstile_secret_key' => 'Cloudflare Turnstile Secret Key',
            'custom_headers' => 'Custom Security Headers (one per line)'
        );

        foreach ($fields as $field => $label) {
            add_settings_field(
                $field,
                $label,
                array($this, 'render_field'),
                'wps-security',
                'wps_security_main',
                array('field' => $field)
            );
        }
    }

    public function section_callback() {
        echo '<p>Configure security settings for your WordPress site. Enable or disable features and set Cloudflare Turnstile keys as needed.</p>';
    }

    public function render_field($args) {
        $options = get_option('wps_security_options', array());
        $field = $args['field'];

        if ($field === 'custom_headers') {
            $value = isset($options[$field]) && is_array($options[$field]) ? implode("\n", $options[$field]) : '';
            echo '<textarea name="wps_security_options[' . $field . ']" rows="6" class="large-text">' . esc_textarea($value) . '</textarea>';
            echo '<p class="description">Enter one header per line (e.g., X-Frame-Options: SAMEORIGIN)</p>';
        } elseif ($field === 'turnstile_site_key') {
            $value = isset($options[$field]) ? $options[$field] : '';
            echo '<input type="text" name="wps_security_options[' . $field . ']" value="' . esc_attr($value) . '" class="regular-text">';
            echo '<p class="description">Your Cloudflare Turnstile site key (public key)</p>';
        } elseif ($field === 'turnstile_secret_key') {
            $value = isset($options[$field]) ? $options[$field] : '';
            $masked_value = !empty($value) ? str_repeat('*', 20) : '';
            echo '<input type="password" name="wps_security_options[' . $field . ']" value="' . esc_attr($value) . '" class="regular-text" placeholder="' . esc_attr($masked_value) . '">';
            echo '<p class="description">Your Cloudflare Turnstile secret key (private key) - will be hidden for security</p>';
        } elseif ($field === 'notification_email') {
            $value = isset($options[$field]) ? $options[$field] : get_option('admin_email');
            echo '<input type="email" name="wps_security_options[' . $field . ']" value="' . esc_attr($value) . '" class="regular-text">';
            echo '<p class="description">Email address for security notifications (defaults to admin email)</p>';
        } elseif ($field === 'allowed_upload_types') {
            $value = isset($options[$field]) && is_array($options[$field]) ? implode(', ', $options[$field]) : 'jpg, jpeg, png, gif, pdf, doc, docx';
            echo '<input type="text" name="wps_security_options[' . $field . ']" value="' . esc_attr($value) . '" class="large-text">';
            echo '<p class="description">Comma-separated list of allowed file extensions (e.g., jpg, png, pdf)</p>';
        } else {
            $checked = isset($options[$field]) && $options[$field] ? 'checked' : '';
            echo '<input type="checkbox" name="wps_security_options[' . $field . ']" value="1" ' . $checked . '>';
        }
    }

    public function sanitize_options($input) {
        $sanitized = array();
        $fields = array(
    'enable_svg_upload', 'enable_rest_api_restrictions', 'enable_security_headers',
    'enable_query_blocking', 'enable_comment_filtering', 'enable_author_protection',
    'enable_xmlrpc_disable', 'enable_file_edit_disable', 'enable_sensitive_file_protection',
    'enable_turnstile_login', 'enable_turnstile_lostpassword', 'enable_turnstile_register', 'enable_turnstile_comments',
    'enable_file_integrity', 'enable_email_notifications', 'enable_upload_restrictions',
    'enable_health_check'
);

        foreach ($fields as $field) {
            $sanitized[$field] = isset($input[$field]) && $input[$field] == '1';
        }

        $sanitized['turnstile_site_key'] = isset($input['turnstile_site_key']) ? sanitize_text_field($input['turnstile_site_key']) : '';
        $sanitized['turnstile_secret_key'] = isset($input['turnstile_secret_key']) ? sanitize_text_field($input['turnstile_secret_key']) : '';
        $sanitized['notification_email'] = isset($input['notification_email']) ? sanitize_email($input['notification_email']) : '';

        if (isset($input['allowed_upload_types'])) {
            $types = array_filter(array_map('trim', explode(',', strtolower($input['allowed_upload_types']))));
            $sanitized['allowed_upload_types'] = array_map('sanitize_text_field', $types);
        } else {
            $sanitized['allowed_upload_types'] = array('jpg', 'jpeg', 'png', 'gif', 'pdf');
        }

        if (isset($input['custom_headers'])) {
            $headers = array_filter(array_map('trim', explode("\n", $input['custom_headers'])));
            $sanitized['custom_headers'] = array_map('sanitize_text_field', $headers);
        } else {
            $sanitized['custom_headers'] = array();
        }

        return $sanitized;
    }

    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1>Enhanced WordPress Security Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('wps_security_group');
                do_settings_sections('wps-security');
                submit_button();
                ?>
            </form>
            
            <div class="notice notice-info">
                <p><strong>Security Tips:</strong></p>
                <ul>
                    <li>Keep your Turnstile secret keys secure and never share them publicly</li>
                    <li>Test security features on a staging site before enabling on production</li>
                    <li>Regularly update your security headers based on your site's needs</li>
                    <li>Monitor your error logs for blocked malicious requests</li>
                </ul>
            </div>
        </div>
        <?php
    }
}
?>
