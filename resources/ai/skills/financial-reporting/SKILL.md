---
name: financial-reporting
description: Generate financial analysis reports for delivered + remitted orders using the financial_report tool, with breakdowns by merchant, country, and city.
triggers: report, financial, revenue, analysis, breakdown, profit, excel, export, orders, merchant, sales, remitted, delivered
---

# Financial Reporting Skill

Use this skill when the user asks for financial summaries, revenue breakdowns, merchant performance, geographic analysis, or any report over delivered + remitted orders.

## What the Tool Does

`financial_report` queries `sheet_orders` for rows with status `delivered`, `remitted`, or `remittted` (common typo included), applies optional filters, and returns:

- **`summary`** — top-line totals: `total_orders`, `total_revenue`, `average_order_value`, `merchant_count`, `top_merchant`, `top_merchant_revenue`
- **`by_merchant`** — array sorted by `total_revenue` desc, each with: `merchant`, `order_count`, `total_revenue`, `average_order_value`, `revenue_share_pct`
- **`by_country`** — array sorted by `total_revenue` desc, each with: `country`, `order_count`, `total_revenue`
- **`by_city`** — top 10 cities sorted by `total_revenue` desc, each with: `city`, `order_count`, `total_revenue`
- **`filters`** — the filters that were actually applied (empty if none)
- **`empty`** — `true` if no orders matched

**Available filters (all optional):**
| Parameter | Type | Notes |
|-----------|------|-------|
| `merchant` | string | Partial match — "pizza" matches "Pizza Palace" |
| `start_date` | string | ISO format `YYYY-MM-DD`, filters on `order_date` |
| `end_date` | string | ISO format `YYYY-MM-DD`, must be ≥ `start_date` |
| `country` | string | Partial match |
| `city` | string | Partial match |
| `agent` | string | Optional override filter by agent name |

## Workflow

### 1. Clarify Before Calling (only if truly ambiguous)
If the user gives enough context, call immediately. If the request is vague about scope (e.g. "give me a report" with no time or merchant hint), call with no filters — the tool defaults to all delivered + remitted orders.

**Do not over-ask.** One clarification at most.

### 2. Call the Tool
Pass only the filters the user specified. Omit everything else.

```
financial_report(
  merchant:   "...",   // only if specified
  start_date: "...",   // only if specified
  end_date:   "...",   // only if specified
  country:    "...",   // only if specified
  city:       "...",   // only if specified
  agent:      "..."    // only if specified
)
```

### 2B. Use Workflow Tasks For Operational Runs
If the user asks for a **step-by-step operational process** (check scheduled+coded orders, then confirm delivery, then confirm remitted, then download reports), use `create_report_task` instead of `financial_report`.

Call pattern:

```txt
create_report_task(
  merchants: [
    { merchant: "Merchant A", start_date: "YYYY-MM-DD", end_date: "YYYY-MM-DD" },
    { merchant: "Merchant B", start_date: "YYYY-MM-DD", end_date: "YYYY-MM-DD" }
  ]
)
```

Task behavior:
1. Finds orders with `status=scheduled` and non-empty `code`
2. Waits for user confirmation (button) to mark status as `Delivered`
3. Waits for user confirmation (button) to mark `agent=remitted`
4. Produces per-merchant report download links

### 3. Handle the Response

#### If `empty: true`
- Tell the user clearly: no delivered/remitted orders matched their filters
- Echo back which filters were applied
- Suggest relaxing filters (widen date range, remove city, check spelling)
- Do not speculate or fabricate numbers

#### If `empty: false`
Lead with the `summary`, then drill into `by_merchant`, then geographic breakdown.

**Structure your response as:**
1. **Top-line summary** — total orders, total revenue, AOV, number of merchants
2. **Top merchant callout** — name, revenue, share of total
3. **Merchant breakdown table** — when there are 3+ merchants
4. **Geographic insights** — notable country or city concentrations
5. **Patterns / recommendations** — brief, decision-oriented observations

#### If an error is returned
- Report the error message plainly
- Suggest checking filter values (date format `YYYY-MM-DD`, merchant name spelling)

## Presentation Guidelines

- **Currency:** round to 2 decimal places; use values exactly as returned by the tool
- **Percentages:** use `revenue_share_pct` from the tool — do not recalculate
- **Tables:** use for merchant breakdown when there are 3+ merchants; columns: Merchant | Orders | Revenue | AOV | Share %
- **Commentary:** brief and decision-oriented (e.g. "Merchant X accounts for 60% of revenue")
- **Never** recalculate, estimate, or override values from the tool output
- **Never** fabricate data when the tool returns empty

## Tool Output Shape (reference)

```json
{
  "type": "financial_report",
  "empty": false,
  "filters": { "merchant": "Pizza", "start_date": "2024-01-01" },
  "summary": {
    "total_orders": 142,
    "total_revenue": 28450.00,
    "average_order_value": 200.35,
    "merchant_count": 4,
    "top_merchant": "Pizza Palace",
    "top_merchant_revenue": 15200.00
  },
  "by_merchant": [
    {
      "merchant": "Pizza Palace",
      "order_count": 76,
      "total_revenue": 15200.00,
      "average_order_value": 200.00,
      "revenue_share_pct": 53.4
    }
  ],
  "by_country": [
    { "country": "Kenya", "order_count": 98, "total_revenue": 19600.00 }
  ],
  "by_city": [
    { "city": "Nairobi", "order_count": 60, "total_revenue": 12000.00 }
  ]
}
```

## Example Interpretations

| User says | What to do |
|-----------|------------|
| "Show me a financial report" | Call with no filters |
| "Revenue for last month" | Derive `start_date`/`end_date` from today's date and call |
| "How is Pizza Palace doing?" | Call with `merchant="Pizza Palace"` |
| "Revenue by city in Kenya" | Call with `country="Kenya"` |
| "Report from Jan to March 2024" | Call with `start_date="2024-01-01"`, `end_date="2024-03-31"` |
| "Top merchants this year" | Call with `start_date="<year start>"` |
| "No orders found — what now?" | Tell user, echo filters, suggest relaxing them |

## Rules
- Base all conclusions strictly on tool output — no estimates or guesses
- Do not recalculate totals when the tool provides them
- If the user asks for a metric the tool doesn't return (e.g. profit margin), say so clearly rather than approximating
- Always surface `filters` in your response so the user knows what scope was applied
