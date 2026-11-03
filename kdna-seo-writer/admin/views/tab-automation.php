<?php
/**
 * Automation tab. Driven by admin/js/settings.js.
 *
 * @package KDNA_SeoWriter
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="kdna-sw-panel" role="tabpanel" id="kdna-sw-panel-automation" aria-labelledby="kdna-sw-tab-automation" x-show="tab === 'automation'">
	<div class="kdna-sw-card">
		<h2><?php esc_html_e( 'When the plugin runs', 'kdna-seo-writer' ); ?></h2>

		<label class="kdna-sw-check"><input type="checkbox" x-model="settings.automation.on_publish"> <?php esc_html_e( 'Generate on publish and update', 'kdna-seo-writer' ); ?></label>
		<p class="description"><?php esc_html_e( 'When an item is published or updated with an empty field, a background job runs about two minutes later. Saving is never slowed down.', 'kdna-seo-writer' ); ?></p>

		<label class="kdna-sw-check"><input type="checkbox" x-model="settings.automation.nightly"> <?php esc_html_e( 'Nightly sweep', 'kdna-seo-writer' ); ?></label>
		<p class="description"><?php esc_html_e( 'Each night the plugin finds items that still have empty fields and works through a set number of them, one at a time, so costs stay predictable. Items that were tried in the last week are left until later.', 'kdna-seo-writer' ); ?></p>

		<div class="kdna-sw-field">
			<label for="kdna-sw-sweep-time"><?php esc_html_e( 'Sweep start time (site time)', 'kdna-seo-writer' ); ?></label>
			<input id="kdna-sw-sweep-time" type="time" x-model="settings.automation.sweep_time">
			<p class="description"><?php esc_html_e( 'Pick a quiet hour for the site. The default is 2am.', 'kdna-seo-writer' ); ?></p>
		</div>

		<div class="kdna-sw-field">
			<label for="kdna-sw-per-night"><?php esc_html_e( 'Items per night', 'kdna-seo-writer' ); ?></label>
			<input id="kdna-sw-per-night" type="number" min="0" step="1" class="small-text" x-model.number="settings.automation.per_night">
			<p class="description"><?php esc_html_e( 'Most items the sweep works on each night. With Claude Haiku 5.5, 50 items usually cost a few cents.', 'kdna-seo-writer' ); ?></p>
		</div>
	</div>

	<div class="kdna-sw-card">
		<h2><?php esc_html_e( 'Other', 'kdna-seo-writer' ); ?></h2>

		<label class="kdna-sw-check"><input type="checkbox" x-model="settings.automation.alt_text"> <?php esc_html_e( 'Write image alt text', 'kdna-seo-writer' ); ?></label>
		<p class="description"><?php esc_html_e( 'Suggests alt text for images that have none, written from the image itself and the page it is on. Every suggestion waits in the Review tab. Images on items being processed go first, then featured images and the rest of the media library in the nightly sweep. Mark an image Decorative in the media library to keep its alt text empty on purpose.', 'kdna-seo-writer' ); ?></p>

		<label class="kdna-sw-check"><input type="checkbox" x-model="settings.automation.rocket_purge"> <?php esc_html_e( 'Clear the WP Rocket cache after each change', 'kdna-seo-writer' ); ?></label>
		<p class="description"><?php esc_html_e( 'Clears only the one page that changed, never the whole site, so visitors and Google see the new tags straight away.', 'kdna-seo-writer' ); ?></p>
	</div>

	<p><button type="button" class="button button-primary" @click="save()" :disabled="busy"><?php esc_html_e( 'Save changes', 'kdna-seo-writer' ); ?></button></p>
</section>
