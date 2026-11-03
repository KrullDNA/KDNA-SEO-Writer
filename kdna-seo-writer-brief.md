# KDNA SEO Writer: project brief

KDNA | Brief v1.0 (plugin 1.0.0) | 4 November 2026

## 1. At a glance

| Item | Value |
| --- | --- |
| Plugin name | KDNA SEO Writer |
| Slug and text domain | kdna-seo-writer |
| PHP class prefix | KDNA_SeoWriter_ |
| Short prefix | kdna_sw_ (PHP, options, post and term meta), --kdna-sw- (CSS), kdna-sw/v1 (REST) |
| JavaScript events | kdna:sw-generated, kdna:sw-approved |
| Final version | 1.0.0, packaged once at the end as kdna-seo-writer-1.0.0.zip |
| Requires | WordPress 6.5+, PHP 8.1+, Yoast SEO (free version is enough). WooCommerce 9.0+ optional (product features switch on when it is active). Elementor and JetEngine optional and fully supported |
| AI provider | Claude, through the Anthropic API, using a key belonging to the site owner or KDNA |
| Elementor | Not needed for the front end (see section 4). Adds a small SEO Writer section to the Elementor editor's Page Settings |
| Who can use it | Administrators (manage_options) for settings, the API key and the review queue. Anyone who can edit a post can generate and approve metadata for that post |
| Intended use | Writing SEO titles, meta descriptions, focus keyphrases, social titles and descriptions, and image alt text for every page, post, product and category on a site, saved straight into Yoast. Reusable on any KDNA client site |

## 2. Purpose

Most client sites have dozens or hundreds of pages with no SEO title, no meta description and no focus keyphrase, because writing them by hand is slow and easy to forget. When the fields are empty, Google and social platforms guess, and the guesses are often poor.

KDNA SEO Writer reads each page the way a visitor sees it, asks Claude to write the metadata in the client's brand voice, checks the result against Yoast's own rules, and saves it into Yoast's fields. Yoast stays on the site and keeps doing what it does well: printing the tags, building the sitemap and showing its traffic lights. This plugin is simply the writer that fills in Yoast's boxes.

Blog posts are filled in automatically. Everything else (pages, products, categories, image alt text) goes into a review queue, so the owner approves anything that represents the brand on key pages before it goes live. Nothing a person has written is ever overwritten without approval.

### 2.1 Why not Yoast Premium

Yoast SEO Premium already includes AI Generate, which drafts titles and descriptions one page at a time or in bulk, with each draft approved before saving. It is a good tool. This plugin exists because:

- Yoast's AI only runs when someone clicks. KDNA SEO Writer fills gaps on its own, on publish and in a nightly sweep.
- It needs a Premium licence on every client site. This plugin uses one API key billed by use.
- It does not write image alt text, apply per-client brand rules (for example TGA-safe wording for cosmetics), or keep a site-wide register of keyphrases.

If a client already has Premium, both can sit side by side. This plugin only writes into empty fields unless a replacement is approved.

### 2.2 A lean plugin

- **Nothing on the front end.** Yoast prints every tag. This plugin adds no CSS, no JavaScript and no requests to any public page.
- **No third-party libraries** apart from Alpine.js on its own admin screens. The Anthropic API is called with WordPress's own request functions, not a code library.
- **Reuse what the site already has:** Yoast's fields and filters, Action Scheduler (from WooCommerce, or loaded by the plugin when WooCommerce is absent), WordPress's media library.
- **Admin assets only where they are used:** the settings screens, the edit screen panel, the Elementor Page Settings section and list screens showing the status column.
- **No features beyond this brief.** Extras go to the future enhancements list.

### 2.3 Backend only, zero effect on site speed

KDNA SEO Writer is a backend tool. A visitor's page load must be exactly as fast with the plugin active as without it. Every stage is built and reviewed against these rules:

- **Front-end page loads do nothing.** On a public page the plugin loads no files, runs no database queries and adds no hooks that run per visit. It only loads its code in wp-admin, in the Elementor editor, in its own REST routes and in background jobs. Yoast prints the tags from its own stored fields, exactly as it does today.
- **The AI is never called while anyone waits.** Every request to Claude runs in a background job through Action Scheduler, never during a page view and never during a save. Saving a post or page in the editor is as fast as before.
- **Background work is gentle.** One job at a time by default, small batches, the nightly sweep runs at a quiet hour set in settings (default 2am site time), and jobs pause if the server is under load or the spend limit is reached.
- **Writes are light.** Saving into Yoast is a handful of field updates per item, and the WP Rocket cache is cleared only for the one URL that changed, never the whole site.
- **Checked with numbers.** Stage 5 compares a public page with the plugin on and off (load time and Query Monitor query count). Any difference is a failed stage.

