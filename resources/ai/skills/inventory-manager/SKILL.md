---
name: inventory-manager
description: Use when tasks require inventory_manager.
triggers: inventory-manager, inventory manager, inventory_manager
---

# InventoryManagerTool Skill

Use this skill when the task maps to the `inventory_manager` tool.

## Workflow
- Prefer one focused tool call over repeated broad calls.
- Use only parameters required to satisfy the request.
- Do not claim success unless tool output confirms success.

## Output
- Return exact values from tool results.
- Include any constraints or filters used.