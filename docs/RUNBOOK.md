# RoxiFile Production Runbook

Operational guide for running RoxiFile in production. For architecture, read
`README.md`; for the source specification, read
`docs/Production Specification — RoxiTel Telegram Virtual File Manager.md`.

---

## 1. Processes that must run

RoxiFile uses the following supervised processes:

| Process         | Command                                        | Restart policy             | Notes                                                                                                                                            |
| --------------- | ---------------------------------------------- | -------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------ |
| Web/Octane      | `php artisan octane:start --server=frankenphp` | `always`                   | Never serve without opcache.                                                                                                                     |
| Default queue   | `php artisan queue:work --queue=default`       | `always`                   | Non-Telegram application jobs. Never consume the telegram queue here.                                                                            |
| Reverb          | `php artisan reverb:start`                     | `always`                   | WebSocket server for realtime events.                                                                                                            |
| Scheduler       | `php artisan schedule:work`                    | `always`                   | Runs cleanup, reconciliation, verification sweeps.                                                                                               |
| Telegram worker | `php artisan telegram:worker`                  | `always` (restart on exit) | Long-running MadelineProto runtime + `telegram` queue consumer. Single instance — a Redis runtime lock makes a second instance exit immediately. |

The Telegram worker must run on **exactly one machine** (per lock name
`TELEGRAM_WORKER_LOCK`). Web and Telegram must share the same private local storage on this single-server deployment. Multi-server file transfer requires shared storage and is not implemented.

### Worker supervision expectations

- The worker writes a heartbeat every ~15s to `telegram_worker_status`
  (singleton row id = 1). A heartbeat older than 45 seconds ⇒ the UI health strip shows the
  worker offline.
- If the worker exits, the supervisor restarts it. On boot it re-acquires the
  lock, re-registers the status row, and reconnects Roxi's session. Authorization readiness is reported after the runtime has actually started; accepting a launch request does not imply readiness.
- The worker logs errors via the application log channel and stamps
  `last_error` / `last_error_at` on the status row.

---

## 2. Roxi first-time login (one time, ever)

Roxi is the single shared MTProto identity all users store through (spec Rule 2 —
one Roxi session total).

1. Configure `TELEGRAM_APP_ID`, `TELEGRAM_API_HASH`, `TELEGRAM_ROXI_USERNAME`
   (and the `TELEGRAM_DB_*` block for the Madeline state database).
2. As a super admin, open **Settings → Telegram → Roxi Telegram account** and
   complete the sign-in (phone, code, and optional 2FA password). Confirm your
   RoxiFile account with its password or passkey when prompted. The login uses
   the same session store as the worker and CLI. Stop the worker first if the
   settings page reports that the session is busy.
   Alternatively, run `php artisan telegram:roxi:login` interactively on the
   server.
3. The session persists in the dedicated Madeline state store. Never delete it
   without planning a new login window: MTProto mode requires that session before startup. Bot-only mode is available when MTProto credentials are not configured.
4. Start `php artisan telegram:worker`. The health strip should show
   "Roxi connected".

The settings flow is restricted to super admins, requires recent RoxiFile
password/passkey confirmation, and serializes access with the worker's runtime
lock. Login codes and Telegram 2FA passwords are sent only for the active
request; they are not stored in RoxiFile's application database or logs. The
CLI command remains available for server-side recovery.

---

## 3. Session revoked / unauthorized alerts

Symptoms: `unauthorized`, `auth_key_unregistered`, `session_expired` errors on
the health strip or in `telegram_connection_attempts`.

1. Check `telegram_worker_status.last_error` and the worker log.
2. If Telegram revoked the Roxi session:
    1. Stop the worker (`supervisorctl stop telegram-worker`).
    2. Re-run the Roxi login (see §2).
    3. Restart the worker and confirm "Roxi connected".
3. If a **bot token** was revoked, update it from the Telegram Connections page
   (connection menu → update token). Tokens are stored encrypted; they are
   never displayed again.

---

## 4. Reconciliation triage (spec §43)

