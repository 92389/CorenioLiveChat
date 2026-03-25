<?php
declare(strict_types=1);

// Apply security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Simple admin page that allows the primary admin account to grant
// or revoke `is_admin` permissions for other employee accounts.

require_once __DIR__ . '/../lib/employee_auth.php';
require_once __DIR__ . '/../lib/db.php';

// Prevent caching to ensure fresh data
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$employeeId = lc_employee_id();
if ($employeeId === null) {
  header('Location: login.php');
  exit;
}

$pdo = lc_pdo();

$availableChannels = ['website', 'whatsapp', 'email', 'facebook', 'instagram', 'other'];

// Ensure all-channels permission column exists.
try {
  $pdo->exec("ALTER TABLE employees ADD COLUMN can_access_all_channels TINYINT(1) NOT NULL DEFAULT 0 AFTER can_grant_admin");
} catch (Throwable $e) {}

// Ensure channel access permissions table exists.
$pdo->exec("CREATE TABLE IF NOT EXISTS employee_channel_access (
  employee_id INT UNSIGNED NOT NULL,
  source_channel ENUM('website','whatsapp','email','facebook','instagram','other') NOT NULL,
  PRIMARY KEY (employee_id, source_channel),
  KEY idx_employee_channel_access_channel (source_channel),
  CONSTRAINT fk_employee_channel_access_employee FOREIGN KEY (employee_id) REFERENCES employees(id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// Ensure the current employee is allowed to manage admin permissions.
$stmt = $pdo->prepare("SELECT id, username, display_name, can_grant_admin, can_access_all_channels FROM employees WHERE id = ? LIMIT 1");
$stmt->execute([$employeeId]);
$current = $stmt->fetch();
if (!$current || (int)$current['can_grant_admin'] !== 1) {
  http_response_code(403);
  echo 'Forbidden: you are not allowed to manage admin permissions.';
  exit; 
}

$message = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  // Handle activation/deactivation
  if (isset($_POST['toggle_active_id'])) {
    $toggleId = (int)$_POST['toggle_active_id'];
    if ($toggleId > 0 && $toggleId !== (int)$current['id']) {
      try {
        // Toggle the active status
        $stmt = $pdo->prepare("UPDATE employees SET is_active = NOT is_active WHERE id = ? AND can_grant_admin = 0");
        $stmt->execute([$toggleId]);
        $message = 'Employee status updated successfully.';
      } catch (Throwable $e) {
        $message = 'Failed to update employee status.';
      }
    }
  } else {
    // IDs that should have `is_admin = 1`
    $ids = isset($_POST['admin_ids']) && is_array($_POST['admin_ids']) ? $_POST['admin_ids'] : [];
    $ids = array_values(array_unique(array_map('intval', $ids)));

    // Channel access matrix from the form.
    $postedChannelAccess = isset($_POST['channel_access']) && is_array($_POST['channel_access']) ? $_POST['channel_access'] : [];
    $allChannelIds = isset($_POST['all_channels_ids']) && is_array($_POST['all_channels_ids'])
      ? array_values(array_unique(array_map('intval', $_POST['all_channels_ids'])))
      : [];
    $channelPairs = [];
    foreach ($postedChannelAccess as $empIdRaw => $channels) {
      $empId = (int)$empIdRaw;
      if ($empId <= 0 || !is_array($channels)) {
        continue;
      }
      foreach ($channels as $channel) {
        if (is_string($channel) && in_array($channel, $availableChannels, true)) {
          $channelPairs[] = [$empId, $channel];
        }
      }
    }

    // Do not allow the current super admin row to lose admin completely by mistake.
    if (!in_array((int)$current['id'], $ids, true)) {
      $ids[] = (int)$current['id'];
    }

    $pdo->beginTransaction();
    try {
      // First clear admin flag on all employees. This ensures that any IDs not included in the submitted 
      // form will have their admin rights revoked, while the ones included will be set to admin in the next step. 
      // This approach is simpler than trying to calculate the diffrence and only update the 
      // changed ones, and since this is a low-traffic admin page, the performance impact should be minimal.
      $pdo->exec("UPDATE employees SET is_admin = 0");

      // Then set it for the selected IDs.
      if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("UPDATE employees SET is_admin = 1 WHERE id IN ($placeholders)");
        $stmt->execute($ids);
      }

      // Reset and apply all-channel permission.
      $pdo->exec("UPDATE employees SET can_access_all_channels = 0");
      if (!empty($allChannelIds)) {
        $placeholders = implode(',', array_fill(0, count($allChannelIds), '?'));
        $stmt = $pdo->prepare("UPDATE employees SET can_access_all_channels = 1 WHERE id IN ($placeholders)");
        $stmt->execute($allChannelIds);
      }

      // Replace channel access matrix with submitted values.
      $pdo->exec("DELETE FROM employee_channel_access");
      if (!empty($channelPairs)) {
        $insAccess = $pdo->prepare("INSERT IGNORE INTO employee_channel_access (employee_id, source_channel) VALUES (?, ?)");
        foreach ($channelPairs as [$empId, $channel]) {
          $insAccess->execute([$empId, $channel]);
        }
      }

      // Ensure primary admin(s) keep access to all channels.
      $primaryAdminRows = $pdo->query("SELECT id FROM employees WHERE can_grant_admin = 1")->fetchAll();
      if (!empty($primaryAdminRows)) {
        $primaryAdminIds = [];
        $insAccess = $pdo->prepare("INSERT IGNORE INTO employee_channel_access (employee_id, source_channel) VALUES (?, ?)");
        foreach ($primaryAdminRows as $row) {
          $primaryAdminId = (int)$row['id'];
          $primaryAdminIds[] = $primaryAdminId;
          foreach ($availableChannels as $channel) {
            $insAccess->execute([$primaryAdminId, $channel]);
          }
        }
        if (!empty($primaryAdminIds)) {
          $placeholders = implode(',', array_fill(0, count($primaryAdminIds), '?'));
          $stmt = $pdo->prepare("UPDATE employees SET can_access_all_channels = 1 WHERE id IN ($placeholders)");
          $stmt->execute($primaryAdminIds);
        }
      }

      $pdo->commit();
      $message = 'Admin and channel permissions updated successfully.';
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      $message = 'Failed to update permissions.';
    }
  }
}