## 3. Where the focus keyphrase needs to appear

Research done on 4 November 2026. This section exists because it decides what "make sure the keyphrase appears everywhere Google requires it" can honestly mean.

> **Plain English.** Google does not require a keyphrase anywhere. Google does not even read meta descriptions as a ranking signal, although it does use the title for ranking and shows the description as the snippet under the link, with matching words in bold. The "keyphrase everywhere" checklist comes from Yoast's SEO analysis, which is a sound set of good habits. So the plugin's target is: every green light in Yoast's keyphrase analysis that metadata can turn green, plus a clear report and suggested wording for the ones that depend on the page content itself.

| Yoast check | What it looks for | How the plugin handles it |
| --- | --- | --- |
| Keyphrase in SEO title | The keyphrase in the title, ideally near the start | Written in automatically, near the start |
| Keyphrase in meta description | All content words of the keyphrase in the description | Written in automatically |
| Meta description length | 120 to 156 characters | Enforced, with an automatic rewrite if out of range |
| SEO title width | The title as it appears in Google, including the separator and site name | Checked against Yoast's title template for that content type, rewritten if too wide |
| Previously used keyphrase | Not reusing a keyphrase already used on another page | Site-wide keyphrase register, Claude is told which keyphrases are taken |
| Keyphrase length and function words | A short keyphrase, not made of words like "the" or "for" | Enforced (1 to 4 content words by default) |
| Keyphrase in image alt attributes | With fewer than 5 images, one alt containing the keyphrase is enough; with 5 or more, roughly 30 to 70% | Alt text suggestions include the keyphrase where it truthfully describes the image (section 6.5) |
| Keyphrase in introduction | The keyphrase in the first paragraph of the page | Page content, so the plugin does not edit it. Claude chooses the keyphrase from wording already on the page so this usually passes; when it does not, the report suggests a reworded opening line to paste in |
| Keyphrase in subheadings | The keyphrase in some H2 and H3 headings | Page content. Reported, with suggested heading wording |
| Keyphrase in slug | The keyphrase in the URL | Suggested only, never changed automatically on a published page, because changing a live URL breaks links and free Yoast has no redirect manager (section 6.4) |
| Keyphrase density, competing links, internal links | Page content and links | Reported only |

## 4. Decisions locked in scoping

| Topic | Decision |
| --- | --- |
| What it writes | SEO title, meta description, focus keyphrase, social (Facebook and X) title and description, image alt text |
| SEO plugin | Yoast only, free version. Writes into Yoast's own fields so there is one set of tags |
| AI provider | Claude only, through the Anthropic API |
| Saving: blog posts | Saved automatically into empty fields |
| Saving: everything else | Pages, products, custom post types, categories and tags, and image alt text go into the review queue |
| Existing text | Never overwritten automatically. A replacement for anything already filled in always goes to review |
| Edited by a person | Any field a person has written or edited is locked and never touched again by automation |
| Per content type | Each post type and taxonomy can be set to Automatic, Review or Off in settings, defaulting to the rules above |
| Keyphrase placement | Written into every metadata field Yoast checks, checked against Yoast's rules, page content issues reported with suggested wording rather than edited |
| Slugs | Suggested only, applied by hand |
| Elementor widget | Not needed. The plugin has no front-end output: Yoast prints the tags. Its screens live in wp-admin, the edit screen panel and Elementor's Page Settings |

## 5. Research and what we take from it

| Tool | What it does | What we take, and the gap we fill |
| --- | --- | --- |
| Yoast SEO Premium, AI Generate and Bulk Editor | Drafts titles, descriptions and social text per page or in bulk, approved before saving. Products and categories need Yoast WooCommerce SEO or AI+ | Review before save for important content. Gap: manual only, licence per site, no alt text or brand rules |
| Mass Meta Creator AI (WordPress.org) | Bulk generates title, description and focus keyword with Gemini or OpenAI, saves to Yoast or Rank Math | Generating the keyphrase alongside the metadata. Gap: CSV driven, works from the page URL rather than reading Elementor content |
| OpptiAI Titles (WordPress.org) | Finds weak titles and descriptions (duplicates, wrong lengths), suggests fixes, approved singly or in batches | Finding problems as well as gaps, a review step. Gap: paid credits through a third party service |
| Unleash AI (WordPress.org) | Bulk titles, descriptions and alt text through its own service | Alt text belongs in the same tool. Gap: data goes through a third party, not the owner's own key |
| Yoast content analysis (yoastseo package) | The list of keyphrase checks and their thresholds | The rules in section 3 |

