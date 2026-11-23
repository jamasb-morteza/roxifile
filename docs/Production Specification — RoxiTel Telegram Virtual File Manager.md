You are a senior Laravel 13 / PHP 8.5 architect, backend engineer, frontend engineer, and systems designer.

Build a production-ready Telegram-backed Virtual File Manager called **Roxivia File Manager**.

The application uses Telegram as the physical file storage backend while PostgreSQL stores the application's virtual filesystem, metadata, ownership, permissions, and relationships.

A critical architectural requirement is that Telegram/MadelineProto operations run in a **long-running process** and are not initialized/restored for ordinary HTTP requests. The restricted super-admin Roxi account sign-in is a documented exception: its multi-step HTTP flow uses the shared session only while holding the worker runtime lock and after recent RoxiFile password/passkey confirmation. All storage operations remain in the long-running worker.

The implementation must be incremental, production-oriented, testable, secure, and maintainable.

---

# 1. Technology Stack

Use:

Backend:

- Laravel 13
- PHP 8.5
- PostgreSQL for the main application database
- MySQL for MadelineProto-related persistence/state where technically appropriate
- MadelineProto for MTProto Telegram communication
- Telegram Bot API for Bot connections
- Laravel Queue
- Redis where appropriate for queue/IPC/state
- Laravel Reverb
- Laravel Events / Broadcasting

Frontend:

- Inertia.js
- Vue 3
- Existing Laravel Vue Starter Kit is already installed
- Use the existing starter kit as the foundation
- Prefer **shadcn-vue** for UI components and design primitives
- Laravel Echo for realtime functionality

Do NOT replace the existing Laravel Vue Starter Kit.

Do NOT introduce another frontend framework.

Prefer shadcn-vue components instead of creating custom equivalents.

---

# 2. Product Concept

Roxivia is a virtual file manager.

Users should experience it like a normal cloud drive:

/
├── Documents/
│ ├── Contracts/
│ ├── Invoices/
│ └── Reports/
├── Images/
│ ├── Logos/
│ └── Photos/
├── Videos/
└── Archives/

The directory structure is entirely virtual.

Telegram stores the actual physical file data.

PostgreSQL stores:

- directories
- virtual paths
- filenames
- MIME types
- normalized file types
- tags
- descriptions
- ownership
- file state
- Telegram account relationship
- Telegram connection relationship
- Telegram chat ID
- Telegram message ID
- Telegram media/file identifiers

Telegram must NOT become the application's filesystem structure.

---

# 3. Core Architecture

The application must be separated into:

Application layer:

Laravel
↓
File Manager
↓
Storage Manager

Infrastructure layer:

Storage Manager
↓
Telegram Connection
↓
Telegram Driver
├── Bot
├── Roxi
└── Personal MTProto (future)
↓
Telegram Runtime
↓
Telegram

Controllers must NOT directly instantiate MadelineProto.

Controllers must NOT directly perform Telegram operations.

The file manager must not care whether the file is stored using Bot API or MTProto.

---

# 4. Telegram Account Model

A Laravel user can have multiple Telegram Accounts.

A Telegram Account represents an identity/configuration used to access Telegram.

Examples:

User #15
├── Account #1: Roxi
├── Account #2: My Bot
└── Account #3: Personal Telegram (future)

A Telegram Account can have multiple Connections.

This distinction is critical.

Do NOT store chat_id directly as the only property of TelegramAccount.

---

# 5. Telegram Connection Model

A Connection represents a specific Telegram chat that is used as a physical storage location.

Every Connection must contain at least:

- id
- user_id
- telegram_account_id
- method
- name
- chat_id
- chat_type
- chat_title
- chat_username where available
- status
- last_verified_at
- connection metadata
- created_at
- updated_at
- deleted_at

Possible methods:

- bot
- roxi
- personal

Every physical file MUST reference one Telegram Connection.

Example:

File #500

telegram_account_id = 10
telegram_connection_id = 45
telegram_chat_id = -100123456789
telegram_message_id = 93821

---

# 6. Connection Methods

When a user creates a Telegram Account/Connection, allow them to choose one of these methods.

## 6.1 Bot

Label:

Telegram Bot

Description:

Use a Telegram Bot to store and manage files in a Telegram chat/group/channel.

The UI must explain:

- Requires a Telegram Bot
- Bot must be added to the target chat
- Required permissions must be available
- Uses Telegram Bot API
- Suitable for users who want to manage their own bot

Implementation:

BotTelegramConnectionDriver
or an appropriate equivalent.

Validate the bot token securely.

Never expose bot tokens to the frontend after initial setup.

---

# 7. Roxi Shared Account

Label:

Roxi Shared Account

Badge:

Recommended

Roxi is a system-owned central Telegram user account managed by the application.

The normal user must NOT need to login to Telegram.

The user's files will be stored using Roxi's long-running MTProto session.

Primary goal:

Avoid creating a MadelineProto session for every application user.

Instead:

One Roxi Telegram Account
↓
One persistent MadelineProto session
↓
Long-running Telegram Worker
↓
Many user-specific Telegram Connections

Example:

Roxi
├── User A → Chat A
├── User B → Chat B
├── User C → Chat C
└── User D → Chat D

Each user's Connection has its own chat_id.

---

# 8. Roxi Connection Provisioning

When the user chooses:

Roxi Shared Account

the application should automatically provision a Telegram storage chat/connection between the user and Roxi wherever Telegram's actual API capabilities permit.

The desired UX:

User
↓
Select "Roxi Shared Account"
↓
System provisions storage connection
↓
Verify chat
↓
Get chat_id
↓
Create TelegramConnection
↓
Connected

The application must NOT blindly assume Telegram allows arbitrary private-chat creation.

Before implementation, verify Telegram's actual API limitations.

If Telegram cannot directly initiate the desired private conversation, implement a secure user-initiated fallback, such as:

- deep link
- start parameter
- user-initiated contact
- group/channel authorization flow

Do not implement unsupported Telegram behavior.

The important final result is:

User
↓
Roxi Account
↓
TelegramConnection
↓
chat_id

---

# 9. Personal Telegram Account

Label:

Personal Telegram Account

Status:

Coming Soon

This is a placeholder only in the first version.

Do NOT implement full Personal Telegram authentication yet.

However, the architecture must reserve support for:

Phone number
↓
Telegram verification code
↓
2FA password if required
↓
MadelineProto session
↓
Personal Telegram Account

The future feature must not require major database or architecture changes.

---

# 10. Long-Running MadelineProto Runtime

This is one of the most important requirements of the project.

MadelineProto must NOT be initialized/restored on ordinary HTTP requests. The
restricted super-admin Roxi login flow is the only HTTP exception; it requires
recent RoxiFile password/passkey confirmation and the same distributed runtime
lock as the worker. Normal Telegram operations stay in the long-running runtime.

Do not use this request-per-session pattern for normal Telegram operations:

HTTP request
↓
new API(...)
↓
load session
↓
Telegram call
↓
end request

Normal Telegram operations use the dedicated long-running Telegram runtime.
The restricted super-admin login flow above is the only HTTP exception.

For example:

php artisan telegram:worker

or an equivalent long-running Artisan command.

The worker must:

- initialize MadelineProto once
- load the Roxi session once
- keep the session alive
- maintain connection state
- process Telegram updates
- process Telegram operations
- reconnect when required
- handle errors
- handle revoked/invalid sessions
- expose health/status
- gracefully handle shutdown/restart

---

# 11. Telegram Runtime Communication

Separate control plane and data/operation processing.

Preferred architecture:

                         Laravel / Octane
                               │
                    ┌──────────┼──────────┐
                    │          │          │
                  HTTP       PostgreSQL  Reverb
                    │
                    ▼
              Telegram Command
                    │
                  Redis
                    │
                    ▼
           Long-running Telegram Worker
                    │
                    ▼
               MadelineProto
                    │
                    ▼
                 Telegram

HTTP requests must not initialize MadelineProto.

The worker should consume operations through a safe queue/IPC mechanism.

Evaluate Laravel Queue + Redis and/or a dedicated internal IPC mechanism.

Choose the simplest architecture that guarantees:

- no repeated session restoration
- safe concurrency
- retries
- operation tracking
- worker resilience

---

# 12. Single Roxi Runtime

