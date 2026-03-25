<?php
declare(strict_types=1);

// Apply security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Chat management admin page - allows deleting closed chats and viewing chat history. 

require_once __DIR__ . '/../lib/employee_auth.php';
require_once __DIR__ . '/../lib/db.php';

// Prevent caching to ensure fresh data so no old data stays beheind and interferes with the new data.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$employeeId = lc_employee_id();
if ($employeeId === null) {
  header('Location: login.php');
  exit;
}

$pdo = lc_pdo();

// Ensure chat source column exists for channel attribution.
try {
  $pdo->exec("ALTER TABLE chats ADD COLUMN source_channel ENUM('website','whatsapp','email','facebook','instagram','other') NOT NULL DEFAULT 'website' AFTER customer_name");
} catch (Throwable $e) {}

// Ensure the current employee is an admin, so they can acces the page and admin permissions. 
$stmt = $pdo->prepare("SELECT id, display_name, is_admin FROM employees WHERE id = ? LIMIT 1");
$stmt->execute([$employeeId]);
$current = $stmt->fetch();
if (!$current || (int)$current['is_admin'] !== 1) {
  http_response_code(403);
  echo 'Forbidden: you must be an admin to access this page.';
  exit;
}

$message = '';
$messageType = '';

// Handle chat deletion, by getting rid of everything related to the chat (messages, tags, reads) to keep the database clean.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  $chatIds = isset($_POST['delete_chat_ids']) && is_array($_POST['delete_chat_ids']) ? $_POST['delete_chat_ids'] : [];
  $chatIds = array_values(array_unique(array_map('intval', $chatIds)));

  if (!empty($chatIds)) {
    try {
      $pdo->beginTransaction();
      
      // Delete associated messages first to avoid foreign key constraint issues, since messages depend on the chat existing, 
      // so we need to delete them first before deleting the chat itself, and if any part of this process fails, 
      // the transaction will be rolled back to maintain data integrity and prevent orphanes records.
      $placeholders = implode(',', array_fill(0, count($chatIds), '?'));
      $pdo->prepare("DELETE FROM messages WHERE chat_id IN ($placeholders)")->execute($chatIds);
      
      // Delete chat tags - this is important to keep the database clean and prevent orphaned tag associations, 
      // since chat_tags depend on the chat existing,
      //and if they are deleted, we would have foreign key constraint issues with the dependent records (messages, tags, reads), 
      // and if any part of this process fails, 
      // the transaction will be rolled back to maintain data integrity and prevent orphanes records.
      $pdo->prepare("DELETE FROM chat_tags WHERE chat_id IN ($placeholders)")->execute($chatIds);
      
      // Delete employee reads - this is important to keep the database clean and prevent orphaned read records, 
      // since employee_chat_reads depend on the chat existing, and if they are not deleted,
      $pdo->prepare("DELETE FROM employee_chat_reads WHERE chat_id IN ($placeholders)")->execute($chatIds);
      
      // Delete chats last, since they are the parent records, and if we deleted them first, 
      // we would have foreign key constraint issues with the dependent records (messages, tags, reads), 
      // and if any part of this process fails, 
      // the transaction will be rolled back to maintain data integrity and prevent orphanes records.
      $pdo->prepare("DELETE FROM chats WHERE id IN ($placeholders)")->execute($chatIds);
      
      $pdo->commit();
      $message = 'Selected chats deleted successfully.';
      $messageType = 'success';
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      $message = 'Failed to delete chats: ' . $e->getMessage();
      $messageType = 'error';
    }
  }
}

