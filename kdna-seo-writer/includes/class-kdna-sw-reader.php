<?php
/**
 * Page reader: builds a clean text version of each item, the way a visitor sees it.
 *
 * @package KDNA_SeoWriter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads pages, posts, products, custom post types and terms into clean text.
 *
 * Claude can only write good metadata from what a visitor actually sees, so
 * this class renders the real content (Elementor, blocks, classic editor,
 * JetEngine fields, WooCommerce product text, category descriptions) and strips
 * it down to text. The H1, first paragraph and H2 and H3 headings are returned
 * separately so a keyphrase can be chosen from wording already on the page.
 *
 * It never reads prices, stock levels or sale dates, because those go out of date.
 */
final class KDNA_SeoWriter_Reader {

	/**
	 * Most items listed when reading a category or tag.
	 */
	private const TERM_ITEMS = 20;

	/**
	 * Elementor widget settings that usually hold visible text, used only when
	 * Elementor's own renderer returns nothing.
	 */
	private const ELEMENTOR_TEXT_KEYS = array(
		'title',
		'editor',
		'text',
		'description',
		'description_text',
		'title_text',
		'content',
		'tab_title',
		'tab_content',
		'testimonial_content',
		'blockquote_content',
		'caption',
		'inner_text',
		'alert_title',
		'alert_description',
		'heading',
		'sub_heading',
		'button_text',
		'prefix',
		'suffix',
	);

	/**
	 * Reads any supported item.
	 *
	 * @param string $object_type Either post or term.
	 * @param int    $id          The post ID or term ID.
	 * @param string $taxonomy    For terms, the taxonomy (optional but faster).
	 * @return array<string, mixed> See result() for the shape.
	 */
	public static function read( string $object_type, int $id, string $taxonomy = '' ): array {
		return 'term' === $object_type ? self::read_term( $id, $taxonomy ) : self::read_post( $id );
	}

	/**
	 * Reads a post, page, product or custom post type item.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>
	 */
	public static function read_post( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return self::skipped( 'post', $post_id, 'not_found', __( 'This item could not be found.', 'kdna-seo-writer' ) );
		}

		if ( 'attachment' === $post->post_type ) {
			return self::skipped( 'post', $post_id, 'not_supported', __( 'Images are handled by the alt text writer, not the page reader.', 'kdna-seo-writer' ), $post->post_type );
		}

		if ( '' !== $post->post_password ) {
			return self::skipped( 'post', $post_id, 'password', __( 'This item is password protected, so it is not sent to Claude.', 'kdna-seo-writer' ), $post->post_type );
		}

		$sources = array();
		$parts   = array();
		$body    = self::elementor_html( $post_id );

		if ( '' !== $body ) {
			$sources[] = 'elementor';
		} else {
			$body      = self::editor_html( $post );
			$sources[] = has_blocks( $post->post_content ) ? 'blocks' : 'classic';
		}

