# employee/chats.php

## Purpose
- Admin chat overview/management page.

## File Type
- Extension: `.php`
- Location: `employee/chats.php`

## Key Symbols
- `const selectAll = document.getElementById('selectAllclosed');`

## Header/Top Context
```text
<?php
declare(strict_types=1);

// Apply security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Chat management admin page - allows deleting closed chats and viewing chat history. 

require_once __DIR__ . '/../lib/employee_auth.php';
```

## Related Files
- Works with `assets/employee.js`, `assets/employee.css`, and `api/employee.php`.
