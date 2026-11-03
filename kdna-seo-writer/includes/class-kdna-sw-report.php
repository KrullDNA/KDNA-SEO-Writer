<?php
/**
 * Keyphrase report: the Yoast checks that depend on page content.
 *
 * @package KDNA_SeoWriter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Checks where the focus keyphrase appears in the page itself (introduction,
 * subheadings, slug and image alt text) and offers Claude's suggested wording
 * to paste in. The plugin never edits page content; the only change it can
 * make here is applying a suggested slug to an item that has never been
 * published.
 *
 * The rules mirror Yoast's own analysis (yoastseo package in Yoast SEO 28.9):
 * - Keyphrase in introduction (IntroductionKeywordAssessment): green when
 *   every meaningful word of the keyphrase is in one sentence of the first
 *   paragraph; amber when they are all in the paragraph but spread out.
 * - Keyphrase in subheadings (SubHeadingsKeywordAssessment): green when 30%
 *   to 75% of H2 and H3 subheadings reflect the keyphrase, or the only
 *   subheading does. A short text (under 300 words) with no subheadings is fine.
 * - Keyphrase in slug (UrlKeywordAssessment): keyphrases of one or two words
 *   need every word in the slug; longer ones need more than half.
 * - Keyphrase in image alt text (KeyphraseInImageTextAssessment): with fewer
 *   than 5 images, at least one alt text; with exactly 5, two to four; with
 *   more, 30% to 75% of them.
 *
 * Yoast SEO free matches the exact word forms, as this report does. Yoast
 * Premium also matches other forms of the words (for example "serums" for
 * "serum"), so Premium may show green where this report shows amber.
 */
final class KDNA_SeoWriter_Report {

	/**
	 * Builds the report for one item.
	 *
	 * @param string $object_type Either post or term.
	 * @param int    $id          Post or term ID.
	 * @param string $subtype     Taxonomy, for terms.
	 * @return array<string, mixed>
	 */
	public static function build( string $object_type, int $id, string $subtype = '' ): array {
		$read      = KDNA_SeoWriter_Reader::read( $object_type, $id, $subtype );
		$keyphrase = KDNA_SeoWriter_Yoast::get( $object_type, $id, 'focus_keyphrase', (string) $read['subtype'] );
		$state     = KDNA_SeoWriter_State::get( $object_type, $id );
		$suggest   = $state['suggestions'];

		// Suggestions written for a different keyphrase would point the wrong way.
		if ( ! empty( $suggest['keyphrase'] ) && KDNA_SeoWriter_Rules::normalise( (string) $suggest['keyphrase'] ) !== KDNA_SeoWriter_Rules::normalise( $keyphrase ) ) {
			$suggest = array();
		}

		$report = array(
			'keyphrase'         => $keyphrase,
			'checks'            => array(),
			'has_suggestions'   => ! empty( $suggest ),
			'content_note'      => __( 'These checks look at the page content, which the plugin never changes. Copy any suggested wording into the page yourself.', 'kdna-seo-writer' ),
			'message'           => '',
		);

		if ( '' === $keyphrase ) {
			$report['message'] = __( 'There is no focus keyphrase yet, so there is nothing to check. Generate the metadata first.', 'kdna-seo-writer' );
			return $report;
		}

		$report['checks'][] = self::intro( (string) $read['first_paragraph'], $keyphrase, (string) ( $suggest['opening_line'] ?? '' ) );

		if ( 'post' === $object_type ) {
			$report['checks'][] = self::subheadings( array_merge( (array) $read['h2'], (array) $read['h3'] ), (int) $read['word_count'], $keyphrase, (array) ( $suggest['headings'] ?? array() ) );
		}

		$report['checks'][] = self::slug( $object_type, $id, (string) $read['subtype'], $keyphrase, (string) ( $suggest['slug'] ?? '' ) );

		if ( 'post' === $object_type ) {
			$report['checks'][] = self::images( (array) $read['images'], $keyphrase );
		}

		return $report;
	}

	/**
	 * Applies a suggested slug, only to an item that has never been published.
	 *
	 * Changing the address of a published page breaks every link to it, and
	 * Yoast SEO free has no redirect manager, so that is never done here.
	 *
	 * @param int $post_id Post ID.
	 * @return true|WP_Error
	 */
	public static function apply_slug( int $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return new WP_Error( 'kdna_sw_slug_missing', __( 'This item could not be found.', 'kdna-seo-writer' ) );
		}
		if ( ! self::never_published( $post ) ) {
			return new WP_Error( 'kdna_sw_slug_published', __( 'This item has been published, so its address is not changed automatically. Change it by hand and add a redirect from the old address.', 'kdna-seo-writer' ) );
		}

		$slug = sanitize_title( (string) ( KDNA_SeoWriter_State::get( 'post', $post_id )['suggestions']['slug'] ?? '' ) );
		if ( '' === $slug ) {
			return new WP_Error( 'kdna_sw_slug_none', __( 'There is no suggested slug yet. Click Generate now first.', 'kdna-seo-writer' ) );
		}

