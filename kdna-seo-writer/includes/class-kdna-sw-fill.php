<?php
/**
 * Fill empty fields now: the first full run over a whole site.
 *
 * @package KDNA_SeoWriter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Queues every item with empty fields, and every image with no alt text, in
 * one go, instead of waiting for the nightly sweep to get through them 50 at a
 * time. Used for the first run on a new site.
 *
 * It behaves exactly like the nightly sweep:
 * - only empty fields are filled, existing and locked text is left alone,
 * - Automatic content types save straight away, everything else waits in
 *   the Review tab, as does all alt text,
 * - jobs run one at a time, spaced out, and pause at the spend limit.
 *
 * After each pass a check job looks for anything still empty that was not
 * tried (a job the server stopped part-way, or one Anthropic was too busy
 * for) and queues it again, up to five passes, so one click covers the site.
 *
 * The jobs use their own Action Scheduler group, so the run's progress can be
 * counted and it can be stopped without touching any other job.
 */
final class KDNA_SeoWriter_Fill {

	/**
	 * Action Scheduler group for this run's jobs.
	 */
	public const GROUP = 'kdna-seo-writer-fill';

	/**
	 * Option holding the current or last run.
	 */
	private const OPTION = 'kdna_sw_fill';

	/**
	 * Most items and most images queued by one pass.
	 */
	public const LIMIT = 2000;

	/**
	 * Seconds between jobs, so the site is never busy with more than one.
	 */
	private const SPACING = 10;

	/**
	 * Job that runs after each pass, to go back for anything the pass missed.
	 */
	public const CHECK = 'kdna_sw_fill_check';

	/**
	 * Most passes in one run. Items done or tried are left out of the next
	 * pass, so later passes only pick up jobs that did not finish (the server
	 * stopped them, or Anthropic was busy).
	 */
	public const PASSES = 5;

	/**
	 * Seconds to wait after a pass's last job before checking.
	 */
	private const CHECK_DELAY = 120;

	/**
	 * Typical tokens for one item (with an allowance for the second try some
	 * items need) and for one image, used for the cost estimate only.
	 */
	private const ITEM_TOKENS  = array( 3900, 1200 );
	private const IMAGE_TOKENS = array( 2000, 180 );

	/**
	 * Registers the job that checks each pass.
	 *
	 * @return void
	 */
	public static function hooks(): void {
		add_action( self::CHECK, array( __CLASS__, 'check' ) );
	}

	/**
	 * Returns what a run would cover, and roughly what it would cost.
	 *
	 * @param bool $retry Also include items and images tried in the last week.
	 * @return array<string, mixed>
	 */
	public static function preview( bool $retry = false ): array {
		$cutoff = $retry ? time() : null;
		$items  = KDNA_SeoWriter_Scheduler::sweep_candidates( self::LIMIT, $cutoff );
		$images = KDNA_SeoWriter_Alt_Text::sweep_candidates( self::LIMIT, $cutoff );

		$types = array();
		foreach ( $items as $item ) {
			$types[ $item['subtype'] ] = ( $types[ $item['subtype'] ] ?? 0 ) + 1;
		}

		$by_type = array();
		foreach ( $types as $subtype => $count ) {
			$object    = taxonomy_exists( $subtype ) ? get_taxonomy( $subtype ) : get_post_type_object( $subtype );
			$by_type[] = array(
				'label' => $object && isset( $object->labels->name ) ? (string) $object->labels->name : $subtype,
				'count' => $count,
			);
		}

		$model    = KDNA_SeoWriter_Claude::model();
		$estimate = count( $items ) * KDNA_SeoWriter_Usage::cost( $model, self::ITEM_TOKENS[0], self::ITEM_TOKENS[1], 0, 0 )
			+ count( $images ) * KDNA_SeoWriter_Usage::cost( $model, self::IMAGE_TOKENS[0], self::IMAGE_TOKENS[1], 0, 0 );

		$limit = (float) KDNA_SeoWriter_Settings::get( 'connection', 'spend_limit' );
		$spent = KDNA_SeoWriter_Usage::this_month()['cost'];

		return array(
			'items'      => count( $items ),
			'images'     => count( $images ),
			'by_type'    => $by_type,
			// Rounded up to the next cent, so a small run never shows as US$0.00.
			'estimate'   => $estimate > 0 ? ceil( $estimate * 100 ) / 100 : 0.0,
			'over_limit' => $limit > 0 && $spent + $estimate > $limit,
			'capped'     => count( $items ) >= self::LIMIT || count( $images ) >= self::LIMIT,
			'retry'      => $retry,
			'left_out'   => self::left_out( $items, $cutoff ),
			'health'     => self::job_health(),
		);
	}

