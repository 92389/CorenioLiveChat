<?php
declare(strict_types=1);

namespace LcWs;

use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;
use SplObjectStorage;
use Throwable;

/**
 * WebSocket chat server for the Live Chat app.
 *
 * The WebSocket server is optional; HTTP polling still works without it.
 * This class manages room subscriptions per chat id, persists messages, and
 * broadcasts message/chat updates to connected clients.
 */
final class ChatServer implements MessageComponentInterface {
  /** @var SplObjectStorage<ConnectionInterface, array> */
  private SplObjectStorage $clients;

  /** @var array<int, SplObjectStorage<ConnectionInterface, null>> */
  private array $rooms = [];

  public function __construct() {
    $this->clients = new SplObjectStorage();
  }

  /** Called by Ratchet when a new WebSocket connection is opened. */
  public function onOpen(ConnectionInterface $conn): void {
    $cookies = $this->parseCookies($conn);
    $customerToken = $cookies['lc_customer'] ?? null;
    $employeeId = $this->resolveEmployeeIdFromSession($cookies['PHPSESSID'] ?? null);

    $role = $employeeId ? 'employee' : 'customer';

    $this->clients->attach($conn, [
      'role' => $role,
      'customer_token' => is_string($customerToken) ? strtolower($customerToken) : null,
      'employee_id' => $employeeId,
      'chat_id' => null,
    ]);

    $this->send($conn, ['type' => 'hello', 'role' => $role]);
  }

  /** Handle an incoming message from a client. */
  public function onMessage(ConnectionInterface $from, $msg): void {
    try {
      $data = json_decode((string)$msg, true);
      if (!is_array($data) || !isset($data['type'])) return;
      $type = (string)$data['type'];
      $info = $this->clients[$from] ?? null;
      if (!is_array($info)) return;

      if ($type === 'send') {
        $chatId = isset($data['chat_id']) ? (int)$data['chat_id'] : 0;
        $body = isset($data['body']) && is_string($data['body']) ? trim($data['body']) : '';
        if ($chatId <= 0 || $body === '') throw new \RuntimeException('Invalid send');
        if (mb_strlen($body) > 2000) throw new \RuntimeException('Message too long');

        $tagCheck = $pdo->prepare("SELECT COUNT(*) AS cnt FROM chat_tags WHERE chat_id = ?");
        $tagCheck->execute([$chatId]);
        $tagRow = $tagCheck->fetch();
        $tagCount = $tagRow ? (int)$tagRow['cnt'] : 0;
        if ($tagCount === 0) {
          throw new \RuntimeException('Tags required');
        }
        $this->assertCanAccessChat($info, $chatId);
        $this->switchRoom($from, $info, $chatId);
        $message = $this->insertMessage($info, $chatId, $body);
        $this->broadcast($chatId, ['type' => 'message', 'message' => $message]);
        return;
      }

      if ($type === 'take_chat') {
        $this->requireEmployee($info);
        $chatId = isset($data['chat_id']) ? (int)$data['chat_id'] : 0;
        if ($chatId <= 0) throw new \RuntimeException('Invalid chat_id');
        $this->switchRoom($from, $info, $chatId);
        $res = $this->takeChat((int)$info['employee_id'], $chatId);
        $this->broadcast($chatId, ['type' => 'chat_update', 'chat_id' => $chatId, 'status' => $res['status'], 'assigned_employee_id' => $res['assigned_employee_id']]);
        return;
      }

      if ($type === 'close_chat') {
        $this->requireEmployee($info);
        $chatId = isset($data['chat_id']) ? (int)$data['chat_id'] : 0;
        if ($chatId <= 0) throw new \RuntimeException('Invalid chat_id');
        $this->switchRoom($from, $info, $chatId);
        $res = $this->closeChat((int)$info['employee_id'], $chatId);
        $this->broadcast($chatId, ['type' => 'chat_update', 'chat_id' => $chatId, 'status' => $res['status'], 'assigned_employee_id' => $res['assigned_employee_id']]);
        return;
      }

      if ($type === 'reopen_chat') {
        $this->requireEmployee($info);
        $chatId = isset($data['chat_id']) ? (int)$data['chat_id'] : 0;
        if ($chatId <= 0) throw new \RuntimeException('Invalid chat_id');
        $this->switchRoom($from, $info, $chatId);
        $res = $this->reopenChat((int)$info['employee_id'], $chatId);
        $this->broadcast($chatId, ['type' => 'chat_update', 'chat_id' => $chatId, 'status' => $res['status'], 'assigned_employee_id' => $res['assigned_employee_id']]);
        return;
      }
    } catch (Throwable $e) {
      $this->send($from, ['type' => 'error', 'message' => 'Request failed']);
    }
  }

