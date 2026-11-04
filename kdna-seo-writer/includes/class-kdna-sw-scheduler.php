<?php
/**
 * Scheduler: background jobs on publish, the nightly sweep and the spend limit.
 *
 * @package KDNA_SeoWriter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Runs all of the plugin's work in the background through Action Scheduler.
 *
 * Nothing is ever sent to Claude while a page is saving or a visitor waits:
 * - When an item is published or updated with an empty field, a job is
 *   queued for two minutes later. The delay lets the editor finish saving
 *   (the block editor saves Yoast's box a moment after the post) and means a
 *   flurry of saves only creates one job.
 * - Each night at the sweep time, the sweep finds items with empty fields and
 *   queues up to the nightly limit, spaced 30 seconds apart.
 * - Generate now, Suggest improvements and Regenerate queue a job to run as
 *   soon as possible.
 *
 * Action Scheduler runs one job at a time by default. Automatic jobs pause
 * when the monthly spend limit is reached, and wait if the server is busy.
 * The plugin's own writes only change post meta, never the post itself, so
 * they cannot trigger another job.
 *
 * Anthropic's Message Batches API (half price, results within 24 hours) was
 * considered for the sweep and not used: each item may need a second request
 * after the rule checks, which would mean a second overnight batch, and at
 * Claude Haiku 5.5 prices a sweep of 50 items costs only a few cents.
 */
final class KDNA_SeoWriter_Scheduler {

	/**
	 * Action Scheduler hook for one item.
	 */
	public const JOB = 'kdna_sw_generate';

	/**
	 * Action Scheduler hook for the nightly sweep.
	 */
	public const SWEEP = 'kdna_sw_sweep';

	/**
	 * Action Scheduler group, so the plugin's jobs are easy to find under
	 * Tools > Scheduled Actions.
	 */
	public const GROUP = 'kdna-seo-writer';

	/**
	 * Seconds to wait after a save before the job runs.
	 */
	private const SAVE_DELAY = 120;

	/**
	 * Seconds between jobs queued by the nightly sweep.
	 */
	private const SWEEP_SPACING = 30;

	/**
	 * Items attempted more recently than this are left out of the sweep, so
	 * items that keep being skipped (too little content) do not use up the
	 * nightly limit every night.
	 */
	private const RETRY_AFTER = 7 * DAY_IN_SECONDS;

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public static function hooks(): void {
		add_action( self::JOB, array( __CLASS__, 'run_job' ), 10, 4 );
		add_action( self::SWEEP, array( __CLASS__, 'run_sweep' ) );
		add_action( 'wp_after_insert_post', array( __CLASS__, 'on_post_saved' ), 20, 2 );
		add_action( 'created_term', array( __CLASS__, 'on_term_saved' ), 20, 3 );
		add_action( 'edited_term', array( __CLASS__, 'on_term_saved' ), 20, 3 );
	}

	/**
	 * Tells you whether Action Scheduler is ready to take jobs.
	 *
	 * @return bool
	 */
	public static function available(): bool {
		return function_exists( 'as_schedule_single_action' ) && function_exists( 'as_has_scheduled_action' );
	}

	/**
	 * Queues a job for one item.
	 *
	 * @param string $object_type Either post or term.
	 * @param int    $id          Post or term ID.
	 * @param string $subtype     Post type or taxonomy.
	 * @param string $mode        auto, manual, suggest or regenerate.
	 * @param int    $delay       Seconds from now.
	 * @param string $group       Action Scheduler group. Fill empty fields now uses its own.
	 * @return bool True when a job was queued (or one was already waiting).
	 */
	public static function queue( string $object_type, int $id, string $subtype, string $mode, int $delay = 0, string $group = self::GROUP ): bool {
		if ( ! self::available() ) {
			return false;
		}

		// Checked across every group, so the nightly sweep and a Fill run never
		// book the same item twice.
		$args = array( $object_type, $id, $subtype, $mode );
		if ( as_has_scheduled_action( self::JOB, $args ) ) {
			return true;
		}

		as_schedule_single_action( time() + max( 0, $delay ), self::JOB, $args, $group );
		KDNA_SeoWriter_State::set_job( $object_type, $id, true );

		return true;
	}

