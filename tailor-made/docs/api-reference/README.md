# Ticket Tailor API Reference

Local reference for the Ticket Tailor REST API endpoints used by Tailor Made.

**Base URL:** `https://api.tickettailor.com/v1`
**Auth:** HTTP Basic — base64-encode `API_KEY:` (key as username, empty password)
**Rate limit:** 5,000 requests per 30 minutes

## Endpoints

### Event Series (CRUD)

| Method | Endpoint | Doc |
|--------|----------|-----|
| GET | `/v1/event_series` | [get-all-event-series.md](get-all-event-series.md) |
| GET | `/v1/event_series/:id` | [get-event-series-by-id.md](get-event-series-by-id.md) |
| POST | `/v1/event_series` | [create-event-series.md](create-event-series.md) |
| PATCH | `/v1/event_series/:id` | [update-event-series.md](update-event-series.md) |
| POST | `/v1/event_series/:id/status` | [change-event-series-status.md](change-event-series-status.md) |
| DELETE | `/v1/event_series/:id` | [delete-event-series.md](delete-event-series.md) |

### Event Occurrences

| Method | Endpoint | Doc |
|--------|----------|-----|
| GET | `/v1/events` | [get-all-events.md](get-all-events.md) |
| GET | `/v1/events/:id` | [get-event-by-id.md](get-event-by-id.md) |
| POST | `/v1/event_series/:id/events` | [create-event-occurrence.md](create-event-occurrence.md) |
| PATCH | `/v1/events/:id` | [update-event-occurrence.md](update-event-occurrence.md) |
| DELETE | `/v1/events/:id` | [delete-event-occurrence.md](delete-event-occurrence.md) |

### Ticket Types

| Method | Endpoint | Doc |
|--------|----------|-----|
| POST | `/v1/event_series/:id/ticket_types` | [create-ticket-type.md](create-ticket-type.md) |
| PATCH | `/v1/ticket_types/:id` | [update-ticket-type.md](update-ticket-type.md) |
| DELETE | `/v1/ticket_types/:id` | [delete-ticket-type.md](delete-ticket-type.md) |

### Other

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/v1/ping` | Health check |
| GET | `/v1/overview` | Account summary |
| GET | `/v1/orders` | All orders |
| GET | `/v1/issued_tickets` | All issued tickets |
| GET | `/v1/vouchers` | Vouchers |
| GET | `/v1/products` | Products |
| GET | `/v1/checkout_forms` | Checkout forms |
| GET | `/v1/stores` | Store info |

## Full Sitemap

See [Ticket Tailor API Docs](https://developers.tickettailor.com/docs/api/ticket-tailor-api) for the complete list.
