<?php
/**
 * Rule checks: Yoast's keyphrase and length rules, and the brand rules, in PHP.
 *
 * @package KDNA_SeoWriter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Checks Claude's answer against Yoast's metadata rules and the brand rules.
 *
 * Nothing is taken on trust: every rule is checked here before anything is
 * saved. The thresholds come from Yoast's own analysis code (the yoastseo
 * package shipped with Yoast SEO 28.9), checked on 4 November 2026:
 *
 * - Keyphrase in SEO title (KeyphraseInSEOTitleAssessment): green when the
 *   exact keyphrase is at the start of the title. Function words such as
 *   "the" may come before it.
 * - SEO title width (PageTitleWidthAssessment): measured in 20px Arial, green
 *   from 400 up to (not including) 600 pixels.
 * - Meta description length (MetaDescriptionLengthAssessment): green when
 *   longer than 120 and no longer than 156 characters.
 * - Keyphrase in meta description (MetaDescriptionKeywordAssessment): green
 *   when one or two sentences contain every content word of the keyphrase.
 * - Keyphrase length (KeyphraseLengthAssessment): 1 to 4 content words.
 * - Function words (FunctionWordsInKeyphraseAssessment): the keyphrase must
 *   not be made only of words like "the", "for" or "how".
 * - Previously used keyphrase: not used on any other page or term.
 */
final class KDNA_SeoWriter_Rules {

	/**
	 * Yoast's title width check is measured in this font size, in pixels.
	 */
	private const FONT_PX = 20;

	/**
	 * Longest social title and description, in characters. Facebook and X cut
	 * longer text off in their previews.
	 */
	private const SOCIAL_TITLE_MAX = 95;
	private const SOCIAL_DESC_MAX  = 200;

	/**
	 * Common English function words (words that carry no meaning on their own).
	 * Used to find the content words of a keyphrase, like Yoast does.
	 */
	private const FUNCTION_WORDS = array(
		'a', 'about', 'above', 'across', 'after', 'again', 'against', 'all', 'almost', 'also', 'although', 'always', 'am', 'among', 'an', 'and', 'another', 'any', 'anyone', 'anything', 'are', 'around', 'as', 'at',
		'be', 'because', 'been', 'before', 'being', 'below', 'between', 'both', 'but', 'by',
		'can', 'cannot', 'could',
		'did', 'do', 'does', 'doing', 'done', 'down', 'during',
		'each', 'either', 'else', 'enough', 'even', 'ever', 'every', 'everything',
		'few', 'for', 'from', 'further',
		'get', 'gets', 'got',
		'had', 'has', 'have', 'having', 'he', 'her', 'here', 'hers', 'herself', 'him', 'himself', 'his', 'how', 'however',
		'i', 'if', 'in', 'into', 'is', 'it', 'its', 'itself',
		'just',
		'least', 'less', 'let', 'like',
		'many', 'may', 'me', 'might', 'mine', 'more', 'most', 'much', 'must', 'my', 'myself',
		'neither', 'never', 'no', 'nor', 'not', 'now',
		'of', 'off', 'often', 'on', 'once', 'one', 'only', 'onto', 'or', 'other', 'others', 'our', 'ours', 'ourselves', 'out', 'over', 'own',
		'per',
		'quite',
		'rather',
		'same', 'shall', 'she', 'should', 'since', 'so', 'some', 'someone', 'something', 'such',
		'than', 'that', 'the', 'their', 'theirs', 'them', 'themselves', 'then', 'there', 'these', 'they', 'this', 'those', 'though', 'through', 'thus', 'to', 'too', 'toward', 'towards',
		'under', 'until', 'up', 'upon', 'us',
		'very', 'via',
		'was', 'we', 'were', 'what', 'whatever', 'when', 'where', 'whether', 'which', 'while', 'who', 'whom', 'whose', 'why', 'will', 'with', 'within', 'without', 'would',
		'yet', 'you', 'your', 'yours', 'yourself', 'yourselves',
	);

