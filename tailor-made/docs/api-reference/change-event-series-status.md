# Change Event Series Status

**POST** `https://api.tickettailor.com/v1/event_series/:event_series_id/status`

Changes the status of an event series (publish, unpublish, close sales).

## Path Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `event_series_id` | string | The event series ID (e.g. `es_123456`) |

## Request

**Content-Type:** `application/x-www-form-urlencoded`

| Field | Type | Required | Allowed Values |
|-------|------|----------|----------------|
| `status` | string | Yes | `DRAFT`, `PUBLISHED`, `CLOSE_SALES` |

### Status Values

| Value | Effect |
|-------|--------|
| `DRAFT` | Unpublishes the event — hides from public, preserves registrations |
| `PUBLISHED` | Makes the event live and visible |
| `CLOSE_SALES` | Keeps event visible but stops ticket sales |

## Response

**Status:** `200 OK`

Returns the full event series object with updated status.

## Error Response

```json
{
  "status": 400,
  "error_code": "VALIDATION_ERROR",
  "message": "One or more fields failed validation",
  "errors": [{"field": "status", "messages": ["Invalid value"], "expected": [{"type": "enum", "allowedValues": ["DRAFT", "PUBLISHED", "CLOSE_SALES"]}]}]
}
```

## Source

[developers.tickettailor.com](https://developers.tickettailor.com/docs/api/change-event-series-status)
