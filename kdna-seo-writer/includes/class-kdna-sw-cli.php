<?php
/**
 * WP-CLI commands: wp kdna-sw read | generate | undo.
 *
 * @package KDNA_SeoWriter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Command line tools for testing and support.
 *
 * ## EXAMPLES
 *
 *     wp kdna-sw read 42
 *     wp kdna-sw generate 42
 *     wp kdna-sw generate 42 --save
 *     wp kdna-sw generate 7 --term=product_cat --save
 *     wp kdna-sw undo 15
 *     wp kdna-sw sweep
 *     wp kdna-sw sweep --now
 *     wp kdna-sw alt 88 --context=42
 *     wp kdna-sw report 42
 */
final class KDNA_SeoWriter_Cli {

	/**
	 * Shows the text the page reader builds for an item.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Post ID, or term ID when --term is given.
	 *
	 * [--term=<taxonomy>]
	 * : Read a term in this taxonomy instead of a post.
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 * @return void
	 */
	public function read( array $args, array $assoc_args ): void {
		$taxonomy = (string) ( $assoc_args['term'] ?? '' );
		$read     = KDNA_SeoWriter_Reader::read( '' !== $taxonomy ? 'term' : 'post', (int) $args[0], $taxonomy );

		WP_CLI::log( 'Status:          ' . ( $read['ok'] ? 'ready' : $read['message'] ) );
		WP_CLI::log( 'Read from:       ' . implode( ', ', (array) $read['sources'] ) );
		WP_CLI::log( 'Words:           ' . $read['word_count'] . ( $read['trimmed'] ? ' (trimmed before sending)' : '' ) );
		WP_CLI::log( 'H1:              ' . $read['h1'] );
		WP_CLI::log( 'First paragraph: ' . $read['first_paragraph'] );
		WP_CLI::log( 'H2:              ' . implode( ' | ', (array) $read['h2'] ) );
		WP_CLI::log( 'H3:              ' . implode( ' | ', (array) $read['h3'] ) );
		WP_CLI::log( '' );
		WP_CLI::log( (string) $read['text'] );
	}

	/**
	 * Writes metadata for an item with Claude and shows the result and checks.
	 *
	 * Without --save nothing is written. With --save, empty Yoast fields are
	 * filled. Add --replace to also replace fields that already have text
	 * (for testing only, every change is logged and can be undone).
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Post ID, or term ID when --term is given.
	 *
	 * [--term=<taxonomy>]
	 * : Write for a term in this taxonomy instead of a post.
	 *
	 * [--save]
	 * : Save the result into Yoast's empty fields.
	 *
	 * [--replace]
	 * : With --save, also replace fields that already have text.
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 * @return void
	 */
	public function generate( array $args, array $assoc_args ): void {
		$taxonomy = (string) ( $assoc_args['term'] ?? '' );
		$type     = '' !== $taxonomy ? 'term' : 'post';
		$id       = (int) $args[0];

		$result = KDNA_SeoWriter_Writer::generate( $type, $id, $taxonomy );

		WP_CLI::log( 'Status:   ' . $result['status'] . ' (' . $result['message'] . ')' );
		WP_CLI::log( 'Attempts: ' . $result['attempts'] );

		if ( ! $result['values'] ) {
			if ( 'error' === $result['status'] ) {
				WP_CLI::error( $result['message'] );
			}
			return;
		}

		foreach ( $result['values'] as $field => $value ) {
			WP_CLI::log( str_pad( KDNA_SeoWriter_Rules::field_label( $field ) . ':', 22 ) . $value );
		}
		WP_CLI::log( str_pad( 'Shown in Google as:', 22 ) . $result['rendered_title'] );
		WP_CLI::log( '' );
		foreach ( $result['checks'] as $check ) {
			WP_CLI::log( ( $check['ok'] ? '  PASS  ' : '  FAIL  ' ) . $check['message'] );
		}
		WP_CLI::log( '' );
		WP_CLI::log( 'Suggested slug:         ' . $result['suggestions']['slug'] );
		WP_CLI::log( 'Suggested opening line: ' . $result['suggestions']['opening_line'] );
		WP_CLI::log( 'Suggested headings:     ' . implode( ' | ', $result['suggestions']['headings'] ) );

		if ( ! isset( $assoc_args['save'] ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'Nothing saved. Add --save to fill empty Yoast fields.' );
			return;
		}

		$subtype = 'term' === $type ? $taxonomy : (string) get_post_type( $id );
		$saved   = KDNA_SeoWriter_Writer::save( $type, $id, $subtype, $result['values'], 'test', ! isset( $assoc_args['replace'] ) );

		if ( is_wp_error( $saved ) ) {
			WP_CLI::error( $saved->get_error_message() );
		}

		WP_CLI::success( $saved ? 'Saved: ' . implode( ', ', $saved ) : 'Nothing saved, every field already had text. Add --replace to replace it.' );
	}

