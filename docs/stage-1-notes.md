# Stage 1 notes: foundation, connection and page reader

Built 4 November 2026. Plugin code is in `kdna-seo-writer/`. No zip has been made (packaging happens in Stage 5).

## What Stage 1 includes

| Part | File |
| --- | --- |
| Bootstrap, version checks, backend-only loading | `kdna-seo-writer.php` |
| Loader, Yoast notice, WooCommerce/Elementor/JetEngine detection, HPOS declaration | `includes/class-kdna-sw-plugin.php` |
| Queue and log tables (versioned) | `includes/class-kdna-sw-install.php` |
| Settings with defaults from section 8 | `includes/class-kdna-sw-settings.php` |
| Encrypted API key | `includes/class-kdna-sw-crypto.php` |
| Anthropic client and Test connection | `includes/class-kdna-sw-claude.php`, `includes/rest/` |
| Page reader | `includes/class-kdna-sw-reader.php` |
| Settings screen (Alpine.js 3.17.5, bundled) and debug mode | `admin/` |

## Checked during the build

- **Anthropic API:** version header `2026-10-21` (this version rejects temperature, top_p and top_k, so none are sent). Models: Claude Haiku 5.5 (`claude-haiku-5-5`, default, low cost) and Claude Sonnet 5.5 (`claude-sonnet-5-5`, higher quality). Prompt caching uses `cache_control` on the brand rules block (minimum cacheable length 512 tokens on these models). Checked against Anthropic's API reference dated October 2026, because docs.anthropic.com is blocked by this environment's network settings. Worth a quick look at the live docs in the Stage 5 review.
- **Elementor 4.5.0:** pages are read with `Plugin::$instance->frontend->get_builder_content_for_display()`. If that returns nothing, the plugin reads Elementor's saved widget data directly.
- **Yoast SEO 28.9:** title templates live in the `wpseo_titles` option (`title-{post type}`, `title-tax-{taxonomy}`). These are used in Stage 2.

## Decisions to review

1. **Structured answers use a JSON schema, not forced tool use.** Claude Sonnet 5.5 rejects a forced `tool_choice`, so the client asks for a JSON schema on the reply (`output_config.format`). The result is the same structured data, and it is guaranteed to match the schema.
2. **Token usage is logged in `kdna_sw_log`** (rows with `kind = usage`), so no third table is needed.
3. **Test connection** looks the model up (free), then sends a tiny request (a fraction of a cent), which also catches accounts with no credit.
4. **Sonnet 5.5 requests switch on Anthropic's server-side fallback.** If a safety check declines a request, Anthropic re-runs it on another model. Haiku 5.5 has no fallback. Batch requests in Stage 3 must leave the fallback out.
5. **Debug mode** (`&kdna_sw_debug=1`) shows a panel on the post edit screen and on category and tag edit screens. It does not run on the front end.
6. **The Overview, Review, Keyphrases, Automation and Advanced tabs** show a placeholder for now. Their settings already exist with the defaults from section 8.

## Testing on kdnastaging.com

1. Upload the `kdna-seo-writer` folder (zip it by hand as a test build, for example `kdna-seo-writer-1.0.0-stage1-test.zip`) and activate it.
2. Settings > KDNA SEO Writer > Connection: paste a key, click Test connection. Try a wrong key and check the message.
3. View the page source of the settings screen and run `/wp-json/kdna-sw/v1/settings` in the browser while logged in: the key must not appear.
4. Open an Elementor page, a block editor post, a product, a JetEngine CPT item and a product category for editing, add `&kdna_sw_debug=1` to the address and check the text shown.
5. Load a public page with Query Monitor, with the plugin on and off: the query count should match, and no kdna-seo-writer files should load.

## Logic tests

`php tests/run-stage1.php` runs 55 checks against stand-in WordPress functions (encryption, settings cleaning, page reader, Anthropic error handling). `php tests/gate.php /any/public/page/` confirms nothing is registered on public requests. These do not replace testing on a real site.