	/**
	 * Queues a job when a post is published or updated with empty fields.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    The post.
	 * @return void
	 */
	public static function on_post_saved( $post_id, $post ): void {
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! KDNA_SeoWriter_Settings::get( 'automation', 'on_publish' ) || ! self::automatic_allowed() || ! self::enabled( 'post', $post->post_type ) ) {
			return;
		}
		if ( self::needs_work( 'post', (int) $post_id, $post->post_type ) ) {
			self::queue( 'post', (int) $post_id, $post->post_type, 'auto', self::SAVE_DELAY );
		}
	}

	/**
	 * Queues a job when a category, tag or other term is created or edited with empty fields.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID (unused).
	 * @param string $taxonomy Taxonomy.
	 * @return void
	 */
	public static function on_term_saved( $term_id, $tt_id, $taxonomy ): void {
		if ( ! KDNA_SeoWriter_Settings::get( 'automation', 'on_publish' ) || ! self::automatic_allowed() || ! self::enabled( 'term', (string) $taxonomy ) ) {
			return;
		}
		if ( self::needs_work( 'term', (int) $term_id, (string) $taxonomy ) ) {
			self::queue( 'term', (int) $term_id, (string) $taxonomy, 'auto', self::SAVE_DELAY );
		}
	}

	/**
	 * Runs one job: writes the metadata, then saves or queues each field.
	 *
	 * @param string $object_type Either post or term.
	 * @param int    $id          Post or term ID.
	 * @param string $subtype     Post type or taxonomy.
	 * @param string $mode        auto, manual, suggest or regenerate.
	 * @return void
	 */
	public static function run_job( $object_type, $id, $subtype = '', $mode = 'auto' ): void {
		$object_type = 'term' === $object_type ? 'term' : 'post';
		$id          = (int) $id;
		$subtype     = (string) $subtype;
		$mode        = in_array( $mode, array( 'auto', 'manual', 'suggest', 'regenerate', 'short' ), true ) ? $mode : 'auto';

		self::give_job_time();

		// Write anyway: Generate now for a page with less text than the
		// Minimum words setting, asked for by a person on the edit screen.
		if ( 'short' === $mode ) {
			$mode                                       = 'manual';
			KDNA_SeoWriter_Reader::$min_words_override = 0;
		}

		if ( 'auto' === $mode ) {
			if ( KDNA_SeoWriter_Usage::limit_reached() ) {
				KDNA_SeoWriter_State::set_last( $object_type, $id, 'paused', __( 'Paused: the monthly spend limit has been reached.', 'kdna-seo-writer' ) );
				return;
			}
			if ( self::server_busy() ) {
				// Try again in 15 minutes rather than adding to the load.
				self::later( self::JOB, array( $object_type, $id, $subtype, $mode ) );
				return;
			}
		}

		try {
			self::do_job( $object_type, $id, $subtype, $mode );
		} catch ( \Throwable $e ) {
			// A PHP error on one item is recorded against that item, instead of
			// the job vanishing as a silent failure in Action Scheduler.
			KDNA_SeoWriter_State::attempted( $object_type, $id );
			KDNA_SeoWriter_State::set_last( $object_type, $id, 'error', __( 'Something went wrong while writing for this item. Turn on debug mode and try Generate now to see the details.', 'kdna-seo-writer' ) );
			KDNA_SeoWriter_State::set_job( $object_type, $id, false );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'KDNA SEO Writer: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		} finally {
			KDNA_SeoWriter_Reader::$min_words_override = -1;
		}
	}

	/**
	 * The work of one job, after the spend limit and server load checks.
	 *
	 * @param string $object_type Either post or term.
	 * @param int    $id          Post or term ID.
	 * @param string $subtype     Post type or taxonomy.
	 * @param string $mode        auto, manual, suggest or regenerate.
	 * @return void
	 */
	private static function do_job( string $object_type, int $id, string $subtype, string $mode ): void {

		if ( ! self::enabled( $object_type, $subtype ) ) {
			KDNA_SeoWriter_State::set_last( $object_type, $id, 'nothing', __( 'This content type is set to Off in the Content tab.', 'kdna-seo-writer' ) );
			return;
		}

		// Images on the item that have no alt text get their own jobs, so a
		// slow image never holds up the page's metadata.
		if ( 'post' === $object_type ) {
			KDNA_SeoWriter_Alt_Text::queue_for_post( $id, $mode );
		}

		if ( 'auto' === $mode && ! self::needs_work( $object_type, $id, $subtype ) ) {
			KDNA_SeoWriter_State::attempted( $object_type, $id );
			KDNA_SeoWriter_State::set_last( $object_type, $id, 'nothing', __( 'Every field is already filled or locked, so nothing was needed.', 'kdna-seo-writer' ) );
			return;
		}

		$result = KDNA_SeoWriter_Writer::generate( $object_type, $id, $subtype );

		// A temporary problem (Anthropic busy or rate limited, or not reached)
		// does not count as a try, so the item is picked up again soon instead
		// of being left out of the sweep for a week.
		if ( empty( $result['temporary'] ) ) {
			KDNA_SeoWriter_State::attempted( $object_type, $id );
		}

		if ( in_array( $result['status'], array( 'skipped', 'error' ), true ) ) {
			KDNA_SeoWriter_State::set_last( $object_type, $id, $result['status'], (string) $result['message'] );
			return;
		}

		$routed = KDNA_SeoWriter_Queue::route( $object_type, $id, $subtype, $result, $mode );

		if ( '' !== $routed['error'] ) {
			KDNA_SeoWriter_State::set_last( $object_type, $id, 'error', $routed['error'] );
		} elseif ( $routed['saved'] && $routed['queued'] ) {
			KDNA_SeoWriter_State::set_last( $object_type, $id, 'saved', __( 'Empty fields were filled, and other suggestions are waiting in the Review tab.', 'kdna-seo-writer' ) );
		} elseif ( $routed['saved'] ) {
			KDNA_SeoWriter_State::set_last( $object_type, $id, 'saved', __( 'Empty fields were filled automatically.', 'kdna-seo-writer' ) );
		} elseif ( $routed['queued'] ) {
			KDNA_SeoWriter_State::set_last( $object_type, $id, 'queued', __( 'Suggestions are waiting for approval in the Review tab.', 'kdna-seo-writer' ) );
		} else {
			KDNA_SeoWriter_State::set_last( $object_type, $id, 'nothing', __( 'Claude\'s suggestions matched what is already saved, or were rejected before, so nothing new was added.', 'kdna-seo-writer' ) );
		}
	}

	/**
	 * Gives the job that is starting its own time allowance.
	 *
	 * Action Scheduler runs several jobs in one PHP request, and on many hosts
	 * that request is stopped after 30 seconds. One item can take longer than
	 * that (reading the page, then one or two answers from Claude), and if PHP
	 * stops part-way the job is lost and the item is left empty. Resetting the
	 * clock at the start of every job stops that. The time can be changed with
	 * the kdna_sw_job_time_limit filter.
	 *
	 * @return void
	 */
	public static function give_job_time(): void {
		if ( ! function_exists( 'set_time_limit' ) || str_contains( (string) ini_get( 'disable_functions' ), 'set_time_limit' ) ) {
			return;
		}
		if ( 0 === (int) ini_get( 'max_execution_time' ) ) {
			return; // Already unlimited.
		}
		@set_time_limit( max( 60, (int) apply_filters( 'kdna_sw_job_time_limit', 300 ) ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Books a job again in 15 minutes, because the server is busy.
	 *
	 * The job is booked directly rather than through queue(), because queue()
	 * sees the job that is running right now and would think it is already
	 * booked. It stays in the same group, so a Fill run waits for it.
	 *
	 * @param string       $hook Job hook.
	 * @param array<mixed> $args Job arguments.
	 * @return void
	 */
	public static function later( string $hook, array $args ): void {
		if ( ! self::available() ) {
			return;
		}
		as_schedule_single_action( time() + 15 * MINUTE_IN_SECONDS, $hook, $args, self::run_group() );
	}

	/**
	 * Returns the group new background jobs belong to: the Fill run's group
	 * while one is going, so the run counts them, otherwise the normal group.
	 *
	 * @return string
	 */
	public static function run_group(): string {
		return KDNA_SeoWriter_Fill::is_running() ? KDNA_SeoWriter_Fill::GROUP : self::GROUP;
	}

	/**
	 * Runs the nightly sweep, then books the next one.
	 *
	 * @return void
	 */
	public static function run_sweep(): void {
		self::schedule_sweep( true );

		if ( ! KDNA_SeoWriter_Settings::get( 'automation', 'nightly' ) || ! self::automatic_allowed() ) {
			return;
		}

		$limit = max( 0, (int) KDNA_SeoWriter_Settings::get( 'automation', 'per_night' ) );
		$items = self::sweep_candidates( $limit );

		foreach ( $items as $index => $item ) {
			self::queue( $item['type'], $item['id'], $item['subtype'], 'auto', $index * self::SWEEP_SPACING );
		}

		// Any nightly places left go to library images with no alt text,
		// featured images first.
		$offset = count( $items );
		foreach ( KDNA_SeoWriter_Alt_Text::sweep_candidates( $limit - $offset ) as $index => $image ) {
			KDNA_SeoWriter_Alt_Text::queue( $image['id'], $image['context'], 'auto', ( $offset + $index ) * self::SWEEP_SPACING );
		}
	}

	/**
	 * Books the next nightly sweep at the sweep time, in the site's time zone.
	 *
	 * A single job is booked each time (rather than a repeating one), so the
	 * time stays right when the clocks change for daylight saving.
	 *
	 * @param bool $replace True to cancel any booked sweep and book a fresh one.
	 * @return void
	 */
	public static function schedule_sweep( bool $replace = false ): void {
		if ( ! self::available() ) {
			return;
		}

		if ( $replace ) {
			as_unschedule_all_actions( self::SWEEP, array(), self::GROUP );
		}

		if ( ! KDNA_SeoWriter_Settings::get( 'automation', 'nightly' ) ) {
			as_unschedule_all_actions( self::SWEEP, array(), self::GROUP );
			return;
		}

		if ( ! as_has_scheduled_action( self::SWEEP, array(), self::GROUP ) ) {
			as_schedule_single_action( self::next_sweep_time(), self::SWEEP, array(), self::GROUP );
		}
	}

	/**
	 * Returns when the next sweep is booked, as a timestamp, or 0 if none.
	 *
	 * @return int
	 */
	public static function next_sweep(): int {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return 0;
		}
		$next = as_next_scheduled_action( self::SWEEP, array(), self::GROUP );
		return is_int( $next ) ? $next : 0;
	}

	/**
	 * Works out the next time the sweep should run.
	 *
	 * @return int Timestamp.
	 */
	public static function next_sweep_time(): int {
		$time = (string) KDNA_SeoWriter_Settings::get( 'automation', 'sweep_time' );
		$zone = wp_timezone();
		$now  = new DateTimeImmutable( 'now', $zone );
		$next = DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $now->format( 'Y-m-d' ) . ' ' . $time, $zone );

		if ( ! $next ) {
			$next = $now->setTime( 2, 0 );
		}
		if ( $next <= $now ) {
			$next = $next->modify( '+1 day' );
		}
		return $next->getTimestamp();
	}

	/**
	 * Finds items for the sweep: published, of a content type that is not Off,
	 * with at least one empty field, not waiting for review, and not tried in
	 * the last week. Items never tried come first.
	 *
	 * @param int      $limit  Most items to return.
	 * @param int|null $cutoff Leave out items tried at or after this time.
	 *                         Defaults to a week ago.
	 * @return array<int, array{type: string, id: int, subtype: string}>
	 */
	public static function sweep_candidates( int $limit, ?int $cutoff = null ): array {
		global $wpdb;

		if ( $limit < 1 ) {
			return array();
		}

		$out    = array();
		$fields = self::enabled_meta_keys();
		$tables = KDNA_SeoWriter_Install::tables();
		$cutoff = $cutoff ?? time() - self::RETRY_AFTER;

		$types = self::sweep_post_types();

		if ( $types && $fields ) {
			$empty_checks = array();
			foreach ( $fields as $key ) {
				$empty_checks[] = $wpdb->prepare( "NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} f WHERE f.post_id = p.ID AND f.meta_key = %s AND f.meta_value <> '' )", $key );
			}
			$type_list = implode( ',', array_map( static fn( $type ) => $wpdb->prepare( '%s', $type ), $types ) );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Background job; every part is prepared above or below.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT p.ID, p.post_type FROM {$wpdb->posts} p
					LEFT JOIN {$wpdb->postmeta} a ON a.post_id = p.ID AND a.meta_key = %s
					WHERE p.post_status = 'publish' AND p.post_password = '' AND p.post_type IN ({$type_list})
					AND ( " . implode( ' OR ', $empty_checks ) . " )
					AND ( a.meta_value IS NULL OR CAST( a.meta_value AS UNSIGNED ) < %d )
					AND NOT EXISTS ( SELECT 1 FROM {$tables['queue']} q WHERE q.object_type = 'post' AND q.object_id = p.ID AND q.status = 'pending' )
					ORDER BY CAST( COALESCE( a.meta_value, '0' ) AS UNSIGNED ) ASC, p.post_date DESC
					LIMIT %d",
					KDNA_SeoWriter_State::ATTEMPTED_KEY,
					$cutoff,
					$limit
				),
				ARRAY_A
			);
			// phpcs:enable

			foreach ( (array) $rows as $row ) {
				// The query cannot see locks: an item whose only empty fields
				// were emptied or edited by a person has nothing to do.
				if ( ! self::needs_work( 'post', (int) $row['ID'], (string) $row['post_type'] ) ) {
					continue;
				}
				$out[] = array(
					'type'    => 'post',
					'id'      => (int) $row['ID'],
					'subtype' => (string) $row['post_type'],
				);
			}
		}

		$left = $limit - count( $out );
		if ( $left > 0 ) {
			$out = array_merge( $out, self::term_candidates( $left, $cutoff ) );
		}

		return $out;
	}

	/**
	 * Finds categories, tags and other terms with empty fields, for the sweep.
	 *
	 * @param int $limit  Most items to return.
	 * @param int $cutoff Leave out terms attempted after this time.
	 * @return array<int, array{type: string, id: int, subtype: string}>
	 */
	private static function term_candidates( int $limit, int $cutoff ): array {
		$out = array();

		foreach ( self::sweep_taxonomies() as $taxonomy ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => true,
					'fields'     => 'ids',
					'number'     => 500,
				)
			);
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term_id ) {
				$term_id = (int) $term_id;
				if ( (int) get_term_meta( $term_id, KDNA_SeoWriter_State::ATTEMPTED_KEY, true ) >= $cutoff ) {
					continue;
				}
				if ( KDNA_SeoWriter_Queue::waiting_fields( 'term', array( $term_id ) ) || ! self::needs_work( 'term', $term_id, $taxonomy ) ) {
					continue;
				}
				$out[] = array(
					'type'    => 'term',
					'id'      => $term_id,
					'subtype' => $taxonomy,
				);
				if ( count( $out ) >= $limit ) {
					return $out;
				}
			}
		}

		return $out;
	}

	/**
	 * Returns the taxonomies the sweep covers: public, shown in wp-admin and
	 * not Off in the Content tab.
	 *
	 * @return array<int, string>
	 */
	public static function sweep_taxonomies(): array {
		return array_values(
			array_filter(
				get_taxonomies(
					array(
						'public'  => true,
						'show_ui' => true,
					)
				),
				static fn( $taxonomy ) => 'post_format' !== $taxonomy && self::enabled( 'term', $taxonomy )
			)
		);
	}

	/**
	 * Returns the post types the sweep covers: public and not Off.
	 *
	 * @return array<int, string>
	 */
	public static function sweep_post_types(): array {
		return array_values(
			array_filter(
				get_post_types( array( 'public' => true ) ),
				static fn( $type ) => 'attachment' !== $type && self::enabled( 'post', $type )
			)
		);
	}

	/**
	 * Tells you whether an item has an empty field that automation may fill.
	 *
	 * @param string $object_type Either post or term.
	 * @param int    $id          Post or term ID.
	 * @param string $subtype     Post type or taxonomy.
	 * @return bool
	 */
	public static function needs_work( string $object_type, int $id, string $subtype ): bool {
		$current = KDNA_SeoWriter_Yoast::get_all( $object_type, $id, $subtype );
		$state   = KDNA_SeoWriter_State::refresh_locks( $object_type, $id, $current );
		$enabled = (array) KDNA_SeoWriter_Settings::get( 'content', 'fields' );

		foreach ( KDNA_SeoWriter_State::GROUPS as $group => $fields ) {
			if ( empty( $enabled[ $group ] ) ) {
				continue;
			}
			foreach ( $fields as $field ) {
				if ( '' === trim( (string) ( $current[ $field ] ?? '' ) ) && empty( $state['locked'][ $field ] ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Tells you whether a content type is switched on (Automatic or Review).
	 *
	 * @param string $object_type Either post or term.
	 * @param string $subtype     Post type or taxonomy.
	 * @return bool
	 */
	public static function enabled( string $object_type, string $subtype ): bool {
		if ( '' === $subtype ) {
			return false;
		}
		$kind = 'term' === $object_type ? 'taxonomy' : 'post_type';
		return 'off' !== KDNA_SeoWriter_Settings::mode_for( $kind, $subtype );
	}

	/**
	 * Tells you whether automatic jobs may be queued at all right now.
	 *
	 * @return bool False when Yoast is missing, no key is saved or the spend
	 *              limit is reached.
	 */
	public static function automatic_allowed(): bool {
		return KDNA_SeoWriter_Yoast::is_active()
			&& KDNA_SeoWriter_Crypto::has_key()
			&& ! KDNA_SeoWriter_Usage::limit_reached();
	}

	/**
	 * Tells you whether the server is under heavy load.
	 *
	 * Uses the one-minute load average where the host provides it. The limit
	 * can be changed with the kdna_sw_max_load filter.
	 *
	 * @return bool
	 */
	public static function server_busy(): bool {
		if ( ! function_exists( 'sys_getloadavg' ) ) {
			return false;
		}
		$load = sys_getloadavg();
		if ( ! is_array( $load ) || ! isset( $load[0] ) ) {
			return false;
		}
		// Load is measured across the whole machine, so it is compared per
		// processor core. A shared host with 16 cores often runs at a load of
		// 8 or more while being perfectly responsive.
		$default = max( 4.0, 1.5 * self::cpu_cores() );
		return (float) $load[0] > (float) apply_filters( 'kdna_sw_max_load', $default );
	}

	/**
	 * Counts the server's processor cores, or 1 when the host does not say.
	 *
	 * @return int
	 */
	private static function cpu_cores(): int {
		static $cores = null;
		if ( null !== $cores ) {
			return $cores;
		}
		$cores = 1;
		$info  = @is_readable( '/proc/cpuinfo' ) ? @file_get_contents( '/proc/cpuinfo' ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( is_string( $info ) ) {
			$cores = max( 1, (int) preg_match_all( '/^processor\s*:/m', $info ) );
		}
		return $cores;
	}

	/**
	 * Returns Yoast's post meta keys for the fields switched on in the Content tab.
	 *
	 * @return array<int, string>
	 */
	public static function enabled_meta_keys(): array {
		$enabled = (array) KDNA_SeoWriter_Settings::get( 'content', 'fields' );
		$keys    = array();
		foreach ( KDNA_SeoWriter_State::GROUPS as $group => $fields ) {
			if ( empty( $enabled[ $group ] ) ) {
				continue;
			}
			foreach ( $fields as $field ) {
				$keys[] = '_yoast_wpseo_' . KDNA_SeoWriter_Yoast::FIELDS[ $field ]['post'];
			}
		}
		return $keys;
	}
}