## 6. How it works

### 6.1 Reading the page

Claude can only write good metadata if it sees what a visitor sees. The plugin builds a clean text version of each item:

- **Elementor pages:** the page's rendered Elementor content, through Elementor's own content functions, with tags removed. Elementor stores text in its own data rather than the normal content box, so reading the content box alone would give Claude nothing. The exact function is confirmed against the installed Elementor version during the build.
- **Block editor and classic pages:** the normal content.
- **JetEngine fields:** chosen per post type in settings, including repeaters (using the same dot notation approach as KDNA Reading Time).
- **WooCommerce products:** title, short description, long description, categories and attributes. Never price, stock or sale dates, because those go out of date.
- **Categories and tags:** name, description, and the titles of the items inside.
- The H1, the first paragraph and the H2 and H3 headings are passed separately, so Claude can choose a keyphrase that already appears in them.
- Items with too little text to describe (default under 50 words) are skipped and listed as "Not enough content".
- Text is trimmed to a sensible length before sending, so long pages do not cost more than they need to.

### 6.2 Writing the metadata

1. The plugin sends Claude the page text, the brand rules (section 6.6), Yoast's title template for that content type, the list of keyphrases already used on the site, and the length rules.
2. Claude returns a structured answer: focus keyphrase, SEO title, meta description, social title, social description, and suggestions for slug, opening line and headings.
3. The plugin checks every rule in section 3 in PHP, not by trusting Claude. If anything fails, it asks Claude once more with the specific problem ("description is 171 characters, the keyphrase word 'retinol' is missing"). If it still fails, the item goes to review with the problem shown, whatever its content type.
4. Social title and description are written to suit sharing (slightly warmer, no need for the keyphrase) but stay within the brand rules.

### 6.3 Saving into Yoast

- Posts, pages, products and custom post types: Yoast's post meta fields for SEO title, meta description, focus keyphrase, Facebook title and description, X title and description. The exact keys are confirmed against the installed Yoast version during the build and kept in one class.
- Categories and tags: Yoast stores term SEO data differently from posts (in its taxonomy meta option). Confirmed during the build and handled in the same class.
- Yoast keeps a fast copy of every page's SEO data in its own "indexables" table. After saving, the plugin makes sure Yoast's copy is refreshed so the new tags appear straight away, using Yoast's own supported method, confirmed during the build. A direct database write that leaves Yoast's copy stale is not acceptable.
- SEO titles use Yoast's variables where the site's title template does (for example the separator and site name), so a later change to the site name flows through.
- Every write is logged with the old value, the new value, who or what approved it, and the date, so any change can be undone from the log.
- After saving, the WP Rocket cache for that URL is cleared so visitors and Google see the new tags.

### 6.4 When it runs, and what saves automatically

- **On publish and on update:** if any field is empty, a job is queued through Action Scheduler, so saving a page is never slowed down.
- **Nightly sweep:** finds anything still empty and queues it, a set number per night (default 50) so costs stay predictable.
- **Generate now:** a button in the edit screen panel, in Elementor's Page Settings, as a row action in the list screens, and as a bulk action.
- **What happens to the result:**
  - Blog posts (post type post): empty fields are filled automatically.
  - Everything else: the suggestion goes into the review queue.
  - A field that already has text: a replacement is only ever suggested, never saved automatically, and only when the owner asks for it (Generate now, or "Suggest improvements" in the Overview).
  - A field a person has typed into or edited: locked. The plugin records a fingerprint of each value it writes; if the saved value no longer matches, a person changed it and automation leaves it alone. The lock can be cleared by hand.
- **Slugs** are only ever suggestions. On a page that has never been published, the review queue offers an Apply button. On a published page it shows a warning that the old URL will stop working unless a redirect is added.

### 6.5 Image alt text

- Claude can look at images, so it writes alt text from the image itself plus the page it sits on.
- Covers images with empty alt text in the media library, prioritising images used on pages that are being processed and featured images.
- One image has one alt text, even if it appears on several pages. So the keyphrase is only included where it honestly describes the image, and only for an image used mainly on one page. An image of a serum bottle on the Bio Retinol page can carry "bio retinol serum". A generic texture shot used on ten pages gets a plain description.
- Alt text describes what is in the image for people using screen readers first, and search second. Under 125 characters by default, no "image of" at the start.
- Images are resized before sending so costs stay low. Decorative images can be marked "decorative" to keep their alt empty on purpose.
- All alt text goes to the review queue by default.

### 6.6 Brand rules (per site)

