---
name: user-report
description: Generates downloadable Excel reports for users based on filter criteria. Supports single or multiple users.
triggers: user-report, user report, user_report
---

# UserReportTool Skill

Use this skill when the task maps to the `user_report` tool.

## Workflow
- Confirm the target entity and intent before calling the tool.
- Use only required parameters plus minimal optional filters.
- After tool response, verify whether the request actually succeeded before replying.

## Output
- Return exact values from the tool response.
- Include applied filters/identifiers and any constraints used.