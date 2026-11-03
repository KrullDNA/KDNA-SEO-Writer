<?php
/**
 * Overview: coverage, problems, usage and Suggest improvements.
 *
 * @package KDNA_SeoWriter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the Overview tab: how much of the site has each field filled, which
 * items have problems (duplicates and wrong lengths), this month's AI usage
 * and cost estimate, and queues "Suggest improvements" jobs.
 *
 * Only published items of content types that are not Off are counted. Each
 * problem list shows up to 100 items, which keeps the tab quick on big sites.
 */
final class KDNA_SeoWriter_Overview {

	/**
	 * Most items listed for each kind of problem.
	 */
	private const LIST_LIMIT = 100;

	/**
	 * Most SEO titles measured for width in one go.
	 */
	private const WIDTH_LIMIT = 500;

	/**
	 * Most items Suggest improvements queues in one click.
	 */
	public const SUGGEST_LIMIT = 50;

	/**
	 * Returns everything the Overview tab shows.
	 *
	 * @return array<string, mixed>
	 */
	public static function data(): array {
		$usage = KDNA_SeoWriter_Usage::this_month();
		$limit = (float) KDNA_SeoWriter_Settings::get( 'connection', 'spend_limit' );

		return array(
			'coverage' => self::coverage(),
			'problems' => self::problems(),
			'usage'    => array(
				'requests'    => $usage['requests'],
				'input'       => $usage['input'] + $usage['cache_read'] + $usage['cache_write'],
				'cached'      => $usage['cache_read'],
				'output'      => $usage['output'],
				'cost'        => round( $usage['cost'], 2 ),
				'limit'       => $limit,
				'reached'     => KDNA_SeoWriter_Usage::limit_reached(),
				'month_label' => wp_date( 'F Y' ),
			),
			'queue'    => KDNA_SeoWriter_Queue::count_items(),
			'sweep'    => array(
				'on'   => (bool) KDNA_SeoWriter_Settings::get( 'automation', 'nightly' ),
				'next' => self::format_time( KDNA_SeoWriter_Scheduler::next_sweep() ),
			),
			'ready'    => array(
				'yoast'     => KDNA_SeoWriter_Yoast::is_active(),
				'key'       => KDNA_SeoWriter_Crypto::has_key(),
				'scheduler' => KDNA_SeoWriter_Scheduler::available(),
			),
		);
	}

