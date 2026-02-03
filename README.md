# Forge Connector for WordPress

Connect your WordPress site to [Forge](https://forge.gluska.co) for seamless AI-powered content publishing and management.

## What is Forge?

Forge is an AI content generation platform that helps businesses create, manage, and publish SEO-optimized content at scale. This plugin enables secure two-way communication between your WordPress site and Forge.

## Features

- **Easy Setup** - Paste your connection key and you're ready to go
- **Secure Connection** - HMAC-SHA256 signed requests ensure secure communication
- **Full Publishing Support** - Create, update, and delete posts directly from Forge
- **Media Management** - Upload images and manage your media library
- **SEO Integration** - Works with Yoast SEO, RankMath, and other SEO plugins
- **Scheduled Posts** - Schedule posts to publish at specific times
- **Categories & Tags** - Full taxonomy support for organizing content
- **Custom Post Types** - Supports any public custom post type
- **Author Assignment** - Assign posts to any WordPress user

## Why Use This Plugin?

Unlike the standard WordPress REST API which can be blocked by security plugins (Wordfence, Sucuri, etc.), Forge Connector uses its own secure endpoints that work alongside your existing security setup.

## Requirements

- WordPress 5.6 or higher
- PHP 7.4 or higher
- A [Forge](https://forge.gluska.co) account

## Installation

### Manual Installation

1. Download the latest release (`.zip` file)
2. Go to WordPress Admin → Plugins → Add New → Upload Plugin
3. Upload the zip file and click "Install Now"
4. Activate the plugin

### From Source

1. Clone this repository into your `/wp-content/plugins/` directory:
   ```bash
   cd wp-content/plugins
   git clone https://github.com/justingluska/forge-wordpress-connector.git forge-connector
   ```
2. Activate the plugin in WordPress Admin → Plugins

## Configuration

1. In your Forge dashboard, go to **Settings → Websites**
2. Click **Connect Production Site** (or Staging)
3. Choose the **Plugin** tab
4. Enter your WordPress site URL and click **Generate Connection Key**
5. Copy the connection key
6. In WordPress, go to **Settings → Forge Connector**
7. Paste your connection key
8. Click **Connect to Forge**

## Security

- All requests are signed using HMAC-SHA256 cryptographic signatures
- Timestamps prevent replay attacks (5-minute tolerance)
- Connection keys are never transmitted after initial setup
- Each site has a unique connection key

## Endpoints

The plugin registers REST API endpoints under `/wp-json/forge/v1/`:

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/connect` | POST | Initial connection handshake |
| `/status` | GET | Check connection status |
| `/disconnect` | POST | Disconnect from Forge |
| `/sync` | GET | Get all site data |
| `/posts` | GET/POST | List and create posts |
| `/posts/{id}` | GET/PUT/DELETE | Read, update, delete posts |
| `/media` | GET/POST | List and upload media |
| `/categories` | GET/POST | List and create categories |
| `/tags` | GET/POST | List and create tags |
| `/users` | GET | List users who can edit posts |
| `/post-types` | GET | List available post types |

All endpoints (except `/connect`) require valid HMAC authentication.

## Frequently Asked Questions

### Where do I get a connection key?

Log in to your [Forge dashboard](https://forge.gluska.co), go to Settings → Websites, and create a new connection using the Plugin method.

### Is my data secure?

Yes. All requests between Forge and your WordPress site are signed using HMAC-SHA256 cryptographic signatures. Your connection key is stored securely and never transmitted after the initial setup.

### Does this work with security plugins?

Yes! Unlike the standard WordPress REST API which can be blocked by security plugins, Forge Connector uses its own secure endpoints that bypass common security restrictions while maintaining strong authentication.

### What happens if I deactivate the plugin?

Forge will no longer be able to publish to your WordPress site, but all your existing content remains untouched. You can reactivate at any time.

## Changelog

### 1.0.0
- Initial release

## License

GPL v2 or later. See [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html) for details.

## Support

For support, please contact [support@gluska.co](mailto:support@gluska.co) or visit the [Forge documentation](https://forge.gluska.co/docs).

## Credits

Developed by [Justin Gluska](https://gluska.co) for [Forge by GLUSKA](https://forge.gluska.co).
