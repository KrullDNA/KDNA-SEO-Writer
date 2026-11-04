<?php
/**
 * Yoast SEO: field keys, term storage, title templates and indexable refresh.
 *
 * @package KDNA_SeoWriter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes Yoast's SEO fields. This is the only class that knows how
 * Yoast stores its data, so a change at Yoast's end is fixed here alone.
 *
 * Confirmed against Yoast SEO 28.9 (free) on 4 November 2026:
 *
 * Posts, pages, products and custom post types use post meta with the prefix
 * _yoast_wpseo_ (WPSEO_Meta::$meta_prefix), written with WPSEO_Meta::set_value():
 *   SEO title            _yoast_wpseo_title
 *   Meta description     _yoast_wpseo_metadesc
 *   Focus keyphrase      _yoast_wpseo_focuskw
 *   Facebook title       _yoast_wpseo_opengraph-title
 *   Facebook description _yoast_wpseo_opengraph-description
 *   X title              _yoast_wpseo_twitter-title
 *   X description        _yoast_wpseo_twitter-description
 *
 * Categories, tags and other terms are stored in one option, wpseo_taxonomy_meta,
 * as [ taxonomy ][ term ID ][ key ], with the keys wpseo_title, wpseo_desc,
 * wpseo_focuskw, wpseo_opengraph-title, wpseo_opengraph-description,
 * wpseo_twitter-title and wpseo_twitter-description. They are written with
 * WPSEO_Taxonomy_Meta::set_values(), which runs Yoast's own validation.
 *
 * Indexables: Yoast keeps a fast copy of each item's SEO data in its own
 * table. After writing, the plugin asks Yoast's own watcher classes
 * (Indexable_Post_Watcher and Indexable_Term_Watcher, fetched through
 * YoastSEO()->classes->get()) to rebuild that item's copy straight away. These
 * are the same methods Yoast runs when you save in the editor. For posts Yoast
 * would also rebuild at the end of the request on its own, but terms saved
 * outside the term edit screen are only refreshed by this explicit call.
 *
 * Title templates live in the wpseo_titles option: title-{post type} for posts
 * and title-tax-{taxonomy} for terms, with the separator under separator.
 */
final class KDNA_SeoWriter_Yoast {

	/**
	 * The plugin's field names, mapped to Yoast's post meta key (without prefix)
	 * and Yoast's term key.
	 */
	public const FIELDS = array(
		'seo_title'        => array(
			'post' => 'title',
			'term' => 'wpseo_title',
		),
		'meta_description' => array(
			'post' => 'metadesc',
			'term' => 'wpseo_desc',
		),
		'focus_keyphrase'  => array(
			'post' => 'focuskw',
			'term' => 'wpseo_focuskw',
		),
		'og_title'         => array(
			'post' => 'opengraph-title',
			'term' => 'wpseo_opengraph-title',
		),
		'og_description'   => array(
			'post' => 'opengraph-description',
			'term' => 'wpseo_opengraph-description',
		),
		'x_title'          => array(
			'post' => 'twitter-title',
			'term' => 'wpseo_twitter-title',
		),
		'x_description'    => array(
			'post' => 'twitter-description',
			'term' => 'wpseo_twitter-description',
		),
	);

	/**
	 * Prefix of Yoast's post meta keys, used if WPSEO_Meta is not loaded.
	 */
	private const POST_META_PREFIX = '_yoast_wpseo_';

	/**
	 * Separators Yoast offers, used if Yoast's own list is not available.
	 */
	private const SEPARATORS = array(
		'sc-dash'   => '-',
		'sc-ndash'  => '–',
		'sc-mdash'  => '—',
		'sc-colon'  => ':',
		'sc-middot' => '·',
		'sc-bull'   => '•',
		'sc-star'   => '*',
		'sc-smstar' => '⋆',
		'sc-pipe'   => '|',
		'sc-tilde'  => '~',
		'sc-laquo'  => '«',
		'sc-raquo'  => '»',
		'sc-lt'     => '<',
		'sc-gt'     => '>',
	);

	/**
	 * Yoast's variables that may follow the title in an SEO title. Anything
	 * else in the template after the title (such as the word "Archives") is
	 * left out, because the written title replaces it.
	 */
	private const SUFFIX_VARIABLES = array( '%%page%%', '%%sep%%', '%%sitename%%' );

	/**
	 * Tells you whether Yoast SEO is active.
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		return defined( 'WPSEO_VERSION' );
	}

	/**
	 * Returns the Yoast version, for the debug panel and error messages.
	 *
	 * @return string
	 */
	public static function version(): string {
		return defined( 'WPSEO_VERSION' ) ? (string) WPSEO_VERSION : '';
	}

