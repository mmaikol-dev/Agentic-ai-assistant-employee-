---
name: order-query
description: Retrieve and inspect orders using list_orders (filtered/paginated) and get_order (single record).
triggers: order, orders, list, find, search, show, status, pending, delivered, details, lookup
---

# Order Query Skill

Use this skill when the user wants to find, filter, browse, or inspect orders. These are read-only operations — never call create/edit tools for a query request.

---

## Tool Selection

| Intent | Tool |
|--------|------|
| Looking up one specific order by ID or order number | `get_order` |
| Listing, filtering, searching, or browsing orders | `list_orders` |

If the user gives an exact `id` or `order_no`, prefer `get_order` — it's faster and returns the full record directly. For everything else, use `list_orders`.

---

## get_order

Fetches a single order by identifier. Provide exactly one of:

| Field | Type | Notes |
|-------|------|-------|
| `id` | integer | Primary key — use when the user provides a numeric DB id |
| `order_no` | string | Order number — use when the user provides an order reference like "ORD-55" |

If the user provides neither, ask: *"Do you have the order number or ID?"* Don't fall back to `list_orders` speculatively.

### Success response (`type: order_detail`)
Display the full order detail. Key fields to surface:
`order_no`, `client_name`, `product_name`, `amount`, `status`, `code`, `city`, `country`, `agent`, `delivery_date`, `phone`, `comments`

### Error handling
- `Order not found` → tell the user and ask them to verify the ID or order number

---

## list_orders

Returns a paginated list of orders sorted by `order_date` descending. All parameters are optional.

### Filters

| Filter | Type | Behavior |
|--------|------|----------|
| `status` | string | Exact match (e.g. `"pending"`, `"delivered"`) |
| `client_name` | string | Partial match |
| `merchant` | string | Partial match |
| `phone` | string | Partial match |
| `code` | string | Partial match |
| `code_is_empty` | boolean | `true` = rows where code is null/blank; `false` = rows with a code value |
| `alt_no` | string | Partial match |
| `agent` | string | Exact match |
| `city` | string | Partial match |
| `country` | string | Exact match |
| `search` | string | Searches across: `order_no`, `product_name`, `client_name`, `merchant`, `city`, `agent`, `phone`, `code`, `alt_no`, `store_name` |

**Filter vs. search guidance:**
- Use specific filters when the user targets a known field (e.g. "orders for agent John" → `agent: "John"`)
- Use `search` for freeform queries where the field is unknown (e.g. "find anything related to Acme")
- Don't combine `search` with filters it already covers — it creates redundant conditions

**`code` field guidance:**
- To find orders with a specific code value → use `code: "XYZ"`
- To find orders that have no code assigned → use `code_is_empty: true`
- To find orders that have any code assigned → use `code_is_empty: false`
- `code_is_empty` and `code` are mutually exclusive — don't send both

### Pagination

| Field | Default | Max | Notes |
|-------|---------|-----|-------|
| `page` | 1 | — | Page number |
| `per_page` | 15 | 50 | Results per page; values below 5 are clamped to 5 |

If the user asks for "all orders" or a large set, start with `per_page: 50`. If results span multiple pages, tell the user and offer to fetch the next page.

### Success response (`type: orders_table`)
The tool returns:
```
total, current_page, last_page, per_page, orders[]
```

**Summarize results clearly:**
- State the total count and how many are shown: *"Showing 15 of 143 orders."*
- For each order, surface: `order_no`, `client_name`, `status`, `amount`, `code`, `city`
- If `last_page > current_page`, offer to fetch more: *"There are more pages — want to see page 2?"*

### Empty results
If `total: 0`, tell the user no orders matched and suggest loosening filters (e.g. check spelling, remove a filter, widen the search).

---

## Displaying the `code` Column

Always include `code` when displaying order lists or detail views. It is a key operational field.

- If `code` is null or empty, display as `—` (not blank, not "null")
- If the user asks to filter by code presence, use `code_is_empty` — don't attempt string matching for empty values

---

## Clarification Rules

- If the user's query maps cleanly to available filters, call the tool immediately — don't ask for confirmation
- Ask at most one clarifying question per turn, only when intent is genuinely ambiguous
- Never ask the user to provide filters that aren't needed for their request

---

## Rules

- Never invent, assume, or hallucinate order data
- Never call `create_order` or `edit_order` for a read request
- Don't make duplicate tool calls when results are already in context
- Base all responses strictly on tool output

---

## Quick Reference

| User says | Action |
|-----------|--------|
| "Show me order #ORD-88" | `get_order(order_no="ORD-88")` |
| "Get order with id 42" | `get_order(id=42)` |
| "List all pending orders" | `list_orders(status="pending")` |
| "Find orders for Jane Doe" | `list_orders(client_name="Jane Doe")` |
| "Orders handled by agent Sara" | `list_orders(agent="Sara")` |
| "Find anything related to Acme" | `list_orders(search="Acme")` |
| "Orders with no code" | `list_orders(code_is_empty=true)` |
| "Orders with code ABC" | `list_orders(code="ABC")` |
| "Show me page 2" | `list_orders(...same filters..., page=2)` |