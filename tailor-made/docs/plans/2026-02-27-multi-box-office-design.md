# Multi-Box-Office Support — Design Document

**Date:** 2026-02-27
**Version:** 2.0.0 (upgrade from 1.3.0)
**Status:** Approved

## Overview

Upgrade the Tailor Made plugin to support multiple Ticket Tailor box offices under one WordPress installation. Events from all box offices sync into a unified pool, filterable by box office via shortcodes, Bricks query loops, and taxonomy archives.

## Architecture: Custom DB Table + Taxonomy (Approach A)

### Data Model

#### New DB Table: `wp_tailor_made_box_offices`

```sql
CREATE TABLE wp_tailor_made_box_offices (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name         VARCHAR(255) NOT NULL,
    slug         VARCHAR(64)  NOT NULL,
    api_key      VARCHAR(512) NOT NULL,       -- encrypted with AUTH_KEY salt
    currency     VARCHAR(10)  NOT NULL DEFAULT 'usd',
    status       VARCHAR(20)  NOT NULL DEFAULT 'active',
    roster_token VARCHAR(128) DEFAULT NULL,
    last_sync    DATETIME     DEFAULT NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY idx_slug (slug)
);
```

#### New Taxonomy: `tt_box_office`

- Slug: `tt_box_office`
- Assigned to: `tt_event` CPT
- Hidden from post editor (managed automatically during sync)
- One term per box office, slug matches `box_offices.slug`
- Rewrite: `/events/box-office/{slug}/`

#### Updated Post Meta

Each `tt_event` post gets:
- `_tt_box_office_id` — ID from box offices table (for fast lookups)

### Sync Engine

**Trigger:** Single cron hook `tailor_made_sync_cron` + single "Sync All" button.

**Flow:**
1. Load all box offices with `status = 'active'`
2. For each box office:
   - Create API client with that box office's API key
   - Fetch all events from TT API
   - Create/update posts; assign `tt_box_office` taxonomy term + `_tt_box_office_id` meta
   - Scoped orphan deletion: only delete posts with `_tt_box_office_id = this_id` whose `_tt_event_id` is NOT in the API response
   - Update `last_sync` on the box office row
3. Aggregate results across all box offices for `tailor_made_last_sync_result`

**Error handling:** If one box office API fails, others still sync. Errors reported per box office.

**Logger:** Each box office sync gets its own `sync_id`. Log entries include box office name/id.

**Unique key:** `_tt_event_id` + `_tt_box_office_id` (not `_tt_event_id` alone, since different accounts could have overlapping IDs).

### Admin UI

#### Box Office Management

List table showing all box offices:

| Name | Slug | Currency | Events | Last Sync | Status | Actions |
|------|------|----------|--------|-----------|--------|---------|
| Tayseer Seminary | tayseer-seminary | USD | 5 | 2 min ago | Active | Edit / Test / Roster Link / Remove |

**Add form:** Name + API Key + "Test & Add" button (pings API, auto-fills name/currency).

**Edit:** Change name, API key, toggle active/paused.

**Remove:** Confirmation dialog with option to delete or keep associated events.

#### Dashboard Updates

- "Sync Now" syncs all active box offices
- Results show per-box-office breakdown
- Event count shows total + per-box-office

#### Sync Log Updates

- Log entries include box office name
- Filter dropdown for per-box-office log viewing

### Shortcodes

All existing shortcodes get optional `box_office` parameter:

```
[tt_events box_office="tayseer-seminary" limit="6" columns="3"]
[tt_events box_office="tayseer-seminary,tayseer-travel" limit="12"]
[tt_events limit="6"]                    <!-- all box offices -->

[tt_upcoming_count box_office="tayseer-travel"]
[tt_upcoming_count]                      <!-- all box offices -->

[tt_event_field field="box_office_name"] <!-- new field -->
```

Implementation: `box_office` param maps to `tax_query` on `tt_box_office` taxonomy.

Event card wrapper gets `.tt-box-office-{slug}` class for per-box-office CSS targeting.

### Bricks Integration

**New dynamic data tags:**
- `{tt_box_office_name}` — box office name
- `{tt_box_office_slug}` — box office slug

**Query loop filtering:** Native Bricks taxonomy filter UI works automatically since `tt_box_office` is a registered taxonomy.

### Magic Links / Rosters

#### Per-Event Rosters (unchanged)

Same as v1.3. Uses the correct box office's API key (looked up via `_tt_box_office_id`).

#### New: Per-Box-Office Rosters

**Shortcode:** `[tt_roster_box_office]`

**URL:** `roster-page?box_office_token=abc123...`

**Flow:**
1. Validate token, look up box office
2. Fetch all active events for that box office (from WP)
3. For each event, fetch issued tickets from TT API
4. Display grouped table: events as sections, attendees under each
5. Summary stats at top

**Token:** Stored in `roster_token` column on box offices table. Rotation/revocation per box office from admin.

**Caching:** 5-minute TTL, cached per box office.

### API Key Security

Keys encrypted using `openssl_encrypt()` with WordPress `AUTH_KEY` salt. Admin UI shows masked keys with reveal button.

### Migration (v1.3 -> v2.0)

On plugin update/activation:

1. Create `wp_tailor_made_box_offices` table via `dbDelta()`
2. Register `tt_box_office` taxonomy
3. If `tailor_made_api_key` option exists:
   - Ping API to get box office name + currency
   - Insert row into new table
   - Assign all existing `tt_event` posts to this box office (taxonomy term + meta)
   - Delete old option
4. Flush rewrite rules

### Backward Compatibility

- Shortcodes without `box_office` param show all events (no breaking change)
- Existing Bricks templates with `{tt_*}` tags unchanged
- `[tt_roster]` per-event shortcode unchanged
- Existing magic link tokens continue to work

## Test Box Offices

| Name | API Key | Status |
|------|---------|--------|
| Tayseer Seminary | sk_12025_292896_... | Active, 5 events |
| Tayseer Travel | sk_12338_295388_... | Active, 0 events |

## Files Affected

- `tailor-made.php` — version bump, taxonomy registration, migration hook
- `includes/class-admin.php` — box office management UI, updated dashboard
- `includes/class-api-client.php` — accept API key per-instance (already supports this)
- `includes/class-sync-engine.php` — iterate box offices, scoped orphan deletion
- `includes/class-sync-logger.php` — add box office context to log entries
- `includes/class-shortcodes.php` — add `box_office` param, new field
- `includes/class-bricks-provider.php` — new dynamic tags
- `includes/class-magic-links.php` — use correct API key per event, new box office roster
- `includes/class-box-office-manager.php` — NEW: CRUD for box offices table, encryption
- `uninstall.php` — drop new table, clean up taxonomy
- `assets/css/shortcodes.css` — box office class on cards
