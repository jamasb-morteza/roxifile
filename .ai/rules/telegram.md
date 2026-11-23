---
paths:
    - 'resources/js/pages/telegram/**'
---

# Telegram

## Connections-only UX: no accounts section, 3-step Add Connection wizard

User decision (2026-09-30): the Telegram page shows connections only — no accounts cards, no per-method entry buttons. One "Add Connection" wizard: name → method (RoxiFile Bot / RoxiFile MTProto Invite) → provisioning step. Page/route is telegram/connections (telegram.connections, component telegram/Connections). TelegramAccount stays a backend concept (Roxi system account, worker/runtime); it is never listed in the UI. Method labels: bot=RoxiFile Bot, invite=RoxiFile MTProto Invite, roxi=RoxiFile MTProto. POST telegram/connections/roxi stays API-only for pending legacy connections.
