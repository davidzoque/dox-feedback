<?php
/**
 * Plugin Name: Dox Feedback
 * Plugin URI:  https://doxstudio.com
 * Description: Client feedback, visual review and approvals for WordPress — pinned comments, threaded replies, client sign-off, and shareable review links for a single page, several pages or a whole site, with email-invited reviewers and roles. Native to Bricks and Elementor; works on any WordPress site.
 * Version:     1.0.3
 * Author:      Dox Studio
 * Author URI:  https://doxstudio.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: dox-feedback
 * Domain Path: /languages
 * Requires at least: 6.4
 * Requires PHP: 8.1
 *
 * Dox Feedback is a fork of "Reviso – Client Feedback & Approvals" (GPL-2.0-or-later).
 * Multi-page / whole-site reviews and email-invited reviewers with roles are
 * original Dox Studio implementations built on the upstream extension hooks.
 * See NOTICE.md for attribution. This program is distributed under GPL-2.0-or-later.
 */

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
    exit;
}

define('DXF_VERSION',  '1.0.3');
define('DXF_DB_VERSION', '0.9.0');
define('DXF_FILE',     __FILE__);
define('DXF_DIR',      plugin_dir_path(__FILE__));
define('DXF_URL',      plugin_dir_url(__FILE__));
define('DXF_BASENAME', plugin_basename(__FILE__));

require_once DXF_DIR . 'includes/class-autoloader.php';
require_once DXF_DIR . 'includes/class-plugin.php';
DXF_Autoloader::register();

register_activation_hook(__FILE__,   ['DXF_Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['DXF_Plugin', 'deactivate']);

add_action('plugins_loaded', ['DXF_Plugin', 'instance']);