The scheduler runs two sweeps:

- `telegram:reconcile` — daily 03:30. Samples up to 100 uploaded files (oldest
  `last_reconciled_at` first, with an ID tie-breaker), asks the storage driver whether the Telegram message
  still exists, and flags mismatches in `files.metadata.reconciliation`
  (`{flagged_at, kind: message_missing}`). **It never deletes anything.**
- `telegram:verify-stale` — every 6h. Queues re-verification for connections
  with `last_verified_at` older than 24h (or never verified).

Triage for flagged files:

1. Find flagged files: the Files page shows an amber warning triangle and the
   health strip shows the count. Query:
   `SELECT id, user_id, name, metadata FROM files WHERE metadata->'reconciliation' IS NOT NULL;`
2. Confirm the message is really gone (check the Telegram chat).
3. If gone: delete the file row from the Files page (trash → purge). The
   Telegram delete of an already-missing message is a harmless no-op.
4. If present: clear the flag by re-running the reconcile sweep — an
   `exists=true` observation clears `metadata.reconciliation`.

Temporary Telegram faults never flag files. Unknown observations rotate the sample without clearing existing evidence. Bot API message existence is unknown; no fictional getMessages request is made.

---

## 5. Temporary files, Trash and operations

- Temporary files are private under `storage/app/private/telegram-upload` and
  `telegram-download`, shared by the HTTP process and Telegram worker.
- Ordinary Trash never deletes Telegram content. Active upload/copy transfers
  must finish or be cancelled first. Restoring keeps the real storage state.
  Folder deletion uses a batch ID; restoring a folder does not revive children
  independently trashed earlier. File names may repeat, as in the existing VFS;
  folder names remain unique among live siblings.
- Purge returns 202 while queued and 200 only when complete. Remote identifiers
  survive failures. Review/retry failures in Recent transfers; a failed physical
  deletion does not prove the content was removed.
- Upload source copies are removed after committed completion. Failed uploads
  retain their source for one hour for retry. An expired source requires a new
  browser upload, unless a known remote result can already be completed.
- Download preparation is a background job. Authenticated GET then serves the
  prepared file, with ownership checked for every Range request. A ready copy
  expires after 15 idle minutes; successful reads renew that window. Cleanup
  runs every five minutes, so expired bytes may remain until the next sweep.
  An HTTP transfer must finish within that inactivity window. Lost browser
  connections leave the copy available for resumption until expiration.
- HTML/SVG remain attachments. Raster images, PDF, small plain text (1 MiB),
  and supported audio/video use the same private prepared copy. Playback uses
  BinaryFileResponse Range support, not Telegram-to-browser diskless streaming.
- Recovery runs every minute for abandoned operations older than two minutes.
  Live leases and backoff timestamps are respected. A recorded upload result
  is reused; an interrupted send without a known result becomes `needs_review`
  and cannot be blindly retried or cancelled. Operators must inspect the chat
  and reconcile the outcome before releasing this retained record/source.
- Operation IDs correlate browser history and safe operation logs. Worker
  errors include a separate safe reference. Never paste sessions/tokens or
  unsanitized exception dumps into tickets.

## 6. Spec §58 constraint audit

Final constraints, item by item:

| #   | Constraint                                            | Status       | Where                                                                                                                     |
| --- | ----------------------------------------------------- | ------------ | ------------------------------------------------------------------------------------------------------------------------- |
| 1   | Laravel 13                                            | ✅           | `composer.json` (`laravel/framework` 13.x)                                                                                |
| 2   | PHP 8.5                                               | ✅           | `composer.json`                                                                                                           |
| 3   | PostgreSQL for application data                       | ✅           | `config/database.php`                                                                                                     |
| 4   | MySQL for MadelineProto persistence where appropriate | ⚠️ Deviation | PostgreSQL `roxifile_madeline` is used instead (decision #1, see below).                                                  |
| 5   | Inertia.js                                            | ✅           | `resources/js/app.ts`, all controllers `Inertia::render`                                                                  |
| 6   | Vue 3                                                 | ✅           | `resources/js/pages/**`                                                                                                   |
| 7   | Laravel Vue Starter Kit                               | ✅           | Base scaffolding (auth, settings, teams)                                                                                  |
| 8   | shadcn-vue                                            | ✅           | `resources/js/components/ui/**`                                                                                           |
| 9   | Laravel Reverb                                        | ✅           | `config/reverb.php`, Echo configured in `app.ts`                                                                          |
| 10  | Multiple Telegram accounts                            | ✅           | `telegram_accounts` table + `TelegramConnectionsPageController` (accounts stay internal)                                  |
| 11  | Multiple connections per account                      | ✅           | `telegram_connections.telegram_account_id` (no unique constraint)                                                         |
| 12  | Every connection has a chat_id after connection       | ✅           | `VerifyTelegramConnection` / `ReconcileConnection` stamp `chat_id` from driver verification                               |
| 13  | Bot connection                                        | ✅           | `CreateBotConnection`, `BotTelegramStorageDriver`, `tests/Feature/Telegram/BotConnectionTest`                             |
| 14  | Roxi shared account connection                        | ✅           | `EnsureRoxiAccount`, `StartRoxiProvisioning`, `RoxiTelegramStorageDriver`                                                 |
| 15  | Personal account placeholder                          | ✅           | `PersonalTelegramStorageDriver` (throws `PersonalTelegramNotAvailable`)                                                   |
| 16  | One persistent Roxi MadelineProto session             | ✅           | `telegram:worker` + `WorkerLock` (`app/Domains/Telegram/Runtime/*`)                                                       |
| 17  | Long-running Telegram worker                          | ✅           | `app/Console/Commands/TelegramWorkerCommand.php`                                                                          |
| 18  | No MadelineProto init on HTTP requests                | ✅           | Controllers only talk to Actions; MTProto lives in the worker (`RoxiRuntime`, tests assert with fakes)                    |
| 19  | Virtual filesystem                                    | ✅           | `directories` + `files` tables, `DirectoryPathService`                                                                    |
| 20  | Virtual paths in PostgreSQL                           | ✅           | `directories.path` (materialized path), `DirectoryPathServiceTest`                                                        |
| 21  | File tags                                             | ✅           | `tags`/`file_tags`, `app/Domains/Files/Actions/*Tag*.php`                                                                 |
| 22  | File descriptions                                     | ✅           | `files.description`, `UpdateFileDescriptionController`                                                                    |
| 23  | MIME-based file type                                  | ✅           | `FileType::fromMime` (content-sniffed MIME, never extension)                                                              |
| 24  | File rename                                           | ✅           | `RenameFile` + endpoint                                                                                                   |
| 25  | Directory creation/rename/move/delete                 | ✅           | `CreateDirectory`…`PurgeDirectory` + endpoints, `DirectoryActionsTest`                                                    |
| 26  | File move/delete/restore                              | ✅           | `MoveFile`/`DeleteFile`/`RestoreFile`, `DownloadDeleteTest`                                                               |
| 27  | File-to-account mapping                               | ✅           | `files.telegram_account_id` (Rule 8)                                                                                      |
| 28  | File-to-connection mapping                            | ✅           | `files.telegram_connection_id` (Rule 8)                                                                                   |
| 29  | File-to-chat mapping                                  | ✅           | `files.telegram_chat_id`                                                                                                  |
| 30  | File-to-message mapping                               | ✅           | `files.telegram_message_id`                                                                                               |
| 31  | Queue-based Telegram operations                       | ✅           | `TelegramOperationJob` on the `telegram` queue consumed by the worker                                                     |
| 32  | Reverb-based operation status                         | ✅           | `App\Domains\Files\Events\*`, `useTelegramEvents.ts`, `RealtimeEventsTest`                                                |
| 33  | Retry/idempotency                                     | ✅           | `ErrorClassifier`, `TelegramOperationJob` backoff + completed-op gate (`UploadFlowTest`)                                  |
| 34  | Temporary file in Phase 1                             | ✅           | `TelegramTempStorage` (private local disk, ULID names)                                                                    |
| 35  | Immediate temp cleanup after successful upload        | ✅           | `TelegramOperationProcessor::upload` deletes temp on success, `UploadFlowTest`                                            |
| 36  | Future diskless streaming TODO                        | 📋 Deferred  | README "Future TODO"                                                                                                      |
| 37  | Secure session/token handling                         | ✅           | Bot tokens encrypted (`Crypt`), Roxi credentials env-only, nothing secret in API payloads (`BotConnectionTest`)           |
| 38  | Proper Laravel Policies                               | ✅           | `app/Domains/*/Policies`, ownership re-validated per request (§37)                                                        |
| 39  | Tests for all critical flows                          | ✅           | 200+ tests: upload/delete/download lifecycles, VFS, tags/search, policies, worker status, reconciliation, realtime events |

### Documented deviations

1. **Madeline state store**: PostgreSQL (`roxifile_madeline`) replaces MySQL.
   MadelineProto's state store is DB-agnostic; PostgreSQL was chosen to match
   the application stack. The application never queries those tables
   (`config/telegram.php` `database` block).
2. **Broadcast queue**: worker-emitted events ride the `telegram` broadcast
   queue (`FileOperationEvent::$broadcastQueue`) so the worker itself pushes to
   Reverb without cross-process Redis fan-out (spec doc §4.2).

---

## 7. Local development quick start

```bash
composer install && npm install
./vendor/bin/sail up -d           # or configure .env manually
php artisan migrate
npm run dev                       # Vite
php artisan reverb:start          # realtime
php artisan queue:work --queue=default # non-Telegram jobs
php artisan schedule:work         # scheduler
php artisan telegram:worker       # Telegram runtime (Bot-API-only mode without MTProto credentials)
```

Without `TELEGRAM_APP_ID` / `TELEGRAM_API_HASH` the worker runs in
Bot-API-only mode: bot connections work end-to-end, Roxi connections report
"worker runtime not available" until `telegram:roxi:login` is done.

Automated tests never touch Telegram: `php artisan test --compact`
(sqlite `:memory:`, `Queue::fake`, `FakeTelegramStorageDriver`,
`Http::fake()`).

## 8. Configuration and managed deployment

Required MTProto values are `TELEGRAM_APP_ID`, `TELEGRAM_API_HASH`,
`TELEGRAM_ROXI_USERNAME` (without @), and `TELEGRAM_DB_HOST`,
`TELEGRAM_DB_PORT`, `TELEGRAM_DB_DATABASE`, `TELEGRAM_DB_USERNAME`,
`TELEGRAM_DB_PASSWORD`. Provision the dedicated PostgreSQL database first;
MadelineProto creates its own tables. Use `CACHE_STORE=redis` and working
`REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD` settings for shared ownership.
The supported queue name is `telegram`; reserve it exclusively for this worker.
Both operation and Telegram broadcast jobs explicitly use the Redis connection.
Bot tokens belong to encrypted account credentials, not a global MTProto token.
Reverb requires its normal `REVERB_*` / `VITE_REVERB_*` settings when enabled.

`docs/telegram-worker.service` is a template, not an installed service. Review its
Unix user, PHP 8.5 binary and working directory for the host. Bootstrap login as
that same Unix user, with private filesystem permissions (`umask 077`), before
starting the service. Ensure `storage/app/private/madeline` exists and is
accessible only to the service user. Login codes and 2FA passwords stay in the
interactive terminal, never `.env` or chat.

For a planned rollout, apply application migrations before restarting the worker
and reloading Octane. The new connected-Invite unique index deliberately refuses
existing duplicates: inspect and resolve them before migration instead of deleting
records automatically. Stop the Telegram service gracefully for code updates;
its consumer stops accepting new work and drains for `TELEGRAM_DRAIN_SECONDS`
(default 60). The supervisor's final stop deadline handles hung work; recovery
retains/revisits abandoned operations. Restart/reload web and Telegram separately.
Restarting Octane never boots a Telegram session. No live migration, service
installation, restart or Telegram login is part of this implementation delivery.

Operational defaults, not commercial plan promises:

| Setting                              | Default     | Meaning                                                            |
| ------------------------------------ | ----------- | ------------------------------------------------------------------ |
| `TELEGRAM_MAX_UPLOAD_KIB`            | 204800      | 200 MiB for MTProto connections; PHP upload/POST limits exceed this |
| `TELEGRAM_MAX_USER_BYTES`            | 10737418240 | Stored and reserved file bytes, including Trash                    |
| `TELEGRAM_MAX_TEMP_USER_BYTES`       | 2147483648  | Temporary bytes reserved per user                                  |
| `TELEGRAM_MAX_ACTIVE_OPERATIONS`     | 10          | Active transfers, including outcomes needing review                |
| `TELEGRAM_MAX_CONCURRENT_OPERATIONS` | 4           | Worker dispatch ceiling                                            |

Raise the application upload limit only together with the web server/PHP upload
and POST limits. Public Bot API uploads are additionally capped at 20 MiB here to
preserve downloadability. Telegram documents a larger sendDocument limit, a
20 MB getFile limit, and deleteMessage age/permission restrictions; verification
cannot promise future deletion. See the [official Bot API](https://core.telegram.org/bots/api#deleteMessage).

## 9. Account deletion and retained cleanup failures

Account deletion immediately ends sessions and disables new requests. It records
`users.deletion_requested_at` durably and disables provisioning. The scheduler
runs `telegram:cleanup-accounts` every minute, removes active and trashed content
through the same durable purge workflow, and deletes the identity and exclusive
Bot credentials only after cleanup completes. Shared Roxi and other users' data
are preserved. A known sent-upload result is materialized before cleanup; an
unknown outcome keeps the account and evidence for operator review.

Use `php artisan telegram:cleanup-accounts` to inspect remaining account IDs and
continue cleanup. Fix the underlying authorization/service problem first, then
run `php artisan telegram:cleanup-accounts --retry-failed` for known failed
physical deletions. A previously cancelled purge is resumed by the irreversible
account-deletion request. Failures keep identifiers/credentials; there is no
automatic deadline that discards them and falsely reports successful deletion.

## 10. Offline checks and isolated concurrency tests

Normal PHPUnit uses SQLite and fake Telegram adapters/HTTP. It requires no real
Telegram credentials, OTP, or production data. Frontend event correlation uses
Node's built-in test runner (`npm run test:frontend`). CI installs locked
packages on PHP 8.5 / Node 24, builds, formats/checks, runs PHPStan, TypeScript and
PHPUnit, then runs process tests against disposable PostgreSQL 18 / Redis 7.

The process tests are opt-in locally:

```bash
ROXIFILE_PROCESS_TESTS=1 \
ROXIFILE_TEST_PG_PORT=55432 \
ROXIFILE_TEST_REDIS_PORT=56379 \
php artisan test --compact tests/Feature/Telegram/ProcessConcurrencyTest.php
```

They require a dedicated `roxifile_concurrency_test` database, create/drop only a
random schema, and use isolated Redis key prefixes. Override connection details
with `ROXIFILE_TEST_PG_{HOST,PORT,DATABASE,USERNAME,PASSWORD}` and
`ROXIFILE_TEST_REDIS_{HOST,PORT,PASSWORD}`. Never point them at application services.
They cover real queue redelivery, ownership fencing, bounded dispatch/drain,
parallel quota enforcement, concurrent Invite confirmation, yielding multipart
HTTP, and HTTP → separate worker → authenticated byte-for-byte download.

Real Telegram roundtrip/login/restart smoke remains opt-in with a disposable
account/session and small test file. It was not performed using production
credentials. Browser playback/visual interaction is not covered by an installed
browser-test dependency; TypeScript, event contract and HTTP/Range behavior are
checked without adding dependencies.