		$result = wp_update_post(
			array(
				'ID'        => $post_id,
				'post_name' => $slug,
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		KDNA_SeoWriter_Log::change( 'post', $post_id, $post->post_type, 'slug', $post->post_name, $slug, 'approved' );
		return true;
	}

	/**
	 * Tells you whether a post has never been published.
	 *
	 * @param WP_Post $post The post.
	 * @return bool
	 */
	public static function never_published( WP_Post $post ): bool {
		return in_array( $post->post_status, array( 'draft', 'pending', 'auto-draft' ), true ) && '0000-00-00 00:00:00' === $post->post_date_gmt;
	}

	/**
	 * Keyphrase in the introduction.
	 *
	 * @param string $paragraph  First paragraph.
	 * @param string $keyphrase  Keyphrase.
	 * @param string $suggestion Claude's suggested opening line.
	 * @return array<string, mixed>
	 */
	private static function intro( string $paragraph, string $keyphrase, string $suggestion ): array {
		$words     = KDNA_SeoWriter_Rules::content_words( $keyphrase );
		$sentences = preg_split( '/(?<=[.!?])\s+/u', $paragraph ) ?: array();
		$one       = false;
		foreach ( $sentences as $sentence ) {
			if ( $words && ! array_diff( $words, KDNA_SeoWriter_Rules::words( $sentence ) ) ) {
				$one = true;
				break;
			}
		}
		$spread = ! $one && $words && ! array_diff( $words, KDNA_SeoWriter_Rules::words( $paragraph ) );

		if ( $one ) {
			$status  = 'good';
			$message = __( 'The keyphrase appears in the first paragraph.', 'kdna-seo-writer' );
		} elseif ( $spread ) {
			$status  = 'okay';
			$message = __( 'All the keyphrase words are in the first paragraph, but not in the same sentence. Yoast prefers them together.', 'kdna-seo-writer' );
		} else {
			$status  = 'bad';
			$message = '' === trim( $paragraph ) ? __( 'No first paragraph was found.', 'kdna-seo-writer' ) : __( 'The keyphrase is not in the first paragraph.', 'kdna-seo-writer' );
		}

		return self::check( 'intro', __( 'Keyphrase in introduction', 'kdna-seo-writer' ), $status, $message, 'good' !== $status ? $suggestion : '', __( 'Suggested opening line', 'kdna-seo-writer' ) );
	}

	/**
	 * Keyphrase in subheadings.
	 *
	 * @param array<int, string> $headings   H2 and H3 headings.
	 * @param int                $word_count Words on the page.
	 * @param string             $keyphrase  Keyphrase.
	 * @param array<int, string> $suggested  Claude's suggested headings.
	 * @return array<string, mixed>
	 */
	private static function subheadings( array $headings, int $word_count, string $keyphrase, array $suggested ): array {
		$count   = count( $headings );
		$matches = count( array_filter( $headings, static fn( $heading ) => KDNA_SeoWriter_Alt_Text::mentions( (string) $heading, $keyphrase ) ) );
		$label   = __( 'Keyphrase in subheadings', 'kdna-seo-writer' );
		$tip     = implode( ' | ', array_filter( array_map( 'strval', $suggested ) ) );
		$tip_for = __( 'Suggested headings', 'kdna-seo-writer' );

		if ( 0 === $count ) {
			if ( $word_count < 300 ) {
				return self::check( 'subheadings', $label, 'good', __( 'The text is short, so it does not need subheadings.', 'kdna-seo-writer' ), '', $tip_for );
			}
			return self::check( 'subheadings', $label, 'bad', __( 'The text is long but has no H2 or H3 subheadings. Add some, and use the keyphrase in a few of them.', 'kdna-seo-writer' ), $tip, $tip_for );
		}

		$min = (int) ceil( $count * 0.3 );
		$max = (int) floor( $count * 0.75 );
		/* translators: 1: matching subheadings, 2: all subheadings. */
		$counts = sprintf( __( '%1$d of %2$d subheadings reflect the keyphrase.', 'kdna-seo-writer' ), $matches, $count );

		if ( ( 1 === $count && 1 === $matches ) || ( $count > 1 && $matches >= $min && $matches <= $max ) ) {
			return self::check( 'subheadings', $label, 'good', $counts, '', $tip_for );
		}
		if ( $count > 1 && $matches > $max ) {
			return self::check( 'subheadings', $label, 'bad', $counts . ' ' . __( 'That is too many: vary the wording of some.', 'kdna-seo-writer' ), '', $tip_for );
		}
		return self::check( 'subheadings', $label, 'bad', $counts . ' ' . __( 'Yoast wants between a third and three quarters.', 'kdna-seo-writer' ), $tip, $tip_for );
	}

	/**
	 * Keyphrase in the slug (the address).
	 *
	 * @param string $object_type Either post or term.
	 * @param int    $id          Post or term ID.
	 * @param string $subtype     Post type or taxonomy.
	 * @param string $keyphrase   Keyphrase.
	 * @param string $suggestion  Claude's suggested slug.
	 * @return array<string, mixed>
	 */
	private static function slug( string $object_type, int $id, string $subtype, string $keyphrase, string $suggestion ): array {
		if ( 'term' === $object_type ) {
			$term      = get_term( $id, $subtype );
			$slug      = $term instanceof WP_Term ? $term->slug : '';
			$can_apply = false;
		} else {
			$post      = get_post( $id );
			$slug      = $post instanceof WP_Post ? (string) $post->post_name : '';
			$can_apply = $post instanceof WP_Post && self::never_published( $post );
		}

		$words = array_values( array_unique( KDNA_SeoWriter_Rules::content_words( $keyphrase ) ) );
		$parts = preg_split( '/[-_]+/', strtolower( urldecode( $slug ) ) ) ?: array();
		$found = count( array_intersect( $words, $parts ) );
		$total = count( $words );
		$good  = $total > 0 && ( $total < 3 ? $found === $total : $found / $total > 0.5 );

		$check = self::check(
			'slug',
			__( 'Keyphrase in slug', 'kdna-seo-writer' ),
			$good ? 'good' : ( $found > 0 ? 'okay' : 'bad' ),
			/* translators: %s: the current slug. */
			$good ? sprintf( __( 'The address (%s) contains the keyphrase.', 'kdna-seo-writer' ), $slug ) : sprintf( __( 'The address (%s) does not fully contain the keyphrase.', 'kdna-seo-writer' ), $slug ),
			! $good ? sanitize_title( $suggestion ) : '',
			__( 'Suggested slug', 'kdna-seo-writer' )
		);

		$check['can_apply'] = ! $good && $can_apply && '' !== $suggestion;
		$check['warning']   = ! $good && ! $can_apply && '' !== $suggestion && 'post' === $object_type
			? __( 'This item has been published, so changing its address would break links to it and its place in Google. Yoast SEO free has no redirect manager, so only change it if you also add a redirect from the old address (for example with a redirect plugin).', 'kdna-seo-writer' )
			: '';

		return $check;
	}

	/**
	 * Keyphrase in image alt text.
	 *
	 * @param array<int, array<string, mixed>> $images    Images in the content.
	 * @param string                           $keyphrase Keyphrase.
	 * @return array<string, mixed>
	 */
	private static function images( array $images, string $keyphrase ): array {
		$label = __( 'Keyphrase in image alt text', 'kdna-seo-writer' );
		$count = count( $images );

		if ( 0 === $count ) {
			return self::check( 'images', $label, 'okay', __( 'There are no images in the content. Yoast suggests adding at least one, with alt text.', 'kdna-seo-writer' ), '', '' );
		}

		$with_alt = count( array_filter( $images, static fn( $image ) => '' !== trim( (string) $image['alt'] ) ) );
		$matches  = count( array_filter( $images, static fn( $image ) => KDNA_SeoWriter_Alt_Text::mentions( (string) $image['alt'], $keyphrase ) ) );

		if ( $count < 5 ) {
			$good = $matches > 0;
		} elseif ( 5 === $count ) {
			$good = $matches >= 2 && $matches <= 4;
		} else {
			$good = $matches >= (int) ceil( $count * 0.3 ) && $matches <= (int) floor( $count * 0.75 );
		}

		/* translators: 1: images whose alt text mentions the keyphrase, 2: images, 3: images with any alt text. */
		$message = sprintf( __( '%1$d of %2$d images mention the keyphrase in their alt text (%3$d have alt text).', 'kdna-seo-writer' ), $matches, $count, $with_alt );
		if ( ! $good ) {
			$message .= ' ' . ( $count < 5
				? __( 'Yoast wants at least one, where it truthfully describes the image.', 'kdna-seo-writer' )
				: __( 'Yoast wants roughly a third to three quarters of them, where it truthfully describes the image.', 'kdna-seo-writer' ) );
			$message .= ' ' . __( 'Alt text suggestions for images on this page appear in the Review tab. Images placed in the block editor keep their own copy of the alt text, so after approving, reselect the image in the block (or paste the text in) to update the page.', 'kdna-seo-writer' );
		}

		return self::check( 'images', $label, $good ? 'good' : 'okay', $message, '', '' );
	}

	/**
	 * Builds one report line.
	 *
	 * @param string $id         Check name.
	 * @param string $label      What is checked.
	 * @param string $status     good, okay or bad (Yoast's green, amber and red).
	 * @param string $message    Plain-English result.
	 * @param string $suggestion Wording to copy, or empty.
	 * @param string $for        Label for the suggestion.
	 * @return array<string, mixed>
	 */
	private static function check( string $id, string $label, string $status, string $message, string $suggestion, string $for ): array {
		return array(
			'id'               => $id,
			'label'            => $label,
			'status'           => $status,
			'ok'               => 'good' === $status,
			'message'          => $message,
			'suggestion'       => $suggestion,
			'suggestion_label' => $for,
			'can_apply'        => false,
			'warning'          => '',
		);
	}
}
