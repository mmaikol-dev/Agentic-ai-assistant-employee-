---
name: list-product-records
description: Use when handling products data with ListProductRecordsTool.
triggers: list-product-records, list product records, list_product_records, products
---

# ListProductRecordsTool Skill

Use this skill when the user asks for data or operations related to the `products` table.

## Workflow
- Call `list_product_records` with focused arguments first.
- If results are empty, return that clearly and suggest narrower/broader filters.
- Ground all answers in tool output; do not fabricate counts.

## Output style
- Keep the response short.
- Show exact totals when available.
- Mention filters used when summarizing counts.