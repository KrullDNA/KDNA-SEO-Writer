# Stage 5 notes: hardening, review and packaging

Built 4 November 2026. Package: `kdna-seo-writer-1.0.0.zip` in the repository root. It contains one folder, `kdna-seo-writer/`, with 147 files (371 KB). The Version header, the `KDNA_SW_VERSION` constant, the readme's Stable tag and the zip name all read 1.0.0.

## 1. Security (brief section 7.4)

| Area | Result |
| --- | --- |
| REST routes | All 12 have a permission check. Settings, test, queue, overview and keyphrases need `manage_options`; generate and report need `edit_post` or `edit_term` for that item. WordPress requires the REST nonce for logged-in requests, and `wp.apiFetch` sends it |
| Queue actions | Each approve, reject and regenerate also checks the user can edit the item itself |
| admin-post actions | Debug and row actions check a nonce and capabilities. Bulk actions use WordPress's own nonce plus a capability check per item |
| Decorative checkbox | Capability checked on save |
| API key | Encrypted with libsodium using the site's salts. Never in page output, REST responses, debug output, the log or Action Scheduler arguments. Error messages never include it. The Stage 1 and Stage 2 tests check this |
| Output | All PHP output is escaped. The Alpine screens use `x-text` and `x-model` only, never `x-html` |
| SQL | Every query is built with `$wpdb->prepare()` or fixed table and column names |
| Prompt injection | Page text is wrapped as material to describe, with an instruction to ignore instructions inside it. Every answer is checked in PHP before saving |
| Save guard | Can only put back values already stored, never write new ones |

## 2. Privacy

**Fixed in this stage:** the page reader now renders every item as a logged-out visitor. Background jobs can run while an administrator is logged in, because Action Scheduler's quick runner passes their login along. Without this fix, a shortcode such as a WooCommerce account or orders block could have put that person's details into the text sent to Anthropic. A test checks it (`tests/run-stage4.php`, privacy section).

The rest was confirmed by reading the code:
- Only the item's own content is sent, plus its published context: category item titles, and for alt text the page the image is on.
- Prices, stock and sale dates are never read.
- Password-protected items are skipped.
- Orders, users and form entries are never read.

What is sent is listed in `readme.txt` under "Privacy: what is sent to Anthropic", ready for client privacy policies.

## 3. Cost

| Item | Result |
| --- | --- |
| Prompt caching | The brand rules block is marked for caching. Anthropic only caches prefixes of 512 tokens or more on these models. The metadata prefix measured about 640 tokens (empty brand rules) to 820 tokens (typical), so it caches. The alt text prefix is about 320 to 500 tokens, so it usually does not. Padding it would save about a 200th of a cent per image, so it was left as is |
| Text trimming | Page text is cut to 12,000 characters (Advanced tab), keeping the start |
| Images | The site's "large" size is used when available. Otherwise files over 4.5 MB or very large images are resized to 1024 pixels before sending |
| Spend limit | Pauses automatic page and image jobs. Tested in Stage 3 |
| Usage estimate | Read straight from the token log, with cache reads at 0.1 times and cache writes at 1.25 times the input price. Tested in Stage 3 |

## 4. Lean and performance (sections 2.2 and 2.3)

| Check | Result |
| --- | --- |
| Public pages | `tests/gate.php`: home page, a shop page, REST reads, and WooCommerce basket requests to `/wc/store/` register **0 hooks** and load **no classes**. The plugin's main file only defines constants and functions |
| Autoloaded options | None. Settings, key, version and transients are not autoloaded, so public pages do not load them |
| Admin assets | Settings CSS and JS load only on Settings > KDNA SEO Writer. Panel CSS and JS load only on edit screens of enabled content types. A few lines of inline CSS go on enabled list screens. Nothing loads elsewhere |
| Saving a post | Adds a handful of light reads (Yoast's values are already cached by WordPress), one Action Scheduler lookup and one insert. No request to Claude |
| Background work | Action Scheduler runs one job at a time. The sweep spaces jobs 30 seconds apart at the quiet hour, and jobs wait while the server's load average is above 4 |
| Unused code | A scan of every function found none unreferenced. The zip contains no tests or docs. Action Scheduler is the 4.2.0 release files only, without its tests and docs |
| Front-end on/off comparison | **Not measured here.** WordPress cannot be run in this build environment. Please record it on staging in the table below |

| Public page | Query Monitor queries, plugin off | Plugin on | Load time off | Load time on |
| --- | --- | --- | --- | --- |
| Home | | | | |
| A blog post | | | | |
| A product | | | | |

## 5. Accessibility

- Settings tabs follow the tabs pattern: role="tablist", tab and tabpanel, aria-selected, a roving tabindex, and Left, Right, Home and End keys (checked in a browser).
- Every form field has a label. Review checkboxes, Approve and Reject buttons say which item and field they act on.
- Unlock and Copy buttons say which field or suggestion they apply to.
- Status is never shown by colour alone: every coloured dot has text beside it, and tick and cross icons have screen reader text.
- Length bars are hidden from screen readers, because the text below them says the same.
- Messages use `role="status"` and `aria-live`.
- Text colours on their backgrounds measure 4.8:1 or better.

## 6. UK English and em dashes

Every UI string, the readme and the prompts were scanned: no em dashes and no US spellings. The only em dash characters in the code are data, not text: Yoast's list of separators, and the character width table. The words "color" and "moisturizer" appear once, on purpose, in the spelling option's example.

## 7. Testing checklist (section 10) on staging

1. Upload `kdna-seo-writer-1.0.0.zip`, activate it, and check there are no errors and the version reads 1.0.0.
2. Add the API key and click Test connection.
3. Set brand rules with one banned word and one claim rule.
4. Publish a blog post with empty Yoast fields. All five fields should fill, with every metadata check green in Yoast.
5. Publish a page, a product and a category. They should wait in Review.
6. Approve, edit then approve, and reject one suggestion each.
7. Edit a generated title by hand, run `wp kdna-sw sweep --now`, and check the title is untouched.
8. View the page source: one title tag, one meta description, correct social tags.
9. Run the keyphrase report on an Elementor page and compare it with Yoast.
10. Approve alt text for a product image. Check the media library and the page source (see the block editor note in `docs/stage-4-notes.md`).
11. Check that no saved text contains the banned word, an em dash, a price or a date.
12. Undo from the log.
13. Set a low spend limit and check jobs pause with a notice.
14. View source on a public page: no plugin files.
15. Use Query Monitor with the plugin on and off, and fill in the table above.
16. Run the sweep while browsing the site.
17. Check the browser console and debug.log are clear.

## Logic tests

`tests/run-stage1.php` (55 checks), `run-stage2.php` (79), `run-stage3.php` (56) and `run-stage4.php` (55) all pass, as does `tests/gate.php`. They run on stand-in WordPress functions, with SQLite for the plugin's own SQL. They do not replace the staging checklist.
