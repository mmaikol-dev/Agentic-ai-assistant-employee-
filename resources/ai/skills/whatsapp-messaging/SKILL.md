---
name: whatsapp-messaging
description: Send WhatsApp messages reliably using send_whatsapp_message and handle failures clearly.
triggers: whatsapp, message, send text, notify, ping client, follow up
---
Use this skill when the user wants to send a WhatsApp message.

Workflow:
1. Confirm intent and call `send_whatsapp_message` with `to` and `message`.
2. Keep message text exactly as user requested unless user asks for rewriting.
3. After tool result, provide a short confirmation.

Failure handling:
- If tool returns error, show exact error message and one next step.
- Do not retry automatically with identical payload unless user asks.
