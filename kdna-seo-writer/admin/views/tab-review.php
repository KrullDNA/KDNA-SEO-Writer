<?php
/**
 * Review tab: suggestions waiting for approval. Driven by admin/js/settings.js.
 *
 * @package KDNA_SeoWriter
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="kdna-sw-panel" role="tabpanel" id="kdna-sw-panel-review" aria-labelledby="kdna-sw-tab-review" x-show="tab === 'review'">
	<div class="kdna-sw-card">
		<h2><?php esc_html_e( 'Waiting for review', 'kdna-seo-writer' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Nothing here is live yet. Approve a suggestion to save it into Yoast, edit it first if you like, or reject it so it is not offered again. Every approval is logged and can be undone.', 'kdna-seo-writer' ); ?></p>

		<div class="kdna-sw-filters">
			<label>
				<span><?php esc_html_e( 'Content type', 'kdna-seo-writer' ); ?></span>
				<select x-model="review.filters.subtype" @change="loadReview(1)">
					<option value=""><?php esc_html_e( 'All', 'kdna-seo-writer' ); ?></option>
					<template x-for="type in contentTypes" :key="type.name">
						<option :value="type.name" x-text="type.label"></option>
					</template>
				</select>
			</label>
			<label>
				<span><?php esc_html_e( 'Field', 'kdna-seo-writer' ); ?></span>
				<select x-model="review.filters.field" @change="loadReview(1)">
					<option value=""><?php esc_html_e( 'All', 'kdna-seo-writer' ); ?></option>
					<option value="seo_title"><?php esc_html_e( 'SEO title', 'kdna-seo-writer' ); ?></option>
					<option value="meta_description"><?php esc_html_e( 'Meta description', 'kdna-seo-writer' ); ?></option>
					<option value="focus_keyphrase"><?php esc_html_e( 'Focus keyphrase', 'kdna-seo-writer' ); ?></option>
					<option value="social"><?php esc_html_e( 'Social title and description', 'kdna-seo-writer' ); ?></option>
				</select>
			</label>
			<label>
				<span><?php esc_html_e( 'Status', 'kdna-seo-writer' ); ?></span>
				<select x-model="review.filters.flag" @change="loadReview(1)">
					<option value=""><?php esc_html_e( 'All', 'kdna-seo-writer' ); ?></option>
					<option value="new"><?php esc_html_e( 'New (fills an empty field)', 'kdna-seo-writer' ); ?></option>
					<option value="attention"><?php esc_html_e( 'Needs attention (replaces existing text)', 'kdna-seo-writer' ); ?></option>
					<option value="failed"><?php esc_html_e( 'Failed checks', 'kdna-seo-writer' ); ?></option>
				</select>
			</label>
		</div>

		<div class="kdna-sw-bulk" x-show="review.total > 0">
			<label class="kdna-sw-check-inline"><input type="checkbox" :checked="allSelected()" @change="toggleAll($event.target.checked)"> <?php esc_html_e( 'Select all on this page', 'kdna-seo-writer' ); ?></label>
			<button type="button" class="button" @click="approveItems(review.selected)" :disabled="busy || !review.selected.length"><?php esc_html_e( 'Approve selected', 'kdna-seo-writer' ); ?></button>
			<button type="button" class="button" @click="rejectItems(review.selected)" :disabled="busy || !review.selected.length"><?php esc_html_e( 'Reject selected', 'kdna-seo-writer' ); ?></button>
			<button type="button" class="button button-primary" @click="approveAllMatching()" :disabled="busy">
				<span x-text="approveAllLabel()"></span>
			</button>
			<span class="kdna-sw-progress" role="status" aria-live="polite" x-text="review.progress"></span>
		</div>
	</div>

	<p x-show="review.loading" class="kdna-sw-loading"><?php esc_html_e( 'Loading…', 'kdna-seo-writer' ); ?></p>

	<div class="kdna-sw-card" x-show="!review.loading && review.total === 0">
		<p><?php esc_html_e( 'Nothing is waiting for review.', 'kdna-seo-writer' ); ?></p>
	</div>

	<template x-for="item in review.items" :key="item.key">
		<article class="kdna-sw-card kdna-sw-item">
			<header class="kdna-sw-item-head">
				<label class="kdna-sw-check-inline">
					<input type="checkbox" :value="item.key" x-model="review.selected">
					<span class="screen-reader-text"><?php esc_html_e( 'Select', 'kdna-seo-writer' ); ?></span>
				</label>
				<h3>
					<a :href="item.edit_url" x-text="item.title"></a>
					<span class="kdna-sw-pill" x-text="item.type_label"></span>
					<span class="kdna-sw-pill is-wait" x-show="item.regenerating"><?php esc_html_e( 'Regenerating…', 'kdna-seo-writer' ); ?></span>
				</h3>
				<a class="kdna-sw-view" :href="item.view_url" target="_blank" rel="noopener"><?php esc_html_e( 'View page', 'kdna-seo-writer' ); ?><span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'kdna-seo-writer' ); ?></span></a>
			</header>

			<template x-for="row in item.fields" :key="row.id">
				<div class="kdna-sw-row">
					<div class="kdna-sw-row-label">
						<strong x-text="row.label"></strong>
						<span class="kdna-sw-pill" :class="flagClass(row.flag)" x-text="flagLabel(row.flag)"></span>
						<span class="kdna-sw-pill" x-show="row.locked"><?php esc_html_e( 'Locked', 'kdna-seo-writer' ); ?></span>
					</div>
					<div class="kdna-sw-row-body">
						<p class="kdna-sw-current"><span class="kdna-sw-small"><?php esc_html_e( 'Now:', 'kdna-seo-writer' ); ?></span> <span x-text="row.current || '<?php echo esc_js( __( '(empty)', 'kdna-seo-writer' ) ); ?>'"></span></p>
						<label class="kdna-sw-small" :for="'kdna-sw-edit-' + row.id"><?php esc_html_e( 'Suggested (you can edit it before approving):', 'kdna-seo-writer' ); ?></label>
						<div class="kdna-sw-edit-wrap">
							<textarea :id="'kdna-sw-edit-' + row.id" rows="2" class="large-text" x-model="row.edit"></textarea>
							<span class="kdna-sw-suffix" x-show="row.field === 'seo_title' && item.title_suffix" x-text="item.title_suffix"></span>
						</div>
						<div class="kdna-sw-meter" x-show="meter(row, item)">
							<div class="kdna-sw-meter-bar" :class="meter(row, item) && meter(row, item).ok ? 'is-good' : 'is-bad'" :style="'width:' + (meter(row, item) ? meter(row, item).percent : 0) + '%'"></div>
						</div>
						<p class="kdna-sw-small" x-show="meter(row, item)" x-text="meter(row, item) ? meter(row, item).text : ''"></p>
						<ul class="kdna-sw-checks">
							<template x-for="(check, index) in row.checks" :key="index">
								<li :class="check.ok ? 'is-good' : 'is-bad'">
									<span aria-hidden="true" x-text="check.ok ? '✓' : '✗'"></span>
									<span class="screen-reader-text" x-text="check.ok ? '<?php echo esc_js( __( 'Pass:', 'kdna-seo-writer' ) ); ?>' : '<?php echo esc_js( __( 'Fail:', 'kdna-seo-writer' ) ); ?>'"></span>
									<span x-text="check.message"></span>
								</li>
							</template>
						</ul>
						<p class="kdna-sw-small" x-show="row.edit !== row.suggested"><?php esc_html_e( 'Edited. The checks above are for Claude\'s version, and the bar shows your version. Edited text is locked against automation once approved.', 'kdna-seo-writer' ); ?></p>
					</div>
					<div class="kdna-sw-row-actions">
						<button type="button" class="button button-primary" @click="approveRow(item, row)" :disabled="busy"><?php esc_html_e( 'Approve', 'kdna-seo-writer' ); ?></button>
						<button type="button" class="button" @click="rejectRow(item, row)" :disabled="busy"><?php esc_html_e( 'Reject', 'kdna-seo-writer' ); ?></button>
					</div>
				</div>
			</template>

			<footer class="kdna-sw-item-foot">
				<button type="button" class="button" @click="approveItem(item)" :disabled="busy"><?php esc_html_e( 'Approve all for this item', 'kdna-seo-writer' ); ?></button>
				<button type="button" class="button" @click="rejectItems([item.key])" :disabled="busy"><?php esc_html_e( 'Reject all', 'kdna-seo-writer' ); ?></button>
				<button type="button" class="button" @click="regenerate(item)" :disabled="busy || item.regenerating"><?php esc_html_e( 'Regenerate', 'kdna-seo-writer' ); ?></button>
			</footer>
		</article>
	</template>

	<nav class="kdna-sw-pages" x-show="review.total > review.perPage" aria-label="<?php esc_attr_e( 'Review pages', 'kdna-seo-writer' ); ?>">
		<button type="button" class="button" @click="loadReview(review.page - 1)" :disabled="review.page <= 1"><?php esc_html_e( 'Previous', 'kdna-seo-writer' ); ?></button>
		<span x-text="pageLabel()"></span>
		<button type="button" class="button" @click="loadReview(review.page + 1)" :disabled="review.page >= pages()"><?php esc_html_e( 'Next', 'kdna-seo-writer' ); ?></button>
	</nav>
</section>
