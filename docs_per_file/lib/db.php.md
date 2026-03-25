# lib/db.php

## Purpose
- Database/config helpers, PDO creation, and transaction wrapper.

## File Type
- Extension: `.php`
- Location: `lib/db.php`

## Key Symbols
- `function lc_config(): array {`
- `function lc_pdo(): PDO {`
- `function lc_tx(callable $fn) {`

## Header/Top Context
```text
<?php
declare(strict_types=1);

/**
 * Load application configuration (DB credentials, cookie name, ws_url, ...).
 *
 * The underlying PHP file is only loaded once; subsequent calls reuse the
 * cached array stored in a static variable.
 *
 * @return array<string,mixed>
 */
function lc_config(): array {
```

## Related Files
- Shared by API and page-level PHP files.