	/**
	 * Tells you whether Yoast is set to keep a content type out of search
	 * results ("Show in search results" switched off in Yoast's settings).
	 *
	 * Stored in the wpseo_titles option as noindex-{post type} and
	 * noindex-tax-{taxonomy} (Yoast SEO 28.9).
	 *
	 * @param string $object_type Either post or term.
	 * @param string $subtype     Post type or taxonomy.
	 * @return bool
	 */
	public static function hidden_from_search( string $object_type, string $subtype ): bool {
		if ( ! self::is_active() ) {
			return false;
		}
		$titles = get_option( 'wpseo_titles', array() );
		$key    = 'term' === $object_type ? 'noindex-tax-' . $subtype : 'noindex-' . $subtype;
		return is_array( $titles ) && ! empty( $titles[ $key ] );
	}

	/**
	 * Reads one field.
	 *
	 * @param string $object_type Either post or term.
	 * @param int    $id          Post or term ID.
	 * @param string $field       One of the keys of FIELDS.
	 * @param string $taxonomy    For terms, the taxonomy.
	 * @return string The saved value, or an empty string.
	 */
	public static function get( string $object_type, int $id, string $field, string $taxonomy = '' ): string {
		$values = self::get_all( $object_type, $id, $taxonomy );
		return $values[ $field ] ?? '';
	}

	/**
	 * Reads every field the plugin writes for one item.
	 *
	 * @param string $object_type Either post or term.
	 * @param int    $id          Post or term ID.
	 * @param string $taxonomy    For terms, the taxonomy.
	 * @return array<string, string> Field name to saved value.
	 */
	public static function get_all( string $object_type, int $id, string $taxonomy = '' ): array {
		$out = array();

		if ( 'term' === $object_type ) {
			$meta = self::term_meta( $id, $taxonomy );
			foreach ( self::FIELDS as $field => $keys ) {
				$out[ $field ] = isset( $meta[ $keys['term'] ] ) ? (string) $meta[ $keys['term'] ] : '';
			}
			return $out;
		}

		foreach ( self::FIELDS as $field => $keys ) {
			$out[ $field ] = (string) get_post_meta( $id, self::post_meta_key( $field ), true );
		}
		return $out;
	}

