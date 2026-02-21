---
name: task-creation
description: Create background and scheduled tasks safely using create_task with complete, valid payloads.
triggers: task, create task, schedule, reminder, remind, recurring, one time, cron, monitor, alert, background job, run later
---

# Task Creation Skill

Use this skill when the user asks to schedule something, run work later, monitor conditions, set reminders, or automate repeat actions.

## Primary Tool

- Use `create_task` for task creation.
- Do not invent task IDs, statuses, links, or execution results.
- Confirm using only the tool response.

## Required Payload

Always send:
- `title`
- `schedule_type` (`immediate` | `one_time` | `recurring` | `event_triggered`)

Include when provided or inferred safely:
- `description`
- `timezone` (IANA, e.g. `Africa/Nairobi`)
- `priority` (`low` | `normal` | `high`)
- `execution_plan` (ordered steps with tool + tool_input when user asked for automation steps)
- `expected_output`
- `original_user_request`

## Schedule Rules

### immediate
- Do not send `run_at` or `cron_expression`.

### one_time
- Must include `run_at` in ISO-8601.
- `run_at` must be in the future.
- If date/time is ambiguous or missing, ask one concise clarification question.
- Use the current server time injected by system prompt to resolve relative time phrases.
- If the user says a time for "today" and that time is already past, schedule the next valid occurrence (usually tomorrow at that time) and state that assumption briefly.
- Never ask the user for the "current date" when system time is already provided.

### recurring
- Must include `cron_expression`.
- Include `cron_human` when you can state schedule naturally.
- If user gives natural language ("every weekday at 9"), convert to cron.

### event_triggered
- Must include `event_condition`.
- Keep condition explicit and testable.

## Clarification Policy

- Ask at most one concise clarification when required fields are missing.
- Otherwise call `create_task` directly.
- If the user asks for invalid past time, propose the nearest valid future time and ask to confirm.
- When the user gives only a time (for example "send at 12:10am"), treat it as the next occurrence in the user's intended timezone.

## Output Style

After tool success:
- Confirm task was created.
- Show `title`, `schedule_type`, and returned `task_url` when present.

After tool failure:
- Show exact failure reason.
- Suggest one corrective next step.

## Guardrails

- Never claim a task ran unless the system reports it.
- Never fabricate workflow completion, report tables, or delivery confirmations.
- Keep payload fields aligned to user intent; do not add unrelated actions.
