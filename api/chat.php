<?php
declare(strict_types=1);

/**
 * Customer-facing chat API.
 *
 * This endpoint is used exclusively by the public chat widget
 * (`assets/chat-widget.js`). Every request is routed via the `action` query
 * parameter and is automatically scoped to the current browser's
 * `lc_customer` cookie (managed by `lc_customer_token()`).
 */
require_once __DIR__ . '/bootstrap.php';

// Simple router: ?action=... decides which block is executed below.
$action = $_GET['action'] ?? '';
if (!is_string($action)) $action = '';

// Reuse a single PDO instance + customer token throughout the request.
$pdo = lc_pdo();
$customerToken = lc_customer_token();
lc_ensure_message_files_table($pdo);

// Ensure chat source column exists for channel attribution.
try {
  $pdo->exec("ALTER TABLE chats ADD COLUMN source_channel ENUM('website','whatsapp','email','facebook','instagram','other') NOT NULL DEFAULT 'website' AFTER customer_name");
} catch (Throwable $e) {}

// ---- action=config --------------------------------------------------------
// Return minimal configuration for the widget (WebSocket URL + tag list).
if ($action === 'config') {
  $cfg = lc_config();
  lc_json_response([
    'ok' => true,
    'ws_url' => $cfg['ws_url'] ?? '',
    'tags' => lc_fetch_all_tags($pdo),
  ]);
}

