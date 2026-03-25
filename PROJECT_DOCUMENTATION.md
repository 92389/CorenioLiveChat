# Live Chat Project Documentation

**Last Updated:** March 23, 2026

## 1) Project Overview

Live Chat is a PHP + MySQL application with two main interfaces:

- **Customer widget** embedded on a website (`index.php` + `assets/chat-widget.js`)
- **Employee dashboard** for handling chats (`employee/index.php` + `assets/employee.js`)

The system supports:

- Customer chat creation and messaging
- Employee assignment / take / close / reopen workflows
- Admin permissions and channel access controls
- File upload/download in chats (including inline image previews)
- Optional WebSocket realtime server, with polling fallback

---

## 2) Current Repository Structure (First-Party Files)

### Root

- `index.php` — demo host page for customer widget
- `debug.php` — setup/diagnostic helper page
- `ws-server.php` — Ratchet WebSocket server entrypoint
- `composer.json` / `composer.lock` — dependency manifest + lockfile
- `PROJECT_DOCUMENTATION.md` — this document
- `FILE_FUNCTIONS_DOCUMENTATION.md` — function-level overview for PHP/JS files

### API

- `api/bootstrap.php` — shared API bootstrap/helpers/error handling
- `api/chat.php` — customer API router
- `api/employee.php` — employee API router

### Libraries

- `lib/db.php` — config + PDO + transaction helper
- `lib/http.php` — JSON/method/query helpers
- `lib/customer.php` — customer token/cookie utilities
- `lib/employee_auth.php` — session/auth/CSRF/rate-limit helpers

### Frontend Assets

- `assets/chat-widget.js` — customer widget runtime
- `assets/employee.js` — employee dashboard runtime
- `assets/chat.css` — customer widget styles
- `assets/employee.css` — dashboard styles
- `assets/demo.css` — demo page styles

### Employee Pages

- `employee/index.php` — dashboard shell
- `employee/login.php` — login page/form processing
- `employee/logout.php` — logout endpoint
- `employee/register.php` — registration page/form processing
- `employee/admin.php` — admin permissions + channel access page
- `employee/chats.php` — admin chat management view
- `employee/admin_nav.php` — admin navigation partial

### Database

- `db/schema.sql` — full database schema (canonical setup file)

### WebSocket Core

- `ws/ChatServer.php` — realtime message component implementation

### Other Project Files

- `config/config.php` — app config (DB, cookie, ws URL)
- `img/corenio_background.png` — branding asset
- `Corenio_LiveChat/.gitattributes`, `Live_Chat/.gitattributes`

---

## 3) Architecture and Data Flow

### 3.1 Customer Path

1. Website loads `assets/chat-widget.js`.
2. Widget talks to `api/chat.php?action=...`.
3. API identifies customer via `lc_customer` cookie token (`lib/customer.php`).
4. Messages/files are stored in MySQL and rendered back to widget.
5. Widget receives updates via polling (and can coexist with WS support).

### 3.2 Employee Path

1. Employee logs in through `employee/login.php`.
2. Session/auth handled by `lib/employee_auth.php`.
3. Dashboard (`employee/index.php`) loads `assets/employee.js`.
4. Frontend calls `api/employee.php?action=...` for list/poll/send/state actions.
5. Permissions enforced using employee role/admin/channel access checks.

### 3.3 Realtime Path (Optional)

1. `ws-server.php` starts Ratchet app.
2. `ws/ChatServer.php` handles socket lifecycle.
3. Clients subscribe by chat/room; actions are validated and persisted.
4. Broadcast updates sent to room members.
5. If WS is unavailable, HTTP polling remains functional.

---

## 4) Key Functional Areas

### 4.1 Authentication and Security

- Employee sessions and login state validation
- CSRF tokens for forms
- Basic login rate-limiting support
- Security headers set on major PHP entry points
- JSON API error envelope consistency (`ok`, `error`, payload)

### 4.2 Chat Operations

- Open/create chat
- Poll/send messages
- Typing indicator support
- Employee take/close/reopen flow
- Chat status transitions reflected in both UIs

### 4.3 File Handling

- File upload from customer and employee sides
- Validated file type/size constraints
- Storage metadata in `message_files`
- Download endpoint support with authorization checks
- Inline image rendering in both UIs

### 4.4 Admin and Access Control

- Admin role flags and permission handling
- Channel access map support
- Admin pages for employee permissions and chat management

---

## 5) Database Summary

Canonical schema is in `db/schema.sql`.

Core entities include:

- `employees`
- `chats`
- `messages`
- `tags`
- `chat_tags`
- `message_files`
- employee/channel access and admin-related fields

**Important:** This repository currently uses the single complete schema file (`db/schema.sql`) as the setup source.

---

## 6) Configuration

`config/config.php` provides:

- DB host/port/name/user/password/charset
- customer cookie name
- WebSocket URL

`lib/db.php` loads and caches this configuration.

---

## 7) API Surface (High-Level)

### 7.1 Customer API (`api/chat.php`)

- Config/bootstrap data for widget
- Chat lifecycle and chat retrieval
- Message polling/sending
- Typing state
- File upload/download

### 7.2 Employee API (`api/employee.php`)

- Dashboard config and list data
- Chat list retrieval and filtering
- Message polling/sending
- Take/close/reopen/delete operations
- Typing state
- File upload/download
- Access control aware behavior

---

## 8) Frontend Responsibilities

### 8.1 Customer Widget (`assets/chat-widget.js`)

- Constructs widget DOM and interactions
- Maintains chat state/history/unread counters
- Renders text + file messages
- Handles tag selection flow
- Calls customer API and handles error states

### 8.2 Employee Dashboard (`assets/employee.js`)

- Manages chat list and selected conversation
- Renders message pane and metadata
- Applies control/button state based on permissions/chat state
- Handles file upload/display and status actions
- Coordinates polling and typing indicator updates

---

## 9) Admin Surfaces

- `employee/admin.php` for permission/channel access administration
- `employee/chats.php` for administrative chat oversight
- `employee/admin_nav.php` as shared admin navigation component

---

## 10) Operations and Setup

### 10.1 Prerequisites

- PHP 8.x recommended
- MySQL/MariaDB
- Composer dependencies installed

### 10.2 Basic Setup

1. Configure DB credentials in `config/config.php`.
2. Create database and apply `db/schema.sql`.
3. Run/install dependencies via Composer.
4. Serve project root through PHP-enabled web server.
5. Optional: run `ws-server.php` for realtime sockets.

### 10.3 Quick Validation

- Visit `index.php` for customer widget
- Visit `employee/login.php` for employee portal
- Use `debug.php` when diagnosing setup/schema issues

---

## 11) Documentation Map

- `PROJECT_DOCUMENTATION.md` (this file): architecture, setup, current state
- `FILE_FUNCTIONS_DOCUMENTATION.md`: function-focused PHP/JS summary
- `docs_per_file/README.md`: one-document-per-file reference index

---

## 12) Current State Notes (March 23, 2026)

- Project is organized around HTTP APIs with optional WS enhancement.
- Database setup is represented by a single canonical schema file.
- Per-file documentation set exists under `docs_per_file/`.
- Third-party packages remain in `vendor/` and are intentionally documented separately from first-party logic.# Live Chat Project - Comprehensive Function Documentation

