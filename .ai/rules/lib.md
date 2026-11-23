---
paths:
    - resources/js/lib/contentsEncryption.ts
---

# Lib

## Recovery export replaces the device-only recovery secret

A password cannot recover the existing random recovery secret from its key wrap. Export a replacement recovery kit locally, require the owner to save it before activation, and update only the recovery wrap using the previous envelope as an atomic stale-write guard. Password changes preserve the recovery wrap and file key. Retained old envelopes, credentials and ciphertext cannot be remotely revoked; never promise otherwise.
