<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/http.php';

// Rate limiting configuration
define('LC_LOGIN_RATE_LIMIT_MAX_ATTEMPTS', 5);
define('LC_LOGIN_RATE_LIMIT_WINDOW_SECONDS', 300); // 5 minutes

/**
 * Start a PHP session for employee authentication (if not already active).
 * Configures secure session settings for production use.
 */
function lc_employee_session_start(): void {
  if (session_status() === PHP_SESSION_NONE) {
    // Set secure session cookie parameters before starting session
    session_set_cookie_params([
      'lifetime' => 0,
      'path' => '/',
      'domain' => '',
      'secure' => true,
      'httponly' => true,
      'samesite' => 'Strict',
    ]);
    session_start();
  }

  // Keep lightweight session metadata only. Session ID regeneration is done
  // at login time to avoid cross-tab/session races during frequent API polling.
  if (!isset($_SESSION['lc_session_created'])) {
    $_SESSION['lc_session_created'] = time();
  }
}

/**
 * Get the current authenticated employee id or null if not logged in.
 * This also verifies the session against the database to ensure the session is still valid (e.g., account not deactivated).
 */
function lc_employee_id(): ?int {
  lc_employee_session_start();
  
  // Check if session exists and has a valid employee ID in it  
  // This is the primary check for whether the user is logged in as an employee.
  if (!isset($_SESSION['lc_employee_id'])) {
    return null;
  }
  
  $id = $_SESSION['lc_employee_id'];
  if (!is_int($id)) {
    return null;
  }
  
  // Verify the session is still valid by checking the database for the employee's active status and admin flags, 
  // so if an account is deactivated or deleted, the session becomes invalid immediately.
  try {
    $pdo = lc_pdo();
    // Also fetch admin flags so admins can bypass the is_active check
    $stmt = $pdo->prepare('SELECT id, is_active, is_admin, can_grant_admin FROM employees WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    
    if (!$row || (int)$row['is_active'] !== 1) {
      // If account is inactive, allow it only for admin accounts even if is_active is not set, 
      // so admins can still log in to fix issues.
      $isAdmin = $row && ((int)($row['is_admin'] ?? 0) === 1 || (int)($row['can_grant_admin'] ?? 0) === 1);
      if (!$isAdmin) {
        // If the session is invalid - clear it
        $_SESSION = [];
        return null;
      }
    }
  } catch (Exception $e) {
    // Database error - fail safely and clear the session to prevent potential issues
    // If the session is invalid - clear it
    return null;
  }
  
  return $id;
}

/**
 * Ensure that there is a logged-in employee in the current session.
 *
 * On failure this sends a 401 JSON response and terminates the request.
 */
function lc_require_employee(): int {
  $id = lc_employee_id();
  if ($id === null) {
    lc_json_response(['ok' => false, 'error' => 'Not authenticated'], 401);
  }
  return $id;
}

/**
 * Attempt to log an employee in using username + password and set the session accordingly.
 *
 * On success the employee id is stored in the session and `true` is returned to indicate successful login.
 * On failure, returns false. If account is inactive, sets $login_error_message.
 */
$login_error_message = '';

/**
 * Check if the client has exceeded the rate limit for login attempts.
 * Returns true if rate limited, false if within limits.
 */
function lc_is_rate_limited(string $identifier): bool {
  lc_employee_session_start();
  
  $key = 'lc_login_attempts_' . $identifier;
  $now = time();
  
  if (!isset($_SESSION[$key])) {
    $_SESSION[$key] = ['count' => 0, 'first_attempt' => $now, 'locked_until' => null];
  }
  
  $attempts = &$_SESSION[$key];
  
  // Check if currently locked
  if ($attempts['locked_until'] !== null && $now < $attempts['locked_until']) {
    return true;
  }
  
  // Reset if window has expired
  if ($now - $attempts['first_attempt'] > LC_LOGIN_RATE_LIMIT_WINDOW_SECONDS) {
    $attempts = ['count' => 0, 'first_attempt' => $now, 'locked_until' => null];
  }
  
  return $attempts['count'] >= LC_LOGIN_RATE_LIMIT_MAX_ATTEMPTS;
}

/**
 * Record a failed login attempt for rate limiting.
 */
function lc_record_failed_attempt(string $identifier): void {
  lc_employee_session_start();
  
  $key = 'lc_login_attempts_' . $identifier;
  $now = time();
  
  if (!isset($_SESSION[$key])) {
    $_SESSION[$key] = ['count' => 0, 'first_attempt' => $now, 'locked_until' => null];
  }
  
  $attempts = &$_SESSION[$key];
  $attempts['count']++;
  
  // Lock the account if max attempts reached
  if ($attempts['count'] >= LC_LOGIN_RATE_LIMIT_MAX_ATTEMPTS) {
    $attempts['locked_until'] = $now + LC_LOGIN_RATE_LIMIT_WINDOW_SECONDS;
  }
}

/**
 * Clear rate limit after successful login.
 */
function lc_clear_rate_limit(string $identifier): void {
  lc_employee_session_start();
  
  $key = 'lc_login_attempts_' . $identifier;
  unset($_SESSION[$key]);
}

/**
 * Generate a CSRF token for form protection.
 */
function lc_generate_csrf_token(): string {
  lc_employee_session_start();
  
  if (!isset($_SESSION['lc_csrf_token'])) {
    $_SESSION['lc_csrf_token'] = bin2hex(random_bytes(32));
  }
  
  return $_SESSION['lc_csrf_token'];
}

/**
 * Validate a CSRF token.
 * Returns true if valid, false otherwise.
 */
function lc_validate_csrf_token(string $token): bool {
  lc_employee_session_start();
  
  if (!isset($_SESSION['lc_csrf_token'])) {
    return false;
  }
  
  return hash_equals($_SESSION['lc_csrf_token'], $token);
}

/**
 * Get the rate limit error message if rate limited.
 */
function lc_get_rate_limit_message(string $identifier): string {
  lc_employee_session_start();
  
  $key = 'lc_login_attempts_' . $identifier;
  
  if (!isset($_SESSION[$key]) || $_SESSION[$key]['locked_until'] === null) {
    return '';
  }
  
  $remaining = $_SESSION[$key]['locked_until'] - time();
  if ($remaining <= 0) {
    return '';
  }
  
  $minutes = ceil($remaining / 60);
  return "Too many failed login attempts. Please try again in {$minutes} minute(s).";
}

function lc_employee_login(string $username, string $password): bool {
  global $login_error_message;
  
  // Rate limiting check using username as identifier
  $rateLimitKey = 'login_' . strtolower($username);
  if (lc_is_rate_limited($rateLimitKey)) {
    $login_error_message = lc_get_rate_limit_message($rateLimitKey);
    return false;
  }
  
  lc_employee_session_start();
  $pdo = lc_pdo();
  $stmt = $pdo->prepare('SELECT id, password_hash, is_active FROM employees WHERE username = ? LIMIT 1');
  $stmt->execute([$username]);
  $row = $stmt->fetch();
  
  if (!$row) {
    // Record failed attempt even for non-existent users to prevent username enumeration
    lc_record_failed_attempt($rateLimitKey);
    return false;
  }
  
  // Check if account is active - if not active, only allow login if it's an admin account
  if ((int)$row['is_active'] !== 1) {
    $adminCheck = $pdo->prepare('SELECT is_admin, can_grant_admin FROM employees WHERE id = ? LIMIT 1');
    $adminCheck->execute([(int)$row['id']]);
    $adminRow = $adminCheck->fetch();
    $isAdmin = $adminRow && (((int)($adminRow['is_admin'] ?? 0)) === 1 || ((int)($adminRow['can_grant_admin'] ?? 0)) === 1);
    if (!$isAdmin) {
      $login_error_message = 'Your account is not active. Please contact an admin.';
      return false;
    }
  }
  
  if (!password_verify($password, (string)$row['password_hash'])) {
    // Record failed attempt
    lc_record_failed_attempt($rateLimitKey);
    return false;
  }

  // Prevent switching to another account in the same browser session.
  // PHP sessions are shared across tabs, so changing account here would also
  // change it in already-open tabs.
  $currentSessionEmployeeId = isset($_SESSION['lc_employee_id']) && is_int($_SESSION['lc_employee_id'])
    ? (int)$_SESSION['lc_employee_id']
    : null;
  $targetEmployeeId = (int)$row['id'];
  if ($currentSessionEmployeeId !== null && $currentSessionEmployeeId !== $targetEmployeeId) {
    $login_error_message = 'You are already signed in as another employee in this browser. Please log out first.';
    return false;
  }
  
  // Clear rate limit on successful login
  lc_clear_rate_limit($rateLimitKey);
  
  // Regenerate session ID to prevent session fixation attacks and ensure any existing session is not reused to prevent 
  // issues with concurrent logins and session data conflicts.
  session_regenerate_id(true);
  
  // Store employee ID and a login token
  $_SESSION['lc_employee_id'] = (int)$row['id'];
  $_SESSION['lc_login_time'] = time();
  $_SESSION['lc_login_ip'] = $_SERVER['REMOTE_ADDR'] ?? '';
  
  return true;
}

/**
 * Log the current employee out and destroy the PHP session. 
 * This is important for security to ensure that once an employee logs out, 
 * their session is completely cleared and cannot be reused by an attacker.
 */
function lc_employee_logout(): void {
  lc_employee_session_start();
  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool)$params['secure'], (bool)$params['httponly']);
  }
  session_destroy();
}