Roxi should normally have exactly one active long-running MadelineProto runtime.

Do not create one Roxi session per user.

Do not create one Roxi instance per HTTP worker.

Do not create one Roxi instance per queue job.

Recommended architecture:

Roxi
↓
ONE MadelineProto session
↓
ONE long-running Telegram runtime
↓
Many Telegram Connections

FrankenPHP/Octane worker count must NOT change the number of Roxi sessions.

---

# 13. Session Persistence

The Roxi MadelineProto session must survive process/application restarts.

Evaluate supported persistence options:

- File
- MySQL
- Redis
- PostgreSQL
- other MadelineProto-supported persistence mechanisms

The first implementation should prioritize:

- reliability
- low complexity
- session persistence
- long-running runtime

The persistence backend must NOT be selected solely because of theoretical restoration speed.

The main performance optimization is:

LOAD SESSION ONCE +
KEEP RUNTIME ALIVE

Do not repeatedly restore the session.

Use MySQL for MadelineProto-specific state/persistence where appropriate.

Do not put normal application data into the MadelineProto database unnecessarily.

---

# 14. PostgreSQL Application Database

PostgreSQL is the source of truth for application data.

Use PostgreSQL for:

- users
- telegram_accounts metadata
- telegram_connections
- directories
- files
- tags
- file_tags
- file_operations
- connection attempts
- application notifications
- metadata
- application settings
- reconciliation state

---

# 15. MySQL MadelineProto Database

MySQL is dedicated to MadelineProto-related persistent state where technically appropriate.

Keep it logically separate from PostgreSQL.

Document which data belongs to:

PostgreSQL:
application data

MySQL:
MadelineProto persistence/state

Do not blindly mirror the same data in both databases.

If MadelineProto has a more appropriate supported persistence mechanism for a specific piece of state, follow MadelineProto's recommended mechanism.

---

# 16. Virtual Filesystem

Directories are virtual.

Example:

/Documents/Invoices/2026/invoice.pdf

This path does not correspond to any Telegram filesystem.

Database:

Directory
id
user_id
parent_id
name

File
directory_id
name

The full path is derived from the directory hierarchy.

Do not use Telegram message IDs as filesystem paths.

---

# 17. Files

Each File should contain at least:

id
user_id
directory_id

telegram_account_id
telegram_connection_id
telegram_chat_id
telegram_message_id
telegram_media_id
telegram_file_id where applicable

name
original_name
extension
mime_type
file_type
size
checksum where practical
description
status

created_at
updated_at
deleted_at

The physical Telegram location must be explicitly stored.

For every file the application must be able to answer:

Which Telegram account?

Which connection?

Which Telegram chat?

Which Telegram message?

Which protocol/method?

---

# 18. MIME Type

Determine file type primarily from MIME type.

Do not rely solely on filename extension.

Store:

mime_type

and normalized:

file_type

Supported normalized types:

- image
- video
- audio
- pdf
- document
- spreadsheet
- presentation
- archive
- text
- other

Examples:

application/pdf
→ pdf

image/jpeg
→ image

video/mp4
→ video

application/zip
→ archive

The Vue application should use file_type for icons, previews and UI behavior.

---

# 19. File Manager Features

Directories:

- Create
- Rename
- Delete
- Restore
- Permanent delete
- Move
- Nested directories
- Breadcrumb navigation

Files:

- Upload
- Download
- Stream where practical
- Preview where technically possible
- Rename
- Move
- Copy where practical
- Delete
- Restore
- Permanent delete
- Bulk delete
- Bulk move
- Bulk tagging

Metadata:

- Description
- Edit description
- MIME type
- File type
- Size
- Checksum
- Created date
- Updated date
- Telegram Account
- Telegram Connection
- Telegram Chat
- Telegram Message

Tags:

- Create
- Rename
- Delete
- Attach
- Detach
- Filter

Search:

- Filename
- Description
- MIME
- File type
- Tags
- Path

Filters:

- File type
- MIME type
- Tags
- Telegram Account
- Connection
- Date

Sorting:

- Name
- Size
- Created
- Updated
- Type

Trash:

- Soft delete
- Restore
- Permanent deletion

---

# 20. Phase 1 File Upload Architecture

IMPORTANT:

In Phase 1, files ARE allowed to be temporarily stored on the application server.

This is intentional.

Do NOT implement diskless streaming in Phase 1.

Upload flow:

Browser
↓
HTTPS
↓
FrankenPHP / Laravel
↓
Temporary file
↓
Create FileOperation
↓
Queue
↓
Telegram Worker
↓
MadelineProto
↓
Telegram
↓
Successful upload
↓
Save Telegram identifiers
↓
Delete temporary file

The temporary file must be deleted immediately after successful Telegram upload.

If upload fails, use the temporary file for retry according to the configured retry policy.

After the operation reaches a permanent failure state, clean up orphaned temporary files.

---

# 21. Temporary Upload Storage

Use a dedicated private temporary directory.

For example:

storage/app/private/telegram-upload/

or an equivalent non-public location.

Never place upload files in:

public/
public/storage/
web-accessible directories

Temporary files must never be directly accessible through HTTP.

Use secure unpredictable filenames.

Example:

telegram-upload/
01JXYZ...
01JABC...

Do not use the original filename as the temporary storage filename.

---

# 22. File Upload Lifecycle

Use explicit operation states.

Example:

FileOperation:

pending
uploading
uploaded
failed
retrying
cancelled

File:

pending
uploaded
failed
deleting
deleted

Upload:

1. User selects file
2. Laravel authenticates user
3. Laravel authorizes directory and connection
4. MIME type is detected
5. FileOperation is created
6. Temporary file is stored privately
7. Upload job is dispatched
8. Telegram Worker picks up operation
9. Telegram Worker resolves Connection
10. Telegram Worker resolves Telegram Account
11. Telegram driver is selected
12. Physical file is uploaded
13. Telegram message/media IDs are returned
14. PostgreSQL File record is finalized
15. FileOperation becomes uploaded/completed
16. Reverb completion event is broadcast
17. Temporary file is deleted

---

# 23. Failure Handling

If:

Browser
↓
Temporary file
↓
Telegram upload
↓
failure

do NOT immediately lose the operation.

Allow retry.

Do not create duplicate physical Telegram files when possible.

Use operation IDs and idempotency mechanisms.

For retry:

existing temporary file
↓
retry upload
↓
success
↓
remove temporary file

If the operation is permanently abandoned, clean the temporary file.

Add a scheduled cleanup command for stale temporary files.

---

# 24. Future TODO — Diskless Streaming

DO NOT implement this in Phase 1.

Add it as a clearly documented future TODO.

Future architecture:

Browser
↓
FrankenPHP
↓
stream
↓
Telegram Worker
↓
MadelineProto
↓
Telegram

The future goal is:

- no temporary file
- no full file in memory
- stream directly from HTTP request to Telegram Worker
- stream directly from Telegram Worker to browser during download

Future implementation should evaluate:

- streaming HTTP
- internal worker HTTP endpoint
- chunked transfer
- backpressure
- request cancellation
- retry behavior
- upload resume
- worker failure
- large files
- memory usage

Do NOT implement this now.

Add a TODO/documentation item:

"Future: implement diskless streaming upload/download between FrankenPHP and Telegram Worker."

---

# 25. Download Architecture — Phase 1

For Phase 1:

Telegram
↓
Telegram Worker
↓
Temporary/private file
↓
FrankenPHP
↓
Browser

or another safe streaming implementation that still permits controlled temporary storage.

The primary objective in Phase 1 is correctness and reliability.

Future phases can eliminate the temporary file.

---

# 26. Telegram Driver Abstraction

Define a common abstraction such as:

TelegramStorageDriver

Methods conceptually:

upload(...)
download(...)
delete(...)
exists(...)
metadata(...)

Drivers:

BotTelegramStorageDriver
RoxiTelegramStorageDriver
PersonalTelegramStorageDriver (placeholder)

The File Manager must use the abstraction.

No controller should contain logic like:

if ($account->method === 'roxi') {
MadelineProto...
}

That logic belongs to the Telegram infrastructure layer.

---

# 27. Roxi Driver

RoxiTelegramStorageDriver must:

