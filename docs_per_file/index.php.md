# index.php

## Purpose
- Demo host page that embeds the customer chat widget.

## File Type
- Extension: `.php`
- Location: `index.php`

## Header/Top Context
```text
<?php
declare(strict_types=1);

// Apply security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Simple landing page used for local testing of the customer widget.
// The important bits here are:
// - `<link rel="stylesheet" href="assets/chat.css" />`
```

## Related Files
- Refer to `PROJECT_DOCUMENTATION.md` and `FILE_FUNCTIONS_DOCUMENTATION.md` for system overview.
