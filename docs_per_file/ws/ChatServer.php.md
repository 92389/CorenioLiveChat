# ws/ChatServer.php

## Purpose
- WebSocket server component for realtime chat events and room broadcasting.

## File Type
- Extension: `.php`
- Location: `ws/ChatServer.php`

## Key Symbols
- `* This class manages room subscriptions per chat id, persists messages, and`
- `final class ChatServer implements MessageComponentInterface {`
- `public function __construct() {`
- `public function onOpen(ConnectionInterface $conn): void {`
- `public function onMessage(ConnectionInterface $from, $msg): void {`
- `public function onClose(ConnectionInterface $conn): void {`
- `public function onError(ConnectionInterface $conn, \Exception $e): void {`
- `private function switchRoom(ConnectionInterface $conn, array &$info, int $chatId): void {`
- `private function joinRoom(ConnectionInterface $conn, int $chatId): void {`
- `private function leaveRoom(ConnectionInterface $conn, int $chatId): void {`
- `private function broadcast(int $chatId, array $payload): void {`
- `private function send(ConnectionInterface $conn, array $payload): void {`

## Header/Top Context
```text
<?php
declare(strict_types=1);

namespace LcWs;

use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;
use SplObjectStorage;
use Throwable;

/**
 * WebSocket chat server for the Live Chat app.
```

## Related Files
- Realtime stack; complements polling APIs.
