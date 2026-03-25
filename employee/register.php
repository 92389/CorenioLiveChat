<?php
declare(strict_types=1);

// Apply security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Employee registration page.
// On success we redirect to `login.php`.
require_once __DIR__ . '/../lib/employee_auth.php';

lc_employee_session_start();

// Generate CSRF token for form protection
$csrfToken = lc_generate_csrf_token();

$error = '';
$success = false;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  // Validate CSRF token
  $submittedToken = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
  if (!lc_validate_csrf_token($submittedToken)) {
    $error = 'Invalid request. Please try again.';
  } else {
    $username = isset($_POST['username']) ? trim((string)$_POST['username']) : '';
    $displayName = isset($_POST['display_name']) ? trim((string)$_POST['display_name']) : '';
    $password = isset($_POST['password']) ? (string)$_POST['password'] : '';
    $confirmPassword = isset($_POST['confirm_password']) ? (string)$_POST['confirm_password'] : '';

    if ($password !== $confirmPassword) {
      $error = 'Passwords do not match.';
    } else {
      $result = lc_employee_register($username, $displayName, $password);
      if ($result['ok']) {
        $success = true;
      } else {
        $error = $result['error'];
      }
    }
  }
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Employee Registration</title>
    <link rel="stylesheet" href="../assets/employee.css" />
  </head>
  <body>
    <div class="wrap">
      <?php if ($success): ?>
        <div class="card">
          <h1>Registration Successful</h1>
          <p>Your employee account has been created and is pending approval.</p>
          <p>Please wait for an admin to activate your account.</p>
          <p><a href="login.php">Click here to sign in</a></p>
        </div>
      <?php else: ?>
        <form class="card" method="post" autocomplete="on">
          <h1>Employee Registration</h1>
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>" />
          <label>Username</label>
          <input name="username" required maxlength="64" />
          <label>Display Name</label>
          <input name="display_name" required maxlength="128" />
          <label>Password</label>
          <input name="password" type="password" required minlength="8" pattern="^(?=.*[A-Za-z])(?=.*[0-9]).{8,}$" title="Password must be at least 8 characters with at least one letter and one number" />
          <label>Confirm Password</label>
          <input name="confirm_password" type="password" required minlength="8" />
          <button type="submit">Register</button>
          <?php if ($error !== ''): ?>
            <div class="err"><?= htmlspecialchars($error, ENT_QUOTES) ?></div>
          <?php endif; ?>
          <div class="hint">
            Already have an account? <a href="login.php">Sign in</a>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </body>
</html>
