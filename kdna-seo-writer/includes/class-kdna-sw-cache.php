<?php
/**
 * WP Rocket cache purge after writes.
 *
 * @package KDNA_SeoWriter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Clears the WP Rocket cache for the one address that changed.
 *
 * WP Rocket's rocket_clean_files() is used rather than rocket_clean_post(),
 * because rocket_clean_post() also clears related pages such as the home page
 * and archives. Only the changed URL is cleared, never the whole site.
 */
final class KDNA_SeoWriter_Cache {

	/**
	 * Clears the cached copy of one item's page, if WP Rocket is active and the
	 * setting is on.
	 *
	 * @param string $url The item's public address.
	 * @return bool True when WP Rocket was asked to clear it.
	 */
	public static function purge_url( string $url ): bool {
		if ( '' === $url || ! KDNA_SeoWriter_Settings::get( 'automation', 'rocket_purge' ) || ! function_exists( 'rocket_clean_files' ) ) {
			return false;
		}

		rocket_clean_files( array( $url ) );
		return true;
	}
}