- resolve the Roxi central account
- resolve the user's TelegramConnection
- obtain the connection's chat_id
- submit operation to the long-running Telegram runtime
- upload using the already-running MadelineProto client
- return Telegram message/media identifiers
- support download
- support delete
- report errors
- report operation progress

It must NOT instantiate a fresh MadelineProto client for every operation.

---

# 28. Telegram Account Status

Distinguish:

Account Status
from
Connection Status

Account statuses:

- connected
- disconnected
- error
- reconnecting
- disabled

Connection statuses:

- pending
- connecting
- connected
- disconnected
- reconnecting
- error
- disabled

A Telegram Account can be healthy while one Connection has an error.

A Telegram Connection can be disconnected while the Telegram Account remains authenticated.

---

# 29. Telegram Account Page

Create a dedicated page:

Telegram Accounts

Use shadcn-vue.

Header:

Telegram Accounts

Description:

Manage Telegram accounts and storage connections used by Roxivia.

Primary button:

- Add Telegram Account

---

# 30. Connection Method Select Cards

When creating a Telegram Account/Connection, present selectable cards.

Use a shadcn-vue-style Select Card / Radio Card / Card selection pattern.

Three cards:

## Roxi Shared Account

Badge:
Recommended

Explain:

- No Telegram login required
- Managed by Roxivia
- Uses a persistent central MTProto session
- Fastest setup
- Dedicated Telegram storage connection
- Best default option

## Telegram Bot

Explain:

- Connect your Telegram Bot
- Bot must be added to a Telegram chat/group/channel
- Uses Bot API
- More control for users who want their own bot

## Personal Telegram Account

Badge:
Coming Soon

Explain:

- Uses your personal Telegram account
- Phone + OTP + 2FA
- MTProto
- Feature not available yet

The UI should provide enough explanation that users understand the differences without needing external documentation.

---

# 31. Account and Connection Table

The Telegram Accounts page must clearly display connections.

A Telegram Account can have multiple Connections.

Example:

Roxi

Connections:

| Status    | Name     | Method | Chat           | Chat ID | Files | Last Verified | Actions |
| --------- | -------- | ------ | -------------- | ------- | ----- | ------------- | ------- |
| Connected | My Files | Roxi   | Morteza ↔ Roxi | -100... | 324   | 2 min ago     | ...     |
| Connected | Work     | Roxi   | Work Storage   | -100... | 82    | 5 min ago     | ...     |

Account status and individual Connection status must be visually distinct.

Use:

- Badge
- Table
- Dropdown Menu
- Tooltip
- Dialog
- AlertDialog
- Skeleton
- Empty state

from shadcn-vue where appropriate.

---

# 32. Connection Actions

Each Connection should support:

- View
- Test connection
- Rename
- Disconnect
- Reconnect
- Verify
- Delete/disable

Depending on method, provide appropriate actions.

For Roxi:

- Verify
- Reconnect
- Disconnect
- Delete storage connection

For Bot:

- Verify
- Reconnect
- Update credentials
- Disconnect

For Personal:

Disabled/placeholder.

---

# 33. Connection Names

Connection names are application-level metadata.

Examples:

My Files
Work Storage
Archive
Project A

They do NOT have to match the Telegram chat title.

Telegram chat title is separate metadata.

---

# 34. Realtime with Laravel Reverb

Use Reverb + Echo.

Broadcast realtime state changes.

Telegram account:

- account.connected
- account.disconnected
- account.error
- account.reconnecting

Telegram connection:

- connection.created
- connection.verifying
- connection.connected
- connection.failed
- connection.disconnected
- connection.reconnecting

Roxi:

- roxi.connection.started
- roxi.chat.detected
- roxi.chat.created where applicable
- roxi.connection.connected
- roxi.connection.failed

File:

- file.upload.started
- file.upload.progress
- file.upload.completed
- file.upload.failed
- file.delete.started
- file.delete.completed
- file.delete.failed

Frontend must update without a full page refresh.

Avoid unnecessary polling.

---

# 35. Upload Progress

The UI should show:

filename

status

progress

speed where practical

estimated remaining time where practical

Example:

invoice.pdf

Uploading
███████████████░░░░░ 78%

Once completed:

Uploaded ✓

If failed:

Upload failed

Retry

Progress should be transmitted through Reverb/events.

---

# 36. File Manager UI

