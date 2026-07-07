<?php
/**
 * Plugin Name: Dox Feedback
 * Plugin URI:  https://doxstudio.com
 * Description: Client feedback, visual review and approvals for WordPress — pinned comments, threaded replies, client sign-off, and shareable review links for a single page, several pages or a whole site, with email-invited reviewers and roles. Native to Bricks and Elementor; works on any WordPress site.
 * Version:     1.1.11
 * Author:      Dox Studio
 * Author URI:  https://doxstudio.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: dox-feedback
 * Domain Path: /languages
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Update URI:  https://github.com/davidzoque/dox-feedback
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

define('DXF_VERSION',  '1.1.11');
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

// Load the bundled Colombian-Spanish translation for ANY Spanish locale
// (es_CO, es_ES, es_MX, es_AR…). WordPress does not fall back es_ES → es_CO on
// its own, so redirect every Spanish variant to our single es_CO .mo — the
// translation then "just works" regardless of each site's exact Spanish setting.
add_filter('load_textdomain_mofile', function ($mofile, $domain) {
    if ($domain !== 'dox-feedback') {
        return $mofile;
    }
    $base = basename((string) $mofile);
    if ($base === 'dox-feedback-es.mo' || strncmp($base, 'dox-feedback-es_', 16) === 0) {
        $es_co = DXF_DIR . 'languages/dox-feedback-es_CO.mo';
        if (is_readable($es_co)) {
            return $es_co;
        }
    }
    return $mofile;
}, 10, 2);

// ─── Auto-actualizaciones desde GitHub (Plugin Update Checker) ────────────────
// El plugin se actualiza desde las releases del repo de GitHub, no desde
// WordPress.org. Para repos privados, define el token en wp-config.php:
//     define( 'DXF_GITHUB_TOKEN', 'github_pat_xxxxxxxx' );
// (fine-grained PAT con permiso de solo lectura de "Contents" sobre el repo)
$dxf_puc = DXF_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';
if ( file_exists( $dxf_puc ) ) {
    require_once $dxf_puc;

    $dxf_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/davidzoque/dox-feedback/',
        __FILE__,
        'dox-feedback'
    );
    $dxf_update_checker->setBranch('main');
    // Usa el ZIP limpio que el workflow de GitHub Actions adjunta a cada release
    $dxf_update_checker->getVcsApi()->enableReleaseAssets();
    if ( defined('DXF_GITHUB_TOKEN') && DXF_GITHUB_TOKEN ) {
        $dxf_update_checker->setAuthentication(DXF_GITHUB_TOKEN);
    }
}
