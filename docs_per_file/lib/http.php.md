# lib/http.php

## Purpose
- HTTP/JSON response helpers and request parsing utilities.

## File Type
- Extension: `.php`
- Location: `lib/http.php`

## Key Symbols
- `function lc_json_response(array $payload, int $status = 200): void {`
- `function lc_require_method(string $method): void {`
- `function lc_read_json_body(): array {`
- `function lc_get_int(string $key, int $default = 0): int {`
- `function lc_get_str(string $key, string $default = ''): string {`

## Header/Top Context
```text
<?php
declare(strict_types=1);

/**
 * Emit a JSON HTTP response and terminate the current script.
 *
 * All API endpoints in this project use a consistent envelope:
 *   { "ok": true|false, ... }
 */
function lc_json_response(array $payload, int $status = 200): void {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
```

## Related Files
- Shared by API and page-level PHP files.