/**
 * Register a new employee.
 *
 * Returns ['ok' => true] on success, or ['ok' => false, 'error' => 'message'] on failure.
 */
function lc_employee_register(string $username, string $displayName, string $password): array {
  $username = trim($username);
  $displayName = trim($displayName);

  if ($username === '') {
    return ['ok' => false, 'error' => 'Username is required.'];
  }
  if (strlen($username) > 64) {
    return ['ok' => false, 'error' => 'Username must be 64 characters or less.'];
  }
  if ($displayName === '') {
    return ['ok' => false, 'error' => 'Display name is required.'];
  }
  if (strlen($displayName) > 128) {
    return ['ok' => false, 'error' => 'Display name must be 128 characters or less.'];
  }
  if (strlen($password) < 8) {
    return ['ok' => false, 'error' => 'Password must be at least 8 characters.'];
  }
  
  // Check for password complexity (at least one letter and one number)
  if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
    return ['ok' => false, 'error' => 'Password must contain at least one letter and one number.'];
  }

  $pdo = lc_pdo();
  
  // Check if username already exists so there are no duplicate usernames.
  $stmt = $pdo->prepare('SELECT id FROM employees WHERE username = ?');
  $stmt->execute([$username]);
  if ($stmt->fetch()) {
    return ['ok' => false, 'error' => 'Username already exists.'];
  }

  $passwordHash = password_hash($password, PASSWORD_DEFAULT);
  
  // Create new employee as inactive by default - admin must approve for them to get permissions.
  $stmt = $pdo->prepare('INSERT INTO employees (username, password_hash, display_name, is_active) VALUES (?, ?, ?, 0)');
  $stmt->execute([$username, $passwordHash, $displayName]);

  $newEmployeeId = (int)$pdo->lastInsertId();

  // Ensure channel access table exists and grant all channels by default.
  $pdo->exec("CREATE TABLE IF NOT EXISTS employee_channel_access (
    employee_id INT UNSIGNED NOT NULL,
    source_channel ENUM('website','whatsapp','email','facebook','instagram','other') NOT NULL,
    PRIMARY KEY (employee_id, source_channel),
    KEY idx_employee_channel_access_channel (source_channel),
    CONSTRAINT fk_employee_channel_access_employee FOREIGN KEY (employee_id) REFERENCES employees(id)
      ON UPDATE CASCADE ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

  $insAccess = $pdo->prepare('INSERT IGNORE INTO employee_channel_access (employee_id, source_channel) VALUES (?, ?)');
  foreach (['website', 'whatsapp', 'email', 'facebook', 'instagram', 'other'] as $channel) {
    $insAccess->execute([$newEmployeeId, $channel]);
  }

  return ['ok' => true, 'employee_id' => $newEmployeeId];
}

