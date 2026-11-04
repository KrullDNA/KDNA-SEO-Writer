=== KDNA SEO Writer ===
Contributors: kdna
Tags: seo, yoast, meta description, alt text, ai
Requires at least: 6.5
Requires PHP: 8.1
Stable tag: 1.0.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Writes SEO titles, meta descriptions, focus keyphrases, social text and image alt text with Claude, checks them against Yoast's rules, and saves them into Yoast.

== Description ==

Most sites have pages with no SEO title, no meta description and no focus keyphrase, because writing them by hand is slow. When those fields are empty, Google and social platforms guess, and the guesses are often poor.

KDNA SEO Writer reads each page the way a visitor sees it, asks Claude (by Anthropic) to write the metadata in the brand's own voice, checks the result against Yoast's rules, and saves it into Yoast's fields. Yoast stays in charge of printing the tags, the sitemap and its traffic lights. This plugin simply fills in Yoast's boxes.

* Blog posts are filled in automatically.
* Pages, products, categories and image alt text wait in a review queue until you approve them.
* Text a person has written is never replaced without approval, and fields a person edits are locked against automation.
* Every change is logged and can be undone.

It is a backend tool. Public pages load no files, run no queries and are exactly as fast with the plugin as without it. Every request to Claude runs in the background.

= What it writes =

* SEO title, built around the site's own Yoast title template, so the separator and site name stay as Yoast variables.
* Meta description, 121 to 156 characters, containing the keyphrase.
* Focus keyphrase, chosen from wording already on the page and never one used elsewhere on the site.
* Facebook and X title and description.
* Image alt text, written from the image itself.
* Suggested slug, opening line and headings, for you to use if you wish. The plugin never edits page content.

= Requirements =

* WordPress 6.5 or newer and PHP 8.1 or newer.
* Yoast SEO (the free version is enough).
* An Anthropic API key, billed by use to the key's owner.
* WooCommerce 9.0 or newer, Elementor and JetEngine are optional and fully supported.

== Installation ==

1. Upload kdna-seo-writer-1.0.3.zip in Plugins > Add New > Upload Plugin, then activate it.
2. Make sure Yoast SEO is active.
3. Get an Anthropic API key:
   * Go to console.anthropic.com and sign in or create an account.
   * Add a payment method and some credit under Billing.
   * Under API keys, create a key and copy it (it starts with sk-ant-). You will only see it once.
4. Go to Settings > KDNA SEO Writer > Connection, paste the key and click Test connection.
5. Choose the model. Claude Haiku 5.5 is the low-cost default and is plenty for metadata. Claude Sonnet 5.5 writes with a little more polish at around twenty times the price.
6. Optionally set a monthly spend limit. Automatic jobs pause when it is reached.
7. Fill in Brand voice (see below).
8. Check the Content tab: for each content type choose Automatic, Review or Off. Blog posts start as Automatic and everything else as Review.

= Setting the brand rules =

Settings > KDNA SEO Writer > Brand voice:

* Brand name, written exactly as it should appear.
* A few sentences on the business and its audience.
* Tone of voice, with one example sentence in the brand's style.
* Spelling: UK or Australian English, or US English.
* Words to avoid. These are checked by the plugin itself before anything is saved, not just suggested to Claude.
* Words to prefer.
* Claim rules, in plain English. Starter rules for cosmetics (TGA), health and finance are included as a guide. They are not legal advice, so check them with someone who knows the client's industry.
* House rules: no em dashes, no exclamation marks in titles, no prices or dates (all on by default).

== How it works ==

= When it runs =

* On publish and update: if a field is empty, a background job runs about two minutes later. Saving is never slowed down.
* Nightly sweep: at a quiet hour (2am by default), up to a set number of items with empty fields (50 by default), one at a time. Images with no alt text use any places left.
* Fill empty fields now: on the Overview tab, for the first run on a site. It works through every item and image that needs doing straight away, with progress and a Stop button.
* Generate now: in the edit screen's SEO Writer panel (classic and block editor), in Elementor's Page Settings, as a "Generate SEO" link in list screens, and as a bulk action.

Background jobs run through Action Scheduler. WooCommerce includes it; without WooCommerce the plugin loads its own copy. You can see the jobs under Tools > Scheduled Actions, group kdna-seo-writer.

= The checks =

Every answer is checked in the plugin itself, using the same limits as Yoast's analysis:

* The SEO title starts with the keyphrase and is 400 to 599 pixels wide in Google.
* The meta description is 121 to 156 characters and contains the keyphrase once or twice.
* The keyphrase has 1 to 4 meaningful words and is not used on any other page.
* The brand rules are followed.

If anything fails, Claude is asked once more with the exact problems. If it still fails, the result goes to the review queue whatever the content type.

= The review queue =

Settings > KDNA SEO Writer > Review shows each suggestion beside the current value, with a length or width bar and a tick or cross for each check.

