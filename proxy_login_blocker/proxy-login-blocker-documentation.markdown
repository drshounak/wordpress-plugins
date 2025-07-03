# Proxy Login Blocker Documentation

## Overview

Proxy Login Blocker is a WordPress plugin designed to enhance the security of your WordPress login page by restricting access from proxy, VPN, or hosting IP addresses. It uses customizable API endpoints to check IP addresses and allows you to define specific rules for blocking or allowing access based on API response fields. The plugin includes features like IP whitelisting, caching for performance, rate limit handling, and a user-friendly admin interface.

## Installation

1. **Download the Plugin**:
   - Download the `proxy-login-blocker.php` file from the [GitHub repository](https://github.com/drshounak/wordpress-plugins/tree/main/proxy_login_blocker).
   - Alternatively, install it via the WordPress Plugin Directory (if available).

2. **Upload to WordPress**:
   - Navigate to your WordPress admin dashboard.
   - Go to **Plugins > Add New > Upload Plugin**.
   - Upload the `proxy-login-blocker.php` file or the zipped plugin folder.
   - Click **Install Now**.

3. **Activate the Plugin**:
   - After installation, click **Activate Plugin** to enable Proxy Login Blocker.

4. **Configure Settings**:
   - Go to **Settings > Proxy Blocker** in your WordPress admin menu to configure the plugin.

## Configuration

The plugin settings are located in **Settings > Proxy Blocker** in the WordPress admin dashboard. Below is a detailed explanation of each setting:

### General Settings

- **Enable Plugin**:
  - **Description**: Enable or disable the proxy detection feature.
  - **Default**: Enabled.
  - **How to Use**: Check the box to activate the plugin. Uncheck to disable it without deactivating the plugin.

- **API Endpoint**:
  - **Description**: Specify the API endpoint to check IP addresses.
  - **Default**: `http://ip-api.com/json/{IP}?fields=16908288` (Free, 45 requests/minute).
  - **How to Use**: Enter the API URL, replacing the IP address with `{IP}`. Examples:
    - `http://ip-api.com/json/{IP}?fields=16908288` (Free, 45/min limit).
    - `https://proxycheck.io/v2/{IP}?vpn=1` (Free, 1000/day, 10000/day with free API key).
    - Custom API: `https://api.example.com/geo/proxy?ip={IP}`.
  - **Note**: Ensure the API returns JSON data with fields you want to check.

- **API Key**:
  - **Description**: Enter an API key if required by your chosen API provider.
  - **Default**: Empty.
  - **How to Use**: Leave blank for APIs that don’t require a key (e.g., ip-api.com free version). For ProxyCheck.io, get a free API key from their website and enter it here.

- **Block Rules**:
  - **Description**: Define which API response fields and values should trigger blocking.
  - **Default**: 
    ```
    proxy: true, yes, 1, ok
    hosting: true, yes, 1, ok
    ```
  - **How to Use**: Enter one rule per line in the format `fieldname: value1, value2, value3`. Examples:
    - `proxy: true, yes, 1, ok` - Blocks if the `proxy` field is `true`, `yes`, `1`, or `ok`.
    - `hosting: true, yes, 1` - Blocks if the `hosting` field is `true`, `yes`, or `1`.
    - `country: CN, RU, IR` - Blocks if the `country` field is `CN`, `RU`, or `IR`.
    - `asn: AS13335, AS15169` - Blocks specific ASNs.
    - `threat: high, medium` - Blocks if the `threat` field is `high` or `medium`.
    - `datacenter: !false` - Blocks all values except `false` (use `!` for negation).
    - `custom_field: elon musk, whatever` - Blocks custom field values.
  - **Note**: Use commas to separate multiple values. Use `!value` to block all values except the specified one.

- **Custom Block Fields**:
  - **Description**: Additional block rules for backward compatibility with older versions. Use this only if you have specific fields not covered by Block Rules.
  - **Default**: Empty.
  - **How to Use**: Same format as Block Rules (`fieldname: value1, value2, value3`). Examples:
    - `country: CN, RU, IR`
    - `asn: AS13335, AS15169`
    - `threat: high, medium`
  - **Note**: This is maintained for compatibility. Prefer using Block Rules for new configurations.

- **Whitelisted IPs**:
  - **Description**: List IP addresses that are always allowed to access the login page.
  - **Default**: Empty.
  - **How to Use**: Enter one IP address per line (e.g., `192.168.1.1`). These IPs bypass all checks.

- **Allow Access on API Failure**:
  - **Description**: Decide whether to allow login attempts when the API is unavailable or rate-limited.
  - **Default**: Disabled.
  - **How to Use**: Check to allow logins (except for non-whitelisted IPs) when the API fails. Uncheck to block logins in such cases.

- **Dashboard Widget**:
  - **Description**: Display a status widget on the WordPress dashboard showing blocked/allowed IPs and API status.
  - **Default**: Disabled.
  - **How to Use**: Check to enable the widget. Only visible to users with `manage_options` capability.

- **Show Cached IPs**:
  - **Description**: Display a list of cached IPs and their status in the settings page.
  - **Default**: Enabled.
  - **How to Use**: Check to show the cached IPs table below the settings form. Uncheck to hide it.

- **Rate Limit Headers**:
  - **Description**: Specify the HTTP headers used to track API rate limits.
  - **Default**: `x-rl,x-ttl`.
  - **How to Use**: Enter comma-separated header names (e.g., `x-ratelimit-remaining,x-ratelimit-reset` for ProxyCheck.io). The plugin uses these to monitor API usage.

### How It Works

1. **Login Page Protection**:
   - When a user visits `wp-login.php`, they’re redirected to a security check page.
   - The plugin checks the user’s IP against:
     - Whitelisted IPs (allowed immediately).
     - Cached results (4-hour expiry).
     - API response based on your Block Rules.

2. **API Integration**:
   - The plugin queries the configured API (e.g., ip-api.com or proxycheck.io) to check the IP.
   - Results are cached for 4 hours to reduce API calls.
   - Rate limits are respected (e.g., 45/min for ip-api.com, 1000/day for proxycheck.io).

3. **Blocking Logic**:
   - IPs are blocked if any Block Rule or Custom Block Field matches the API response.
   - Negation (`!value`) allows blocking all values except the specified one.
   - Whitelisted IPs and cached results bypass API checks.

4. **Rate Limit Handling**:
   - The plugin monitors API rate limits using specified headers.
   - If rate limits are exceeded, it can either allow or block logins based on the “Allow Access on API Failure” setting.
   - Automatic recovery checks run every 10 minutes when rate-limited.

5. **Security Features**:
   - Uses session-based verification and temporary tokens to prevent unauthorized access.
   - Only affects `wp-login.php`, preserving access to `wp-admin` for cron, AJAX, etc.

## Monitoring

### Dashboard Widget
- If enabled, a widget appears on the WordPress dashboard showing:
  - Total cached IPs.
  - Number of allowed and blocked IPs.
  - API rate limit status (Good, Low, or Rate Limited).
  - Link to the settings page.

### Cached IPs Table
- If “Show Cached IPs” is enabled, a table displays:
  - IP Address
  - Status (Allowed/Blocked)
  - Reason (e.g., clean, proxy/hosting detected)
  - Cached Time
  - Expires In
  - User Agent
- The table auto-refreshes every 30 seconds and can be manually refreshed.

### Rate Limit Status
- Displays the remaining API requests, last check time, and status.
- Auto-refreshes every 30 seconds.
- Indicates if the API is rate-limited and when it will reset.

## Example Configurations

### Basic Proxy Detection
- **API Endpoint**: `http://ip-api.com/json/{IP}?fields=16908288`
- **Block Rules**:
  ```
  proxy: true, yes, 1
  hosting: true, yes, 1
  ```
- **Result**: Blocks IPs where `proxy` or `hosting` is `true`, `yes`, or `1`.

### Country-Based Blocking
- **API Endpoint**: `https://proxycheck.io/v2/{IP}?vpn=1`
- **Block Rules**:
  ```
  country: CN, RU, IR, KP
  ```
- **Result**: Blocks IPs from China, Russia, Iran, or North Korea.

### Negation (Allow Specific Values)
- **API Endpoint**: `https://proxycheck.io/v2/{IP}?vpn=1`
- **Block Rules**:
  ```
  datacenter: !false
  country: !US, !CA, !UK
  ```
- **Result**: Blocks all IPs except those where `datacenter` is `false` or `country` is `US`, `CA`, or `UK`.

### Custom Field Blocking
- **API Endpoint**: Custom API returning `threat_level` and `provider`.
- **Block Rules**:
  ```
  threat_level: high, critical
  provider: suspicious isp inc
  ```
- **Result**: Blocks IPs with high/critical threat levels or specific providers.

## Troubleshooting

- **API Fails or Rate Limited**:
  - Check the “Allow Access on API Failure” setting to control behavior.
  - Verify your API endpoint and key are correct.
  - Monitor the rate limit status in the settings page.

- **Legitimate Users Blocked**:
  - Add their IPs to the Whitelist IPs field.
  - Adjust Block Rules to be less restrictive (e.g., use negation for specific countries).

- **Cached IPs Issue**:
  - Cached results expire after 4 hours.
  - To clear cache manually, deactivate and reactivate the plugin (this clears transients).

- **Plugin Not Working**:
  - Ensure the plugin is enabled.
  - Check your API endpoint returns valid JSON with the expected fields.
  - Verify your Block Rules syntax (fieldname: value1, value2).

## FAQ

**Q: Which APIs are supported?**  
A: Any API returning JSON data with fields you can specify in Block Rules. Recommended:
- ip-api.com (free, 45/min limit).
- proxycheck.io (free, 1000/day, 10000/day with free API key).

**Q: Can I use multiple APIs?**  
A: No, the plugin supports one API endpoint at a time. Choose the one that best fits your needs.

**Q: What happens when the API is down?**  
A: If “Allow Access on API Failure” is enabled, non-whitelisted IPs are allowed. Otherwise, they’re blocked until the API is available.

**Q: How do I get a ProxyCheck.io API key?**  
A: Sign up for a free account at [proxycheck.io](https://proxycheck.io/) to get a key that increases the daily limit to 10000 requests.

**Q: Can I block specific countries or ASNs?**  
A: Yes, use the Block Rules field (e.g., `country: CN, RU` or `asn: AS13335`).

## Support

- **GitHub**: [https://github.com/drshounak/wordpress-plugins](https://github.com/drshounak/wordpress-plugins)
- **Author**: Dr. Shounak Pal ([@drshounakpal](https://twitter.com/drshounakpal))
- **License**: GPL v2 or later

For issues or feature requests, create an issue on the GitHub repository or contact the author via Twitter.