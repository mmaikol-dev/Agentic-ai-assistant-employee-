---
name: get-budget-transaction-record
description: Use when handling budget_transactions data with GetBudgetTransactionRecordTool.
triggers: get-budget-transaction-record, get budget transaction record, get_budget_transaction_record, budget_transactions
---

# GetBudgetTransactionRecordTool Skill

Use this skill when the user asks for data or operations related to the `budget_transactions` table.

## Workflow
- Call `get_budget_transaction_record` with focused arguments first.
- If results are empty, return that clearly and suggest narrower/broader filters.
- Ground all answers in tool output; do not fabricate counts.

## Output style
- Keep the response short.
- Show exact totals when available.
- Mention filters used when summarizing counts.