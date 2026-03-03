# Create an Event Series

**POST** `https://api.tickettailor.com/v1/event_series`

Creates a new event series. An event series is the parent container; individual dates (occurrences) are added separately.

## Request

**Content-Type:** `application/x-www-form-urlencoded`

### Required Fields

| Parameter | Type | Description |
|-----------|------|-------------|
| `name` | string | Event series name |
| `currency` | string | Currency code (see allowed values below) |

### Optional Fields

| Parameter | Type | Description |
|-----------|------|-------------|
| `access_code` | string | Code to access a protected event |
| `country` | string | ISO 3166 country code (e.g. `US`) |
| `description` | string | Event description |
| `max_tickets_sold_per_occurrence` | integer | Max tickets per occurrence across all types |
| `postal_code` | string | Venue postal code |
| `venue` | string | Venue name |
| `tickets_available_at` | integer | UTC unix timestamp — tickets go on sale |
| `tickets_available_at_message` | string | Message shown before sale starts; supports `{countdown}` |
| `tickets_unavailable_at` | integer | UTC unix timestamp — tickets stop selling |
| `tickets_unavailable_at_message` | string | Message shown after sales end |
| `online_platform` | string | Platform name (e.g. "Zoom") |
| `voucher_ids` | string[] | Array of voucher IDs |
| `waitlist_active` | string | `true`, `no_tickets_available`, or `false` |
| `waitlist_call_to_action` | string | Waitlist button text |
| `waitlist_event_page_text` | string | Description above waitlist form |
| `waitlist_confirmation_message` | string | Message after waitlist signup |

### Allowed Currencies

`gbp`, `usd`, `eur`, `sgd`, `aud`, `brl`, `cad`, `czk`, `dkk`, `hkd`, `huf`, `ils`, `jpy`, `myr`, `mxn`, `nok`, `nzd`, `php`, `pln`, `rub`, `sek`, `chf`, `twd`, `thb`, `try`

## Response

**Status:** `201 Created`

Returns the full event series object including `id`, `name`, `status`, `currency`, `timezone`, `venue`, `ticket_types`, `ticket_groups`, `payment_methods`, `images`, `revenue`, `url`, etc.

## Error Response

```json
{
  "status": 400,
  "error_code": "VALIDATION_ERROR",
  "message": "One or more fields failed validation",
  "errors": [{"field": "name", "messages": ["Value is required and can't be empty"]}]
}
```

## Source

[developers.tickettailor.com](https://developers.tickettailor.com/docs/api/create-event-series)