		if ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
			$product = self::product_html( $post_id );
			if ( '' !== $product['short'] ) {
				$parts[] = $product['short'];
			}
			$parts[]   = $body;
			$parts[]   = $product['facts'];
			$sources[] = 'woocommerce';
		} else {
			$parts[] = $body;
		}

		$jet = self::jetengine_html( $post_id, $post->post_type );
		if ( '' !== $jet ) {
			$parts[]   = $jet;
			$sources[] = 'jetengine';
		}

		$html = implode( "\n", array_filter( $parts ) );

		return self::result(
			array(
				'object_type' => 'post',
				'object_id'   => $post_id,
				'subtype'     => $post->post_type,
				'title'       => get_the_title( $post ),
				'url'         => (string) get_permalink( $post ),
				'sources'     => $sources,
			),
			$html
		);
	}

	/**
	 * Reads a category, tag or custom taxonomy term.
	 *
	 * Uses the term's name, its description and the titles of the published
	 * items inside it.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy name, if known.
	 * @return array<string, mixed>
	 */
	public static function read_term( int $term_id, string $taxonomy = '' ): array {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof WP_Term ) {
			return self::skipped( 'term', $term_id, 'not_found', __( 'This category or tag could not be found.', 'kdna-seo-writer' ) );
		}

		$html  = '<h1>' . esc_html( $term->name ) . '</h1>';
		$html .= wpautop( do_shortcode( (string) $term->description ) );

		$titles = self::term_item_titles( $term );
		if ( $titles ) {
			$html .= '<p>' . esc_html__( 'Items in this group:', 'kdna-seo-writer' ) . ' ' . esc_html( implode( ', ', $titles ) ) . '</p>';
		}

		$link = get_term_link( $term );

		return self::result(
			array(
				'object_type' => 'term',
				'object_id'   => $term_id,
				'subtype'     => $term->taxonomy,
				'title'       => $term->name,
				'url'         => is_wp_error( $link ) ? '' : (string) $link,
				'sources'     => array( 'term' ),
			),
			$html
		);
	}

	/**
	 * Renders an Elementor page into HTML, if the item is built with Elementor.
	 *
	 * Elementor keeps its text in its own data (the _elementor_data field), not
	 * in the normal content box, so reading the content box alone would give
	 * Claude nothing.
	 *
	 * Confirmed against Elementor 4.5.0 (includes/frontend.php):
	 * Plugin::$instance->frontend->get_builder_content_for_display( $post_id )
	 * is Elementor's public way to render a document's content. It returns an
	 * empty string when the post being rendered is the global post (to stop a
	 * page rendering itself), so the global post is cleared while it runs.
	 *
	 * If the renderer returns nothing, the widget data is read directly instead.
	 *
	 * @param int $post_id Post ID.
	 * @return string HTML, or an empty string if the item is not an Elementor item.
	 */
	private static function elementor_html( int $post_id ): string {
		if ( ! KDNA_SeoWriter_Plugin::instance()->has( 'elementor' ) ) {
			return '';
		}

		try {
			$elementor = \Elementor\Plugin::$instance;
			$document  = $elementor->documents->get( $post_id );
			if ( ! $document || ! $document->is_built_with_elementor() ) {
				return '';
			}

			$html = '';
			if ( isset( $elementor->frontend ) ) {
				$saved_post      = $GLOBALS['post'] ?? null;
				$GLOBALS['post'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restored straight after rendering.

				$html = (string) $elementor->frontend->get_builder_content_for_display( $post_id, false );

				$GLOBALS['post'] = $saved_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the original value.
			}

			if ( '' === trim( wp_strip_all_tags( $html ) ) ) {
				$html = self::elementor_data_html( $post_id );
			}

			return $html;
		} catch ( \Throwable $e ) {
			return self::elementor_data_html( $post_id );
		}
	}

	/**
	 * Builds simple HTML straight from Elementor's saved widget data.
	 *
	 * This is a back-up for when Elementor's renderer cannot run (for example a
	 * widget that errors in a background job). Heading widgets keep their
	 * heading level so H1, H2 and H3 detection still works.
	 *
	 * @param int $post_id Post ID.
	 * @return string HTML.
	 */
	private static function elementor_data_html( int $post_id ): string {
		$raw  = get_post_meta( $post_id, '_elementor_data', true );
		$data = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		if ( ! is_array( $data ) ) {
			return '';
		}

		$html = '';
		self::walk_elementor( $data, $html );
		return $html;
	}

	/**
	 * Walks Elementor's element tree collecting visible text, in page order.
	 *
	 * @param array<int|string, mixed> $elements Elements at this level.
	 * @param string                   $html     HTML collected so far.
	 * @return void
	 */
	private static function walk_elementor( array $elements, string &$html ): void {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
			$widget   = (string) ( $element['widgetType'] ?? '' );

			if ( 'heading' === $widget && ! empty( $settings['title'] ) && is_string( $settings['title'] ) ) {
				// Elementor leaves header_size out of the saved data when the
				// widget uses its default level, which is H2.
				$level = (string) ( $settings['header_size'] ?? 'h2' );
				$level = in_array( $level, array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) ? $level : 'h2';
				$html .= '<' . $level . '>' . $settings['title'] . '</' . $level . '>';
			} elseif ( $settings ) {
				$html .= self::elementor_settings_html( $settings );
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				self::walk_elementor( $element['elements'], $html );
			}
		}
	}

	/**
	 * Collects text from an Elementor widget's settings, including repeater rows.
	 *
	 * @param array<string, mixed> $settings Widget settings.
	 * @return string HTML.
	 */
	private static function elementor_settings_html( array $settings ): string {
		$html = '';
		foreach ( $settings as $key => $value ) {
			if ( is_string( $value ) && in_array( $key, self::ELEMENTOR_TEXT_KEYS, true ) && '' !== trim( $value ) ) {
				$html .= '<p>' . $value . '</p>';
			} elseif ( is_array( $value ) && array_is_list( $value ) ) {
				foreach ( $value as $row ) {
					if ( is_array( $row ) ) {
						$html .= self::elementor_settings_html( $row );
					}
				}
			}
		}
		return $html;
	}

	/**
	 * Renders block editor or classic editor content into HTML.
	 *
	 * Blocks and shortcodes are rendered, but the full the_content filter is
	 * not run, because other plugins hook into it to add share buttons, related
	 * posts and so on, which are not part of the page's own content.
	 *
	 * @param WP_Post $post The post.
	 * @return string HTML.
	 */
	private static function editor_html( WP_Post $post ): string {
		$content = (string) $post->post_content;
		if ( '' === trim( $content ) ) {
			return '';
		}

		$saved_post      = $GLOBALS['post'] ?? null;
		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Some blocks read the global post. Restored below.
		setup_postdata( $post );

		try {
			$html = has_blocks( $content ) ? do_blocks( $content ) : wpautop( $content );
			$html = do_shortcode( $html );
		} catch ( \Throwable $e ) {
			$html = wpautop( strip_shortcodes( $content ) );
		}

		$GLOBALS['post'] = $saved_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the original value.
		if ( $saved_post instanceof WP_Post ) {
			setup_postdata( $saved_post );
		}

		return $html;
	}

	/**
	 * Builds the WooCommerce product text: short description and product facts.
	 *
	 * Only text that does not go out of date is used: categories and attributes
	 * (such as size or skin type). Price, stock and sale dates are never read.
	 * The long description is the product's main content, read by the caller.
	 *
	 * @param int $post_id Product ID.
	 * @return array{short: string, facts: string}
	 */
	private static function product_html( int $post_id ): array {
		$out     = array(
			'short' => '',
			'facts' => '',
		);
		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			return $out;
		}

		$out['short'] = wpautop( do_shortcode( (string) $product->get_short_description() ) );

		$facts      = array();
		$categories = wp_get_post_terms( $post_id, 'product_cat', array( 'fields' => 'names' ) );
		if ( ! is_wp_error( $categories ) && $categories ) {
			$facts[] = __( 'Categories:', 'kdna-seo-writer' ) . ' ' . implode( ', ', $categories );
		}

		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! is_object( $attribute ) || ! method_exists( $attribute, 'get_name' ) || ! $attribute->get_visible() ) {
				continue;
			}
			if ( $attribute->is_taxonomy() ) {
				$values = wc_get_product_terms( $post_id, $attribute->get_name(), array( 'fields' => 'names' ) );
			} else {
				$values = $attribute->get_options();
			}
			$values = array_filter( array_map( 'strval', (array) $values ) );
			if ( $values ) {
				$facts[] = wc_attribute_label( $attribute->get_name(), $product ) . ': ' . implode( ', ', $values );
			}
		}

		if ( $facts ) {
			$out['facts'] = '<p>' . implode( '</p><p>', array_map( 'esc_html', $facts ) ) . '</p>';
		}

		return $out;
	}

	/**
	 * Reads the JetEngine fields chosen for this post type in the Content tab.
	 *
	 * Fields are named as in JetEngine. Repeater sub-fields use dot notation,
	 * the same approach as KDNA Reading Time: "faq.answer" reads the answer
	 * sub-field from every row of the faq repeater. JetEngine saves meta fields
	 * and repeaters as normal post meta (repeaters as an array of rows), so they
	 * are read with get_post_meta and work even if JetEngine is switched off.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $post_type Post type.
	 * @return string HTML with one paragraph per value.
	 */
	private static function jetengine_html( int $post_id, string $post_type ): string {
		$map = KDNA_SeoWriter_Settings::get( 'content', 'jet_fields' );
		if ( ! is_array( $map ) || empty( $map[ $post_type ] ) ) {
			return '';
		}

		$html  = '';
		$paths = array_filter( array_map( 'trim', explode( ',', (string) $map[ $post_type ] ) ) );

		foreach ( $paths as $path ) {
			$keys  = explode( '.', $path );
			$base  = array_shift( $keys );
			$value = get_post_meta( $post_id, $base, true );

			foreach ( self::dig( $value, $keys ) as $text ) {
				$html .= '<p>' . $text . '</p>';
			}
		}

		return $html;
	}

	/**
	 * Follows a dot notation path into a field value and returns every text found.
	 *
	 * When a step is not found directly, each repeater row is searched instead,
	 * so "faq.answer" works for a repeater whatever its row keys are.
	 *
	 * @param mixed             $value Field value (text or array).
	 * @param array<int,string> $path  Remaining path steps.
	 * @return array<int, string> Text values.
	 */
	private static function dig( $value, array $path ): array {
		if ( ! $path ) {
			return self::strings_in( $value );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}

		$key = array_shift( $path );
		if ( array_key_exists( $key, $value ) ) {
			return self::dig( $value[ $key ], $path );
		}

		$found = array();
		foreach ( $value as $row ) {
			if ( is_array( $row ) && array_key_exists( $key, $row ) ) {
				$found = array_merge( $found, self::dig( $row[ $key ], $path ) );
			}
		}
		return $found;
	}

	/**
	 * Returns every piece of readable text inside a value, skipping IDs and numbers.
	 *
	 * @param mixed $value Text or array.
	 * @return array<int, string>
	 */
	private static function strings_in( $value ): array {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $item ) {
				$out = array_merge( $out, self::strings_in( $item ) );
			}
			return $out;
		}

		if ( ! is_string( $value ) ) {
			return array();
		}

		$value = trim( $value );
		if ( '' === $value || is_numeric( $value ) || ! preg_match( '/\p{L}/u', $value ) ) {
			return array();
		}

		return array( $value );
	}

	/**
	 * Returns the titles of published items in a term, newest first.
	 *
	 * @param WP_Term $term The term.
	 * @return array<int, string>
	 */
	private static function term_item_titles( WP_Term $term ): array {
		$taxonomy = get_taxonomy( $term->taxonomy );
		if ( ! $taxonomy || empty( $taxonomy->object_type ) ) {
			return array();
		}

		$ids = get_posts(
			array(
				'post_type'        => $taxonomy->object_type,
				'post_status'      => 'publish',
				'posts_per_page'   => self::TERM_ITEMS,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'has_password'     => false,
				'suppress_filters' => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Background job, limited to 20 items.
				'tax_query'        => array(
					array(
						'taxonomy'         => $term->taxonomy,
						'terms'            => array( $term->term_id ),
						'include_children' => true,
					),
				),
			)
		);

		return array_values( array_filter( array_map( 'get_the_title', $ids ) ) );
	}

	/**
	 * Turns rendered HTML into the reader's final answer.
	 *
	 * @param array<string, mixed> $item Item details (object type, ID, title, URL, sources).
	 * @param string               $html Rendered HTML of the item's content.
	 * @return array{
	 *     ok: bool,
	 *     reason: string,
	 *     message: string,
	 *     object_type: string,
	 *     object_id: int,
	 *     subtype: string,
	 *     title: string,
	 *     url: string,
	 *     sources: array<int, string>,
	 *     h1: string,
	 *     h1_from: string,
	 *     first_paragraph: string,
	 *     h2: array<int, string>,
	 *     h3: array<int, string>,
	 *     text: string,
	 *     word_count: int,
	 *     trimmed: bool
	 * }
	 */
	private static function result( array $item, string $html ): array {
		$html = self::remove_hidden_parts( $html );

		$h1 = self::tag_texts( $html, 'h1' );
		$h2 = self::tag_texts( $html, 'h2' );
		$h3 = self::tag_texts( $html, 'h3' );

		$text       = self::html_to_text( $html );
		$word_count = self::count_words( $text );
		$max_chars  = max( 1000, (int) KDNA_SeoWriter_Settings::get( 'advanced', 'max_chars' ) );
		$min_words  = (int) KDNA_SeoWriter_Settings::get( 'content', 'min_words' );
		$trimmed    = mb_strlen( $text ) > $max_chars;

		$result = array_merge(
			array(
				'ok'      => true,
				'reason'  => '',
				'message' => '',
			),
			$item,
			array(
				// Most themes print the title as the page's H1, so it is used
				// when the content itself has no H1.
				'h1'              => $h1 ? $h1[0] : (string) $item['title'],
				'h1_from'         => $h1 ? 'content' : 'title',
				'first_paragraph' => self::first_paragraph( $html ),
				'h2'              => $h2,
				'h3'              => $h3,
				'text'            => $trimmed ? self::trim_text( $text, $max_chars ) : $text,
				'word_count'      => $word_count,
				'trimmed'         => $trimmed,
			)
		);

		if ( $word_count < $min_words ) {
			$result['ok']     = false;
			$result['reason'] = 'not_enough_content';
			/* translators: 1: number of words found, 2: minimum number of words. */
			$result['message'] = sprintf( __( 'Not enough content: %1$d words found, %2$d needed to describe it well.', 'kdna-seo-writer' ), $word_count, $min_words );
		}

		return $result;
	}

	/**
	 * Builds the answer for an item that cannot be read at all.
	 *
	 * @param string $object_type Either post or term.
	 * @param int    $id          Item ID.
	 * @param string $reason      Short reason code.
	 * @param string $message     Plain-English explanation.
	 * @param string $subtype     Post type or taxonomy, if known.
	 * @return array<string, mixed>
	 */
	private static function skipped( string $object_type, int $id, string $reason, string $message, string $subtype = '' ): array {
		return array(
			'ok'              => false,
			'reason'          => $reason,
			'message'         => $message,
			'object_type'     => $object_type,
			'object_id'       => $id,
			'subtype'         => $subtype,
			'title'           => '',
			'url'             => '',
			'sources'         => array(),
			'h1'              => '',
			'h1_from'         => '',
			'first_paragraph' => '',
			'h2'              => array(),
			'h3'              => array(),
			'text'            => '',
			'word_count'      => 0,
			'trimmed'         => false,
		);
	}

	/**
	 * Removes parts of the HTML a visitor does not read as content.
	 *
	 * Scripts, styles, inline SVG icons, forms' hidden templates and navigation
	 * menus are removed so they do not pollute the text sent to Claude.
	 *
	 * @param string $html HTML.
	 * @return string HTML without those parts.
	 */
	private static function remove_hidden_parts( string $html ): string {
		$html = (string) preg_replace( '#<(script|style|noscript|template|svg|nav|iframe|object)\b[^>]*>.*?</\1>#is', ' ', $html );
		$html = (string) preg_replace( '#<!--.*?-->#s', ' ', $html );

		// Shortcodes from switched-off plugins are left in the content as plain
		// [tags]. They mean nothing to a visitor, so they are removed.
		return (string) preg_replace( '#\[/?[a-z][a-z0-9_\-]*(?:\s[^\]]*)?\]#i', ' ', $html );
	}

	/**
	 * Returns the text inside every tag of one kind, such as all H2 headings.
	 *
	 * @param string $html HTML.
	 * @param string $tag  Tag name, such as h2.
	 * @return array<int, string> Clean texts, empty ones removed.
	 */
	private static function tag_texts( string $html, string $tag ): array {
		if ( ! preg_match_all( '#<' . $tag . '\b[^>]*>(.*?)</' . $tag . '>#is', $html, $matches ) ) {
			return array();
		}
		$texts = array_map( array( __CLASS__, 'inline_text' ), $matches[1] );
		return array_values( array_unique( array_filter( $texts ) ) );
	}

	/**
	 * Returns the first real paragraph of the content.
	 *
	 * Yoast's "keyphrase in introduction" check looks at the first paragraph,
	 * so this is passed to Claude on its own. Very short paragraphs (like a
	 * single label) are skipped.
	 *
	 * @param string $html HTML.
	 * @return string Text of the first paragraph, or the start of the text if there are no paragraphs.
	 */
	private static function first_paragraph( string $html ): string {
		if ( preg_match_all( '#<p\b[^>]*>(.*?)</p>#is', $html, $matches ) ) {
			foreach ( $matches[1] as $paragraph ) {
				$text = self::inline_text( $paragraph );
				if ( self::count_words( $text ) >= 5 ) {
					return $text;
				}
			}
		}

		$lines = preg_split( '/\n+/', self::html_to_text( $html ) ) ?: array();
		foreach ( $lines as $line ) {
			if ( self::count_words( $line ) >= 5 ) {
				return trim( $line );
			}
		}
		return '';
	}

	/**
	 * Converts a small piece of HTML (one heading or paragraph) to single-line text.
	 *
	 * @param string $html HTML fragment.
	 * @return string Text on one line.
	 */
	private static function inline_text( string $html ): string {
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Converts HTML into clean text, keeping paragraph breaks.
	 *
	 * @param string $html HTML.
	 * @return string Plain text.
	 */
	public static function html_to_text( string $html ): string {
		// Put a line break where block-level elements start or end, so words
		// from neighbouring paragraphs or cells do not run together.
		$html = (string) preg_replace( '#<(br|hr)\b[^>]*>#i', "\n", $html );
		$html = (string) preg_replace( '#</?(p|div|section|article|header|footer|aside|h[1-6]|li|ul|ol|dl|dt|dd|tr|td|th|table|blockquote|figure|figcaption|pre)\b[^>]*>#i', "\n", $html );

		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( "\u{00A0}", ' ', $text );
		$text = (string) preg_replace( '/[ \t]+/u', ' ', $text );
		$text = (string) preg_replace( '/ *\n */u', "\n", $text );
		$text = (string) preg_replace( '/\n{3,}/u', "\n\n", $text );

		return trim( $text );
	}

	/**
	 * Counts the words in a piece of text.
	 *
	 * @param string $text Plain text.
	 * @return int Number of words.
	 */
	public static function count_words( string $text ): int {
		return (int) preg_match_all( '/[\p{L}\p{N}][\p{L}\p{N}\'’\-]*/u', $text );
	}

	/**
	 * Shortens text to a maximum length, ending on a whole word.
	 *
	 * Long pages are trimmed so they do not cost more than they need to. The
	 * start of a page says the most about it, so the start is kept.
	 *
	 * @param string $text      Plain text.
	 * @param int    $max_chars Maximum number of characters.
	 * @return string Shortened text.
	 */
	private static function trim_text( string $text, int $max_chars ): string {
		$cut   = mb_substr( $text, 0, $max_chars );
		$space = mb_strrpos( $cut, ' ' );
		if ( false !== $space && $space > $max_chars * 0.8 ) {
			$cut = mb_substr( $cut, 0, $space );
		}
		return rtrim( $cut ) . ' …';
	}
}
