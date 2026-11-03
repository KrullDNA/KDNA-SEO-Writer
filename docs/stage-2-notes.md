# Stage 2 notes: writing engine and Yoast writer

Built 4 November 2026. No zip has been made (packaging happens in Stage 5).

## What Stage 2 adds

| Part | File |
| --- | --- |
| Prompt building, structured answer, one retry naming the failures, saving | `includes/class-kdna-sw-writer.php` |
| Every rule from section 3 plus the brand rules, checked in PHP | `includes/class-kdna-sw-rules.php` |
| Yoast field keys, term storage, title templates, indexable refresh | `includes/class-kdna-sw-yoast.php` |
| Site-wide keyphrase register | `includes/class-kdna-sw-keyphrases.php` |
| Change log with undo | `includes/class-kdna-sw-log.php` |
| Fingerprints of written values (kdna_sw_state) | `includes/class-kdna-sw-state.php` |
| WP Rocket purge of the one changed URL | `includes/class-kdna-sw-cache.php` |
| Debug panel: Test generate, Generate and save, change list with Undo | `admin/class-kdna-sw-debug.php` |
| WP-CLI: `wp kdna-sw read / generate / undo` | `includes/class-kdna-sw-cli.php` |

## Confirmed against Yoast SEO 28.9

- **Post fields** (`WPSEO_Meta::set_value()`): `_yoast_wpseo_title`, `_yoast_wpseo_metadesc`, `_yoast_wpseo_focuskw`, `_yoast_wpseo_opengraph-title`, `_yoast_wpseo_opengraph-description`, `_yoast_wpseo_twitter-title`, `_yoast_wpseo_twitter-description`.
- **Term fields**: option `wpseo_taxonomy_meta[taxonomy][term_id]`, keys `wpseo_title`, `wpseo_desc`, `wpseo_focuskw`, `wpseo_opengraph-*`, `wpseo_twitter-*`, saved with `WPSEO_Taxonomy_Meta::set_values()`. Yoast resets any key it is not given, so the plugin always passes the term's full existing values with only its own fields changed. This protects canonical URLs and noindex settings.
- **Indexable refresh**: `YoastSEO()->classes->get( Indexable_Post_Watcher / Indexable_Term_Watcher )->build_indexable( $id )`, the same methods Yoast runs when you save in the editor. Terms need this explicit call, because Yoast only rebuilds them on its own when the term edit screen is saved.
- **Rule thresholds** come from Yoast's own analysis code (the yoastseo package):
  - descriptions must be more than 120 and at most 156 characters;
  - titles must be 400 to under 600 pixels wide in 20px Arial;
  - the exact keyphrase must start the title (function words like "the" may come first);
  - one or two sentences of the description must contain every keyphrase word;
  - the keyphrase must have 1 to 4 content words.

## How the SEO title is saved

Claude writes only the words of the title. The plugin adds the Yoast variables from the site's title template after it, for example `Bio Retinol Serum for Smoother Looking Skin %%page%% %%sep%% %%sitename%%`, so a later change to the site name or separator flows through. Only `%%page%%`, `%%sep%%` and `%%sitename%%` are kept. For categories, Yoast's default word "Archives" is left out.

## Decisions to review

1. **An existing focus keyphrase is kept.** If a page already has one (usually chosen by a person), Claude is told to use it and the title and description are written around it, so they match what Yoast analyses.
2. **The retry keeps whichever answer breaks fewer rules.** If both fail, the result is marked "needs review" with every failed check listed.
3. **Title width is measured in PHP** with Arial character widths, with a 10 pixel safety margin on the long side. It can differ from the browser's measurement by a few pixels.
4. **The em dash rule checks the plugin's own words only.** If the site's Yoast separator is an em dash, that is a site setting, not text the plugin wrote.
5. **Test generate runs while you wait.** Both Test generate and Generate and save in the debug panel do this, because they are test tools for administrators. Normal generation runs in the background from Stage 3.
6. **The log table gained an `object_subtype` column** (table version 2), so undo knows a term's taxonomy. It is added automatically.

## Testing on kdnastaging.com

1. Open a page for editing and add `&kdna_sw_debug=1` to the address.
2. Click **Test generate**: check the values and the list of ticks and crosses.
3. Click **Generate and save into empty fields**. Then check Yoast's analysis on the page. These should be green: keyphrase in SEO title, keyphrase in meta description, meta description length, SEO title width and previously used keyphrase.
4. View the page source: there should be exactly one title and one meta description, both the new ones.
5. Repeat on a product category.
6. In the debug panel's Recent changes, click **Undo** on a field and check the old value is back.
7. Add a banned word in Brand voice, generate again, and check it never appears in saved text.

With WP-CLI: `wp kdna-sw generate <id> [--term=<taxonomy>] [--save] [--replace]` and `wp kdna-sw undo <log-id>`.

## Logic tests

`php tests/run-stage2.php` runs 79 checks:
- unit-style tests of every rule check;
- the Yoast field handling, including the term reset protection;
- the full generate, retry, save and undo flow against a stand-in Yoast and fake Claude replies.

`php tests/run-stage1.php` and `php tests/gate.php` still pass.
