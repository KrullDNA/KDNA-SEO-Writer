<?php
/**
 * Save guard: stops an open editor from wiping values the plugin saved in the background.
 *
 * @package KDNA_SeoWriter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Protects background saves from stale editor screens.
 *
 * Yoast's box in the editor sends every field it holds each time the page is
 * saved, including empty ones, and an empty value overwrites what is stored
 * (checked in Yoast SEO 28.9: WPSEO_Metabox::save_postdata(), the Elementor
 * integration's save_postdata() and WPSEO_Taxonomy::update_term()).
 *
 * So if the plugin fills a field in the background while someone has the page
 * open, their next Update would send the empty field back and wipe it. To stop
 * that, each editor screen records when it was opened (a hidden kdna_sw_loaded
 * field added by the plugin). When the page is saved, any field that:
 * - arrives empty from the editor,
 * - holds text the plugin wrote, unchanged since, and
 * - was written by the plugin after that editor screen was opened,
 * has the stored value put back into the save, so Yoast keeps it. The person could
 * not have seen or cleared that text, so nothing they did is undone. Fields
 * they typed into are always saved as typed.
 */
final class KDNA_SeoWriter_Guard {

	/**
	 * Name of the hidden field holding the time the editor screen was opened.
	 */
	public const FIELD = 'kdna_sw_loaded';

	/**
	 * Prefix of Yoast's post form fields (WPSEO_Meta::$form_prefix).
	 */
	private const POST_FORM_PREFIX = 'yoast_wpseo_';

	/**
	 * Registers the hooks, all running before Yoast's own save.
	 *
	 * @return void
	 */
	public static function hooks(): void {
		// Yoast saves on wp_insert_post at priority 10, Elementor's panel through
		// admin-ajax, and terms on edit_term at priority 99.
		add_action( 'wp_insert_post', array( __CLASS__, 'guard_post' ), 1 );
		add_action( 'wp_ajax_wpseo_elementor_save', array( __CLASS__, 'guard_elementor' ), 1 );
		add_action( 'edit_term', array( __CLASS__, 'guard_term' ), 1, 3 );
	}

	/**
	 * Prints the hidden "screen opened at" field for a form.
	 *
	 * @return void
	 */
	public static function hidden_field(): void {
		echo '<input type="hidden" name="' . esc_attr( self::FIELD ) . '" value="' . esc_attr( (string) time() ) . '" />';
	}

	/**
	 * Guards a post saved from the classic or block editor.
	 *
	 * @param int $post_id Post ID (or a revision of it).
	 * @return void
	 */
	public static function guard_post( $post_id ): void {
		$parent  = wp_is_post_revision( (int) $post_id );
		$post_id = $parent ? (int) $parent : (int) $post_id;

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only removes stale empty values before Yoast's own nonce-checked save.
		if ( ! isset( $_POST['ID'] ) || (int) $_POST['ID'] !== $post_id ) {
			return;
		}
		self::guard_post_fields( $post_id );
	}

	/**
	 * Guards a post saved from Yoast's panel in the Elementor editor.
	 *
	 * @return void
	 */
	public static function guard_elementor(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Yoast checks its own nonce straight after this.
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( $post_id ) {
			self::guard_post_fields( $post_id );
		}
	}

	/**
	 * Removes stale empty fields from a post save.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private static function guard_post_fields( int $post_id ): void {
		$loaded = self::loaded_at();
		if ( ! $loaded || KDNA_SeoWriter_State::get( 'post', $post_id )['generated'] <= $loaded ) {
			return;
		}

		foreach ( KDNA_SeoWriter_Yoast::FIELDS as $field => $keys ) {
			self::maybe_keep( 'post', $post_id, $field, self::POST_FORM_PREFIX . $keys['post'], '' );
		}
	}

	/**
	 * Guards a category, tag or other term saved from its edit screen.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID (unused).
	 * @param string $taxonomy Taxonomy.
	 * @return void
	 */
	public static function guard_term( $term_id, $tt_id, $taxonomy ): void {
		$loaded = self::loaded_at();
		if ( ! $loaded || KDNA_SeoWriter_State::get( 'term', (int) $term_id )['generated'] <= $loaded ) {
			return;
		}

		foreach ( KDNA_SeoWriter_Yoast::FIELDS as $field => $keys ) {
			self::maybe_keep( 'term', (int) $term_id, $field, $keys['term'], (string) $taxonomy );
		}
	}

	/**
	 * Puts the stored value back into the save if the form sent a stale empty value.
	 *
	 * @param string $object_type Either post or term.
	 * @param int    $id          Post or term ID.
	 * @param string $field       Plugin field name.
	 * @param string $form_name   Name of Yoast's form field.
	 * @param string $taxonomy    For terms, the taxonomy.
	 * @return void
	 */
	private static function maybe_keep( string $object_type, int $id, string $field, string $form_name, string $taxonomy ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Only removes stale empty values before Yoast's own nonce-checked save.
		if ( ! isset( $_POST[ $form_name ] ) || ! is_string( $_POST[ $form_name ] ) || '' !== trim( wp_unslash( $_POST[ $form_name ] ) ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only checked for emptiness.
			return;
		}

		$stored = KDNA_SeoWriter_Yoast::get( $object_type, $id, $field, $taxonomy );
		if ( '' !== trim( $stored ) && KDNA_SeoWriter_State::matches( $object_type, $id, $field, $stored ) ) {
			// The stored value is put back into the form rather than removed,
			// because Yoast's term save resets any field it is not sent.
			$_POST[ $form_name ] = wp_slash( $stored );
		}
		// phpcs:enable
	}

	/**
	 * Returns when the editor screen being saved was opened.
	 *
	 * @return int Timestamp, or 0 if the form did not include it.
	 */
	private static function loaded_at(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- A timestamp only, used to decide whether to keep stored values.
		return isset( $_POST[ self::FIELD ] ) ? absint( $_POST[ self::FIELD ] ) : 0;
	}
}
