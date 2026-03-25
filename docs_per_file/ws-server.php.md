# ws-server.php

## Purpose
- WebSocket server entrypoint that boots Ratchet and mounts ChatServer.

## File Type
- Extension: `.php`
- Location: `ws-server.php`

## Header/Top Context
```text
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

require_once __DIR__ . '/lib/db.php';

use Ratchet\App;
use LcWs\ChatServer;

$host = '0.0.0.0';
$port = 8080;
```

## Related Files
- Realtime stack; complements polling APIs.
