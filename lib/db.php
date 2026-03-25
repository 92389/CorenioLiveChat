<?php
declare(strict_types=1);

/**
 * Load application configuration (DB credentials, cookie name, ws_url, ...).
 *
 * The underlying PHP file is only loaded once; subsequent calls reuse the
 * cached array stored in a static variable.
 *
 * @return array<string,mixed>
 */
function lc_config(): array {
  static $cfg = null;
  if ($cfg === null) {
    $cfg = require __DIR__ . '/../config/config.php';
  }
  return $cfg;
}

/**
 * Shared PDO instance configured for the Live Chat database.
 *
 * - Uses utf8mb4 for full Unicode support.
 * - Throws exceptions on SQL errors.
 * - Returns rows as associative arrays.
 * - Disables emulated prepares for safer parameter binding.
 */
function lc_pdo(): PDO {
  static $pdo = null;
  if ($pdo instanceof PDO) return $pdo;

  $db = lc_config()['db'];
  $dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $db['host'],
    (int)$db['port'],
    $db['name'],
    $db['charset']
  );

  $pdo = new PDO($dsn, $db['user'], $db['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ]);

  return $pdo;
}

/**
 * Helper for running a callback inside a DB transaction.
 *
 * Any exception thrown inside the callback will roll back the transaction and
 * be re-thrown to the caller.
 *
 * @template T
 * @param callable(PDO):T $fn
 * @return T
 */
function lc_tx(callable $fn) {
  $pdo = lc_pdo();
  $pdo->beginTransaction();
  try {
    $res = $fn($pdo);
    $pdo->commit();
    return $res;
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}