* Approve saves it into Yoast (or, for alt text, into the media library).
* You can edit a suggestion before approving. Edited text is locked against automation afterwards, because the words are then yours.
* Reject removes it, and the same suggestion is never offered again.
* Regenerate asks Claude for fresh suggestions.
* Filter by content type, field or status, and approve a whole filtered selection in one go.

The menu item shows how many items are waiting. Every approval is logged and can be undone.

= Image alt text =

Claude looks at a resized copy of each image plus the page it is on, and writes alt text for people using screen readers first. A page's keyphrase is only used for an image that appears on that one page, and only where it truthfully describes the image. Mark an image as Decorative in the media library to keep its alt text empty on purpose.

WordPress's image block keeps its own copy of the alt text inside the page. After approving alt text for an image already placed in a block, reselect the image in that block (or paste the text in) to update the page. Featured images, Elementor images and WooCommerce product images update straight away.

= Keyphrase report =

For each item, the report checks the Yoast checks that depend on the page content (keyphrase in the introduction, subheadings, slug and image alt text) and shows suggested wording to copy. A suggested slug can only be applied automatically to an item that has never been published. Changing the address of a published page breaks links to it, and Yoast SEO free has no redirect manager.

== Privacy: what is sent to Anthropic ==

To write metadata, the plugin sends Anthropic:

* The text of the item being written for, as a logged-out visitor would see it: the page content (including Elementor content), the title, headings, the address, chosen JetEngine fields, and for WooCommerce products the short and long description, categories and attributes. Prices, stock and sale dates are never sent.
* For categories and tags: the name, description and titles of the published items in them.
* The brand rules from the Brand voice tab.
* The focus keyphrases already used on the site, so none is repeated.
* For alt text: a resized copy of the image, its file name, title and caption, and the title, main heading and introduction of the page it is on.

It never sends customer details, orders, form entries, user accounts, passwords or the content of any item other than the one being written for. Password-protected items are never sent.

The API key is stored encrypted using the site's own security keys, and is never shown on screen, in REST responses or in debug output.

Anthropic's commercial terms state that it does not train its models on API customers' data by default; check their current terms at anthropic.com/legal before quoting this. If you mention the plugin in the site's privacy policy, you can say that published page content and images are sent to Anthropic to write search engine, social and accessibility text.

== Frequently Asked Questions ==

= Does it slow the site down? =

No. On a public page view the plugin loads nothing at all. All writing happens in background jobs.

= Will it overwrite SEO text I have written? =

No. Automatic jobs only fill empty fields. A replacement for existing text is only ever suggested for review, and only when you ask (Generate now or Suggest improvements). Fields a person edits are locked.

= What does it cost? =

With Claude Haiku 5.5, writing the metadata for one page or the alt text for one image usually costs a fraction of a cent (a second try, when a check fails, doubles that). The token log under the Overview tab shows the real figure for your site. The Overview tab shows this month's estimated spend, and the Connection tab has a monthly limit.

= Can I see what is sent? =

Yes. As an administrator, open an item for editing and add &kdna_sw_debug=1 to the address. The debug panel shows the exact text the reader builds, with Test generate and the item's change log.

= What happens if I change the site name or the Yoast separator? =

The SEO titles the plugin writes keep Yoast's %%sep%% and %%sitename%% variables, so the change flows through to every title.

= WP-CLI =

`wp kdna-sw read <id>`, `wp kdna-sw generate <id> [--save]`, `wp kdna-sw sweep [--now]`, `wp kdna-sw alt <id>`, `wp kdna-sw report <id>` and `wp kdna-sw undo <log-id>`. Add `--term=<taxonomy>` for categories and tags.

= Uninstalling =

Yoast's SEO fields are always kept, including everything the plugin wrote. The plugin's own data (settings, API key, review queue, log) is only removed if "Delete the plugin's own data when the plugin is deleted" is ticked in the Advanced tab.

== Changelog ==

= 1.0.3 =
* New: Fill empty fields now, on the Overview tab, for the first run on a site. Shows how many items and images need doing and roughly what it will cost, then works through them all in the background, with progress and a Stop button.
* Generate now works on a new or unsaved post in both the classic and block editors: it saves the post as a draft first. On a published post with unsaved changes, it asks for Update first.

= 1.0.2 =
* Fix: a browser or password manager filling the API key box with a saved password no longer causes an error when saving other tabs, and can never replace the saved key. Only the Connection tab sends a key, and only one starting with sk-ant-.
* A rejected key now saves nothing, so the other settings are never left half saved.

= 1.0.1 =
* Content types with no public pages (such as JetEngine listing items and Elementor templates), and content types Yoast keeps out of search results, are now left out automatically. The Content tab lists them and says why.
* JetEngine fields can be marked as headings by adding :h2 to :h6, for example article_sections.section_heading:h2.
* Repeaters are now read row by row when several of their sub-fields are listed, so each heading is followed by its own text.
* Rich text JetEngine fields are no longer wrapped in an extra paragraph.

= 1.0.0 =
* First release.
