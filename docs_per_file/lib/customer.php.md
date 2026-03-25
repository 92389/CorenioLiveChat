# lib/customer.php

## Purpose
- Customer identity and cookie-token helper functions.

## File Type
- Extension: `.php`
- Location: `lib/customer.php`

## Key Symbols
- `function lc_uuid_v4(): string {`
- `function lc_customer_token(): string {`

## Header/Top Context
```text
<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Generate a random RFC 4122 version 4 UUID string.
 */
function lc_uuid_v4(): string {
  $data = random_bytes(16);
  $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
  $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
```

## Related Files
- Shared by API and page-level PHP files.
