=== Forge Connector ===
Contributors: justingluska
Tags: content management, publishing, headless, api, forge, gluska
Requires at least: 5.6
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect your WordPress site to Forge by GLUSKA for seamless content publishing and management.

== Description ==

Forge Connector allows you to connect your WordPress site to your Forge dashboard for streamlined content publishing and synchronization.

**Features:**

* **Easy Setup** - Just paste your connection key and you're ready to go
* **Secure Connection** - HMAC-signed requests ensure secure communication
* **Full Publishing Support** - Create, update, and delete posts directly from Forge
* **Media Management** - Upload images and manage your media library
* **SEO Integration** - Works with Yoast SEO, RankMath, and other SEO plugins
* **Scheduled Posts** - Schedule posts to publish at specific times
* **Categories & Tags** - Full taxonomy support for organizing content

== Installation ==

1. Upload the `forge-connector` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Settings > Forge Connector
4. Enter your connection key from your Forge dashboard
5. Click "Connect to Forge"

== Frequently Asked Questions ==

= Where do I get a connection key? =

Log in to your Forge dashboard, go to WordPress connections, and create a new connection. Your connection key will be generated automatically.

= Is my data secure? =

Yes. All requests between Forge and your WordPress site are signed using HMAC-SHA256 cryptographic signatures. Your connection key is never transmitted after the initial setup.

= Does this work with security plugins? =

Yes! Unlike the standard WordPress REST API which can be blocked by security plugins, Forge Connector uses its own secure endpoints that bypass common security restrictions.

= What WordPress features are supported? =

Forge Connector supports posts, pages, custom post types, categories, tags, featured images, authors, scheduling, and SEO meta fields (Yoast, RankMath, etc.).

== Changelog ==

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.0.0 =
Initial release of Forge Connector.
