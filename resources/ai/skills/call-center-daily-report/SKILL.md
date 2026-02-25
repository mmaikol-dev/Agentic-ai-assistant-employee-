---
name: call-center-daily-report
description: Generate a daily call center report showing orders with status 'scheduled' or 'delivered' that have a code value assigned. Lists order_no, client_name, status, code, city, and cc_email.
triggers: call-center-daily-report, call center daily report, call_center_daily_report, daily call center, call center today
---

# CallCenterDailyReportTool Skill

Use this skill when the task maps to the `call_center_daily_report` tool.

## Workflow
- Prefer one focused tool call over repeated broad calls.
- Use only parameters required to satisfy the request.
- Do not claim success unless tool output confirms success.

## Output
- Return exact values from tool results.
- Include any constraints or filters used.