Set in Settings > Brand voice:

- Brand name and a short description of the business and audience.
- Tone (for example warm and expert, plain and direct) with an example sentence.
- Spelling: UK or Australian English (default), or US English.
- Words and phrases to always avoid, and words to prefer.
- Claim rules: a free-text box for regulatory limits. For example, for a cosmetics client: no therapeutic claims such as "treats", "cures" or "heals", which the TGA treats as therapeutic and outside cosmetic claims. A short list of starter rules for common client types (cosmetics, health, finance) is included as a starting point, labelled as a guide not legal advice.
- No em dashes, no exclamation marks in titles, no prices or dates (all on by default).

### 6.7 Review queue

Settings > KDNA SEO Writer > Review:

- One row per item: current value beside suggested value for each field, with Yoast-style length bars and a tick or cross for each keyphrase check.
- Approve, edit then approve, regenerate, or reject, per field or for the whole item. Bulk approve for a filtered selection.
- Filters by content type, field, status (new, needs attention, failed checks).
- Approved suggestions are saved through the same writer as section 6.3. Rejected ones are remembered so the same suggestion is not offered again.
- A count badge on the menu item shows how many suggestions are waiting.

### 6.8 Overview and keyphrase report

- **Overview:** coverage across the site (how many items have each field filled), items with problems (duplicate titles or descriptions, wrong lengths, duplicate keyphrases), monthly AI usage and estimated cost, and a "Suggest improvements" action for weak existing metadata.
- **Keyphrase report:** for each item, the Yoast checks from section 3 that depend on page content (introduction, subheadings, slug, alt text), each shown as pass or fail with a suggested line to paste in. Nothing on the page is changed by the plugin.

### 6.9 Cost control

