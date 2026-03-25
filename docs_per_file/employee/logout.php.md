# employee/logout.php

## Purpose
- Employee logout endpoint that ends session and redirects.

## File Type
- Extension: `.php`
- Location: `employee/logout.php`

## Header/Top Context
```text
<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/employee_auth.php';

// Clear the employee session and send them back to the login screen.
lc_employee_logout();
header('Location: login.php');
exit;

```

## Related Files
- Works with `assets/employee.js`, `assets/employee.css`, and `api/employee.php`.
