<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

require_once __DIR__ . '/lib/db.php';

use Ratchet\App;
use LcWs\ChatServer;

$host = '0.0.0.0';
$port = 8080;

echo "Live Chat WebSocket server starting on ws://$host:$port\n";

$app = new App($host, $port, '0.0.0.0');

$app->route('/', new ChatServer(), ['*']);

$app->run();

