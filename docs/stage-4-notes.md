# Stage 4 notes: image alt text and keyphrase report

Built 4 November 2026. No zip has been made (packaging happens in Stage 5).

## What Stage 4 adds

| Part | File |
| --- | --- |
| Finding images, image usage, resizing, Claude request, checks, Decorative flag | `includes/class-kdna-sw-alt-text.php` |
| Keyphrase report (introduction, subheadings, slug, alt text) and slug Apply | `includes/class-kdna-sw-report.php` |
| REST `/report/{id}` (read, apply slug) and `/keyphrases` | `includes/rest/class-kdna-sw-rest-report.php` |
| Images listed by the page reader | `includes/class-kdna-sw-reader.php` |
| Alt text in the review queue (thumbnail, page it was written for), approve, regenerate, undo | `includes/class-kdna-sw-queue.php`, `includes/class-kdna-sw-log.php` |
| Report in the classic box, block editor panel and Elementor section | `admin/js/editor-panel.js` |
| Keyphrases tab (register, duplicates, search, report per item) | `admin/views/tab-keyphrases.php`, `admin/js/settings.js` |
| "Write alt text" link in the media library list | `admin/class-kdna-sw-lists.php` |
| WP-CLI: `wp kdna-sw alt <id> [--context=<post>] [--queue]`, `wp kdna-sw report <id>` | `includes/class-kdna-sw-cli.php` |

## How alt text works

- **When it runs.** When an item is processed (on publish, Generate now or the sweep), its images with empty alt text each get their own background job, up to 10 per item. The nightly sweep uses any places left after pages for the rest of the media library: featured images first, then images attached to a page, then the rest. Images tried in the last week are skipped.
- **Where images are found.** The featured image, images in the content (`wp-image-123` classes and image block IDs), Elementor's saved widget data (image widgets, galleries and backgrounds, all stored as `{"id":…, "url":…}`) and the WooCommerce gallery.
- **What Claude sees.** A copy of the image, using the site's own "large" size (1024 pixels) when it exists, or a copy resized in memory if the file is over 4.5 MB or very large. Claude also gets the page title, H1 and introduction, the file name, title and caption. JPEG, PNG, GIF and WebP only; SVG and other types are skipped.
- **The keyphrase rule.** The page's keyphrase is only offered when the image is used on that one published page. Usage is checked across featured images, post content, Elementor data and WooCommerce galleries. Claude is told to use it only if it truthfully describes the image, and is given the page's current count against Yoast's guide. For a shared image, a check refuses any alt text that contains the keyphrase.
- **Checks.** The alt text must be 1 to 125 characters (the Advanced setting), must not start with "image of", "photo of" or similar, and must follow the brand rules. A failure gets one retry naming the problem.
- **Review.** Every suggestion goes to the Review tab, with a thumbnail and the page it was written for. Approving saves it into the media library (`_wp_attachment_image_alt`), logs it with undo, and clears WP Rocket for that page.
- **Decorative.** There is a checkbox in the media library's attachment details. Ticking it keeps the alt text empty on purpose, removes any waiting suggestion, and the plugin skips the image from then on.

## Important: block editor images

WordPress's image block stores its own copy of the alt text inside the page content when the image is placed. Approving alt text updates the media library straight away, and the page source changes wherever WordPress reads the media library: featured images, Elementor image widgets and WooCommerce product images. An image block that already sits in a page keeps its old (empty) copy until the image is reselected in the block, or the text is pasted in. The plugin never edits page content, as the brief requires, so the keyphrase report says this where it matters. **This affects the acceptance check "Approving alt text updates the media library and the page source" for block editor pages only.**

## Keyphrase report

The rules follow Yoast's own analysis code (yoastseo package, Yoast SEO 28.9):

| Check | Green when |
| --- | --- |
| Keyphrase in introduction | Every meaningful keyphrase word is in one sentence of the first paragraph. "Could be better" when they are spread across the paragraph |
| Keyphrase in subheadings | 30% to 75% of the H2 and H3 headings reflect the keyphrase, or the only subheading does. A text under 300 words with no subheadings is fine |
| Keyphrase in slug | One- or two-word keyphrases need every word in the slug; longer ones need more than half |
| Keyphrase in image alt text | Fewer than 5 images: at least one. Exactly 5: two to four. More: 30% to 75% |

"Reflects the keyphrase" uses Yoast's matching: every word for one- or two-word keyphrases, more than half for longer ones. Yoast free matches exact word forms, as this report does. Yoast Premium also matches other forms (for example "serums"), so Premium may show green where the report shows amber.

Suggested wording (opening line, headings, slug) comes from the item's last Generate run and is only shown when it was written for the current keyphrase. **Apply this slug** appears only on items that have never been published. On published items, the report explains that the old address will stop working unless a redirect is added. The report appears in the classic box, the block editor panel, Elementor's Page Settings section and the Keyphrases tab.

## Decisions to review

1. **Images in the content count, the featured image does not.** The alt text rule counts images in the page content, matching what the reader sees.
2. **Database table version 4** adds `context_id` to the queue, the page an alt text suggestion was written for. It is added automatically.
3. **The Decorative flag** is stored as attachment meta `kdna_sw_decorative`, and removed on uninstall if "delete plugin data" is ticked.

## Testing on kdnastaging.com

1. Publish a page whose images have no alt text. Their suggestions should appear in the Review tab with thumbnails. Try product shots and lifestyle photos.
2. Put one image on two pages and generate for both. Its alt text must not contain either page's keyphrase.
3. Approve alt text for a featured or product image. Check Media Library > the image, and the page source.
4. Mark an image Decorative and check it is never suggested again.
5. Compare the keyphrase report with Yoast's own analysis on an Elementor page for the introduction, subheadings and slug. Use `wp kdna-sw report <id>`, the edit screen panel or the Keyphrases tab.
6. Apply a suggested slug on a draft. Check a published page shows the redirect warning instead.
7. Confirm no page content changed.

## Logic tests

`php tests/run-stage4.php` runs 53 checks. Posts, meta, queue and log run in SQLite, so the usage and sweep queries are exercised, and a real PNG is used. It covers:
- the reader's image list;
- finding images on an item (featured, content, blocks, Elementor, gallery);
- usage across pages;
- the sweep order and exclusions;
- the Claude request (image block, keyphrase allowed or not, retry);
- the checks;
- the queue, approve and undo;
- Decorative;
- every report rule, including slug Apply on drafts only.

Stages 1 to 3 and `tests/gate.php` still pass.
