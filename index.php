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
// - `<script src="assets/chat-widget.js"></script>`
// Those two lines are what you would copy into your own site to embed the widget.
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Live Chat Demo Page</title>
    <link rel="stylesheet" href="assets/demo.css" />
    <link rel="stylesheet" href="assets/chat.css" />
  </head>
  <body>
    

    <script>
      // Optional override if you move paths:
      // window.LC_CHAT_API = "/your/path/api/chat.php";
    </script>
    <script src="assets/chat-widget.js"></script>
  </body>
</html>
