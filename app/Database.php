<?php
declare(strict_types=1);

namespace Wwm;

use PDO;

final class Database
{
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

        return $pdo;
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
SQL);
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