Use shadcn-vue.

Suggested layout:

Sidebar:

My Files
Recent
Tags
Trash
Telegram Storages
Telegram Accounts
Settings

Main:

Breadcrumb
Search
Filters
Sort
Upload
New Folder

File list/grid.

Context menu:

Open
Download
Rename
Move
Copy
Tags
Description
Details
Delete

Details panel:

Name
Path
Type
MIME
Size
Description
Tags
Created
Updated

Telegram section:

Account
Connection
Method
Chat
Chat ID
Message ID

---

# 37. Authorization

Use Laravel Policies.

Every request must validate ownership/access for:

- TelegramAccount
- TelegramConnection
- Directory
- File
- Tag
- FileOperation

Never trust:

telegram_account_id
telegram_connection_id
chat_id
directory_id

from the frontend without server-side validation.

A user must never access another user's storage or file.

System-owned Roxi cannot be modified by normal users.

---

# 38. Database Relationships

User:

hasMany TelegramAccount
hasMany TelegramConnection
hasMany Directory
hasMany File
hasMany Tag

TelegramAccount:

belongsTo User nullable
hasMany TelegramConnection

TelegramConnection:

belongsTo User
belongsTo TelegramAccount
hasMany File

Directory:

belongsTo User
belongsTo parent
hasMany children
hasMany File

File:

belongsTo User
belongsTo Directory
belongsTo TelegramAccount
belongsTo TelegramConnection
belongsToMany Tag

Tag:

belongsTo User
belongsToMany File

---

# 39. Suggested Database Tables

PostgreSQL:

users

telegram_accounts

telegram_connections

directories

files

tags

file_tags

file_operations

telegram_connection_attempts

notifications if needed

Any additional tables should have a clear purpose.

---

# 40. File Operations

FileOperation should track all asynchronous Telegram operations.

Fields:

id
user_id
file_id nullable
telegram_account_id nullable
telegram_connection_id nullable

operation
status
progress
error
metadata

created_at
updated_at
completed_at

Possible operations:

upload
download
delete
copy
reconcile

Use operation state to make asynchronous processing observable and retryable.

---

# 41. Long-Running Worker Health

Create health/status information for the Telegram Worker.

Display in admin or system diagnostics:

- worker running
- Roxi connected
- session loaded
- last activity
- last successful operation
- last error
- reconnect count

Worker should support:

- graceful shutdown
- startup
- reconnect
- crash recovery
- deployment restart

Use a process supervisor/container restart policy in production.

---

# 42. Telegram Error Handling

Handle:

- network errors
- FloodWait
- rate limits
- unauthorized
- revoked session
- permission denied
- chat unavailable
- message not found
- file unavailable
- upload failure
- download failure

Do not blindly retry all Telegram exceptions.

Retry transient failures.

Do not retry permanent authorization failures indefinitely.

Convert Telegram-specific exceptions into application-level operation states.

---

# 43. Reconciliation

Because Telegram is external storage, inconsistencies are possible.

Examples:

PostgreSQL says file exists
but Telegram message no longer exists.

Telegram message exists
but PostgreSQL record is missing.

Connection loses access.

Implement a future-safe reconciliation mechanism.

Do not immediately delete application records because of temporary Telegram errors.

---

# 44. Security

Protect:

- Telegram API Hash
- Telegram API credentials
- Bot tokens
- MadelineProto session
- Roxi account session
- temporary uploaded files

Never expose them in frontend responses.

Never log:

- OTP
- 2FA password
- bot token
- API hash
- session contents
- sensitive Telegram credentials

Temporary files:

- private
- random filenames
- no public URL
- deleted after successful upload
- stale files automatically cleaned

Use encryption for sensitive database credentials when appropriate.

---

# 45. Performance

Expect potentially millions of File records.

PostgreSQL indexes should include at minimum:

user_id
directory_id
telegram_account_id
telegram_connection_id
mime_type
file_type
deleted_at
created_at
updated_at

Avoid N+1 queries.

Use efficient pagination.

Do not load an entire directory tree unnecessarily.

Do not calculate expensive recursive paths on every request when unnecessary.

---

# 46. Queue Architecture

Use Laravel Queue for asynchronous business operations.

