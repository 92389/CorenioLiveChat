# api/employee.php

## Purpose
- Employee-facing API router handling dashboard actions, chat operations, permissions, and files.

## File Type
- Extension: `.php`
- Location: `api/employee.php`

## Key Symbols
- `function lc_employee_has_channel_access(PDO $pdo, int $employeeId, string $sourceChannel, bool $canAccessAllChannels = false): bool {`
- `function lc_employee_channel_access_map(PDO $pdo, int $employeeId): array {`
- `function lc_employee_display_name(PDO $pdo, int $employeeId): string {`

## Header/Top Context
```text
<?php
declare(strict_types=1);

/**
 * Employee-facing chat API.
 *
 * This endpoint powers the employee dashboard (`assets/employee.js`). Like the
 * customer API, it uses a simple `?action=` router, but all calls are guarded
 * by `lc_require_employee()` which enforces a valid logged-in session.
 */
require_once __DIR__ . '/bootstrap.php';

```

## Related Files
- Often used with files under `lib/` and frontend scripts in `assets/`.
