# Create an Event Occurrence

**POST** `https://api.tickettailor.com/v1/event_series/:event_series_id/events`

Creates a new event occurrence (a specific date) within an event series.

## Path Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `event_series_id` | string | The event series ID (e.g. `es_123456`) |

## Request

**Content-Type:** `application/x-www-form-urlencoded`

### Required Fields

| Parameter | Type | Description |
|-----------|------|-------------|
| `start_date` | string | Start date in `YYYY-MM-dd` format (e.g. `2026-07-15`) |
| `end_date` | string | End date in `YYYY-MM-dd` format (e.g. `2026-07-16`) |

### Optional Fields

| Parameter | Type | Description |
|-----------|------|-------------|
| `start_time` | string | Start time in `HH:ii:ss` format (e.g. `19:15:00`) |
| `end_time` | string | End time in `HH:ii:ss` format (e.g. `23:15:00`) |
| `hidden` | boolean | `true` or `false` — hide from public listing |
| `unavailable` | boolean | `true` or `false` — mark as unavailable |
| `unavailable_status` | string | Custom message when unavailable |
| `online_link` | string | URL for online events |
| `override_id` | string | Override ID to apply (e.g. `ov_123`) |

## Response

**Status:** `201 Created`

Returns the event occurrence object with:
- `id` — unique event occurrence ID
- `event_series_id` — parent series
- `start` / `end` — date/time objects with `date`, `formatted`, `iso`, `time`, `timezone`, `unix`
- `ticket_types` — array of ticket types with pricing and quantities
- `revenue`, `currency`, `url`, `total_issued_tickets`

## Source

[developers.tickettailor.com](https://developers.tickettailor.com/docs/api/create-event-series-event)
