<?php
/**
 * Change log with undo.
 *
 * @package KDNA_SeoWriter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Records every value the plugin writes into Yoast, and can put the old value back.
 *
 * Each row holds the field, the old value, the new value, what made the
 * change (automatic, approved, undo and so on), who did it and when. Rows for
 * token usage live in the same table with kind = usage.
 */
final class KDNA_SeoWriter_Log {

	/**
	 * Records one change.
	 *
	 * @param string $object_type Either post or term.
	 * @param int    $id          Post or term ID.
	 * @param string $subtype     Post type or taxonomy.
	 * @param string $field       Field name.
	 * @param string $old_value   Value before the change.
	 * @param string $new_value   Value after the change.
	 * @param string $source      What made the change, for example auto, approved, test or undo.
	 * @return int The new log row ID, or 0 if it could not be written.
	 */
	public static function change( string $object_type, int $id, string $subtype, string $field, string $old_value, string $new_value, string $source ): int {
		global $wpdb;

		$tables = KDNA_SeoWriter_Install::tables();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table.
		$ok = $wpdb->insert(
			$tables['log'],
			array(
				'kind'           => 'change',
				'object_type'    => 'term' === $object_type ? 'term' : 'post',
				'object_id'      => $id,
				'object_subtype' => sanitize_key( $subtype ),
				'field'          => sanitize_key( $field ),
				'old_value'      => $old_value,
				'new_value'      => $new_value,
				'source'         => sanitize_key( $source ),
				'user_id'        => get_current_user_id(),
				'created_at'     => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Returns one log row.
	 *
	 * @param int $log_id Row ID.
	 * @return array<string, mixed>|null
	 */
	public static function get( int $log_id ): ?array {
		global $wpdb;

		$tables = KDNA_SeoWriter_Install::tables();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table, name from $wpdb->prefix.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['log']} WHERE id = %d AND kind = 'change'", $log_id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Returns the most recent changes for one item, newest first.
	 *
	 * @param string $object_type Either post or term.
	 * @param int    $id          Post or term ID.
	 * @param int    $limit       How many rows to return.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_item( string $object_type, int $id, int $limit = 20 ): array {
		global $wpdb;

		$tables = KDNA_SeoWriter_Install::tables();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table, name from $wpdb->prefix.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$tables['log']} WHERE kind = 'change' AND object_type = %s AND object_id = %d ORDER BY id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$object_type,
				$id,
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Puts back the value a change replaced.
	 *
	 * The undo only goes ahead if the field still holds what that change
	 * wrote. If someone has edited the field since, their edit is kept and an
	 * explanation is returned instead.
	 *
	 * @param int $log_id Row ID of the change to undo.
	 * @return true|WP_Error
	 */
	public static function undo( int $log_id ) {
		global $wpdb;

		$row = self::get( $log_id );
		if ( ! $row ) {
			return new WP_Error( 'kdna_sw_log_missing', __( 'That change could not be found in the log.', 'kdna-seo-writer' ) );
		}
		if ( ! empty( $row['undone_at'] ) ) {
			return new WP_Error( 'kdna_sw_log_undone', __( 'That change has already been undone.', 'kdna-seo-writer' ) );
		}

		$type    = (string) $row['object_type'];
		$id      = (int) $row['object_id'];
		$subtype = (string) $row['object_subtype'];
		$field   = (string) $row['field'];

		if ( ! isset( KDNA_SeoWriter_Yoast::FIELDS[ $field ] ) ) {
			return new WP_Error( 'kdna_sw_log_field', __( 'That change is for a field the plugin no longer writes.', 'kdna-seo-writer' ) );
		}

		$current = KDNA_SeoWriter_Yoast::get( $type, $id, $field, $subtype );
		if ( trim( $current ) !== trim( (string) $row['new_value'] ) ) {
			return new WP_Error( 'kdna_sw_log_changed', __( 'This field has been edited since that change, so the edit has been kept and nothing was undone.', 'kdna-seo-writer' ) );
		}

		$old    = (string) $row['old_value'];
		$result = KDNA_SeoWriter_Yoast::set( $type, $id, array( $field => $old ), $subtype );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		self::change( $type, $id, $subtype, $field, $current, $old, 'undo' );
		KDNA_SeoWriter_State::forget( $type, $id, $field );

		$tables = KDNA_SeoWriter_Install::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table.
		$wpdb->update( $tables['log'], array( 'undone_at' => current_time( 'mysql', true ) ), array( 'id' => $log_id ), array( '%s' ), array( '%d' ) );

		KDNA_SeoWriter_Cache::purge_url( self::item_url( $type, $id ) );

		return true;
	}

	/**
	 * Returns the public address of an item.
	 *
	 * @param string $object_type Either post or term.
	 * @param int    $id          Post or term ID.
	 * @return string
	 */
	public static function item_url( string $object_type, int $id ): string {
		if ( 'term' === $object_type ) {
			$link = get_term_link( $id );
			return is_wp_error( $link ) ? '' : (string) $link;
		}
		return (string) get_permalink( $id );
	}
}
