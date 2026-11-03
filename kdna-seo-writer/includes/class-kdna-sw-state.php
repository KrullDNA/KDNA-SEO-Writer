<?php
/**
 * Per-item state: fingerprints, field locks, last result and field status.
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
 * so the field is locked and automation never touches it again. A lock can be
 * cleared by hand. A field that already had text before the plugin arrived has
 * no fingerprint, and automation never replaces it either, because automation
 * only ever fills empty fields.
 *
 * When each item was last attempted is kept in a second, plain meta key
 * (kdna_sw_attempted) so the nightly sweep can sort by it in one query.
 */
final class KDNA_SeoWriter_State {

	/**
	 * Meta key used on posts and terms.
	 */
	public const META_KEY = 'kdna_sw_state';

	/**
	 * Meta key holding the time an item was last attempted, for the nightly sweep.
	 */
	public const ATTEMPTED_KEY = 'kdna_sw_attempted';

	/**
	 * Field groups shown in status displays, and the Yoast fields in each.
	 */
	public const GROUPS = array(
		'seo_title'        => array( 'seo_title' ),
		'meta_description' => array( 'meta_description' ),
		'focus_keyphrase'  => array( 'focus_keyphrase' ),
		'social'           => array( 'og_title', 'og_description', 'x_title', 'x_description' ),
	);

	/**
	 * Returns the stored state for an item.
	 *
	 * @param string $object_type Either post or term.
	 * @param int    $id          Post or term ID.
	 * @return array{fingerprints: array<string, string>, locked: array<string, bool>, generated: int, skipped: string, last: array<string, mixed>}
	 */
	public static function get( string $object_type, int $id ): array {
		$raw = 'term' === $object_type ? get_term_meta( $id, self::META_KEY, true ) : get_post_meta( $id, self::META_KEY, true );
		$raw = is_array( $raw ) ? $raw : array();

		return array(
			'fingerprints' => isset( $raw['fingerprints'] ) && is_array( $raw['fingerprints'] ) ? $raw['fingerprints'] : array(),
			'locked'       => isset( $raw['locked'] ) && is_array( $raw['locked'] ) ? $raw['locked'] : array(),
			'generated'    => (int) ( $raw['generated'] ?? 0 ),
			'skipped'      => (string) ( $raw['skipped'] ?? '' ),
			'last'         => isset( $raw['last'] ) && is_array( $raw['last'] ) ? $raw['last'] : array(),
			'job'          => (int) ( $raw['job'] ?? 0 ),
		);
	}

	/**
	 * Records that a background job is waiting for this item, or that it has run.
	 *
	 * @param string $object_type Either post or term.
	 * @param int    $id          Post or term ID.
	 * @param bool   $waiting     True when a job has just been queued.
	 * @return void
	 */
	public static function set_job( string $object_type, int $id, bool $waiting ): void {
		$state        = self::get( $object_type, $id );
		$state['job'] = $waiting ? time() : 0;
		self::save( $object_type, $id, $state );
	}

	/**
	 * Tells you whether a background job is waiting for this item.
	 *
	 * A job older than a day is treated as lost (for example if the job queue
	 * was cleared), so the item is not shown as waiting for ever.
	 *
	 * @param string $object_type Either post or term.
	 * @param int    $id          Post or term ID.
	 * @return bool
	 */
	public static function job_waiting( string $object_type, int $id ): bool {
		$job = self::get( $object_type, $id )['job'];
		return $job > 0 && $job > time() - DAY_IN_SECONDS;
	}

	/**
	 * Locks any field a person has edited since the plugin wrote it.
	 *
	 * A field is locked when it has a fingerprint and its current value no
	 * longer matches it, including when a person has emptied it on purpose.
	 *
	 * @param string                $object_type Either post or term.
	 * @param int                   $id          Post or term ID.
	 * @param array<string, string> $current     Current Yoast values, field name to value.
	 * @return array<string, mixed> The state, with locks up to date.
	 */
	public static function refresh_locks( string $object_type, int $id, array $current ): array {
		$state   = self::get( $object_type, $id );
		$changed = false;

		foreach ( $state['fingerprints'] as $field => $fingerprint ) {
			if ( ! empty( $state['locked'][ $field ] ) || ! array_key_exists( $field, $current ) ) {
				continue;
			}
			if ( ! hash_equals( (string) $fingerprint, self::fingerprint( (string) $current[ $field ] ) ) ) {
				$state['locked'][ $field ] = true;
				$changed                   = true;
			}
		}

		if ( $changed ) {
			self::save( $object_type, $id, $state );
		}
		return $state;
	}

	/**
	 * Locks fields on purpose, for example after a person edits a suggestion
	 * before approving it, because the words are then theirs.
	 *
	 * @param string             $object_type Either post or term.
	 * @param int                $id          Post or term ID.
	 * @param array<int, string> $fields      Field names.
	 * @return void
	 */
	public static function lock( string $object_type, int $id, array $fields ): void {
		$state = self::get( $object_type, $id );
		foreach ( $fields as $field ) {
			$state['locked'][ $field ] = true;
		}
		self::save( $object_type, $id, $state );
	}

