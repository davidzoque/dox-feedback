<?php
/**
 * Soft-error landing — failed activation, expired/closed review, etc.
 *
 * @var array  $review
 * @var string $error
 */
if ( ! defined('ABSPATH') ) exit;
// Template-scoped vars; not globals.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$project = $review['name'] !== '' ? $review['name'] : __('this review', 'dox-feedback');

// Brand mark: the site's own logo when available (mirrors the mailer and the
// reviewer landing), else the orange-gradient chat chip.
$logo_url = '';
$logo_id  = (int) get_theme_mod('custom_logo');
if ( $logo_id ) {
    $src = wp_get_attachment_image_src($logo_id, 'medium');
    if ( is_array($src) && ! empty($src[0]) ) {
        $logo_url = (string) $src[0];
    }
}
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html__('Review unavailable', 'dox-feedback'); ?></title>
<?php wp_head(); ?>
</head>
<body>
<div class="rv-shell">
    <div class="rv-card">
        <?php if ( $logo_url !== '' ) : ?>
            <span class="rv-brand rv-brand--logo"><img src="<?php echo esc_url($logo_url); ?>" alt="<?php echo esc_attr($project); ?>"></span>
        <?php else : ?>
            <span class="rv-brand" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
            </span>
        <?php endif; ?>
        <h1><?php echo esc_html($project); ?></h1>
        <p class="rv-err"><?php echo esc_html($error); ?></p>
        <p class="rv-hint"><?php esc_html_e('If you think this is a mistake, contact whoever sent you the link.', 'dox-feedback'); ?></p>
    </div>
</div>
<?php wp_footer(); ?>
</body>
</html>
