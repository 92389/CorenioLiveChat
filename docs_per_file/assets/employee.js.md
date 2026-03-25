# assets/employee.js

## Purpose
- Employee dashboard frontend runtime (chat list, messages, controls, polling).

## File Type
- Extension: `.js`
- Location: `assets/employee.js`

## Key Symbols
- `const API = window.LC_EMPLOYEE_API;`
- `const CURRENT_EMPLOYEE_ID = Number(window.LC_EMPLOYEE_ID || 0);`
- `const IS_ADMIN_EMPLOYEE = !!window.LC_EMPLOYEE_IS_ADMIN;`
- `async function apiGet(params) {`
- `const url = `${API}?${new URLSearchParams(params)}`;`
- `const res = await fetch(url, { credentials: "same-origin" });`
- `const contentType = res.headers.get('content-type');`
- `const data = await res.json();`
- `async function apiPost(action, body) {`
- `const res = await fetch(`${API}?action=${encodeURIComponent(action)}`, {`
- `const contentType = res.headers.get('content-type');`
- `const data = await res.json();`

## Header/Top Context
```text
// Employee dashboard client logic.
// This script renders and keeps the chat list, message pane and badges
// in sync with the employee + customer APIs, and optionally uses WebSockets
// for realtime updates (with a polling fallback).
const API = window.LC_EMPLOYEE_API;
const CURRENT_EMPLOYEE_ID = Number(window.LC_EMPLOYEE_ID || 0);
const IS_ADMIN_EMPLOYEE = !!window.LC_EMPLOYEE_IS_ADMIN;
 
// ----------------------------- HTTP helpers ---------------------------------
async function apiGet(params) {
  const url = `${API}?${new URLSearchParams(params)}`;
  try {
```

## Related Files
- Consumed by UI pages in root `index.php` or `employee/index.php` and backed by `api/` endpoints.
