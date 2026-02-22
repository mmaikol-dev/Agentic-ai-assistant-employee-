---
name: get-sheet-record
description: Use when handling sheets data with GetSheetRecordTool.
triggers: get-sheet-record, get sheet record, get_sheet_record, sheets
---

# GetSheetRecordTool Skill

Use this skill when the user asks for data or operations related to the `sheets` table.

## Workflow
- Call `get_sheet_record` with focused arguments first.
- If results are empty, return that clearly and suggest narrower/broader filters.
- Ground all answers in tool output; do not fabricate counts.

## Output style
- Keep the response short.
- Show exact totals when available.
- Mention filters used when summarizing counts.