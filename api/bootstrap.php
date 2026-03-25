<?php
declare(strict_types=1);

// Apply security headers for all API requests
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'");

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/customer.php';
require_once __DIR__ . '/../lib/employee_auth.php';

/**
 * Lightweight HTTP exception type used by the API layer.
 *
 * Throwing this instead of calling `lc_json_response()` directly allows
 * endpoint code to remain linear while still producing consistent responses
 * with the correct HTTP status code.
 */
final class LcHttpException extends RuntimeException {
  /** HTTP status code that should be sent with the response. */
  public int $status;

  public function __construct(int $status, string $message) {
    parent::__construct($message);
    $this->status = $status;
  }
}

/**
 * Abort the current request with a JSON error payload and proper status code.
 *
 * This is a helper for API handlers and is implemented by throwing
 * `LcHttpException` so that the global exception handler can format the
 * response.
 */
function lc_abort(int $status, string $message): void {
  throw new LcHttpException($status, $message);
}

// Global exception handler for the API endpoints.
// - `LcHttpException` → pass through the message and its status code
// - anything else     → hide details from the client and return HTTP 500
set_exception_handler(function (Throwable $e) {
  if ($e instanceof LcHttpException) {
    lc_json_response(['ok' => false, 'error' => $e->getMessage()], $e->status);
  }
  // Avoid leaking internal error details; log $e server-side if needed.
  lc_json_response(['ok' => false, 'error' => 'Server error'], 500);
});

/**
 * Fetch all active tags, ordered by sort_order then name.
 */
function lc_fetch_all_tags(PDO $pdo): array {
  $stmt = $pdo->query("SELECT id, name FROM tags WHERE is_active = 1 ORDER BY sort_order ASC, name ASC");
  return $stmt->fetchAll();
}

/**
 * Check if an employee has admin privileges.
 */
function lc_employee_is_admin(PDO $pdo, int $employeeId): bool {
  $stmt = $pdo->prepare("SELECT is_admin, can_grant_admin FROM employees WHERE id = ? LIMIT 1");
  $stmt->execute([$employeeId]);
  $row = $stmt->fetch();
  if (!$row) return false;
  return (int)$row['is_admin'] === 1 || (int)$row['can_grant_admin'] === 1;
}

/**
 * Fetch all tags currently associated with the given chat.
 */
