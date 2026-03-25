# config/config.php

## Purpose
- Application configuration values (DB, cookies, WebSocket URL).

## File Type
- Extension: `.php`
- Location: `config/config.php`

## Header/Top Context
```text
<?php
declare(strict_types=1);

// Application-level configuration for the Live Chat demo.
//
// Copy this file and adjust the values to match your environment.
// IMPORTANT: never commit real production credentials to a public repository.

return [
  'db' => [
    'host' => '127.0.0.1',
    'port' => 3306,
```

## Related Files
- Refer to `PROJECT_DOCUMENTATION.md` and `FILE_FUNCTIONS_DOCUMENTATION.md` for system overview.