	/**
	 * Queues the run.
	 *
	 * @param bool $retry Also include items and images tried in the last week.
	 * @return array<string, mixed>|WP_Error The run's status, or an error.
	 */
	public static function start( bool $retry = false ) {
		$problem = self::cannot_start();
		if ( '' !== $problem ) {
			return new WP_Error( 'kdna_sw_fill_blocked', $problem, array( 'status' => 400 ) );
		}
		if ( self::status()['running'] ) {
			return new WP_Error( 'kdna_sw_fill_running', __( 'A run is already going. Wait for it to finish, or stop it first.', 'kdna-seo-writer' ), array( 'status' => 400 ) );
		}

		$started = time();
		$pass    = self::queue_pass( $retry ? $started : null );

		if ( 0 === $pass['total'] ) {
			return new WP_Error( 'kdna_sw_fill_nothing', __( 'Nothing was started: every item and image is filled, already queued, waiting for review or was tried recently. The "Left out" list under Check what needs doing says why for each one.', 'kdna-seo-writer' ), array( 'status' => 400 ) );
		}

		update_option(
			self::OPTION,
			array(
				'started' => $started,
				'retry'   => $retry,
				'total'   => $pass['total'],
				'items'   => $pass['items'],
				'images'  => $pass['images'],
				'pass'    => 1,
				'stopped' => 0,
				'done'    => 0,
				'left'    => 0,
				'user'    => get_current_user_id(),
			),
			false
		);

		self::book_check( $pass['total'] );

		return self::status();
	}

	/**
	 * Queues one pass: every item with empty fields and every image with no
	 * alt text that has not been tried recently and is not already booked.
	 *
	 * @param int|null $cutoff Leave out anything tried at or after this time
	 *                         (null for the usual week).
	 * @return array{total: int, items: int, images: int}
	 */
	private static function queue_pass( ?int $cutoff = null ): array {
		$queued = 0;
		$items  = 0;
		$images = 0;

		foreach ( KDNA_SeoWriter_Scheduler::sweep_candidates( self::LIMIT, $cutoff ) as $item ) {
			if ( self::booked( KDNA_SeoWriter_Scheduler::JOB, array( $item['type'], $item['id'], $item['subtype'], 'auto' ) ) ) {
				continue;
			}
			if ( KDNA_SeoWriter_Scheduler::queue( $item['type'], $item['id'], $item['subtype'], 'auto', $queued * self::SPACING, self::GROUP ) ) {
				++$queued;
				++$items;
			}
		}

		if ( KDNA_SeoWriter_Alt_Text::enabled() ) {
			foreach ( KDNA_SeoWriter_Alt_Text::sweep_candidates( self::LIMIT, $cutoff ) as $image ) {
				if ( self::booked( KDNA_SeoWriter_Alt_Text::JOB, array( $image['id'], $image['context'], 'auto' ) ) ) {
					continue;
				}
				if ( KDNA_SeoWriter_Alt_Text::queue( $image['id'], $image['context'], 'auto', $queued * self::SPACING, self::GROUP ) ) {
					++$queued;
					++$images;
				}
			}
		}

		return array(
			'total'  => $queued,
			'items'  => $items,
			'images' => $images,
		);
	}

