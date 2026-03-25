# Live Chat Function Reference Documentation

**Last Updated:** March 23, 2026

## 1) Purpose of This Document

This file is the **function-focused companion** to `PROJECT_DOCUMENTATION.md`.

It explains what the main first-party PHP and JavaScript files do, with emphasis on:

- exported/important functions and methods,
- responsibilities of each module,
- where key logic lives.

---

## 2) Scope

- Included: first-party application code (`api/`, `lib/`, `employee/`, `assets/`, `ws/`, root entry files).
- Excluded: third-party dependency internals under `vendor/`.

---

## 3) Core PHP Utility Layer

### 3.1 `lib/db.php`

**Primary role:** configuration loading and database access bootstrap.

**Key functions:**

- `lc_config()` — loads and caches app config from `config/config.php`.
- `lc_pdo()` — builds/reuses the shared PDO instance.
- `lc_tx(callable $fn)` — transaction wrapper with rollback on failure.

### 3.2 `lib/http.php`

**Primary role:** standardized HTTP/JSON behavior for API endpoints.

**Key functions:**

- `lc_json_response(array $payload, int $status = 200)` — sends JSON response and exits.
- `lc_require_method(string $method)` — enforces request method.
- `lc_read_json_body()` — parses JSON request body.
- `lc_get_int(...)`, `lc_get_str(...)` — typed query parameter helpers.

### 3.3 `lib/customer.php`

**Primary role:** customer identity token generation and persistence.

**Key functions:**

- `lc_uuid_v4()` — creates UUIDv4 token values.
- `lc_customer_token()` — reads/creates persistent customer cookie token.

### 3.4 `lib/employee_auth.php`

**Primary role:** employee authentication/session/CSRF/rate-limiting logic.

**Key functions:**

- `lc_employee_session_start()` — starts secure session context.
- `lc_employee_id()` — resolves current authenticated employee ID.
- `lc_require_employee()` — guards endpoints requiring auth.
- `lc_employee_login(...)` / `lc_employee_logout()` / `lc_employee_register(...)` — auth lifecycle.
- `lc_generate_csrf_token()` / `lc_validate_csrf_token(...)` — CSRF protection.
- rate-limit helpers for login attempt control.

---

## 4) API Layer

### 4.1 `api/bootstrap.php`

**Primary role:** shared API bootstrap and helper library used by both API routers.

**Important contents:**

- security headers and centralized exception handling
- `LcHttpException` and `lc_abort(...)`
- shared read/query helpers for tags/messages/admin checks
- file handling helpers:
  - ensure table/directories,
  - validate upload constraints,
  - sanitize names,
  - detect MIME,
  - output file downloads.

### 4.2 `api/chat.php`

**Primary role:** customer-facing API action router.

**Main action families:**

- configuration/bootstrap for widget
- chat creation/loading/listing
- polling and sending messages
- typing-state updates
- file upload/download endpoints.

### 4.3 `api/employee.php`

**Primary role:** employee-facing API action router.

**Main action families:**

- dashboard configuration and chat lists
- message polling/sending
- take/close/reopen/delete chat operations
- typing-state updates
- file upload/download endpoints
- role/channel access checks and admin-aware behavior.

---

## 5) Employee Web Pages (PHP)

### 5.1 `employee/index.php`

- Dashboard shell page.
- Loads employee context values for `assets/employee.js`.

### 5.2 `employee/login.php`

- Login form + POST handling.
- Calls auth helpers and performs CSRF validation.

### 5.3 `employee/logout.php`

- Calls logout helper and redirects to login.

### 5.4 `employee/register.php`

- Registration form + validation + account creation path.

### 5.5 `employee/admin.php`

- Admin permission management page.
- Handles admin/channel permission controls for employees.

### 5.6 `employee/chats.php`

- Administrative chat-management page.
- Focused on chat oversight/management operations.

### 5.7 `employee/admin_nav.php`

- Shared admin navigation include/partial.

---

## 6) Frontend JavaScript Layer

