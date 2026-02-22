---
name: get-whatsapp-record
description: Use when handling whatsapp data with GetWhatsappRecordTool.
triggers: get-whatsapp-record, get whatsapp record, get_whatsapp_record, whatsapp
---

# GetWhatsappRecordTool Skill

Use this skill when the user asks for data or operations related to the `whatsapp` table.

## Workflow
- Call `get_whatsapp_record` with focused arguments first.
- If results are empty, return that clearly and suggest narrower/broader filters.
- Ground all answers in tool output; do not fabricate counts.

## Output style
- Keep the response short.
- Show exact totals when available.
- Mention filters used when summarizing counts.