# employee/register.php

## Purpose
- Employee registration page and registration processing.

## File Type
- Extension: `.php`
- Location: `employee/register.php`

## Header/Top Context
```text
<?php
declare(strict_types=1);

// Apply security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Employee registration page.
// On success we redirect to `login.php`.
require_once __DIR__ . '/../lib/employee_auth.php';
```

## Related Files
- Works with `assets/employee.js`, `assets/employee.css`, and `api/employee.php`.
