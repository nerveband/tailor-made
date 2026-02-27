# Tailor Made — Agent Reference

Development notes, quirks, and patterns for AI agents working on this plugin.

## Plugin Architecture

```
tailor-made/
├── tailor-made.php                # Main plugin file (requires, hooks, cron)
├── agents.md                      # This file
├── prompts/                       # AI workflow prompt templates
└── includes/
    ├── class-api-client.php       # TT API wrapper (DO NOT MODIFY)
    ├── class-box-office-manager.php # Multi-box-office CRUD, encryption, DB table
    ├── class-cpt.php              # tt_event post type + tt_box_office taxonomy (DO NOT MODIFY)
    ├── class-sync-engine.php      # Sync logic — iterates box offices, scoped orphan deletion
    ├── class-sync-logger.php      # DB-backed logging
    ├── class-admin.php            # Admin UI (7 tabs: Dashboard, How To Use, How Sync Works, Shortcodes, Magic Links, Sync Log, About)
    ├── class-bricks-provider.php  # Bricks dynamic data (DO NOT MODIFY)
    └── class-github-updater.php   # Auto-update from GitHub (DO NOT MODIFY)
```

## Server Access

- **Staging SSH:** `runcloud@23.94.202.65`
- **Plugin path:** `~/webapps/TS-Staging/wp-content/plugins/tailor-made/`
- **WP-CLI:** `/usr/local/bin/wp` (use `wp` not `wp-cli.phar`)
- **Staging URL:** ts-staging.wavedepth.com

## Deployment Pattern

Files are edited locally then uploaded via SCP:
```bash
scp "local/path/file.php" runcloud@23.94.202.65:~/webapps/TS-Staging/wp-content/plugins/tailor-made/path/file.php
```

After changes that affect activation hooks (DB tables, cron):
```bash
ssh runcloud@23.94.202.65 "cd ~/webapps/TS-Staging && wp plugin deactivate tailor-made && wp plugin activate tailor-made"
```

## Key Quirks

### Ticket Tailor API
- **Auth:** Basic auth with API key as username, empty password: `base64_encode($api_key . ':')`
- **Pagination:** Uses `starting_after` param with last item's ID (not page numbers)
- **No categories/tags:** TT provides NO categorization for events — but Tailor Made groups events by box office via the `tt_box_office` taxonomy. Within a box office, filtering is by keyword search on title or meta queries
- **Event statuses:** `published`, `live`, `past`, `draft` — no custom statuses
- **Prices in cents:** All price values are in cents (divide by 100 for display)

### WordPress / Bricks

- **Post type:** `tt_event` — registered with `show_in_menu => false` (shown under Tailor Made menu instead)
- **Draft events:** TT `draft` events now map to WP `publish` (changed in v1.1.0) so they render on the front end. Previously mapped to WP `draft` which is invisible to non-logged-in users even with `post_status => any` (since draft has `exclude_from_search = true`)
- **Meta key prefix:** All meta keys start with `_tt_` (underscore prefix = hidden from default custom fields UI)
- **Dynamic data in Bricks:** Custom fields use `{cf__tt_fieldname}` syntax — note the **double underscore** (`cf_` + `_tt_`)
- **Query loop filtering:** Use `s` parameter for title/content keyword search. Use Meta Queries for field-value filtering
- **Bricks content storage:** Page content stored in `_bricks_page_content_2` post meta as a flat JSON array of elements with parent references
- **CRITICAL — Bricks meta filter bypass:** Bricks hooks on `update_post_metadata` silently block external writes to `_bricks_page_content_2`. Three hooks intercept: `Bricks\Ajax::update_bricks_postmeta`, `Bricks\Ajax::sanitize_bricks_postmeta`, and `Bricks\Query_Filters::qf_update_post_metadata`. **You MUST use `$wpdb->update()` directly** to write Bricks content, then call `wp_cache_delete($post_id, 'post_meta')` and `clean_post_cache($post_id)` to clear caches. Using `update_post_meta()` will silently fail.

### Multi-Box-Office Architecture

- **Box office table:** `{prefix}tailor_made_box_offices` — stores name, slug, encrypted API key, status (active/inactive), created/updated timestamps
- **API key encryption:** Keys encrypted with `openssl_encrypt()` using WordPress `AUTH_KEY` and `AUTH_SALT` — decrypted on use only
- **Taxonomy:** `tt_box_office` — each box office gets a term; events are tagged during sync
- **Meta field:** `_tt_box_office_id` — links each event post to its box office table row
- **Manager class:** `Tailor_Made_Box_Office_Manager` handles all CRUD, encryption/decryption, and DB migrations

### Sync Engine

- **Multi-box-office sync:** `sync_all_box_offices()` iterates all active box offices, calling `sync_all()` for each with the box office's own API key
- **Full replace, not incremental:** Each sync fetches ALL events from TT and overwrites WP data completely
- **Scoped orphan deletion:** Posts are only orphan-deleted within the box office being synced — one box office's cleanup never touches another's events
- **Compound matching:** Events matched by `_tt_event_id` + `_tt_box_office_id` (not just event ID alone)
- **Fault isolation:** If one box office's API call fails, the sync continues with remaining box offices
- **Featured image caching:** Images are only re-downloaded when the source URL changes (`_tt_image_header_source` meta tracks the current URL)
- **Logger integration:** The sync engine creates a `Tailor_Made_Sync_Logger` instance. When logging is disabled, all `->log()` calls are no-ops (checked via `get_option` in constructor)

### Sync Logger