	/**
	 * Writes fields into Yoast and refreshes Yoast's indexable for the item.
	 *
	 * @param string                $object_type Either post or term.
	 * @param int                   $id          Post or term ID.
	 * @param array<string, string> $values      Field name to new value. An empty value clears the field.
	 * @param string                $taxonomy    For terms, the taxonomy.
	 * @return true|WP_Error True when saved.
	 */
	public static function set( string $object_type, int $id, array $values, string $taxonomy = '' ) {
		if ( ! self::is_active() ) {
			return new WP_Error( 'kdna_sw_no_yoast', __( 'Yoast SEO is not active, so nothing can be saved.', 'kdna-seo-writer' ) );
		}

		$values = array_intersect_key( $values, self::FIELDS );
		if ( ! $values ) {
			return true;
		}

		if ( 'term' === $object_type ) {
			$result = self::set_term( $id, $taxonomy, $values );
		} else {
			$result = self::set_post( $id, $values );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		self::refresh_indexable( $object_type, $id );
		return true;
	}

	/**
	 * Writes post fields using Yoast's own WPSEO_Meta::set_value().
	 *
	 * @param int                   $post_id Post ID.
	 * @param array<string, string> $values  Field name to value.
	 * @return true
	 */
	private static function set_post( int $post_id, array $values ) {
		foreach ( $values as $field => $value ) {
			if ( class_exists( 'WPSEO_Meta' ) && method_exists( 'WPSEO_Meta', 'set_value' ) ) {
				if ( '' === $value ) {
					WPSEO_Meta::delete( self::FIELDS[ $field ]['post'], $post_id );
				} else {
					WPSEO_Meta::set_value( self::FIELDS[ $field ]['post'], $value, $post_id );
				}
			} elseif ( '' === $value ) {
				delete_post_meta( $post_id, self::post_meta_key( $field ) );
			} else {
				update_post_meta( $post_id, self::post_meta_key( $field ), wp_slash( $value ) );
			}
		}
		return true;
	}

	/**
	 * Writes term fields using Yoast's own WPSEO_Taxonomy_Meta::set_values().
	 *
	 * @param int                   $term_id  Term ID.
	 * @param string                $taxonomy Taxonomy.
	 * @param array<string, string> $values   Field name to value.
	 * @return true|WP_Error
	 */
	private static function set_term( int $term_id, string $taxonomy, array $values ) {
		if ( '' === $taxonomy ) {
			$term     = get_term( $term_id );
			$taxonomy = $term instanceof WP_Term ? $term->taxonomy : '';
		}
		if ( '' === $taxonomy || ! class_exists( 'WPSEO_Taxonomy_Meta' ) ) {
			return new WP_Error( 'kdna_sw_term_storage', __( 'Yoast\'s category and tag storage could not be found, so nothing was saved.', 'kdna-seo-writer' ) );
		}

		// Yoast's validation starts from its defaults and only keeps the keys it
		// is given, so any key left out would be reset (for example a canonical
		// URL or noindex setting). The term's existing values are therefore
		// passed in full, with only the plugin's fields changed.
		$existing = WPSEO_Taxonomy_Meta::get_term_meta( $term_id, $taxonomy );
		$meta     = is_array( $existing ) ? $existing : self::term_meta( $term_id, $taxonomy );
		foreach ( $values as $field => $value ) {
			$meta[ self::FIELDS[ $field ]['term'] ] = $value;
		}

		WPSEO_Taxonomy_Meta::set_values( $term_id, $taxonomy, $meta );
		return true;
	}

	/**
	 * Asks Yoast to rebuild its stored copy (indexable) of one item, now.
	 *
	 * If Yoast's classes cannot be reached (for example a future Yoast renames
	 * them), Yoast's own watchers still rebuild posts at the end of the request,
	 * and the debug panel reports that the explicit refresh was not possible.
	 *
	 * @param string $object_type Either post or term.
	 * @param int    $id          Post or term ID.
	 * @return bool True when Yoast's rebuild was called.
	 */
	public static function refresh_indexable( string $object_type, int $id ): bool {
		if ( ! function_exists( 'YoastSEO' ) ) {
			return false;
		}

		$class = 'term' === $object_type
			? '\Yoast\WP\SEO\Integrations\Watchers\Indexable_Term_Watcher'
			: '\Yoast\WP\SEO\Integrations\Watchers\Indexable_Post_Watcher';

		try {
			$watcher = YoastSEO()->classes->get( $class );
			if ( is_object( $watcher ) && method_exists( $watcher, 'build_indexable' ) ) {
				$watcher->build_indexable( $id );
				return true;
			}
		} catch ( \Throwable $e ) {
			return false;
		}

		return false;
	}

	/**
	 * Returns Yoast's SEO title template for a post type or taxonomy.
	 *
	 * The Advanced tab can override it. If Yoast has no template saved, Yoast's
	 * own default is used.
	 *
	 * @param string $object_type Either post or term.
	 * @param string $subtype     Post type or taxonomy.
	 * @return string Template, for example %%title%% %%page%% %%sep%% %%sitename%%.
	 */
	public static function title_template( string $object_type, string $subtype ): string {
		$override = trim( (string) KDNA_SeoWriter_Settings::get( 'advanced', 'title_template' ) );
		if ( '' !== $override ) {
			return $override;
		}

		$titles = get_option( 'wpseo_titles', array() );
		$key    = 'term' === $object_type ? 'title-tax-' . $subtype : 'title-' . $subtype;

		if ( is_array( $titles ) && ! empty( $titles[ $key ] ) && is_string( $titles[ $key ] ) ) {
			return $titles[ $key ];
		}

		return 'term' === $object_type
			? '%%term_title%% Archives %%page%% %%sep%% %%sitename%%'
			: '%%title%% %%page%% %%sep%% %%sitename%%';
	}

	/**
	 * Returns the part of the template that follows the title, as Yoast variables.
	 *
	 * For %%title%% %%page%% %%sep%% %%sitename%% this is " %%page%% %%sep%% %%sitename%%".
	 * Only page, separator and site name are kept, so a later change to the
	 * site name or separator flows through to every title.
	 *
	 * @param string $template Title template.
	 * @return string Suffix with a leading space, or an empty string.
	 */
	public static function title_suffix( string $template ): string {
		$position = false;
		foreach ( array( '%%title%%', '%%term_title%%' ) as $variable ) {
			$found = strpos( $template, $variable );
			if ( false !== $found ) {
				$position = $found + strlen( $variable );
				break;
			}
		}

		$after = false === $position ? $template : substr( $template, $position );
		if ( ! preg_match_all( '/%%[a-z_]+%%/', $after, $matches ) ) {
			return '';
		}

		$kept = array_values( array_intersect( $matches[0], self::SUFFIX_VARIABLES ) );
		return $kept ? ' ' . implode( ' ', $kept ) : '';
	}

	/**
	 * Builds the value saved in Yoast's SEO title field.
	 *
	 * Claude writes the words of the title. Yoast's separator and site name
	 * variables are added after it, matching the site's title template.
	 *
	 * @param string $title_text  The title words written by Claude.
	 * @param string $object_type Either post or term.
	 * @param string $subtype     Post type or taxonomy.
	 * @return string Value for the SEO title field.
	 */
	public static function build_seo_title( string $title_text, string $object_type, string $subtype ): string {
		$title_text = trim( str_replace( '%%', '', $title_text ) );
		return $title_text . self::title_suffix( self::title_template( $object_type, $subtype ) );
	}

	/**
	 * Turns a Yoast title with variables into the text Google will show.
	 *
	 * Uses Yoast's own wpseo_replace_vars() when available. Otherwise the
	 * separator, site name and page number are filled in here.
	 *
	 * @param string $title       Title, possibly with %%variables%%.
	 * @param string $object_type Either post or term.
	 * @param int    $id          Post or term ID.
	 * @return string Plain title text.
	 */
	public static function render_title( string $title, string $object_type, int $id ): string {
		if ( function_exists( 'wpseo_replace_vars' ) ) {
			$args = 'term' === $object_type ? get_term( $id ) : get_post( $id );
			try {
				$title = (string) wpseo_replace_vars( $title, is_object( $args ) ? $args : array() );
			} catch ( \Throwable $e ) {
				$title = self::simple_replace( $title );
			}
		} else {
			$title = self::simple_replace( $title );
		}

		$title = html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( (string) preg_replace( '/\s+/u', ' ', $title ) );
	}

	/**
	 * Fills in the separator, site name and page variables without Yoast.
	 *
	 * @param string $title Title with variables.
	 * @return string Title text.
	 */
	private static function simple_replace( string $title ): string {
		$title = str_replace(
			array( '%%sep%%', '%%sitename%%', '%%page%%' ),
			array( self::separator(), (string) get_bloginfo( 'name' ), '' ),
			$title
		);
		return (string) preg_replace( '/%%[a-z_]+%%/', '', $title );
	}

	/**
	 * Returns the separator character chosen in Yoast's settings.
	 *
	 * @return string
	 */
	public static function separator(): string {
		$titles = get_option( 'wpseo_titles', array() );
		$key    = is_array( $titles ) && ! empty( $titles['separator'] ) ? (string) $titles['separator'] : 'sc-dash';
		return self::SEPARATORS[ $key ] ?? '-';
	}

	/**
	 * Returns every saved focus keyphrase on the site, for the keyphrase register.
	 *
	 * @return array<int, array{object_type: string, id: int, subtype: string, keyphrase: string}>
	 */
	public static function all_focus_keyphrases(): array {
		global $wpdb;

		$out = array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One light read of Yoast's own keyphrase field, cached by the keyphrase register.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.post_id, pm.meta_value, p.post_type
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key = %s AND pm.meta_value <> ''
				AND p.post_status NOT IN ( 'trash', 'auto-draft', 'inherit' )",
				self::POST_META_PREFIX . 'focuskw'
			)
		);

		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'object_type' => 'post',
				'id'          => (int) $row->post_id,
				'subtype'     => (string) $row->post_type,
				'keyphrase'   => (string) $row->meta_value,
			);
		}

		$tax_meta = get_option( 'wpseo_taxonomy_meta', array() );
		if ( is_array( $tax_meta ) ) {
			foreach ( $tax_meta as $taxonomy => $terms ) {
				if ( ! is_array( $terms ) ) {
					continue;
				}
				foreach ( $terms as $term_id => $meta ) {
					if ( is_array( $meta ) && ! empty( $meta['wpseo_focuskw'] ) ) {
						$out[] = array(
							'object_type' => 'term',
							'id'          => (int) $term_id,
							'subtype'     => (string) $taxonomy,
							'keyphrase'   => (string) $meta['wpseo_focuskw'],
						);
					}
				}
			}
		}

		return $out;
	}

	/**
	 * Returns Yoast's full post meta key for a field.
	 *
	 * @param string $field One of the keys of FIELDS.
	 * @return string
	 */
	private static function post_meta_key( string $field ): string {
		$prefix = class_exists( 'WPSEO_Meta' ) ? WPSEO_Meta::$meta_prefix : self::POST_META_PREFIX;
		return $prefix . self::FIELDS[ $field ]['post'];
	}

	/**
	 * Returns the stored Yoast values for one term.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy, looked up if empty.
	 * @return array<string, mixed>
	 */
	private static function term_meta( int $term_id, string $taxonomy ): array {
		if ( '' === $taxonomy ) {
			$term     = get_term( $term_id );
			$taxonomy = $term instanceof WP_Term ? $term->taxonomy : '';
		}

		$all = get_option( 'wpseo_taxonomy_meta', array() );
		return is_array( $all ) && isset( $all[ $taxonomy ][ $term_id ] ) && is_array( $all[ $taxonomy ][ $term_id ] )
			? $all[ $taxonomy ][ $term_id ]
			: array();
	}
}
