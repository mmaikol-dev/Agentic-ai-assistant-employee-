---
name: get-requisition-record
description: Use when handling requisitions data with GetRequisitionRecordTool.
triggers: get-requisition-record, get requisition record, get_requisition_record, requisitions
---

# GetRequisitionRecordTool Skill

Use this skill when the user asks for data or operations related to the `requisitions` table.

## Workflow
- Call `get_requisition_record` with focused arguments first.
- If results are empty, return that clearly and suggest narrower/broader filters.
- Ground all answers in tool output; do not fabricate counts.

## Output style
- Keep the response short.
- Show exact totals when available.
- Mention filters used when summarizing counts.