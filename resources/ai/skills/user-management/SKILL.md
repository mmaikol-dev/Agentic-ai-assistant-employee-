---
name: user-management
description: Create new users or edit existing users. For creating, provide user details without an id. For editing, provide the user id or email along with the fields to update.
triggers: user-management, user management, user_management
---

# UserManagementTool Skill

Use this skill when the task maps to the `user_management` tool.

## Workflow
- Prefer one focused tool call over repeated broad calls.
- Use only parameters required to satisfy the request.
- Do not claim success unless tool output confirms success.

## Output
- Return exact values from tool results.
- Include any constraints or filters used.