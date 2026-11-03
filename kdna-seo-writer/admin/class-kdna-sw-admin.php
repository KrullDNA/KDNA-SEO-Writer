<?php
/**
 * Admin screens: the settings page.
 *
 * @package KDNA_SeoWriter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds Settings > KDNA SEO Writer. Debug mode lives in KDNA_SeoWriter_Debug.
 *
 * Scripts and styles are only loaded on the plugin's own settings screen,
 * never on other admin screens and never on the front end.
 */
final class KDNA_SeoWriter_Admin {

	/**
	 * Slug of the settings page.
	 */
	public const PAGE = 'kdna-seo-writer';

	/**
	 * Screen ID WordPress gives the settings page, set when the menu is added.
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * The single shared copy of this class.
	 *
	 * @var KDNA_SeoWriter_Admin|null
	 */
	private static ?KDNA_SeoWriter_Admin $instance = null;

	/**
	 * Returns the shared admin object.
	 *
	 * @return KDNA_SeoWriter_Admin
	 */
	public static function instance(): KDNA_SeoWriter_Admin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Registers the admin hooks.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( KDNA_SW_FILE ), array( $this, 'action_links' ) );

		add_action( 'admin_init', array( $this, 'maybe_book_sweep' ) );
		add_action( 'admin_notices', array( $this, 'spend_notice' ) );

		KDNA_SeoWriter_Debug::instance()->hooks();
		KDNA_SeoWriter_Editor::instance()->hooks();
		KDNA_SeoWriter_Lists::instance()->hooks();
	}

	/**
	 * Makes sure the nightly sweep is booked, checking at most twice a day.
	 *
	 * The sweep books its own next run, so this is only a safety net (for
	 * example after the plugin is first switched on, or if the job list was
	 * cleared).
	 *
	 * @return void
	 */
	public function maybe_book_sweep(): void {
		if ( wp_doing_ajax() || get_transient( 'kdna_sw_sweep_check' ) ) {
			return;
		}
		KDNA_SeoWriter_Scheduler::schedule_sweep( false );
		set_transient( 'kdna_sw_sweep_check', 1, 12 * HOUR_IN_SECONDS );
	}

	/**
	 * Shows a notice when the monthly spend limit has paused automatic jobs.
	 *
	 * Shown to administrators on the Dashboard, the Plugins screen and the
	 * plugin's own settings page.
	 *
	 * @return void
	 */
	public function spend_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! current_user_can( 'manage_options' ) || ! in_array( $screen->id, array( 'dashboard', 'plugins', $this->hook_suffix ), true ) ) {
			return;
		}
		if ( ! KDNA_SeoWriter_Usage::limit_reached() ) {
			return;
		}

		$url = admin_url( 'options-general.php?page=' . self::PAGE . '#connection' );
		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'KDNA SEO Writer has paused automatic jobs because this month\'s estimated spend has reached the limit. They start again next month, or when the limit is raised. Generate now still works.', 'kdna-seo-writer' );
		echo ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Change the limit', 'kdna-seo-writer' ) . '</a></p></div>';
	}

	/**
	 * Adds Settings > KDNA SEO Writer.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		// The menu item shows how many items are waiting for review.
		$waiting = KDNA_SeoWriter_Queue::count_items();
		$badge   = $waiting > 0
			? ' <span class="awaiting-mod count-' . (int) $waiting . '"><span class="pending-count" aria-hidden="true">' . (int) $waiting . '</span><span class="screen-reader-text">' . esc_html(
				/* translators: %d: number of items. */
				sprintf( _n( '%d item waiting for review', '%d items waiting for review', $waiting, 'kdna-seo-writer' ), $waiting )
			) . '</span></span>'
			: '';

		$this->hook_suffix = (string) add_options_page(
			__( 'KDNA SEO Writer', 'kdna-seo-writer' ),
			__( 'KDNA SEO Writer', 'kdna-seo-writer' ) . $badge,
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Adds a Settings link beside the plugin on the Plugins screen.
	 *
	 * @param array<int|string, string> $links Existing links.
	 * @return array<int|string, string>
	 */
	public function action_links( array $links ): array {
		$url = admin_url( 'options-general.php?page=' . self::PAGE );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'kdna-seo-writer' ) . '</a>' );
		return $links;
	}

	/**
	 * Loads Alpine.js, the settings app and its styles, on the settings page only.
	 *
	 * @param string $hook_suffix The current admin screen.
	 * @return void
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( '' === $this->hook_suffix || $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'kdna-sw-admin', KDNA_SW_URL . 'admin/css/admin.css', array(), KDNA_SW_VERSION );

		// The settings app registers itself with Alpine, so it loads first and
		// Alpine (bundled inside the plugin, version 3.17.5) loads after it.
		wp_enqueue_script(
			'kdna-sw-settings',
			KDNA_SW_URL . 'admin/js/settings.js',
			array( 'wp-api-fetch' ),
			KDNA_SW_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_enqueue_script(
			'kdna-sw-alpine',
			KDNA_SW_URL . 'admin/js/vendor/alpine.min.js',
			array( 'kdna-sw-settings' ),
			'3.17.5',
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_add_inline_script( 'kdna-sw-settings', 'window.kdnaSwData = ' . wp_json_encode( $this->app_data() ) . ';', 'before' );
	}

	/**
	 * Builds the data the settings app starts with. Never includes the API key.
	 *
	 * @return array<string, mixed>
	 */
	private function app_data(): array {
		$post_types = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( 'attachment' === $type->name ) {
				continue;
			}
			$post_types[] = array(
				'name'  => $type->name,
				'label' => $type->labels->name,
				'mode'  => KDNA_SeoWriter_Settings::mode_for( 'post_type', $type->name ),
			);
		}

		$taxonomies = array();
		foreach ( get_taxonomies(
			array(
				'public'  => true,
				'show_ui' => true,
			),
			'objects'
		) as $taxonomy ) {
			if ( 'post_format' === $taxonomy->name ) {
				continue;
			}
			$taxonomies[] = array(
				'name'  => $taxonomy->name,
				'label' => $taxonomy->labels->name,
				'mode'  => KDNA_SeoWriter_Settings::mode_for( 'taxonomy', $taxonomy->name ),
			);
		}

		$models = array();
		foreach ( KDNA_SeoWriter_Claude::MODELS as $id => $info ) {
			$models[] = array(
				'id'    => $id,
				'label' => $info['label'],
			);
		}

		return array(
			'restPath'     => '/' . KDNA_SW_REST_NAMESPACE,
			'state'        => KDNA_SeoWriter_Rest_Settings::payload(),
			'postTypes'    => $post_types,
			'taxonomies'   => $taxonomies,
			'models'       => $models,
			'integrations' => KDNA_SeoWriter_Plugin::instance()->integrations(),
			'starterRules' => self::starter_rules(),
			'page'         => self::PAGE,
			// Open on the Overview once a key is saved, otherwise on Connection.
			'defaultTab'   => KDNA_SeoWriter_Crypto::has_key() ? 'overview' : 'connection',
			'i18n'         => array(
				'genericError'      => __( 'Something went wrong. Please try again.', 'kdna-seo-writer' ),
				'testError'         => __( 'The test could not run. Please try again.', 'kdna-seo-writer' ),
				'confirmRemoveKey'  => __( 'Remove the saved API key? The plugin will stop writing until a new key is added.', 'kdna-seo-writer' ),
				'confirmClearLocks' => __( 'Clear every lock on the site? Fields people have edited can then be worked on again (existing text is still never replaced without approval).', 'kdna-seo-writer' ),
				/* translators: %d: number of items. */
				'confirmApproveAll' => __( 'Approve all %d items that match the current filters and save them into Yoast? Every change is logged and can be undone.', 'kdna-seo-writer' ),
				'approved'          => __( 'Approved and saved into Yoast.', 'kdna-seo-writer' ),
				/* translators: %d: number of items. */
				'approvedMany'      => __( 'Approved %d items and saved them into Yoast.', 'kdna-seo-writer' ),
				'rejected'          => __( 'Rejected. The same suggestion will not be offered again.', 'kdna-seo-writer' ),
				'regenerating'      => __( 'Asking Claude for fresh suggestions. They appear here in a minute or two.', 'kdna-seo-writer' ),
				/* translators: 1: items done, 2: items in total. */
				'approving'         => __( 'Approving %1$d of %2$d…', 'kdna-seo-writer' ),
				/* translators: %d: number of items. */
				'approveAll'        => __( 'Approve all %d matching', 'kdna-seo-writer' ),
				/* translators: 1: page number, 2: number of pages. */
				'pageOf'            => __( 'Page %1$d of %2$d', 'kdna-seo-writer' ),
				/* translators: 1: number of characters, 2: minimum, 3: maximum. */
				'chars'             => __( '%1$d characters (aim for %2$d to %3$d)', 'kdna-seo-writer' ),
				/* translators: 1: width in pixels, 2: minimum, 3: maximum. */
				'pixels'            => __( 'About %1$d pixels wide in Google (aim for %2$d to %3$d)', 'kdna-seo-writer' ),
				/* translators: 1: input tokens, 2: output tokens, 3: cached tokens. */
				'tokens'            => __( '%1$s tokens in, %2$s out, %3$s read from the cache', 'kdna-seo-writer' ),
				'images'            => __( 'Images', 'kdna-seo-writer' ),
				'copied'            => __( 'Copied', 'kdna-seo-writer' ),
				'statuses'          => array(
					'good' => __( 'Good', 'kdna-seo-writer' ),
					'okay' => __( 'Could be better', 'kdna-seo-writer' ),
					'bad'  => __( 'Needs work', 'kdna-seo-writer' ),
				),
				'flags'             => array(
					'new'       => __( 'New', 'kdna-seo-writer' ),
					'attention' => __( 'Replaces existing text', 'kdna-seo-writer' ),
					'failed'    => __( 'Failed checks', 'kdna-seo-writer' ),
				),
			),
		);
	}

	/**
	 * Starter claim rules for common client types.
	 *
	 * These are a starting point to edit, written as a guide only. They are not
	 * legal advice, and each client's rules should be checked by someone who
	 * knows their industry.
	 *
	 * @return array<int, array{id: string, label: string, text: string}>
	 */
	private static function starter_rules(): array {
		return array(
			array(
				'id'    => 'cosmetics',
				'label' => __( 'Cosmetics (Australia, TGA)', 'kdna-seo-writer' ),
				'text'  => __( "Cosmetic claims only. Never say a product treats, cures, heals, prevents or repairs a disease, condition or skin damage, because the TGA treats those as therapeutic claims.\nDo not name medical conditions such as acne, eczema, rosacea, psoriasis or dermatitis as something the product fixes.\nUse cosmetic wording such as \"improves the look of\", \"helps skin feel\", \"hydrates\" and \"smooths the appearance of\".\nNo claims that the product changes how the skin works or its structure.\nDo not say \"clinically proven\" unless the page itself describes the study.", 'kdna-seo-writer' ),
			),
			array(
				'id'    => 'health',
				'label' => __( 'Health and wellbeing', 'kdna-seo-writer' ),
				'text'  => __( "Never promise a cure or a guaranteed result.\nDo not diagnose, and do not suggest the service replaces medical advice.\nAvoid \"miracle\", \"instant\", \"guaranteed\" and \"100%\".\nDo not name prescription-only medicines in promotional wording.", 'kdna-seo-writer' ),
			),
			array(
				'id'    => 'finance',
				'label' => __( 'Finance', 'kdna-seo-writer' ),
				'text'  => __( "Never promise returns or guaranteed results.\nDo not describe any product as \"risk-free\" or \"safe\".\nWrite as general information, not personal advice.\nAvoid \"best\" or \"cheapest\" unless the page itself backs it up.", 'kdna-seo-writer' ),
			),
		);
	}

	/**
	 * Prints the settings page. The tabs are driven by the Alpine.js app.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		require KDNA_SW_DIR . 'admin/views/settings-page.php';
	}
}
