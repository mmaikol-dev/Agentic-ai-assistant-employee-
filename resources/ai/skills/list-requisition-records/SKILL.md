---
name: list-requisition-records
description: Use when handling requisitions data with ListRequisitionRecordsTool.
triggers: list-requisition-records, list requisition records, list_requisition_records, requisitions
---

# ListRequisitionRecordsTool Skill

Use this skill when the user asks for data or operations related to the `requisitions` table.

## Workflow
- Call `list_requisition_records` with focused arguments first.
- If results are empty, return that clearly and suggest narrower/broader filters.
- Ground all answers in tool output; do not fabricate counts.

## Output style
- Keep the response short.
- Show exact totals when available.
- Mention filters used when summarizing counts.