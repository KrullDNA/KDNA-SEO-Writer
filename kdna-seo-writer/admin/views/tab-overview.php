<?php
/**
 * Overview tab: coverage, problems, usage. Driven by admin/js/settings.js.
 *
 * @package KDNA_SeoWriter
 */

defined( 'ABSPATH' ) || exit;

$kdna_sw_problem_lists = array(
	'duplicate_titles'       => __( 'Duplicate SEO titles', 'kdna-seo-writer' ),
	'duplicate_descriptions' => __( 'Duplicate meta descriptions', 'kdna-seo-writer' ),
	'duplicate_keyphrases'   => __( 'Duplicate focus keyphrases', 'kdna-seo-writer' ),
	'description_lengths'    => __( 'Meta descriptions the wrong length', 'kdna-seo-writer' ),
	'title_widths'           => __( 'SEO titles too wide or too narrow', 'kdna-seo-writer' ),
);
?>
<section class="kdna-sw-panel" role="tabpanel" id="kdna-sw-panel-overview" aria-labelledby="kdna-sw-tab-overview" x-show="tab === 'overview'">
	<p x-show="overview.loading" class="kdna-sw-loading"><?php esc_html_e( 'Loading…', 'kdna-seo-writer' ); ?></p>

	<template x-if="overview.data">
		<div>
			<div class="notice notice-warning inline" x-show="!overview.data.ready.yoast"><p><?php esc_html_e( 'Yoast SEO is not active, so nothing can be saved.', 'kdna-seo-writer' ); ?></p></div>
			<div class="notice notice-warning inline" x-show="!overview.data.ready.key"><p><?php esc_html_e( 'No API key is saved yet. Add one in the Connection tab.', 'kdna-seo-writer' ); ?></p></div>
			<div class="notice notice-warning inline" x-show="!overview.data.ready.scheduler"><p><?php esc_html_e( 'The background job runner (Action Scheduler) is not available, so nothing runs automatically.', 'kdna-seo-writer' ); ?></p></div>
			<div class="notice notice-warning inline" x-show="overview.data.usage.reached"><p><?php esc_html_e( 'This month\'s spend limit has been reached, so automatic jobs are paused until next month. Generate now still works.', 'kdna-seo-writer' ); ?></p></div>

			<div class="kdna-sw-stats">
				<div class="kdna-sw-stat">
					<span class="kdna-sw-stat-number" x-text="overview.data.queue"></span>
					<span class="kdna-sw-stat-label"><?php esc_html_e( 'items waiting for review', 'kdna-seo-writer' ); ?></span>
					<button type="button" class="button-link" @click="go('review')" x-show="overview.data.queue > 0"><?php esc_html_e( 'Open the Review tab', 'kdna-seo-writer' ); ?></button>
				</div>
				<div class="kdna-sw-stat">
					<span class="kdna-sw-stat-number" x-text="'US$' + overview.data.usage.cost.toFixed(2)"></span>
					<span class="kdna-sw-stat-label"><span><?php esc_html_e( 'estimated spend in', 'kdna-seo-writer' ); ?></span> <span x-text="overview.data.usage.month_label"></span></span>
					<span class="kdna-sw-small" x-show="overview.data.usage.limit > 0" x-text="'<?php echo esc_js( __( 'Limit:', 'kdna-seo-writer' ) ); ?> US$' + overview.data.usage.limit"></span>
				</div>
				<div class="kdna-sw-stat">
					<span class="kdna-sw-stat-number" x-text="overview.data.usage.requests"></span>
					<span class="kdna-sw-stat-label"><?php esc_html_e( 'requests to Claude this month', 'kdna-seo-writer' ); ?></span>
					<span class="kdna-sw-small" x-text="tokensLabel()"></span>
				</div>
				<div class="kdna-sw-stat">
					<span class="kdna-sw-stat-number kdna-sw-stat-text" x-text="overview.data.sweep.on ? (overview.data.sweep.next || '<?php echo esc_js( __( 'Not booked yet', 'kdna-seo-writer' ) ); ?>') : '<?php echo esc_js( __( 'Off', 'kdna-seo-writer' ) ); ?>'"></span>
					<span class="kdna-sw-stat-label"><?php esc_html_e( 'next nightly sweep', 'kdna-seo-writer' ); ?></span>
				</div>
			</div>

			<div class="kdna-sw-card">
				<h2><?php esc_html_e( 'Coverage', 'kdna-seo-writer' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Published items with each field filled in Yoast, for every content type that is not Off.', 'kdna-seo-writer' ); ?></p>
				<table class="widefat striped kdna-sw-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Content type', 'kdna-seo-writer' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Items', 'kdna-seo-writer' ); ?></th>
							<th scope="col"><?php esc_html_e( 'SEO title', 'kdna-seo-writer' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Meta description', 'kdna-seo-writer' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Focus keyphrase', 'kdna-seo-writer' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Social', 'kdna-seo-writer' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<template x-for="row in overview.data.coverage" :key="row.name">
							<tr>
								<th scope="row" x-text="row.label"></th>
								<td x-text="row.total"></td>
								<td x-text="percent(row.filled.seo_title, row.total)"></td>
								<td x-text="percent(row.filled.meta_description, row.total)"></td>
								<td x-text="percent(row.filled.focus_keyphrase, row.total)"></td>
								<td x-text="percent(row.filled.social, row.total)"></td>
							</tr>
						</template>
					</tbody>
				</table>
			</div>

			<div class="kdna-sw-card">
				<h2><?php esc_html_e( 'Problems', 'kdna-seo-writer' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Existing metadata that Yoast would mark as a problem. Suggest improvements asks Claude for better versions, which wait in the Review tab: nothing is replaced until you approve it. Up to 50 items are queued per click.', 'kdna-seo-writer' ); ?></p>
				<p>
					<button type="button" class="button button-primary" @click="suggestImprovements()" :disabled="busy || !problemCount()"><?php esc_html_e( 'Suggest improvements for items with problems', 'kdna-seo-writer' ); ?></button>
					<span class="kdna-sw-progress" role="status" aria-live="polite" x-text="overview.message"></span>
				</p>
				<?php foreach ( $kdna_sw_problem_lists as $kdna_sw_key => $kdna_sw_label ) : ?>
					<details class="kdna-sw-problems">
						<summary><?php echo esc_html( $kdna_sw_label ); ?> (<span x-text="overview.data.problems.<?php echo esc_js( $kdna_sw_key ); ?>.length"></span>)</summary>
						<ul>
							<template x-for="(problem, index) in overview.data.problems.<?php echo esc_js( $kdna_sw_key ); ?>" :key="index">
								<li><a :href="problem.edit_url" x-text="problem.title"></a>: <span x-text="problem.issue"></span></li>
							</template>
						</ul>
					</details>
				<?php endforeach; ?>
			</div>
		</div>
	</template>
</section>
