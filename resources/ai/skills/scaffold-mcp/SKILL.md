---
name: scaffold-mcp
description: Use when tasks require scaffold_mcp.
triggers: scaffold-mcp, scaffold mcp, scaffold_mcp
---

# ScaffoldMcpTool Skill

Use this skill when the task maps to the `scaffold_mcp` tool.

## Workflow
- Prefer one focused tool call over repeated broad calls.
- Use only parameters required to satisfy the request.
- Do not claim success unless tool output confirms success.

## Output
- Return exact values from tool results.
- Include any constraints or filters used.