// ---- action=list_chats ----------------------------------------------------
// Return a short history of chats for this customer (for the history panel).
if ($action === 'list_chats') {
  lc_require_method('GET');
  
  // Ensure soft-delete columns exist
  try {
    $pdo->exec("ALTER TABLE chats ADD COLUMN employee_deleted_at TIMESTAMP NULL DEFAULT NULL AFTER closed_at");
  } catch (Throwable $e) {}
  try {
    $pdo->exec("ALTER TABLE chats ADD COLUMN customer_deleted_at TIMESTAMP NULL DEFAULT NULL AFTER employee_deleted_at");
  } catch (Throwable $e) {}
  
  $stmt = $pdo->prepare(" 
    SELECT c.id, c.customer_name, c.source_channel, c.status, c.assigned_employee_id, c.created_at, c.updated_at, c.closed_at
    FROM chats c
    WHERE c.customer_token = ? AND c.customer_deleted_at IS NULL
    ORDER BY c.updated_at DESC
    LIMIT 50
  ");
  $stmt->execute([$customerToken]);
  $chats = $stmt->fetchAll();
  foreach ($chats as &$c) {
    $c['id'] = (int)$c['id'];
    $c['source_channel'] = is_string($c['source_channel']) ? $c['source_channel'] : 'website';
    $c['assigned_employee_id'] = $c['assigned_employee_id'] !== null ? (int)$c['assigned_employee_id'] : null;
    // Only show tags for open/taken chats, hide for closed
    $c['tags'] = ($c['status'] === 'closed') ? [] : lc_fetch_chat_tags($pdo, (int)$c['id']);
  }
  lc_json_response(['ok' => true, 'chats' => $chats]);
}

// ---- action=create_or_get -------------------------------------------------
// Either reuse the most recent open/taken chat or create a brand new one. 
if ($action === 'create_or_get') {
  lc_require_method('POST');
  $data = lc_read_json_body();
  $name = isset($data['customer_name']) && is_string($data['customer_name']) ? trim($data['customer_name']) : null;
  if ($name === '') $name = null;

  $allowedSourceChannels = ['website', 'whatsapp', 'email', 'facebook', 'instagram', 'other'];
  $sourceChannel = 'website';
  if (isset($data['source_channel']) && is_string($data['source_channel'])) {
    $candidate = strtolower(trim($data['source_channel']));
    if (in_array($candidate, $allowedSourceChannels, true)) {
      $sourceChannel = $candidate;
    }
  }

  $chat = lc_tx(function (PDO $pdo) use ($customerToken, $name, $sourceChannel) {
    // If there is any active chat, reuse it (enforces: only 1 open chat at a time).
    // There is only 1 chat per customer, because the customer should put all the problems in the tags at one time so everything can be resolved.
    $stmt = $pdo->prepare(" 
      SELECT id, customer_name, source_channel, status, assigned_employee_id, created_at, updated_at, closed_at
      FROM chats
      WHERE customer_token = ? AND status IN ('open','taken')
      ORDER BY updated_at DESC
      LIMIT 1
      FOR UPDATE
    ");
    $stmt->execute([$customerToken]);
    $existing = $stmt->fetch();
    if ($existing) {
      if ($name !== null && ($existing['customer_name'] === null || trim((string)$existing['customer_name']) === '')) {
        $pdo->prepare("UPDATE chats SET customer_name = ? WHERE id = ?")->execute([$name, (int)$existing['id']]);
        $existing['customer_name'] = $name;
      }
      return $existing;
    }

    // Otherwise create a new chat.
    $stmt = $pdo->prepare("INSERT INTO chats (customer_token, customer_name, source_channel, status) VALUES (?, ?, ?, 'open')");
    $stmt->execute([$customerToken, $name, $sourceChannel]);
    $chatId = (int)$pdo->lastInsertId();

    $welcome = "Welcome to the Corenio live chat, please select the tags that best match your issue so we can direct you to the perfect person to fix your problem.";
    $msg = $pdo->prepare("INSERT INTO messages (chat_id, sender_type, body) VALUES (?, 'system', ?)");
    $msg->execute([$chatId, $welcome]);

    $stmt = $pdo->prepare(" 
      SELECT id, customer_name, source_channel, status, assigned_employee_id, created_at, updated_at, closed_at
      FROM chats WHERE id = ? LIMIT 1
    ");
    $stmt->execute([$chatId]);
    return $stmt->fetch();
  });

  $chatId = (int)$chat['id'];
  $tags = lc_fetch_all_tags($pdo);
  $selectedTags = lc_fetch_chat_tags($pdo, $chatId);
  $messages = lc_fetch_chat_messages($pdo, $chatId, 200);

  lc_json_response([
    'ok' => true,
    'chat' => [
      'id' => $chatId,
      'customer_name' => $chat['customer_name'],
      'source_channel' => is_string($chat['source_channel']) ? $chat['source_channel'] : 'website',
      'status' => $chat['status'],
      'assigned_employee_id' => $chat['assigned_employee_id'] !== null ? (int)$chat['assigned_employee_id'] : null,
      'created_at' => $chat['created_at'],
      'updated_at' => $chat['updated_at'],
      'closed_at' => $chat['closed_at'],
      'tags' => $selectedTags,
    ],
    'tags' => $tags,
    'messages' => $messages,
  ]);
}

// ---- action=get_chat ------------------------------------------------------
// Fetch a single chat with its messages, making sure it belongs to customer.
if ($action === 'get_chat') {
  lc_require_method('GET');
  $chatId = lc_get_int('chat_id', 0);
  if ($chatId <= 0) lc_json_response(['ok' => false, 'error' => 'Invalid chat_id'], 400);

  $stmt = $pdo->prepare(" 
    SELECT id, customer_name, source_channel, status, assigned_employee_id, created_at, updated_at, closed_at
    FROM chats
    WHERE id = ? AND customer_token = ?
    LIMIT 1
  ");
  $stmt->execute([$chatId, $customerToken]);
  $chat = $stmt->fetch();
  if (!$chat) lc_json_response(['ok' => false, 'error' => 'Chat not found'], 404);

  // Only show tags for open/taken chats, hide for closed
  $tags = ($chat['status'] === 'closed') ? [] : lc_fetch_chat_tags($pdo, $chatId);
  $availableTags = ($chat['status'] === 'closed') ? [] : lc_fetch_all_tags($pdo);

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
      'tags' => $tags,
    ],
    'messages' => lc_fetch_chat_messages($pdo, $chatId, 500),
    'tags' => $availableTags,
  ]);
}

// ---- action=set_tags ------------------------------------------------------
// Update which tags the customer has chosen for this chat.
if ($action === 'set_tags') {
  lc_require_method('POST');
  $data = lc_read_json_body();
  $chatId = isset($data['chat_id']) ? (int)$data['chat_id'] : 0;
  $tagIds = isset($data['tag_ids']) && is_array($data['tag_ids']) ? $data['tag_ids'] : [];

  $tagIdsClean = [];
  foreach ($tagIds as $id) {
    if (is_numeric($id)) $tagIdsClean[] = (int)$id;
  }
  $tagIdsClean = array_values(array_unique(array_filter($tagIdsClean, fn($v) => $v > 0)));

  if ($chatId <= 0) lc_json_response(['ok' => false, 'error' => 'Invalid chat_id'], 400);

  lc_tx(function (PDO $pdo) use ($customerToken, $chatId, $tagIdsClean) {
    $stmt = $pdo->prepare("SELECT id, status FROM chats WHERE id = ? AND customer_token = ? LIMIT 1 FOR UPDATE");
    $stmt->execute([$chatId, $customerToken]);
    $chat = $stmt->fetch();
    if (!$chat) {
      lc_abort(404, 'Chat not found');
    }
    
    // Prevent tag changes on closed chats
    if ($chat['status'] === 'closed') {
      lc_abort(409, 'Cannot modify tags on closed chats');
    }

    $pdo->prepare("DELETE FROM chat_tags WHERE chat_id = ?")->execute([$chatId]);
    if (count($tagIdsClean) > 0) {
      $ins = $pdo->prepare("INSERT IGNORE INTO chat_tags (chat_id, tag_id) VALUES (?, ?)");
      foreach ($tagIdsClean as $tid) {
        $ins->execute([$chatId, $tid]);
      }
    }
  });

  // Fetch saved tags names for the chat
  $savedTags = lc_fetch_chat_tags($pdo, $chatId);
  
  // After tags are saved, prompt customer to describe their problem
  $descriptionPrompt = "Can you please describe the problem(s) you are experiencing";
  $pdo->prepare("INSERT INTO messages (chat_id, sender_type, body) VALUES (?, 'system', ?)")->execute([$chatId, $descriptionPrompt]);
  
  lc_json_response(['ok' => true, 'tags' => $savedTags]);
}


// ---- action=set_typing ---------------------------------------------------
// Mark that the customer is typing in this chat (persist to DB so employees can see it).
if ($action === 'set_typing') {
  lc_require_method('POST');
  $data = lc_read_json_body();
  $chatId = isset($data['chat_id']) ? (int)$data['chat_id'] : 0;
  $isTyping = isset($data['is_typing']) ? (bool)$data['is_typing'] : false;
  if ($chatId <= 0) lc_json_response(['ok' => false, 'error' => 'Invalid chat_id'], 400);

  // Ensure helper table exists
  $pdo->exec("CREATE TABLE IF NOT EXISTS customer_typing (
    chat_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

  if ($isTyping) {
    $ins = $pdo->prepare("INSERT INTO customer_typing (chat_id, last_seen_at) VALUES (?, NOW()) ON DUPLICATE KEY UPDATE last_seen_at = NOW()");
    $ins->execute([$chatId]);
  } else {
    $del = $pdo->prepare("DELETE FROM customer_typing WHERE chat_id = ?");
    $del->execute([$chatId]);
  }

  lc_json_response(['ok' => true]);
}


// ---- action=get_typing_status ---------------------------------------------------
// Check whether any employee is typing in the given chat (used by customer UI).
if ($action === 'get_typing_status') {
  lc_require_method('GET');
  $chatId = lc_get_int('chat_id', 0);
  if ($chatId <= 0) lc_json_response(['ok' => false, 'error' => 'Invalid chat_id'], 400);

  // Ensure employee_typing table exists
  $pdo->exec("CREATE TABLE IF NOT EXISTS employee_typing (
    employee_id INT UNSIGNED NOT NULL,
    chat_id BIGINT UNSIGNED NOT NULL,
    last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (employee_id, chat_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

  $stmt = $pdo->prepare("SELECT 1 FROM employee_typing WHERE chat_id = ? AND last_seen_at > (NOW() - INTERVAL 5 SECOND) LIMIT 1");
  $stmt->execute([$chatId]);
  $typing = (bool)$stmt->fetch();

  lc_json_response(['ok' => true, 'typing' => ['employee_typing' => $typing]]);
}

// ---- action=send ----------------------------------------------------------
// Customer sends a new message over HTTP (fallback if WS is unavailable).
if ($action === 'send') {
  lc_require_method('POST');
  $data = lc_read_json_body();
  $chatId = isset($data['chat_id']) ? (int)$data['chat_id'] : 0;
  $body = isset($data['body']) && is_string($data['body']) ? trim($data['body']) : '';
  if ($chatId <= 0 || $body === '') lc_json_response(['ok' => false, 'error' => 'Invalid request'], 400);
  if (mb_strlen($body) > 2000) lc_json_response(['ok' => false, 'error' => 'Message too long'], 400);

  $msg = lc_tx(function (PDO $pdo) use ($customerToken, $chatId, $body) {
    $stmt = $pdo->prepare("SELECT id, status FROM chats WHERE id = ? AND customer_token = ? LIMIT 1 FOR UPDATE");
    $stmt->execute([$chatId, $customerToken]);
    $chat = $stmt->fetch();
    if (!$chat) lc_abort(404, 'Chat not found');
    if ($chat['status'] === 'closed') lc_abort(409, 'Chat is closed');

    // Enforce that at least one tag is selected for this chat before
    // allowing the customer to send messages.
    // This prevents chats without tags.
    // For general problems there is a support tag that tackles problems that dont fit into the tags.
    $tagCheck = $pdo->prepare("SELECT COUNT(*) AS cnt FROM chat_tags WHERE chat_id = ?");
    $tagCheck->execute([$chatId]);
    $tagRow = $tagCheck->fetch();
    $tagCount = $tagRow ? (int)$tagRow['cnt'] : 0;
    if ($tagCount === 0) {
      lc_abort(409, 'Please select at least one tag before sending a message');
    }

    // Check if this will be the first customer message (count before inserting)
    // to determine whether we should insert the thank you message after it, 
    // since we only want to show the thank you message once, after the customer sends their first message, 
    // and it will always be the first message.
    $msgCount = $pdo->prepare("SELECT COUNT(*) AS cnt FROM messages WHERE chat_id = ? AND sender_type = 'customer'");
    $msgCount->execute([$chatId]);
    $msgRow = $msgCount->fetch();
    $customerMsgCount = $msgRow ? (int)$msgRow['cnt'] : 0;
    $isFirstMessage = ($customerMsgCount === 0);
    
    // Insert the customer's message
    $ins = $pdo->prepare("INSERT INTO messages (chat_id, sender_type, body) VALUES (?, 'customer', ?)");
    $ins->execute([$chatId, $body]);
    $id = (int)$pdo->lastInsertId();
    
    // If this was the first message, insert the thank you message after it
    if ($isFirstMessage) {
      $thankYou = "Thank you, i will now find the right person to help you with your issue(s)";
      $pdo->prepare("INSERT INTO messages (chat_id, sender_type, body) VALUES (?, 'system', ?)")->execute([$chatId, $thankYou]);
    }
    
    $sel = $pdo->prepare("SELECT m.id, m.chat_id, m.sender_type, m.sender_employee_id, m.body, m.created_at, mf.id AS file_id, mf.original_name AS file_name, mf.mime_type AS file_mime, mf.size_bytes AS file_size_bytes FROM messages m LEFT JOIN message_files mf ON mf.message_id = m.id WHERE m.id = ? LIMIT 1");
    $sel->execute([$id]);
    return $sel->fetch();
  });

  lc_json_response(['ok' => true, 'message' => $msg]);
}

// ---- action=send_file -----------------------------------------------------
// Customer uploads and sends a file message.
if ($action === 'send_file') {
  lc_require_method('POST');
  $chatId = isset($_POST['chat_id']) ? (int)$_POST['chat_id'] : 0;
  if ($chatId <= 0) lc_json_response(['ok' => false, 'error' => 'Invalid chat_id'], 400);
  if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
    lc_json_response(['ok' => false, 'error' => 'No file uploaded'], 400);
  }

  $msg = lc_tx(function (PDO $pdo) use ($customerToken, $chatId) {
    $stmt = $pdo->prepare("SELECT id, status FROM chats WHERE id = ? AND customer_token = ? LIMIT 1 FOR UPDATE");
    $stmt->execute([$chatId, $customerToken]);
    $chat = $stmt->fetch();
    if (!$chat) lc_abort(404, 'Chat not found');
    if ($chat['status'] === 'closed') lc_abort(409, 'Chat is closed');

    $tagCheck = $pdo->prepare("SELECT COUNT(*) AS cnt FROM chat_tags WHERE chat_id = ?");
    $tagCheck->execute([$chatId]);
    $tagRow = $tagCheck->fetch();
    $tagCount = $tagRow ? (int)$tagRow['cnt'] : 0;
    if ($tagCount === 0) {
      lc_abort(409, 'Please select at least one tag before sending a file');
    }

    $msgCount = $pdo->prepare("SELECT COUNT(*) AS cnt FROM messages WHERE chat_id = ? AND sender_type = 'customer'");
    $msgCount->execute([$chatId]);
    $msgRow = $msgCount->fetch();
    $customerMsgCount = $msgRow ? (int)$msgRow['cnt'] : 0;
    $isFirstMessage = ($customerMsgCount === 0);

    $stored = lc_store_uploaded_chat_file($_FILES['file']);
    $ins = $pdo->prepare("INSERT INTO messages (chat_id, sender_type, body) VALUES (?, 'customer', ?)");
    $ins->execute([$chatId, '[File] ' . $stored['original_name']]);
    $id = (int)$pdo->lastInsertId();

    $fin = $pdo->prepare("INSERT INTO message_files (message_id, original_name, storage_name, mime_type, size_bytes, storage_path) VALUES (?, ?, ?, ?, ?, ?)");
    $fin->execute([$id, $stored['original_name'], $stored['storage_name'], $stored['mime_type'], $stored['size_bytes'], $stored['storage_path']]);

    if ($isFirstMessage) {
      $thankYou = "Thank you, i will now find the right person to help you with your issue(s)";
      $pdo->prepare("INSERT INTO messages (chat_id, sender_type, body) VALUES (?, 'system', ?)")->execute([$chatId, $thankYou]);
    }

    $sel = $pdo->prepare("SELECT m.id, m.chat_id, m.sender_type, m.sender_employee_id, m.body, m.created_at, mf.id AS file_id, mf.original_name AS file_name, mf.mime_type AS file_mime, mf.size_bytes AS file_size_bytes FROM messages m LEFT JOIN message_files mf ON mf.message_id = m.id WHERE m.id = ? LIMIT 1");
    $sel->execute([$id]);
    return $sel->fetch();
  });

  lc_json_response(['ok' => true, 'message' => $msg]);
}

// ---- action=poll ----------------------------------------------------------
// Long-poll style endpoint that returns any new messages since `since_id`.
// Used as a fallback or complement to WebSockets.
if ($action === 'poll') {
  lc_require_method('GET');
  $chatId = lc_get_int('chat_id', 0);
  $sinceId = lc_get_int('since_id', 0);
  if ($chatId <= 0) lc_json_response(['ok' => false, 'error' => 'Invalid chat_id'], 400);

  $stmt = $pdo->prepare("SELECT id, status, assigned_employee_id, closed_at, updated_at FROM chats WHERE id = ? AND customer_token = ? LIMIT 1");
  $stmt->execute([$chatId, $customerToken]);
  $chat = $stmt->fetch();
  if (!$chat) lc_json_response(['ok' => false, 'error' => 'Chat not found'], 404);

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

  // Only show tags for open/taken chats, hide for closed
  $tags = ($chat['status'] === 'closed') ? [] : lc_fetch_chat_tags($pdo, $chatId);

  lc_json_response([
    'ok' => true,
    'chat' => [
      'id' => (int)$chat['id'],
      'status' => $chat['status'],
      'assigned_employee_id' => $chat['assigned_employee_id'] !== null ? (int)$chat['assigned_employee_id'] : null,
      'closed_at' => $chat['closed_at'],
      'updated_at' => $chat['updated_at'],
      'tags' => $tags,
    ],
    'messages' => $messages,
  ]);
}

// ---- action=mark_read -----------------------------------------------------
// Mark all messages in a chat as read by the customer.
if ($action === 'mark_read') {
  lc_require_method('POST');
  $data = lc_read_json_body();
  $chatId = isset($data['chat_id']) ? (int)$data['chat_id'] : 0;
  if ($chatId <= 0) lc_json_response(['ok' => false, 'error' => 'Invalid chat_id'], 400);

  // Verify the chat belongs to this customer
  $stmt = $pdo->prepare("SELECT id FROM chats WHERE id = ? AND customer_token = ? LIMIT 1");
  $stmt->execute([$chatId, $customerToken]);
  $chat = $stmt->fetch();
  if (!$chat) lc_json_response(['ok' => false, 'error' => 'Chat not found'], 404);

  // Mark all messages from employees as read by customer
  $pdo->prepare("
    UPDATE messages 
    SET customer_read_at = NOW()
    WHERE chat_id = ? AND sender_type = 'employee' AND customer_read_at IS NULL
  ")->execute([$chatId]);

  lc_json_response(['ok' => true]);
}

// ---- action=delete_chat ---------------------------------------------------
// Customer soft-deletes their chat (removes it from their view only).
// The chat remains visible to employees and the data is preserved.
// 
// Request body:
//   - chat_id: ID of the chat to delete (must belong to customer's token)
// 
// Response: {'ok': true, 'message': 'Chat deleted'}
// Errors: 400 (invalid chat_id), 404 (chat not found)
if ($action === 'delete_chat') {
  lc_require_method('POST');
  $data = lc_read_json_body();
  $chatId = isset($data['chat_id']) ? (int)$data['chat_id'] : 0;
  if ($chatId <= 0) lc_json_response(['ok' => false, 'error' => 'Invalid chat_id'], 400);

  // Ensure soft-delete columns exist (outside of transaction to avoid breaking it)
  try {
    $pdo->exec("ALTER TABLE chats ADD COLUMN employee_deleted_at TIMESTAMP NULL DEFAULT NULL AFTER closed_at");
  } catch (Throwable $e) {}
  try {
    $pdo->exec("ALTER TABLE chats ADD COLUMN customer_deleted_at TIMESTAMP NULL DEFAULT NULL AFTER employee_deleted_at");
  } catch (Throwable $e) {}

  lc_tx(function (PDO $pdo) use ($customerToken, $chatId) {
    $stmt = $pdo->prepare("SELECT id FROM chats WHERE id = ? AND customer_token = ? LIMIT 1 FOR UPDATE");
    $stmt->execute([$chatId, $customerToken]);
    $chat = $stmt->fetch();
    if (!$chat) {
      lc_abort(404, 'Chat not found');
    }

    // Soft delete - mark as deleted by customer
    $pdo->prepare("UPDATE chats SET customer_deleted_at = NOW() WHERE id = ?")->execute([$chatId]);
  });

  lc_json_response(['ok' => true, 'message' => 'Chat deleted']);
}

// ---- action=download_file -------------------------------------------------
// Download a file attached to a message in one of the customer's chats.
if ($action === 'download_file') {
  lc_require_method('GET');
  $fileId = lc_get_int('file_id', 0);
  $inline = lc_get_int('inline', 0) === 1;
  if ($fileId <= 0) lc_json_response(['ok' => false, 'error' => 'Invalid file_id'], 400);

  $stmt = $pdo->prepare("
    SELECT mf.id, mf.original_name, mf.mime_type, mf.size_bytes, mf.storage_path
    FROM message_files mf
    JOIN messages m ON m.id = mf.message_id
    JOIN chats c ON c.id = m.chat_id
    WHERE mf.id = ? AND c.customer_token = ?
    LIMIT 1
  ");
  $stmt->execute([$fileId, $customerToken]);
  $file = $stmt->fetch();
  if (!$file) lc_json_response(['ok' => false, 'error' => 'File not found'], 404);

  lc_output_chat_file_download($file, $inline);
}

lc_json_response(['ok' => false, 'error' => 'Unknown action'], 400);


