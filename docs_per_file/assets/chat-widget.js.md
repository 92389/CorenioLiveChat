# assets/chat-widget.js

## Purpose
- Customer chat widget frontend runtime (UI, polling, send/receive, file UI).

## File Type
- Extension: `.js`
- Location: `assets/chat-widget.js`

## Key Symbols
- `const API =`
- `const state = {`
- `function el(tag, attrs = {}, children = []) {`
- `const n = document.createElement(tag);`
- `function fmtTime(iso) {`
- `async function apiGet(action, params = {}) {`
- `const sp = new URLSearchParams({ action, ...params });`
- `const res = await fetch(`${API}?${sp.toString()}`, { credentials: "same-origin" });`
- `const data = await res.json();`
- `const err = new Error(data.error || "Request failed");`
- `async function apiPost(action, body = {}) {`
- `const res = await fetch(`${API}?action=${encodeURIComponent(action)}`, {`

## Header/Top Context
```text
// Customer chat widget bootstrap script.
// This script is standalone: it renders the floating button, panel UI,
// talks to the PHP customer API, and optionally connects to the WebSocket
// server for realtime updates (falling back to HTTP polling).
(() => {
  // Base URL for the customer API. Can be overridden via window.LC_CHAT_API
  // before this script is loaded.
  const API =
    window.LC_CHAT_API ||
    new URL("../api/chat.php", document.currentScript?.src || window.location.href).toString();

  // In-memory widget state (current chat, tags, unread counts, etc).
```

## Related Files
- Consumed by UI pages in root `index.php` or `employee/index.php` and backed by `api/` endpoints.