  /**
   * Called when a socket disconnects (either side closes the connection).
   * 
   * We make sure to:
   * - remove the connection from any room it was part of
   * - detach it from the global `clients` storage to free memory
   */
  public function onClose(ConnectionInterface $conn): void {
    $info = $this->clients[$conn] ?? null;
    if (is_array($info) && isset($info['chat_id']) && is_int($info['chat_id'])) {
      $this->leaveRoom($conn, (int)$info['chat_id']);
    }
    if ($this->clients->contains($conn)) $this->clients->detach($conn);
  }

  /**
   * Called by Ratchet when an unhandled exception bubbles out of the stack.
   * We simply close the socket; the client can decide whether to reconnect.
   */
  public function onError(ConnectionInterface $conn, \Exception $e): void {
    try { $conn->close(); } catch (Throwable) {}
  }

  /**
   * Move a connection to the room for the given chat id, leaving any previous room.
   */
  private function switchRoom(ConnectionInterface $conn, array &$info, int $chatId): void {
    if (isset($info['chat_id']) && is_int($info['chat_id'])) {
      $this->leaveRoom($conn, (int)$info['chat_id']);
    }
    $this->joinRoom($conn, $chatId);
    $info['chat_id'] = $chatId;
    $this->clients[$conn] = $info;
  }

  /**
   * Add a connection to the in‑memory room for the given chat id. 
   */
  private function joinRoom(ConnectionInterface $conn, int $chatId): void {
    if (!isset($this->rooms[$chatId])) {
      $this->rooms[$chatId] = new SplObjectStorage();
    }
    $this->rooms[$chatId]->attach($conn);
  }

  /**
   * Remove a connection from the in‑memory room for the given chat id. 
   *
   * If the room becomes empty, it is deleted to keep memory usage small. 
   */
  private function leaveRoom(ConnectionInterface $conn, int $chatId): void {
    if (!isset($this->rooms[$chatId])) return;
    if ($this->rooms[$chatId]->contains($conn)) $this->rooms[$chatId]->detach($conn);
    if (count($this->rooms[$chatId]) === 0) unset($this->rooms[$chatId]);
  }

  /**
   * Broadcast a JSON message to all clients that are currently subscribed
   * to the given chat room.
   */
  private function broadcast(int $chatId, array $payload): void {
    if (!isset($this->rooms[$chatId])) return;
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    foreach ($this->rooms[$chatId] as $client) {
      try { $client->send($json); } catch (Throwable) {}
    }
  }