	/**
	 * Arial character widths in thousandths of the font size, used to measure
	 * the title the way Yoast's snippet preview does. Characters not listed
	 * use a typical width.
	 */
	private const WIDTHS = array(
		' ' => 278, '!' => 278, '"' => 355, '#' => 556, '$' => 556, '%' => 889, '&' => 667, "'" => 191, '(' => 333, ')' => 333, '*' => 389, '+' => 584, ',' => 278, '-' => 333, '.' => 278, '/' => 278,
		'0' => 556, '1' => 556, '2' => 556, '3' => 556, '4' => 556, '5' => 556, '6' => 556, '7' => 556, '8' => 556, '9' => 556,
		':' => 278, ';' => 278, '<' => 584, '=' => 584, '>' => 584, '?' => 556, '@' => 1015,
		'A' => 667, 'B' => 667, 'C' => 722, 'D' => 722, 'E' => 667, 'F' => 611, 'G' => 778, 'H' => 722, 'I' => 278, 'J' => 500, 'K' => 667, 'L' => 556, 'M' => 833,
		'N' => 722, 'O' => 778, 'P' => 667, 'Q' => 778, 'R' => 722, 'S' => 667, 'T' => 611, 'U' => 722, 'V' => 667, 'W' => 944, 'X' => 667, 'Y' => 667, 'Z' => 611,
		'[' => 278, '\\' => 278, ']' => 278, '^' => 469, '_' => 556, '`' => 333,
		'a' => 556, 'b' => 556, 'c' => 500, 'd' => 556, 'e' => 556, 'f' => 278, 'g' => 556, 'h' => 556, 'i' => 222, 'j' => 222, 'k' => 500, 'l' => 222, 'm' => 833,
		'n' => 556, 'o' => 556, 'p' => 556, 'q' => 556, 'r' => 333, 's' => 500, 't' => 278, 'u' => 556, 'v' => 500, 'w' => 722, 'x' => 500, 'y' => 500, 'z' => 500,
		'{' => 334, '|' => 260, '}' => 334, '~' => 584,
		'–' => 556, '—' => 1000, '·' => 278, '•' => 350, '«' => 556, '»' => 556, '’' => 222, '‘' => 222, '“' => 333, '”' => 333, '…' => 1000, '®' => 737, '™' => 1000, '©' => 737,
	);

	/**
	 * Fields that are titles, where the "no exclamation marks" rule applies.
	 */
	private const TITLE_FIELDS = array( 'seo_title', 'og_title', 'x_title' );

