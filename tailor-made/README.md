# Tailor Made

Unofficial Ticket Tailor API integration for WordPress. Syncs events, ticket types, pricing, venues, and checkout links into a custom post type that works as native dynamic data in Bricks Builder.

## What it does

- Supports multiple Ticket Tailor box offices under one WordPress install
- Pulls all events from the Ticket Tailor API into a `tt_event` custom post type
- Stores every field as post meta: dates, venue, ticket types, prices, checkout URLs, images, capacity, availability
- Registers 29 dynamic data tags in Bricks Builder (under the "Ticket Tailor" group)
- Tags each event with its source box office via the `tt_box_office` taxonomy
- Works with Bricks query loops so you can build event grids, cards, and listings
- Shortcodes for displaying events without a page builder
- Auto-syncs every hour via WP-Cron
- Manual sync button in the admin dashboard
- Cleans up events that are deleted from Ticket Tailor (scoped per box office)
- API key encryption at rest
- Auto-updates from GitHub releases

## Requirements

- WordPress 5.0+
- PHP 7.4+
- Bricks Builder (for dynamic data tags; the CPT works without it)
- A Ticket Tailor account with an API key

## Install

Download the latest release zip from the [Releases](https://github.com/wavedepth/tailor-made/releases) page and upload it via Plugins > Add New > Upload Plugin.

Or clone directly into your plugins directory:

```
cd wp-content/plugins/
git clone https://github.com/wavedepth/tailor-made.git
```

Activate the plugin in WordPress.

## Configure

1. Go to **Tailor Made > Dashboard** in the WordPress admin sidebar
2. Click **Add Box Office**
3. Enter a name (e.g. "Tayseer Seminary") and the API key from Ticket Tailor (starts with `sk_`)
4. Click **Save** — the API key is encrypted before being stored
5. Click **Test Connection** to verify the key works
6. Click **Sync Now** to pull events from all active box offices

Your API key is in Ticket Tailor under Box Office Settings > API Keys. Each box office in Ticket Tailor has its own API key.

## Multiple Box Offices

Tailor Made supports multiple Ticket Tailor box offices under a single WordPress install. Each box office has its own API key and syncs independently.

**How it works:**

- Add box offices via the **Dashboard** tab — each gets a name, slug, and API key
- Events from each box office are tagged with the `tt_box_office` taxonomy term (matching the box office slug)
- Each event stores a `_tt_box_office_id` meta field linking it to the internal box office record
- Orphan deletion is scoped per box office — removing one box office only deletes its events
- One box office failing to sync does not affect the others

**Filtering by box office:**

- **Bricks query loops:** Add a taxonomy filter on `tt_box_office` and select the term(s) you want
- **Shortcodes:** Use the `box_office` attribute: `[tt_events box_office="tayseer-seminary"]`
- **WP_Query:** Use a standard `tax_query` on `tt_box_office`

**Managing box offices:**

- **Enable/disable** a box office without deleting it (pauses sync)
- **Delete** a box office to remove it and all its events
- **Edit** the name or API key at any time

## Auto-sync

The plugin registers a WP-Cron job that syncs every hour. When an event is created, updated, or deleted in Ticket Tailor, the change appears in WordPress within an hour.

For real-time sync, you can trigger the sync endpoint manually or set up a server cron to hit it more frequently.

## Auto-updates

The plugin checks GitHub releases for new versions. When a new release is published, WordPress will show the update in the Plugins page and you can update with one click, the same as any plugin from wordpress.org.

## Bricks Builder dynamic data tags

All tags appear under the "Ticket Tailor" group in the Bricks dynamic data picker.

| Tag | Description |
|-----|-------------|
| `{tt_event_name}` | Event title |
| `{tt_event_description}` | Event description (HTML) |
| `{tt_event_id}` | Ticket Tailor event ID (e.g. `ev_7669695`) |
| `{tt_status}` | Event status (`draft`, `published`, `past`) |
| `{tt_start_date}` | Start date (`2026-06-01`) |
| `{tt_start_time}` | Start time (`18:00`) |
| `{tt_start_formatted}` | Formatted start (`Mon Jun 1, 2026 6:00 PM`) |
| `{tt_end_date}` | End date |
| `{tt_end_time}` | End time |
| `{tt_end_formatted}` | Formatted end |
| `{tt_venue_name}` | Venue name |
| `{tt_venue_country}` | Venue country code |
| `{tt_image_header}` | Header image URL (from Ticket Tailor) |
| `{tt_image_thumbnail}` | Thumbnail image URL |
| `{tt_checkout_url}` | Direct checkout link |
| `{tt_event_url}` | Public event page URL |
| `{tt_call_to_action}` | CTA button text |
| `{tt_online_event}` | Whether event is online (`true`/`false`) |
| `{tt_tickets_available}` | Whether tickets are available |
| `{tt_min_price}` | Lowest ticket price in cents |
| `{tt_max_price}` | Highest ticket price in cents |
| `{tt_min_price_formatted}` | Lowest price formatted (`$75.00` or `Free`) |
| `{tt_max_price_formatted}` | Highest price formatted |
| `{tt_total_capacity}` | Total ticket capacity across all types |
| `{tt_tickets_remaining}` | Remaining tickets |
| `{tt_total_orders}` | Number of orders placed |
| `{tt_currency}` | Currency code (`usd`, `gbp`, etc.) |
| `{tt_timezone}` | Timezone (`America/New_York`) |
| `{tt_box_office_name}` | Box office display name |
| `{tt_box_office_slug}` | Box office slug (for filtering) |

## Using with Bricks query loop

1. Add a Container or Div element in Bricks
2. Enable **Query Loop** on it
3. Set post type to **TT Events**
4. Inside the loop, add elements and bind them to dynamic data tags above
5. For the register/checkout button, use `{tt_checkout_url}` as the link URL and `{tt_call_to_action}` as the button text

The featured image is also set during sync, so `{featured_img_url}` works as well.

## Post meta reference

All event data is stored as post meta on the `tt_event` CPT. You can query these directly with `get_post_meta()` or use them in any plugin that reads custom fields.

| Meta key | Type | Description |
|----------|------|-------------|
| `_tt_event_id` | string | Ticket Tailor event ID |
| `_tt_event_series_id` | string | Parent event series ID |
| `_tt_status` | string | TT status |
| `_tt_currency` | string | Currency code |
| `_tt_start_date` | string | YYYY-MM-DD |
| `_tt_start_unix` | int | Unix timestamp |
| `_tt_end_date` | string | YYYY-MM-DD |
| `_tt_end_unix` | int | Unix timestamp |
| `_tt_venue_name` | string | Venue name |
| `_tt_venue_country` | string | ISO country code |
| `_tt_checkout_url` | string | Direct checkout URL |
| `_tt_event_url` | string | Public event page |
| `_tt_ticket_types` | JSON | Full ticket type array |
| `_tt_min_price` | int | Lowest price in cents |
| `_tt_max_price` | int | Highest price in cents |
| `_tt_total_capacity` | int | Total seats |
| `_tt_tickets_remaining` | int | Remaining seats |
| `_tt_box_office_id` | int | Internal box office table ID |
| `_tt_raw_json` | JSON | Complete API response |

## Shortcodes

Display events anywhere without Bricks Builder.

### `[tt_events]`

Shows a grid or list of events.

| Attribute | Default | Description |
|-----------|---------|-------------|
| `limit` | `6` | Number of events to show |
| `status` | `publish` | Post status filter |
| `orderby` | `_tt_start_unix` | Meta key to sort by |
| `order` | `ASC` | Sort direction |
| `columns` | `3` | Grid columns |
| `show` | `image,title,date,price,location,description,button` | Fields to display |
| `style` | `grid` | Layout: `grid` or `list` |
| `box_office` | *(all)* | Filter by box office slug. Comma-separated for multiple |

### `[tt_event]`

Shows a single event card.

| Attribute | Default | Description |
|-----------|---------|-------------|
| `id` | *required* | WP post ID or TT event ID (`ev_xxx`) |
| `show` | `image,title,date,price,location,description,button` | Fields to display |

### `[tt_event_field]`

Outputs a single event field value inline.

| Attribute | Default | Description |
|-----------|---------|-------------|
| `field` | *required* | Meta key without `_tt_` prefix (e.g. `venue_name`). Also supports `box_office_name` as a virtual field |
| `id` | *current post* | WP post ID or TT event ID |

### `[tt_upcoming_count]`

Outputs the number of upcoming events.

| Attribute | Default | Description |
|-----------|---------|-------------|
| `box_office` | *(all)* | Filter by box office slug |

### `[tt_roster_box_office]`

Renders an attendee roster for all events in a box office.

| Attribute | Default | Description |
|-----------|---------|-------------|
| `slug` | *required* | Box office slug |

### Examples

```
[tt_events]                                        — All events, default grid
[tt_events box_office="tayseer-seminary"]           — Events from one box office
[tt_events box_office="tayseer-seminary,tayseer-travel" limit="3"]  — Multiple box offices
[tt_events style="list" columns="1"]               — List layout
[tt_upcoming_count]                                — Count of all upcoming events
[tt_upcoming_count box_office="tayseer-seminary"]  — Count for one box office
[tt_event_field field="box_office_name"]            — Box office name for current event
[tt_roster_box_office slug="tayseer-seminary"]      — Roster for a box office
```

## Ticket Tailor API coverage

The plugin's API client supports these endpoints:

- `GET /v1/ping` -- Health check
- `GET /v1/overview` -- Account summary
- `GET /v1/events` -- All events (paginated)
- `GET /v1/events/:id` -- Single event
- `GET /v1/event_series` -- All event series
- `GET /v1/event_series/:id` -- Single series
- `GET /v1/orders` -- All orders
- `GET /v1/issued_tickets` -- All issued tickets
- `GET /v1/vouchers` -- Vouchers
- `GET /v1/products` -- Products
- `GET /v1/checkout_forms` -- Checkout forms
- `GET /v1/stores` -- Store info

All paginated endpoints are automatically fetched in full (cursor-based pagination with limit 100).

## License

GPL-2.0-or-later
