---
name: get-product-record
description: Use when handling products data with GetProductRecordTool.
triggers: get-product-record, get product record, get_product_record, products
---

# GetProductRecordTool Skill

Use this skill when the user asks for data or operations related to the `products` table.

## Workflow
- Call `get_product_record` with focused arguments first.
- If results are empty, return that clearly and suggest narrower/broader filters.
- Ground all answers in tool output; do not fabricate counts.

## Output style
- Keep the response short.
- Show exact totals when available.
- Mention filters used when summarizing counts.