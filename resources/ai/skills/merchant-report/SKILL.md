---
name: merchant-report
description: Use when tasks require merchant_report.
triggers: merchant-report, merchant report, merchant_report
---

# MerchantReportTool Skill

Use this skill when the task maps to the `merchant_report` tool.

## Workflow
- `status` is required for `merchant_report`; ask one concise clarification question if missing.
- Suggested status options: `delivered`, `scheduled`, `pending`, `cancelled`, `processing`, `shipped`.
- Prefer one focused tool call over repeated broad calls.
- Use only parameters required to satisfy the request.
- Do not claim success unless tool output confirms success.

## Output
- Return exact values from tool results.
- Include any constraints or filters used.
