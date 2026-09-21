<?php
/**
 * Dox Core: cargador.
 *
 * Cada plugin de Dox Studio lleva su propia copia de esta carpeta, igual que
 * Crocoblock hace con su framework: así ningún plugin depende de otro y todos
 * funcionan sueltos. Lo que hay que evitar es que cinco plugins carguen cinco
 * copias distintas del mismo código.
 *
 * La regla es "gana la más nueva": cada copia se apunta aquí con su versión y,
 * cuando ya están todas apuntadas, se carga solo la del número más alto. Como
 * los plugins se cargan antes de `after_setup_theme`, a esas alturas ya están
 * todos en la lista.
 *
 * Uso desde el plugin (antes de after_setup_theme, o sea al cargarse):
 *
 *     require_once __DIR__ . '/dox-core/loader.php';
 *     Dox_Core_Loader::register(
 *         require __DIR__ . '/dox-core/version.php',
 *         __DIR__ . '/dox-core/dox-core.php'
 *     );
 *
 * La versión se lee de version.php y no se escribe a mano en el plugin: así
 * viaja con la carpeta y no hay forma de copiar código nuevo con número viejo.
 *
 * OJO: register( $version, $file ) no puede cambiar de firma nunca. La clase la
 * define el primer plugin que cargue, que puede llevar una copia antigua, y los
 * plugins más nuevos la llaman igual.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'Dox_Core_Loader' ) ) {

	class Dox_Core_Loader {

		/**
		 * La lista vive en $GLOBALS y no en una propiedad de la clase porque la
		 * clase la define el primer plugin que se cargue y los demás usan esa,
		 * que puede ser de otra versión: el estado compartido tiene que ser
		 * independiente de qué copia definió la clase.
		 */
		const STORE = 'dox_core_candidates';

		public static function register( $version, $file ) {

			if ( ! isset( $GLOBALS[ self::STORE ] ) ) {
				$GLOBALS[ self::STORE ] = [];
			}

			$GLOBALS[ self::STORE ][] = [
				'version' => (string) $version,
				'file'    => (string) $file,
			];

			if ( ! has_action( 'after_setup_theme', [ __CLASS__, 'boot' ] ) ) {
				add_action( 'after_setup_theme', [ __CLASS__, 'boot' ], -20 );
			}
		}

		public static function boot() {

			if ( class_exists( 'Dox_Core' ) ) return; // ya la cargó otra copia

			$candidates = isset( $GLOBALS[ self::STORE ] ) ? $GLOBALS[ self::STORE ] : [];
			if ( ! $candidates ) return;

			usort( $candidates, function ( $a, $b ) {
				return version_compare( $b['version'], $a['version'] );
			} );

			foreach ( $candidates as $candidate ) {
				if ( file_exists( $candidate['file'] ) ) {
					require_once $candidate['file'];
					return;
				}
			}
		}
	}
}
