---
name: update-user-record
description: Use when handling users data with UpdateUserRecordTool.
triggers: update-user-record, update user record, update_user_record, users
---

# UpdateUserRecordTool Skill

Use this skill when the user asks for data or operations related to the `users` table.

## Workflow
- Call `update_user_record` with focused arguments first.
- If results are empty, return that clearly and suggest narrower/broader filters.
- Ground all answers in tool output; do not fabricate counts.

## Output style
- Keep the response short.
- Show exact totals when available.
- Mention filters used when summarizing counts.