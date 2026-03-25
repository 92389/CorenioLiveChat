# api/bootstrap.php

## Purpose
- Shared API bootstrap and reusable helper functions for API endpoints.

## File Type
- Extension: `.php`
- Location: `api/bootstrap.php`

## Key Symbols
- `final class LcHttpException extends RuntimeException {`
- `public function __construct(int $status, string $message) {`
- `function lc_abort(int $status, string $message): void {`
- `function lc_fetch_all_tags(PDO $pdo): array {`
- `function lc_employee_is_admin(PDO $pdo, int $employeeId): bool {`
- `function lc_fetch_chat_tags(PDO $pdo, int $chatId): array {`
- `function lc_fetch_chat_messages(PDO $pdo, int $chatId, int $limit = 200): array {`
- `function lc_ensure_message_files_table(PDO $pdo): void {`
- `function lc_chat_files_base_dir(): string {`
- `function lc_ensure_chat_files_dir(): string {`
- `function lc_chat_file_constraints(): array {`
- `function lc_sanitize_upload_filename(string $name): string {`

## Header/Top Context
```text
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
```

## Related Files
- Often used with files under `lib/` and frontend scripts in `assets/`.