	/**
	 * Queues Suggest improvements jobs, whose results always go to review.
	 *
	 * @param array<int, string> $keys Item keys such as post:42. Empty means
	 *                                 every item with a problem.
	 * @return int Number of items queued.
	 */
	public static function suggest( array $keys ): int {
		if ( ! $keys ) {
			$keys = self::problem_keys();
		}

		$count = 0;
		foreach ( array_slice( array_unique( $keys ), 0, self::SUGGEST_LIMIT ) as $key ) {
			$item = KDNA_SeoWriter_Queue::parse_key( (string) $key );
			if ( ! $item || ! KDNA_SeoWriter_Queue::can_edit( $item['type'], $item['id'] ) ) {
				continue;
			}
			$subtype = self::subtype_of( $item['type'], $item['id'] );
			if ( '' !== $subtype && KDNA_SeoWriter_Scheduler::queue( $item['type'], $item['id'], $subtype, 'suggest', $count * 5 ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Counts items and filled fields for each content type that is not Off.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function coverage(): array {
		global $wpdb;

		$rows = array();
		$keys = array(
			'seo_title'        => '_yoast_wpseo_title',
			'meta_description' => '_yoast_wpseo_metadesc',
			'focus_keyphrase'  => '_yoast_wpseo_focuskw',
			'social'           => '_yoast_wpseo_opengraph-title',
		);

		foreach ( self::post_types() as $type ) {
			$object = get_post_type_object( $type );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Admin screen counts, two light queries per content type.
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'", $type ) );

			$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders built above.
			$filled = $wpdb->get_results( $wpdb->prepare( "SELECT pm.meta_key, COUNT(DISTINCT pm.post_id) AS filled FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type = %s AND p.post_status = 'publish' AND pm.meta_key IN ({$placeholders}) AND pm.meta_value <> '' GROUP BY pm.meta_key", array_merge( array( $type ), array_values( $keys ) ) ), ARRAY_A );
			// phpcs:enable

			$by_key = wp_list_pluck( (array) $filled, 'filled', 'meta_key' );
			$counts = array();
			foreach ( $keys as $group => $meta_key ) {
				$counts[ $group ] = (int) ( $by_key[ $meta_key ] ?? 0 );
			}

			$rows[] = array(
				'label'  => $object ? (string) $object->labels->name : $type,
				'name'   => $type,
				'total'  => $total,
				'filled' => $counts,
			);
		}

		$tax_meta = get_option( 'wpseo_taxonomy_meta', array() );
		foreach ( self::taxonomies() as $taxonomy ) {
			$object = get_taxonomy( $taxonomy );
			$total  = (int) wp_count_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
				)
			);
			$counts = array_fill_keys( array_keys( $keys ), 0 );
			$terms  = is_array( $tax_meta ) && isset( $tax_meta[ $taxonomy ] ) && is_array( $tax_meta[ $taxonomy ] ) ? $tax_meta[ $taxonomy ] : array();
			foreach ( $terms as $meta ) {
				if ( ! is_array( $meta ) ) {
					continue;
				}
				$counts['seo_title']        += ! empty( $meta['wpseo_title'] ) ? 1 : 0;
				$counts['meta_description'] += ! empty( $meta['wpseo_desc'] ) ? 1 : 0;
				$counts['focus_keyphrase']  += ! empty( $meta['wpseo_focuskw'] ) ? 1 : 0;
				$counts['social']           += ! empty( $meta['wpseo_opengraph-title'] ) ? 1 : 0;
			}

			$rows[] = array(
				'label'  => $object ? (string) $object->labels->name : $taxonomy,
				'name'   => $taxonomy,
				'total'  => $total,
				'filled' => $counts,
			);
		}

		return $rows;
	}

	/**
	 * Finds duplicate titles, descriptions and keyphrases, and wrong lengths.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private static function problems(): array {
		return array(
			'duplicate_titles'       => self::duplicates( '_yoast_wpseo_title', __( 'Same SEO title as', 'kdna-seo-writer' ) ),
			'duplicate_descriptions' => self::duplicates( '_yoast_wpseo_metadesc', __( 'Same meta description as', 'kdna-seo-writer' ) ),
			'duplicate_keyphrases'   => self::duplicate_keyphrases(),
			'description_lengths'    => self::description_lengths(),
			'title_widths'           => self::title_widths(),
		);
	}

	/**
	 * Returns every item key that appears in any problem list.
	 *
	 * @return array<int, string>
	 */
	public static function problem_keys(): array {
		$keys = array();
		foreach ( self::problems() as $list ) {
			foreach ( $list as $problem ) {
				$keys[] = (string) $problem['key'];
			}
		}
		return array_values( array_unique( $keys ) );
	}

	/**
	 * Lists published posts sharing the same value in one Yoast field.
	 *
	 * @param string $meta_key Yoast post meta key.
	 * @param string $label    Start of the explanation, for example "Same SEO title as".
	 * @return array<int, array<string, mixed>>
	 */
	private static function duplicates( string $meta_key, string $label ): array {
		global $wpdb;

		$types = self::post_types();
		if ( ! $types ) {
			return array();
		}
		$type_list = implode( ',', array_map( static fn( $type ) => $wpdb->prepare( '%s', $type ), $types ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Admin screen, $type_list is prepared above.
		$groups = $wpdb->get_results( $wpdb->prepare( "SELECT pm.meta_value, GROUP_CONCAT(pm.post_id) AS ids FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND pm.meta_value <> '' AND p.post_status = 'publish' AND p.post_type IN ({$type_list}) GROUP BY pm.meta_value HAVING COUNT(*) > 1 LIMIT %d", $meta_key, self::LIST_LIMIT ), ARRAY_A );

		$out = array();
		foreach ( (array) $groups as $group ) {
			$ids = array_map( 'intval', explode( ',', (string) $group['ids'] ) );
			foreach ( $ids as $id ) {
				$others = array_map( 'get_the_title', array_diff( $ids, array( $id ) ) );
				$out[]  = self::problem( 'post', $id, $label . ' ' . implode( ', ', array_filter( $others ) ) );
			}
		}
		return array_slice( $out, 0, self::LIST_LIMIT );
	}

	/**
	 * Lists items (posts and terms) sharing a focus keyphrase.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function duplicate_keyphrases(): array {
		$groups = array();
		foreach ( KDNA_SeoWriter_Keyphrases::all() as $row ) {
			$groups[ KDNA_SeoWriter_Rules::normalise( $row['keyphrase'] ) ][] = $row;
		}

		$out = array();
		foreach ( $groups as $rows ) {
			if ( count( $rows ) < 2 ) {
				continue;
			}
			foreach ( $rows as $row ) {
				/* translators: %1$s: keyphrase, %2$d: number of other items. */
				$out[] = self::problem( $row['object_type'], (int) $row['id'], sprintf( __( 'Keyphrase "%1$s" is also used on %2$d other items', 'kdna-seo-writer' ), $row['keyphrase'], count( $rows ) - 1 ), $row['subtype'] );
			}
		}
		return array_slice( $out, 0, self::LIST_LIMIT );
	}

	/**
	 * Lists published posts whose meta description is outside Yoast's range.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function description_lengths(): array {
		global $wpdb;

		$types = self::post_types();
		if ( ! $types ) {
			return array();
		}
		$type_list = implode( ',', array_map( static fn( $type ) => $wpdb->prepare( '%s', $type ), $types ) );
		$min       = (int) KDNA_SeoWriter_Settings::get( 'advanced', 'desc_min' );
		$max       = (int) KDNA_SeoWriter_Settings::get( 'advanced', 'desc_max' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Admin screen, $type_list is prepared above.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT pm.post_id, CHAR_LENGTH(pm.meta_value) AS chars FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_yoast_wpseo_metadesc' AND pm.meta_value <> '' AND p.post_status = 'publish' AND p.post_type IN ({$type_list}) AND ( CHAR_LENGTH(pm.meta_value) <= %d OR CHAR_LENGTH(pm.meta_value) > %d ) LIMIT %d", $min, $max, self::LIST_LIMIT ), ARRAY_A );

		$out = array();
		foreach ( (array) $rows as $row ) {
			/* translators: 1: number of characters, 2: minimum, 3: maximum. */
			$out[] = self::problem( 'post', (int) $row['post_id'], sprintf( __( 'Meta description is %1$d characters (Yoast wants %2$d to %3$d)', 'kdna-seo-writer' ), (int) $row['chars'], $min + 1, $max ) );
		}
		return $out;
	}

	/**
	 * Lists published posts whose SEO title is too wide or too narrow in Google.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function title_widths(): array {
		global $wpdb;

		$types = self::post_types();
		if ( ! $types ) {
			return array();
		}
		$type_list = implode( ',', array_map( static fn( $type ) => $wpdb->prepare( '%s', $type ), $types ) );
		$min       = (int) KDNA_SeoWriter_Settings::get( 'advanced', 'title_min_px' );
		$max       = (int) KDNA_SeoWriter_Settings::get( 'advanced', 'title_max_px' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Admin screen, $type_list is prepared above.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_yoast_wpseo_title' AND pm.meta_value <> '' AND p.post_status = 'publish' AND p.post_type IN ({$type_list}) LIMIT %d", self::WIDTH_LIMIT ), ARRAY_A );

		$out = array();
		foreach ( (array) $rows as $row ) {
			$width = KDNA_SeoWriter_Rules::title_width( KDNA_SeoWriter_Yoast::render_title( (string) $row['meta_value'], 'post', (int) $row['post_id'] ) );
			if ( $width >= $max || $width < $min ) {
				/* translators: 1: width in pixels, 2: minimum, 3: maximum. */
				$out[] = self::problem( 'post', (int) $row['post_id'], sprintf( __( 'SEO title is about %1$d pixels wide (Yoast wants %2$d to %3$d)', 'kdna-seo-writer' ), $width, $min, $max - 1 ) );
				if ( count( $out ) >= self::LIST_LIMIT ) {
					break;
				}
			}
		}
		return $out;
	}

	/**
	 * Builds one problem row.
	 *
	 * @param string $object_type Either post or term.
	 * @param int    $id          Post or term ID.
	 * @param string $issue       Plain-English description of the problem.
	 * @param string $subtype     Taxonomy, for terms.
	 * @return array<string, mixed>
	 */
	private static function problem( string $object_type, int $id, string $issue, string $subtype = '' ): array {
		if ( 'term' === $object_type ) {
			$term  = get_term( $id, $subtype );
			$title = $term instanceof WP_Term ? $term->name : '';
			$edit  = (string) get_edit_term_link( $id, $subtype );
		} else {
			$title = get_the_title( $id );
			$edit  = (string) get_edit_post_link( $id, 'url' );
		}

		return array(
			'key'      => $object_type . ':' . $id,
			'title'    => '' !== $title ? $title : __( '(no title)', 'kdna-seo-writer' ),
			'edit_url' => $edit,
			'issue'    => $issue,
		);
	}

	/**
	 * Returns the post type or taxonomy of an item.
	 *
	 * @param string $object_type Either post or term.
	 * @param int    $id          Post or term ID.
	 * @return string
	 */
	public static function subtype_of( string $object_type, int $id ): string {
		if ( 'term' === $object_type ) {
			$term = get_term( $id );
			return $term instanceof WP_Term ? $term->taxonomy : '';
		}
		return (string) get_post_type( $id );
	}

	/**
	 * Returns public post types that are not Off.
	 *
	 * @return array<int, string>
	 */
	private static function post_types(): array {
		return array_values(
			array_filter(
				get_post_types( array( 'public' => true ) ),
				static fn( $type ) => 'attachment' !== $type && KDNA_SeoWriter_Scheduler::enabled( 'post', $type )
			)
		);
	}

	/**
	 * Returns public taxonomies that are not Off.
	 *
	 * @return array<int, string>
	 */
	private static function taxonomies(): array {
		return array_values(
			array_filter(
				get_taxonomies(
					array(
						'public'  => true,
						'show_ui' => true,
					)
				),
				static fn( $taxonomy ) => 'post_format' !== $taxonomy && KDNA_SeoWriter_Scheduler::enabled( 'term', $taxonomy )
			)
		);
	}

	/**
	 * Formats a timestamp in the site's date and time format.
	 *
	 * @param int $timestamp Timestamp, or 0.
	 * @return string Empty for 0.
	 */
	private static function format_time( int $timestamp ): string {
		return $timestamp > 0 ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) : '';
	}
}
