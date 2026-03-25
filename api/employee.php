<?php
declare(strict_types=1);

/**
 * Employee-facing chat API.
 *
 * This endpoint powers the employee dashboard (`assets/employee.js`). Like the
 * customer API, it uses a simple `?action=` router, but all calls are guarded
 * by `lc_require_employee()` which enforces a valid logged-in session.
 */
require_once __DIR__ . '/bootstrap.php';

// Resolve the action first, then ensure we have an authenticated employee.
$action = $_GET['action'] ?? '';
if (!is_string($action)) $action = '';

$employeeId = lc_require_employee();
$pdo = lc_pdo();
lc_ensure_message_files_table($pdo);
// Whether the current employee is an admin (can override assignment checks)
$isAdmin = lc_employee_is_admin($pdo, $employeeId);

// Ensure chat source column exists for channel attribution.
try {
  $pdo->exec("ALTER TABLE chats ADD COLUMN source_channel ENUM('website','whatsapp','email','facebook','instagram','other') NOT NULL DEFAULT 'website' AFTER customer_name");
} catch (Throwable $e) {}

// Ensure all-channels permission column exists.
try {
  $pdo->exec("ALTER TABLE employees ADD COLUMN can_access_all_channels TINYINT(1) NOT NULL DEFAULT 0 AFTER can_grant_admin");
} catch (Throwable $e) {}

$employeeCanAccessAllChannels = false;
try {
  $stmt = $pdo->prepare("SELECT is_admin, can_grant_admin, can_access_all_channels FROM employees WHERE id = ? LIMIT 1");
  $stmt->execute([$employeeId]);
  $row = $stmt->fetch();
  $employeeCanAccessAllChannels = $row && (
    (int)($row['can_access_all_channels'] ?? 0) === 1 ||
    (int)($row['is_admin'] ?? 0) === 1 ||
    (int)($row['can_grant_admin'] ?? 0) === 1
  );
} catch (Throwable $e) {
  $employeeCanAccessAllChannels = false;
}

function lc_employee_has_channel_access(PDO $pdo, int $employeeId, string $sourceChannel, bool $canAccessAllChannels = false): bool {
  if ($canAccessAllChannels) {
    return true;
  }
  try {
    $stmt = $pdo->prepare("SELECT 1 FROM employee_channel_access WHERE employee_id = ? AND source_channel = ? LIMIT 1");
    $stmt->execute([$employeeId, $sourceChannel]);
    return (bool)$stmt->fetch();
  } catch (Throwable $e) {
    // If permission table is unavailable, fail open to avoid blocking chats.
    return true;
  }
}

function lc_employee_channel_access_map(PDO $pdo, int $employeeId): array {
  try {
    $stmt = $pdo->prepare("SELECT source_channel FROM employee_channel_access WHERE employee_id = ?");
    $stmt->execute([$employeeId]);
    $rows = $stmt->fetchAll();
    $map = [];
    foreach ($rows as $row) {
      $channel = isset($row['source_channel']) ? (string)$row['source_channel'] : '';
      if ($channel !== '') {
        $map[$channel] = true;
      }
    }
    return $map;
  } catch (Throwable $e) {
    // If permission table is unavailable, allow all channels.
    return [
      'website' => true,
      'whatsapp' => true,
      'email' => true,
      'facebook' => true,
      'instagram' => true,
      'other' => true,
    ];
  }
}

/**
 * Fetch a human-friendly display name for an employee.
 *
 * This is used for system messages such as "You are now connected to X".
 */
function lc_employee_display_name(PDO $pdo, int $employeeId): string {
  $stmt = $pdo->prepare("SELECT display_name FROM employees WHERE id = ? LIMIT 1");
  $stmt->execute([$employeeId]);
  $row = $stmt->fetch();
  return $row ? (string)$row['display_name'] : 'Employee';
}

// ---- action=me ------------------------------------------------------------
// Return basic profile information for the currently logged-in employee.
if ($action === 'me') {
  lc_require_method('GET');
  $stmt = $pdo->prepare("SELECT id, username, display_name FROM employees WHERE id = ? LIMIT 1");
  $stmt->execute([$employeeId]);
  $me = $stmt->fetch();
  lc_json_response(['ok' => true, 'me' => $me]);
}

