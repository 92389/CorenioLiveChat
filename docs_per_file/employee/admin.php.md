# employee/admin.php

## Purpose
- Admin management page for employee permissions and channel access.

## File Type
- Extension: `.php`
- Location: `employee/admin.php`

## Key Symbols
- `function lc_channel_label(string $channel): string {`
- `function toggleEmployee(event, employeeId) {`
- `const form = document.getElementById('adminForm');`
- `const input = document.createElement('input');`

## Header/Top Context
```text
<?php
declare(strict_types=1);

// Apply security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Simple admin page that allows the primary admin account to grant
// or revoke `is_admin` permissions for other employee accounts.

```

## Related Files
- Works with `assets/employee.js`, `assets/employee.css`, and `api/employee.php`.