### 6.1 `assets/chat-widget.js`

**Primary role:** customer widget runtime.

**Key function groups:**

- DOM creation and widget UI state management
- API request helpers (`apiGet`, `apiPost`, `apiPostForm`)
- history/polling and message rendering
- typing indicator handling
- file upload and inline file/image rendering
- widget controls (open/minimize/history/resume).

### 6.2 `assets/employee.js`

**Primary role:** employee dashboard runtime.

**Key function groups:**

- API request helpers and error normalization
- chat list rendering and selection logic
- message pane rendering and updates
- control/button state logic based on permission/chat status
- polling + typing indicator flows
- file upload and inline file display.

---

## 7) WebSocket Layer

### 7.1 `ws/ChatServer.php`

**Primary role:** WebSocket message component (Ratchet).

**Key methods:**

- `onOpen`, `onMessage`, `onClose`, `onError`
- room membership management (join/leave/switch)
- message persistence + broadcast coordination
- role/session-based access checks for socket actions.

### 7.2 `ws-server.php`

**Primary role:** WebSocket process entrypoint.

**Responsibilities:**

- boot Composer autoload
- instantiate Ratchet app
- route `/` to `LcWs\ChatServer`
- run server loop.

---

## 8) Root Entry and Diagnostics Files

### 8.1 `index.php`

- Minimal host page embedding customer widget assets.

### 8.2 `debug.php`

- Manual diagnostics for DB/setup validation.

---

## 9) Related Non-Code Files Relevant to Functionality

- `config/config.php` — runtime configuration source.
- `db/schema.sql` — canonical SQL schema used for setup.

---

## 10) How to Use This Function Reference

- Use this file to quickly locate **where functionality is implemented**.
- Use `PROJECT_DOCUMENTATION.md` for high-level architecture/setup.
- Use `docs_per_file/README.md` for per-file drill-down documents.

---

## 11) Quick Function Ownership Map

- **DB/Config primitives:** `lib/db.php`
- **HTTP response/request primitives:** `lib/http.php`
- **Customer identity:** `lib/customer.php`
- **Employee auth/security:** `lib/employee_auth.php`
- **Shared API helper logic:** `api/bootstrap.php`
- **Customer API actions:** `api/chat.php`
- **Employee API actions:** `api/employee.php`
- **Customer UI runtime:** `assets/chat-widget.js`
- **Employee UI runtime:** `assets/employee.js`
- **Realtime socket logic:** `ws/ChatServer.php` + `ws-server.php`# Live Chat Code File Functions Reference

This document explains what each **first-party** PHP and JavaScript file does in this project.

## Scope

- Included: all app-owned `.php` and `.js` files in this repository.
- Excluded: `vendor/` third-party dependency source files.

---

## Root Files

### `index.php`

- Demo/embedding page for the customer widget.
- Loads `assets/chat.css` and `assets/chat-widget.js`.
- Serves as a simple example host page for the website chat button/panel.

### `debug.php`

- Manual diagnostics page.
- Checks DB connectivity, schema/table/column availability, and other setup assumptions.
- Useful during installation or when dashboard/API issues occur.

### `ws-server.php`

- WebSocket server bootstrap/entry point.
- Loads Composer autoloading and DB helpers.
- Starts Ratchet app and mounts `LcWs\ChatServer` on `/`.

---

## API Layer (`api/`)

### `api/bootstrap.php`

- Shared API bootstrap and helper layer for both customer and employee APIs.
- Sets security headers and centralized exception handling.
- Provides reusable helpers for:
  - tag/message fetching,
  - admin checks,
  - file upload constraints and sanitization,
  - file storage + download streaming,
  - ensuring `message_files` table exists.

### `api/chat.php`

- **Customer-facing API router** (`?action=` pattern).
- Handles customer chat lifecycle and messaging actions (config, create/open chat, polling, send, typing, etc.).
- Handles customer file upload/download for chat messages.
- Uses customer cookie token to scope access to the correct customer chats.

### `api/employee.php`

