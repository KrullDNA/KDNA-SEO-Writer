<?php
/**
 * Install and upgrade: creates the plugin's two database tables.
 *
 * @package KDNA_SeoWriter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the review queue and log tables.
 *
 * The table layout has a version number. When a later version of the plugin
 * changes the layout, the number goes up and maybe_upgrade() brings the
 * tables up to date the next time an administrator visits wp-admin.
 */
final class KDNA_SeoWriter_Install {

	/**
	 * The current version of the table layout.
	 */
	public const DB_VERSION = '3';

	/**
	 * Where the installed table layout version is remembered.
	 */
	public const DB_VERSION_OPTION = 'kdna_sw_db_version';

	/**
	 * Runs when the plugin is activated: creates tables and default settings.
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::create_tables();
		KDNA_SeoWriter_Settings::install_defaults();
	}

	/**
	 * Brings the tables up to date if the plugin has been updated.
	 *
	 * This only reads one small option, so it is cheap to run on admin_init.
	 * It is skipped on admin-ajax requests, which some themes and plugins send
	 * from public pages, so those requests stay free of database work.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		if ( wp_doing_ajax() ) {
			return;
		}
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::create_tables();
			KDNA_SeoWriter_Settings::install_defaults();
		}
	}

	/**
	 * Returns the full table names with the site's database prefix.
	 *
	 * @return array{queue: string, log: string}
	 */
	public static function tables(): array {
		global $wpdb;
		return array(
			'queue' => $wpdb->prefix . 'kdna_sw_queue',
			'log'   => $wpdb->prefix . 'kdna_sw_log',
		);
	}

	/**
	 * Creates or updates both tables using WordPress's dbDelta helper.
	 *
	 * Queue: one row per suggested value waiting for review, plus rejected
	 * suggestions so they are not offered again.
	 *
	 * Log: one row per change written into Yoast (with the old value, so it can
	 * be undone), and one row per request to Claude with the tokens used, which
	 * the Overview turns into a monthly cost estimate.
	 *
	 * @return void
	 */
	public static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$tables  = self::tables();
		$charset = $wpdb->get_charset_collate();

		// dbDelta is fussy: two spaces after PRIMARY KEY, one field per line.
		$queue = "CREATE TABLE {$tables['queue']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			object_type varchar(20) NOT NULL DEFAULT 'post',
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			object_subtype varchar(64) NOT NULL DEFAULT '',
			field varchar(40) NOT NULL DEFAULT '',
			current_value longtext NULL,
			suggested_value longtext NULL,
			checks longtext NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			flag varchar(20) NOT NULL DEFAULT 'new',
			fingerprint char(32) NOT NULL DEFAULT '',
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY object_lookup (object_type,object_id,field),
			KEY status (status,flag),
			KEY fingerprint (fingerprint)
		) {$charset};";

		$log = "CREATE TABLE {$tables['log']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			kind varchar(20) NOT NULL DEFAULT 'change',
			object_type varchar(20) NOT NULL DEFAULT '',
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			object_subtype varchar(64) NOT NULL DEFAULT '',
			field varchar(40) NOT NULL DEFAULT '',
			old_value longtext NULL,
			new_value longtext NULL,
			source varchar(40) NOT NULL DEFAULT '',
			model varchar(64) NOT NULL DEFAULT '',
			input_tokens int(10) unsigned NOT NULL DEFAULT 0,
			output_tokens int(10) unsigned NOT NULL DEFAULT 0,
			cache_read_tokens int(10) unsigned NOT NULL DEFAULT 0,
			cache_write_tokens int(10) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			undone_at datetime NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY kind_date (kind,created_at),
			KEY object_lookup (object_type,object_id)
		) {$charset};";

		dbDelta( $queue );
		dbDelta( $log );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}
}
