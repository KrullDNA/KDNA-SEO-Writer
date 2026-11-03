<?php
/**
 * Review queue: suggestions waiting for approval, approvals and rejections.
 *
 * @package KDNA_SeoWriter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Decides what happens to each written field, and runs the review queue.
 *
 * Every field Claude writes ends up in one of three places:
 * - saved straight into Yoast (empty field, Automatic content type, all checks passed),
 * - the review queue (Review content types, replacements for existing text,
 *   locked fields the owner asked about, and anything that failed a check),
 * - nowhere (automatic jobs never touch fields that have text or are locked).
 *
 * The queue stores one row per field. Approved rows are saved through the same
 * writer as automatic saves and then removed (the change log keeps the
 * history). Rejected rows are kept, so the same suggestion is not offered again.
 */
final class KDNA_SeoWriter_Queue {

	/**
	 * Name of the cached count of items waiting, shown on the menu badge.
	 */
	private const COUNT_KEY = 'kdna_sw_queue_count';

	/**
	 * Which rule check field applies to each Yoast field.
	 */
	private const CHECK_FIELD = array(
		'focus_keyphrase'  => 'focus_keyphrase',
		'seo_title'        => 'seo_title',
		'meta_description' => 'meta_description',
		'og_title'         => 'og_title',
		'og_description'   => 'og_description',
		'x_title'          => 'og_title',
		'x_description'    => 'og_description',
		'alt_text'         => 'alt_text',
	);

	/**
	 * Saves or queues each field of a result, following the rules above.
	 *
	 * @param string               $object_type Either post or term.
	 * @param int                  $id          Post or term ID.
	 * @param string               $subtype     Post type or taxonomy.
	 * @param array<string, mixed> $result      Result from KDNA_SeoWriter_Writer::generate().
	 * @param string               $mode        auto (on publish, nightly), manual (Generate now),
	 *                                          suggest (Suggest improvements) or regenerate (from the queue).
	 * @return array{saved: array<int, string>, queued: array<int, string>, error: string}
	 */
	public static function route( string $object_type, int $id, string $subtype, array $result, string $mode ): array {
		$out = array(
			'saved'  => array(),
			'queued' => array(),
			'error'  => '',
		);

		$current      = KDNA_SeoWriter_Yoast::get_all( $object_type, $id, $subtype );
		$state        = KDNA_SeoWriter_State::refresh_locks( $object_type, $id, $current );
		$kind         = 'term' === $object_type ? 'taxonomy' : 'post_type';
		$content_mode = KDNA_SeoWriter_Settings::mode_for( $kind, $subtype );
		$passed       = 'ready' === $result['status'];

		// Only automatic jobs and Generate now may save without review, and only
		// for Automatic content types when every check passed.
		$may_save = $passed && 'auto' === $content_mode && in_array( $mode, array( 'auto', 'manual' ), true );

		// Suggest improvements and Regenerate always go to review, even for empty fields.
		$owner_asked = in_array( $mode, array( 'manual', 'suggest', 'regenerate' ), true );

		$to_save = array();
		foreach ( (array) $result['values'] as $field => $value ) {
			$value  = trim( (string) $value );
			$now    = trim( (string) ( $current[ $field ] ?? '' ) );
			$locked = ! empty( $state['locked'][ $field ] );

			if ( '' === $value || $now === $value ) {
				continue;
			}

			if ( '' === $now && ! $locked ) {
				if ( $may_save ) {
					$to_save[ $field ] = $value;
				} elseif ( self::add( $object_type, $id, $subtype, $field, $now, $value, (array) $result['checks'], $locked ) ) {
					$out['queued'][] = $field;
				}
				continue;
			}

			// The field has text, or a person has locked it. Automation leaves it
			// alone. When the owner asked, a replacement is suggested for review.
			if ( $owner_asked && self::add( $object_type, $id, $subtype, $field, $now, $value, (array) $result['checks'], $locked ) ) {
				$out['queued'][] = $field;
			}
		}

		if ( $to_save ) {
			$saved = KDNA_SeoWriter_Writer::save( $object_type, $id, $subtype, $to_save, 'auto', true );
			if ( is_wp_error( $saved ) ) {
				$out['error'] = $saved->get_error_message();
			} else {
				$out['saved'] = $saved;
			}
		}

		return $out;
	}

