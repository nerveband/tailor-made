# Create a Ticket Type

**POST** `https://api.tickettailor.com/v1/event_series/:event_series_id/ticket_types`

Creates a new ticket type for an event series.

## Path Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `event_series_id` | string | The event series ID (e.g. `es_123456`) |

## Request

**Content-Type:** `application/x-www-form-urlencoded`

### Required Fields

| Parameter | Type | Description |
|-----------|------|-------------|
| `name` | string | Ticket type name (e.g. "General Admission") |
| `price` | integer | Price in cents (e.g. `7500` = $75.00). Use `0` for free tickets |
| `quantity` | integer | Number of tickets available |

### Optional Fields

| Parameter | Type | Description |
|-----------|------|-------------|
| `access_code` | string | Code to access hidden ticket |
| `booking_fee` | integer | Per-ticket fee in cents |
| `description` | string | Ticket type description |
| `discounts` | string[] | Array of discount IDs |
| `group_id` | string | Ticket group ID |
| `hide_after` | integer | Unix timestamp — hide after this time |
| `hide_until` | integer | Unix timestamp — hide until this time |
| `hide_when_sold_out` | boolean | Auto-hide when sold out |
| `max_per_order` | integer | Max quantity per order |
| `min_per_order` | integer | Min quantity per order |
| `show_quantity_remaining` | string | Show remaining count |
| `show_quantity_remaining_less_than` | integer | Only show remaining when below this number |
| `status` | string | `ON_SALE`, `SOLD_OUT`, `UNAVAILABLE`, `HIDDEN`, `ADMIN_ONLY`, `LOCKED` |

## Response

**Status:** `200 OK`

Returns the ticket type object with:
- `id` — unique ticket type ID
- `name`, `price`, `quantity`, `status`, `type` (`paid`/`free`)
- `booking_fee`, `min_per_order`, `max_per_order`
- `quantity_held`, `quantity_issued`, `quantity_in_baskets`, `quantity_total`
- `sort_order`, `hide_when_sold_out`, `show_quantity_remaining`
- `group_id`, `access_code`, `description`, `override_id`, `has_overrides`
- `discounts`

## Source

[developers.tickettailor.com](https://developers.tickettailor.com/docs/api/create-ticket-type-for-event-series)
