# WUA MCP Abilities

Custom WordPress plugin by White Unicorn Agency. It registers a library of "abilities" (functions) on `wp_abilities_api_init` for use with AI tools such as Claude via the MCP Adapter.

**Current version: 2.10** — see the Changelog section below. The canonical version number lives in the header of `wua-mcp-abilities.php`; keep it and the changelog in sync on every change.

## Requirements

* The **MCP Adapter** plugin must be installed and active for this plugin to work.
* The **Abilities API** plugin may also be required, depending on your MCP Adapter version.
* **ACF Pro** must be installed and active for the `wua-mcp-acf/*` abilities to register — the ACF file bails early if ACF is missing.
* To use with Claude Desktop, add the server to `claude_desktop_config.json` (File → Settings → Developer → Edit Config).

## File layout

* `wua-mcp-abilities.php` — main plugin file and version header. Registers the `wua-mcp-abilities` category and **31** abilities (content, post meta, users, site info, media, taxonomies, maintenance), and loads the two files below.
* `wua-mcp-acf-abilities.php` — registers the `wua-mcp-acf` category and **7** ACF abilities.
* `wua-mcp-filesystem-abilities.php` — registers the `wua-mcp-filesystem` category and **4** filesystem abilities.

**Total: 42 abilities across 3 categories.** (If you add or remove abilities, update these counts.)

## Usage notes & gotchas

Non-obvious behaviors that have caused problems before — read these before using or extending the plugin:

* **Writing HTML or multi-line values:** `update-post-meta` sanitizes its input (strips HTML tags and collapses whitespace/newlines). For rich text, HTML, ACF WYSIWYG/textarea content, or already-serialized values, use **`update-post-meta-raw`**, which writes the exact bytes to the database. Verify writes with `search-post-meta` or `get-post-meta` — both return the raw stored value.
* **ACF Local JSON is the source of truth.** `acf-update-local-json-field-group` writes the theme's `acf-json/<group_key>.json` file only; ACF auto-registers the group from that file on the next load. Do **not** re-introduce `acf_update_field_group()` / `acf_update_field()` calls into that ability — doing so lets ACF's save hook overwrite the freshly-written JSON from stale state and creates duplicate `acf-field-group` DB records (see the 2.9 changelog entry). As of 2.10, `acf-create-field-group` is also JSON-first — it writes a complete `acf-json/<key>.json` file (creating the directory if needed) rather than a DB record.
* **Filesystem abilities are confined to `wp-content`.** Paths may be given relative to `wp-content` or as an absolute path. On Windows / Local, the write and delete guards normalize both sides of the containment check with `wp_normalize_path()` so back-slash vs. forward-slash mismatches don't wrongly reject valid paths (see the 2.8 changelog entry). As of 2.10, `fs-write-file` auto-creates missing parent directories (`wp_mkdir_p`, still inside the wp-content guard) and rejects paths containing `..`.
* **ACF field-value writes:** prefer `acf-update-field-value` and `acf-update-options` — they call `update_field()` so ACF handles serialization. Pass an attachment ID (integer) for image/file fields.

## Changelog

* **2.10** — `acf-create-field-group` now writes Local JSON (with its fields) instead of calling `acf_update_field_group()` alone, which created the group but saved none of its fields (`acf-get-fields` returned nothing). It builds the `acf-json` directory if missing. `fs-write-file` now creates missing parent directories (`wp_mkdir_p`) and rejects `..` path traversal.
* **2.9** — `acf-update-local-json-field-group` now writes Local JSON only. Removed the `acf_update_field_group()` / `acf_update_field()` DB-sync block that (1) let ACF's save hook overwrite the just-written JSON file, dropping the new fields, and (2) created duplicate `acf-field-group` records in the database.
* **2.8** — Fixed the filesystem write/delete path guard on Windows / Local. The containment check compared `realpath()` (back-slashes) against `WP_CONTENT_DIR` (forward slash), so every write was wrongly rejected; both sides are now normalized with `wp_normalize_path()`.
* **2.7** — Merge of the v2.2 and v2.6 lineages: v2.2's verbose schema descriptions and robust error handling (GIF + PNG-alpha thumbnails, set-featured-image validation, broader cache-plugin support, detailed bulk-meta change logs) combined with v2.6's expanded toolset (search-post-meta, create-term / get-terms / set-post-terms, copy-post-meta, update-post-meta-raw, fs-delete-file) and the acf-update-options helper.

## Maintaining this plugin

* Bump `Version:` in `wua-mcp-abilities.php` and add a Changelog entry above for every change.
* If you add or remove abilities, update the counts in the File layout section.
* When rolling a new version out to other WUA-managed sites, remember that older copies may still carry the bugs fixed here. In particular, sites that ran the pre-2.9 ACF ability may have leftover duplicate `acf-field-group` rows to trash — keep the row with the "loaded from JSON" badge and trash the other.

## Resources

* https://github.com/WordPress/mcp-adapter/releases
* https://github.com/WordPress/abilities-api/releases
* https://4wp.dev/how-to-use-mcp-with-wordpress-step-by-step/
* https://www.youtube.com/watch?v=5b9al9C3bMk&t=3s
