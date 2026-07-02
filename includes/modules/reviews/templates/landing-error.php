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
        <h1><?php echo esc_html($project); ?></h1>
        <p class="rv-err"><?php echo esc_html($error); ?></p>
        <p style="font-size: 13px; margin-top: 24px;"><?php esc_html_e('If you think this is a mistake, contact whoever sent you the link.', 'dox-feedback'); ?></p>
    </div>
</div>
<?php wp_footer(); ?>
</body>
</html>