	/**
	 * Adds one suggestion to the queue, unless the same one was rejected before.
	 *
	 * Any older suggestion still waiting for the same field is replaced.
	 *
	 * @param string                           $object_type Either post or term.
	 * @param int                              $id          Post or term ID.
	 * @param string                           $subtype     Post type or taxonomy.
	 * @param string                           $field       Field name.
	 * @param string                           $current     Value in Yoast now.
	 * @param string                           $suggested   Suggested value.
	 * @param array<int, array<string, mixed>> $checks      All checks from the writer.
	 * @param bool                             $locked      Whether the field is locked.
	 * @param int                              $context_id  For images, the page the alt text was written for.
	 * @return bool True when the suggestion was added.
	 */
	public static function add( string $object_type, int $id, string $subtype, string $field, string $current, string $suggested, array $checks, bool $locked = false, int $context_id = 0 ): bool {
		global $wpdb;

		$tables      = KDNA_SeoWriter_Install::tables();
		$fingerprint = self::fingerprint( $field, $suggested );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table, name from $wpdb->prefix.
		$rejected = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$tables['queue']} WHERE object_type = %s AND object_id = %d AND field = %s AND status = 'rejected' AND fingerprint = %s",
				$object_type,
				$id,
				$field,
				$fingerprint
			)
		);
		if ( $rejected > 0 ) {
			return false;
		}

		$wpdb->delete(
			$tables['queue'],
			array(
				'object_type' => $object_type,
				'object_id'   => $id,
				'field'       => $field,
				'status'      => 'pending',
			),
			array( '%s', '%d', '%s', '%s' )
		);

		$field_checks = self::checks_for( $field, $checks );
		$failed       = (bool) array_filter( $field_checks, static fn( $check ) => empty( $check['ok'] ) );
		$flag         = $failed ? 'failed' : ( ( '' !== $current || $locked ) ? 'attention' : 'new' );
		$now          = current_time( 'mysql', true );

		$wpdb->insert(
			$tables['queue'],
			array(
				'object_type'     => $object_type,
				'object_id'       => $id,
				'object_subtype'  => sanitize_key( $subtype ),
				'context_id'      => $context_id,
				'field'           => $field,
				'current_value'   => $current,
				'suggested_value' => $suggested,
				'checks'          => wp_json_encode( $field_checks ),
				'status'          => 'pending',
				'flag'            => $flag,
				'fingerprint'     => $fingerprint,
				'created_by'      => get_current_user_id(),
				'created_at'      => $now,
				'updated_at'      => $now,
			),
			array( '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		// phpcs:enable

		self::flush_count();
		return true;
	}

	/**
	 * Returns one page of items waiting for review, newest first.
	 *
	 * @param array<string, string> $filters subtype (content type), field and flag (new, attention, failed).
	 * @param int                   $page    Page number, from 1.
	 * @param int                   $per     Items per page.
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public static function items( array $filters, int $page = 1, int $per = 20 ): array {
		global $wpdb;

		$tables = KDNA_SeoWriter_Install::tables();
		$where  = self::where( $filters );
		$offset = max( 0, ( $page - 1 ) * $per );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Custom plugin table; $where is built from prepared parts.
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM ( SELECT 1 FROM {$tables['queue']} WHERE {$where} GROUP BY object_type, object_id ) grouped" );
		$keys  = $wpdb->get_results(
			$wpdb->prepare( "SELECT object_type, object_id, MAX(id) AS latest FROM {$tables['queue']} WHERE {$where} GROUP BY object_type, object_id ORDER BY latest DESC LIMIT %d OFFSET %d", $per, $offset ),
			ARRAY_A
		);
		// phpcs:enable

		$items = array();
		foreach ( (array) $keys as $key ) {
			$items[] = self::item( (string) $key['object_type'], (int) $key['object_id'], $filters );
		}

		return array(
			'items' => array_values( array_filter( $items ) ),
			'total' => $total,
		);
	}

	/**
	 * Returns every item key ("post:42", "term:7") matching the filters, for bulk approval.
	 *
	 * @param array<string, string> $filters Same filters as items().
	 * @param int                   $limit   Most keys to return.
	 * @return array<int, string>
	 */
	public static function matching_keys( array $filters, int $limit = 2000 ): array {
		global $wpdb;

		$tables = KDNA_SeoWriter_Install::tables();
		$where  = self::where( $filters );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Custom plugin table; $where is built from prepared parts.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT object_type, object_id, MAX(id) AS latest FROM {$tables['queue']} WHERE {$where} GROUP BY object_type, object_id ORDER BY latest DESC LIMIT %d", $limit ), ARRAY_A );

		return array_map( static fn( $row ) => $row['object_type'] . ':' . (int) $row['object_id'], (array) $rows );
	}

	/**
	 * Builds one item for the review screen, with live current values.
	 *
	 * @param string                $object_type Either post or term.
	 * @param int                   $id          Post or term ID.
	 * @param array<string, string> $filters     Field and flag filters to apply to its rows.
	 * @return array<string, mixed>|null
	 */
	private static function item( string $object_type, int $id, array $filters ): ?array {
		$rows = self::rows_for( $object_type, $id, $filters );
		if ( ! $rows ) {
			return null;
		}

		if ( 'attachment' === $object_type ) {
			return self::image_item( $id, $rows );
		}

		$subtype = (string) $rows[0]['object_subtype'];
		$current = KDNA_SeoWriter_Yoast::get_all( $object_type, $id, $subtype );
		$state   = KDNA_SeoWriter_State::get( $object_type, $id );
		$suffix  = KDNA_SeoWriter_Yoast::render_title( KDNA_SeoWriter_Yoast::title_suffix( KDNA_SeoWriter_Yoast::title_template( $object_type, $subtype ) ), $object_type, $id );

		if ( 'term' === $object_type ) {
			$term  = get_term( $id, $subtype );
			$title = $term instanceof WP_Term ? $term->name : '';
			$edit  = (string) get_edit_term_link( $id, $subtype );
			$type  = get_taxonomy( $subtype );
		} else {
			$title = get_the_title( $id );
			$edit  = (string) get_edit_post_link( $id, 'url' );
			$type  = get_post_type_object( $subtype );
		}

		$fields = array();
		foreach ( $rows as $row ) {
			$field     = (string) $row['field'];
			$suggested = (string) $row['suggested_value'];
			$fields[]  = array(
				'id'        => (int) $row['id'],
				'field'     => $field,
				'label'     => KDNA_SeoWriter_Rules::field_label( $field ),
				'current'   => (string) ( $current[ $field ] ?? '' ),
				'suggested' => 'seo_title' === $field ? KDNA_SeoWriter_Rules::strip_variables( $suggested ) : $suggested,
				'flag'      => (string) $row['flag'],
				'locked'    => ! empty( $state['locked'][ $field ] ),
				'checks'    => json_decode( (string) $row['checks'], true ) ?: array(),
			);
		}

		return array(
			'key'          => $object_type . ':' . $id,
			'object_type'  => $object_type,
			'id'           => $id,
			'subtype'      => $subtype,
			'type_label'   => $type && isset( $type->labels->singular_name ) ? (string) $type->labels->singular_name : $subtype,
			'title'        => '' !== $title ? $title : __( '(no title)', 'kdna-seo-writer' ),
			'edit_url'     => $edit,
			'view_url'     => KDNA_SeoWriter_Log::item_url( $object_type, $id ),
			'title_suffix' => $suffix,
			'keyphrase'    => (string) ( $current['focus_keyphrase'] ?? '' ),
			'regenerating' => KDNA_SeoWriter_State::job_waiting( $object_type, $id ),
			'fields'       => $fields,
		);
	}

	/**
	 * Builds one image item for the review screen, with its thumbnail.
	 *
	 * @param int                              $id   Attachment ID.
	 * @param array<int, array<string, mixed>> $rows The image's waiting rows.
	 * @return array<string, mixed>
	 */
	private static function image_item( int $id, array $rows ): array {
		$context = (int) $rows[0]['context_id'];
		$title   = get_the_title( $id );
		$fields  = array();

		foreach ( $rows as $row ) {
			$fields[] = array(
				'id'        => (int) $row['id'],
				'field'     => 'alt_text',
				'label'     => KDNA_SeoWriter_Rules::field_label( 'alt_text' ),
				'current'   => KDNA_SeoWriter_Alt_Text::get_alt( $id ),
				'suggested' => (string) $row['suggested_value'],
				'flag'      => (string) $row['flag'],
				'locked'    => false,
				'checks'    => json_decode( (string) $row['checks'], true ) ?: array(),
			);
		}

		return array(
			'key'          => 'attachment:' . $id,
			'object_type'  => 'attachment',
			'id'           => $id,
			'subtype'      => 'attachment',
			'type_label'   => __( 'Image', 'kdna-seo-writer' ),
			'title'        => '' !== $title ? $title : wp_basename( (string) get_attached_file( $id ) ),
			'edit_url'     => (string) get_edit_post_link( $id, 'url' ),
			'view_url'     => $context ? (string) get_permalink( $context ) : (string) wp_get_attachment_url( $id ),
			'context'      => $context ? get_the_title( $context ) : '',
			'thumbnail'    => (string) wp_get_attachment_image_url( $id, 'medium' ),
			'title_suffix' => '',
			'keyphrase'    => '',
			'regenerating' => KDNA_SeoWriter_State::job_waiting( 'post', $id ),
			'fields'       => $fields,
		);
	}

	/**
	 * Approves suggestions, optionally with edited text, and saves them into Yoast.
	 *
	 * Text edited by a person before approval is theirs, so those fields are
	 * locked against automation afterwards.
	 *
	 * @param array<int, int>    $row_ids Queue row IDs.
	 * @param array<int, string> $edits   Row ID to edited text, for edit then approve.
	 * @return array{approved: int, errors: array<int, string>}
	 */
	public static function approve( array $row_ids, array $edits = array() ): array {
		$out = array(
			'approved' => 0,
			'errors'   => array(),
		);

		$by_item = array();
		foreach ( self::rows_by_id( $row_ids ) as $row ) {
			$by_item[ $row['object_type'] . ':' . $row['object_id'] ][] = $row;
		}

		foreach ( $by_item as $rows ) {
			$type    = (string) $rows[0]['object_type'];
			$id      = (int) $rows[0]['object_id'];
			$subtype = (string) $rows[0]['object_subtype'];

			if ( ! self::can_edit( $type, $id ) ) {
				$out['errors'][] = __( 'You are not allowed to edit one of the items, so it was skipped.', 'kdna-seo-writer' );
				continue;
			}

			// Image alt text is saved into the media library, not Yoast.
			if ( 'attachment' === $type ) {
				foreach ( $rows as $row ) {
					$edit   = isset( $edits[ (int) $row['id'] ] ) ? trim( sanitize_text_field( (string) $edits[ (int) $row['id'] ] ) ) : '';
					$edited = '' !== $edit && $edit !== (string) $row['suggested_value'];
					KDNA_SeoWriter_Alt_Text::save( $id, $edited ? $edit : (string) $row['suggested_value'], $edited ? 'edited' : 'approved', (int) $row['context_id'] );
				}
				self::delete_rows( array_map( static fn( $row ) => (int) $row['id'], $rows ) );
				$out['approved'] += count( $rows );
				continue;
			}

			$values = array();
			$edited = array();
			foreach ( $rows as $row ) {
				$field = (string) $row['field'];
				$value = (string) $row['suggested_value'];

				if ( isset( $edits[ (int) $row['id'] ] ) ) {
					$text = trim( sanitize_text_field( (string) $edits[ (int) $row['id'] ] ) );
					$was  = 'seo_title' === $field ? KDNA_SeoWriter_Rules::strip_variables( $value ) : $value;
					if ( '' !== $text && $text !== $was ) {
						$value    = 'seo_title' === $field ? KDNA_SeoWriter_Yoast::build_seo_title( $text, $type, $subtype ) : $text;
						$edited[] = $field;
					}
				}
				$values[ $field ] = $value;
			}

			// Fields approved as written and fields edited first are logged
			// separately, so the change log shows which words were a person's.
			$as_written = array_diff_key( $values, array_flip( $edited ) );
			$by_person  = array_intersect_key( $values, array_flip( $edited ) );
			$failed     = false;
			foreach ( array( 'approved' => $as_written, 'edited' => $by_person ) as $source => $batch ) {
				if ( ! $batch ) {
					continue;
				}
				$saved = KDNA_SeoWriter_Writer::save( $type, $id, $subtype, $batch, $source, false );
				if ( is_wp_error( $saved ) ) {
					$out['errors'][] = $saved->get_error_message();
					$failed          = true;
					break;
				}
			}
			if ( $failed ) {
				continue;
			}

			if ( $edited ) {
				KDNA_SeoWriter_State::lock( $type, $id, $edited );
			}

			self::delete_rows( array_map( static fn( $row ) => (int) $row['id'], $rows ) );
			$out['approved'] += count( $rows );
		}

		self::flush_count();
		return $out;
	}

	/**
	 * Approves every waiting suggestion for the given items.
	 *
	 * @param array<int, string> $keys Item keys such as post:42.
	 * @return array{approved: int, errors: array<int, string>}
	 */
	public static function approve_items( array $keys ): array {
		return self::approve( self::row_ids_for_keys( $keys ) );
	}

	/**
	 * Rejects suggestions. They are kept so the same text is not offered again.
	 *
	 * @param array<int, int> $row_ids Queue row IDs.
	 * @return int Number rejected.
	 */
	public static function reject( array $row_ids ): int {
		global $wpdb;

		$tables = KDNA_SeoWriter_Install::tables();
		$count  = 0;

		foreach ( self::rows_by_id( $row_ids ) as $row ) {
			if ( ! self::can_edit( (string) $row['object_type'], (int) $row['object_id'] ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table.
			$count += (int) $wpdb->update(
				$tables['queue'],
				array(
					'status'     => 'rejected',
					'updated_at' => current_time( 'mysql', true ),
				),
				array( 'id' => (int) $row['id'] ),
				array( '%s', '%s' ),
				array( '%d' )
			);
		}

		self::flush_count();
		return $count;
	}

	/**
	 * Rejects every waiting suggestion for the given items.
	 *
	 * @param array<int, string> $keys Item keys such as post:42.
	 * @return int Number rejected.
	 */
	public static function reject_items( array $keys ): int {
		return self::reject( self::row_ids_for_keys( $keys ) );
	}

	/**
	 * Clears an item's waiting suggestions and asks Claude for fresh ones.
	 *
	 * @param array<int, string> $keys Item keys such as post:42.
	 * @return int Number of items queued.
	 */
	public static function regenerate( array $keys ): int {
		$count = 0;
		foreach ( $keys as $key ) {
			$item = self::parse_key( $key );
			if ( ! $item || ! self::can_edit( $item['type'], $item['id'] ) ) {
				continue;
			}
			$rows = self::rows_for( $item['type'], $item['id'], array() );
			if ( ! $rows ) {
				continue;
			}
			self::delete_rows( array_map( static fn( $row ) => (int) $row['id'], $rows ) );
			if ( 'attachment' === $item['type'] ) {
				KDNA_SeoWriter_Alt_Text::queue( $item['id'], (int) $rows[0]['context_id'], 'regenerate' );
			} else {
				KDNA_SeoWriter_Scheduler::queue( $item['type'], $item['id'], (string) $rows[0]['object_subtype'], 'regenerate' );
			}
			++$count;
		}
		self::flush_count();
		return $count;
	}

	/**
	 * Returns how many items have suggestions waiting, for the menu badge.
	 *
	 * @return int
	 */
	public static function count_items(): int {
		$cached = get_transient( self::COUNT_KEY );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		global $wpdb;
		$tables = KDNA_SeoWriter_Install::tables();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table.
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM ( SELECT 1 FROM {$tables['queue']} WHERE status = 'pending' GROUP BY object_type, object_id ) grouped" );

		set_transient( self::COUNT_KEY, $count, HOUR_IN_SECONDS );
		return $count;
	}

	/**
	 * Returns which fields have suggestions waiting, for several items at once.
	 *
	 * Used by the list screens' status column, so one query covers a whole page.
	 *
	 * @param string          $object_type Either post or term.
	 * @param array<int, int> $ids         Post or term IDs.
	 * @return array<int, array<int, string>> ID to field names.
	 */
	public static function waiting_fields( string $object_type, array $ids ): array {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( ! $ids ) {
			return array();
		}

		$tables       = KDNA_SeoWriter_Install::tables();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Custom plugin table, placeholders built above.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT object_id, field FROM {$tables['queue']} WHERE status = 'pending' AND object_type = %s AND object_id IN ({$placeholders})", array_merge( array( $object_type ), $ids ) ), ARRAY_A );

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['object_id'] ][] = (string) $row['field'];
		}
		return $out;
	}

	/**
	 * Clears the cached count after the queue changes.
	 *
	 * @return void
	 */
	public static function flush_count(): void {
		delete_transient( self::COUNT_KEY );
	}

	/**
	 * Builds the WHERE clause for the queue filters.
	 *
	 * @param array<string, string> $filters subtype, field and flag.
	 * @return string SQL, already escaped.
	 */
	private static function where( array $filters ): string {
		global $wpdb;

		$parts = array( "status = 'pending'" );
		if ( ! empty( $filters['subtype'] ) ) {
			$parts[] = $wpdb->prepare( 'object_subtype = %s', sanitize_key( $filters['subtype'] ) );
		}
		if ( ! empty( $filters['field'] ) ) {
			$fields = 'social' === $filters['field'] ? array( 'og_title', 'og_description', 'x_title', 'x_description' ) : array( sanitize_key( $filters['field'] ) );
			$parts[] = 'field IN (' . implode( ',', array_map( static fn( $field ) => $wpdb->prepare( '%s', $field ), $fields ) ) . ')';
		}
		if ( ! empty( $filters['flag'] ) && in_array( $filters['flag'], array( 'new', 'attention', 'failed' ), true ) ) {
			$parts[] = $wpdb->prepare( 'flag = %s', $filters['flag'] );
		}
		return implode( ' AND ', $parts );
	}

	/**
	 * Returns an item's waiting rows, matching any field and flag filters.
	 *
	 * @param string                $object_type Either post or term.
	 * @param int                   $id          Post or term ID.
	 * @param array<string, string> $filters     Filters.
	 * @return array<int, array<string, mixed>>
	 */
	private static function rows_for( string $object_type, int $id, array $filters ): array {
		global $wpdb;

		$tables = KDNA_SeoWriter_Install::tables();
		$where  = self::where( array_intersect_key( $filters, array_flip( array( 'field', 'flag' ) ) ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Custom plugin table; $where is built from prepared parts.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tables['queue']} WHERE {$where} AND object_type = %s AND object_id = %d ORDER BY id ASC", $object_type, $id ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Returns waiting rows by ID.
	 *
	 * @param array<int, int> $row_ids Row IDs.
	 * @return array<int, array<string, mixed>>
	 */
	private static function rows_by_id( array $row_ids ): array {
		global $wpdb;

		$row_ids = array_values( array_filter( array_map( 'absint', $row_ids ) ) );
		if ( ! $row_ids ) {
			return array();
		}

		$tables       = KDNA_SeoWriter_Install::tables();
		$placeholders = implode( ',', array_fill( 0, count( $row_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Custom plugin table, placeholders built above.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tables['queue']} WHERE status = 'pending' AND id IN ({$placeholders})", $row_ids ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Returns the waiting row IDs for some items.
	 *
	 * @param array<int, string> $keys Item keys such as post:42.
	 * @return array<int, int>
	 */
	private static function row_ids_for_keys( array $keys ): array {
		$ids = array();
		foreach ( $keys as $key ) {
			$item = self::parse_key( (string) $key );
			if ( $item ) {
				foreach ( self::rows_for( $item['type'], $item['id'], array() ) as $row ) {
					$ids[] = (int) $row['id'];
				}
			}
		}
		return $ids;
	}

	/**
	 * Deletes rows by ID.
	 *
	 * @param array<int, int> $row_ids Row IDs.
	 * @return void
	 */
	private static function delete_rows( array $row_ids ): void {
		global $wpdb;

		$tables = KDNA_SeoWriter_Install::tables();
		foreach ( $row_ids as $row_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table.
			$wpdb->delete( $tables['queue'], array( 'id' => (int) $row_id ), array( '%d' ) );
		}
	}

	/**
	 * Picks out the checks that matter for one field.
	 *
	 * @param string                           $field  Field name.
	 * @param array<int, array<string, mixed>> $checks All checks.
	 * @return array<int, array<string, mixed>>
	 */
	private static function checks_for( string $field, array $checks ): array {
		$wanted = self::CHECK_FIELD[ $field ] ?? $field;
		return array_values( array_filter( $checks, static fn( $check ) => ( $check['field'] ?? '' ) === $wanted ) );
	}

	/**
	 * Splits an item key such as post:42 into its parts.
	 *
	 * @param string $key Item key.
	 * @return array{type: string, id: int}|null
	 */
	public static function parse_key( string $key ): ?array {
		if ( ! preg_match( '/^(post|term|attachment):(\d+)$/', $key, $match ) ) {
			return null;
		}
		return array(
			'type' => $match[1],
			'id'   => (int) $match[2],
		);
	}

	/**
	 * Tells you whether the current user may change an item's SEO data.
	 *
	 * @param string $object_type Either post or term.
	 * @param int    $id          Post or term ID.
	 * @return bool
	 */
	public static function can_edit( string $object_type, int $id ): bool {
		if ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return true;
		}
		return 'term' === $object_type ? current_user_can( 'edit_term', $id ) : current_user_can( 'edit_post', $id );
	}

	/**
	 * Returns the fingerprint used to remember a rejected suggestion.
	 *
	 * @param string $field Field name.
	 * @param string $value Suggested value.
	 * @return string
	 */
	private static function fingerprint( string $field, string $value ): string {
		return md5( $field . "\n" . KDNA_SeoWriter_Rules::normalise( $value ) );
	}
}
