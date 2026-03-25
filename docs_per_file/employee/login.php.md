# employee/login.php

## Purpose
- Employee login page and login form processing.

## File Type
- Extension: `.php`
- Location: `employee/login.php`

## Header/Top Context
```text
<?php
declare(strict_types=1);

// Apply security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Simple form-based login page for employees.
// On success we redirect to `employee/index.php`, which hosts the dashboard.
require_once __DIR__ . '/../lib/employee_auth.php';
```

## Related Files
- Works with `assets/employee.js`, `assets/employee.css`, and `api/employee.php`.
