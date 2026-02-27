---
name: get-category-record
description: Use when handling categories data with GetCategoryRecordTool.
triggers: get-category-record, get category record, get_category_record, categories
---

# GetCategoryRecordTool Skill

Use this skill when the user asks for data or operations related to the `categories` table.

## Workflow
- Call `get_category_record` with focused arguments first.
- If results are empty, return that clearly and suggest narrower/broader filters.
- Ground all answers in tool output; do not fabricate counts.

## Output style
- Keep the response short.
- Show exact totals when available.
- Mention filters used when summarizing counts.