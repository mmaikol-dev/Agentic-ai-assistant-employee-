---
name: list-sheet-records
description: Use when handling sheets data with ListSheetRecordsTool.
triggers: list-sheet-records, list sheet records, list_sheet_records, sheets
---

# ListSheetRecordsTool Skill

Use this skill when the user asks for data or operations related to the `sheets` table.

## Workflow
- Call `list_sheet_records` with focused arguments first.
- If results are empty, return that clearly and suggest narrower/broader filters.
- Ground all answers in tool output; do not fabricate counts.

## Output style
- Keep the response short.
- Show exact totals when available.
- Mention filters used when summarizing counts.