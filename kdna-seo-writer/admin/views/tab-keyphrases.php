<?php
/**
 * Keyphrases tab: the keyphrase register and each item's keyphrase report.
 * Driven by admin/js/settings.js.
 *
 * @package KDNA_SeoWriter
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="kdna-sw-panel" role="tabpanel" id="kdna-sw-panel-keyphrases" aria-labelledby="kdna-sw-tab-keyphrases" x-show="tab === 'keyphrases'">
	<div class="kdna-sw-card">
		<h2><?php esc_html_e( 'Keyphrase register', 'kdna-seo-writer' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Every focus keyphrase on the site. Each page should have its own, so Yoast does not warn that one was used before. Claude is always told which ones are taken. Open a report to see where the keyphrase appears in the page itself, with suggested wording to paste in.', 'kdna-seo-writer' ); ?></p>

		<div class="kdna-sw-filters">
			<label>
				<span><?php esc_html_e( 'Search', 'kdna-seo-writer' ); ?></span>
				<input type="search" class="regular-text" x-model="keyphrases.search">
			</label>
			<label class="kdna-sw-check-inline"><input type="checkbox" x-model="keyphrases.duplicatesOnly"> <?php esc_html_e( 'Only duplicates', 'kdna-seo-writer' ); ?></label>
		</div>

		<p x-show="keyphrases.loading" class="kdna-sw-loading"><?php esc_html_e( 'Loading…', 'kdna-seo-writer' ); ?></p>
		<p x-show="!keyphrases.loading && !filteredKeyphrases().length"><?php esc_html_e( 'No keyphrases to show yet.', 'kdna-seo-writer' ); ?></p>

		<table class="widefat striped kdna-sw-table" x-show="filteredKeyphrases().length">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Keyphrase', 'kdna-seo-writer' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Item', 'kdna-seo-writer' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Type', 'kdna-seo-writer' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Report', 'kdna-seo-writer' ); ?></span></th>
				</tr>
			</thead>
			<template x-for="row in filteredKeyphrases()" :key="row.key">
				<tbody>
					<tr>
						<td>
							<span x-text="row.keyphrase"></span>
							<span class="kdna-sw-pill is-bad" x-show="row.duplicate"><?php esc_html_e( 'Used more than once', 'kdna-seo-writer' ); ?></span>
						</td>
						<td><a :href="row.edit_url" x-text="row.title"></a></td>
						<td x-text="row.type_label"></td>
						<td><button type="button" class="button" @click="toggleReport(row)" :aria-expanded="keyphrases.open === row.key ? 'true' : 'false'" x-text="keyphrases.open === row.key ? '<?php echo esc_js( __( 'Hide report', 'kdna-seo-writer' ) ); ?>' : '<?php echo esc_js( __( 'Report', 'kdna-seo-writer' ) ); ?>'"></button></td>
					</tr>
					<tr x-show="keyphrases.open === row.key">
						<td colspan="4">
							<p x-show="!keyphrases.reports[row.key]"><?php esc_html_e( 'Loading…', 'kdna-seo-writer' ); ?></p>
							<template x-if="keyphrases.reports[row.key]">
								<div class="kdna-sw-report-inline">
									<p x-show="keyphrases.reports[row.key].message" x-text="keyphrases.reports[row.key].message"></p>
									<ul class="kdna-sw-report-rows">
										<template x-for="check in keyphrases.reports[row.key].checks" :key="check.id">
											<li :class="'is-' + check.status">
												<strong x-text="check.label"></strong>
												<span class="kdna-sw-pill" :class="check.status === 'good' ? 'is-good' : (check.status === 'okay' ? 'is-wait' : 'is-bad')" x-text="statusLabel(check.status)"></span>
												<p x-text="check.message"></p>
												<p class="kdna-sw-suggest" x-show="check.suggestion">
													<span class="kdna-sw-small" x-text="check.suggestion_label + ':'"></span>
													<code x-text="check.suggestion"></code>
													<button type="button" class="button-link" @click="copy(check.suggestion, $event)"><?php esc_html_e( 'Copy', 'kdna-seo-writer' ); ?></button>
												</p>
												<p x-show="check.can_apply"><button type="button" class="button" @click="applySlug(row)" :disabled="busy"><?php esc_html_e( 'Apply this slug', 'kdna-seo-writer' ); ?></button></p>
												<p class="kdna-sw-warning" x-show="check.warning" x-text="check.warning"></p>
											</li>
										</template>
									</ul>
									<p class="description" x-show="!keyphrases.reports[row.key].has_suggestions"><?php esc_html_e( 'No suggested wording yet. Use Generate now on the item to get some.', 'kdna-seo-writer' ); ?></p>
									<p class="description" x-text="keyphrases.reports[row.key].content_note"></p>
								</div>
							</template>
						</td>
					</tr>
				</tbody>
			</template>
		</table>
	</div>
</section>