// ---- action=list_chats ----------------------------------------------------
// Return a list of chats with unread counts and NEW markers for the sidebar.
if ($action === 'list_chats') {
  lc_require_method('GET');
  
  // Ensure soft-delete columns exist
  try {
    $pdo->exec("ALTER TABLE chats ADD COLUMN employee_deleted_at TIMESTAMP NULL DEFAULT NULL AFTER closed_at");
  } catch (Throwable $e) {}
  try {
    $pdo->exec("ALTER TABLE chats ADD COLUMN customer_deleted_at TIMESTAMP NULL DEFAULT NULL AFTER employee_deleted_at");
  } catch (Throwable $e) {}
  
  $status = $_GET['status'] ?? '';
  if (!is_string($status)) $status = '';
  $allowed = ['open','taken','closed','all'];
  if (!in_array($status, $allowed, true)) $status = 'all';

  $channel = $_GET['channel'] ?? '';
  if (!is_string($channel)) $channel = '';
  $allowedChannels = ['all','website','whatsapp','email','facebook','instagram','other'];
  $channel = strtolower(trim($channel));
  if (!in_array($channel, $allowedChannels, true)) $channel = 'all';

  $where = 'WHERE employee_deleted_at IS NULL';
  $params = [$employeeId];
  if ($status !== 'all') {
    $where .= ' AND c.status = ?';
    $params[] = $status;
  }
  if ($channel !== 'all') {
    $where .= ' AND c.source_channel = ?';
    $params[] = $channel;
  }

  $stmt = $pdo->prepare("
    SELECT
      c.id,
      c.customer_name,
      c.source_channel,
      c.customer_token,
      c.status,
      c.assigned_employee_id,
      c.created_at,
      c.updated_at,
      c.closed_at,
      e.display_name AS assigned_employee_name,
      COALESCE(r.last_seen_message_id, 0) AS last_seen_message_id,
      CASE
        WHEN c.status = 'open' AND c.assigned_employee_id IS NULL AND r.employee_id IS NULL THEN 1
        ELSE 0
      END AS is_new_chat,
      (
        SELECT COUNT(*)
        FROM messages um
        WHERE um.chat_id = c.id
          AND um.sender_type = 'customer'
          AND um.id > COALESCE(r.last_seen_message_id, 0)
      ) AS unread_customer_count,
      (
        SELECT m.body
        FROM messages m
        WHERE m.chat_id = c.id
        ORDER BY m.id DESC
        LIMIT 1
      ) AS last_message_body,
      (
        SELECT m.created_at
        FROM messages m
        WHERE m.chat_id = c.id
        ORDER BY m.id DESC
        LIMIT 1
      ) AS last_message_at
    FROM chats c
    LEFT JOIN employees e ON e.id = c.assigned_employee_id
    LEFT JOIN employee_chat_reads r ON r.chat_id = c.id AND r.employee_id = ?
    $where
    ORDER BY COALESCE(last_message_at, c.updated_at) DESC, c.updated_at DESC
    LIMIT 200
  ");
  $stmt->execute($params);
  $chats = $stmt->fetchAll();
  $channelAccessMap = lc_employee_channel_access_map($pdo, $employeeId);
  foreach ($chats as &$c) {
    $c['id'] = (int)$c['id'];
    $c['source_channel'] = is_string($c['source_channel']) ? $c['source_channel'] : 'website';
    $c['assigned_employee_id'] = $c['assigned_employee_id'] !== null ? (int)$c['assigned_employee_id'] : null;
    $c['tags'] = lc_fetch_chat_tags($pdo, (int)$c['id']);
    $c['last_seen_message_id'] = (int)$c['last_seen_message_id'];
    $c['unread_customer_count'] = (int)$c['unread_customer_count'];
    $c['is_new_chat'] = ((int)$c['is_new_chat']) === 1;
    $c['can_access'] = $employeeCanAccessAllChannels || isset($channelAccessMap[$c['source_channel']]);
  }
  
  // Filter out chats with no tags (customers haven't saved any tags yet)
  $chats = array_filter($chats, function($c) {
    return !empty($c['tags']);
  });
  // Re-index the array so JSON encodes it as an array, not an object
  $chats = array_values($chats);
  $allowedChannels = array_values(array_keys($channelAccessMap));
  if ($employeeCanAccessAllChannels) {
    $allowedChannels = ['all', 'website', 'whatsapp', 'email', 'facebook', 'instagram', 'other'];
  }
  
  lc_json_response(['ok' => true, 'chats' => $chats, 'allowed_channels' => $allowedChannels]);
}

// ---- action=get_chat ------------------------------------------------------
// Load a single chat with messages and mark everything as "seen" for badges.
if ($action === 'get_chat') {
  lc_require_method('GET');
  $chatId = lc_get_int('chat_id', 0);
  if ($chatId <= 0) lc_abort(400, 'Invalid chat_id');

  $stmt = $pdo->prepare("
    SELECT c.id, c.customer_name, c.source_channel, c.status, c.assigned_employee_id, c.created_at, c.updated_at, c.closed_at
    FROM chats c
    WHERE c.id = ?
    LIMIT 1
  ");
  $stmt->execute([$chatId]);
  $chat = $stmt->fetch();
  if (!$chat) lc_abort(404, 'Chat not found');
  $sourceChannel = is_string($chat['source_channel']) ? $chat['source_channel'] : 'website';
  if (!lc_employee_has_channel_access($pdo, $employeeId, $sourceChannel, $employeeCanAccessAllChannels)) {
    lc_abort(403, 'No access to this channel');
  }

  // Read-state for badges/markers
  $stmt = $pdo->prepare("SELECT last_seen_message_id FROM employee_chat_reads WHERE employee_id = ? AND chat_id = ? LIMIT 1");
  $stmt->execute([$employeeId, $chatId]);
  $readRow = $stmt->fetch();
  $unreadSinceId = $readRow ? (int)$readRow['last_seen_message_id'] : 0;

  $messages = lc_fetch_chat_messages($pdo, $chatId, 500);
  $maxId = 0;
  foreach ($messages as $m) {
    $maxId = max($maxId, (int)$m['id']);
  }

  // Mark everything currently visible as "seen" for this employee.
  $up = $pdo->prepare("
    INSERT INTO employee_chat_reads (employee_id, chat_id, last_seen_message_id)
    VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE last_seen_message_id = VALUES(last_seen_message_id)
  ");
  $up->execute([$employeeId, $chatId, $maxId]);

  lc_json_response([
    'ok' => true,
    'chat' => [
      'id' => (int)$chat['id'],
      'customer_name' => $chat['customer_name'],
      'source_channel' => is_string($chat['source_channel']) ? $chat['source_channel'] : 'website',
      'status' => $chat['status'],
      'assigned_employee_id' => $chat['assigned_employee_id'] !== null ? (int)$chat['assigned_employee_id'] : null,
      'created_at' => $chat['created_at'],
      'updated_at' => $chat['updated_at'],
      'closed_at' => $chat['closed_at'],
      'tags' => lc_fetch_chat_tags($pdo, $chatId),
    ],
    'unread_since_id' => $unreadSinceId,
    'messages' => $messages,
  ]);
}

// ---- action=poll ----------------------------------------------------------
// Employee-side long-poll endpoint (fallback when WebSockets are unavailable).
if ($action === 'poll') {
  lc_require_method('GET');
  $chatId = lc_get_int('chat_id', 0);
  $sinceId = lc_get_int('since_id', 0);
  if ($chatId <= 0) lc_abort(400, 'Invalid chat_id');

  $stmt = $pdo->prepare("SELECT id, status, source_channel, assigned_employee_id, updated_at, closed_at FROM chats WHERE id = ? LIMIT 1");
  $stmt->execute([$chatId]);
  $chat = $stmt->fetch();
  if (!$chat) lc_abort(404, 'Chat not found');
  $sourceChannel = is_string($chat['source_channel']) ? $chat['source_channel'] : 'website';
  if (!lc_employee_has_channel_access($pdo, $employeeId, $sourceChannel, $employeeCanAccessAllChannels)) {
    lc_abort(403, 'No access to this channel');
  }
  $assigned = $chat['assigned_employee_id'] !== null ? (int)$chat['assigned_employee_id'] : null;
  if (!$isAdmin && $assigned !== $employeeId) lc_abort(403, 'Not assigned to this chat');

  $stmt = $pdo->prepare("
    SELECT id, chat_id, sender_type, sender_employee_id, body, created_at
    FROM messages
    WHERE chat_id = ? AND id > ?
    ORDER BY id ASC
    LIMIT 200
  ");
  $stmt->execute([$chatId, $sinceId]);
  $messages = $stmt->fetchAll();
  if (!empty($messages)) {
    $messageIds = array_map(fn($m) => (int)$m['id'], $messages);
    $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
    $f = $pdo->prepare("SELECT id, message_id, original_name, mime_type, size_bytes FROM message_files WHERE message_id IN ($placeholders)");
    $f->execute($messageIds);
    $files = $f->fetchAll();
    $fileByMessage = [];
    foreach ($files as $row) {
      $fileByMessage[(int)$row['message_id']] = $row;
    }
    foreach ($messages as &$m) {
      $msgId = (int)$m['id'];
      $file = $fileByMessage[$msgId] ?? null;
      $m['file_id'] = $file ? (int)$file['id'] : null;
      $m['file_name'] = $file ? (string)$file['original_name'] : null;
      $m['file_mime'] = $file ? (string)$file['mime_type'] : null;
      $m['file_size_bytes'] = $file ? (int)$file['size_bytes'] : null;
    }
    unset($m);
  }

  // While polling this chat, consider messages "seen" (employee is viewing it).
  $maxId = 0;
  foreach ($messages as $m) {
    $maxId = max($maxId, (int)$m['id']);
  }
  if ($maxId > 0) {
    $up = $pdo->prepare("
      INSERT INTO employee_chat_reads (employee_id, chat_id, last_seen_message_id)
      VALUES (?, ?, ?)
      ON DUPLICATE KEY UPDATE last_seen_message_id = GREATEST(last_seen_message_id, VALUES(last_seen_message_id))
    ");
    $up->execute([$employeeId, $chatId, $maxId]);
  }

  lc_json_response([
    'ok' => true,
    'chat' => [
      'id' => (int)$chat['id'],
      'status' => $chat['status'],
      'assigned_employee_id' => $assigned,
      'updated_at' => $chat['updated_at'],
      'closed_at' => $chat['closed_at'],
    ],
    'messages' => $messages,
  ]);
}

// ---- action=take_chat -----------------------------------------------------
// Employee claims ownership of a chat (if not taken/closed already).
if ($action === 'take_chat') {
  lc_require_method('POST');
  $data = lc_read_json_body();
  $chatId = isset($data['chat_id']) ? (int)$data['chat_id'] : 0;
  if ($chatId <= 0) lc_abort(400, 'Invalid chat_id');

  $displayName = lc_employee_display_name($pdo, $employeeId);

  $chat = lc_tx(function (PDO $pdo) use ($chatId, $employeeId, $displayName, $isAdmin, $employeeCanAccessAllChannels) {
    $stmt = $pdo->prepare("SELECT id, status, source_channel, assigned_employee_id FROM chats WHERE id = ? LIMIT 1 FOR UPDATE");
    $stmt->execute([$chatId]);
    $row = $stmt->fetch();
    if (!$row) lc_abort(404, 'Chat not found');
    $sourceChannel = is_string($row['source_channel']) ? $row['source_channel'] : 'website';
    if (!lc_employee_has_channel_access($pdo, $employeeId, $sourceChannel, $employeeCanAccessAllChannels)) {
      lc_abort(403, 'No access to this channel');
    }
    if ($row['status'] === 'closed') lc_abort(409, 'Chat is closed');

    $assigned = $row['assigned_employee_id'] !== null ? (int)$row['assigned_employee_id'] : null;
    if ($assigned !== null && $assigned !== $employeeId && !$isAdmin) {
      lc_abort(409, 'Chat already taken');
    }

    $stmt = $pdo->prepare("UPDATE chats SET status = 'taken', assigned_employee_id = ? WHERE id = ?");
    $stmt->execute([$employeeId, $chatId]);

    if ($assigned === null) {
      $sys = $pdo->prepare("INSERT INTO messages (chat_id, sender_type, body) VALUES (?, 'system', ?)");
      $sys->execute([$chatId, "You are now connected to $displayName."]);
    }

    $stmt = $pdo->prepare("SELECT id, customer_name, status, assigned_employee_id, created_at, updated_at, closed_at FROM chats WHERE id = ? LIMIT 1");
    $stmt->execute([$chatId]);
    return $stmt->fetch();
  });

  lc_json_response(['ok' => true, 'chat' => $chat]);
}

// ---- action=send ----------------------------------------------------------
// Employee sends a message, with assignment + closed checks.
if ($action === 'send') {
  lc_require_method('POST');
  $data = lc_read_json_body();
  $chatId = isset($data['chat_id']) ? (int)$data['chat_id'] : 0;
  $body = isset($data['body']) && is_string($data['body']) ? trim($data['body']) : '';
  if ($chatId <= 0 || $body === '') lc_abort(400, 'Invalid request');
  if (mb_strlen($body) > 2000) lc_abort(400, 'Message too long');

  $msg = lc_tx(function (PDO $pdo) use ($chatId, $employeeId, $body, $isAdmin, $employeeCanAccessAllChannels) {
    $stmt = $pdo->prepare("SELECT id, status, source_channel, assigned_employee_id FROM chats WHERE id = ? LIMIT 1 FOR UPDATE");
    $stmt->execute([$chatId]);
    $chat = $stmt->fetch();
    if (!$chat) lc_abort(404, 'Chat not found');
    $sourceChannel = is_string($chat['source_channel']) ? $chat['source_channel'] : 'website';
    if (!lc_employee_has_channel_access($pdo, $employeeId, $sourceChannel, $employeeCanAccessAllChannels)) {
      lc_abort(403, 'No access to this channel');
    }
    if ($chat['status'] === 'closed') lc_abort(409, 'Chat is closed');
    $assigned = $chat['assigned_employee_id'] !== null ? (int)$chat['assigned_employee_id'] : null;
    if (!$isAdmin && $assigned !== $employeeId) lc_abort(403, 'Not assigned to this chat');

    $ins = $pdo->prepare("INSERT INTO messages (chat_id, sender_type, sender_employee_id, body) VALUES (?, 'employee', ?, ?)");
    $ins->execute([$chatId, $employeeId, $body]);
    $id = (int)$pdo->lastInsertId();
    $sel = $pdo->prepare("SELECT m.id, m.chat_id, m.sender_type, m.sender_employee_id, m.body, m.created_at, mf.id AS file_id, mf.original_name AS file_name, mf.mime_type AS file_mime, mf.size_bytes AS file_size_bytes FROM messages m LEFT JOIN message_files mf ON mf.message_id = m.id WHERE m.id = ? LIMIT 1");
    $sel->execute([$id]);
    return $sel->fetch();
  });

  lc_json_response(['ok' => true, 'message' => $msg]);
}

// ---- action=send_file -----------------------------------------------------
// Employee uploads and sends a file message.
if ($action === 'send_file') {
  lc_require_method('POST');
  $chatId = isset($_POST['chat_id']) ? (int)$_POST['chat_id'] : 0;
  if ($chatId <= 0) lc_abort(400, 'Invalid chat_id');
  if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
    lc_abort(400, 'No file uploaded');
  }

  $msg = lc_tx(function (PDO $pdo) use ($chatId, $employeeId, $isAdmin, $employeeCanAccessAllChannels) {
    $stmt = $pdo->prepare("SELECT id, status, source_channel, assigned_employee_id FROM chats WHERE id = ? LIMIT 1 FOR UPDATE");
    $stmt->execute([$chatId]);
    $chat = $stmt->fetch();
    if (!$chat) lc_abort(404, 'Chat not found');
    $sourceChannel = is_string($chat['source_channel']) ? $chat['source_channel'] : 'website';
    if (!lc_employee_has_channel_access($pdo, $employeeId, $sourceChannel, $employeeCanAccessAllChannels)) {
      lc_abort(403, 'No access to this channel');
    }
    if ($chat['status'] === 'closed') lc_abort(409, 'Chat is closed');
    $assigned = $chat['assigned_employee_id'] !== null ? (int)$chat['assigned_employee_id'] : null;
    if (!$isAdmin && $assigned !== $employeeId) lc_abort(403, 'Not assigned to this chat');

    $stored = lc_store_uploaded_chat_file($_FILES['file']);
    $ins = $pdo->prepare("INSERT INTO messages (chat_id, sender_type, sender_employee_id, body) VALUES (?, 'employee', ?, ?)");
    $ins->execute([$chatId, $employeeId, '[File] ' . $stored['original_name']]);
    $id = (int)$pdo->lastInsertId();

    $fin = $pdo->prepare("INSERT INTO message_files (message_id, original_name, storage_name, mime_type, size_bytes, storage_path) VALUES (?, ?, ?, ?, ?, ?)");
    $fin->execute([$id, $stored['original_name'], $stored['storage_name'], $stored['mime_type'], $stored['size_bytes'], $stored['storage_path']]);

    $sel = $pdo->prepare("SELECT m.id, m.chat_id, m.sender_type, m.sender_employee_id, m.body, m.created_at, mf.id AS file_id, mf.original_name AS file_name, mf.mime_type AS file_mime, mf.size_bytes AS file_size_bytes FROM messages m LEFT JOIN message_files mf ON mf.message_id = m.id WHERE m.id = ? LIMIT 1");
    $sel->execute([$id]);
    return $sel->fetch();
  });

  lc_json_response(['ok' => true, 'message' => $msg]);
}

// ---- action=close_chat ----------------------------------------------------
// Employee closes the chat and adds a system message explaining who closed it.
if ($action === 'close_chat') {
  lc_require_method('POST');
  $data = lc_read_json_body();
  $chatId = isset($data['chat_id']) ? (int)$data['chat_id'] : 0;
  if ($chatId <= 0) lc_abort(400, 'Invalid chat_id');

  $displayName = lc_employee_display_name($pdo, $employeeId);

  $chat = lc_tx(function (PDO $pdo) use ($chatId, $employeeId, $displayName, $isAdmin, $employeeCanAccessAllChannels) {
    $stmt = $pdo->prepare("SELECT id, status, source_channel, assigned_employee_id FROM chats WHERE id = ? LIMIT 1 FOR UPDATE");
    $stmt->execute([$chatId]);
    $row = $stmt->fetch();
    if (!$row) lc_abort(404, 'Chat not found');
    $sourceChannel = is_string($row['source_channel']) ? $row['source_channel'] : 'website';
    if (!lc_employee_has_channel_access($pdo, $employeeId, $sourceChannel, $employeeCanAccessAllChannels)) {
      lc_abort(403, 'No access to this channel');
    }
    $assigned = $row['assigned_employee_id'] !== null ? (int)$row['assigned_employee_id'] : null;
    if (!$isAdmin && $assigned !== $employeeId) lc_abort(403, 'Not assigned to this chat');
    if ($row['status'] === 'closed') return $row;

    $pdo->prepare("UPDATE chats SET status = 'closed', closed_at = NOW() WHERE id = ?")->execute([$chatId]);
    $pdo->prepare("INSERT INTO messages (chat_id, sender_type, body) VALUES (?, 'system', ?)")
      ->execute([$chatId, "Chat closed by $displayName."]);

    $stmt = $pdo->prepare("SELECT id, customer_name, status, assigned_employee_id, created_at, updated_at, closed_at FROM chats WHERE id = ? LIMIT 1");
    $stmt->execute([$chatId]);
    return $stmt->fetch();
  });

  lc_json_response(['ok' => true, 'chat' => $chat]);
}

// ---- action=reopen_chat ---------------------------------------------------
// Employee re-opens a chat and appends a system message.
// Admins can reopen any chat; regular employees can only reopen their assigned chats.
if ($action === 'reopen_chat') {
  lc_require_method('POST');
  $data = lc_read_json_body();
  $chatId = isset($data['chat_id']) ? (int)$data['chat_id'] : 0;
  if ($chatId <= 0) lc_abort(400, 'Invalid chat_id');

  $displayName = lc_employee_display_name($pdo, $employeeId);
  $isAdmin = lc_employee_is_admin($pdo, $employeeId);

  $chat = lc_tx(function (PDO $pdo) use ($chatId, $employeeId, $displayName, $isAdmin, $employeeCanAccessAllChannels) {
    $stmt = $pdo->prepare("SELECT id, status, source_channel, assigned_employee_id, reopened_count FROM chats WHERE id = ? LIMIT 1 FOR UPDATE");
    $stmt->execute([$chatId]);
    $row = $stmt->fetch();
    if (!$row) lc_abort(404, 'Chat not found');
    $sourceChannel = is_string($row['source_channel']) ? $row['source_channel'] : 'website';
    if (!lc_employee_has_channel_access($pdo, $employeeId, $sourceChannel, $employeeCanAccessAllChannels)) {
      lc_abort(403, 'No access to this channel');
    }
    $assigned = $row['assigned_employee_id'] !== null ? (int)$row['assigned_employee_id'] : null;
    
    // Admins can reopen any chat; regular employees can only reopen their assigned chats
    if (!$isAdmin && $assigned !== $employeeId) {
      lc_abort(403, 'Not assigned to this chat');
    }
    
    if ($row['status'] !== 'closed') return $row;

    $pdo->prepare("UPDATE chats SET status = 'taken', closed_at = NULL, reopened_count = reopened_count + 1 WHERE id = ?")->execute([$chatId]);
    $pdo->prepare("INSERT INTO messages (chat_id, sender_type, body) VALUES (?, 'system', ?)")
      ->execute([$chatId, "Chat reopened by $displayName."]);

    $stmt = $pdo->prepare("SELECT id, customer_name, status, assigned_employee_id, created_at, updated_at, closed_at FROM chats WHERE id = ? LIMIT 1");
    $stmt->execute([$chatId]);
    return $stmt->fetch();
  });

  lc_json_response(['ok' => true, 'chat' => $chat]);
}

// ---- action=delete_chat ---------------------------------------------------
// Employee soft-deletes a closed chat (removes it from their view only).
// The chat remains visible to the customer and all data is preserved.
// Only employees assigned to the chat (or admins) can delete it.
// 
// Request body:
//   - chat_id: ID of the closed chat to delete
// 
// Response: {'ok': true, 'message': 'Chat deleted'}
// Errors: 400 (invalid chat_id, not closed), 403 (not assigned), 404 (not found)
if ($action === 'delete_chat') {
  lc_require_method('POST');
  $data = lc_read_json_body();
  $chatId = isset($data['chat_id']) ? (int)$data['chat_id'] : 0;
  if ($chatId <= 0) lc_abort(400, 'Invalid chat_id');

  // Ensure soft-delete columns exist (outside of transaction to avoid breaking it)
  try {
    $pdo->exec("ALTER TABLE chats ADD COLUMN employee_deleted_at TIMESTAMP NULL DEFAULT NULL AFTER closed_at");
  } catch (Throwable $e) {}
  try {
    $pdo->exec("ALTER TABLE chats ADD COLUMN customer_deleted_at TIMESTAMP NULL DEFAULT NULL AFTER employee_deleted_at");
  } catch (Throwable $e) {}

  lc_tx(function (PDO $pdo) use ($chatId, $employeeId, $isAdmin, $employeeCanAccessAllChannels) {
    $stmt = $pdo->prepare("SELECT id, status, source_channel, assigned_employee_id FROM chats WHERE id = ? LIMIT 1 FOR UPDATE");
    $stmt->execute([$chatId]);
    $chat = $stmt->fetch();
    
    if (!$chat) lc_abort(404, 'Chat not found');
    $sourceChannel = is_string($chat['source_channel']) ? $chat['source_channel'] : 'website';
    if (!lc_employee_has_channel_access($pdo, $employeeId, $sourceChannel, $employeeCanAccessAllChannels)) {
      lc_abort(403, 'No access to this channel');
    }
    if ($chat['status'] !== 'closed') lc_abort(400, 'Can only delete closed chats');
    
    $assigned = $chat['assigned_employee_id'] !== null ? (int)$chat['assigned_employee_id'] : null;
    if (!$isAdmin && $assigned !== $employeeId) lc_abort(403, 'Not assigned to this chat');

    // Soft delete - mark as deleted by employee
    $pdo->prepare("UPDATE chats SET employee_deleted_at = NOW() WHERE id = ?")->execute([$chatId]);
  });

  lc_json_response(['ok' => true, 'message' => 'Chat deleted']);
}

// ---- action=set_typing ---------------------------------------------------
// Mark that this employee is typing in a chat. Persist to DB so customers
// can observe typing status reliably across sessions.
if ($action === 'set_typing') {
  lc_require_method('POST');
  $data = lc_read_json_body();
  $chatId = isset($data['chat_id']) ? (int)$data['chat_id'] : 0;
  $isTyping = isset($data['is_typing']) ? (bool)$data['is_typing'] : false;
  if ($chatId <= 0) lc_abort(400, 'Invalid chat_id');

  // Ensure the helper table exists (lightweight, safe to run repeatedly).
  $pdo->exec("CREATE TABLE IF NOT EXISTS employee_typing (
    employee_id INT UNSIGNED NOT NULL,
    chat_id BIGINT UNSIGNED NOT NULL,
    last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (employee_id, chat_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

  if ($isTyping) {
    $ins = $pdo->prepare("INSERT INTO employee_typing (employee_id, chat_id, last_seen_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE last_seen_at = NOW()");
    $ins->execute([$employeeId, $chatId]);
  } else {
    $del = $pdo->prepare("DELETE FROM employee_typing WHERE employee_id = ? AND chat_id = ?");
    $del->execute([$employeeId, $chatId]);
  }

  lc_json_response(['ok' => true]);
}

// ---- action=get_typing_status ---------------------------------------------------
// Return whether the *customer* is typing in the given chat. This is used by
// employee UI to show a typing indicator when the customer is typing.
if ($action === 'get_typing_status') {
  lc_require_method('GET');
  $chatId = lc_get_int('chat_id', 0);
  if ($chatId <= 0) lc_abort(400, 'Invalid chat_id');

  // Ensure customer_typing table exists
  $pdo->exec("CREATE TABLE IF NOT EXISTS customer_typing (
    chat_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

  $stmt = $pdo->prepare("SELECT 1 FROM customer_typing WHERE chat_id = ? AND last_seen_at > (NOW() - INTERVAL 5 SECOND) LIMIT 1");
  $stmt->execute([$chatId]);
  $typing = (bool)$stmt->fetch();

  lc_json_response(['ok' => true, 'typing' => ['employee_typing' => $typing]]);
}

// ---- action=mark_read ---------------------------------------------------
// Mark all messages in a chat as read by the employee.
if ($action === 'mark_read') {
  lc_require_method('POST');
  $data = lc_read_json_body();
  $chatId = isset($data['chat_id']) ? (int)$data['chat_id'] : 0;
  if ($chatId <= 0) lc_abort(400, 'Invalid chat_id');

  // Verify the chat exists
  $stmt = $pdo->prepare("SELECT id, source_channel FROM chats WHERE id = ? LIMIT 1");
  $stmt->execute([$chatId]);
  $chat = $stmt->fetch();
  if (!$chat) lc_abort(404, 'Chat not found');
  $sourceChannel = is_string($chat['source_channel']) ? $chat['source_channel'] : 'website';
  if (!lc_employee_has_channel_access($pdo, $employeeId, $sourceChannel, $employeeCanAccessAllChannels)) {
    lc_abort(403, 'No access to this channel');
  }

  // Mark all messages from customers as read by employee
  $pdo->prepare("
    UPDATE messages 
    SET employee_read_at = NOW()
    WHERE chat_id = ? AND sender_type = 'customer' AND employee_read_at IS NULL
  ")->execute([$chatId]);

  lc_json_response(['ok' => true]);
}

// ---- action=download_file -------------------------------------------------
// Download a file attached to a message in a chat the employee can access.
if ($action === 'download_file') {
  lc_require_method('GET');
  $fileId = lc_get_int('file_id', 0);
  $inline = lc_get_int('inline', 0) === 1;
  if ($fileId <= 0) lc_abort(400, 'Invalid file_id');

  $stmt = $pdo->prepare("
    SELECT mf.id, mf.original_name, mf.mime_type, mf.size_bytes, mf.storage_path, c.source_channel
    FROM message_files mf
    JOIN messages m ON m.id = mf.message_id
    JOIN chats c ON c.id = m.chat_id
    WHERE mf.id = ?
    LIMIT 1
  ");
  $stmt->execute([$fileId]);
  $file = $stmt->fetch();
  if (!$file) lc_abort(404, 'File not found');
  $sourceChannel = is_string($file['source_channel']) ? $file['source_channel'] : 'website';
  if (!lc_employee_has_channel_access($pdo, $employeeId, $sourceChannel, $employeeCanAccessAllChannels)) {
    lc_abort(403, 'No access to this channel');
  }

  lc_output_chat_file_download($file, $inline);
}

lc_abort(400, 'Unknown action');


