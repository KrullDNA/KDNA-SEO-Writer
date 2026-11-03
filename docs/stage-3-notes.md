# Stage 3 notes: automation and review queue

Built 4 November 2026. No zip has been made (packaging happens in Stage 5).

## What Stage 3 adds

| Part | File |
| --- | --- |
| Background jobs: on publish and update, nightly sweep, Generate now, spend limit, server load pause | `includes/class-kdna-sw-scheduler.php` |
| Deciding what is saved and what waits for review; the review queue | `includes/class-kdna-sw-queue.php` |
| Locks, last result and field status per item | `includes/class-kdna-sw-state.php` |
| Monthly usage, cost estimate, spend limit | `includes/class-kdna-sw-usage.php` |
| Overview: coverage, problems, Suggest improvements | `includes/class-kdna-sw-overview.php` |
| Save guard (see below) | `includes/class-kdna-sw-guard.php` |
| REST routes `/generate/{id}`, `/queue`, `/overview` | `includes/rest/` |
| Edit screen panels: classic box, block editor side panel, term screen box, Elementor Page Settings section | `admin/class-kdna-sw-editor.php`, `admin/js/editor-panel.js` |
| List screens: status column, Generate SEO row action, bulk action | `admin/class-kdna-sw-lists.php` |
| Overview, Review, Automation and Advanced tabs | `admin/views/tab-*.php`, `admin/js/settings.js` |
| Action Scheduler 4.2.0 (released 16 September 2026), loaded only when WooCommerce is not active | `lib/action-scheduler/` |

## What saves automatically

| Situation | Result |
| --- | --- |
| Empty field, Automatic content type (blog posts by default), every check passed | Saved straight into Yoast |
| Empty field, Review content type | Review queue ("New") |
| Any check still failing after the retry | Review queue ("Failed checks"), whatever the content type |
| Field already has text | Automatic jobs leave it alone. Generate now, Suggest improvements and Regenerate suggest a replacement for review ("Replaces existing text") |
| Field a person has edited or emptied (locked) | Automatic jobs leave it alone. Generate now suggests a replacement for review |
| Content type set to Off | Nothing happens |

Edited-then-approved text is locked afterwards, because the words are then a person's.

## The save guard (worth testing)

Yoast's box in the editor sends every field it holds each time a page is saved, empty ones included, and an empty value overwrites what is stored. This was checked in Yoast's code. So if the plugin fills a field in the background while someone has the page open, their next Update would wipe it.

To stop that, every editor screen now carries a hidden "screen opened at" time. This covers the classic editor, the block editor (added to its meta box form), Elementor (added to Yoast's panel form) and the category edit screen. On save, any field that meets all three of these conditions gets the stored value back:
- it arrives empty;
- it holds untouched plugin text;
- the plugin wrote it after the screen was opened.

Text a person types is always saved as typed.

## Loading on block editor saves

The block editor saves through the core REST API, not wp-admin. So the plugin now also wakes up for write requests (POST, PUT, PATCH) to the core `/wp/v2/` routes, and only those. Public pages, REST reads and other routes stay untouched, including WooCommerce basket requests to `/wc/store/`. `tests/gate.php` checks each case.

## Decisions to review

1. **No Message Batches for the sweep.** Each item may need a second request after the rule checks, which would mean a second overnight batch. At Claude Haiku 5.5 prices, a sweep of 50 items costs a few cents, so the 50% saving is small.
2. **Jobs are delayed after saves.** A job runs about two minutes after a save, which lets the block editor finish saving Yoast's box and turns a flurry of saves into one job.
3. **The sweep spaces its jobs out.** It queues items 30 seconds apart, and Action Scheduler runs one at a time. Items tried in the last 7 days are skipped, so pages that are always too short do not use up the nightly limit. This uses a small extra meta key, `kdna_sw_attempted`, so the sweep can sort in one query.
4. **The spend limit only pauses automatic jobs.** Generate now still works, because the owner asked for it.
5. **The server load pause uses the host's one-minute load average where available.** Jobs wait 15 minutes above 4.0, which can be changed with the `kdna_sw_max_load` filter.
6. **The Overview lists up to 100 items per problem type**, and Suggest improvements queues up to 50 items per click.
7. **Bulk approve runs in batches of 10 items**, sent one after another by the screen, so hundreds of items never hit the server's time limit.
8. **The Keyphrases tab stays a placeholder** until Stage 4.

## Testing on kdnastaging.com

1. Publish a blog post with empty Yoast fields. The post should save as fast as before, and the SEO Writer panel should show "Queued". Within a few minutes the fields should fill. Tools > Scheduled Actions, group `kdna-seo-writer`, shows the job.
2. Publish a page, a product and a category. Their suggestions should appear in Settings > KDNA SEO Writer > Review, and nothing should be saved until approved.
3. Edit a generated title by hand and save. The panel should show it as Locked. Run `wp kdna-sw sweep --now` and check the title is untouched.
4. Approve, edit then approve, and reject one suggestion each. Then use Approve all matching with 100 or more items.
5. Click Generate now in the Elementor editor's Page Settings > SEO Writer.
6. **Guard test:** open a page with empty Yoast fields in the editor, click Generate now and wait for "filled", then click Update without reloading. The new values must still be there.
7. Set a spend limit of US$0.01 and check automatic jobs pause with a notice.

## Logic tests

`php tests/run-stage3.php` runs 56 checks against stand-in WordPress and Yoast functions. The queue and log tables run in SQLite, so the plugin's real queries are exercised. It covers:
- routing;
- locks;
- the queue, including filters, rejections and bulk approve of 100 items;
- the scheduler's decisions;
- the cost estimate and spend limit;
- the save guard.

The sweep's main query was not run here, because it needs WordPress's own tables, so check it on staging with `wp kdna-sw sweep`.

`tests/run-stage1.php`, `tests/run-stage2.php` and `tests/gate.php` still pass.