- **DB table:** `{prefix}tailor_made_sync_log` — created on plugin activation via `dbDelta()`
- **If table doesn't exist:** Re-activate the plugin: `wp plugin deactivate tailor-made && wp plugin activate tailor-made`
- **Sync run grouping:** Each sync generates a UUID (`sync_id`). All log entries from that run share the same ID for filtering
- **Cleanup cron:** `tailor_made_log_cleanup_cron` runs daily, purges entries older than `tailor_made_log_retention_days` option (default 30)

### Admin UI

- **7 tabs:** Dashboard, How To Use, How Sync Works, Shortcodes, Magic Links, Sync Log, About
- **Tab routing:** Query param `?page=tailor-made&tab=<tab-slug>`
- **AJAX endpoints:** All use `tailor_made_nonce` for security
  - `tailor_made_sync` — run sync
  - `tailor_made_test_connection` — ping TT API
  - `tailor_made_compare_events` — TT vs WP comparison
  - `tailor_made_clear_logs` — truncate log table
  - `tailor_made_save_log_settings` — update logging options
  - `tailor_made_add_box_office` — create a new box office
  - `tailor_made_edit_box_office` — update box office name/key
  - `tailor_made_delete_box_office` — remove box office and its events
  - `tailor_made_test_box_office` — test a box office API key
  - `tailor_made_toggle_box_office` — enable/disable a box office
- **Changelog:** Lives in the `render_tab_about()` method — update it when making changes

## Version Bumping

When releasing a new version:
1. Update `TAILOR_MADE_VERSION` in `tailor-made.php`
2. Update the `Version:` header in the plugin header comment
3. Add a new changelog section in `class-admin.php` → `render_tab_about()` → Changelog div
4. Tag the release on GitHub for the auto-updater

## Common Operations

### Run a sync manually via CLI
```bash
ssh runcloud@23.94.202.65 "cd ~/webapps/TS-Staging && wp eval 'print_r(Tailor_Made_Sync_Engine::sync_all_box_offices());'"
```

### List box offices
```bash
ssh runcloud@23.94.202.65 "cd ~/webapps/TS-Staging && wp db query \"SELECT id, name, slug, status FROM wp_tailor_made_box_offices\""
```

### Check log entries
```bash
ssh runcloud@23.94.202.65 "cd ~/webapps/TS-Staging && wp db query \"SELECT * FROM wp_tailor_made_sync_log ORDER BY id DESC LIMIT 20\""
```

### List events
```bash
ssh runcloud@23.94.202.65 "cd ~/webapps/TS-Staging && wp post list --post_type=tt_event --fields=ID,post_title,post_status --format=table"
```

### Check cron jobs
```bash
ssh runcloud@23.94.202.65 "cd ~/webapps/TS-Staging && wp cron event list --fields=hook,next_run_relative | grep tailor"
```

### Inject Bricks content into a page
Content is a flat JSON array — elements reference parents by ID, not nesting. **Must use `$wpdb` directly** (not `update_post_meta`) due to Bricks meta filters that silently block external writes.

```php
// Read content
$raw = $wpdb->get_var( $wpdb->prepare(
    "SELECT meta_value FROM $wpdb->postmeta WHERE post_id = %d AND meta_key = %s",
    $post_id, '_bricks_page_content_2'
) );
$content = maybe_unserialize( $raw );

// Modify content...
array_splice( $content, $insert_pos, 0, $new_elements );

// Write directly to DB (bypasses Bricks filters)
$wpdb->update(
    $wpdb->postmeta,
    array( 'meta_value' => maybe_serialize( $content ) ),
    array( 'post_id' => $post_id, 'meta_key' => '_bricks_page_content_2' ),
    array( '%s' ),
    array( '%d', '%s' )
);
wp_cache_delete( $post_id, 'post_meta' );
clean_post_cache( $post_id );
```

Best run via `wp eval-file` with a PHP file uploaded via SCP.

## Bricks Query Loop JSON Pattern

When injecting a query loop section into Bricks content:

```php
// Loop container element
array(
    'id' => 'unique6',
    'name' => 'block',
    'parent' => $parent_id,
    'children' => array($card_id),
    'settings' => array(
        'hasLoop' => 'query',
        'query' => array(
            'post_type' => array('tt_event'),
            'posts_per_page' => 6,
            's' => 'keyword',           // title/content search
            'post_status' => 'any',     // include drafts
            'orderby' => 'meta_value_num',
            'meta_key' => '_tt_start_unix',
            'order' => 'ASC'
        ),
    ),
    'label' => 'Events Loop'
)
```

Child elements inside the loop use dynamic data:
- `{post_title}` — event name
- `{featured_image}` — header image (use `useDynamicData` key, NOT `useDynamic`)
- `{cf__tt_start_formatted}` — formatted date
- `{cf__tt_price_display}` — price range
- `{cf__tt_checkout_url}` — link for buttons

**Image element dynamic data syntax:**
```php
'settings' => array(
    'image' => array( 'useDynamicData' => '{featured_image}' ),
    'tag'   => 'figure',
)
```
Note: `useDynamicData` (not `useDynamic`) — Bricks silently ignores the wrong key.

## Staging Pages

| Page | ID | URL | Notes |
|------|----|-----|-------|
| Shamail | 58 | ts-staging.wavedepth.com/shamail/ | Has injected "Shamail Events" query loop section (filtered by `s: "Shamail"`) |
| Events | 1453 | ts-staging.wavedepth.com/events/ | Shows all tt_events with `post_status: any` |

All 5 TT events currently sync as WP `draft` posts — query loops MUST include `post_status: any` to display them.

## Testing Checklist

After any change:
1. Upload file(s) via SCP
2. Load the admin page — check for PHP errors (white screen = fatal error)
3. Test each affected tab renders correctly
4. If sync engine changed: run a manual sync and check results
5. If logger changed: enable logging, run sync, check log entries
6. If activation hooks changed: deactivate + reactivate plugin
