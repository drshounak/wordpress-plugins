<?php
/**
 * Plugin Name: Simple SMTP Mailer by TechWeirdo
 * Plugin URI: https://github.com/drshounak/wordpress-plugins/simple-smtp-mailer
 * Description: TechWeirdo’s professional SMTP plugin with custom SMTP server settings for any provider.
 * Version: 1.0.0
 * Author: Dr. Shounak Pal
 * Author URI: https://x.com/drshounakpal
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Prevent direct access
defined('ABSPATH') or exit;

// Define plugin constants
define('ASM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ASM_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('ASM_VERSION', '1.0.0');

class AdvancedSMTPMailer {
    private $options;

    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'admin_init'));
        add_action('phpmailer_init', array($this, 'configure_phpmailer'));
        add_action('wp_ajax_asm_test_email', array($this, 'test_email'));
        register_activation_hook(__FILE__, array($this, 'activate'));
    }

    public function init() {
        $this->options = get_option('asm_settings', array());
    }

    public function activate() {
        $default_options = array(
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
        add_option('asm_settings', $default_options);
    }

    public function add_admin_menu() {
        add_options_page(
            'Advanced SMTP Settings',
            'SMTP Mailer',
            'manage_options',
            'advanced-smtp-mailer',
            array($this, 'admin_page')
        );
    }

    public function admin_init() {
        register_setting('asm_settings_group', 'asm_settings', array($this, 'sanitize_settings'));

        add_settings_section(
            'asm_general_section',
            'General Settings',
            array($this, 'general_section_callback'),
            'advanced-smtp-mailer'
        );

        add_settings_field(
            'from_email',
            'From Email',
            array($this, 'from_email_callback'),
            'advanced-smtp-mailer',
            'asm_general_section'
        );

        add_settings_field(
            'from_name',
            'From Name',
            array($this, 'from_name_callback'),
            'advanced-smtp-mailer',
            'asm_general_section'
        );

        add_settings_section(
            'asm_smtp_section',
            'SMTP Settings',
            array($this, 'smtp_section_callback'),
            'advanced-smtp-mailer'
        );

        $this->add_smtp_fields();
    }

    private function add_smtp_fields() {
        $smtp_fields = array(
            'smtp_host'       => 'SMTP Host',
            'smtp_port'       => 'SMTP Port',
            'smtp_encryption' => 'Encryption',
            'smtp_username'   => 'Username',
            'smtp_password'   => 'Password',
            'debug_mode'      => 'Enable Debug Mode'
        );

        foreach ($smtp_fields as $field => $label) {
            add_settings_field(
                $field,
                $label,
                array($this, $field . '_callback'),
                'advanced-smtp-mailer',
                'asm_smtp_section'
            );
        }
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

    public function general_section_callback() {
        echo '<p>Configure your email settings below.</p>';
    }

    public function smtp_section_callback() {
        echo '<p>Configure SMTP server settings.</p>';
    }

    public function from_email_callback() {
        $value = esc_attr($this->options['from_email'] ?? get_option('admin_email'));
        echo '<input type="email" id="from_email" name="asm_settings[from_email]" value="' . $value . '" class="regular-text" required />';
    }

    public function from_name_callback() {
        $value = esc_attr($this->options['from_name'] ?? get_option('blogname'));
        echo '<input type="text" id="from_name" name="asm_settings[from_name]" value="' . $value . '" class="regular-text" />';
    }

    public function smtp_host_callback() {
        $value = esc_attr($this->options['smtp_host'] ?? '');
        echo '<input type="text" id="smtp_host" name="asm_settings[smtp_host]" value="' . $value . '" class="regular-text" required />';
    }

    public function smtp_port_callback() {
        $value = esc_attr($this->options['smtp_port'] ?? '587');
        echo '<input type="number" id="smtp_port" name="asm_settings[smtp_port]" value="' . $value . '" class="small-text" required />';
    }

    public function smtp_encryption_callback() {
        $value = esc_attr($this->options['smtp_encryption'] ?? 'tls');
        echo '<select id="smtp_encryption" name="asm_settings[smtp_encryption]">';
        echo '<option value="none"' . selected($value, 'none', false) . '>None</option>';
        echo '<option value="ssl"' . selected($value, 'ssl', false) . '>SSL</option>';
        echo '<option value="tls"' . selected($value, 'tls', false) . '>TLS</option>';
        echo '</select>';
    }

    public function smtp_username_callback() {
        $value = esc_attr($this->options['smtp_username'] ?? '');
        echo '<input type="text" id="smtp_username" name="asm_settings[smtp_username]" value="' . $value . '" class="regular-text" />';
    }

    public function smtp_password_callback() {
        $value = esc_attr($this->options['smtp_password'] ?? '');
        echo '<input type="password" id="smtp_password" name="asm_settings[smtp_password]" value="' . $value . '" class="regular-text" />';
    }

    public function debug_mode_callback() {
        $checked = !empty($this->options['debug_mode']) ? 'checked' : '';
        echo '<label><input type="checkbox" id="debug_mode" name="asm_settings[debug_mode]" ' . $checked . ' /> Enable debug output</label>';
    }

    public function configure_phpmailer($phpmailer) {
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
        check_ajax_referer('asm_test_email', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        $to      = sanitize_email($_POST['test_email']);
        $subject = 'Test Email from Advanced SMTP Mailer';
        $message = 'This is a test email to verify your SMTP configuration.';
        $result  = wp_mail($to, $subject, $message);
        if ($result) {
            wp_send_json_success('Test email sent successfully!');
        } else {
            wp_send_json_error('Failed to send test email. Please check your settings.');
        }
    }

    public function admin_page() {
        ?>
        <div class="wrap">
            <h1>Advanced SMTP Mailer Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('asm_settings_group');
                do_settings_sections('advanced-smtp-mailer');
                ?>
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
            data.append('action', 'asm_test_email');
            data.append('test_email', email);
            data.append('nonce', '<?php echo wp_create_nonce('asm_test_email'); ?>');
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

new AdvancedSMTPMailer();

// Email logger
add_action('wp_mail', function($args) {
    $opts = get_option('asm_settings');
    if (!empty($opts['debug_mode'])) {
        error_log(sprintf("SMTP Log: To=%s Subject=%s", is_array($args['to']) ? implode(',', $args['to']) : $args['to'], $args['subject']));
    }
});