Examples:

UploadTelegramFileJob
DeleteTelegramFileJob
VerifyTelegramConnectionJob
ReconcileTelegramStorageJob
CleanupTemporaryUploadsJob

Do NOT put actual file binaries inside Redis jobs.

Queue payloads should contain metadata/reference information.

Phase 1 upload flow:

Temporary file path +
operation_id +
connection_id

is passed to the job.

The worker reads the temporary file.

---

# 47. Temporary File Cleanup

Create a scheduled cleanup mechanism.

Example:

php artisan telegram:cleanup-uploads

It should remove:

- abandoned temporary uploads
- stale failed uploads
- expired operation files

Use a safe age threshold.

Never delete files that are actively being uploaded.

---

# 48. Idempotency

Telegram operations must be designed to avoid duplicate physical files.

Uploads should use an operation ID / idempotency key.

When an upload job retries:

Check whether the operation already completed.

If completed:

Do not upload again.

If pending/failed:

Continue/retry according to state.

---

# 49. Frontend Realtime State

The Vue application should have a clean state model for:

accounts
connections
files
file operations

For long-running operations:

pending
processing
completed
failed

Update state through Reverb events.

Avoid duplicating business logic in Vue.

---

# 50. Personal Account Placeholder Architecture

The future Personal Telegram implementation should use:

PersonalTelegramConnectionDriver

and eventually:

PersonalTelegramRuntimeManager

but do not implement the full runtime now.

The database should already support:

method = personal

and future session references.

---

# 51. Suggested Project Structure

Use a clean structure similar to:

app/
├── Domain/
│ ├── Files/
│ ├── Telegram/
│ └── Storage/
│
├── Application/
│ ├── Files/
│ ├── Telegram/
│ └── Storage/
│
├── Infrastructure/
│ ├── Telegram/
│ │ ├── MadelineProto/
│ │ ├── Roxi/
│ │ └── Bot/
│ └── Storage/
│
├── Http/
│ ├── Controllers/
│ ├── Requests/
│ └── Resources/
│
├── Jobs/
├── Events/
├── Policies/
└── Services/

Do not over-engineer.

Use abstractions where they provide real value.

---

# 52. Important Separation

The system has three separate concepts:

Application User
↓
Telegram Account
↓
Telegram Connection
↓
Telegram Chat

And:

Application User
↓
Virtual Directory
↓
Virtual File
↓
Telegram Connection
↓
Telegram Message

Do not merge these concepts.

---

# 53. Example

User:

Morteza

Telegram Account:

Roxi

Telegram Connection:

My Files

Method:

roxi

Chat:

Morteza ↔ Roxi

Chat ID:

-100123456789

Virtual file:

/Documents/Invoices/2026/invoice.pdf

Physical Telegram object:

Chat -100123456789
Message 93821

PostgreSQL:

File #500
user_id = Morteza
directory_id = 42
telegram_account_id = Roxi
telegram_connection_id = 15
telegram_chat_id = -100123456789
telegram_message_id = 93821
name = invoice.pdf
mime_type = application/pdf
file_type = pdf
status = uploaded

---

# 54. Development Process

Before writing significant code:

1. Inspect the existing Laravel 13 application.
2. Inspect the installed Laravel Vue Starter Kit.
3. Inspect current dependencies.
4. Inspect existing database configuration.
5. Inspect Redis/Reverb configuration.
6. Confirm Laravel/ PHP/Inertia/Vue versions.
7. Design migrations.
8. Design Telegram Runtime.
9. Design Roxi Connection provisioning.
10. Design storage abstraction.
11. Then implement incrementally.

Do not generate the entire application in one step.

---

# 55. Implementation Phases

Phase 1:
Project inspection

Phase 2:
Database schema and Eloquent models

Phase 3:
Telegram Account and Connection domain

Phase 4:
Roxi system account

Phase 5:
Long-running MadelineProto runtime

Phase 6:
Roxi connection provisioning

Phase 7:
Telegram storage abstraction

Phase 8:
Phase 1 temporary-file upload

Phase 9:
Download/delete

Phase 10:
Virtual filesystem

Phase 11:
Tags/descriptions/search

Phase 12:
Reverb realtime updates

Phase 13:
Bot driver

