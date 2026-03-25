# api/chat.php

## Purpose
- Customer-facing API router handling chat lifecycle, messaging, polling, and files.

## File Type
- Extension: `.php`
- Location: `api/chat.php`

## Header/Top Context
```text
<?php
declare(strict_types=1);

/**
 * Customer-facing chat API.
 *
 * This endpoint is used exclusively by the public chat widget
 * (`assets/chat-widget.js`). Every request is routed via the `action` query
 * parameter and is automatically scoped to the current browser's
 * `lc_customer` cookie (managed by `lc_customer_token()`).
 */
require_once __DIR__ . '/bootstrap.php';
```

## Related Files
- Often used with files under `lib/` and frontend scripts in `assets/`.
