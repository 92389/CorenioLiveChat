<?php
declare(strict_types=1);

// Apply security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Employee dashboard shell page.
// The HTML here just renders the layout; all dynamic behaviour is implemented
// in `assets/employee.js` which calls the employee API endpoints.
require_once __DIR__ . '/../lib/employee_auth.php';
require_once __DIR__ . '/../lib/db.php';

// Prevent caching to ensure fresh data so no old data stays beheind and interferes with the new data.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Redirect to login if there is no authenticated employee session. 
// This is important for securituy to prevent unauthorized access to the dashboard.
$employeeId = lc_employee_id();
if ($employeeId === null) {
  header('Location: login.php');
  exit;
}

// Fetch the employee's display name + admin flags for use in the top-right corner. 
// This is used for system messages and to conditionally show admin features in the dashboard, 
// and also to provide a better user experience by showing the employee's name instead of just "Employee".
$pdo = lc_pdo();

// Try to fetch with admin columns, fall back if they don't exist (older schema) to maintain compatibility and avoid errors 
// if the database hasn't been migrated yet, while still showing the dashboard with basic functionality.
$me = null;
$meName = 'Employee';
$isSuperAdmin = false;
$isAdmin = false;

try {
  $stmt = $pdo->prepare("SELECT id, display_name, is_admin, can_grant_admin FROM employees WHERE id = ? LIMIT 1");
  $stmt->execute([$employeeId]);
  $me = $stmt->fetch();
  if ($me) {
    $meName = (string)$me['display_name'];
    $isSuperAdmin = isset($me['can_grant_admin']) && (int)$me['can_grant_admin'] === 1;
    $isAdmin = isset($me['is_admin']) && (int)$me['is_admin'] === 1;
  }
} catch (PDOException $e) {
  // If the query fails (e.g., columns don't exist), try without admin columns to at least get the display name, 
  // so the dashboard can still be used with limited functionality even if the database schema is not fully up to date, 
  // while also showing the employee's name in the top-right corner for a better user experience.
  // This is for older databases or if the database hasn't been migrated yet, 
  // and allows the dashboard to still function with basic features while showing the employee's name, 
  // even if the admin features are not available until the migration is run.
  try {
    $stmt = $pdo->prepare("SELECT id, display_name FROM employees WHERE id = ? LIMIT 1");
    $stmt->execute([$employeeId]);
    $me = $stmt->fetch(); 
    if ($me) {
      $meName = (string)$me['display_name'];
    }
  } catch (PDOException $e2) {
    // Database error - continue with defaults to avoid breaking the dashboard, 
    // but the employee will just see "Employee" int the top-right corner and no admin features, 
    // which is better than a broken page.
  }
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Live Chat - Employee</title>
    <link rel="stylesheet" href="../assets/employee.css" />
  </head>
  <body>
    <header class="topbar">
      <div class="brand">
        Live Chat
        <span id="globalUnreadBadge" class="globalUnreadBadge">0</span>
      </div>
      <div class="me">
        <span class="meName"><?= htmlspecialchars($meName, ENT_QUOTES) ?></span>
        <?php if ($isSuperAdmin || $isAdmin): ?>
          <a class="link" href="admin.php">Admin</a>
        <?php endif; ?>
        <a class="link" href="logout.php">Logout</a>
      </div>
    </header>

    <main class="layout">
      <aside class="sidebar">
        <div class="filters">
          <div class="filterSection">
            <span class="filterLabel">Channels</span>
            <div class="channelChipsRow" role="group" aria-label="Filter by channel">
              <button class="channelChip active" data-channel="all" type="button">All Channels</button>
              <button class="channelChip" data-channel="website" type="button">Website</button>
              <button class="channelChip" data-channel="whatsapp" type="button">WhatsApp</button>
              <button class="channelChip" data-channel="email" type="button">Email</button>
              <button class="channelChip" data-channel="facebook" type="button">Facebook</button>
              <button class="channelChip" data-channel="instagram" type="button">Instagram</button>
              <button class="channelChip" data-channel="other" type="button">Other</button>
            </div>
          </div>
          <div class="filterDivider" aria-hidden="true"></div>
          <div class="filterSection">
            <span class="filterLabel">Chat Status</span>
            <div class="statusChipsRow" role="group" aria-label="Filter by chat status">
            <button class="chip active" data-status="all">All</button>
            <button class="chip" data-status="open">Open</button>
            <button class="chip" data-status="taken">Taken</button>
            <button class="chip" data-status="closed">Closed</button>
            </div>
          </div>
        </div>
        <div id="chatList" class="chatList"></div>
      </aside>

      <section class="chatPane">
        <div class="chatHeader">
          <div>
            <div id="chatTitle" class="chatTitle">Select a chat</div>
            <div id="chatMeta" class="chatMeta"></div>
          </div>
          <div class="chatActions">
            <button id="takeBtn" class="btn success" disabled>Take</button>
            <button id="closeBtn" class="btn danger" disabled>Close</button>
            <button id="reopenBtn" class="btn success" disabled>Reopen</button>
            <button id="deleteBtn" class="btn danger" disabled>Delete</button>
          </div>
        </div>

        <div id="messages" class="messages"></div>
        
        <div id="typingIndicator" class="typingIndicator" style="display: none;">
          <div class="typingDots">
            <span></span><span></span><span></span>
          </div>
          Customer is typing…
        </div>

        <form id="sendForm" class="composer">
          <input id="msgInput" class="msgInput" placeholder="Type a message…" autocomplete="off" disabled />
          <button class="btn primary" type="button" disabled id="fileBtn" title="Attach file" aria-label="Attach file">+</button>
          <input id="fileInput" type="file" style="display: none;" disabled />
          <button class="btn primary" type="submit" disabled id="sendBtn">Send</button>
        </form>
      </section>
    </main>

    <script>
      // Tell the dashboard JavaScript where to find the employee API endpoint.
      window.LC_EMPLOYEE_API = "../api/employee.php";
      window.LC_EMPLOYEE_ID = <?= (int)$employeeId ?>;
      window.LC_EMPLOYEE_IS_ADMIN = <?= ($isSuperAdmin || $isAdmin) ? 'true' : 'false' ?>;
    </script>
    <script src="../assets/employee.js"></script>
  </body>
</html>
