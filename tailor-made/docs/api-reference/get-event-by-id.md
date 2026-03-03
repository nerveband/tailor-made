# Get Event by ID

**GET** `https://api.tickettailor.com/v1/events/:event_id`

Returns a single event occurrence with full details.

## Path Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `event_id` | string | Event occurrence ID (e.g. `ev_7669695`) |

## Response

**Status:** `200 OK`

### Full Response Schema

```
object (root)
├── object: string ("event")
├── id: string (e.g. "ev_7669695")
├── event_series_id: string
├── name: string
├── description: string (nullable)
├── status: string (draft, published, sales_closed)
├── currency: string
├── timezone: string
├── start: object
│   ├── date: string (YYYY-MM-dd)
│   ├── time: string (HH:mm)
│   ├── formatted: string (e.g. "Wed Jul 15, 2026 9:00 AM")
│   ├── iso: string (ISO 8601)
│   ├── unix: integer
│   └── timezone: string
├── end: object (same structure as start)
├── venue: object
│   ├── name: string (nullable)
│   ├── country: string (nullable, ISO 3166)
│   └── postal_code: string (nullable)
├── images: object
│   ├── header: string (URL)
│   └── thumbnail: string (URL)
├── checkout_url: string
├── url: string
├── call_to_action: string
├── online_event: string ("true"/"false")
├── online_link: string (nullable)
├── private: string ("true"/"false")
├── hidden: string ("true"/"false")
├── unavailable: string ("true"/"false")
├── unavailable_status: string (nullable)
├── available_status: string (nullable)
├── access_code: string (nullable)
├── tickets_available: string ("true"/"false", nullable)
├── tickets_available_at: integer (nullable, unix)
├── tickets_available_at_message: string
├── tickets_unavailable_at: integer (nullable, unix)
├── tickets_unavailable_at_message: string
├── ticket_types: array of objects (nullable)
│   ├── id: string
│   ├── name: string
│   ├── price: integer (cents)
│   ├── quantity: integer (total)
│   ├── quantity_issued: integer
│   ├── quantity_held: integer
│   ├── quantity_in_baskets: integer
│   ├── quantity_total: integer
│   ├── status: string (on_sale, sold_out, unavailable, hidden, admin_only, locked)
│   ├── type: string (paid, free)
│   ├── booking_fee: integer
│   ├── min_per_order: integer
│   ├── max_per_order: integer
│   ├── sort_order: integer
│   ├── description: string (nullable)
│   ├── group_id: string (nullable)
│   ├── access_code: string (nullable)
│   ├── hide_when_sold_out: string ("true"/"false")
│   ├── show_quantity_remaining: string ("true"/"false")
│   ├── show_quantity_remaining_less_than: integer (nullable)
│   ├── hide_until: object (nullable, date/time)
│   ├── hide_after: object (nullable, date/time)
│   ├── override_id: string (nullable)
│   ├── has_overrides: string ("true"/"false")
│   └── discounts: array of strings
├── ticket_groups: array of objects (nullable)
│   ├── id: string
│   ├── name: string
│   ├── sort_order: integer
│   ├── min_per_order: integer (nullable)
│   ├── max_per_order: integer (nullable)
│   ├── max_quantity: integer (nullable)
│   ├── ticket_ids: array of strings
│   └── bundle_ids: array of strings (nullable)
├── payment_methods: array of objects
│   ├── id: string
│   ├── external_id: string
│   ├── type: string (stripe, paypal, offline)
│   ├── name: string
│   └── instructions: string
├── revenue: number
├── total_orders: integer
├── total_issued_tickets: integer
├── total_holds: integer
├── sales_tax_label: string (nullable)
├── sales_tax_percentage: integer (nullable)
├── sales_tax_treatment: string (nullable, inclusive/exclusive)
├── transaction_fee_fixed_amount: integer (nullable)
├── transaction_fee_percentage: integer (nullable)
├── show_map: string ("true"/"false")
├── max_tickets_sold_per_occurrence: integer (nullable)
├── created_at: integer (unix)
├── chk: string
├── voucher_ids: array of strings
├── waitlist_active: string (true, no_tickets_available, false)
├── waitlist_call_to_action: string
├── waitlist_event_page_text: string
└── waitlist_confirmation_message: string
```

## Source

[developers.tickettailor.com](https://developers.tickettailor.com/docs/api/get-event-by-id)