**Last Updated:** February 20, 2026

This document provides complete documentation of all functions, methods, and procedures in the Live Chat application, organized by module and use case.

---

## Table of Contents

1. [Database & Configuration Layer](#database--configuration-layer)
2. [HTTP Utilities](#http-utilities)
3. [Customer Management](#customer-management)
4. [Employee Authentication](#employee-authentication)
5. [Bootstrap & Shared Helpers](#bootstrap--shared-helpers)
6. [Customer API Endpoints](#customer-api-endpoints)
7. [Employee API Endpoints](#employee-api-endpoints)
8. [Customer Widget (JavaScript)](#customer-widget-javascript)
9. [Employee Dashboard (JavaScript)](#employee-dashboard-javascript)
10. [WebSocket Server](#websocket-server)
11. [Database Schema](#database-schema)

---

## Database & Configuration Layer

### File: `lib/db.php`

#### `lc_config(): array`

**Purpose:** Load and cache application configuration (database credentials, cookie names, WebSocket URL, etc.).

**Returns:** Associative array with keys: `db` (host, port, name, user, pass, charset), `customer_cookie`, `ws_url`, etc.

**Details:** Uses a static variable to ensure the configuration file (`config/config.php`) is loaded only once per request, improving performance.

**Example Keys:**

- `db['host']` - MySQL host
- `db['port']` - MySQL port
- `db['name']` - Database name
- `db['user']` - Database username
- `db['pass']` - Database password
- `customer_cookie` - Cookie name for storing customer token
- `ws_url` - WebSocket server URL (optional)

---

#### `lc_pdo(): PDO`

**Purpose:** Return a singleton PDO instance configured for the Live Chat database.

**Returns:** A `PDO` object connected to the database specified in config.

**Configuration Applied:**

- Character set: `utf8mb4` (full Unicode support)
- Error mode: `PDO::ERRMODE_EXCEPTION` (throws exceptions on SQL errors)
- Default fetch mode: `PDO::FETCH_ASSOC` (returns rows as associative arrays)
- Emulate prepares: disabled for safer parameter binding

**Usage:** Called by all API endpoints and business logic to execute queries.

---

#### `lc_tx(callable $fn): mixed`

**Purpose:** Execute a database operation inside a transaction with automatic rollback on error.

**Parameters:**

- `$fn` - Callback function that receives the PDO instance and performs database operations

**Returns:** The return value from the callback.

**Behavior:**

- Calls `$pdo->beginTransaction()`
- Executes the callback
- On success: calls `commit()`
- On exception: rolls back and re-throws

**Example:**

```php
$result = lc_tx(function (PDO $pdo) {
  // Multiple operations here are atomic
  $pdo->prepare("UPDATE chats SET status='taken' WHERE id=?")->execute([123]);
  return true;
});
```

---

## HTTP Utilities

### File: `lib/http.php`

#### `lc_json_response(array $payload, int $status = 200): void`

**Purpose:** Emit a JSON HTTP response with proper headers and status code, then exit.

**Parameters:**

- `$payload` - Associative array to be JSON-encoded
- `$status` - HTTP status code (default: 200)

**Headers Set:**

- `Content-Type: application/json; charset=utf-8`
- `Cache-Control: no-store`

**Details:** All API responses follow a consistent envelope: `{ "ok": true/false, ... }`

**Exit Behavior:** Terminates the request after sending the response.

---

#### `lc_require_method(string $method): void`

**Purpose:** Enforce that the current request uses a specific HTTP method (GET, POST, etc.).

**Parameters:**

- `$method` - Required HTTP method (case-insensitive)

**On Mismatch:** Calls `lc_json_response()` with a 405 status code and exits.

**Example:**

```php
lc_require_method('POST');  // Will error if request is not POST
```

---

#### `lc_read_json_body(): array`

**Purpose:** Read and decode JSON payload from `php://input` (request body).

**Returns:** Associative array on success, or empty array `[]` if body is empty.

**Behavior:**

- Returns `[]` if body is empty or not valid UTF-8
- Returns `400 JSON` error if body cannot be decoded to an array
- Uses `json_decode($raw, true)` to always return associative arrays

---

#### `lc_get_int(string $key, int $default = 0): int`

**Purpose:** Read an integer from the `$_GET` query parameters.

**Parameters:**

- `$key` - Query parameter name
- `$default` - Value to return if parameter is missing or non-numeric (default: 0)

**Returns:** Integer value of the parameter, or the default.

---

#### `lc_get_str(string $key, string $default = ''): string`

**Purpose:** Read a string from the `$_GET` query parameters.

**Parameters:**

- `$key` - Query parameter name
- `$default` - Value to return if parameter is missing or not a string (default: '')

**Returns:** String value of the parameter, or the default.

---

## Customer Management

### File: `lib/customer.php`

#### `lc_uuid_v4(): string`

**Purpose:** Generate a random RFC 4122 version 4 UUID string.

**Returns:** UUID string in the format `xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx`

**Details:** Uses `random_bytes(16)` for cryptographic randomness and formats according to UUID v4 specification.

---

#### `lc_customer_token(): string`

**Purpose:** Retrieve (or lazily create) a stable customer token stored in a browser cookie.

**Returns:** UUID string that uniquely identifies this browser/customer.

**Behavior:**

- Checks for existing `lc_customer` cookie (configurable name from `lc_config()`)
- If valid UUID found, returns it
- If missing or invalid, generates a new UUID and sets a cookie lasting ~180 days
- Cookie attributes: `secure=false`, `httponly=true`, `samesite=Lax`

**Purpose of Token:** Used as the primary identifier for all customer chats; customers are identified by their browser cookie rather than username/password.

---

## Employee Authentication

### File: `lib/employee_auth.php`

#### `lc_employee_session_start(): void`

**Purpose:** Start a PHP session for employee authentication if one is not already active.

**Details:** Checks `session_status()` and only calls `session_start()` if `PHP_SESSION_NONE`.

---

#### `lc_employee_id(): ?int`

**Purpose:** Get the current authenticated employee ID from the session, or `null` if not logged in.

**Returns:** Integer employee ID if authenticated and active, or `null`.

**Validation:**

- Checks `$_SESSION['lc_employee_id']` for an integer value
- Queries the database to verify the employee still exists
- **Admin Override:** Admins (with `is_admin=1` or `can_grant_admin=1`) can remain logged in even if their account is marked `is_active=0`
- Non-admins with inactive accounts are logged out

**Example:**

```php
$empId = lc_employee_id();
if ($empId === null) {
  // Not logged in
}
```

---

#### `lc_require_employee(): int`

**Purpose:** Ensure there is a logged-in employee in the current session; abort if not.

**Returns:** The authenticated employee ID on success.

**On Failure:** Calls `lc_json_response(['ok' => false, 'error' => 'Not authenticated'], 401)` and exits.

**Usage:** Called at the start of `api/employee.php` to guard all employee endpoints.

---

#### `lc_employee_login(string $username, string $password): bool`

**Purpose:** Attempt to log an employee in using username and password.

**Parameters:**

- `$username` - Employee username
- `$password` - Plain-text password

**Returns:** `true` on success, `false` on failure.

**Side Effects on Success:**

- Calls `session_regenerate_id(true)` to prevent session fixation attacks
- Stores `lc_employee_id`, `lc_login_time`, `lc_login_ip` in the session

**Special Behavior:**

- **Admin Accounts:** Admins (with `is_admin=1` or `can_grant_admin=1`) can log in even if `is_active=0`
- **Non-Admin Accounts:** Must have `is_active=1` to log in
- **Inactive Non-Admin:** Sets `$GLOBALS['login_error_message']` to "Your account is not active..."

---

#### `lc_employee_logout(): void`

**Purpose:** Log the current employee out and destroy the PHP session.

**Details:**

- Clears `$_SESSION` array
- Deletes the session cookie if cookies are enabled
- Calls `session_destroy()`

---

#### `lc_employee_register(string $username, string $displayName, string $password): array`

**Purpose:** Register a new employee account.

**Parameters:**

- `$username` - Unique username (max 64 chars)
- `$displayName` - Human-readable display name (max 128 chars)
- `$password` - Password (min 6 chars)

**Returns:** Associative array:

- On success: `{ "ok": true, "employee_id": <int> }`
- On failure: `{ "ok": false, "error": "<error message>" }`

**Validation:**

- Username and display name cannot be empty
- Username max 64 chars, display name max 128 chars
- Password min 6 chars
- Username must be unique

**New Employee Behavior:** Created as `is_active=0` (inactive by default; admin must approve before they can log in).

---

## Bootstrap & Shared Helpers

### File: `api/bootstrap.php`

#### `LcHttpException`

**Purpose:** Lightweight exception type for API errors.

**Properties:**

- `int $status` - HTTP status code
- `string $message` - Error message (inherited from `RuntimeException`)

**Usage:** Thrown to abort API handlers; caught by global exception handler.

**Example:**

```php
throw new LcHttpException(404, 'Chat not found');
```

---

#### `lc_abort(int $status, string $message): void`

**Purpose:** Abort the current request with a JSON error response.

**Parameters:**

- `$status` - HTTP status code (e.g., 400, 404, 403)
- `$message` - Error message to send to client

**Implementation:** Throws `LcHttpException` which is caught by the global exception handler.

---

#### Global Exception Handler

**Purpose:** Catch exceptions and format them as JSON responses.

**Behavior:**

- `LcHttpException` → pass through message and status code
- Other exceptions → return HTTP 500 with generic message (details hidden from client for security)

---

#### `lc_fetch_all_tags(PDO $pdo): array`

**Purpose:** Fetch all active tags ordered by sort order.

**Parameters:**

- `$pdo` - Database connection

**Returns:** Array of tag rows: `[{ "id": <int>, "name": <string> }, ...]`

**SQL:** `SELECT id, name FROM tags WHERE is_active = 1 ORDER BY sort_order ASC, name ASC`

---

#### `lc_employee_is_admin(PDO $pdo, int $employeeId): bool`

**Purpose:** Check if an employee has admin privileges.

**Parameters:**

- `$pdo` - Database connection
- `$employeeId` - Employee ID to check

**Returns:** `true` if employee has `is_admin=1` OR `can_grant_admin=1`, otherwise `false`.

**Usage:** Used to bypass permission checks on chat operations for administrators.

---

#### `lc_fetch_chat_tags(PDO $pdo, int $chatId): array`

**Purpose:** Fetch all active tags currently associated with a chat.

**Parameters:**

- `$pdo` - Database connection
- `$chatId` - Chat ID

**Returns:** Array of tag rows joined with `tags` table: `[{ "id": <int>, "name": <string> }, ...]`

**Details:** Joins `chat_tags` with `tags` and filters by `is_active=1`.

---

#### `lc_fetch_chat_messages(PDO $pdo, int $chatId, int $limit = 200): array`

**Purpose:** Fetch up to `$limit` most recent messages for a chat, ordered oldest first.

**Parameters:**

- `$pdo` - Database connection
- `$chatId` - Chat ID
- `$limit` - Maximum messages to return (clamped to 1-500 for safety)

**Returns:** Array of message rows with employee info joined:

```
[
  {
    "id": <int>,
    "chat_id": <int>,
    "sender_type": "customer|employee|system",
    "sender_employee_id": <int|null>,
    "body": <string>,
    "created_at": <ISO datetime>,
    "customer_read_at": <ISO datetime|null>,
    "employee_read_at": <ISO datetime|null>,
    "employee_username": <string|null>,
    "employee_display_name": <string|null>
  },
  ...
]
```

**Details:** Left joins `messages` with `employees` to include sender display info.

---

## Customer API Endpoints

### File: `api/chat.php`

All endpoints use the `?action=<name>` router pattern. All requests are scoped to the current browser's customer token.

---

#### `?action=config` (GET)

**Purpose:** Return minimal configuration for the widget (WebSocket URL and available tags).

**Returns:**

```json
{
  "ok": true,
  "ws_url": "ws://...",
  "tags": [{ "id": <int>, "name": <string> }, ...]
}
```

**Details:** Used by the widget on load to get configuration and available tags.

---

#### `?action=list_chats` (GET)

**Purpose:** Return a short history of chats for this customer (for the history panel).

**Parameters:** None

**Returns:**

```json
{
  "ok": true,
  "chats": [
    {
      "id": <int>,
      "customer_name": <string|null>,
      "status": "open|taken|closed",
      "assigned_employee_id": <int|null>,
      "created_at": <ISO datetime>,
      "updated_at": <ISO datetime>,
      "closed_at": <ISO datetime|null>,
      "tags": []  // Empty for closed chats
    },
    ...
  ]
}
```

**Details:**

- Fetches up to 50 most recent chats
- Tags hidden for closed chats
- Ordered by `updated_at DESC`

---

#### `?action=create_or_get` (POST)

**Purpose:** Reuse an existing open/taken chat or create a new one.

**Body:**

```json
{
  "customer_name": "<string|null>"
}
```

**Returns:**

```json
{
  "ok": true,
  "chat": {
    "id": <int>,
    "customer_name": <string|null>,
    "status": "open|taken|closed",
    "assigned_employee_id": <int|null>,
    "created_at": <ISO datetime>,
    "updated_at": <ISO datetime>,
    "closed_at": <ISO datetime|null>,
    "tags": []
  },
  "tags": [{ "id": <int>, "name": <string> }, ...],
  "messages": [...]
}
```

**Behavior:**

- If customer already has an active chat (`status='open'` or `status='taken'`), reuses it
- Enforces: only 1 active chat per customer at a time
- If `customer_name` is provided and chat has no name yet, updates it
- On new chat creation:
  - Inserts welcome message: "Welcome to the Corenio live chat, please select the tags..."
  - Returns the newly created chat with initial messages

---

#### `?action=get_chat` (GET)

**Purpose:** Fetch a single chat with its messages and tags (customer-scoped).

**Parameters:**

- `chat_id` (int) - Chat ID to retrieve

**Returns:**

```json
{
  "ok": true,
  "chat": { ... },
  "messages": [...],
  "tags": [...]
}
```

**Details:**

- Verifies chat belongs to current customer
- Tags hidden for closed chats
- Returns up to 500 messages

---

#### `?action=set_tags` (POST)

**Purpose:** Update which tags the customer has chosen for their chat.

**Body:**

```json
{
  "chat_id": <int>,
  "tag_ids": [<int>, <int>, ...]
}
```

**Returns:**

```json
{
  "ok": true,
  "tags": [{ "id": <int>, "name": <string> }, ...]
}
```

**Behavior:**

- Prevents tag changes on closed chats
- Inserts one of two system messages:
  - If **no employees online** (count from `employees WHERE is_active=1`): "Looks like you chose 'X, Y', unfortunately there are no developers available right now..."
  - If **employees exist**: "Looks like you chose 'X, Y'. I will now connect you to the right person."
- These system messages appear in the chat as "Corenio Bot"

---

#### `?action=set_typing` (POST)

**Purpose:** Mark that the customer is typing in this chat (persisted to DB).

**Body:**

```json
{
  "chat_id": <int>,
  "is_typing": <boolean>
}
```

**Returns:**

```json
{
  "ok": true
}
```

**Behavior:**

- Creates `customer_typing` helper table if it doesn't exist
- If `is_typing=true`: Inserts or updates row with current timestamp
- If `is_typing=false`: Deletes the row
- Employees poll this to show "customer is typing" indicator

---

#### `?action=get_typing_status` (GET)

**Purpose:** Check whether any employee is typing in the given chat.

**Parameters:**

- `chat_id` (int) - Chat ID

**Returns:**

```json
{
  "ok": true,
  "typing": {
    "employee_typing": <boolean>
  }
}
```

**Behavior:**

- Creates `employee_typing` helper table if needed
- Returns true if any row exists in `employee_typing` for this chat with `last_seen_at > NOW() - INTERVAL 5 SECOND`
- Used by customer UI polling

---

#### `?action=send` (POST)

**Purpose:** Customer sends a new message (HTTP fallback if WS unavailable).

**Body:**

```json
{
  "chat_id": <int>,
  "body": "<string>"
}
```

**Returns:**

```json
{
  "ok": true,
  "message": {
    "id": <int>,
    "chat_id": <int>,
    "sender_type": "customer",
    "sender_employee_id": <null>,
    "body": "<string>",
    "created_at": <ISO datetime>
  }
}
```

**Validation:**

- Chat must exist and belong to customer
- Chat must not be closed
- At least one tag must be selected (prevents untagged chats)
- Message body max 2000 characters

---

#### `?action=poll` (GET)

**Purpose:** Long-poll style endpoint returning new messages since `since_id`.

**Parameters:**

- `chat_id` (int) - Chat ID
- `since_id` (int) - Return messages with `id > since_id`

**Returns:**

```json
{
  "ok": true,
  "chat": {
    "id": <int>,
    "status": "open|taken|closed",
    "assigned_employee_id": <int|null>,
    "closed_at": <ISO datetime|null>,
    "updated_at": <ISO datetime>
  },
  "messages": [...]
}
```

**Details:**

- Used as fallback when WebSockets unavailable
- Tags hidden for closed chats
- Returns up to 200 new messages

---

#### `?action=mark_read` (POST)

**Purpose:** Mark all employee messages in a chat as read by the customer.

**Body:**

```json
{
  "chat_id": <int>
}
```

**Returns:**

```json
{
  "ok": true
}
```

**Behavior:**

- Sets `customer_read_at = NOW()` for all messages from employees in the chat
- Allows the employee to see checkmarks on their messages

---

## Employee API Endpoints

### File: `api/employee.php`

All endpoints are guarded by `lc_require_employee()` and scoped to the authenticated employee. Admin privileges checked via `lc_employee_is_admin()`.

---

#### Helper Function: `lc_employee_display_name(PDO $pdo, int $employeeId): string`

**Purpose:** Fetch a human-friendly display name for an employee.

**Returns:** Display name string or 'Employee' if not found.

**Usage:** Used for system messages like "You are now connected to X".

---

#### `?action=me` (GET)

**Purpose:** Return basic profile information for the currently logged-in employee.

**Returns:**

```json
{
  "ok": true,
  "me": {
    "id": <int>,
    "username": "<string>",
    "display_name": "<string>"
  }
}
```

---

#### `?action=list_chats` (GET)

**Purpose:** Return a list of chats with unread counts and NEW markers for the sidebar.

**Parameters:**

- `status` (string, optional) - Filter by status: "open", "taken", "closed", or "all" (default)

**Returns:**

```json
{
  "ok": true,
  "chats": [
    {
      "id": <int>,
      "customer_name": "<string|null>",
      "customer_token": "<string>",
      "status": "open|taken|closed",
      "assigned_employee_id": <int|null>,
      "created_at": <ISO datetime>,
      "updated_at": <ISO datetime>,
      "closed_at": <ISO datetime|null>,
      "assigned_employee_name": "<string|null>",
      "last_seen_message_id": <int>,
      "is_new_chat": <boolean>,
      "unread_customer_count": <int>,
      "last_message_body": "<string|null>",
      "tags": [...]
    },
    ...
  ]
}
```

**Details:**

- Filters out chats with no tags (customer hasn't selected any yet)
- Calculates unread counts based on `employee_chat_reads` table
- NEW badge shown for unclaimed chats

---

#### `?action=get_chat` (GET)

**Purpose:** Load a single chat with messages and mark everything as "seen" for badges.

**Parameters:**

- `chat_id` (int) - Chat ID

**Returns:**

```json
{
  "ok": true,
  "chat": { ... },
  "unread_since_id": <int>,
  "messages": [...]
}
```

**Side Effects:**

- Marks all currently visible messages as "seen" by this employee (updates `employee_chat_reads`)
- Clears notification badges for this chat

---

#### `?action=poll` (GET)

**Purpose:** Employee-side long-poll endpoint (fallback when WebSockets unavailable).

**Parameters:**

- `chat_id` (int) - Chat ID
- `since_id` (int) - Return messages with `id > since_id`

**Returns:**

```json
{
  "ok": true,
  "chat": {
    "id": <int>,
    "status": "open|taken|closed",
    "assigned_employee_id": <int|null>,
    "updated_at": <ISO datetime>,
    "closed_at": <ISO datetime|null>
  },
  "messages": [...]
}
```

**Permission:** Non-admins must be assigned to the chat (403 if not).

**Side Effects:** Marks new messages as "seen" while polling.

---

#### `?action=take_chat` (POST)

**Purpose:** Employee claims ownership of a chat.

**Body:**

```json
{
  "chat_id": <int>
}
```

**Returns:**

```json
{
  "ok": true,
  "chat": {
    "id": <int>,
    "customer_name": "<string|null>",
    "status": "taken",
    "assigned_employee_id": <int>,
    "created_at": <ISO datetime>,
    "updated_at": <ISO datetime>,
    "closed_at": <ISO datetime|null>
  }
}
```

**Behavior:**

- Updates chat `status='taken'` and sets `assigned_employee_id`
- Inserts system message: "You are now connected to [employee name]."
- Admins can take any chat; non-admins can only take unassigned or self-assigned chats

---

#### `?action=send` (POST)

**Purpose:** Employee sends a message to the chat.

**Body:**

```json
{
  "chat_id": <int>,
  "body": "<string>"
}
```

**Returns:**

```json
{
  "ok": true,
  "message": {
    "id": <int>,
    "chat_id": <int>,
    "sender_type": "employee",
    "sender_employee_id": <int>,
    "body": "<string>",
    "created_at": <ISO datetime>
  }
}
```

**Validation:**

- Chat must exist
- Chat must not be closed
- Employee must be assigned to the chat (admins bypass this)
- Message max 2000 characters

---

#### `?action=close_chat` (POST)

**Purpose:** Employee closes the chat (no further messages allowed).

**Body:**

```json
{
  "chat_id": <int>
}
```

**Returns:**

```json
{
  "ok": true,
  "chat": {
    "id": <int>,
    "customer_name": "<string|null>",
    "status": "closed",
    "assigned_employee_id": <int>,
    "created_at": <ISO datetime>,
    "updated_at": <ISO datetime>,
    "closed_at": <ISO datetime>"
  }
}
```

**Behavior:**

- Updates `status='closed'` and `closed_at=NOW()`
- Inserts system message: "Chat closed by [employee name]."
- Non-admins must be assigned to the chat; admins can close any chat

---

#### `?action=reopen_chat` (POST)

**Purpose:** Employee re-opens a previously closed chat.

**Body:**

```json
{
  "chat_id": <int>
}
```

**Returns:**

```json
{
  "ok": true,
  "chat": {
    "id": <int>,
    "customer_name": "<string|null>",
    "status": "taken",
    "assigned_employee_id": <int>,
    "created_at": <ISO datetime>,
    "updated_at": <ISO datetime>,
    "closed_at": <null>
  }
}
```

**Behavior:**

- Updates `status='taken'` and clears `closed_at`
- Increments `reopened_count`
- Inserts system message: "Chat reopened by [employee name]."
- Non-admins must be assigned to the chat; admins can reopen any chat

---

#### `?action=delete_chat` (POST)

**Purpose:** Delete a closed chat and all associated data.

**Body:**

```json
{
  "chat_id": <int>
}
```

**Returns:**

```json
{
  "ok": true,
  "message": "Chat deleted"
}
```

**Constraints:**

- Chat must be in `status='closed'`
- Non-admins must be assigned to the chat; admins can delete any chat

**Deletions:**

- Removes all messages
- Removes all chat_tags
- Removes employee read entries
- Removes the chat itself

---

#### `?action=set_typing` (POST)

**Purpose:** Mark that this employee is typing in a chat (persisted to DB).

**Body:**

```json
{
  "chat_id": <int>,
  "is_typing": <boolean>
}
```

**Returns:**

```json
{
  "ok": true
}
```

**Behavior:**

- Creates `employee_typing` helper table if it doesn't exist
- If `is_typing=true`: Inserts or updates row for this employee in this chat
- If `is_typing=false`: Deletes the row
- Customers poll this via `get_typing_status` to show "employee is typing" indicator

---

#### `?action=get_typing_status` (GET)

**Purpose:** Check if the customer is typing in the given chat.

**Parameters:**

- `chat_id` (int) - Chat ID

**Returns:**

```json
{
  "ok": true,
  "typing": {
    "customer_typing": <boolean>
  }
}
```

**Behavior:**

- Queries `customer_typing` table for recent rows (within last 5 seconds)
- Used by employee UI polling

---

#### `?action=mark_read` (POST)

**Purpose:** Mark all customer messages in a chat as read by the employee.

**Body:**

```json
{
  "chat_id": <int>
}
```

**Returns:**

```json
{
  "ok": true
}
```

**Behavior:**

- Sets `employee_read_at = NOW()` for all messages from customers in the chat
- Allows the customer to see checkmarks on the employee's messages

---

## Customer Widget (JavaScript)

### File: `assets/chat-widget.js`

This is a self-contained widget that renders a floating chat panel. It uses the Customer API exclusively.

---

#### State Object

**Purpose:** Maintain in-memory state of the current chat session.

```javascript
const state = {
  chatId: null, // Current chat ID
  chat: null, // Full chat object from API
  tags: [], // All available tags
  selectedTagIds: Set(), // User's selected tag IDs
  lastMessageId: 0, // For polling/deduplication
  unreadCount: 0, // Unread message count
  pollingTimer: null, // setInterval ID for polling
  historyOpen: false, // Chat history panel visible
  panelOpen: false, // Main chat panel visible
  isTyping: false, // Customer is currently typing
  typingTimeoutId: null, // Timer for clearing typing flag
};
```

---

#### `el(tag, attrs = {}, children = []): HTMLElement`

**Purpose:** Small DOM helper to create elements declaratively.

**Parameters:**

- `tag` - HTML tag name (e.g., "div", "button")
- `attrs` - Attributes object (supports "class", "text", "on\*" handlers, and data attributes)
- `children` - Array of child elements/nodes

**Returns:** Created HTML element

**Example:**

```javascript
el("button", { class: "btn", text: "Click me", onclick: () => alert("Hi") });
```

---

#### `fmtTime(iso): string`

**Purpose:** Format an ISO datetime string to a readable locale string.

**Parameters:**

- `iso` - ISO datetime string from API

**Returns:** Formatted time string or the original string if parsing fails

---

#### `apiGet(action, params = {}): Promise<Object>`

**Purpose:** Make a GET request to the customer API.

**Parameters:**

- `action` - API action name (e.g., "config", "get_chat")
- `params` - Query parameters object

**Returns:** Promise resolving to API response object (with validation that `ok=true`)

**Throws:** If API returns `ok=false` or HTTP error

---

#### `apiPost(action, body = {}): Promise<Object>`

**Purpose:** Make a POST request to the customer API.

**Parameters:**

- `action` - API action name (e.g., "send", "set_tags")
- `body` - Request body object (JSON-encoded)

**Returns:** Promise resolving to API response object

**Throws:** If API returns `ok=false` or HTTP error

---

#### DOM Cached References (`ui` object)

```javascript
const ui = {
  historyPanel, // History panel container
  historyList, // Chat history list
  panel, // Main chat panel
  body, // Message display area
  title, // Chat title
  sub, // Chat subtitle
  tagRow, // Tag selector buttons
  tagsWrap, // Entire tags section
  input, // Message input field
  sendBtn, // Send button
  resumeBtn, // Resume button (when minimized)
  badge, // Unread badge
  typingIndicator, // "Employee is typing" indicator
};
```

---

#### `setResumeVisible(visible): void`

**Purpose:** Show/hide the "Resume" pill button.

**Details:** Used when the panel is minimized and there are unread messages.

---

#### `setUnreadCount(n): void`

**Purpose:** Update the unread badge count and toggle visibility.

**Parameters:**

- `n` - Number of unread messages

**Details:** Shows the resume button if `n > 0`.

---

#### `setPanelOpen(open): void`

**Purpose:** Open or close the main chat panel.

**Parameters:**

- `open` - True to open, false to close

**Side Effects:**

- Toggles the "open" CSS class
- Focuses input if opened
- Clears unread count if opened

---

#### `closePanel()` / `openPanel()` / `togglePanel()`: Promise<void>

**Purpose:** Convenience wrappers for panel state.

**`openPanel()` behavior:**

- Ensures an active chat (create if needed)
- Resets chat if it was previously closed
- Loads the chat into the panel
- Refreshes chat history

---

#### `toggleHistory()`: void

**Purpose:** Toggle visibility of the chat history panel.

**Details:** Refreshes history list when opened.

---

#### `clearMessages()`: void

**Purpose:** Remove all message bubbles from the message pane.

---

#### `addMessage(m, { bumpUnread } = {}): void`

**Purpose:** Append a single message bubble to the panel.

**Parameters:**

- `m` - Message object from API (with `sender_type`, `body`, `created_at`, etc.)
- `bumpUnread` - If true and panel is closed, increment unread count

**Display Logic:**

- System messages labeled as "Corenio Bot" (or employee name if applicable)
- Employee messages show sender's username
- Customer messages show as "me" (right-aligned)
- Checkmark displayed if `employee_read_at` is set (for customer messages)

---

#### `escapeHtml(s): string`

**Purpose:** Minimal HTML escaping for safe innerHTML display.

**Details:** Creates a div, sets `textContent`, and returns the HTML.

---

#### `renderTags()`: void

**Purpose:** Re-render the tag selector buttons based on current selection.

**Behavior:**

- Creates clickable button for each available tag
- Highlights selected tags with "selected" CSS class
- Clicking a tag toggles its selection

---

#### `updateHeader()`: void

**Purpose:** Update the panel header (title + subtitle) with chat ID/status/tags.

---

#### `updateComposer()`: void

**Purpose:** Enable/disable the message input based on chat state.

**Disabled when:**

- No chat selected
- Chat is closed
- No tags selected (if tags are available)

**Placeholder text** changes based on state.

---

#### `startPolling()`: void

**Purpose:** Begin a polling loop for new messages (fallback when WebSocket unavailable).

**Interval:** 2 seconds

**Polling Actions:**

- Fetch new messages since `lastMessageId`
- Update chat status
- Add new messages to display
- Check employee typing status
- Handle 404 (chat deleted) by resetting state

---

#### `checkEmployeeTyping()`: Promise<void>

**Purpose:** Poll the API to check if an employee is typing.

**Behavior:**

- Calls `get_typing_status` endpoint
- Shows/hides the typing indicator based on result
- Called during regular polling

---

#### `ensureActiveChat()`: Promise<void>

**Purpose:** Ensure there is an "active" chat for this browser.

**Behavior:**

- Prompts for customer name (optional, stored in localStorage)
- Creates or reuses existing open/taken chat
- Populates state with chat, tags, and messages
- Hides tag selector if tags already exist
- Starts polling

---

#### `loadChat(chatId)`: Promise<void>

**Purpose:** Load an existing chat (from history) into the main panel.

**Behavior:**

- Fetches full chat with messages and tags
- Populates `state` and UI
- Updates header, composer, tags
- **Hides tag selector if tags already exist** (prevents re-selection after reopen/refresh)
- Calls `mark_read` to clear employee notification badges
- Starts polling

---

#### `saveTags()`: Promise<void>

**Purpose:** Persist currently selected tags to the server.

**Behavior:**

- Sends selected tag IDs via `set_tags` API
- Updates chat with returned tags
- **Hides tag selector** (user can't change tags after saving)
- Refreshes history
- Reloads chat to fetch bot messages added after tag save

---

#### `onSend(e)`: Promise<void>

**Purpose:** Handle message form submission.

**Behavior:**

- Clears "is typing" status
- Posts message via `send` API
- Appends message to UI
- Refreshes history

---

#### Input Typing Listener

**Purpose:** Monitor typing in the message input field.

**Behavior:**

- Sets `set_typing(true)` when user starts typing
- Clears after 3 seconds of inactivity
- Sends `set_typing(false)` when cleared

---

#### `refreshHistory()`: Promise<void>

**Purpose:** Fetch and render the chat history list.

**Behavior:**

- Calls `list_chats` API
- Renders history panel with chat items
- Each item clickable to open that chat
- Tracks resume button visibility

---

#### Bootstrap (IIFE)

**Purpose:** Initialize the widget on page load.

**Behavior:**

- Creates all DOM elements
- Attaches event listeners
- Fetches initial history (but doesn't create a new chat yet)
- Closes the panel by default

---

## Employee Dashboard (JavaScript)

### File: `assets/employee.js`

This is the employee-facing dashboard. Uses the Employee API exclusively.

---

#### HTTP Helpers: `apiGet(params)`, `apiPost(action, body)`

**Purpose:** Make API requests with error handling.

**Details:** Similar to the customer widget but with more detailed error messages and content-type validation.

---

#### DOM Cached References (`els` object)

```javascript
const els = {
  list: document.getElementById("chatList"), // Chat list
  title: document.getElementById("chatTitle"), // Chat title
  meta: document.getElementById("chatMeta"), // Chat metadata
  messages: document.getElementById("messages"), // Message pane
  globalBadge: document.getElementById("globalUnreadBadge"), // Global unread badge
  takeBtn,
  closeBtn,
  reopenBtn,
  deleteBtn, // Action buttons
  sendForm,
  msgInput,
  sendBtn, // Composer
  chips: Array.from(document.querySelectorAll(".chip")), // Status filter chips
};
```

---

#### Global Variables

```javascript
let currentStatusFilter = "all"; // Current filter for list
let selectedChatId = null; // Currently viewed chat
let selectedChat = null; // Chat object
let lastLoadedMessageId = 0; // For deduplication
let pollTimer = null; // setInterval ID
let unreadSinceId = 0; // For unread marker
let typingTimeoutId = null; // Typing timeout
let isCurrentlyTyping = false; // Typing flag
```

---

#### `fmtTime(iso): string`

**Purpose:** Format ISO datetime to readable string.

---

#### `escapeHtml(s): string`

**Purpose:** HTML escape for safe innerHTML.

---

#### `renderChatList(chats): void`

**Purpose:** Render (or re-render) the chat list in the left sidebar.

**Parameters:**

- `chats` - Array of chat objects from API

**Display:**

- Chat ID, customer name, last message preview
- Tags as chips
- Unread badge and NEW badge
- Status indicator
- Global unread count updated

---

#### `renderMessages(messages): void`

**Purpose:** Render all messages for the currently selected chat.

**Parameters:**

- `messages` - Array of message objects

**Behavior:**

- Clears current messages
- Appends each message via `addMessageToPane()`
- Scrolls to bottom

---

#### `addMessageToPane(m): void`

**Purpose:** Append a single message bubble to the message pane.

**Parameters:**

- `m` - Message object

**Display Logic:**

- Employee messages: Show as "me" (right-aligned), use `employee_username` as sender
- Customer messages: Show as "customer" with dot indicator
- System messages: Show as "System" or "Corenio Bot"
- NEW badge for unread customer messages
- Checkmark if customer has read the message (`customer_read_at` set)

---

#### `scrollToBottom()`: void

**Purpose:** Scroll message pane to bottom.

---

#### `updateControls()`: void

**Purpose:** Enable/disable action buttons based on chat state.

**Button States:**

- `takeBtn` - Enabled only for open, unassigned chats
- `closeBtn` - Enabled for assigned, non-closed chats
- `reopenBtn` - Enabled for closed, assigned chats
- `deleteBtn` - Enabled only for closed chats
- Composer - Enabled for assigned, non-closed chats

---

#### `refreshList()`: Promise<void>

**Purpose:** Fetch latest chats and update the sidebar.

**Behavior:**

- Calls `list_chats` with current status filter
- Renders chat list
- Updates global badge

---

#### `openChat(chatId)`: Promise<void>

**Purpose:** Load a chat into the main pane.

**Parameters:**

- `chatId` - Chat ID to load

**Behavior:**

- Fetches full chat with messages via `get_chat`
- Populates title, metadata, messages
- Updates controls
- Marks all messages as read by employee
- Starts polling for new messages
- Refreshes the chat list

---

#### `startPollingMessages()`: void

**Purpose:** Start polling loop for new messages.

**Interval:** 2 seconds

**Polling Actions:**

- Fetch new messages since `lastLoadedMessageId`
- Update chat status
- Check customer typing status
- Append new messages

---

#### `checkTypingStatus()`: Promise<void>

**Purpose:** Poll to check if customer is typing.

**Behavior:**

- Calls `get_typing_status` endpoint
- Shows/hides the typing indicator

---

#### `takeSelected()`: Promise<void>

**Purpose:** Claim the currently selected chat.

**Behavior:**

- Posts `take_chat` request
- Reloads the chat pane to show updated status

---

#### `closeSelected()`: Promise<void>

**Purpose:** Close the currently selected chat.

**Behavior:**

- Posts `close_chat` request
- Reloads the chat pane

---

#### `reopenSelected()`: Promise<void>

**Purpose:** Re-open the currently selected chat.

**Behavior:**

- Posts `reopen_chat` request
- Reloads the chat pane

---

#### `deleteSelected()`: Promise<void>

**Purpose:** Delete the currently selected (closed) chat.

**Behavior:**

- Prompts for confirmation
- Posts `delete_chat` request
- Clears selection and refreshes list

---

#### `sendMessage(body)`: Promise<void>

**Purpose:** Send a message as the current employee.

**Parameters:**

- `body` - Message text

**Behavior:**

- Posts `send` request
- Appends message to UI
- Clears typing flag
- Refreshes list

---

#### Event Listeners

- Action buttons (`take`, `close`, `reopen`, `delete`) trigger respective functions
- Message form submit calls `sendMessage()`
- Input typing listener sends `set_typing` with 3-second timeout
- Status filter chips update `currentStatusFilter` and refresh list

---

#### Bootstrap

**Purpose:** Initialize the dashboard.

**Behavior:**

- Calls `refreshList()` to populate initial chat list
- Sets up refresh interval (2.5 seconds)

---

## WebSocket Server

### File: `ws/ChatServer.php`

Optional real-time WebSocket server for the live chat. Implements `Ratchet\MessageComponentInterface`.

**Note:** The application works perfectly fine without this server using HTTP polling.

---

#### Class: `ChatServer`

**Properties:**

- `SplObjectStorage $clients` - All connected sockets with metadata
- `array $rooms` - In-memory rooms indexed by chat ID

---

#### `onOpen(ConnectionInterface $conn): void`

**Purpose:** Handle new WebSocket connection.

**Behavior:**

- Parses cookies to determine role (customer vs. employee)
- For employees: reads PHP session ID to resolve `lc_employee_id`
- Stores metadata: role, customer_token, employee_id, chat_id
- Sends "hello" message to client with their role

---

#### `onMessage(ConnectionInterface $from, $msg): void`

**Purpose:** Handle incoming WebSocket messages.

**Supported Message Types:**

- `send` - Send a new message to a chat
- `take_chat` - Employee claims a chat
- `close_chat` - Employee closes a chat
- `reopen_chat` - Employee re-opens a chat

**Behavior:**

- Parses JSON payload
- Validates permissions (via `assertCanAccessChat()`)
- Executes action (calls database helper methods)
- Broadcasts result to room

---

#### `onClose(ConnectionInterface $conn): void`

**Purpose:** Handle socket disconnection.

**Behavior:**

- Removes connection from any room it was in
- Detaches from `$clients` storage (frees memory)

---

#### `onError(ConnectionInterface $conn, Exception $e): void`

**Purpose:** Handle unhandled exceptions.

**Behavior:**

- Closes the socket (client can reconnect)

---

#### `switchRoom(ConnectionInterface $conn, array &$info, int $chatId): void`

**Purpose:** Move a connection to a new room, leaving old room.

**Parameters:**

- `$conn` - Connection to move
- `$info` - Client metadata (modified in-place)
- `$chatId` - New room chat ID

---

#### `joinRoom(ConnectionInterface $conn, int $chatId): void`

**Purpose:** Add a connection to a room.

**Behavior:**

- Creates room if needed
- Attaches connection to `$rooms[$chatId]`

---

#### `leaveRoom(ConnectionInterface $conn, int $chatId): void`

**Purpose:** Remove a connection from a room.

**Behavior:**

- Detaches connection
- Deletes room if empty (memory efficiency)

---

#### `broadcast(int $chatId, array $payload): void`

**Purpose:** Send a JSON message to all clients in a room.

**Parameters:**

- `$chatId` - Room ID
- `$payload` - Array to JSON-encode and send

**Behavior:**

- Iterates all connections in the room
- Sends JSON payload to each (ignores errors)

---

#### `send(ConnectionInterface $conn, array $payload): void`

**Purpose:** Send a JSON message to one connection.

**Parameters:**

- `$conn` - Target connection
- `$payload` - Array to JSON-encode

---

#### `parseCookies(ConnectionInterface $conn): array`

**Purpose:** Extract cookies from the HTTP upgrade request.

**Returns:** Associative array of cookie name => value pairs.

**Details:** Uses PSR-7 request interface via `$conn->httpRequest->getHeader('Cookie')`.

---

#### `resolveEmployeeIdFromSession(?string $phpSessId): ?int`

**Purpose:** Read a PHP session file directly to resolve employee ID.

**Parameters:**

- `$phpSessId` - PHP session ID from cookie

**Returns:** Employee ID if found, or null.

**Details:** Reads session file and extracts `lc_employee_id` from serialized data.

---

#### `requireEmployee(array $info): void`

**Purpose:** Guard that throws if the connection is not an authenticated employee.

**Throws:** RuntimeException if not an employee.

---

#### `assertCanAccessChat(array $info, int $chatId): void`

**Purpose:** Ensure the connection is allowed to access the given chat.

**Authorization Rules:**

- **Employees:** Can subscribe to any chat
- **Customers:** Can only access chats with their token

**Throws:** RuntimeException on permission denial.

---

#### `insertMessage(array $info, int $chatId, string $body): array`

**Purpose:** Insert a new message into the database with full validation.

**Parameters:**

- `$info` - Client metadata
- `$chatId` - Chat ID
- `$body` - Message text

**Returns:** Fresh message row from database.

**Validations:**

- Chat exists and not closed
- For employees: must be assigned to chat (admins bypass)
- For customers: chat must have at least one tag
- Message < 2000 chars

**Transaction:** Runs inside `lc_tx()` for atomicity.

---

#### `takeChat(int $employeeId, int $chatId): array`

**Purpose:** Mark a chat as taken by an employee.

**Returns:** Chat status update object: `{ "status": "taken", "assigned_employee_id": <int> }`

**Validations:**

- Chat exists and not closed
- If already assigned: admin bypass required

**Transaction:** Atomic via `lc_tx()`.

---

#### `closeChat(int $employeeId, int $chatId): array`

**Purpose:** Close a chat and append a system message.

**Returns:** Status update object.

**Validations:**

- Chat exists
- Must be assigned to chat (admin bypass)

**Side Effects:**

- Sets `status='closed'` and `closed_at=NOW()`
- Inserts system message

**Transaction:** Atomic via `lc_tx()`.

---

#### `reopenChat(int $employeeId, int $chatId): array`

**Purpose:** Re-open a closed chat.

**Returns:** Status update object: `{ "status": "taken", ... }`

**Validations:**

- Chat must be closed
- Must be assigned (admin bypass)

**Side Effects:**

- Sets `status='taken'` and clears `closed_at`
- Increments `reopened_count`
- Inserts system message

**Transaction:** Atomic via `lc_tx()`.

---

## Database Schema

### File: `db/schema.sql`

#### Table: `employees`

**Purpose:** Store employee accounts and permissions.

**Columns:**

- `id` (INT UNSIGNED, PK) - Employee ID
- `username` (VARCHAR 64, UNIQUE) - Login username
- `password_hash` (VARCHAR 255) - Bcrypt hash
- `display_name` (VARCHAR 128) - Human-readable name
- `is_active` (TINYINT, default 1) - Account enabled
- `is_admin` (TINYINT, default 0) - Admin privileges
- `can_grant_admin` (TINYINT, default 0) - Can modify is_admin for others
- `created_at` (TIMESTAMP) - Account creation time

**Indexes:** UNIQUE on `username`

**Seed:** Default admin account (username: `admin`, password: `admin123`, display: `Admin`)

---

#### Table: `tags`

**Purpose:** Store issue categories for chat routing.

**Columns:**

- `id` (INT UNSIGNED, PK) - Tag ID
- `name` (VARCHAR 64, UNIQUE) - Tag name (e.g., "Billing")
- `sort_order` (INT) - Display order
- `is_active` (TINYINT, default 1) - Tag available

**Seed:** Billing, Technical Issue, Account/Login, Sales, Other

---

#### Table: `chats`

**Purpose:** Store chat sessions.

**Columns:**

- `id` (BIGINT UNSIGNED, PK) - Chat ID
- `customer_token` (CHAR 36) - Customer UUID (FK-implicit, no constraint)
- `customer_name` (VARCHAR 128, nullable) - Customer's name
- `status` (ENUM) - 'open', 'taken', or 'closed'
- `assigned_employee_id` (INT UNSIGNED, nullable, FK) - Employee handling the chat
- `reopened_count` (INT, default 0) - Times re-opened
- `created_at` (TIMESTAMP) - Chat creation
- `updated_at` (TIMESTAMP, auto-update) - Last activity
- `closed_at` (TIMESTAMP, nullable) - Closure time

**Indexes:**

- `idx_chats_customer_token` on `customer_token`
- `idx_chats_status_updated` on `status`, `updated_at`
- `idx_chats_assigned` on `assigned_employee_id`

**Constraints:** Foreign key on `assigned_employee_id` with CASCADE/SET NULL

---

#### Table: `chat_tags`

**Purpose:** Junction table associating chats with tags (many-to-many).

**Columns:**

- `chat_id` (BIGINT UNSIGNED, FK, PK-part)
- `tag_id` (INT UNSIGNED, FK, PK-part)

**Constraints:** Composite PK on (chat_id, tag_id); FKs with CASCADE/RESTRICT

---

#### Table: `messages`

**Purpose:** Store all messages in chats.

**Columns:**

- `id` (BIGINT UNSIGNED, PK) - Message ID
- `chat_id` (BIGINT UNSIGNED, FK) - Associated chat
- `sender_type` (ENUM) - 'customer', 'employee', or 'system'
- `sender_employee_id` (INT UNSIGNED, nullable, FK) - Employee if applicable
- `body` (TEXT) - Message text
- `created_at` (TIMESTAMP) - Sent time
- `customer_read_at` (TIMESTAMP, nullable) - When customer read (added in conversation, not in schema.sql)
- `employee_read_at` (TIMESTAMP, nullable) - When employee read (added in conversation, not in schema.sql)

**Indexes:** Composite on `(chat_id, id)` for efficient polling

**Constraints:** FKs on `chat_id` and `sender_employee_id` with CASCADE/SET NULL

---

#### Table: `employee_chat_reads`

**Purpose:** Track which messages each employee has seen (for notification badges).

**Columns:**

- `employee_id` (INT UNSIGNED, FK, PK-part)
- `chat_id` (BIGINT UNSIGNED, FK, PK-part)
- `last_seen_message_id` (BIGINT UNSIGNED) - Max message ID seen
- `updated_at` (TIMESTAMP, auto-update)

**Constraints:** Composite PK; FKs with CASCADE

---

#### Runtime Helper Tables (created on demand)

##### Table: `employee_typing` (created by API code)

**Purpose:** Track which employees are typing.

**Columns:**

- `employee_id` (INT UNSIGNED, PK-part)
- `chat_id` (BIGINT UNSIGNED, PK-part)
- `last_seen_at` (TIMESTAMP) - When typing state was updated

**Details:** Customers poll this; rows auto-delete if older than 5 seconds during polling.

---

##### Table: `customer_typing` (created by API code)

**Purpose:** Track which customers are typing.

**Columns:**

- `chat_id` (BIGINT UNSIGNED, PK) - Chat ID
- `last_seen_at` (TIMESTAMP) - When typing state was updated

**Details:** Employees poll this; rows auto-delete if older than 5 seconds during polling.

---

## Summary

This Live Chat application is a multi-layered system:

1. **Database Layer** (`lib/db.php`): Manages PDO connections and transactions
2. **HTTP Layer** (`lib/http.php`): Request/response utilities
3. **Auth Layer** (`lib/employee_auth.php`): Session management for employees
4. **Customer Layer** (`lib/customer.php`): Customer token management
5. **Bootstrap** (`api/bootstrap.php`): Shared helpers and exceptions
6. **APIs** (`api/chat.php`, `api/employee.php`): RESTful endpoints for web clients
7. **Clients** (`assets/chat-widget.js`, `assets/employee.js`): JavaScript UIs
8. **WebSocket** (`ws/ChatServer.php`): Optional real-time server
9. **Schema** (`db/schema.sql`): Database tables and seed data

All components work together to provide:

- **Customer experience:** Browse chat history, select tags, message employees
- **Employee experience:** View chat queue, claim chats, send messages, close/reopen chats
- **Real-time features:** Optional WebSockets with HTTP polling fallback for messages, typing indicators, and read receipts
- **Admin features:** Admins bypass assignment checks and can manage any chat
- **Tag-based routing:** Customers must select tags before messaging; system messages inform them when employees are/aren't available