function lc_fetch_chat_tags(PDO $pdo, int $chatId): array {
  $stmt = $pdo->prepare("
    SELECT t.id, t.name
    FROM chat_tags ct
    JOIN tags t ON t.id = ct.tag_id AND t.is_active = 1
    WHERE ct.chat_id = ?
    ORDER BY t.sort_order ASC, t.name ASC
  ");
  $stmt->execute([$chatId]);
  return $stmt->fetchAll();
}

/**
 * Fetch up to `$limit` most recent messages for a chat, newest last.
 *
 * The limit is clamped between 1 and 500 so callers cannot request unbounded
 * history and accidentally overload the page.
 */
function lc_fetch_chat_messages(PDO $pdo, int $chatId, int $limit = 200): array {
  // Defensive cap on limit to keep payloads reasonably sized.
  $limit = max(1, min(500, $limit));
  lc_ensure_message_files_table($pdo);
  $stmt = $pdo->prepare("
    SELECT 
      m.id, 
      m.chat_id, 
      m.sender_type, 
      m.sender_employee_id, 
      m.body, 
      m.created_at, 
      m.customer_read_at, 
      m.employee_read_at,
      e.username AS employee_username,
      e.display_name AS employee_display_name,
      mf.id AS file_id,
      mf.original_name AS file_name,
      mf.mime_type AS file_mime,
      mf.size_bytes AS file_size_bytes
    FROM messages m
    LEFT JOIN employees e ON e.id = m.sender_employee_id
    LEFT JOIN message_files mf ON mf.message_id = m.id
    WHERE m.chat_id = ?
    ORDER BY m.id DESC
    LIMIT $limit
  ");
  $stmt->execute([$chatId]);
  $rows = $stmt->fetchAll();
  return array_reverse($rows);
}

function lc_ensure_message_files_table(PDO $pdo): void {
  $pdo->exec("CREATE TABLE IF NOT EXISTS message_files (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    message_id BIGINT UNSIGNED NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    storage_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL,
    storage_path VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_message_files_message_id (message_id),
    KEY idx_message_files_storage_name (storage_name),
    CONSTRAINT fk_message_files_message FOREIGN KEY (message_id) REFERENCES messages(id)
      ON UPDATE CASCADE ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function lc_chat_files_base_dir(): string {
  return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'chat_files';
}

function lc_ensure_chat_files_dir(): string {
  $primary = lc_chat_files_base_dir();
  $fallback = rtrim(sys_get_temp_dir(), "\\/") . DIRECTORY_SEPARATOR . 'live_chat_uploads' . DIRECTORY_SEPARATOR . 'chat_files';
  foreach ([$primary, $fallback] as $dir) {
    if (!is_dir($dir)) {
      @mkdir($dir, 0775, true);
    }
    if (is_dir($dir) && is_writable($dir)) {
      return $dir;
    }
  }
  lc_abort(500, 'Upload directory is not writable');
}

function lc_chat_file_constraints(): array {
  return [
    'max_bytes' => 10 * 1024 * 1024,
    'allowed_mimes' => [
      'image/jpeg',
      'image/png',
      'image/gif',
      'image/webp',
      'application/pdf',
      'text/plain',
      'application/zip',
      'application/x-zip-compressed',
      'application/msword',
      'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
      'application/vnd.ms-excel',
      'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ],
    'allowed_extensions' => [
      'jpg', 'jpeg', 'png', 'gif', 'webp',
      'pdf', 'txt', 'zip', 'doc', 'docx', 'xls', 'xlsx',
    ],
  ];
}

function lc_sanitize_upload_filename(string $name): string {
  $name = trim($name);
  if ($name === '') return 'file';
  $name = str_replace(["\r", "\n", "\0"], '', $name);
  $name = preg_replace('/[^A-Za-z0-9._ -]/', '_', $name) ?? 'file';
  $name = preg_replace('/\s+/', ' ', $name) ?? 'file';
  return mb_substr($name, 0, 255);
}

function lc_upload_extension_mime_map(): array {
  return [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'pdf' => 'application/pdf',
    'txt' => 'text/plain',
    'zip' => 'application/zip',
    'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls' => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
  ];
}

function lc_detect_upload_mime(string $tmpPath, string $ext): string {
  $extMap = lc_upload_extension_mime_map();

  if (function_exists('finfo_open')) {
    $finfo = @finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo !== false) {
      $detected = (string)@finfo_file($finfo, $tmpPath);
      @finfo_close($finfo);
      if ($detected !== '') {
        return $detected;
      }
    }
  }

  if (function_exists('mime_content_type')) {
    $detected = (string)@mime_content_type($tmpPath);
    if ($detected !== '') {
      return $detected;
    }
  }

  return $extMap[$ext] ?? '';
}

function lc_is_absolute_path(string $path): bool {
  if ($path === '') return false;
  if ($path[0] === '/' || $path[0] === '\\') return true;
  return (bool)preg_match('/^[A-Za-z]:[\\\/]/', $path);
}

function lc_store_uploaded_chat_file(array $file): array {
  if (!isset($file['error']) || (int)$file['error'] !== UPLOAD_ERR_OK) {
    lc_abort(400, 'Invalid file upload');
  }
  if (!isset($file['tmp_name']) || !is_string($file['tmp_name']) || $file['tmp_name'] === '') {
    lc_abort(400, 'Invalid file upload');
  }
  if (!is_uploaded_file($file['tmp_name'])) {
    lc_abort(400, 'Invalid upload source');
  }

  $constraints = lc_chat_file_constraints();
  $size = isset($file['size']) ? (int)$file['size'] : 0;
  if ($size <= 0 || $size > (int)$constraints['max_bytes']) {
    lc_abort(400, 'File size must be between 1 byte and 10 MB');
  }

  $originalName = lc_sanitize_upload_filename((string)($file['name'] ?? 'file'));
  $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
  if ($ext === '' || !in_array($ext, $constraints['allowed_extensions'], true)) {
    lc_abort(400, 'Unsupported file type');
  }

  $mime = lc_detect_upload_mime($file['tmp_name'], $ext);
  if ($mime === '' || !in_array($mime, $constraints['allowed_mimes'], true)) {
    lc_abort(400, 'Unsupported file type');
  }

  $dir = lc_ensure_chat_files_dir();
  $storageName = bin2hex(random_bytes(16)) . '.' . $ext;
  $fullPath = $dir . DIRECTORY_SEPARATOR . $storageName;
  if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
    lc_abort(500, 'Failed to store uploaded file');
  }

  $projectRoot = dirname(__DIR__);
  $storagePath = str_starts_with($fullPath, $projectRoot . DIRECTORY_SEPARATOR)
    ? str_replace(DIRECTORY_SEPARATOR, '/', substr($fullPath, strlen($projectRoot) + 1))
    : $fullPath;

  return [
    'original_name' => $originalName,
    'storage_name' => $storageName,
    'mime_type' => $mime,
    'size_bytes' => $size,
    'storage_path' => $storagePath,
    'full_path' => $fullPath,
  ];
}

function lc_output_chat_file_download(array $file, bool $inline = false): void {
  $safeName = lc_sanitize_upload_filename((string)($file['original_name'] ?? 'download'));
  $storagePath = (string)($file['storage_path'] ?? '');
  $fullPath = lc_is_absolute_path($storagePath)
    ? $storagePath
    : dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $storagePath);
  if (!is_file($fullPath)) {
    lc_abort(404, 'File not found');
  }

  $mime = (string)($file['mime_type'] ?? 'application/octet-stream');
  $size = (int)($file['size_bytes'] ?? filesize($fullPath));
  header('Content-Type: ' . $mime);
  header('Content-Length: ' . (string)$size);
  $dispositionType = $inline ? 'inline' : 'attachment';
  header('Content-Disposition: ' . $dispositionType . '; filename="' . addslashes($safeName) . '"');
  header('Cache-Control: private, no-store, no-cache, must-revalidate');
  header('Pragma: no-cache');
  readfile($fullPath);
  exit;
}

