---
name: list-category-records
description: Use when handling categories data with ListCategoryRecordsTool.
triggers: list-category-records, list category records, list_category_records, categories
---

# ListCategoryRecordsTool Skill

Use this skill when the user asks for data or operations related to the `categories` table.

## Workflow
- Call `list_category_records` with focused arguments first.
- If results are empty, return that clearly and suggest narrower/broader filters.
- Ground all answers in tool output; do not fabricate counts.

## Output style
- Keep the response short.
- Show exact totals when available.
- Mention filters used when summarizing counts.