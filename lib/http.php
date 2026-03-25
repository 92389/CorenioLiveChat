<?php
declare(strict_types=1);

/**
 * Emit a JSON HTTP response and terminate the current script.
 *
 * All API endpoints in this project use a consistent envelope:
 *   { "ok": true|false, ... }
 */
function lc_json_response(array $payload, int $status = 200): void {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

/**
 * Enforce that the current request uses a specific HTTP method.
 *
 * On mismatch, a 405 JSON error is returned immediately to avoid crashes.
 */
function lc_require_method(string $method): void {
  if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== strtoupper($method)) {
    lc_json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
  }
}

/**
 * Read and decode a JSON payload from php://input.
 *
 * Returns an empty array if the body is empty, and sends a 400 JSON response
 * if the body cannot be decoded to an array.
 */
function lc_read_json_body(): array {
  $raw = file_get_contents('php://input');
  if ($raw === false || trim($raw) === '') return [];
  $data = json_decode($raw, true);
  if (!is_array($data)) {
    lc_json_response(['ok' => false, 'error' => 'Invalid JSON'], 400);
  }
  return $data;
}

/**
 * Convenience helper for reading an integer from $_GET.
 */
function lc_get_int(string $key, int $default = 0): int {
  $v = $_GET[$key] ?? null;
  if ($v === null) return $default;
  if (!is_numeric($v)) return $default;
  return (int)$v;
}

/**
 * Convenience helper for reading a string from $_GET.
 */
function lc_get_str(string $key, string $default = ''): string {
  $v = $_GET[$key] ?? null;
  if (!is_string($v)) return $default;
  return $v;
}