- **Employee-facing API router** (`?action=` pattern).
- Requires authenticated employee session for all actions.
- Powers dashboard operations: chat listing/filtering, assignment/take/close/reopen/delete flows, message polling/sending, typing updates.
- Implements file upload/download handling for employee-side chat usage.
- Enforces role/channel access logic (admin and channel permissions).

---

## Library Helpers (`lib/`)

### `lib/db.php`

- Core config + database access helper file.
- Loads config via `lc_config()`.
- Creates shared PDO connection via `lc_pdo()`.
- Provides transaction wrapper `lc_tx()`.

### `lib/http.php`

- HTTP utility helpers used by API endpoints.
- Sends standardized JSON responses.
- Validates request methods.
- Reads JSON request body.
- Provides small typed query-param helpers (`lc_get_int`, `lc_get_str`).

### `lib/customer.php`

- Customer identity helpers.
- Generates UUID v4 customer identifiers.
- Retrieves or issues persistent customer cookie token (`lc_customer_token`).

### `lib/employee_auth.php`

- Employee authentication/session/security helper library.
- Handles session start and current employee resolution.
- Enforces authenticated access for protected endpoints.
- Contains login/logout/register helpers.
- Provides CSRF token generation/validation.
- Includes simple login rate-limiting support.

---

## Employee Pages (`employee/`)

### `employee/index.php`

- Main employee dashboard shell (HTML structure only).
- Checks login/session and fetches employee/admin context.
- Injects runtime globals used by `assets/employee.js`.

### `employee/login.php`

- Employee login page + form processing.
- Validates CSRF and credentials.
- Redirects authenticated users to dashboard.

### `employee/logout.php`

- Logs out current employee session and redirects to login page.

### `employee/register.php`

- Employee registration page + form processing.
- Validates CSRF and input rules, then creates employee account.

### `employee/admin.php`

- Admin permissions page for employee management.
- Restricted to admin users.
- Manages elevated permissions and channel access assignments.

### `employee/chats.php`

- Admin chat-overview/management page.
- Focused on reviewing and managing chat records (including closed/deleted management flows).

### `employee/admin_nav.php`

- Shared admin navigation partial.
- Renders links for admin pages and dashboard return.

---

## Frontend JavaScript (`assets/`)

### `assets/chat-widget.js`

- Customer widget client runtime.
- Builds and controls floating chat UI (open/minimize/history/resume).
- Calls `api/chat.php` for config, chat creation/loading, messages, typing, file upload, and history.
- Renders text and file messages (including inline image previews where applicable).
- Uses polling fallback logic (and optional WS behavior where available).

### `assets/employee.js`

- Employee dashboard client runtime.
- Calls `api/employee.php` for chat list, message stream, assignment/status actions, typing state, and file upload.
- Renders chat list and message pane, controls action buttons, and applies channel/theme/access behaviors.
- Handles inline file rendering and dashboard interaction state.

---

## Config (`config/`)

### `config/config.php`

- Central app configuration source.
- Defines DB connection settings.
- Defines customer cookie key and WebSocket URL.

---

## WebSocket Core (`ws/`)

### `ws/ChatServer.php`

- **Core WebSocket message component class** for realtime chat.
- Tracks connected clients and per-chat rooms.
- Handles socket lifecycle (`onOpen`, `onMessage`, `onClose`, `onError`).
- Validates/authenticates sender context from cookies/session.
- Processes realtime actions (send/take/close/reopen) and persists through DB helpers.
- Broadcasts message/chat updates to relevant subscribers in the same chat room.
- Complements polling-based APIs (realtime enhancement; app can still function without WS).

---

## Quick Architecture Summary

- `lib/` = shared primitives (DB/auth/http/customer identity).
- `api/` = HTTP business/action layer.
- `employee/*.php` + `assets/employee.js` = employee/admin UI.
- `index.php` + `assets/chat-widget.js` = customer UI.
- `ws-server.php` + `ws/ChatServer.php` = realtime WebSocket path.
