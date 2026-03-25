<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Generate a random RFC 4122 version 4 UUID string.
 */
function lc_uuid_v4(): string {
  $data = random_bytes(16);
  $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
  $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
  $hex = bin2hex($data);
  return sprintf('%s-%s-%s-%s-%s',
    substr($hex, 0, 8),
    substr($hex, 8, 4),
    substr($hex, 12, 4),
    substr($hex, 16, 4),
    substr($hex, 20, 12)
  );
}

/**
 * Retrieve (or lazily create) a stable customer token.
 *
 * The token is stored in a cookie (see `config.php` for the name) and used as
 * the primary identifier for chats on the customer side. If the cookie is
 * missing or invalid we generate a new UUID and set it for ~180 days.
 */
function lc_customer_token(): string {
  $cfg = lc_config();
  $cookie = $cfg['customer_cookie'] ?? 'lc_customer';

  $token = $_COOKIE[$cookie] ?? '';
  if (is_string($token) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $token)) {
    return strtolower($token);
  }

  $token = lc_uuid_v4();
  // 180 days
  setcookie($cookie, $token, [
    'expires' => time() + 60 * 60 * 24 * 180,
    'path' => '/',
    'secure' => false,
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
  $_COOKIE[$cookie] = $token;
  return $token;
}

