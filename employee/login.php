<?php
declare(strict_types=1);

// Apply security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Simple form-based login page for employees.
// On success we redirect to `employee/index.php`, which hosts the dashboard.
require_once __DIR__ . '/../lib/employee_auth.php';

lc_employee_session_start();

// If already authenticated in this browser session, do not allow account
// switching from the login form in another tab.
if (lc_employee_id() !== null) {
  header('Location: index.php');
  exit;
}

// Generate CSRF token for form protection
$csrfToken = lc_generate_csrf_token();

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  // Validate CSRF token
  $submittedToken = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
  if (!lc_validate_csrf_token($submittedToken)) {
    $error = 'Invalid request. Please try again.';
  } else {
    // Basic username/password authentication using helper from employee_auth.php.
    $username = isset($_POST['username']) ? trim((string)$_POST['username']) : '';
    $password = isset($_POST['password']) ? (string)$_POST['password'] : '';
    if (lc_employee_login($username, $password)) {
      header('Location: index.php');
      exit;
    }
    // Use custom error message if account is inactive or other issue, otherwise show generic message. 
    global $login_error_message;
    $error = $login_error_message ?: 'Invalid username or password.';
  }
}
// basic login form to seperate the employees from the customer because they have diffrent permissions. 
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Employee Login</title>
    <link rel="stylesheet" href="../assets/employee.css" />
  </head>
  <body>
    <div class="wrap">
      <form class="card" method="post" autocomplete="on">
        <h1>Employee Login</h1>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>" />
        <label>Username</label>
        <input name="username" required />
        <label>Password</label>
        <input name="password" type="password" required />
        <button type="submit">Sign in</button>
        <?php if ($error !== ''): ?>
          <div class="err"><?= htmlspecialchars($error, ENT_QUOTES) ?></div>
        <?php endif; ?>
        <div class="hint">
          New employee? <a href="register.php">Register here</a>
        </div>
        <div>
          <a href="../index.php">← Back to customer view</a>
        </div>
      </form>
    </div>
  </body>
</html>