- The Claude model is a setting with a low-cost model as default and a higher-quality option. Model names are kept in one constant and confirmed against Anthropic's current model list during the build.
- Brand rules are sent in a reusable block so repeated requests cost less (Anthropic's prompt caching), and the nightly sweep can use Anthropic's lower-cost batch processing. Both are confirmed against the current API documentation during the build.
- Token use is logged per request. The Overview shows this month's usage and an estimate in dollars, using rates set in Settings (so the estimate stays correct if prices change).
- A monthly spend limit (default off) pauses automatic jobs when reached, with a notice.

## 7. Technical notes

### 7.1 Storage

- Settings: option kdna_sw_settings.
- API key: option kdna_sw_api_key, encrypted with a key derived from the site salts, never sent to the browser.
- Per item: post meta and term meta kdna_sw_state (fingerprints of written values, locks, last generated date, skipped reason).
- Review queue, rejected suggestions and change log: custom tables {prefix}kdna_sw_queue and {prefix}kdna_sw_log, created by a versioned install class.
- Keyphrase register: built from Yoast's focus keyphrase fields, cached, refreshed when any keyphrase changes.

### 7.2 REST API (kdna-sw/v1)

| Route | Access | Purpose |
| --- | --- | --- |
| /generate/{id} | edit_post for that item, REST nonce | Generate now for one item |
| /queue | manage_options, REST nonce | Read the review queue, approve, edit, reject, bulk approve |
| /overview | manage_options, REST nonce | Coverage, problems, usage |
| /report/{id} | edit_post for that item, REST nonce | Keyphrase report for one item |
| /settings | manage_options, REST nonce | Read and save settings |
| /test | manage_options, REST nonce | Test the Anthropic connection |
| /log | manage_options, REST nonce | Change log, with undo |

### 7.3 File structure

```
kdna-seo-writer/
├── kdna-seo-writer.php              (bootstrap, constants, HPOS declaration)
├── uninstall.php                    (keeps Yoast data, removes plugin data if chosen)
├── includes/
│   ├── class-kdna-sw-plugin.php     (loader, hooks)
│   ├── class-kdna-sw-install.php    (queue and log tables, migrations)
│   ├── class-kdna-sw-settings.php
│   ├── class-kdna-sw-crypto.php     (API key encryption)
│   ├── class-kdna-sw-claude.php     (Anthropic API client, model constant, usage logging)
│   ├── class-kdna-sw-reader.php     (page text from Elementor, blocks, JetEngine, WooCommerce, terms)
│   ├── class-kdna-sw-writer.php     (prompt building, structured answers, retries)
│   ├── class-kdna-sw-rules.php      (Yoast keyphrase, length and brand checks in PHP)
│   ├── class-kdna-sw-yoast.php      (Yoast field keys, term storage, indexable refresh)
│   ├── class-kdna-sw-keyphrases.php (site-wide keyphrase register)
│   ├── class-kdna-sw-scheduler.php  (on publish, nightly sweep, spend limit)
│   ├── class-kdna-sw-queue.php      (review queue, approvals, rejections)
│   ├── class-kdna-sw-alt-text.php   (image alt text)
│   ├── class-kdna-sw-report.php     (keyphrase report)
│   ├── class-kdna-sw-cache.php      (WP Rocket purge after writes)
│   └── rest/
├── admin/
│   ├── class-kdna-sw-admin.php      (settings screens, list columns, row and bulk actions)
│   ├── class-kdna-sw-editor.php     (edit screen panel, Elementor Page Settings section)
│   ├── js/ (Alpine app, editor panel)
│   └── css/
├── languages/
└── readme.txt
```

### 7.4 Security and privacy

- Capability checks and nonces on every route, action and form, all input sanitised, all output escaped, prepared queries.
- API key encrypted at rest, never output to the page, REST responses or debug output.
- Only page content is sent to Anthropic: never customer data, orders, form entries, user details or unpublished private content beyond the item being written for. readme.txt explains what is sent, so clients can mention it in their privacy policy if they wish.
- Debug mode with ?kdna_sw_debug=1 for administrators only, showing the text that would be sent and the rule checks, never the key.

## 8. Settings (Settings > KDNA SEO Writer)

| Tab | Settings |
| --- | --- |
| Overview | Coverage, problems, usage, Suggest improvements |
| Review | The review queue |
| Keyphrases | Keyphrase register and keyphrase report |
| Connection | Anthropic API key, Test connection, model, cost rates, monthly spend limit |
| Brand voice | Section 6.6 |
| Content | Per post type and taxonomy: Automatic, Review or Off; which fields to write; content sources (JetEngine fields); minimum words |
| Automation | Generate on publish (on), nightly sweep (on), sweep start time (default 2am) and items per night, alt text (on), WP Rocket purge after writes (on) |
| Advanced | Length limits, keyphrase word limits, title template override, clear locks, delete plugin data on uninstall (off) |

The screens follow the KDNA admin style: Alpine.js, UK English, plain-English help under every option.

## 9. Build stages

Five stages, each individually reviewable. Paste one prompt per Claude Code session, in order. Use Sonnet for build sessions and Opus to review each finished stage. Only Stage 5 produces the installable zip. If a stage needs testing on kdnastaging.com before the end, ask first and label any interim zip as a test build.

> **Packaging rule (applies to every stage).** Do not build a zip at the end of Stages 1 to 4. A zip is built once, at the end of Stage 5, as kdna-seo-writer-1.0.0.zip. The version in the file name must match the Version header and the version constant. Any later change gets a new version number and a new zip, and earlier zips are kept for rollback.

Commit this brief to the repository as docs/kdna-seo-writer-brief.docx and the Markdown copy as docs/kdna-seo-writer-brief.md before Stage 1.

### Stage 1: Foundation, connection and page reader

Delivers: Bootstrap, settings, tables, encrypted API key, Anthropic client with Test connection, and the page reader.

**Claude Code prompt**

```text
Read docs/kdna-seo-writer-brief.md in full before starting, then read the relevant sections again for this stage. Follow every KDNA convention in the brief (kdna- prefixes, UK English, no em dashes in any UI string, comment every function in plain English, keep the plugin lean as set out in section 2.2). Packaging rule: do not build a zip until the final stage (Stage 5), and always include the version number in the zip file name, for example kdna-seo-writer-1.0.0.zip.

Build Stage 1 of KDNA SEO Writer: the foundation.

1. Plugin bootstrap with constants, PHP and WordPress version checks, HPOS compatibility declaration, a friendly admin notice if Yoast SEO is not active, and WooCommerce, Elementor and JetEngine detection (all optional).
2. Versioned install class creating the kdna_sw_queue and kdna_sw_log tables in section 7.1, and the settings class storing kdna_sw_settings with defaults from section 8.
3. Crypto class encrypting the API key with a key derived from the site salts. Never output it.
4. Anthropic API client using wp_remote_post (no SDK): Messages API, structured answers through tool use, image input support for later stages, retries with back-off on rate limit and overload errors, token usage logged per request. Check Anthropic's current documentation for the API version header, the current model names and prompt caching, and keep the API version and model names in one constant each. Test connection route and button.
5. The page reader in section 6.1: rendered Elementor content (confirm the right Elementor function for the installed version and note it in a code comment), block and classic content, chosen JetEngine fields including repeaters, WooCommerce product text without prices or stock, term text. Return clean text plus the H1, first paragraph and H2 and H3 headings separately. Skip items under the minimum word count with a reason.
6. Settings screen shell in Alpine.js at Settings > KDNA SEO Writer with the Connection, Brand voice and Content tabs working.
7. Debug mode with ?kdna_sw_debug=1 for administrators showing the text the reader produces for the current item.
8. Backend only, as set out in section 2.3: load the plugin's classes only in wp-admin, the Elementor editor, its own REST routes, WP-CLI and Action Scheduler jobs. On a public page request the plugin must register nothing that runs per visit.

Do not package yet.
```

**Acceptance criteria**

- Test connection succeeds with a real key and gives a plain-English error with a wrong key
- The key never appears in page source, REST responses or debug output
- The reader returns the real visible text for an Elementor page, a block editor post, a WooCommerce product, a JetEngine CPT item and a product category
- No plugin assets load on the front end or unrelated admin screens
- A public page shows the same Query Monitor query count with the plugin active and inactive

### Stage 2: Writing engine and Yoast writer

Delivers: Prompt building, keyphrase choice, rule checks with retry, the keyphrase register, and saving into Yoast for posts and terms.

**Claude Code prompt**

```text
Read docs/kdna-seo-writer-brief.md in full before starting, then read the relevant sections again for this stage. Follow every KDNA convention in the brief (kdna- prefixes, UK English, no em dashes in any UI string, comment every function in plain English, keep the plugin lean as set out in section 2.2). Packaging rule: do not build a zip until the final stage (Stage 5), and always include the version number in the zip file name, for example kdna-seo-writer-1.0.0.zip.

Build Stage 2: writing and saving (sections 3, 6.2, 6.3 and 6.6).

1. Writer class building the request: page text, brand rules as a cacheable block, Yoast's title template for that content type, keyphrases already used on the site, and the length and keyphrase rules. Claude returns focus keyphrase, SEO title, meta description, social title, social description, and slug, opening line and heading suggestions as structured data. Ask Claude to choose a keyphrase from wording already in the H1, first paragraph or headings where possible.
2. Rules class checking every metadata rule in section 3 in PHP: keyphrase near the start of the title, all content words of the keyphrase in the description, description 120 to 156 characters, title width using the rendered Yoast title, keyphrase length and function words, not previously used, plus brand rules (banned words, no em dashes, no prices or dates). One retry naming the specific failures; if it still fails, mark the result as needing review.
3. Keyphrase register built from Yoast's focus keyphrase fields across posts and terms, cached and refreshed on change.
4. Yoast class: confirm against the installed Yoast version the exact post meta keys for SEO title, meta description, focus keyphrase, Facebook title and description, and X title and description, and how term SEO data is stored. Record them in a code comment and keep them in this one class. After writing, refresh Yoast's indexable for that item using Yoast's supported method, and confirm on the front end that the new tags appear straight away.
5. Every write logged in kdna_sw_log with old value, new value, source and date, with an undo function. Store a fingerprint of each written value in kdna_sw_state.
6. Purge the WP Rocket cache for the URL after a write.
7. Write unit-style test functions for the rule checks.

Do not package yet.
```

**Acceptance criteria**

- Generating for a test page writes all five fields into Yoast, Yoast's analysis shows green for keyphrase in title, keyphrase in meta description, description length, title width and previously used keyphrase
- Exactly one title and one description tag in the page source, the new ones, with no stale Yoast copy
- Category and tag metadata saves and shows correctly
- Undo from the log restores the previous values
- A brand rule banning a word is never broken in saved output

### Stage 3: Automation and review queue

Delivers: On publish and nightly jobs, save rules per content type, locks, the review queue, Generate now buttons, list columns and bulk actions, Overview, and spend limit.

**Claude Code prompt**

```text
Read docs/kdna-seo-writer-brief.md in full before starting, then read the relevant sections again for this stage. Follow every KDNA convention in the brief (kdna- prefixes, UK English, no em dashes in any UI string, comment every function in plain English, keep the plugin lean as set out in section 2.2). Packaging rule: do not build a zip until the final stage (Stage 5), and always include the version number in the zip file name, for example kdna-seo-writer-1.0.0.zip.

Build Stage 3: when it runs and who approves (sections 6.4, 6.7, 6.8 Overview and 6.9).

1. Scheduler: queue a job through Action Scheduler on publish and update when any field is empty (never during the save itself, and never looping on the plugin's own writes), and a nightly sweep with the items-per-night limit. Load Action Scheduler from the plugin when WooCommerce is not active. Use Anthropic's batch processing for the sweep if the current documentation confirms it suits this use.
2. Save rules per post type and taxonomy from the Content tab: Automatic, Review or Off. Defaults: post is Automatic, everything else is Review. Automatic only ever fills empty fields. Replacements for existing text always go to review.
3. Locks: compare each saved Yoast value with the stored fingerprint; if a person has changed it, lock that field. Clear lock action.
4. Review queue screen from section 6.7: current beside suggested, length bars, rule ticks, approve, edit then approve, regenerate, reject, bulk approve, filters, remembered rejections, count badge on the menu.
5. Generate now: edit screen panel (classic meta box and block editor side panel), a SEO Writer section in Elementor's Page Settings with a Generate button and the current status, a list row action, and a bulk action. Status column in list screens (filled, waiting for review, locked, missing).
6. Overview tab: coverage, problems (duplicate titles, descriptions and keyphrases, wrong lengths), usage and cost estimate, Suggest improvements action that queues replacements for review.
7. Monthly spend limit that pauses automatic jobs with a notice.

Do not package yet.
```

**Acceptance criteria**

- Publishing a blog post with empty Yoast fields fills them automatically within a few minutes, and the post saves as fast as before
- Publishing a page, product or category puts a suggestion in the review queue and saves nothing until approved
- Editing a generated title by hand locks it, and the nightly sweep leaves it alone
- An existing description is never replaced without approval
- Bulk approve of 100 items completes without timing out
- Generate now works from the Elementor editor

### Stage 4: Image alt text and keyphrase report

Delivers: Alt text from the images themselves, keyphrase placement rules for alt text, and the content keyphrase report with suggested wording.

**Claude Code prompt**

```text
Read docs/kdna-seo-writer-brief.md in full before starting, then read the relevant sections again for this stage. Follow every KDNA convention in the brief (kdna- prefixes, UK English, no em dashes in any UI string, comment every function in plain English, keep the plugin lean as set out in section 2.2). Packaging rule: do not build a zip until the final stage (Stage 5), and always include the version number in the zip file name, for example kdna-seo-writer-1.0.0.zip.

Build Stage 4: image alt text (section 6.5) and the keyphrase report (sections 3 and 6.8).

1. Find images with empty alt text, prioritising featured images and images used in the content of items being processed, including images inside Elementor widgets and backgrounds that use the media library.
2. Send a resized copy of each image with the page context to Claude and get alt text under the length limit, with no "image of" opening. Include the page keyphrase only when the image is used mainly on that page and the keyphrase truthfully describes it. Aim for Yoast's alt text keyphrase rule (one image with fewer than 5 images, roughly 30 to 70% with 5 or more) without forcing it.
3. A Decorative flag on attachments that keeps alt empty on purpose and is skipped by the plugin.
4. All alt text suggestions go to the review queue by default, with the image thumbnail shown beside the suggestion.
5. Keyphrase report per item: keyphrase in introduction, subheadings (H2 and H3), slug, image alt text, each pass or fail, with Claude's suggested opening line, heading wording and slug ready to copy. Slug Apply button only on items never published; on published items show the redirect warning from section 6.4.
6. Show the report in the edit screen panel, the Elementor Page Settings section and the Keyphrases tab.

Do not package yet.
```

**Acceptance criteria**

- Alt text suggestions accurately describe test images, including product shots and lifestyle photos
- An image used on several pages never receives one page's keyphrase
- Approving alt text updates the media library and the page source
- The keyphrase report matches Yoast's own analysis for introduction, subheadings and slug on test pages
- No page content is changed by the plugin

### Stage 5: Hardening, review and packaging

Delivers: Security, privacy, cost and performance pass, readme, final 1.0.0 zip.

**Claude Code prompt**

```text
Read docs/kdna-seo-writer-brief.md in full before starting, then read the relevant sections again for this stage. Follow every KDNA convention in the brief (kdna- prefixes, UK English, no em dashes in any UI string, comment every function in plain English, keep the plugin lean as set out in section 2.2). Packaging rule: do not build a zip until the final stage (Stage 5), and always include the version number in the zip file name, for example kdna-seo-writer-1.0.0.zip.

Build Stage 5: final hardening and packaging.

1. Security pass against section 7.4, especially key handling and the generate and queue routes.
2. Privacy pass: confirm only the item's own content is sent to Anthropic, and nothing from orders, users or forms.
3. Cost pass: confirm prompt caching is working, text is trimmed, images are resized, the spend limit pauses jobs, and the usage estimate matches the token log.
4. Lean and performance pass against sections 2.2 and 2.3: no plugin CSS, JavaScript, queries or per-visit hooks on any front-end page, no assets on unrelated admin screens, saving a post is no slower, the nightly sweep runs one job at a time at the quiet hour and does not slow the site, no unused code in the zip. Compare a public page with the plugin on and off (load time and Query Monitor query count) and record the results.
5. Accessibility pass on the settings screens, review queue and editor panels.
6. Scan every UI string for UK English and remove any em dashes.
7. Write readme.txt with setup steps (getting an Anthropic API key, setting brand rules), what is sent to Anthropic, and how the review queue works.
8. Make sure the Version header, the version constant and the zip filename all read 1.0.0, then package kdna-seo-writer-1.0.0.zip in the repository root containing the plugin folder.
```

**Acceptance criteria**

- Full testing checklist in section 10 passes
- Version reads 1.0.0 in all three places
- Zip installs cleanly through Plugins > Add New > Upload

## 10. Testing checklist

Run on kdnastaging.com, then on the live site. After every update: Elementor > Tools > Regenerate CSS & Data, clear WP Rocket cache, hard refresh.

- Upload kdna-seo-writer-1.0.0.zip, activate, confirm no errors and version reads 1.0.0
- Add the API key, Test connection succeeds
- Set brand rules, including one banned word and one claim rule
- Publish a blog post with empty Yoast fields: all five fields fill automatically, Yoast's analysis is green for every metadata check
- Publish a page, a product and a category: suggestions wait in the review queue, nothing saves until approved
- Approve, edit then approve, and reject one suggestion each
- Edit a generated title by hand, run the nightly sweep from WP-CLI, confirm the title is untouched
- Check page source on each test item: one title tag, one description, correct social tags
- Run the keyphrase report on an Elementor page and compare with Yoast's own analysis
- Approve alt text for a product image and confirm it in the media library and page source
- Confirm no saved text contains the banned word, an em dash, a price or a date
- Use Undo in the log and confirm the previous values return
- Set a low spend limit and confirm automatic jobs pause with a notice
- View source on a public page: no plugin CSS or JavaScript files load
- Load a public page with Query Monitor, plugin on and off: same query count, no measurable change in load time
- Run the nightly sweep while browsing the site: pages stay as fast as normal
- Browser console clear of errors, no PHP warnings in debug.log

## 11. Risks and trade-offs

| Risk | How we handle it |
| --- | --- |
| AI writes something off-brand or non-compliant on a key page | Only blog posts save automatically, everything else is reviewed, brand and claim rules checked in PHP, full undo |
| Hand-written SEO overwritten | Existing text never replaced automatically, edited fields locked |
| Owner expects every Yoast light green | Section 3 sets expectations: metadata checks are handled, content checks are reported with suggested wording |
| Keyphrase stuffing makes titles read badly | One keyphrase, placed once, natural wording rules in the prompt, Google does not reward stuffing |
| Yoast changes its field storage or indexables | Field keys and refresh method kept in one class and confirmed at build, clear errors |
| API costs creep up | Low-cost model by default, caching, trimming, nightly limits, usage shown, spend limit |
| Anthropic model names or API version change | Kept in one constant each, Test connection catches errors |
| Changing a slug breaks links | Slugs suggested only, applied automatically only before first publish |
| Plugin slows the site | Backend only (section 2.3): nothing runs on public page loads, all AI work in background jobs, measured on and off in Stage 5 |
| Thin pages produce weak metadata | Minimum word count, skipped items listed |

Future enhancements, out of scope for 1.0.0: Rank Math support, OpenAI as an alternative provider, rewriting weak titles using Search Console click data, schema suggestions, multilingual sites, a link with KDNA Search Visibility so metadata is written before a launch date, and optional AI rewriting of introductions and headings in Elementor content.

### Research sources

- Yoast, AI Generate feature page: yoast.com/features/ai-generate
- Yoast SEO 28.4 release post, Bulk Editor and AI drafts, September 2026: yoast.com/yoast-seo-sept-1-2026
- Yoast SEO assessments overview: yoast.com/wordpress/plugins/seo/assessments-overview
- yoastseo package, assessors overview and scoring criteria: cdn.jsdelivr.net/npm/yoastseo
- Yoast SEO 9.4 changelog, image alt and subheading thresholds: developer.yoast.com/changelog/yoast-seo/9.4
- Mass Meta Creator AI on WordPress.org: wordpress.org/plugins/mass-meta-creator-ai
- OpptiAI Titles on WordPress.org: wordpress.org/plugins/opptiai-titles
- Unleash AI on WordPress.org: wordpress.org/plugins/unleashai
- Search Engine Journal, Google title links guidance: searchenginejournal.com/google-title-links/422926