	/**
	 * Runs the nightly sweep straight away.
	 *
	 * Without --now, the sweep queues its items as background jobs, exactly as
	 * it does each night. With --now, each item is worked on here and then,
	 * one after another, so you can watch the results.
	 *
	 * ## OPTIONS
	 *
	 * [--now]
	 * : Work on the items here instead of queuing background jobs.
	 *
	 * @param array<int, string>    $args       Positional arguments (unused).
	 * @param array<string, string> $assoc_args Named arguments.
	 * @return void
	 */
	public function sweep( array $args, array $assoc_args ): void {
		$limit = (int) KDNA_SeoWriter_Settings::get( 'automation', 'per_night' );
		$items = KDNA_SeoWriter_Scheduler::sweep_candidates( $limit );

		if ( ! $items ) {
			WP_CLI::success( 'Nothing to do: no items with empty fields that automation may fill.' );
			return;
		}

		if ( ! isset( $assoc_args['now'] ) ) {
			KDNA_SeoWriter_Scheduler::run_sweep();
			WP_CLI::success( count( $items ) . ' items queued. Run "wp action-scheduler run --group=kdna-seo-writer" to process them now.' );
			return;
		}

		foreach ( $items as $item ) {
			KDNA_SeoWriter_Scheduler::run_job( $item['type'], $item['id'], $item['subtype'], 'auto' );
			$last = KDNA_SeoWriter_State::get( $item['type'], $item['id'] )['last'];
			WP_CLI::log( sprintf( '%s %d (%s): %s', $item['type'], $item['id'], $item['subtype'], $last['message'] ?? '' ) );
		}
		WP_CLI::success( 'Sweep finished.' );
	}

	/**
	 * Writes alt text for one image and shows it with its checks.
	 *
	 * Nothing is saved: with --queue the suggestion goes to the Review tab.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Attachment ID.
	 *
	 * [--context=<post-id>]
	 * : The page the image is on.
	 *
	 * [--queue]
	 * : Put the suggestion in the review queue.
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 * @return void
	 */
	public function alt( array $args, array $assoc_args ): void {
		$id      = (int) $args[0];
		$context = (int) ( $assoc_args['context'] ?? 0 );
		$result  = KDNA_SeoWriter_Alt_Text::generate( $id, $context );

		WP_CLI::log( 'Status:             ' . $result['status'] . ' (' . $result['message'] . ')' );
		WP_CLI::log( 'Keyphrase allowed:  ' . ( $result['keyphrase_allowed'] ? 'yes' : 'no' ) );
		WP_CLI::log( 'Alt text:           ' . $result['alt'] );
		foreach ( $result['checks'] as $check ) {
			WP_CLI::log( ( $check['ok'] ? '  PASS  ' : '  FAIL  ' ) . $check['message'] );
		}

		if ( isset( $assoc_args['queue'] ) && 'error' !== $result['status'] ) {
			KDNA_SeoWriter_Queue::add( 'attachment', $id, 'attachment', 'alt_text', KDNA_SeoWriter_Alt_Text::get_alt( $id ), $result['alt'], $result['checks'], false, $context );
			WP_CLI::success( 'Added to the Review tab.' );
		}
	}

	/**
	 * Shows the keyphrase report for an item.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Post ID, or term ID when --term is given.
	 *
	 * [--term=<taxonomy>]
	 * : Report on a term in this taxonomy.
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 * @return void
	 */
	public function report( array $args, array $assoc_args ): void {
		$taxonomy = (string) ( $assoc_args['term'] ?? '' );
		$report   = KDNA_SeoWriter_Report::build( '' !== $taxonomy ? 'term' : 'post', (int) $args[0], $taxonomy );

		WP_CLI::log( 'Keyphrase: ' . $report['keyphrase'] );
		if ( '' !== $report['message'] ) {
			WP_CLI::log( $report['message'] );
		}
		foreach ( $report['checks'] as $check ) {
			WP_CLI::log( sprintf( '  %-5s %s: %s', strtoupper( $check['status'] ), $check['label'], $check['message'] ) );
			if ( '' !== $check['suggestion'] ) {
				WP_CLI::log( '        ' . $check['suggestion_label'] . ': ' . $check['suggestion'] );
			}
		}
	}

	/**
	 * Undoes one change from the log.
	 *
	 * ## OPTIONS
	 *
	 * <log-id>
	 * : The log row ID, shown in the debug panel's change list.
	 *
	 * @param array<int, string> $args Positional arguments.
	 * @return void
	 */
	public function undo( array $args ): void {
		$result = KDNA_SeoWriter_Log::undo( (int) $args[0] );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		WP_CLI::success( 'The previous value has been put back.' );
	}
}
