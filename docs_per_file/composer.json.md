# composer.json

## Purpose
- Composer package manifest listing project dependencies and autoloading.

## File Type
- Extension: `.json`
- Location: `composer.json`

## Header/Top Context
```text
{
  "name": "local/live-chat",
  "description": "Live chat widget + employee dashboard (PHP + MySQL + WebSockets)",
  "type": "project",
  "require": {
    "cboden/ratchet": "^0.4"
  },
  "autoload": {
    "psr-4": {
      "LcWs\\": "ws/"
    }
  }
```

## Related Files
- Refer to `PROJECT_DOCUMENTATION.md` and `FILE_FUNCTIONS_DOCUMENTATION.md` for system overview.
