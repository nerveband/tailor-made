# Tailor Made

Unofficial Ticket Tailor API integration for WordPress. Syncs events, ticket types, pricing, venues, and checkout links into a custom post type that works as native dynamic data in Bricks Builder.

## What it does

- Pulls all events from the Ticket Tailor API into a `tt_event` custom post type
- Stores every field as post meta: dates, venue, ticket types, prices, checkout URLs, images, capacity, availability
- Registers 27 dynamic data tags in Bricks Builder (under the "Ticket Tailor" group)
- Works with Bricks query loops so you can build event grids, cards, and listings
- Auto-syncs every hour via WP-Cron
- Manual sync button in the admin dashboard
- Cleans up events that are deleted from Ticket Tailor
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

1. Go to **Tailor Made** in the WordPress admin sidebar
2. Enter your Ticket Tailor API key (starts with `sk_`)
3. Click **Save Settings**
4. Click **Test Connection** to verify
5. Click **Sync Now** to pull events

Your API key is in Ticket Tailor under Box Office Settings > API Keys.

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
| `_tt_raw_json` | JSON | Complete API response |

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
