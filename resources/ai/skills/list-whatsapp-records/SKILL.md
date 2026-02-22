---
name: list-whatsapp-records
description: Use when handling whatsapp data with ListWhatsappRecordsTool.
triggers: list-whatsapp-records, list whatsapp records, list_whatsapp_records, whatsapp
---

# ListWhatsappRecordsTool Skill

Use this skill when the user asks for data or operations related to the `whatsapp` table.

## Workflow
- Call `list_whatsapp_records` with focused arguments first.
- If results are empty, return that clearly and suggest narrower/broader filters.
- Ground all answers in tool output; do not fabricate counts.

## Output style
- Keep the response short.
- Show exact totals when available.
- Mention filters used when summarizing counts.