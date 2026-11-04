# Version 1.0.1 notes

Package: `kdna-seo-writer-1.0.1.zip` (1.0.0 kept for rollback). No database changes.

## Changes

1. **Content types with no SEO use are left out automatically.** These are:
   - design building blocks: JetEngine listing items, popups, menus and theme parts, Elementor templates and WordPress block templates (more can be added with the `kdna_sw_excluded_post_types` filter);
   - types WordPress gives no public pages;
   - types Yoast is set to keep out of search results (Yoast's `noindex-{type}` and `noindex-tax-{taxonomy}` settings).

   They are always treated as Off, even if a mode was saved for them earlier. The Content tab lists them under the tables with the reason.
2. **Heading markers for JetEngine fields.** Add `:h2` to `:h6` to a field, for example `article_sections.section_heading:h2`. The field is then read as a heading, so Claude and the keyphrase report see it as one.
3. **Repeaters are read row by row** when several of their sub-fields are listed, so each section heading is followed by its own text.
4. **Rich text (WYSIWYG) fields are no longer wrapped in an extra paragraph.**
5. **The first paragraph must be at least 8 words**, so a short subtitle or tagline is not taken as the introduction.
6. **The JetEngine fields box is now a larger text box**, for long field lists.

## Tests

`php tests/run-1.0.1.php` runs 17 checks. Stages 1 to 4 and `tests/gate.php` still pass.
