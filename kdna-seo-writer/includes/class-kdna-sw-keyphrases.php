<?php
/**
 * Site-wide keyphrase register.
 *
 * @package KDNA_SeoWriter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Keeps a list of every focus keyphrase already used on the site.
 *
 * Yoast warns when two pages share a focus keyphrase ("previously used
 * keyphrase"), so Claude is told which keyphrases are taken, and the rules
 * check refuses any repeat. The list is built from Yoast's own keyphrase
 * fields on posts and terms, cached for an hour, and cleared whenever a
 * keyphrase changes on a backend request.
 */
final class KDNA_SeoWriter_Keyphrases {

	/**
	 * Name of the cached list.
	 */
	private const CACHE_KEY = 'kdna_sw_keyphrases';

	/**
	 * The list for this request, so it is only built once.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private static ?array $memory = null;

	/**
	 * Registers the hooks that clear the cached list when a keyphrase changes.
	 *
	 * @return void
	 */
	public static function hooks(): void {
		add_action( 'added_post_meta', array( __CLASS__, 'maybe_flush_for_meta' ), 10, 3 );
		add_action( 'updated_post_meta', array( __CLASS__, 'maybe_flush_for_meta' ), 10, 3 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'maybe_flush_for_meta' ), 10, 3 );
		add_action( 'update_option_wpseo_taxonomy_meta', array( __CLASS__, 'flush' ) );
		add_action( 'trashed_post', array( __CLASS__, 'flush' ) );
		add_action( 'untrashed_post', array( __CLASS__, 'flush' ) );
		add_action( 'deleted_post', array( __CLASS__, 'flush' ) );
		add_action( 'delete_term', array( __CLASS__, 'flush' ) );
	}

	/**
	 * Clears the list if the meta that changed is Yoast's focus keyphrase.
	 *
	 * @param mixed  $meta_id  Meta ID (unused).
	 * @param mixed  $post_id  Post ID (unused).
	 * @param string $meta_key Meta key that changed.
	 * @return void
	 */
	public static function maybe_flush_for_meta( $meta_id, $post_id, $meta_key ): void {
		if ( '_yoast_wpseo_focuskw' === $meta_key ) {
			self::flush();
		}
	}

	/**
	 * Clears the cached list.
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$memory = null;
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Returns every keyphrase in use, with the item it belongs to.
	 *
	 * @param bool $fresh True to skip the cache and read Yoast's fields now.
	 * @return array<int, array{object_type: string, id: int, subtype: string, keyphrase: string}>
	 */
	public static function all( bool $fresh = false ): array {
		if ( ! $fresh && null !== self::$memory ) {
			return self::$memory;
		}

		if ( ! $fresh ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) ) {
				self::$memory = $cached;
				return $cached;
			}
		}

		self::$memory = KDNA_SeoWriter_Yoast::all_focus_keyphrases();

		// An expiry is set so the transient is not autoloaded on public pages.
		set_transient( self::CACHE_KEY, self::$memory, HOUR_IN_SECONDS );

		return self::$memory;
	}

	/**
	 * Returns the keyphrases used by every item except the one being written for.
	 *
	 * @param string $object_type Either post or term.
	 * @param int    $id          The item's ID.
	 * @param bool   $fresh       True to read Yoast's fields now rather than the cache.
	 * @return array<int, string> Keyphrases, as typed, without duplicates.
	 */
	public static function taken_by_others( string $object_type, int $id, bool $fresh = false ): array {
		$taken = array();
		foreach ( self::all( $fresh ) as $row ) {
			if ( $row['object_type'] === $object_type && (int) $row['id'] === $id ) {
				continue;
			}
			$key           = KDNA_SeoWriter_Rules::normalise( $row['keyphrase'] );
			$taken[ $key ] = $row['keyphrase'];
		}
		return array_values( $taken );
	}
}
