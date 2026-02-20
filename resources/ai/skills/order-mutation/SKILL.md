---
name: order-mutation
description: Create and update orders safely using create_order and edit_order.
triggers: create order, update order, edit order, modify order, change status, amend
---
Use this skill when the user requests creating or updating orders.

Workflow:
1. For new records, call `create_order`.
2. For updates, call `edit_order` with `id` or `order_no`.
3. Only send fields explicitly requested by the user.
4. Confirm success using returned tool payload.

Rules:
- If required fields are missing, ask one concise clarification.
- Never claim success without a successful tool result.
- Preserve existing values for fields not requested to change.
