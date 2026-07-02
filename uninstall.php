<?php
/**
 * Fired when the plugin is uninstalled.
 * Only cleans up if the user has opted in via settings.
 *
 * Every direct DB call here removes our own custom tables / option rows /
 * meta rows. Object caching is moot — this runs once at uninstall.
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

if ( ! defined('WP_UNINSTALL_PLUGIN') ) {
    exit;
}

$options = get_option('dxf_options', []);
if ( empty($options['delete_data_on_uninstall']) ) {
    return;
}

global $wpdb;

// Drop custom tables (incl. approvals, which holds reviewer name/email/IP).
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}dxf_comments");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}dxf_review_tokens");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}dxf_approvals");

// Remove options — includes plaintext secrets (AI API key, webhook signing secret).
$option_keys = [
    'dxf_options',
    'dxf_db_version',
    'dxf_license',
    'dxf_instance_id',
    'dxf_ai',
    'dxf_integrations',
    'dxf_digest',
    'dxf_rewrite_flushed_v3',
];
foreach ( $option_keys as $key ) {
    delete_option($key);
}

// Remove our postmeta (per-token approval flags + per-post round counter).
$wpdb->query(
    "DELETE FROM {$wpdb->postmeta}
      WHERE meta_key LIKE '\_dxf\_complete\_%'
         OR meta_key = '_dxf_round'"
);

// Delete uploaded screenshots/attachments in /uploads/dxf/.
$uploads = wp_upload_dir();
if ( empty($uploads['error']) ) {
    $dir = trailingslashit($uploads['basedir']) . 'dxf';
    if ( is_dir($dir) ) {
        $files = glob($dir . '/*');
        if ( is_array($files) ) {
            foreach ( $files as $file ) {
                if ( is_file($file) ) {
                    wp_delete_file($file);
                }
            }
        }
        // Remove the now-empty directory via WP_Filesystem.
        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
        global $wp_filesystem;
        if ( $wp_filesystem ) {
            $wp_filesystem->rmdir($dir);
        }
    }
}

// Remove scheduled crons.
wp_clear_scheduled_hook('dxf_license_check');
wp_clear_scheduled_hook('dxf_digest_cron');
