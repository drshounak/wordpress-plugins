=== WP Starter Addon by TechWeirdo ===
Contributors: drshounak
Tags: scripts, header, footer, smtp, email, webp, image optimizer, image compression, 2fa, two-factor authentication, otp, security, toolkit
Requires at least: 5.8
Tested up to: 6.5
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The ultimate toolkit for WordPress: Custom Scripts, SMTP, Image Optimizer, and Email 2FA in one powerful, modular plugin.

== Description ==

**WP Starter Addon by TechWeirdo** is the all-in-one solution for essential WordPress enhancements. It combines four powerful plugins into a single, easy-to-manage package, allowing you to enable only the features you need. Stop cluttering your site with multiple plugins and streamline your workflow with this comprehensive toolkit.

This plugin is fully backward-compatible with our previous individual plugins. If you are an existing user, you can switch seamlessly and all your saved settings will be automatically migrated.

**Included Modules:**

*   **Custom Scripts Manager**
    *   Easily add global scripts (like Google Analytics, Facebook Pixel) to your site's `<head>` or before the closing `</body>` tag.
    *   Insert page-specific or post-specific scripts directly from the post editor.
    *   A simple and safe way to manage tracking codes, custom CSS, and other scripts without editing theme files.

*   **SMTP Mailer**
    *   Ensure reliable email delivery by configuring WordPress to send emails through any SMTP provider (e.g., Gmail, SendGrid, Mailgun).
    *   Avoids emails going to spam by authenticating them properly.
    *   Includes a test email feature to verify your configuration is working correctly.

*   **Image Optimizer**
    *   Automatically compress and convert newly uploaded images to modern, fast-loading formats like WebP or AVIF.
    *   Set maximum dimensions to resize large images, saving server space and bandwidth.
    *   Includes a bulk optimization tool to convert your entire existing media library.
    *   Optionally keep or delete original image files after conversion.

*   **Email 2FA (Two-Factor Authentication)**
    *   Enhance your site's security by adding a second layer of protection to the login process.
    *   After entering their password, users receive a one-time password (OTP) via email to complete the login.
    *   Users can optionally disable 2FA for their own account from their profile page.
    *   Admin-configurable OTP expiry time and rate-limiting to prevent abuse.

**Why Choose WP Starter Addon?**

*   **Modular:** Only enable the modules you need. Disabled modules don't load any code, keeping your site fast.
*   **Lightweight:** Replaces four separate plugins with one, reducing overhead and potential conflicts.
*   **Easy to Manage:** A single, unified dashboard to control all features.
*   **Backward Compatible:** A seamless upgrade path for users of our old plugins.

== Installation ==

**New Installation:**

1.  Upload the `wp-starter-addon` folder to the `/wp-content/plugins/` directory.
2.  Activate the plugin through the 'Plugins' menu in WordPress.
3.  Navigate to the new "WP Starter Addon" menu in your admin sidebar to configure the modules.

**For Users of Our Old Plugins (Custom Scripts Manager, Simple SMTP Mailer, etc.):**

It is safe to switch. Your settings will be preserved.

1.  **Important:** Backup your website before proceeding.
2.  Install and activate the "WP Starter Addon by TechWeirdo" plugin.
3.  The new plugin will automatically detect and migrate your old settings upon activation.
4.  Go to the "WP Starter Addon" admin pages and verify that your scripts, SMTP settings, etc., are all present.
5.  Once you have confirmed everything is working correctly, you can safely **deactivate and delete** the old individual plugins:
    *   Custom Scripts Manager by TechWeirdo
    *   Simple SMTP Mailer
    *   Simple Image Optimiser by TechWeirdo
    *   Email 2FA by TechWeirdo

== Frequently Asked Questions ==

= What happens to my settings from the old plugins? =

They are automatically and safely migrated to the new plugin. When you activate WP Starter Addon, it checks for the old option keys in your database and copies their values to the new keys. You won't lose any data.

= Can I disable modules I don't need? =

Yes! This is a core feature. Go to `WP Starter Addon > Module Settings` and simply uncheck any module you don't want to use. This will prevent its code from loading and keep your site as lean as possible.

= Will the Image Optimizer convert my existing images? =

Yes. While it automatically converts new uploads, you can convert your existing media library by going to `WP Starter Addon > Image Optimizer` and using the "Convert All Images" bulk tool.

= Is this plugin free? =

Yes, this plugin is free and open-source, licensed under the GPLv2 (or later).

== Screenshots ==

1.  The main WP Starter Addon dashboard showing the status of all modules.
2.  The Module Settings page where you can enable or disable each feature.
3.  The Custom Scripts Manager settings page for global scripts.
4.  The SMTP Mailer configuration page.
5.  The Image Optimizer settings page with bulk conversion tool.
6.  The Email 2FA settings page.

== Changelog ==

= 2.0.0 =
*   Initial release of WP Starter Addon.
*   Combined four plugins into a single, modular toolkit: Custom Scripts Manager, SMTP Mailer, Image Optimizer, and Email 2FA.
*   Added a central dashboard and a settings page to enable/disable individual modules.
*   Implemented a seamless, automatic migration routine to import settings from the previous standalone plugins.
*   Unified branding and code structure under the "WP Starter Addon" name.
