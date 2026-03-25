# lib/employee_auth.php

## Purpose
- Employee auth/session/CSRF/rate-limit helper functions.

## File Type
- Extension: `.php`
- Location: `lib/employee_auth.php`

## Key Symbols
- `function lc_employee_session_start(): void {`
- `function lc_employee_id(): ?int {`
- `function lc_require_employee(): int {`
- `function lc_is_rate_limited(string $identifier): bool {`
- `function lc_record_failed_attempt(string $identifier): void {`
- `function lc_clear_rate_limit(string $identifier): void {`
- `function lc_generate_csrf_token(): string {`
- `function lc_validate_csrf_token(string $token): bool {`
- `function lc_get_rate_limit_message(string $identifier): string {`
- `function lc_employee_login(string $username, string $password): bool {`
- `function lc_employee_logout(): void {`
- `function lc_employee_register(string $username, string $displayName, string $password): array {`

## Header/Top Context
```text
<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/http.php';

// Rate limiting configuration
define('LC_LOGIN_RATE_LIMIT_MAX_ATTEMPTS', 5);
define('LC_LOGIN_RATE_LIMIT_WINDOW_SECONDS', 300); // 5 minutes

/**
 * Start a PHP session for employee authentication (if not already active).
```

## Related Files
- Shared by API and page-level PHP files.