// Reload employees list after any changes. 
// This also ensures we have the latest data for display, and that any changes are reflected immediately.
$stmt = $pdo->query("SELECT id, username, display_name, is_active, is_admin, can_grant_admin, can_access_all_channels FROM employees ORDER BY username ASC");
$employees = $stmt->fetchAll();

// Build lookup map: employee_id => [channel => true].
$channelAccessMap = [];
$accessRows = $pdo->query("SELECT employee_id, source_channel FROM employee_channel_access")->fetchAll();
foreach ($accessRows as $row) {
  $empId = (int)$row['employee_id'];
  $channel = (string)$row['source_channel'];
  if (!isset($channelAccessMap[$empId])) {
    $channelAccessMap[$empId] = [];
  }
  $channelAccessMap[$empId][$channel] = true;
}

function lc_channel_label(string $channel): string {
  $labels = [
    'website' => 'Website',
    'whatsapp' => 'WhatsApp',
    'email' => 'Email',
    'facebook' => 'Facebook',
    'instagram' => 'Instagram',
    'other' => 'Other',
  ];
  return $labels[$channel] ?? ucfirst($channel);
}

?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Admin - Employee Management</title>
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
          <h1>Employee Admin Permissions</h1>
        </div>

        <?php $activeAdminNav = 'permissions'; ?>
        <?php require __DIR__ . '/admin_nav.php'; ?>

        <p class="adminUserInfo">
          You are logged in as
          <strong><?= htmlspecialchars((string)$current['display_name'], ENT_QUOTES) ?></strong>
          (<?= htmlspecialchars((string)$current['username'], ENT_QUOTES) ?>).
          This account is the primary admin and can grant or revoke admin permissions
          for other employees.
        </p>

      <?php if ($message !== ''): ?>
        <div class="<?= strpos($message, 'Failed') === false ? 'msgSuccess' : 'msgError' ?>">
          <?= htmlspecialchars($message, ENT_QUOTES) ?>
        </div>
      <?php endif; ?>

      <form method="post" id="adminForm">
        <table class="adminTable">
          <thead>
            <tr>
              <th class="adminTableHeaderAdmin">Admin</th>
              <th>Username</th>
              <th>Display name</th>
              <th>Status</th>
              <th>Channel Access</th>
              <th class="adminTableHeaderNotes">Notes</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($employees as $emp): ?>
              <tr>
                <td class="adminTableCellCenter">
                  <input
                    type="checkbox"
                    name="admin_ids[]"
                    value="<?= (int)$emp['id'] ?>"
                    <?= (int)$emp['is_admin'] === 1 ? 'checked' : '' ?>
                  />
                </td>
                <td><?= htmlspecialchars((string)$emp['username'], ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars((string)$emp['display_name'], ENT_QUOTES) ?></td>
                <td>
                  <?php if ((int)$emp['can_grant_admin'] === 1): ?>
                    <span class="badge badgeSuper">Primary admin</span>
                  <?php else: ?>
                    <input type="hidden" name="toggle_active_id_<?= (int)$emp['id'] ?>" value="0" />
                    <button type="button" class="btn <?= (int)$emp['is_active'] === 1 ? 'danger' : '' ?>" style="padding: 4px 8px; font-size: 12px;" onclick="toggleEmployee(event, <?= (int)$emp['id'] ?>)">
                      <?= (int)$emp['is_active'] === 1 ? 'Deactivate' : 'Activate' ?>
                    </button>
                    <span style="margin-left: 8px;"><?= (int)$emp['is_active'] === 1 ? 'Active' : 'Inactive' ?></span>
                  <?php endif; ?>
                </td>
                <td>
                  <div class="channelPerms">
                    <?php
                      $allChannelsChecked = (int)$emp['can_access_all_channels'] === 1;
                      if ((int)$emp['can_grant_admin'] === 1) {
                        $allChannelsChecked = true;
                      }
                    ?>
                    <label class="channelPermItem channelPermItemAll">
                      <input
                        type="checkbox"
                        name="all_channels_ids[]"
                        value="<?= (int)$emp['id'] ?>"
                        <?= $allChannelsChecked ? 'checked' : '' ?>
                        <?= (int)$emp['can_grant_admin'] === 1 ? 'disabled' : '' ?>
                      />
                      <span>All Channels</span>
                    </label>
                    <?php foreach ($availableChannels as $channel): ?>
                      <?php
                        $isChecked = isset($channelAccessMap[(int)$emp['id']][$channel]);
                        if ((int)$emp['can_grant_admin'] === 1) {
                          $isChecked = true;
                        }
                      ?>
                      <label class="channelPermItem">
                        <input
                          type="checkbox"
                          name="channel_access[<?= (int)$emp['id'] ?>][]"
                          value="<?= htmlspecialchars($channel, ENT_QUOTES) ?>"
                          <?= $isChecked ? 'checked' : '' ?>
                          <?= (int)$emp['can_grant_admin'] === 1 ? 'disabled' : '' ?>
                        />
                        <span><?= htmlspecialchars(lc_channel_label($channel), ENT_QUOTES) ?></span>
                      </label>
                    <?php endforeach; ?>
                  </div>
                </td>
                <td>
                  <?php if ((int)$emp['can_grant_admin'] === 1): ?>
                    <span class="badge badgeSuper">Primary admin</span>
                  <?php elseif ((int)$emp['is_admin'] === 1): ?>
                    <span class="badge">Admin</span>
                  <?php else: ?>
                    &nbsp;
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <p class="adminHelpText">
          <strong>Admin:</strong> Ticking a row marks that employee as an admin. The primary admin account
          (with the green badge) keeps its admin rights even if you untick it.
        </p>
        <p class="adminHelpText">
          <strong>Activate/Deactivate:</strong> New employees are registered as inactive. Use the Activate button
          to allow them to log in and take chats. Deactivating an employee prevents them from logging in.
        </p>
        <p class="adminHelpText">
          <strong>Channel Access:</strong> Tick the channels each employee can access in the chat manager.
          If none are ticked for an employee, they will not see chats from any channel.
        </p>

        <button class="btn primary" type="submit">Save changes</button>
      </form>

      <script>
        function toggleEmployee(event, employeeId) {
          event.preventDefault();
          const form = document.getElementById('adminForm');
          const input = document.createElement('input');
          input.type = 'hidden';
          input.name = 'toggle_active_id';
          input.value = String(employeeId);
          form.appendChild(input);
          form.submit();
        }
      </script>
    </div>
  </body>
  </html>
