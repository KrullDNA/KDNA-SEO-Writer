<?php
/**
 * Per-item state: fingerprints of the values the plugin wrote.
 *
 * @package KDNA_SeoWriter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Remembers what the plugin wrote into each field, stored as kdna_sw_state
 * post meta or term meta.
 *
 * Each value the plugin writes is stored as a short fingerprint (a hash). If a
 * field's value later stops matching its fingerprint, a person has edited it,
 * and automation must leave it alone. Stage 3 builds the field locks on this.
 */
final class KDNA_SeoWriter_State {

	/**
	 * Meta key used on posts and terms.
	 */
	public const META_KEY = 'kdna_sw_state';

	/**
	 * Returns the stored state for an item.
	 *
	 * @param string $object_type Either post or term.
	 * @param int    $id          Post or term ID.
	 * @return array{fingerprints: array<string, string>, locked: array<string, bool>, generated: int, skipped: string}
	 */
	public static function get( string $object_type, int $id ): array {
		$raw = 'term' === $object_type ? get_term_meta( $id, self::META_KEY, true ) : get_post_meta( $id, self::META_KEY, true );
		$raw = is_array( $raw ) ? $raw : array();

		return array(
			'fingerprints' => isset( $raw['fingerprints'] ) && is_array( $raw['fingerprints'] ) ? $raw['fingerprints'] : array(),
			'locked'       => isset( $raw['locked'] ) && is_array( $raw['locked'] ) ? $raw['locked'] : array(),
			'generated'    => (int) ( $raw['generated'] ?? 0 ),
			'skipped'      => (string) ( $raw['skipped'] ?? '' ),
		);
	}

	/**
	 * Saves the state for an item.
	 *
	 * @param string               $object_type Either post or term.
	 * @param int                  $id          Post or term ID.
	 * @param array<string, mixed> $state       State from get(), changed.
	 * @return void
	 */
	public static function save( string $object_type, int $id, array $state ): void {
		if ( 'term' === $object_type ) {
			update_term_meta( $id, self::META_KEY, $state );
		} else {
			update_post_meta( $id, self::META_KEY, $state );
		}
	}

	/**
	 * Records the fingerprints of values the plugin has just written.
	 *
	 * @param string                $object_type Either post or term.
	 * @param int                   $id          Post or term ID.
	 * @param array<string, string> $values      Field name to the value written.
	 * @return void
	 */
	public static function remember( string $object_type, int $id, array $values ): void {
		$state = self::get( $object_type, $id );
		foreach ( $values as $field => $value ) {
			$state['fingerprints'][ $field ] = self::fingerprint( $value );
		}
		$state['generated'] = time();
		$state['skipped']   = '';
		self::save( $object_type, $id, $state );
	}

	/**
	 * Records why an item was skipped (for example not enough content), so it
	 * can be listed in the Overview.
	 *
	 * @param string $object_type Either post or term.
	 * @param int    $id          Post or term ID.
	 * @param string $reason      Short reason code from the page reader.
	 * @return void
	 */
	public static function skipped( string $object_type, int $id, string $reason ): void {
		if ( 'not_found' === $reason ) {
			return;
		}
		$state            = self::get( $object_type, $id );
		$state['skipped'] = sanitize_key( $reason );
		self::save( $object_type, $id, $state );
	}

	/**
	 * Forgets the fingerprint of a field, for example after an undo puts back
	 * text the plugin did not write. Without a fingerprint, automation treats
	 * the field as written by a person and leaves it alone.
	 *
	 * @param string $object_type Either post or term.
	 * @param int    $id          Post or term ID.
	 * @param string $field       Field name.
	 * @return void
	 */
	public static function forget( string $object_type, int $id, string $field ): void {
		$state = self::get( $object_type, $id );
		unset( $state['fingerprints'][ $field ] );
		self::save( $object_type, $id, $state );
	}

	/**
	 * Tells you whether a field still holds exactly what the plugin wrote.
	 *
	 * @param string $object_type Either post or term.
	 * @param int    $id          Post or term ID.
	 * @param string $field       Field name.
	 * @param string $value       The field's current value.
	 * @return bool True when the value matches the plugin's last write.
	 */
	public static function matches( string $object_type, int $id, string $field, string $value ): bool {
		$state = self::get( $object_type, $id );
		return isset( $state['fingerprints'][ $field ] ) && hash_equals( $state['fingerprints'][ $field ], self::fingerprint( $value ) );
	}

	/**
	 * Returns the fingerprint of a value.
	 *
	 * @param string $value Field value.
	 * @return string 32-character hash.
	 */
	public static function fingerprint( string $value ): string {
		return md5( trim( $value ) );
	}
}
