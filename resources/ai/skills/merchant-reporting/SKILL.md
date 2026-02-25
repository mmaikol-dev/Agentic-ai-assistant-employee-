---
name: merchant-reporting
description: Generate merchant-focused performance reports using the merchant_report tool.
triggers: merchant report, merchant performance, top merchants, merchant ranking, merchant contribution, merchant analysis
---

# Merchant Reporting Skill

Use this skill when the request is specifically about merchant performance.

## Tool

Use `merchant_report` for:
- Merchant ranking by revenue
- Merchant revenue share analysis
- Merchant order and quantity performance
- Top products within the selected merchant scope

## Workflow

1. `status` is required for `merchant_report`. Ask one concise clarification question if status is missing.
2. Suggested status options: `delivered`, `scheduled`, `pending`, `cancelled`, `processing`, `shipped`.
3. Use `merchant_report` after status is provided.
4. Keep this separate from:
- `financial_report` (overall financial scope)
- `call_center_daily_report` / `call_center_monthly_report` (call-center scope)
5. Return exact tool output without recalculating totals.

## Common Inputs

- `status` (required, exact status value to filter by)
- `merchant` (optional partial match)
- `start_date`, `end_date` (`YYYY-MM-DD`)
- `country`, `city`, `agent`
- `limit` for merchant rows

## Output Expectations

Highlight:
- `summary`
- `by_merchant`
- `top_products`
- `status_breakdown` (group statuses and counts)
- `instructions_analysis` (instruction coverage + top repeated instructions)
- applied `filters`
