<?php
/**
 * Advanced tab. Driven by admin/js/settings.js.
 *
 * @package KDNA_SeoWriter
 */

defined( 'ABSPATH' ) || exit;

$kdna_sw_numbers = array(
	'desc_min'     => array( __( 'Meta description: must be longer than (characters)', 'kdna-seo-writer' ), __( 'Yoast\'s default is 120.', 'kdna-seo-writer' ) ),
	'desc_max'     => array( __( 'Meta description: no longer than (characters)', 'kdna-seo-writer' ), __( 'Yoast\'s default is 156.', 'kdna-seo-writer' ) ),
	'title_min_px' => array( __( 'SEO title: at least (pixels wide)', 'kdna-seo-writer' ), __( 'Yoast\'s default is 400.', 'kdna-seo-writer' ) ),
	'title_max_px' => array( __( 'SEO title: under (pixels wide)', 'kdna-seo-writer' ), __( 'Yoast\'s default is 600, roughly what fits in Google\'s results.', 'kdna-seo-writer' ) ),
	'kp_min_words' => array( __( 'Focus keyphrase: fewest meaningful words', 'kdna-seo-writer' ), __( 'Words like "the" and "for" do not count. Default 1.', 'kdna-seo-writer' ) ),
	'kp_max_words' => array( __( 'Focus keyphrase: most meaningful words', 'kdna-seo-writer' ), __( 'Yoast recommends no more than 4.', 'kdna-seo-writer' ) ),
	'alt_max'      => array( __( 'Image alt text: most characters', 'kdna-seo-writer' ), __( 'Default 125, which screen readers handle well.', 'kdna-seo-writer' ) ),
	'max_chars'    => array( __( 'Page text sent to Claude: most characters', 'kdna-seo-writer' ), __( 'Longer pages are trimmed to this, keeping the start, so they cost no more than needed. Default 12,000.', 'kdna-seo-writer' ) ),
);
?>
<section class="kdna-sw-panel" role="tabpanel" id="kdna-sw-panel-advanced" aria-labelledby="kdna-sw-tab-advanced" x-show="tab === 'advanced'">
	<div class="kdna-sw-card">
		<h2><?php esc_html_e( 'Limits', 'kdna-seo-writer' ); ?></h2>
		<p class="description"><?php esc_html_e( 'These match Yoast\'s own checks. Only change them if you have a reason to.', 'kdna-seo-writer' ); ?></p>
		<?php foreach ( $kdna_sw_numbers as $kdna_sw_key => $kdna_sw_text ) : ?>
			<div class="kdna-sw-field">
				<label for="kdna-sw-<?php echo esc_attr( $kdna_sw_key ); ?>"><?php echo esc_html( $kdna_sw_text[0] ); ?></label>
				<input id="kdna-sw-<?php echo esc_attr( $kdna_sw_key ); ?>" type="number" min="0" step="1" class="small-text" x-model.number="settings.advanced.<?php echo esc_attr( $kdna_sw_key ); ?>">
				<p class="description"><?php echo esc_html( $kdna_sw_text[1] ); ?></p>
			</div>
		<?php endforeach; ?>
	</div>

	<div class="kdna-sw-card">
		<h2><?php esc_html_e( 'Title template', 'kdna-seo-writer' ); ?></h2>
		<div class="kdna-sw-field">
			<label for="kdna-sw-title-template"><?php esc_html_e( 'Title template override', 'kdna-seo-writer' ); ?></label>
			<input id="kdna-sw-title-template" type="text" class="regular-text" x-model="settings.advanced.title_template" placeholder="%%title%% %%sep%% %%sitename%%">
			<p class="description"><?php esc_html_e( 'Leave empty to use Yoast\'s own title template for each content type, which is right for almost every site. The separator and site name variables after the title are kept in every SEO title the plugin writes.', 'kdna-seo-writer' ); ?></p>
		</div>
	</div>

	<div class="kdna-sw-card">
		<h2><?php esc_html_e( 'Locks', 'kdna-seo-writer' ); ?></h2>
		<p class="description"><?php esc_html_e( 'When a person edits a field the plugin wrote, that field is locked and automation never touches it again. Clearing the locks lets the plugin work on those fields again. Text that is already filled in is still never replaced without your approval.', 'kdna-seo-writer' ); ?></p>
		<p><button type="button" class="button" @click="clearLocks()" :disabled="busy"><?php esc_html_e( 'Clear all locks', 'kdna-seo-writer' ); ?></button></p>
	</div>

	<div class="kdna-sw-card">
		<h2><?php esc_html_e( 'Uninstall', 'kdna-seo-writer' ); ?></h2>
		<label class="kdna-sw-check"><input type="checkbox" x-model="settings.advanced.delete_data"> <?php esc_html_e( 'Delete the plugin\'s own data when the plugin is deleted', 'kdna-seo-writer' ); ?></label>
		<p class="description"><?php esc_html_e( 'Removes the settings, API key, review queue and change log. Yoast\'s SEO fields are always kept, including everything the plugin wrote.', 'kdna-seo-writer' ); ?></p>
	</div>

	<p><button type="button" class="button button-primary" @click="save()" :disabled="busy"><?php esc_html_e( 'Save changes', 'kdna-seo-writer' ); ?></button></p>
</section>