  /**
   * Convenience helper for sending a single JSON message to one connection. 
   */
  private function send(ConnectionInterface $conn, array $payload): void {
    $conn->send(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  }

  /**
   * Extract cookies from the underlying HTTP upgrade request.
   *
   * Ratchet exposes the PSR‑7 request as `$conn->httpRequest`. We keep this
   * method defensive because not all ConnectionInterface implementations
   * necessarily provide that property.
   *
   * @return array<string,string>
   */
  private function parseCookies(ConnectionInterface $conn): array {
    $header = '';
    try {
      // Ratchet provides httpRequest on the underlying connection.
      $req = (property_exists($conn, 'httpRequest') ? $conn->httpRequest : null);
      if ($req) {
        $cookies = $req->getHeader('Cookie');
        if (is_array($cookies) && count($cookies) > 0) $header = (string)$cookies[0];
      }
    } catch (Throwable) {}

    $out = [];
    foreach (explode(';', $header) as $part) {
      $part = trim($part);
      if ($part === '' || !str_contains($part, '=')) continue;
      [$k, $v] = explode('=', $part, 2);
      $out[trim($k)] = urldecode(trim($v));
    }
    return $out;
  }

  /**
   * Try to resolve an employee id from a given PHP session id.
   *
   * We read the native PHP session file directly. This keeps the WebSocket
   * layer decoupled from the HTTP stack while still being able to trust the
   * existing `lc_employee_id` session variable.
   */
  private function resolveEmployeeIdFromSession(?string $phpSessId): ?int {
    if (!$phpSessId) return null;
    $path = session_save_path();
    if (!is_string($path) || $path === '') $path = sys_get_temp_dir();
    $file = rtrim($path, "\\/") . DIRECTORY_SEPARATOR . 'sess_' . $phpSessId;
    if (!is_file($file)) return null;
    $contents = @file_get_contents($file);
    if (!is_string($contents)) return null;
    if (preg_match('/lc_employee_id\\|i:(\\d+);/', $contents, $m)) {
      return (int)$m[1];
    }
    return null;
  }

  /**
   * Guard that throws if the current socket is not an authenticated employee.
   */
  private function requireEmployee(array $info): void {
    if (($info['role'] ?? '') !== 'employee' || !isset($info['employee_id']) || !$info['employee_id']) {
      throw new \RuntimeException('Not employee');
    }
  }

  /**
   * Ensure that the current socket is allowed to access the given chat.
   *
   * - Employees may subscribe to any chat (for triage and monitoring).
   * - Customers are restricted to chats with their own `customer_token`.
   */
  private function assertCanAccessChat(array $info, int $chatId): void {
    $pdo = \lc_pdo();
    if (($info['role'] ?? '') === 'employee') {
      // Employees can subscribe to any chat (for triage); sending/closing is still restricted.
      $stmt = $pdo->prepare("SELECT id FROM chats WHERE id = ? LIMIT 1");
      $stmt->execute([$chatId]);
      if (!$stmt->fetch()) throw new \RuntimeException('Chat not found');
      return;
    }

    $token = $info['customer_token'] ?? null;
    if (!is_string($token) || $token === '') throw new \RuntimeException('No customer token');
    $stmt = $pdo->prepare("SELECT id FROM chats WHERE id = ? AND customer_token = ? LIMIT 1");
    $stmt->execute([$chatId, $token]);
    if (!$stmt->fetch()) throw new \RuntimeException('Chat not found');
  }

  /**
   * Insert a new message into the database for the given chat.
   *
   * All important invariants are enforced here inside a transaction:
   * - the chat must exist
   * - the chat may not be closed
   * - for employees, the chat must be assigned to that employee
   * - for customers, the chat must have at least one tag selected
   * - the message must be less than 2000 characters
   *
   * On success the freshly inserted row is re‑fetched and returned.
   *
   * @return array<string,mixed>
   */
  private function insertMessage(array $info, int $chatId, string $body): array {
    $pdo = \lc_pdo();
    return \lc_tx(function (\PDO $pdo) use ($info, $chatId, $body) {
      $stmt = $pdo->prepare("SELECT id, status, assigned_employee_id FROM chats WHERE id = ? LIMIT 1 FOR UPDATE");
      $stmt->execute([$chatId]);
      $chat = $stmt->fetch();
      if (!$chat) throw new \RuntimeException('Chat not found');
      if ($chat['status'] === 'closed') throw new \RuntimeException('Chat closed');

      $role = (string)($info['role'] ?? 'customer');
      if ($role === 'employee') {
        $employeeId = (int)$info['employee_id'];
        $assigned = $chat['assigned_employee_id'] !== null ? (int)$chat['assigned_employee_id'] : null;
        // Allow admins to bypass assignment checks so they can still send messages to unassigned chats, 
        // but require regular employees to be assigned to the chat before they can send messages.
        $isAdmin = \lc_employee_is_admin($pdo, $employeeId);
        if ($assigned !== $employeeId && !$isAdmin) throw new \RuntimeException('Not assigned');
        $ins = $pdo->prepare("INSERT INTO messages (chat_id, sender_type, sender_employee_id, body) VALUES (?, 'employee', ?, ?)");
        $ins->execute([$chatId, $employeeId, $body]);
      } else {
        // For customers, require that at least one tag is selected for the chat
        // before allowing messages to be sent via WebSockets.
        $tagCheck = $pdo->prepare("SELECT COUNT(*) AS cnt FROM chat_tags WHERE chat_id = ?");
        $tagCheck->execute([$chatId]);
        $tagRow = $tagCheck->fetch();
        $tagCount = $tagRow ? (int)$tagRow['cnt'] : 0;
        if ($tagCount === 0) {
          throw new \RuntimeException('Tags required');
        }

        $ins = $pdo->prepare("INSERT INTO messages (chat_id, sender_type, body) VALUES (?, 'customer', ?)");
        $ins->execute([$chatId, $body]);
      }

      $id = (int)$pdo->lastInsertId();
      $sel = $pdo->prepare("SELECT id, chat_id, sender_type, sender_employee_id, body, created_at FROM messages WHERE id = ? LIMIT 1");
      $sel->execute([$id]);
      return (array)$sel->fetch();
    });
  }

  /**
   * Mark a chat as "taken" by the given employee.
   *
   * This is used by the employee dashboard as well as through WebSocket messages. 
   * Conflicts (already taken / closed) are signalled via exceptions.
   * This is the take feature that allows the employee to take a chat in case the issue was not properly resolved. 
   * This feature is only available for employees and not for customers.
   * The employee can take a chat by sending a "take_chat" message to the server.
   * The server will check if the chat is open and if the employee is assigned to the chat.
   * If the chat is open and the employee is assigned to the chat, the server will take the chat and append a system message to the chat.
   * The server will then broadcast the "chat_update" message to all clients that are currently subscribed to the chat.
   * The clients will then update the chat list and the chat view to show the taken chat.
   */
  private function takeChat(int $employeeId, int $chatId): array {
    $pdo = \lc_pdo();
    return \lc_tx(function (\PDO $pdo) use ($employeeId, $chatId) {
      $stmt = $pdo->prepare("SELECT id, status, assigned_employee_id FROM chats WHERE id = ? LIMIT 1 FOR UPDATE");
      $stmt->execute([$chatId]);
      $row = $stmt->fetch();
      if (!$row) throw new \RuntimeException('Chat not found');
      if ($row['status'] === 'closed') throw new \RuntimeException('Chat closed');
      $assigned = $row['assigned_employee_id'] !== null ? (int)$row['assigned_employee_id'] : null;
      $isAdmin = \lc_employee_is_admin($pdo, $employeeId);
      if ($assigned !== null && $assigned !== $employeeId && !$isAdmin) throw new \RuntimeException('Already taken');

      $pdo->prepare("UPDATE chats SET status='taken', assigned_employee_id=? WHERE id=?")->execute([$employeeId, $chatId]);
      return ['status' => 'taken', 'assigned_employee_id' => $employeeId];
    });
  }

  /**
   * Close a chat on behalf of an employee and append a system message.
   * This is the close feature that allows the employee to close a chat in case the issue was not properly resolved. 
   * This feature is only available for employees and not for customers.
   * The employee can close a chat by sending a "close_chat" message to the server.
   * The server will check if the chat is open and if the employee is assigned to the chat.
   * If the chat is open and the employee is assigned to the chat, the server will close the chat and append a system message to the chat.
   * The server will then broadcast the "chat_update" message to all clients that are currently subscribed to the chat.
   * The clients will then update the chat list and the chat view to show the closed chat.
   * The clients will then update the chat view to show the closed chat.
   */
  private function closeChat(int $employeeId, int $chatId): array {
    $pdo = \lc_pdo();
    return \lc_tx(function (\PDO $pdo) use ($employeeId, $chatId) {
      $stmt = $pdo->prepare("SELECT id, status, assigned_employee_id FROM chats WHERE id = ? LIMIT 1 FOR UPDATE");
      $stmt->execute([$chatId]);
      $row = $stmt->fetch();
      if (!$row) throw new \RuntimeException('Chat not found');
      $assigned = $row['assigned_employee_id'] !== null ? (int)$row['assigned_employee_id'] : null;
      $isAdmin = \lc_employee_is_admin($pdo, $employeeId);
      if ($assigned !== $employeeId && !$isAdmin) throw new \RuntimeException('Not assigned');
      if ($row['status'] === 'closed') return ['status' => 'closed', 'assigned_employee_id' => $employeeId];
      $pdo->prepare("UPDATE chats SET status='closed', closed_at=NOW() WHERE id=?")->execute([$chatId]);
      $pdo->prepare("INSERT INTO messages (chat_id, sender_type, body) VALUES (?, 'system', 'Chat closed.')")->execute([$chatId]);
      return ['status' => 'closed', 'assigned_employee_id' => $employeeId];
    });
  }

    /**
    * Re‑open a previously closed chat and append a system message.
    *This is the re-open feature that allows the employee to re-open a closed chat in case the issue was not properly resolved. 
    *This feature is only available for employees and not for customers.
    *The employee can re-open a closed chat by sending a "reopen_chat" message to the server.
    *The server will check if the chat is closed and if the employee is assigned to the chat.
    *If the chat is closed and the employee is assigned to the chat, the server will re-open the chat and append a system message to the chat.
    *The server will then broadcast the "chat_update" message to all clients that are currently subscribed to the chat.
    *The clients will then update the chat list and the chat view to show the re-opened chat.
    *The clients will then update the chat view to show the re-opened chat.
    */
  private function reopenChat(int $employeeId, int $chatId): array {
    $pdo = \lc_pdo();
    return \lc_tx(function (\PDO $pdo) use ($employeeId, $chatId) {
      $stmt = $pdo->prepare("SELECT id, status, assigned_employee_id FROM chats WHERE id = ? LIMIT 1 FOR UPDATE");
      $stmt->execute([$chatId]);
      $row = $stmt->fetch();
      if (!$row) throw new \RuntimeException('Chat not found');
      $assigned = $row['assigned_employee_id'] !== null ? (int)$row['assigned_employee_id'] : null;
      $isAdmin = \lc_employee_is_admin($pdo, $employeeId);
      if ($assigned !== $employeeId && !$isAdmin) throw new \RuntimeException('Not assigned');
      if ($row['status'] !== 'closed') return ['status' => (string)$row['status'], 'assigned_employee_id' => $employeeId];
      $pdo->prepare("UPDATE chats SET status='taken', closed_at=NULL, reopened_count=reopened_count+1 WHERE id=?")->execute([$chatId]);
      $pdo->prepare("INSERT INTO messages (chat_id, sender_type, body) VALUES (?, 'system', 'Chat reopened.')")->execute([$chatId]);
      return ['status' => 'taken', 'assigned_employee_id' => $employeeId];
    });
  }
}



