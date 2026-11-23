---
paths:
    - 'app/**'
---

# App

## DDD structure: Domains with Controller -> Action + DTO pattern

Code lives in app/Domains/{Identity,Files,Telegram}/{Models,Enums,Actions,Data,Policies,Http/Controllers,Http/Requests,Concerns,Notifications,Rules,Services}. Rules: (1) Controllers are thin — auth, form-request validation, flash/redirect only; every state change goes through a domain Action class (Domains/X/Actions/*) taking readonly DTOs from Domains/X/Data via request->toDto(). (2) Cross-domain calls must go action-to-action, never another domain's models directly in controllers. (3) Models register their factory with #[UseFactory(Database\Factories\XFactory::class)]. (4) Policies for domain models live in the same domain's Policies dir; Fortify auth responses live in Identity/Http/Responses.

## Connection encryption is a client boundary with permanent reservation locks

For contents-encrypted connections, server transfers and checksums operate on ROXIF001 ciphertext only; never decrypt or produce server previews. Setup and upload lock the user then connection, and encryption_locked_at survives cancellation and deletion. Keep owner vault envelopes available for downloads even when a connection is disabled. Encryption setup stays opt-in until the recovery foundation is complete (#31/#32/#33).