	/**
	 * Lowercases text and removes punctuation, for comparing words and phrases.
	 *
	 * Apostrophes and hyphens inside words are kept, as Yoast does, so "skin's"
	 * and "anti-ageing" stay one word.
	 *
	 * @param string $text Any text.
	 * @return string Normalised text, words separated by single spaces.
	 */
	public static function normalise( string $text ): string {
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = mb_strtolower( str_replace( array( '’', '‘', '`' ), "'", $text ), 'UTF-8' );
		$text = (string) preg_replace( "/[^\p{L}\p{N}'\-]+/u", ' ', $text );
		$text = (string) preg_replace( "/(?<![\p{L}\p{N}])['\-]+|['\-]+(?![\p{L}\p{N}])/u", ' ', $text );
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Splits text into normalised words.
	 *
	 * @param string $text Any text.
	 * @return array<int, string>
	 */
	public static function words( string $text ): array {
		$normal = self::normalise( $text );
		return '' === $normal ? array() : explode( ' ', $normal );
	}

	/**
	 * Returns the words of a keyphrase that carry meaning (not function words).
	 *
	 * @param string $keyphrase The keyphrase.
	 * @return array<int, string>
	 */
	public static function content_words( string $keyphrase ): array {
		return array_values( array_diff( self::words( $keyphrase ), self::FUNCTION_WORDS ) );
	}

	/**
	 * Measures how wide a title is in Google's results, in pixels (20px Arial).
	 *
	 * This mirrors Yoast's own measurement closely. Real browsers can differ
	 * by a few pixels, so the limits are treated with a small margin.
	 *
	 * @param string $title Plain title text.
	 * @return int Width in pixels, rounded up.
	 */
	public static function title_width( string $title ): int {
		$units = 0;
		foreach ( mb_str_split( $title, 1, 'UTF-8' ) as $char ) {
			if ( isset( self::WIDTHS[ $char ] ) ) {
				$units += self::WIDTHS[ $char ];
			} elseif ( mb_strtoupper( $char, 'UTF-8' ) === $char && mb_strtolower( $char, 'UTF-8' ) !== $char ) {
				$units += 667;
			} else {
				$units += 556;
			}
		}
		return (int) ceil( $units * self::FONT_PX / 1000 );
	}

	/**
	 * Checks a complete answer and returns every check with a pass or fail.
	 *
	 * @param array<string, string> $answer  Values to check: focus_keyphrase, seo_title
	 *                                       (as saved, with Yoast variables), seo_title_rendered
	 *                                       (as Google shows it), meta_description,
	 *                                       social_title, social_description.
	 * @param array<string, mixed>  $context fields (which fields are being written, field => bool)
	 *                                       and taken (keyphrases used elsewhere on the site).
	 * @return array{passed: bool, checks: array<int, array{id: string, field: string, ok: bool, message: string}>}
	 */
	public static function check( array $answer, array $context ): array {
		$fields = (array) ( $context['fields'] ?? array() );
		$taken  = (array) ( $context['taken'] ?? array() );
		$kp     = trim( (string) ( $answer['focus_keyphrase'] ?? '' ) );
		$checks = array();

		$checks = array_merge( $checks, self::check_keyphrase( $kp, $taken ) );

		if ( ! empty( $fields['seo_title'] ) ) {
			$checks = array_merge( $checks, self::check_title( (string) ( $answer['seo_title_rendered'] ?? '' ), $kp ) );
		}

		if ( ! empty( $fields['meta_description'] ) ) {
			$checks = array_merge( $checks, self::check_description( (string) ( $answer['meta_description'] ?? '' ), $kp ) );
		}

		if ( ! empty( $fields['social'] ) ) {
			$checks = array_merge( $checks, self::check_social( (string) ( $answer['social_title'] ?? '' ), (string) ( $answer['social_description'] ?? '' ) ) );
		}

		$brand_fields = array(
			'focus_keyphrase'  => 'focus_keyphrase',
			'seo_title'        => 'seo_title',
			'meta_description' => 'meta_description',
			'social_title'     => 'og_title',
			'social_description' => 'og_description',
		);
		foreach ( $brand_fields as $key => $field ) {
			$group = in_array( $key, array( 'social_title', 'social_description' ), true ) ? 'social' : $key;
			if ( 'focus_keyphrase' !== $key && empty( $fields[ $group ] ) ) {
				continue;
			}
			// The SEO title is checked as written, without Yoast's separator,
			// because the separator is a site setting and not the plugin's text.
			$text   = 'seo_title' === $key ? self::strip_variables( (string) ( $answer['seo_title'] ?? '' ) ) : (string) ( $answer[ $key ] ?? '' );
			$checks = array_merge( $checks, self::check_brand( $text, $field ) );
		}

		$passed = true;
		foreach ( $checks as $check ) {
			if ( ! $check['ok'] ) {
				$passed = false;
				break;
			}
		}

		return array(
			'passed' => $passed,
			'checks' => $checks,
		);
	}

	/**
	 * Returns only the failed checks' messages, for the retry request and reports.
	 *
	 * @param array<int, array<string, mixed>> $checks Checks from check().
	 * @return array<int, string>
	 */
	public static function failures( array $checks ): array {
		$out = array();
		foreach ( $checks as $check ) {
			if ( empty( $check['ok'] ) ) {
				$out[] = (string) $check['message'];
			}
		}
		return $out;
	}

	/**
	 * Checks the keyphrase: length, function words and not used elsewhere.
	 *
	 * @param string             $kp    Keyphrase.
	 * @param array<int, string> $taken Keyphrases used on other items.
	 * @return array<int, array{id: string, field: string, ok: bool, message: string}>
	 */
	private static function check_keyphrase( string $kp, array $taken ): array {
		$min     = max( 1, (int) KDNA_SeoWriter_Settings::get( 'advanced', 'kp_min_words' ) );
		$max     = max( $min, (int) KDNA_SeoWriter_Settings::get( 'advanced', 'kp_max_words' ) );
		$content = self::content_words( $kp );
		$count   = count( $content );
		$checks  = array();

		if ( '' === $kp ) {
			return array( self::result( 'keyphrase_length', 'focus_keyphrase', false, __( 'The focus keyphrase is empty.', 'kdna-seo-writer' ) ) );
		}

		$checks[] = self::result(
			'keyphrase_function_words',
			'focus_keyphrase',
			$count > 0,
			$count > 0
				? __( 'The keyphrase contains words with meaning.', 'kdna-seo-writer' )
				/* translators: %s: the keyphrase. */
				: sprintf( __( 'The keyphrase "%s" is made only of function words such as "the" or "for".', 'kdna-seo-writer' ), $kp )
		);

		$ok       = $count >= $min && $count <= $max;
		$checks[] = self::result(
			'keyphrase_length',
			'focus_keyphrase',
			$ok,
			$ok
				/* translators: %d: number of content words. */
				? sprintf( _n( 'The keyphrase has %d content word.', 'The keyphrase has %d content words.', $count, 'kdna-seo-writer' ), $count )
				/* translators: 1: keyphrase, 2: word count, 3: minimum, 4: maximum. */
				: sprintf( __( 'The keyphrase "%1$s" has %2$d content words. It needs %3$d to %4$d.', 'kdna-seo-writer' ), $kp, $count, $min, $max )
		);

		$normal = self::normalise( $kp );
		$used   = false;
		foreach ( $taken as $other ) {
			if ( self::normalise( (string) $other ) === $normal ) {
				$used = true;
				break;
			}
		}
		$checks[] = self::result(
			'keyphrase_unused',
			'focus_keyphrase',
			! $used,
			$used
				/* translators: %s: keyphrase. */
				? sprintf( __( 'The keyphrase "%s" is already used on another page.', 'kdna-seo-writer' ), $kp )
				: __( 'The keyphrase is not used on any other page.', 'kdna-seo-writer' )
		);

		return $checks;
	}

	/**
	 * Checks the SEO title as Google will show it: keyphrase at the start, and width.
	 *
	 * @param string $rendered Title with Yoast's variables filled in.
	 * @param string $kp       Keyphrase.
	 * @return array<int, array{id: string, field: string, ok: bool, message: string}>
	 */
	private static function check_title( string $rendered, string $kp ): array {
		$checks = array();

		$title_words = self::words( $rendered );
		$kp_words    = self::words( $kp );
		$at_start    = false;

		if ( $kp_words ) {
			// Skip any function words at the very start, as Yoast does.
			$start = 0;
			while ( isset( $title_words[ $start ] ) && in_array( $title_words[ $start ], self::FUNCTION_WORDS, true ) && $title_words[ $start ] !== $kp_words[0] ) {
				++$start;
			}
			$at_start = array_slice( $title_words, $start, count( $kp_words ) ) === $kp_words;
		}

		$checks[] = self::result(
			'title_keyphrase',
			'seo_title',
			$at_start,
			$at_start
				? __( 'The SEO title starts with the keyphrase.', 'kdna-seo-writer' )
				/* translators: %s: keyphrase. */
				: sprintf( __( 'The SEO title must start with the exact keyphrase "%s".', 'kdna-seo-writer' ), $kp )
		);

		$width = self::title_width( $rendered );
		$min   = (int) KDNA_SeoWriter_Settings::get( 'advanced', 'title_min_px' );
		$max   = (int) KDNA_SeoWriter_Settings::get( 'advanced', 'title_max_px' );

		// A margin of 10 pixels on the long side allows for small differences
		// between this measurement and the browser's.
		if ( $width > $max - 10 ) {
			/* translators: 1: title, 2: width in pixels, 3: maximum width. */
			$message = sprintf( __( 'The SEO title "%1$s" is about %2$d pixels wide in Google. It must be under %3$d pixels, so shorten it.', 'kdna-seo-writer' ), $rendered, $width, $max );
			$ok      = false;
		} elseif ( $width < $min ) {
			/* translators: 1: title, 2: width in pixels, 3: minimum width. */
			$message = sprintf( __( 'The SEO title "%1$s" is only about %2$d pixels wide. Yoast wants at least %3$d pixels, so make it a little longer.', 'kdna-seo-writer' ), $rendered, $width, $min );
			$ok      = false;
		} else {
			/* translators: %d: width in pixels. */
			$message = sprintf( __( 'The SEO title is about %d pixels wide, within Yoast\'s range.', 'kdna-seo-writer' ), $width );
			$ok      = true;
		}
		$checks[] = self::result( 'title_width', 'seo_title', $ok, $message );

		return $checks;
	}

	/**
	 * Checks the meta description: length and keyphrase words.
	 *
	 * @param string $description Meta description.
	 * @param string $kp          Keyphrase.
	 * @return array<int, array{id: string, field: string, ok: bool, message: string}>
	 */
	private static function check_description( string $description, string $kp ): array {
		$checks = array();
		$length = mb_strlen( trim( $description ), 'UTF-8' );
		$min    = (int) KDNA_SeoWriter_Settings::get( 'advanced', 'desc_min' );
		$max    = (int) KDNA_SeoWriter_Settings::get( 'advanced', 'desc_max' );
		$ok     = $length > $min && $length <= $max;

		$checks[] = self::result(
			'description_length',
			'meta_description',
			$ok,
			$ok
				/* translators: %d: number of characters. */
				? sprintf( __( 'The meta description is %d characters.', 'kdna-seo-writer' ), $length )
				/* translators: 1: number of characters, 2: minimum, 3: maximum. */
				: sprintf( __( 'The meta description is %1$d characters. It needs %2$d to %3$d.', 'kdna-seo-writer' ), $length, $min + 1, $max )
		);

		$content  = self::content_words( $kp );
		$desc_set = self::words( $description );
		$missing  = array_values( array_diff( $content, $desc_set ) );

		if ( $missing ) {
			$checks[] = self::result(
				'description_keyphrase',
				'meta_description',
				false,
				/* translators: %s: missing words. */
				sprintf( __( 'These keyphrase words are missing from the meta description: %s.', 'kdna-seo-writer' ), '"' . implode( '", "', $missing ) . '"' )
			);
			return $checks;
		}

		// Yoast counts the sentences that contain every keyphrase word, and
		// wants one or two. More than two reads as keyword stuffing.
		$matches   = 0;
		$sentences = preg_split( '/(?<=[.!?])\s+/u', trim( $description ) ) ?: array();
		foreach ( $sentences as $sentence ) {
			if ( ! array_diff( $content, self::words( $sentence ) ) ) {
				++$matches;
			}
		}
		$ok       = $matches >= 1 && $matches <= 2;
		$checks[] = self::result(
			'description_keyphrase',
			'meta_description',
			$ok,
			$ok
				? __( 'The meta description contains the keyphrase.', 'kdna-seo-writer' )
				/* translators: %d: number of sentences. */
				: sprintf( __( 'The keyphrase appears in %d sentences of the meta description. Use it once, or twice at most.', 'kdna-seo-writer' ), $matches )
		);

		return $checks;
	}

	/**
	 * Checks the social title and description are present and not too long.
	 *
	 * @param string $title       Social title.
	 * @param string $description Social description.
	 * @return array<int, array{id: string, field: string, ok: bool, message: string}>
	 */
	private static function check_social( string $title, string $description ): array {
		$title_len = mb_strlen( trim( $title ), 'UTF-8' );
		$desc_len  = mb_strlen( trim( $description ), 'UTF-8' );

		$title_ok = $title_len > 0 && $title_len <= self::SOCIAL_TITLE_MAX;
		$desc_ok  = $desc_len > 0 && $desc_len <= self::SOCIAL_DESC_MAX;

		return array(
			self::result(
				'social_title_length',
				'og_title',
				$title_ok,
				$title_ok
					? __( 'The social title is a good length.', 'kdna-seo-writer' )
					/* translators: 1: number of characters, 2: maximum. */
					: sprintf( __( 'The social title is %1$d characters. It needs 1 to %2$d.', 'kdna-seo-writer' ), $title_len, self::SOCIAL_TITLE_MAX )
			),
			self::result(
				'social_description_length',
				'og_description',
				$desc_ok,
				$desc_ok
					? __( 'The social description is a good length.', 'kdna-seo-writer' )
					/* translators: 1: number of characters, 2: maximum. */
					: sprintf( __( 'The social description is %1$d characters. It needs 1 to %2$d.', 'kdna-seo-writer' ), $desc_len, self::SOCIAL_DESC_MAX )
			),
		);
	}

	/**
	 * Checks one field against the brand rules from the Brand voice tab.
	 *
	 * @param string $text  Field text.
	 * @param string $field Field name, used to decide whether title rules apply.
	 * @return array<int, array{id: string, field: string, ok: bool, message: string}>
	 */
	public static function check_brand( string $text, string $field ): array {
		$problems = self::brand_problems( $text, in_array( $field, self::TITLE_FIELDS, true ) );
		$label    = self::field_label( $field );

		if ( ! $problems ) {
			return array( self::result( 'brand_' . $field, $field, true, __( 'Follows the brand rules.', 'kdna-seo-writer' ) ) );
		}

		$checks = array();
		foreach ( $problems as $problem ) {
			/* translators: 1: field name, 2: problem. */
			$checks[] = self::result( 'brand_' . $field, $field, false, sprintf( __( '%1$s: %2$s', 'kdna-seo-writer' ), $label, $problem ) );
		}
		return $checks;
	}

	/**
	 * Lists every brand rule a piece of text breaks.
	 *
	 * @param string $text     Text to check.
	 * @param bool   $is_title True for titles, where exclamation marks are not allowed.
	 * @return array<int, string> Plain-English problems, empty when the text is fine.
	 */
	public static function brand_problems( string $text, bool $is_title ): array {
		$brand    = (array) KDNA_SeoWriter_Settings::all()['brand'];
		$problems = array();

		foreach ( self::avoid_list() as $phrase ) {
			$pattern = '/(?<![\p{L}\p{N}])' . preg_quote( $phrase, '/' ) . '(?![\p{L}\p{N}])/iu';
			if ( preg_match( $pattern, $text ) ) {
				/* translators: %s: banned word or phrase. */
				$problems[] = sprintf( __( 'uses "%s", which is on the avoid list.', 'kdna-seo-writer' ), $phrase );
			}
		}

		if ( ! empty( $brand['no_em_dashes'] ) && preg_match( '/[\x{2014}\x{2015}]/u', $text ) ) {
			$problems[] = __( 'contains an em dash. Use a comma instead.', 'kdna-seo-writer' );
		}

		if ( $is_title && ! empty( $brand['no_exclaim_titles'] ) && str_contains( $text, '!' ) ) {
			$problems[] = __( 'contains an exclamation mark, which titles must not have.', 'kdna-seo-writer' );
		}

		if ( ! empty( $brand['no_prices_dates'] ) ) {
			if ( self::has_price( $text ) ) {
				$problems[] = __( 'mentions a price or discount, which goes out of date.', 'kdna-seo-writer' );
			}
			if ( self::has_date( $text ) ) {
				$problems[] = __( 'mentions a date or year, which goes out of date.', 'kdna-seo-writer' );
			}
		}

		return $problems;
	}

	/**
	 * Returns the words and phrases on the brand's avoid list.
	 *
	 * @return array<int, string>
	 */
	public static function avoid_list(): array {
		$raw   = (string) KDNA_SeoWriter_Settings::get( 'brand', 'avoid' );
		$items = preg_split( '/[\r\n,]+/', $raw ) ?: array();
		$items = array_map( static fn( $item ) => trim( (string) $item, " \t\"'" ), $items );
		return array_values( array_unique( array_filter( $items ) ) );
	}

	/**
	 * Tells you whether text mentions a price, a currency amount or a discount.
	 *
	 * @param string $text Text.
	 * @return bool
	 */
	public static function has_price( string $text ): bool {
		return (bool) preg_match( '/[$€£¥]\s?\d|\b(?:aud|usd|nzd|gbp|eur|us\$|au\$)\s?\d|\d\s?(?:aud|usd|nzd|gbp|eur|dollars?|cents?)\b|\b\d+(?:\.\d+)?\s?%\s?off\b|\bhalf price\b/iu', $text );
	}

	/**
	 * Tells you whether text mentions a date or a year.
	 *
	 * @param string $text Text.
	 * @return bool
	 */
	public static function has_date( string $text ): bool {
		$months = 'jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?';

		return (bool) preg_match(
			'/\b(?:19|20)\d{2}\b'
			. '|\b\d{1,2}[\/.\-]\d{1,2}[\/.\-]\d{2,4}\b'
			. '|\b\d{1,2}(?:st|nd|rd|th)?\s+(?:' . $months . ')\b'
			. '|\b(?:' . $months . ')\s+\d{1,2}(?:st|nd|rd|th)?\b/iu',
			$text
		);
	}

	/**
	 * Removes Yoast variables such as %%sep%% from a title.
	 *
	 * @param string $title Title with variables.
	 * @return string Title without them.
	 */
	public static function strip_variables( string $title ): string {
		return trim( (string) preg_replace( '/\s*%%[a-z_]+%%/', '', $title ) );
	}

	/**
	 * Returns a readable name for a field, for messages.
	 *
	 * @param string $field Field name.
	 * @return string
	 */
	public static function field_label( string $field ): string {
		$labels = array(
			'focus_keyphrase'  => __( 'Focus keyphrase', 'kdna-seo-writer' ),
			'seo_title'        => __( 'SEO title', 'kdna-seo-writer' ),
			'meta_description' => __( 'Meta description', 'kdna-seo-writer' ),
			'og_title'         => __( 'Social title', 'kdna-seo-writer' ),
			'og_description'   => __( 'Social description', 'kdna-seo-writer' ),
			'x_title'          => __( 'X title', 'kdna-seo-writer' ),
			'x_description'    => __( 'X description', 'kdna-seo-writer' ),
		);
		return $labels[ $field ] ?? $field;
	}

	/**
	 * Builds one check result.
	 *
	 * @param string $id      Check name.
	 * @param string $field   Field it applies to.
	 * @param bool   $ok      Whether it passed.
	 * @param string $message Plain-English explanation.
	 * @return array{id: string, field: string, ok: bool, message: string}
	 */
	private static function result( string $id, string $field, bool $ok, string $message ): array {
		return array(
			'id'      => $id,
			'field'   => $field,
			'ok'      => $ok,
			'message' => $message,
		);
	}
}
