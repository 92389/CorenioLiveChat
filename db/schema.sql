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
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  -- Admin / permissions flags
  -- `is_admin`        → this employee has admin privileges inside the app
  -- `can_grant_admin` → this employee is allowed to change `is_admin` for others
  -- `can_access_all_channels` → this employee can use the "All Channels" filter and access all channels
  -- In a typical setup there is exactly one row with `can_grant_admin = 1`.
  is_admin TINYINT(1) NOT NULL DEFAULT 0,
  can_grant_admin TINYINT(1) NOT NULL DEFAULT 0,
  can_access_all_channels TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_employees_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tags (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(64) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tags_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chats (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  customer_token CHAR(36) NOT NULL,
  customer_name VARCHAR(128) NULL,
  source_channel ENUM('website','whatsapp','email','facebook','instagram','other') NOT NULL DEFAULT 'website',
  status ENUM('open','taken','closed') NOT NULL DEFAULT 'open',
  assigned_employee_id INT UNSIGNED NULL,
  reopened_count INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  closed_at TIMESTAMP NULL,
  PRIMARY KEY (id),
  KEY idx_chats_customer_token (customer_token),
  KEY idx_chats_status_updated (status, updated_at),
  KEY idx_chats_assigned (assigned_employee_id),
  CONSTRAINT fk_chats_employee FOREIGN KEY (assigned_employee_id) REFERENCES employees(id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_tags (
  chat_id BIGINT UNSIGNED NOT NULL,
  tag_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (chat_id, tag_id),
  KEY idx_chat_tags_tag (tag_id),
  CONSTRAINT fk_chat_tags_chat FOREIGN KEY (chat_id) REFERENCES chats(id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_chat_tags_tag FOREIGN KEY (tag_id) REFERENCES tags(id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  chat_id BIGINT UNSIGNED NOT NULL,
  sender_type ENUM('customer','employee','system') NOT NULL,
  sender_employee_id INT UNSIGNED NULL,
  body TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_messages_chat_id_id (chat_id, id),
  CONSTRAINT fk_messages_chat FOREIGN KEY (chat_id) REFERENCES chats(id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_messages_employee FOREIGN KEY (sender_employee_id) REFERENCES employees(id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS message_files (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Track what each employee has seen (for notification badges)
CREATE TABLE IF NOT EXISTS employee_chat_reads (
  employee_id INT UNSIGNED NOT NULL,
  chat_id BIGINT UNSIGNED NOT NULL,
  last_seen_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (employee_id, chat_id),
  KEY idx_employee_chat_reads_chat (chat_id),
  CONSTRAINT fk_employee_chat_reads_employee FOREIGN KEY (employee_id) REFERENCES employees(id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_employee_chat_reads_chat FOREIGN KEY (chat_id) REFERENCES chats(id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-employee channel access permissions
CREATE TABLE IF NOT EXISTS employee_channel_access (
  employee_id INT UNSIGNED NOT NULL,
  source_channel ENUM('website','whatsapp','email','facebook','instagram','other') NOT NULL,
  PRIMARY KEY (employee_id, source_channel),
  KEY idx_employee_channel_access_channel (source_channel),
  CONSTRAINT fk_employee_channel_access_employee FOREIGN KEY (employee_id) REFERENCES employees(id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed tags (edit as you like)
INSERT IGNORE INTO tags (name, sort_order) VALUES
  ('Billing', 10),
  ('Technical issue', 20),
  ('Account/Login', 30),
  ('Sales', 40),
  ('Other', 50);

-- Seed employee:
-- username: admin
-- password: admin123
-- NOTE: Change this immediately in production.
INSERT IGNORE INTO employees (username, password_hash, display_name, is_active, is_admin, can_grant_admin)
VALUES (
  'admin',
  '$2y$10$ssRy.6x94FapLQvha12fROQFuz6sF4KRtpI9s3nvdY6G7vAj3F0/u',
  'Admin',
  1, -- is_active
  1, -- is_admin
  1  -- can_grant_admin (primary admin account)
);

-- If you are upgrading an existing database that was created before these
-- admin fields existed, run the following once:
--   ALTER TABLE employees
--     ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active,
--     ADD COLUMN can_grant_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER is_admin;
--   UPDATE employees SET is_admin = 1, can_grant_admin = 1 WHERE username = 'admin';


