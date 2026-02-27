---
name: create-requisition-record
description: Use when handling requisitions data with CreateRequisitionRecordTool.
triggers: create-requisition-record, create requisition record, create_requisition_record, requisitions
---

# CreateRequisitionRecordTool Skill

Use this skill when the user asks for data or operations related to the `requisitions` table.

## Workflow
- Call `create_requisition_record` with focused arguments first.
- If results are empty, return that clearly and suggest narrower/broader filters.
- Ground all answers in tool output; do not fabricate counts.

## Output style
- Keep the response short.
- Show exact totals when available.
- Mention filters used when summarizing counts.