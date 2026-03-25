# employee/index.php

## Purpose
- Employee dashboard shell page and runtime bootstrap values.

## File Type
- Extension: `.php`
- Location: `employee/index.php`

## Key Symbols
- `// and allows the dashboard to still function with basic features while showing the employee's name,`

## Header/Top Context
```text
<?php
declare(strict_types=1);

// Apply security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Employee dashboard shell page.
// The HTML here just renders the layout; all dynamic behaviour is implemented
// in `assets/employee.js` which calls the employee API endpoints.
```

## Related Files
- Works with `assets/employee.js`, `assets/employee.css`, and `api/employee.php`.
