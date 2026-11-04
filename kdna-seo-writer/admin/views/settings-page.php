<?php
/**
 * Settings page markup. Driven by the Alpine.js app in admin/js/settings.js.
 *
 * Every visible string is printed by PHP so it can be translated. Values from
 * settings are shown with x-text and x-model, which never inject HTML.
 *
 * @package KDNA_SeoWriter
 */

defined( 'ABSPATH' ) || exit;

$kdna_sw_tabs = array(
	'overview'   => __( 'Overview', 'kdna-seo-writer' ),
	'review'     => __( 'Review', 'kdna-seo-writer' ),
	'keyphrases' => __( 'Keyphrases', 'kdna-seo-writer' ),
	'connection' => __( 'Connection', 'kdna-seo-writer' ),
	'brand'      => __( 'Brand voice', 'kdna-seo-writer' ),
	'content'    => __( 'Content', 'kdna-seo-writer' ),
	'automation' => __( 'Automation', 'kdna-seo-writer' ),
	'advanced'   => __( 'Advanced', 'kdna-seo-writer' ),
);
?>
<div class="wrap kdna-sw" x-data="kdnaSwSettings" x-cloak>
	<h1><?php esc_html_e( 'KDNA SEO Writer', 'kdna-seo-writer' ); ?></h1>
	<p class="kdna-sw-intro"><?php esc_html_e( 'Writes SEO titles, meta descriptions, focus keyphrases and social text with Claude, checks them against Yoast\'s rules and saves them into Yoast.', 'kdna-seo-writer' ); ?></p>

	<nav class="nav-tab-wrapper kdna-sw-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Settings sections', 'kdna-seo-writer' ); ?>" @keydown="tabKeys($event)">
		<?php foreach ( $kdna_sw_tabs as $kdna_sw_id => $kdna_sw_label ) : ?>
			<button type="button" role="tab" class="nav-tab"
				id="kdna-sw-tab-<?php echo esc_attr( $kdna_sw_id ); ?>"
				aria-controls="kdna-sw-panel-<?php echo esc_attr( $kdna_sw_id ); ?>"
				:class="{ 'nav-tab-active': tab === '<?php echo esc_js( $kdna_sw_id ); ?>' }"
				:aria-selected="tab === '<?php echo esc_js( $kdna_sw_id ); ?>' ? 'true' : 'false'"
				:tabindex="tab === '<?php echo esc_js( $kdna_sw_id ); ?>' ? 0 : -1"
				data-tab="<?php echo esc_attr( $kdna_sw_id ); ?>"
				@click="go('<?php echo esc_js( $kdna_sw_id ); ?>')"><?php echo esc_html( $kdna_sw_label ); ?></button>
		<?php endforeach; ?>
	</nav>

	<div class="kdna-sw-notice notice inline" role="status" aria-live="polite" x-show="notice.text" :class="notice.ok ? 'notice-success' : 'notice-error'">
		<p x-text="notice.text"></p>
	</div>

	<?php
	require __DIR__ . '/tab-overview.php';
	require __DIR__ . '/tab-review.php';
	?>

	<?php require __DIR__ . '/tab-keyphrases.php'; ?>

	<!-- Connection -->
	<section class="kdna-sw-panel" role="tabpanel" id="kdna-sw-panel-connection" aria-labelledby="kdna-sw-tab-connection" x-show="tab === 'connection'">
		<div class="kdna-sw-card">
			<h2><?php esc_html_e( 'Anthropic API key', 'kdna-seo-writer' ); ?></h2>

			<div class="kdna-sw-field">
				<label for="kdna-sw-api-key"><?php esc_html_e( 'API key', 'kdna-seo-writer' ); ?></label>
				<input id="kdna-sw-api-key" name="kdna_sw_anthropic_key" type="password" class="regular-text" autocomplete="new-password" autocapitalize="off" spellcheck="false" data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other" x-model="apiKey"
					:placeholder="key.saved ? '<?php echo esc_js( __( 'A key is saved. Type a new one to replace it.', 'kdna-seo-writer' ) ); ?>' : 'sk-ant-...'">
				<p class="description"><?php esc_html_e( 'Create a key at console.anthropic.com under API keys, then paste it here. It is stored encrypted and is never shown again, not even to administrators. Usage is billed to the Anthropic account the key belongs to.', 'kdna-seo-writer' ); ?></p>
				<p class="kdna-sw-key-status">
					<span x-show="key.saved && key.readable" class="kdna-sw-pill is-good"><?php esc_html_e( 'Key saved', 'kdna-seo-writer' ); ?></span>
					<span x-show="key.saved && !key.readable" class="kdna-sw-pill is-bad"><?php esc_html_e( 'Saved key cannot be unlocked, please enter it again', 'kdna-seo-writer' ); ?></span>
					<span x-show="!key.saved" class="kdna-sw-pill"><?php esc_html_e( 'No key saved yet', 'kdna-seo-writer' ); ?></span>
					<button type="button" class="button-link kdna-sw-link-danger" x-show="key.saved" @click="removeKey()" :disabled="busy"><?php esc_html_e( 'Remove key', 'kdna-seo-writer' ); ?></button>
				</p>
			</div>

			<div class="kdna-sw-actions">
				<button type="button" class="button" @click="testConnection()" :disabled="busy || testing">
					<span x-show="!testing"><?php esc_html_e( 'Test connection', 'kdna-seo-writer' ); ?></span>
					<span x-show="testing"><?php esc_html_e( 'Testing…', 'kdna-seo-writer' ); ?></span>
				</button>
				<span class="kdna-sw-test-result" role="status" aria-live="polite" x-show="test.message" :class="test.ok ? 'is-good' : 'is-bad'" x-text="test.message"></span>
			</div>
			<p class="description"><?php esc_html_e( 'Test connection saves any changes on this tab first, then checks the key and model with a tiny request that costs a small fraction of a cent.', 'kdna-seo-writer' ); ?></p>
		</div>

		<div class="kdna-sw-card">
			<h2><?php esc_html_e( 'Model', 'kdna-seo-writer' ); ?></h2>
			<fieldset class="kdna-sw-field">
				<legend class="screen-reader-text"><?php esc_html_e( 'Claude model', 'kdna-seo-writer' ); ?></legend>
				<template x-for="model in models" :key="model.id">
					<label class="kdna-sw-radio">
						<input type="radio" name="kdna-sw-model" :value="model.id" x-model="settings.connection.model">
						<span x-text="model.label"></span>
					</label>
				</template>
				<p class="description"><?php esc_html_e( 'Haiku is fast and very cheap, and is plenty for titles and descriptions. Sonnet writes with a little more polish and costs about twenty times more. You can switch at any time.', 'kdna-seo-writer' ); ?></p>
			</fieldset>
		</div>

		<div class="kdna-sw-card">
			<h2><?php esc_html_e( 'Cost', 'kdna-seo-writer' ); ?></h2>
			<table class="widefat striped kdna-sw-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Model', 'kdna-seo-writer' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Input, US$ per million tokens', 'kdna-seo-writer' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Output, US$ per million tokens', 'kdna-seo-writer' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<template x-for="model in models" :key="model.id">
						<tr>
							<th scope="row" x-text="model.label"></th>
							<td><input type="number" min="0" step="0.01" class="small-text" x-model.number="settings.connection.rates[model.id].input" :aria-label="model.label + ' <?php echo esc_js( __( 'input price', 'kdna-seo-writer' ) ); ?>'"></td>
							<td><input type="number" min="0" step="0.01" class="small-text" x-model.number="settings.connection.rates[model.id].output" :aria-label="model.label + ' <?php echo esc_js( __( 'output price', 'kdna-seo-writer' ) ); ?>'"></td>
						</tr>
					</template>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( 'Used only to estimate costs in the Overview. Filled in with Anthropic\'s prices at the time of the build. If Anthropic changes its prices, update them here so the estimate stays right.', 'kdna-seo-writer' ); ?></p>

			<div class="kdna-sw-field">
				<label for="kdna-sw-spend-limit"><?php esc_html_e( 'Monthly spend limit (US$)', 'kdna-seo-writer' ); ?></label>
				<input id="kdna-sw-spend-limit" type="number" min="0" step="1" class="small-text" x-model.number="settings.connection.spend_limit">
				<p class="description"><?php esc_html_e( 'When the estimated spend for the month reaches this amount, automatic jobs pause until next month and a notice appears. Leave at 0 for no limit.', 'kdna-seo-writer' ); ?></p>
			</div>
		</div>

		<p><button type="button" class="button button-primary" @click="save()" :disabled="busy"><?php esc_html_e( 'Save changes', 'kdna-seo-writer' ); ?></button></p>
	</section>

	<!-- Brand voice -->
	<section class="kdna-sw-panel" role="tabpanel" id="kdna-sw-panel-brand" aria-labelledby="kdna-sw-tab-brand" x-show="tab === 'brand'">
		<div class="kdna-sw-card">
			<h2><?php esc_html_e( 'About the brand', 'kdna-seo-writer' ); ?></h2>

			<div class="kdna-sw-field">
				<label for="kdna-sw-brand-name"><?php esc_html_e( 'Brand name', 'kdna-seo-writer' ); ?></label>
				<input id="kdna-sw-brand-name" type="text" class="regular-text" x-model="settings.brand.name">
				<p class="description"><?php esc_html_e( 'The name as it should be written, including any capitals or symbols.', 'kdna-seo-writer' ); ?></p>
			</div>

			<div class="kdna-sw-field">
				<label for="kdna-sw-brand-description"><?php esc_html_e( 'Business and audience', 'kdna-seo-writer' ); ?></label>
				<textarea id="kdna-sw-brand-description" rows="4" class="large-text" x-model="settings.brand.description"></textarea>
				<p class="description"><?php esc_html_e( 'Two or three sentences on what the business does and who it is for. For example: clean corrective skincare for women 35 and over, made in Australia.', 'kdna-seo-writer' ); ?></p>
			</div>

			<div class="kdna-sw-field">
				<label for="kdna-sw-brand-tone"><?php esc_html_e( 'Tone of voice', 'kdna-seo-writer' ); ?></label>
				<input id="kdna-sw-brand-tone" type="text" class="regular-text" x-model="settings.brand.tone">
				<p class="description"><?php esc_html_e( 'A few words, for example: warm and expert, or plain and direct.', 'kdna-seo-writer' ); ?></p>
			</div>

			<div class="kdna-sw-field">
				<label for="kdna-sw-brand-example"><?php esc_html_e( 'Example sentence', 'kdna-seo-writer' ); ?></label>
				<textarea id="kdna-sw-brand-example" rows="2" class="large-text" x-model="settings.brand.tone_example"></textarea>
				<p class="description"><?php esc_html_e( 'One sentence written the way the brand speaks. Claude uses it as a guide for style.', 'kdna-seo-writer' ); ?></p>
			</div>

			<div class="kdna-sw-field">
				<label for="kdna-sw-brand-spelling"><?php esc_html_e( 'Spelling', 'kdna-seo-writer' ); ?></label>
				<select id="kdna-sw-brand-spelling" x-model="settings.brand.spelling">
					<option value="en-GB"><?php esc_html_e( 'UK or Australian English', 'kdna-seo-writer' ); ?></option>
					<option value="en-US"><?php esc_html_e( 'US English', 'kdna-seo-writer' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'For example colour or color, moisturiser or moisturizer.', 'kdna-seo-writer' ); ?></p>
			</div>
		</div>

		<div class="kdna-sw-card">
			<h2><?php esc_html_e( 'Words', 'kdna-seo-writer' ); ?></h2>

			<div class="kdna-sw-field">
				<label for="kdna-sw-brand-avoid"><?php esc_html_e( 'Words and phrases to always avoid', 'kdna-seo-writer' ); ?></label>
				<textarea id="kdna-sw-brand-avoid" rows="3" class="large-text" x-model="settings.brand.avoid"></textarea>
				<p class="description"><?php esc_html_e( 'One per line or separated by commas. These are checked in the plugin itself before anything is saved, not just suggested to Claude.', 'kdna-seo-writer' ); ?></p>
			</div>

			<div class="kdna-sw-field">
				<label for="kdna-sw-brand-prefer"><?php esc_html_e( 'Words to prefer', 'kdna-seo-writer' ); ?></label>
				<textarea id="kdna-sw-brand-prefer" rows="3" class="large-text" x-model="settings.brand.prefer"></textarea>
				<p class="description"><?php esc_html_e( 'Words the brand likes to use, for example "skin barrier" rather than "skin wall".', 'kdna-seo-writer' ); ?></p>
			</div>
		</div>

		<div class="kdna-sw-card">
			<h2><?php esc_html_e( 'Claim rules', 'kdna-seo-writer' ); ?></h2>

			<div class="kdna-sw-field">
				<label for="kdna-sw-brand-claims"><?php esc_html_e( 'Rules about what can and cannot be claimed', 'kdna-seo-writer' ); ?></label>
				<textarea id="kdna-sw-brand-claims" rows="7" class="large-text" x-model="settings.brand.claims"></textarea>
				<p class="description"><?php esc_html_e( 'Any limits from regulators or the client, written in plain English. Claude is told to follow them for every item.', 'kdna-seo-writer' ); ?></p>
			</div>

			<div class="kdna-sw-starters">
				<p><strong><?php esc_html_e( 'Add starter rules', 'kdna-seo-writer' ); ?></strong></p>
				<template x-for="rule in starterRules" :key="rule.id">
					<button type="button" class="button" @click="addStarter(rule)" x-text="rule.label"></button>
				</template>
				<p class="description"><?php esc_html_e( 'A starting point to edit, added to the end of the box above. These are a guide only, not legal advice. Check them with someone who knows the client\'s industry.', 'kdna-seo-writer' ); ?></p>
			</div>
		</div>

		<div class="kdna-sw-card">
			<h2><?php esc_html_e( 'House rules', 'kdna-seo-writer' ); ?></h2>
			<label class="kdna-sw-check"><input type="checkbox" x-model="settings.brand.no_em_dashes"> <?php esc_html_e( 'No em dashes', 'kdna-seo-writer' ); ?></label>
			<label class="kdna-sw-check"><input type="checkbox" x-model="settings.brand.no_exclaim_titles"> <?php esc_html_e( 'No exclamation marks in titles', 'kdna-seo-writer' ); ?></label>
			<label class="kdna-sw-check"><input type="checkbox" x-model="settings.brand.no_prices_dates"> <?php esc_html_e( 'No prices or dates, because they go out of date', 'kdna-seo-writer' ); ?></label>
			<p class="description"><?php esc_html_e( 'These are checked in the plugin itself before anything is saved.', 'kdna-seo-writer' ); ?></p>
		</div>

		<p><button type="button" class="button button-primary" @click="save()" :disabled="busy"><?php esc_html_e( 'Save changes', 'kdna-seo-writer' ); ?></button></p>
	</section>

	<!-- Content -->
	<section class="kdna-sw-panel" role="tabpanel" id="kdna-sw-panel-content" aria-labelledby="kdna-sw-tab-content" x-show="tab === 'content'">
		<div class="kdna-sw-card">
			<h2><?php esc_html_e( 'What happens to each type of content', 'kdna-seo-writer' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Automatic: empty fields are filled straight away. Review: suggestions wait in the Review tab until you approve them. Off: this type is left alone. Text that is already filled in is never replaced without your approval, whatever is chosen here.', 'kdna-seo-writer' ); ?></p>

			<table class="widefat striped kdna-sw-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Post type', 'kdna-seo-writer' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Mode', 'kdna-seo-writer' ); ?></th>
						<th scope="col" x-show="integrations.jetengine"><?php esc_html_e( 'JetEngine fields to read', 'kdna-seo-writer' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<template x-for="type in postTypes" :key="type.name">
						<tr>
							<th scope="row"><span x-text="type.label"></span> <code x-text="type.name"></code></th>
							<td>
								<select x-model="settings.content.post_types[type.name]" :aria-label="type.label + ' <?php echo esc_js( __( 'mode', 'kdna-seo-writer' ) ); ?>'">
									<option value="auto"><?php esc_html_e( 'Automatic', 'kdna-seo-writer' ); ?></option>
									<option value="review"><?php esc_html_e( 'Review', 'kdna-seo-writer' ); ?></option>
									<option value="off"><?php esc_html_e( 'Off', 'kdna-seo-writer' ); ?></option>
								</select>
							</td>
							<td x-show="integrations.jetengine">
								<textarea rows="3" class="large-text kdna-sw-jet-fields" x-model="settings.content.jet_fields[type.name]" placeholder="intro, faq.question:h3, faq.answer" :aria-label="type.label + ' <?php echo esc_js( __( 'JetEngine fields', 'kdna-seo-writer' ) ); ?>'"></textarea>
							</td>
						</tr>
					</template>
				</tbody>
			</table>
			<div class="description kdna-sw-help" x-show="integrations.jetengine">
				<p><?php esc_html_e( 'JetEngine fields: type the field names (Name/ID in JetEngine), separated by commas, in the order they appear on the page. The plugin reads the normal content too, so only list fields that hold visible text.', 'kdna-seo-writer' ); ?></p>
				<p><?php esc_html_e( 'For a repeater, put a dot between the repeater and the sub-field, for example faq.answer. List several sub-fields of the same repeater and it is read row by row, each heading followed by its own text.', 'kdna-seo-writer' ); ?></p>
				<p><?php esc_html_e( 'Add :h2 or :h3 to a field that is shown as a heading, for example article_sections.section_heading:h2, so it counts as a heading.', 'kdna-seo-writer' ); ?></p>
			</div>

			<table class="widefat striped kdna-sw-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Categories, tags and other taxonomies', 'kdna-seo-writer' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Mode', 'kdna-seo-writer' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<template x-for="taxonomy in taxonomies" :key="taxonomy.name">
						<tr>
							<th scope="row"><span x-text="taxonomy.label"></span> <code x-text="taxonomy.name"></code></th>
							<td>
								<select x-model="settings.content.taxonomies[taxonomy.name]" :aria-label="taxonomy.label + ' <?php echo esc_js( __( 'mode', 'kdna-seo-writer' ) ); ?>'">
									<option value="auto"><?php esc_html_e( 'Automatic', 'kdna-seo-writer' ); ?></option>
									<option value="review"><?php esc_html_e( 'Review', 'kdna-seo-writer' ); ?></option>
									<option value="off"><?php esc_html_e( 'Off', 'kdna-seo-writer' ); ?></option>
								</select>
							</td>
						</tr>
					</template>
				</tbody>
			</table>

			<div class="kdna-sw-hidden-types" x-show="hiddenTypes.length">
				<p class="description"><strong><?php esc_html_e( 'Not shown, so always left alone:', 'kdna-seo-writer' ); ?></strong></p>
				<ul>
					<template x-for="type in hiddenTypes" :key="type.name">
						<li class="description"><span x-text="type.label"></span> <code x-text="type.name"></code>: <span x-text="type.reason"></span></li>
					</template>
				</ul>
				<p class="description"><?php esc_html_e( 'To include one that Yoast keeps out of search results, switch on "Show in search results" for it in Yoast SEO > Settings > Content types (or Categories and tags) first.', 'kdna-seo-writer' ); ?></p>
			</div>
		</div>

		<div class="kdna-sw-card">
			<h2><?php esc_html_e( 'Fields to write', 'kdna-seo-writer' ); ?></h2>
			<label class="kdna-sw-check"><input type="checkbox" x-model="settings.content.fields.seo_title"> <?php esc_html_e( 'SEO title', 'kdna-seo-writer' ); ?></label>
			<label class="kdna-sw-check"><input type="checkbox" x-model="settings.content.fields.meta_description"> <?php esc_html_e( 'Meta description', 'kdna-seo-writer' ); ?></label>
			<label class="kdna-sw-check"><input type="checkbox" x-model="settings.content.fields.focus_keyphrase"> <?php esc_html_e( 'Focus keyphrase', 'kdna-seo-writer' ); ?></label>
			<label class="kdna-sw-check"><input type="checkbox" x-model="settings.content.fields.social"> <?php esc_html_e( 'Social title and description (Facebook and X)', 'kdna-seo-writer' ); ?></label>
			<p class="description"><?php esc_html_e( 'Untick any field you would rather always write by hand.', 'kdna-seo-writer' ); ?></p>
		</div>

		<div class="kdna-sw-card">
			<h2><?php esc_html_e( 'Minimum content', 'kdna-seo-writer' ); ?></h2>
			<div class="kdna-sw-field">
				<label for="kdna-sw-min-words"><?php esc_html_e( 'Minimum words', 'kdna-seo-writer' ); ?></label>
				<input id="kdna-sw-min-words" type="number" min="0" step="1" class="small-text" x-model.number="settings.content.min_words">
				<p class="description"><?php esc_html_e( 'Items with fewer words than this are skipped and listed as "Not enough content", because there is too little to describe well.', 'kdna-seo-writer' ); ?></p>
			</div>
		</div>

		<p><button type="button" class="button button-primary" @click="save()" :disabled="busy"><?php esc_html_e( 'Save changes', 'kdna-seo-writer' ); ?></button></p>
	</section>

	<?php
	require __DIR__ . '/tab-automation.php';
	require __DIR__ . '/tab-advanced.php';
	?>

	<p class="kdna-sw-footer">
		<?php
		/* translators: %s: plugin version number. */
		printf( esc_html__( 'Version %s. To see the text the reader builds for an item, open it for editing and add &kdna_sw_debug=1 to the address.', 'kdna-seo-writer' ), esc_html( KDNA_SW_VERSION ) );
		?>
	</p>
</div>
