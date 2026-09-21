<?php
/**
 * Portada del menú Dox Plugins: los plugins Dox instalados en este sitio.
 *
 * Solo lista lo que está puesto. Nada de escaparate de otros productos: quien
 * entra aquí viene a tocar sus ajustes.
 *
 * @var Dox_Core $this
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$installed = $this->get_plugins();
$logo      = $this->logo_svg();
?>
<div class="wrap dox-core-wrap">

	<div class="dox-core-head">
		<?php if ( $logo ) : ?>
			<span class="dox-core-logo"><?php echo $logo; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG propio del plugin ?></span>
		<?php else : ?>
			<span class="dox-core-logo-text">Dox Plugins</span>
		<?php endif; ?>
	</div>

	<?php
	// WordPress mueve los avisos de otros plugins al primer título de la página,
	// y sin esto acaban metidos dentro de la primera tarjeta. El h1 solo lo leen
	// los lectores de pantalla; el hr marca dónde van los avisos.
	?>
	<h1 class="screen-reader-text"><?php esc_html_e( 'Dox Plugins', 'dox-core' ); ?></h1>
	<hr class="wp-header-end">

	<p class="dox-core-lead"><?php esc_html_e( 'Every Dox Studio plugin on this site, in one place.', 'dox-core' ); ?></p>

	<div class="dox-core-grid">
		<?php foreach ( $installed as $slug => $plugin ) :
			$link = ! empty( $plugin['page'] )
				? admin_url( 'admin.php?page=' . $plugin['page']['menu_slug'] )
				: ( $plugin['settings_url'] ? admin_url( $plugin['settings_url'] ) : '' );
			?>
			<div class="dox-core-card">
				<div class="dox-core-card-head">
					<h2><?php echo esc_html( $plugin['name'] ); ?></h2>
					<?php if ( $plugin['version'] ) : ?>
						<span class="dox-core-version"><?php echo esc_html( $plugin['version'] ); ?></span>
					<?php endif; ?>
				</div>
				<?php if ( $plugin['summary'] ) : ?>
					<p><?php echo esc_html( $plugin['summary'] ); ?></p>
				<?php endif; ?>
				<?php if ( $link ) : ?>
					<a class="button button-primary" href="<?php echo esc_url( $link ); ?>"><?php esc_html_e( 'Settings', 'dox-core' ); ?></a>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
	</div>
</div>

<style>
.dox-core-wrap { --dox-accent: #ff8d27; --dox-accent-deep: #ea780f; --dox-ink: #141313; max-width: 1120px; }
.dox-core-head { display: flex; align-items: center; margin: 22px 0 6px; }
.dox-core-logo { display: block; line-height: 0; }
.dox-core-logo svg { height: 30px; width: auto; display: block; }
.dox-core-logo-text { font-size: 22px; font-weight: 700; color: var(--dox-ink); }
.dox-core-wrap .wp-header-end { display: none; }
.dox-core-lead { color: #646970; margin: 0 0 26px; font-size: 14px; }
.dox-core-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(290px, 1fr)); gap: 16px; }
.dox-core-card { background: #fff; border: 1px solid #e2e4e7; border-radius: 10px; padding: 20px; display: flex; flex-direction: column; gap: 10px; }
.dox-core-card-head { display: flex; align-items: center; gap: 10px; }
.dox-core-card h2 { font-size: 15px; font-weight: 600; margin: 0; color: var(--dox-ink); }
.dox-core-card p { margin: 0; color: #646970; font-size: 13px; line-height: 1.6; flex: 1; }
.dox-core-card .button { align-self: flex-start; }
.dox-core-card .button-primary { background: var(--dox-accent); border-color: var(--dox-accent); color: #fff; }
.dox-core-card .button-primary:hover,
.dox-core-card .button-primary:focus { background: var(--dox-accent-deep); border-color: var(--dox-accent-deep); color: #fff; box-shadow: none; }
.dox-core-version { font-size: 11px; font-weight: 600; color: var(--dox-accent); background: rgba(255,141,39,.12); border-radius: 20px; padding: 2px 9px; }
</style>
