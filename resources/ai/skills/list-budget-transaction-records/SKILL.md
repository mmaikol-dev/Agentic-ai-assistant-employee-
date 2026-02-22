---
name: list-budget-transaction-records
description: Use when handling budget_transactions data with ListBudgetTransactionRecordsTool.
triggers: list-budget-transaction-records, list budget transaction records, list_budget_transaction_records, budget_transactions
---

# ListBudgetTransactionRecordsTool Skill

Use this skill when the user asks for data or operations related to the `budget_transactions` table.

## Workflow
- Call `list_budget_transaction_records` with focused arguments first.
- If results are empty, return that clearly and suggest narrower/broader filters.
- Ground all answers in tool output; do not fabricate counts.

## Output style
- Keep the response short.
- Show exact totals when available.
- Mention filters used when summarizing counts.