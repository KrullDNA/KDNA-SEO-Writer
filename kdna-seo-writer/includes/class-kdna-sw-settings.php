<?php
/**
 * Settings: one option (kdna_sw_settings) holding every setting, with defaults.
 *
 * @package KDNA_SeoWriter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads, cleans and saves the plugin's settings.
 *
 * Everything lives in a single option that is not autoloaded, so public page
 * views never pay for it. The API key is stored separately and encrypted (see
 * KDNA_SeoWriter_Crypto), and is never part of these settings.
 */
final class KDNA_SeoWriter_Settings {

	/**
	 * Name of the option that holds the settings.
	 */
	public const OPTION = 'kdna_sw_settings';

	/**
	 * Save modes a content type can have.
	 */
	public const MODES = array( 'auto', 'review', 'off' );

	/**
	 * Settings loaded during this request, so the option is only read once.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $cache = null;

	/**
	 * Returns the default value of every setting, grouped by settings tab.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function defaults(): array {
		$rates = array();
		foreach ( KDNA_SeoWriter_Claude::MODELS as $model => $info ) {
			$rates[ $model ] = array(
				'input'  => (float) $info['input'],
				'output' => (float) $info['output'],
			);
		}

		return array(
			'connection' => array(
				'model'       => KDNA_SeoWriter_Claude::DEFAULT_MODEL,
				'rates'       => $rates,
				'spend_limit' => 0.0,
			),
			'brand'      => array(
				'name'              => '',
				'description'       => '',
				'tone'              => '',
				'tone_example'      => '',
				'spelling'          => 'en-GB',
				'avoid'             => '',
				'prefer'            => '',
				'claims'            => '',
				'no_em_dashes'      => true,
				'no_exclaim_titles' => true,
				'no_prices_dates'   => true,
			),
			'content'    => array(
				'post_types' => array(),
				'taxonomies' => array(),
				'fields'     => array(
					'seo_title'        => true,
					'meta_description' => true,
					'focus_keyphrase'  => true,
					'social'           => true,
				),
				'jet_fields' => array(),
				'min_words'  => 50,
			),
			'automation' => array(
				'on_publish'   => true,
				'nightly'      => true,
				'sweep_time'   => '02:00',
				'per_night'    => 50,
				'alt_text'     => true,
				'rocket_purge' => true,
			),
			'advanced'   => array(
				'desc_min'       => 120,
				'desc_max'       => 156,
				'title_max_px'   => 600,
				'kp_min_words'   => 1,
				'kp_max_words'   => 4,
				'title_template' => '',
				'alt_max'        => 125,
				'max_chars'      => 12000,
				'delete_data'    => false,
			),
		);
	}

	/**
	 * Adds the settings option with defaults if it does not exist yet.
	 *
	 * The option is created with autoload switched off so it is never loaded on
	 * public pages.
	 *
	 * @return void
	 */
	public static function install_defaults(): void {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, self::defaults(), '', false );
		}
	}

	/**
	 * Returns all settings, with defaults filled in for anything not saved yet.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			self::$cache = self::merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		}
		return self::$cache;
	}

	/**
	 * Returns one setting, for example get( 'brand', 'name' ).
	 *
	 * @param string $group Settings tab, such as brand or content.
	 * @param string $key   Setting name within that tab.
	 * @return mixed The value, or null if there is no such setting.
	 */
	public static function get( string $group, string $key ) {
		$all = self::all();
		return $all[ $group ][ $key ] ?? null;
	}

	/**
	 * Returns the save mode (auto, review or off) for a post type or taxonomy.
	 *
	 * Blog posts default to Automatic, everything else defaults to Review, as
	 * set out in the brief. A choice saved in the Content tab wins.
	 *
	 * @param string $kind    Either post_type or taxonomy.
	 * @param string $subtype The post type or taxonomy name.
	 * @return string One of auto, review or off.
	 */
	public static function mode_for( string $kind, string $subtype ): string {
		$group = 'taxonomy' === $kind ? 'taxonomies' : 'post_types';
		$saved = self::get( 'content', $group );

		if ( is_array( $saved ) && isset( $saved[ $subtype ] ) && in_array( $saved[ $subtype ], self::MODES, true ) ) {
			return $saved[ $subtype ];
		}

		return ( 'post_type' === $kind && 'post' === $subtype ) ? 'auto' : 'review';
	}

	/**
	 * Cleans submitted settings and saves them.
	 *
	 * Only known settings are kept. Every value is converted to the type its
	 * default has, so bad input can never break the plugin.
	 *
	 * @param array<string, mixed> $input Settings as sent from the settings screen.
	 * @return array<string, array<string, mixed>> The settings as saved.
	 */
	public static function save( array $input ): array {
		$current = self::all();
		$clean   = self::sanitise( $input, $current );

		update_option( self::OPTION, $clean, false );
		self::$cache = null;

		return self::all();
	}

	/**
	 * Fills in defaults for any setting missing from the stored values.
	 *
	 * @param array<string, mixed> $defaults Default settings.
	 * @param array<string, mixed> $stored   Saved settings.
	 * @return array<string, mixed> Saved settings with gaps filled from defaults.
	 */
	private static function merge( array $defaults, array $stored ): array {
		$out = $defaults;
		foreach ( $defaults as $group => $values ) {
			if ( ! isset( $stored[ $group ] ) || ! is_array( $stored[ $group ] ) ) {
				continue;
			}
			foreach ( $values as $key => $default ) {
				if ( ! array_key_exists( $key, $stored[ $group ] ) ) {
					continue;
				}
				$value = $stored[ $group ][ $key ];
				if ( is_array( $default ) && is_array( $value ) ) {
					$out[ $group ][ $key ] = array_replace( $default, $value );
				} else {
					$out[ $group ][ $key ] = $value;
				}
			}
		}
		return $out;
	}

	/**
	 * Converts submitted values into safe values of the right type.
	 *
	 * Groups that were not submitted keep their current values, so each tab can
	 * be saved on its own.
	 *
	 * @param array<string, mixed> $input   Submitted settings.
	 * @param array<string, mixed> $current Settings as they are now.
	 * @return array<string, mixed> Clean settings.
	 */
	private static function sanitise( array $input, array $current ): array {
		$defaults = self::defaults();
		$out      = $current;

		foreach ( $defaults as $group => $values ) {
			if ( ! isset( $input[ $group ] ) || ! is_array( $input[ $group ] ) ) {
				continue;
			}
			foreach ( $values as $key => $default ) {
				if ( ! array_key_exists( $key, $input[ $group ] ) ) {
					continue;
				}
				$out[ $group ][ $key ] = self::clean_value( $group, $key, $input[ $group ][ $key ], $default );
			}
		}

		return $out;
	}

	/**
	 * Cleans a single setting value.
	 *
	 * @param string $group   Settings tab.
	 * @param string $key     Setting name.
	 * @param mixed  $value   Submitted value.
	 * @param mixed  $default Default value, which decides the type.
	 * @return mixed Clean value.
	 */
	private static function clean_value( string $group, string $key, $value, $default ) {
		switch ( "{$group}.{$key}" ) {
			case 'connection.model':
				$value = sanitize_text_field( (string) $value );
				return array_key_exists( $value, KDNA_SeoWriter_Claude::MODELS ) ? $value : KDNA_SeoWriter_Claude::DEFAULT_MODEL;

			case 'connection.rates':
				$rates = $default;
				if ( is_array( $value ) ) {
					foreach ( $rates as $model => $pair ) {
						if ( isset( $value[ $model ] ) && is_array( $value[ $model ] ) ) {
							$rates[ $model ]['input']  = max( 0.0, round( (float) ( $value[ $model ]['input'] ?? $pair['input'] ), 4 ) );
							$rates[ $model ]['output'] = max( 0.0, round( (float) ( $value[ $model ]['output'] ?? $pair['output'] ), 4 ) );
						}
					}
				}
				return $rates;

			case 'brand.spelling':
				return in_array( $value, array( 'en-GB', 'en-US' ), true ) ? $value : 'en-GB';

			case 'brand.description':
			case 'brand.tone_example':
			case 'brand.avoid':
			case 'brand.prefer':
			case 'brand.claims':
				return sanitize_textarea_field( (string) $value );

			case 'content.post_types':
				return self::clean_modes( $value, get_post_types( array( 'show_ui' => true ) ) );

			case 'content.taxonomies':
				return self::clean_modes( $value, get_taxonomies( array( 'show_ui' => true ) ) );

			case 'content.fields':
				$fields = array();
				foreach ( $default as $field => $on ) {
					$fields[ $field ] = is_array( $value ) && ! empty( $value[ $field ] );
				}
				return $fields;

			case 'content.jet_fields':
				$out = array();
				if ( is_array( $value ) ) {
					foreach ( $value as $post_type => $list ) {
						$post_type = sanitize_key( (string) $post_type );
						$paths     = self::clean_field_paths( (string) $list );
						if ( '' !== $post_type && '' !== $paths ) {
							$out[ $post_type ] = $paths;
						}
					}
				}
				return $out;

			case 'automation.sweep_time':
				return preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', (string) $value ) ? (string) $value : '02:00';

			case 'advanced.title_template':
				return sanitize_text_field( (string) $value );
		}

		if ( is_bool( $default ) ) {
			return (bool) rest_sanitize_boolean( $value );
		}
		if ( is_int( $default ) ) {
			return max( 0, absint( $value ) );
		}
		if ( is_float( $default ) ) {
			return max( 0.0, round( (float) $value, 2 ) );
		}
		if ( is_string( $default ) ) {
			return sanitize_text_field( (string) $value );
		}

		return $default;
	}

	/**
	 * Keeps only valid Automatic, Review or Off choices for real content types.
	 *
	 * @param mixed                 $value   Submitted map of name to mode.
	 * @param array<string, string> $allowed Registered post types or taxonomies.
	 * @return array<string, string> Clean map.
	 */
	private static function clean_modes( $value, array $allowed ): array {
		$out = array();
		if ( ! is_array( $value ) ) {
			return $out;
		}
		foreach ( $value as $name => $mode ) {
			$name = sanitize_key( (string) $name );
			if ( isset( $allowed[ $name ] ) && in_array( $mode, self::MODES, true ) ) {
				$out[ $name ] = $mode;
			}
		}
		return $out;
	}

	/**
	 * Cleans a comma separated list of field names, such as "intro, faq.answer".
	 *
	 * Letters, numbers, underscores, hyphens and dots (for repeater sub-fields)
	 * are allowed. Anything else is removed.
	 *
	 * @param string $list Comma or line separated field names.
	 * @return string Clean, comma separated list.
	 */
	public static function clean_field_paths( string $list ): string {
		$paths = preg_split( '/[\s,]+/', $list ) ?: array();
		$paths = array_map(
			static fn( $path ) => trim( (string) preg_replace( '/[^A-Za-z0-9_.\-]/', '', $path ), '.' ),
			$paths
		);
		return implode( ', ', array_values( array_unique( array_filter( $paths ) ) ) );
	}
}
