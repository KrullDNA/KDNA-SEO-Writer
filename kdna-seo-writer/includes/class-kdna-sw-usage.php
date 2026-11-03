<?php
/**
 * Token usage, monthly cost estimate and the monthly spend limit.
 *
 * @package KDNA_SeoWriter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds up the tokens logged for each request to Claude and estimates the cost.
 *
 * The estimate uses the prices in the Connection tab. Anthropic charges cached
 * input (the brand rules, on repeat requests) at a tenth of the input price,
 * and writing to the cache at 1.25 times the input price, so those are
 * counted separately.
 */
final class KDNA_SeoWriter_Usage {

	/**
	 * Price multipliers for cached input, relative to the normal input price.
	 */
	private const CACHE_READ_FACTOR  = 0.1;
	private const CACHE_WRITE_FACTOR = 1.25;

	/**
	 * Totals for the current month, worked out once per request.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $month = null;

	/**
	 * Returns this month's totals: requests, tokens and estimated cost, overall and per model.
	 *
	 * The month runs from the 1st, in UTC, the same as Anthropic's billing.
	 *
	 * @return array{requests: int, input: int, output: int, cache_read: int, cache_write: int, cost: float, models: array<string, array<string, mixed>>, since: string}
	 */
	public static function this_month(): array {
		if ( null !== self::$month ) {
			return self::$month;
		}

		global $wpdb;

		$tables = KDNA_SeoWriter_Install::tables();
		$since  = gmdate( 'Y-m-01 00:00:00' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table, name from $wpdb->prefix.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT model, COUNT(*) AS requests, SUM(input_tokens) AS input, SUM(output_tokens) AS output, SUM(cache_read_tokens) AS cache_read, SUM(cache_write_tokens) AS cache_write FROM {$tables['log']} WHERE kind = 'usage' AND created_at >= %s GROUP BY model", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$since
			),
			ARRAY_A
		);

		$totals = array(
			'requests'    => 0,
			'input'       => 0,
			'output'      => 0,
			'cache_read'  => 0,
			'cache_write' => 0,
			'cost'        => 0.0,
			'models'      => array(),
			'since'       => $since,
		);

		foreach ( (array) $rows as $row ) {
			$model = (string) $row['model'];
			$cost  = self::cost( $model, (int) $row['input'], (int) $row['output'], (int) $row['cache_read'], (int) $row['cache_write'] );

			$totals['models'][ $model ] = array(
				'requests' => (int) $row['requests'],
				'input'    => (int) $row['input'],
				'output'   => (int) $row['output'],
				'cost'     => $cost,
			);
			foreach ( array( 'requests', 'input', 'output', 'cache_read', 'cache_write' ) as $key ) {
				$totals[ $key ] += (int) $row[ $key ];
			}
			$totals['cost'] += $cost;
		}

		$totals['cost'] = round( $totals['cost'], 4 );
		self::$month    = $totals;

		return $totals;
	}

	/**
	 * Estimates the cost of some tokens in US dollars.
	 *
	 * @param string $model       Model ID.
	 * @param int    $input       Normal input tokens.
	 * @param int    $output      Output tokens.
	 * @param int    $cache_read  Input tokens read from the cache.
	 * @param int    $cache_write Input tokens written to the cache.
	 * @return float
	 */
	public static function cost( string $model, int $input, int $output, int $cache_read, int $cache_write ): float {
		$rates = (array) KDNA_SeoWriter_Settings::get( 'connection', 'rates' );
		$rate  = $rates[ $model ] ?? ( $rates[ KDNA_SeoWriter_Claude::DEFAULT_MODEL ] ?? array(
			'input'  => 0,
			'output' => 0,
		) );

		$in  = (float) $rate['input'];
		$out = (float) $rate['output'];

		return ( $input * $in + $cache_read * $in * self::CACHE_READ_FACTOR + $cache_write * $in * self::CACHE_WRITE_FACTOR + $output * $out ) / 1000000;
	}

	/**
	 * Tells you whether this month's estimated spend has reached the limit.
	 *
	 * @return bool True when a limit is set and reached.
	 */
	public static function limit_reached(): bool {
		$limit = (float) KDNA_SeoWriter_Settings::get( 'connection', 'spend_limit' );
		if ( $limit <= 0 ) {
			return false;
		}
		return self::this_month()['cost'] >= $limit;
	}

	/**
	 * Forgets this request's totals, so the next read is fresh.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$month = null;
	}
}
