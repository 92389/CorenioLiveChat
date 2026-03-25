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
    'name' => 'live_chat',
    'user' => 'root',
    'pass' => 'root',
    'charset' => 'utf8mb4',
  ],

  // Cookie name for identifying a customer across page loads.
  'customer_cookie' => 'lc_customer',

  // WebSocket server URL used by the browser client.
  // If you run the ws server on the same machine, this is usually fine.
  'ws_url' => 'ws://127.0.0.1:8080',
];