	/**
	 * Clears locks, so the plugin may work on those fields again.
	 *
	 * The current value becomes the new fingerprint, so the field only locks
	 * again if a person edits it after this.
	 *
	 * @param string                $object_type Either post or term.
	 * @param int                   $id          Post or term ID.
	 * @param array<int, string>    $fields      Fields to unlock, or an empty list for all.
	 * @param array<string, string> $current     Current Yoast values.
	 * @return void
	 */
	public static function unlock( string $object_type, int $id, array $fields, array $current ): void {
		$state  = self::get( $object_type, $id );
		$fields = $fields ? $fields : array_keys( $state['locked'] );

		foreach ( $fields as $field ) {
			unset( $state['locked'][ $field ] );
			if ( isset( $current[ $field ] ) && '' !== trim( $current[ $field ] ) ) {
				$state['fingerprints'][ $field ] = self::fingerprint( $current[ $field ] );
			} else {
				unset( $state['fingerprints'][ $field ] );
			}
		}
		self::save( $object_type, $id, $state );
	}

	/**
	 * Clears every lock on the site, from Settings > Advanced.
	 *
	 * @return int Number of items whose locks were cleared.
	 */
	public static function clear_all_locks(): int {
		global $wpdb;

		$count = 0;
		$sets  = array(
			'post' => $wpdb->postmeta,
			'term' => $wpdb->termmeta,
		);

		foreach ( $sets as $type => $table ) {
			$column = 'term' === $type ? 'term_id' : 'post_id';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Admin action, core meta tables.
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT {$column} FROM {$table} WHERE meta_key = %s AND meta_value LIKE %s", self::META_KEY, '%' . $wpdb->esc_like( '"locked";a:' ) . '%' ) );

			foreach ( (array) $ids as $id ) {
				$id    = (int) $id;
				$state = self::get( $type, $id );
				if ( ! array_filter( $state['locked'] ) ) {
					continue;
				}
				$subtype = 'term' === $type ? '' : (string) get_post_type( $id );
				self::unlock( $type, $id, array(), KDNA_SeoWriter_Yoast::get_all( $type, $id, $subtype ) );
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Records the outcome of the last background job for an item, shown in the
	 * edit screen panel.
	 *
	 * @param string $object_type Either post or term.
	 * @param int    $id          Post or term ID.
	 * @param string $status      saved, queued, nothing, skipped, error or paused.
	 * @param string $message     Plain-English message.
	 * @return void
	 */
	public static function set_last( string $object_type, int $id, string $status, string $message ): void {
		$state         = self::get( $object_type, $id );
		$state['job']  = 0;
		$state['last'] = array(
			'status'  => sanitize_key( $status ),
			'message' => $message,
			'time'    => time(),
		);
		self::save( $object_type, $id, $state );
	}

	/**
	 * Records that the plugin has just attempted an item.
	 *
	 * @param string $object_type Either post or term.
	 * @param int    $id          Post or term ID.
	 * @return void
	 */
	public static function attempted( string $object_type, int $id ): void {
		if ( 'term' === $object_type ) {
			update_term_meta( $id, self::ATTEMPTED_KEY, time() );
		} else {
			update_post_meta( $id, self::ATTEMPTED_KEY, time() );
		}
	}

	/**
	 * Works out the status of each field group for an item.
	 *
	 * @param string                $object_type Either post or term.
	 * @param int                   $id          Post or term ID.
	 * @param array<string, string> $current     Current Yoast values.
	 * @param array<int, string>    $waiting     Fields with a suggestion waiting for review.
	 * @return array<string, string> Group name to filled, waiting, locked or missing,
	 *                               for the groups switched on in the Content tab.
	 */
	public static function field_statuses( string $object_type, int $id, array $current, array $waiting ): array {
		$state   = self::get( $object_type, $id );
		$enabled = (array) KDNA_SeoWriter_Settings::get( 'content', 'fields' );
		$out     = array();

		foreach ( self::GROUPS as $group => $fields ) {
			if ( empty( $enabled[ $group ] ) ) {
				continue;
			}

			// Social is shown as filled when the Facebook title and description are.
			$check  = 'social' === $group ? array( 'og_title', 'og_description' ) : $fields;
			$filled = true;
			foreach ( $check as $field ) {
				if ( '' === trim( (string) ( $current[ $field ] ?? '' ) ) ) {
					$filled = false;
				}
			}

			if ( array_intersect( $fields, $waiting ) ) {
				$out[ $group ] = 'waiting';
			} elseif ( array_intersect( $fields, array_keys( array_filter( $state['locked'] ) ) ) ) {
				$out[ $group ] = 'locked';
			} else {
				$out[ $group ] = $filled ? 'filled' : 'missing';
			}
		}

		return $out;
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