Phase 14:
Health/reconciliation/cleanup

Phase 15:
Testing and performance hardening

Future Phase:
Personal Telegram Account

Future Phase:
Diskless streaming

---

# 56. Future TODO — Diskless File Streaming

This MUST appear in the project's TODO/documentation.

Do NOT implement during the first version.

TODO:

"Replace Phase 1 temporary-file upload/download with direct streaming between FrankenPHP and the long-running Telegram Worker."

Target architecture:

UPLOAD:

Browser
↓
FrankenPHP
↓
Streaming HTTP
↓
Telegram Worker
↓
MadelineProto
↓
Telegram

DOWNLOAD:

Telegram
↓
MadelineProto
↓
Telegram Worker
↓
Streaming HTTP
↓
FrankenPHP
↓
Browser

Goals:

- no persistent temporary file
- no full file buffering
- lower disk usage
- lower memory usage
- support very large files
- support backpressure
- support cancellation
- support progress reporting
- maintain secure internal worker communication

First implementation should prioritize correctness and reliability over this optimization.

---

# 57. Final Architecture

The intended production architecture is:

                         Browser
                            │
                          HTTPS
                            │
                            ▼
                 ┌─────────────────────┐
                 │ FrankenPHP +        │
                 │ Laravel Octane      │
                 │                     │
                 │ Auth                │
                 │ API                 │
                 │ File Manager        │
                 │ Inertia             │
                 │ Reverb              │
                 └─────────┬───────────┘
                           │
                 ┌─────────┴─────────┐
                 │                   │
             PostgreSQL             Redis
                 │                   │
                 │                   │
                 │             Queue / Commands
                 │                   │
                 │                   ▼
                 │          ┌──────────────────┐
                 │          │ Telegram Worker  │
                 │          │                  │
                 │          │ Long Running     │
                 │          │                  │
                 │          │ Roxi             │
                 │          │ MadelineProto    │
                 │          │ session loaded    │
                 │          │ once              │
                 │          └────────┬─────────┘
                 │                   │
                 │                  MTProto
                 │                   │
                 │                   ▼
                 │               Telegram
                 │                   │
                 └───────────metadata/files

PHASE 1 FILE DATA:

Browser
↓
FrankenPHP
↓
Private Temporary File
↓
Queue
↓
Telegram Worker
↓
MadelineProto
↓
Telegram
↓
Delete Temporary File

FUTURE FILE DATA:

Browser
↓
FrankenPHP
↓
Stream
↓
Telegram Worker
↓
MadelineProto
↓
Telegram

---

# 58. Final Constraints

The implementation must satisfy all of the following:

1. Laravel 13
2. PHP 8.5
3. PostgreSQL for application data
4. MySQL for MadelineProto-related persistence where appropriate
5. Inertia.js
6. Vue 3
7. Existing Laravel Vue Starter Kit
8. shadcn-vue preferred
9. Laravel Reverb
10. Multiple Telegram Accounts
11. Multiple Connections per Account
12. Every Connection has a chat_id after connection
13. Bot connection
14. Roxi shared account connection
15. Personal account placeholder
16. One persistent Roxi MadelineProto session
17. Long-running Telegram worker
18. No MadelineProto initialization on ordinary HTTP requests
19. Virtual filesystem
20. Virtual paths in PostgreSQL
21. File tags
22. File descriptions
23. MIME-based file type
24. File rename
25. Directory creation/rename/move/delete
26. File move/delete/restore
27. File-to-account mapping
28. File-to-connection mapping
29. File-to-chat mapping
30. File-to-message mapping
31. Queue-based Telegram operations
32. Reverb-based operation status
33. Retry/idempotency
34. Temporary file in Phase 1
35. Immediate temporary-file cleanup after successful Telegram upload
36. Future diskless streaming TODO
37. Secure session/token handling
38. Proper Laravel Policies
39. Tests for all critical flows

Do not optimize for theoretical complexity.

Prioritize:

Correctness
Security
Session reuse
Clear domain boundaries
Reliable background processing
Good UX
Maintainability

The most important performance rule is:

DO NOT repeatedly restore MadelineProto sessions.

Load the required session once in the long-running Telegram runtime and reuse it.
