---
name: list-user-records
description: Use when handling users data with ListUserRecordsTool.
triggers: list-user-records, list user records, list_user_records, users
---

# ListUserRecordsTool Skill

Use this skill when the user asks for data or operations related to the `users` table.

## Workflow
- Call `list_user_records` with focused arguments first.
- If results are empty, return that clearly and suggest narrower/broader filters.
- Ground all answers in tool output; do not fabricate counts.

## Output style
- Keep the response short.
- Show exact totals when available.
- Mention filters used when summarizing counts.