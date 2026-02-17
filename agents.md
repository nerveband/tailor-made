# Tailor Made — Agent Reference

Development notes, quirks, and patterns for AI agents working on this plugin.

## Plugin Architecture

```
tailor-made/
├── tailor-made.php              # Main plugin file (requires, hooks, cron)
├── agents.md                    # This file
└── includes/
    ├── class-api-client.php     # TT API wrapper (DO NOT MODIFY)
    ├── class-cpt.php            # tt_event post type (DO NOT MODIFY)
    ├── class-sync-engine.php    # Sync logic + logger integration
    ├── class-sync-logger.php    # DB-backed logging
    ├── class-admin.php          # Admin UI (5 tabs)
    ├── class-bricks-provider.php # Bricks dynamic data (DO NOT MODIFY)
    └── class-github-updater.php # Auto-update from GitHub (DO NOT MODIFY)
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
- **No categories/tags:** TT provides NO categorization for events — filtering must be done by keyword search on title or meta queries
- **Event statuses:** `published`, `live`, `past`, `draft` — no custom statuses
- **Prices in cents:** All price values are in cents (divide by 100 for display)

### WordPress / Bricks

- **Post type:** `tt_event` — registered with `show_in_menu => false` (shown under Tailor Made menu instead)
- **Draft events:** Events with TT status `draft` sync as WP Draft posts. Query loops must explicitly include `post_status => any` or `draft` to show them on the front end
- **Meta key prefix:** All meta keys start with `_tt_` (underscore prefix = hidden from default custom fields UI)
- **Dynamic data in Bricks:** Custom fields use `{cf__tt_fieldname}` syntax — note the **double underscore** (`cf_` + `_tt_`)
- **Query loop filtering:** Use `s` parameter for title/content keyword search. Use Meta Queries for field-value filtering
- **Bricks content storage:** Page content stored in `_bricks_page_content_2` post meta as a flat JSON array of elements with parent references

### Sync Engine

- **Full replace, not incremental:** Each sync fetches ALL events from TT and overwrites WP data completely
- **Orphan deletion:** Posts in WP whose `_tt_event_id` is no longer in the API response are permanently deleted (`wp_delete_post($id, true)`)
- **Featured image caching:** Images are only re-downloaded when the source URL changes (`_tt_image_header_source` meta tracks the current URL)
- **Logger integration:** The sync engine creates a `Tailor_Made_Sync_Logger` instance. When logging is disabled, all `->log()` calls are no-ops (checked via `get_option` in constructor)

### Sync Logger

- **DB table:** `{prefix}tailor_made_sync_log` — created on plugin activation via `dbDelta()`
- **If table doesn't exist:** Re-activate the plugin: `wp plugin deactivate tailor-made && wp plugin activate tailor-made`
- **Sync run grouping:** Each sync generates a UUID (`sync_id`). All log entries from that run share the same ID for filtering
- **Cleanup cron:** `tailor_made_log_cleanup_cron` runs daily, purges entries older than `tailor_made_log_retention_days` option (default 30)

### Admin UI

- **5 tabs:** Dashboard, How To Use, How Sync Works, Sync Log, About
- **Tab routing:** Query param `?page=tailor-made&tab=<tab-slug>`
- **AJAX endpoints:** All use `tailor_made_nonce` for security
  - `tailor_made_sync` — run sync
  - `tailor_made_test_connection` — ping TT API
  - `tailor_made_compare_events` — TT vs WP comparison
  - `tailor_made_clear_logs` — truncate log table
  - `tailor_made_save_log_settings` — update logging options
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
ssh runcloud@23.94.202.65 "cd ~/webapps/TS-Staging && wp eval 'require_once ABSPATH . \"wp-content/plugins/tailor-made/tailor-made.php\"; \$e = new Tailor_Made_Sync_Engine(); print_r(\$e->sync_all());'"
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
Use `wp eval` to get/modify `_bricks_page_content_2` post meta. Content is a flat JSON array — elements reference parents by ID, not nesting. Always use `array_splice` to insert at the right position.

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
- `{featured_image}` / `{featured_image_url}` — header image
- `{cf__tt_start_formatted}` — formatted date
- `{cf__tt_price_display}` — price range
- `{cf__tt_checkout_url}` — link for buttons

## Testing Checklist

After any change:
1. Upload file(s) via SCP
2. Load the admin page — check for PHP errors (white screen = fatal error)
3. Test each affected tab renders correctly
4. If sync engine changed: run a manual sync and check results
5. If logger changed: enable logging, run sync, check log entries
6. If activation hooks changed: deactivate + reactivate plugin
