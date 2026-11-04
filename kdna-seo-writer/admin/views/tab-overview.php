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

			<div class="kdna-sw-card kdna-sw-fill">
				<h2><?php esc_html_e( 'Fill empty fields now', 'kdna-seo-writer' ); ?></h2>
				<p class="description"><?php esc_html_e( 'For the first run on a site. Works through every item with empty fields, and every image with no alt text, straight away instead of 50 a night. When it reaches the end it goes back for anything that could not be finished, so one click covers the whole site. Only empty fields are filled: text that is already there, or that a person has edited, is never touched. Blog posts are saved straight away, and everything else waits in the Review tab, as does all alt text. Jobs run one at a time, spaced out, and pause if the spend limit is reached.', 'kdna-seo-writer' ); ?></p>

				<div class="notice notice-warning inline" x-show="fill.problem"><p x-text="fill.problem"></p></div>

				<template x-if="fill.status.running">
					<div>
						<div class="kdna-sw-meter kdna-sw-fill-meter" aria-hidden="true"><div class="kdna-sw-meter-bar is-good" :style="'width:' + fillPercent() + '%'"></div></div>
						<p role="status" aria-live="polite"><strong x-text="fillProgress()"></strong> <span class="kdna-sw-small" x-text="fill.status.started ? '<?php echo esc_js( __( 'Started', 'kdna-seo-writer' ) ); ?> ' + fill.status.started : ''"></span></p>
						<p class="kdna-sw-small" x-show="fillPass()" x-text="fillPass()"></p>
						<p class="description"><?php esc_html_e( 'This keeps going in the background, so you can close this page. Leaving it open, or keeping the site busy, can make it go a little faster on quiet sites. Results appear in the Review tab as they arrive.', 'kdna-seo-writer' ); ?></p>
						<p><button type="button" class="button" @click="stopFill()" :disabled="busy"><?php esc_html_e( 'Stop', 'kdna-seo-writer' ); ?></button></p>
					</div>
				</template>

				<template x-if="!fill.status.running">
					<div>
						<p x-show="fill.status.finished" class="kdna-sw-small" x-text="fillFinished()"></p>
						<p x-show="fill.status.stopped" class="kdna-sw-small"><?php esc_html_e( 'The last run was stopped. Anything already saved or waiting in Review stays as it is.', 'kdna-seo-writer' ); ?></p>

						<p x-show="!fill.preview">
							<button type="button" class="button" @click="loadFillPreview()" :disabled="fill.loading || !!fill.problem">
								<span x-show="!fill.loading"><?php esc_html_e( 'Check what needs doing', 'kdna-seo-writer' ); ?></span>
								<span x-show="fill.loading"><?php esc_html_e( 'Checking…', 'kdna-seo-writer' ); ?></span>
							</button>
						</p>

						<template x-if="fill.preview">
							<div>
								<p><strong x-text="fillSummary()"></strong></p>
								<ul class="kdna-sw-fill-types" x-show="fill.preview.by_type.length">
									<template x-for="type in fill.preview.by_type" :key="type.label">
										<li><span x-text="type.label"></span>: <span x-text="type.count"></span></li>
									</template>
								</ul>
								<p x-show="fill.preview.items + fill.preview.images > 0" x-text="fillEstimate()"></p>
								<p class="kdna-sw-warning" x-show="fill.preview.over_limit"><?php esc_html_e( 'This is likely to go past this month\'s spend limit. Jobs pause when the limit is reached, and carry on next month or when the limit is raised.', 'kdna-seo-writer' ); ?></p>
								<p class="kdna-sw-warning" x-show="fill.preview.health && fill.preview.health.stalled"><?php esc_html_e( 'Background jobs are due but none has run for over 20 minutes. WordPress runs them when the site has visitors or someone is in wp-admin. If this stays, open Tools, Scheduled Actions, Pending, search for kdna_sw and click Run on one. If that works but they still do not run by themselves, ask your host whether WP-Cron or loopback requests are blocked.', 'kdna-seo-writer' ); ?></p>
								<p class="kdna-sw-warning" x-show="fill.preview.health && fill.preview.health.failed > 0" x-text="fillFailed()"></p>
								<p><label><input type="checkbox" x-model="fill.retry" @change="loadFillPreview()"> <?php esc_html_e( 'Also try again items tried in the last week', 'kdna-seo-writer' ); ?></label><br><span class="description"><?php esc_html_e( 'Usually anything tried is left for a week, so pages that keep failing do not cost money every night. Tick this after adding text to pages, or after updating the plugin. Pages that are still too short are skipped again at no cost.', 'kdna-seo-writer' ); ?></span></p>
								<template x-if="fill.preview.left_out && fill.preview.left_out.count > 0">
									<div class="kdna-sw-fill-left">
										<p><button type="button" class="button-link" @click="fill.showLeft = !fill.showLeft" :aria-expanded="fill.showLeft ? 'true' : 'false'" x-text="fillLeftTitle()"></button></p>
										<table class="widefat striped" x-show="fill.showLeft">
											<thead><tr><th><?php esc_html_e( 'Item', 'kdna-seo-writer' ); ?></th><th><?php esc_html_e( 'Type', 'kdna-seo-writer' ); ?></th><th><?php esc_html_e( 'Why it is left out', 'kdna-seo-writer' ); ?></th></tr></thead>
											<tbody>
												<template x-for="row in fill.preview.left_out.rows" :key="row.link + row.title">
													<tr>
														<td><a :href="row.link" x-text="row.title"></a></td>
														<td x-text="row.type"></td>
														<td><span x-text="row.reason"></span> <span class="description" x-show="'short' === row.code"><?php esc_html_e( 'Add more text, use Write anyway in its SEO Writer box, or lower Minimum words in the Content tab.', 'kdna-seo-writer' ); ?></span></td>
													</tr>
												</template>
											</tbody>
										</table>
									</div>
								</template>
								<p class="description" x-show="fill.preview.capped"><?php esc_html_e( 'Each pass covers up to 2,000 items and 2,000 images. The run carries on with the rest in its later passes.', 'kdna-seo-writer' ); ?></p>
								<p>
									<button type="button" class="button button-primary" @click="startFill()" :disabled="busy || !!fill.problem || !(fill.preview.items + fill.preview.images)"><?php esc_html_e( 'Fill empty fields now', 'kdna-seo-writer' ); ?></button>
									<button type="button" class="button-link" @click="loadFillPreview()" :disabled="fill.loading"><?php esc_html_e( 'Check again', 'kdna-seo-writer' ); ?></button>
								</p>
							</div>
						</template>
					</div>
				</template>
			</div>

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
