<?php
declare(strict_types=1);

namespace Wwm;

use PDO;

final class Database
{
    public const SCHEMA_VERSION = 14;

    public static function connect(string $path): PDO
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        return $pdo;
    }

    public static function migrateIfNeeded(PDO $pdo): void
    {
        $versionFile = WWM_ROOT . '/data/.schema_version';
        $current = is_readable($versionFile) ? (int)file_get_contents($versionFile) : 0;
        if ($current >= self::SCHEMA_VERSION) {
            return;
        }

        self::migrate($pdo);
        @file_put_contents($versionFile, (string)self::SCHEMA_VERSION);
    }

    public static function migrate(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email TEXT NOT NULL UNIQUE COLLATE NOCASE,
  password_hash TEXT,
  name TEXT DEFAULT '',
  created_at TEXT NOT NULL,
  last_login_at TEXT
);

CREATE TABLE IF NOT EXISTS access (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  course_slug TEXT NOT NULL,
  access_type TEXT NOT NULL CHECK (access_type IN ('demo', 'paid')),
  granted_at TEXT NOT NULL,
  expires_at TEXT,
  source TEXT,
  source_ref TEXT,
  UNIQUE(user_id, course_slug, access_type)
);

CREATE INDEX IF NOT EXISTS idx_access_user ON access(user_id);
CREATE INDEX IF NOT EXISTS idx_access_expires ON access(expires_at);

CREATE TABLE IF NOT EXISTS password_resets (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  token_hash TEXT NOT NULL UNIQUE,
  expires_at TEXT NOT NULL,
  used_at TEXT
);

CREATE TABLE IF NOT EXISTS processed_events (
  event_key TEXT PRIMARY KEY,
  payload TEXT,
  created_at TEXT NOT NULL
);
SQL);

        self::ensureColumn($pdo, 'users', 'is_admin', 'INTEGER NOT NULL DEFAULT 0');
        self::ensureColumn($pdo, 'users', 'signup_ip', 'TEXT');
        self::ensureColumn($pdo, 'users', 'signup_country', 'TEXT');
        self::ensureColumn($pdo, 'users', 'signup_city', 'TEXT');
        self::ensureColumn($pdo, 'users', 'utm_source', 'TEXT');
        self::ensureColumn($pdo, 'users', 'utm_medium', 'TEXT');
        self::ensureColumn($pdo, 'users', 'utm_campaign', 'TEXT');
        self::ensureColumn($pdo, 'users', 'utm_term', 'TEXT');
        self::ensureColumn($pdo, 'users', 'utm_content', 'TEXT');
        self::ensureColumn($pdo, 'users', 'last_ip', 'TEXT');
        self::ensureColumn($pdo, 'users', 'last_country', 'TEXT');
        self::ensureColumn($pdo, 'users', 'last_city', 'TEXT');
        self::ensureColumn($pdo, 'users', 'avo_contact_id', 'INTEGER');
        self::ensureColumn($pdo, 'users', 'avo_logged_in_tagged', 'INTEGER NOT NULL DEFAULT 0');
        self::ensureColumn($pdo, 'users', 'avo_demo_opened_tagged', 'INTEGER NOT NULL DEFAULT 0');

        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS lesson_opens (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  course_slug TEXT NOT NULL,
  lesson_num INTEGER NOT NULL,
  first_opened_at TEXT NOT NULL,
  last_opened_at TEXT NOT NULL,
  UNIQUE(user_id, course_slug, lesson_num)
);

CREATE INDEX IF NOT EXISTS idx_lesson_opens_user ON lesson_opens(user_id);
CREATE INDEX IF NOT EXISTS idx_lesson_opens_course ON lesson_opens(course_slug);
CREATE INDEX IF NOT EXISTS idx_lesson_opens_user_course ON lesson_opens(user_id, course_slug);

CREATE TABLE IF NOT EXISTS login_links (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  token_hash TEXT NOT NULL UNIQUE,
  next_path TEXT NOT NULL DEFAULT '/',
  expires_at TEXT NOT NULL,
  used_at TEXT,
  created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_login_links_user ON login_links(user_id);

CREATE TABLE IF NOT EXISTS email_messages (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  to_email TEXT NOT NULL COLLATE NOCASE,
  email_type TEXT NOT NULL,
  subject TEXT NOT NULL,
  status TEXT NOT NULL CHECK (status IN ('pending', 'sent', 'failed')),
  error_message TEXT,
  sent_at TEXT NOT NULL,
  open_token TEXT NOT NULL UNIQUE,
  opened_at TEXT,
  open_count INTEGER NOT NULL DEFAULT 0
);

CREATE INDEX IF NOT EXISTS idx_email_messages_user ON email_messages(user_id);
CREATE INDEX IF NOT EXISTS idx_email_messages_sent ON email_messages(sent_at);

CREATE TABLE IF NOT EXISTS email_links (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  message_id INTEGER NOT NULL REFERENCES email_messages(id) ON DELETE CASCADE,
  token TEXT NOT NULL UNIQUE,
  target_url TEXT NOT NULL,
  link_label TEXT NOT NULL DEFAULT '',
  clicked_at TEXT,
  click_count INTEGER NOT NULL DEFAULT 0
);

CREATE INDEX IF NOT EXISTS idx_email_links_message ON email_links(message_id);

CREATE TABLE IF NOT EXISTS email_templates (
  template_id TEXT PRIMARY KEY,
  subject TEXT NOT NULL,
  body_text TEXT NOT NULL,
  body_html TEXT,
  updated_at TEXT NOT NULL
);
SQL);

        self::migrateEmailTemplatesLogo($pdo);
    }

    private static function migrateEmailTemplatesLogo(PDO $pdo): void
    {
        $stmt = $pdo->query('SELECT template_id, body_html FROM email_templates WHERE body_html IS NOT NULL');
        $rows = $stmt ? $stmt->fetchAll() : [];
        if ($rows === []) {
            return;
        }

        $update = $pdo->prepare(
            'UPDATE email_templates SET body_html = ?, updated_at = ? WHERE template_id = ?'
        );
        foreach ($rows as $row) {
            $html = (string)($row['body_html'] ?? '');
            $simulated = str_replace('{{logo_url}}', wwm_email_logo_url(), $html);
            $normalized = wwm_repair_email_html($simulated);
            if ($normalized === $html) {
                $normalized = wwm_repair_email_html($html);
            }
            if ($normalized === $html) {
                continue;
            }
            $update->execute([
                $normalized,
                gmdate('c'),
                (string)$row['template_id'],
            ]);
        }
    }

    private static function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void
    {
        $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
        $columns = $stmt ? $stmt->fetchAll() : [];
        foreach ($columns as $col) {
            if (($col['name'] ?? '') === $column) {
                return;
            }
        }
        $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
    }
}