// Fetch all chats, grouped by status and including the assigned employee's name and tags for better visibility in the admin interface, 
// so admins can easily see the chat details and manage them effectively.
$stmt = $pdo->query("
  SELECT c.id, c.customer_name, c.source_channel, c.status, c.assigned_employee_id, c.created_at, c.updated_at, c.closed_at, e.display_name AS assigned_employee_name,
    (SELECT COUNT(*) FROM messages WHERE chat_id = c.id) as message_count,
    (SELECT GROUP_CONCAT(t.name SEPARATOR ', ') FROM chat_tags ct JOIN tags t ON ct.tag_id = t.id AND t.is_active = 1 WHERE ct.chat_id = c.id) as tag_names
  FROM chats c
  LEFT JOIN employees e ON c.assigned_employee_id = e.id
  ORDER BY c.status DESC, c.updated_at DESC
");
$chats = $stmt->fetchAll();

// This is were all the chats are grouped by status to make it easier to display them in seperate sections in the admin interface, 
// so admins can easily see the open, taken and closed chats in their own sections and managethem effectively. 
// This also allows for better performance when displaying the chats, 
// since we can just loop through each status group instead of filtering them in PHP while rendering the HTML, 
// which can be inefficient with large datasets.
$chatsByStatus = ['open' => [], 'taken' => [], 'closed' => []];
foreach ($chats as $chat) {
  $status = $chat['status'];
  if (isset($chatsByStatus[$status])) {
    $chatsByStatus[$status][] = $chat;
  }
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Chat Management</title>
    <link rel="stylesheet" href="../assets/employee.css" />
  </head>
  <body>
    <div class="topbar">
      <div class="brand">
        <span>Employee Admin</span>
      </div>
      <div class="me">
        <span class="meName"><?= htmlspecialchars((string)$current['display_name'], ENT_QUOTES) ?></span>
        <a class="link" href="logout.php">Logout</a>
      </div>
    </div>
    <div class="adminPageContent">
      <div class="adminContainer">
        <div class="adminHeader">
          <h1>Chat Management</h1>
        </div>

        <?php $activeAdminNav = 'chats'; ?>
        <?php require __DIR__ . '/admin_nav.php'; ?>

        <p class="adminUserInfo">
          You are logged in as
          <strong><?= htmlspecialchars((string)$current['display_name'], ENT_QUOTES) ?></strong>.
          Use this page to delete closed chats and view chat history.
        </p>

        <?php if ($message !== ''): ?>
          <div class="<?= $messageType === 'success' ? 'msgSuccess' : 'msgError' ?>">
            <?= htmlspecialchars($message, ENT_QUOTES) ?>
          </div>
        <?php endif; ?>

        <form method="post">
          <?php foreach (['open', 'taken', 'closed'] as $status): ?>
            <?php $statusChats = $chatsByStatus[$status]; ?>
            <h2><?= ucfirst($status) ?> Chats (<?= count($statusChats) ?>)</h2>
            
            <?php if (empty($statusChats)): ?>
              <p style="opacity: 0.7;">No <?= $status ?> chats.</p>
            <?php else: ?>
              <table class="adminTable">
                <thead>
                  <tr>
                    <?php if ($status === 'closed'): ?>
                      <th style="width: 40px;"><input type="checkbox" id="selectAll<?= $status ?>" /></th>
                    <?php endif; ?>
                    <th>Chat ID</th>
                    <th>Customer</th>
                    <th>Source</th>
                    <th>Assigned to</th>
                    <th>Tags</th>
                    <th>Messages</th>
                    <th>Created</th>
                    <th>Updated</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($statusChats as $chat): ?>
                    <tr>
                      <?php if ($status === 'closed'): ?>
                        <td class="adminTableCellCenter">
                          <input type="checkbox" name="delete_chat_ids[]" value="<?= (int)$chat['id'] ?>" class="chatCheckbox<?= $status ?>" />
                        </td>
                      <?php endif; ?>
                      <td><strong>#<?= (int)$chat['id'] ?></strong></td>
                      <td><?= htmlspecialchars($chat['customer_name'] ?? 'Anonymous', ENT_QUOTES) ?></td>
                      <td><?= htmlspecialchars(ucfirst((string)($chat['source_channel'] ?? 'website')), ENT_QUOTES) ?></td>
                      <td><?= htmlspecialchars($chat['assigned_employee_name'] ?? '—', ENT_QUOTES) ?></td>
                      <td><small><?= htmlspecialchars($chat['tag_names'] ?? '—', ENT_QUOTES) ?></small></td>
                      <td><?= (int)$chat['message_count'] ?></td>
                      <td><small><?= (new DateTime($chat['created_at']))->format('M d, H:i') ?></small></td>
                      <td><small><?= (new DateTime($chat['updated_at']))->format('M d, H:i') ?></small></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          <?php endforeach; ?>

          <div style="margin-top: 20px;">
            <button class="btn danger" type="submit" onclick="return confirm('Delete selected closed chats? This cannot be undone.');">Delete Selected Closed Chats</button>
          </div>
        </form>

        <script>
          // Add "select all" functionality for closed chats
          const selectAll = document.getElementById('selectAllclosed');
          if (selectAll) {
            selectAll.addEventListener('change', function() {
              document.querySelectorAll('.chatCheckboxclosed').forEach(cb => {
                cb.checked = this.checked;
              });
            });
          }
        </script>
      </div>
    </div>
  </body>
</html>
