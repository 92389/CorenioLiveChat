# db/schema.sql

## Purpose
- Primary SQL schema used to create required database tables/indices.

## File Type
- Extension: `.sql`
- Location: `db/schema.sql`

## Header/Top Context
```text
-- Live Chat schema (MySQL / MariaDB)
-- Create a database first, e.g. CREATE DATABASE live_chat CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- Then run: USE live_chat; SOURCE db/schema.sql;

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS employees (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(64) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  display_name VARCHAR(128) NOT NULL,
```

## Related Files
- Refer to `PROJECT_DOCUMENTATION.md` and `FILE_FUNCTIONS_DOCUMENTATION.md` for system overview.