	/**
	 * Tells you whether a job is already booked in any group (for example by
	 * the nightly sweep), so a pass does not count it as its own.
	 *
	 * @param string       $hook Job hook.
	 * @param array<mixed> $args Job arguments.
	 * @return bool
	 */
	private static function booked( string $hook, array $args ): bool {
		return function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( $hook, $args );
	}

	/**
	 * Books the check that runs after a pass's last job.
	 *
	 * @param int $jobs Jobs in the pass.
	 * @return void
	 */
	private static function book_check( int $jobs ): void {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + $jobs * self::SPACING + self::CHECK_DELAY, self::CHECK, array(), self::GROUP );
		}
	}

	/**
	 * Runs after each pass. While jobs are still waiting (some may have been
	 * held back because the server was busy), it checks again later. Once
	 * they have all run, anything still empty and not yet tried is queued as
	 * another pass, up to PASSES passes. Then the run is finished.
	 *
	 * @return void
	 */
	public static function check(): void {
		$run = get_option( self::OPTION, array() );
		if ( ! is_array( $run ) || empty( $run['started'] ) || ! empty( $run['stopped'] ) || ! empty( $run['done'] ) ) {
			return;
		}

		if ( self::pending( true ) > 0 ) {
			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( time() + 5 * MINUTE_IN_SECONDS, self::CHECK, array(), self::GROUP );
			}
			return;
		}

		$pass = (int) ( $run['pass'] ?? 1 );
		if ( $pass < self::PASSES && '' === self::cannot_start() ) {
			// A retry run goes back for anything not tried since it started.
			$next = self::queue_pass( ! empty( $run['retry'] ) ? (int) $run['started'] : null );
			if ( $next['total'] > 0 ) {
				$run['pass']    = $pass + 1;
				$run['total']   = (int) ( $run['total'] ?? 0 ) + $next['total'];
				$run['items']   = (int) ( $run['items'] ?? 0 ) + $next['items'];
				$run['images']  = (int) ( $run['images'] ?? 0 ) + $next['images'];
				update_option( self::OPTION, $run, false );
				self::book_check( $next['total'] );
				return;
			}
		}

		// Finished. Anything still waiting to be done is counted, so the
		// Overview can say so. The nightly sweep keeps going after it.
		$run['done'] = time();
		$run['left'] = count( KDNA_SeoWriter_Scheduler::sweep_candidates( self::LIMIT ) ) + count( KDNA_SeoWriter_Alt_Text::sweep_candidates( self::LIMIT ) );
		update_option( self::OPTION, $run, false );
	}

	/**
	 * Tells you whether a run is going, without counting jobs.
	 *
	 * @return bool
	 */
	public static function is_running(): bool {
		$run = get_option( self::OPTION, array() );
		return is_array( $run ) && ! empty( $run['started'] ) && empty( $run['stopped'] ) && empty( $run['done'] ) && ! empty( $run['pass'] );
	}

	/**
	 * Stops the run: every job not yet started is cancelled. Anything already
	 * saved or waiting in Review stays.
	 *
	 * @return array<string, mixed> The run's status.
	 */
	public static function stop(): array {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), self::GROUP );
		}

		$run = get_option( self::OPTION, array() );
		if ( is_array( $run ) && $run ) {
			$run['stopped'] = time();
			update_option( self::OPTION, $run, false );
		}

		// The items' "queued" markers are cleared so their panels stop saying Queued.
		foreach ( KDNA_SeoWriter_Scheduler::sweep_candidates( self::LIMIT ) as $item ) {
			if ( KDNA_SeoWriter_State::job_waiting( $item['type'], $item['id'] ) ) {
				KDNA_SeoWriter_State::set_job( $item['type'], $item['id'], false );
			}
		}

		return self::status();
	}

	/**
	 * Returns the current or last run's progress.
	 *
	 * @return array{running: bool, checking: bool, total: int, done: int, pending: int, items: int, images: int, pass: int, passes: int, left: int, started: string, stopped: bool, finished: bool}
	 */
	public static function status(): array {
		$run     = get_option( self::OPTION, array() );
		$run     = is_array( $run ) ? $run : array();
		$total   = (int) ( $run['total'] ?? 0 );
		$pending = $total > 0 ? self::pending() : 0;
		$stopped = ! empty( $run['stopped'] );

		// Runs from 1.0.3 have no passes: they end when their jobs have run.
		$checks  = ! empty( $run['pass'] ) && empty( $run['done'] ) && ! $stopped;
		$running = ! $stopped && ( $pending > 0 || $checks );

		return array(
			'running'  => $running,
			'checking' => $running && 0 === $pending,
			'total'    => $total,
			'done'     => max( 0, $total - $pending ),
			'pending'  => $pending,
			'items'    => (int) ( $run['items'] ?? 0 ),
			'images'   => (int) ( $run['images'] ?? 0 ),
			'pass'     => (int) ( $run['pass'] ?? 1 ),
			'passes'   => self::PASSES,
			'left'     => (int) ( $run['left'] ?? 0 ),
			'started'  => ! empty( $run['started'] ) ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $run['started'] ) : '',
			'stopped'  => $stopped,
			'finished' => $total > 0 && ! $running && ! $stopped,
		);
	}

	/**
	 * Lists items with empty fields that a run would leave out, and why.
	 *
	 * The Coverage table counts every published item, so this explains the
	 * difference: tried recently (with the result, such as "Not enough
	 * content"), waiting in Review, already queued, locked by a person, or a
	 * category with no posts.
	 *
	 * @param array<int, array{type: string, id: int, subtype: string}> $picked Items the run would cover.
	 * @param int|null                                                  $cutoff Tried at or after this counts as recent.
	 * @return array{count: int, rows: array<int, array<string, mixed>>}
	 */
	public static function left_out( array $picked, ?int $cutoff = null ): array {
		global $wpdb;

		$cutoff = $cutoff ?? time() - 7 * DAY_IN_SECONDS;
		$skip   = array();
		foreach ( $picked as $item ) {
			$skip[ $item['type'] . ':' . $item['id'] ] = true;
		}

		$rows   = array();
		$count  = 0;
		$fields = KDNA_SeoWriter_Scheduler::enabled_meta_keys();
		$types  = KDNA_SeoWriter_Scheduler::sweep_post_types();

		if ( $types && $fields ) {
			$checks = array();
			foreach ( $fields as $key ) {
				$checks[] = $wpdb->prepare( "NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} f WHERE f.post_id = p.ID AND f.meta_key = %s AND f.meta_value <> '' )", $key );
			}
			$type_list = implode( ',', array_map( static fn( $type ) => $wpdb->prepare( '%s', $type ), $types ) );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Admin check; every part is prepared above.
			$found = $wpdb->get_results(
				"SELECT p.ID, p.post_type FROM {$wpdb->posts} p
				WHERE p.post_status = 'publish' AND p.post_password = '' AND p.post_type IN ({$type_list})
				AND ( " . implode( ' OR ', $checks ) . ' )
				ORDER BY p.post_type ASC, p.ID ASC LIMIT 1000',
				ARRAY_A
			);
			// phpcs:enable
			foreach ( (array) $found as $row ) {
				$id = (int) $row['ID'];
				if ( isset( $skip[ 'post:' . $id ] ) ) {
					continue;
				}
				$why = self::reason( 'post', $id, (string) $row['post_type'], $cutoff );
				if ( $why ) {
					++$count;
					if ( count( $rows ) < 200 ) {
						$object = get_post_type_object( (string) $row['post_type'] );
						$title  = get_the_title( $id );
						$rows[] = array_merge(
							$why,
							array(
								'title' => '' !== $title ? $title : '#' . $id,
								'type'  => $object && isset( $object->labels->singular_name ) ? (string) $object->labels->singular_name : (string) $row['post_type'],
								'link'  => (string) get_edit_post_link( $id, 'raw' ),
							)
						);
					}
				}
			}
		}

		foreach ( KDNA_SeoWriter_Scheduler::sweep_taxonomies() as $taxonomy ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'number'     => 500,
				)
			);
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			$object = get_taxonomy( $taxonomy );
			foreach ( $terms as $term ) {
				$id = (int) $term->term_id;
				if ( isset( $skip[ 'term:' . $id ] ) ) {
					continue;
				}
				$why = self::reason( 'term', $id, $taxonomy, $cutoff, (int) $term->count );
				if ( $why ) {
					++$count;
					if ( count( $rows ) < 200 ) {
						$rows[] = array_merge(
							$why,
							array(
								'title' => (string) $term->name,
								'type'  => $object && isset( $object->labels->singular_name ) ? (string) $object->labels->singular_name : $taxonomy,
								'link'  => (string) get_edit_term_link( $id, $taxonomy ),
							)
						);
					}
				}
			}
		}

		return array(
			'count' => $count,
			'rows'  => $rows,
		);
	}

	/**
	 * Works out why one item with empty fields is not in the run.
	 *
	 * @param string   $object_type Either post or term.
	 * @param int      $id          Post or term ID.
	 * @param string   $subtype     Post type or taxonomy.
	 * @param int      $cutoff      Tried at or after this counts as recent.
	 * @param int|null $posts       For terms, how many posts they have.
	 * @return array{code: string, reason: string}|null Null when the item has no empty field.
	 */
	private static function reason( string $object_type, int $id, string $subtype, int $cutoff, ?int $posts = null ): ?array {
		$current = KDNA_SeoWriter_Yoast::get_all( $object_type, $id, $subtype );
		$state   = KDNA_SeoWriter_State::refresh_locks( $object_type, $id, $current );
		$enabled = (array) KDNA_SeoWriter_Settings::get( 'content', 'fields' );

		$empty  = 0;
		$locked = 0;
		foreach ( KDNA_SeoWriter_State::GROUPS as $group => $group_fields ) {
			if ( empty( $enabled[ $group ] ) ) {
				continue;
			}
			foreach ( $group_fields as $field ) {
				if ( '' === trim( (string) ( $current[ $field ] ?? '' ) ) ) {
					++$empty;
					$locked += empty( $state['locked'][ $field ] ) ? 0 : 1;
				}
			}
		}
		if ( 0 === $empty ) {
			return null;
		}

		$out = static fn( string $code, string $reason ) => array(
			'code'   => $code,
			'reason' => $reason,
		);

		if ( 'term' === $object_type && 0 === $posts ) {
			return $out( 'empty', __( 'Has no posts or products yet, so its page is empty. It is left out until it has some.', 'kdna-seo-writer' ) );
		}
		if ( KDNA_SeoWriter_Queue::waiting_fields( $object_type, array( $id ) ) ) {
			return $out( 'review', __( 'Suggestions are waiting for approval in the Review tab.', 'kdna-seo-writer' ) );
		}
		if ( $locked === $empty ) {
			return $out( 'locked', __( 'Its empty fields are locked, because a person edited or emptied them. Click Unlock in the SEO Writer box on its edit screen to let the plugin fill them.', 'kdna-seo-writer' ) );
		}
		if ( KDNA_SeoWriter_State::job_waiting( $object_type, $id ) ) {
			return $out( 'queued', __( 'Already queued, waiting for the background job runner.', 'kdna-seo-writer' ) );
		}

		$tried = (int) ( 'term' === $object_type ? get_term_meta( $id, KDNA_SeoWriter_State::ATTEMPTED_KEY, true ) : get_post_meta( $id, KDNA_SeoWriter_State::ATTEMPTED_KEY, true ) );
		if ( $tried >= $cutoff ) {
			$last    = $state['last'];
			$message = ! empty( $last['message'] ) ? (string) $last['message'] : __( 'no result was recorded.', 'kdna-seo-writer' );
			$code    = 'not_enough_content' === $state['skipped'] && 'skipped' === ( $last['status'] ?? '' ) ? 'short' : 'tried';
			/* translators: 1: how long ago, such as "2 hours", 2: the result. */
			return $out( $code, sprintf( __( 'Tried %1$s ago: %2$s', 'kdna-seo-writer' ), human_time_diff( $tried ), $message ) );
		}

		return null;
	}

	/**
	 * Checks the background job runner is working, so the Overview can say
	 * if jobs are stuck or failing.
	 *
	 * @return array{stalled: bool, failed: int}
	 */
	public static function job_health(): array {
		$due      = 0;
		$recent   = 0;
		$failed   = 0;
		$groups   = array( KDNA_SeoWriter_Scheduler::GROUP, self::GROUP );
		$past_due = time() - 20 * MINUTE_IN_SECONDS;

		foreach ( $groups as $group ) {
			$due    += self::count_query(
				array(
					'group'        => $group,
					'status'       => 'pending',
					'date'         => $past_due,
					'date_compare' => '<=',
				)
			);
			$recent += self::count_query(
				array(
					'group'            => $group,
					'status'           => 'complete',
					'modified'         => $past_due,
					'modified_compare' => '>=',
				)
			);
			$failed += self::count_query(
				array(
					'group'            => $group,
					'status'           => 'failed',
					'modified'         => time() - DAY_IN_SECONDS,
					'modified_compare' => '>=',
				)
			);
		}

		return array(
			// Jobs are overdue and none has finished for 20 minutes.
			'stalled' => $due > 0 && 0 === $recent,
			'failed'  => $failed,
		);
	}

	/**
	 * Explains why a run cannot start, if it cannot.
	 *
	 * @return string Empty when it can start.
	 */
	public static function cannot_start(): string {
		if ( ! KDNA_SeoWriter_Yoast::is_active() ) {
			return __( 'Yoast SEO is not active, so there is nowhere to save the metadata.', 'kdna-seo-writer' );
		}
		if ( ! KDNA_SeoWriter_Crypto::has_key() ) {
			return __( 'No Anthropic API key has been saved yet. Add one in the Connection tab.', 'kdna-seo-writer' );
		}
		if ( ! KDNA_SeoWriter_Scheduler::available() ) {
			return __( 'The background job runner (Action Scheduler) is not available, so nothing can be queued.', 'kdna-seo-writer' );
		}
		if ( KDNA_SeoWriter_Usage::limit_reached() ) {
			return __( 'This month\'s spend limit has been reached. Raise it in the Connection tab first.', 'kdna-seo-writer' );
		}
		return '';
	}

	/**
	 * Counts this run's jobs that have not run yet (not counting the check).
	 *
	 * @param bool $with_running Also count jobs running right now.
	 * @return int
	 */
	private static function pending( bool $with_running = false ): int {
		$statuses = $with_running ? array( 'pending', 'in-progress' ) : array( 'pending' );
		$count    = 0;
		foreach ( $statuses as $status ) {
			$count += self::count_jobs( '', $status ) - self::count_jobs( self::CHECK, $status );
		}
		return max( 0, $count );
	}

	/**
	 * Counts this run's jobs with one status, optionally for one hook only.
	 *
	 * @param string $hook   Job hook, or empty for every hook.
	 * @param string $status Action Scheduler status.
	 * @return int
	 */
	private static function count_jobs( string $hook, string $status ): int {
		$query = array(
			'group'  => self::GROUP,
			'status' => $status,
		);
		if ( '' !== $hook ) {
			$query['hook'] = $hook;
		}
		return self::count_query( $query );
	}

	/**
	 * Counts Action Scheduler jobs matching a query.
	 *
	 * @param array<string, mixed> $query Action Scheduler query.
	 * @return int
	 */
	private static function count_query( array $query ): int {
		if ( class_exists( 'ActionScheduler' ) && method_exists( 'ActionScheduler', 'store' ) ) {
			try {
				return (int) ActionScheduler::store()->query_actions( $query, 'count' );
			} catch ( \Throwable $e ) {
				return 0;
			}
		}
		if ( function_exists( 'as_get_scheduled_actions' ) ) {
			$query['per_page'] = -1;
			return count( as_get_scheduled_actions( $query, 'ids' ) );
		}
		return 0;
	}
}
