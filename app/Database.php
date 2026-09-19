<?php
declare(strict_types=1);

namespace Wwm;

use PDO;

final class Database
{
    public const SCHEMA_VERSION = 24;

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
        if (self::installedSchemaVersion($pdo) >= self::SCHEMA_VERSION) {
            self::persistSchemaVersion($pdo);

            return;
        }

        self::migrate($pdo);
        self::persistSchemaVersion($pdo);
    }

    /** Schema marker in SQLite survives FTP deploy (unlike data/.schema_version on some hosts). */
    public static function installedSchemaVersion(PDO $pdo): int
    {
        $versionFile = WWM_ROOT . '/data/.schema_version';
        $fromFile = is_readable($versionFile) ? (int)trim((string)file_get_contents($versionFile)) : 0;
        $fromDb = (int)$pdo->query('PRAGMA user_version')->fetchColumn();

        return max($fromFile, $fromDb);
    }

    public static function persistSchemaVersion(PDO $pdo): void
    {
        $target = self::SCHEMA_VERSION;
        if ((int)$pdo->query('PRAGMA user_version')->fetchColumn() < $target) {
            $pdo->exec('PRAGMA user_version = ' . $target);
        }
        $versionFile = WWM_ROOT . '/data/.schema_version';
        if (!is_readable($versionFile) || (int)trim((string)file_get_contents($versionFile)) < $target) {
            @file_put_contents($versionFile, (string)$target);
        }
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
        self::ensureColumn($pdo, 'users', 'admin_super', 'INTEGER NOT NULL DEFAULT 0');
        self::ensureColumn($pdo, 'users', 'admin_students', 'INTEGER NOT NULL DEFAULT 0');
        self::ensureColumn($pdo, 'users', 'admin_courses', 'INTEGER NOT NULL DEFAULT 0');
        $pdo->exec(
            'UPDATE users SET admin_students = 1, admin_courses = 1
             WHERE is_admin = 1 AND admin_super = 0 AND admin_students = 0 AND admin_courses = 0'
        );
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
        self::ensureColumn($pdo, 'users', 'avo_ad_snapshot', 'TEXT');
        self::ensureColumn($pdo, 'users', 'avo_contact_registered_at', 'TEXT');
        self::ensureColumn($pdo, 'users', 'avo_first_order_at', 'TEXT');
        self::ensureColumn($pdo, 'access', 'avo_ordered_at', 'TEXT');
        self::ensureColumn($pdo, 'access', 'avo_paid_at', 'TEXT');
        self::ensureColumn($pdo, 'users', 'registration_source', 'TEXT NOT NULL DEFAULT \'\'');

        $pdo->exec(
            'UPDATE users SET registration_source = \'csv-import\' WHERE registration_source = \'\''
            . ' AND id IN (SELECT DISTINCT user_id FROM access WHERE source = \'csv-import\')'
        );
        $pdo->exec(
            'UPDATE users SET registration_source = \'avo-import\' WHERE registration_source = \'\''
            . ' AND id IN (SELECT DISTINCT user_id FROM access WHERE source = \'avo-import\')'
        );

        self::ensureColumn($pdo, 'users', 'admin_broadcasts', 'INTEGER NOT NULL DEFAULT 0');
        $pdo->exec('UPDATE users SET admin_broadcasts = 1 WHERE admin_super = 1');

        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS email_suppressions (
  email TEXT NOT NULL PRIMARY KEY COLLATE NOCASE,
  user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  source TEXT NOT NULL DEFAULT 'unsubscribe',
  created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_email_suppressions_user ON email_suppressions(user_id);

CREATE TABLE IF NOT EXISTS email_broadcasts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL DEFAULT '',
  subject TEXT NOT NULL,
  body_text TEXT NOT NULL,
  body_html TEXT NOT NULL DEFAULT '',
  audience TEXT NOT NULL DEFAULT 'all_students',
  status TEXT NOT NULL CHECK (status IN ('draft', 'scheduled', 'sending', 'sent', 'cancelled')),
  scheduled_at TEXT,
  started_at TEXT,
  completed_at TEXT,
  created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  recipients_total INTEGER NOT NULL DEFAULT 0,
  sent_count INTEGER NOT NULL DEFAULT 0,
  failed_count INTEGER NOT NULL DEFAULT 0,
  skipped_unsub_count INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_email_broadcasts_status ON email_broadcasts(status);
CREATE INDEX IF NOT EXISTS idx_email_broadcasts_scheduled ON email_broadcasts(scheduled_at);

CREATE TABLE IF NOT EXISTS broadcast_recipients (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  broadcast_id INTEGER NOT NULL REFERENCES email_broadcasts(id) ON DELETE CASCADE,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  email TEXT NOT NULL COLLATE NOCASE,
  status TEXT NOT NULL CHECK (status IN ('pending', 'sent', 'failed', 'skipped')),
  error_message TEXT,
  sent_at TEXT,
  UNIQUE(broadcast_id, user_id)
);

CREATE INDEX IF NOT EXISTS idx_broadcast_recipients_broadcast ON broadcast_recipients(broadcast_id);
CREATE INDEX IF NOT EXISTS idx_broadcast_recipients_pending ON broadcast_recipients(broadcast_id, status);
SQL);

        self::ensureColumn($pdo, 'email_broadcasts', 'audience_filter_json', 'TEXT NOT NULL DEFAULT \'\'');
        self::ensureColumn($pdo, 'email_messages', 'broadcast_id', 'INTEGER REFERENCES email_broadcasts(id) ON DELETE SET NULL');
        self::ensureColumn($pdo, 'broadcast_recipients', 'email_message_id', 'INTEGER REFERENCES email_messages(id) ON DELETE SET NULL');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_email_messages_broadcast ON email_messages(broadcast_id)');

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

CREATE TABLE IF NOT EXISTS site_settings (
  setting_key TEXT PRIMARY KEY,
  setting_value TEXT NOT NULL DEFAULT '',
  updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS payments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  avo_account_id TEXT NOT NULL,
  course_slug TEXT NOT NULL,
  id_goods INTEGER,
  amount REAL,
  currency TEXT NOT NULL DEFAULT '',
  source TEXT NOT NULL DEFAULT 'avo',
  ordered_at TEXT,
  paid_at TEXT,
  utm_source TEXT,
  utm_medium TEXT,
  utm_campaign TEXT,
  utm_term TEXT,
  utm_content TEXT,
  ad_snapshot TEXT,
  avo_contact_id INTEGER,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL,
  UNIQUE(avo_account_id, course_slug)
);

CREATE INDEX IF NOT EXISTS idx_payments_user ON payments(user_id);
CREATE INDEX IF NOT EXISTS idx_payments_paid_at ON payments(paid_at);

CREATE TABLE IF NOT EXISTS email_automations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  slug TEXT NOT NULL UNIQUE,
  title TEXT NOT NULL,
  description TEXT NOT NULL DEFAULT '',
  course_slug TEXT NOT NULL DEFAULT '',
  is_active INTEGER NOT NULL DEFAULT 0,
  definition_json TEXT NOT NULL,
  avo_export_json TEXT,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_email_automations_course ON email_automations(course_slug);
CREATE INDEX IF NOT EXISTS idx_email_automations_active ON email_automations(is_active);

CREATE TABLE IF NOT EXISTS email_automation_runs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  automation_id INTEGER NOT NULL REFERENCES email_automations(id) ON DELETE CASCADE,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  course_slug TEXT NOT NULL,
  status TEXT NOT NULL CHECK (status IN ('active', 'completed', 'cancelled')),
  current_node_id TEXT NOT NULL,
  next_run_at TEXT,
  context_json TEXT NOT NULL DEFAULT '{}',
  enrolled_at TEXT NOT NULL,
  completed_at TEXT,
  updated_at TEXT NOT NULL,
  UNIQUE(automation_id, user_id)
);

CREATE INDEX IF NOT EXISTS idx_email_automation_runs_due ON email_automation_runs(status, next_run_at);
CREATE INDEX IF NOT EXISTS idx_email_automation_runs_user ON email_automation_runs(user_id);

CREATE TABLE IF NOT EXISTS email_automation_step_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  automation_id INTEGER NOT NULL REFERENCES email_automations(id) ON DELETE CASCADE,
  run_id INTEGER NOT NULL REFERENCES email_automation_runs(id) ON DELETE CASCADE,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  node_id TEXT NOT NULL,
  node_type TEXT NOT NULL DEFAULT '',
  branch TEXT,
  detail TEXT,
  created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_automation_step_events_flow ON email_automation_step_events(automation_id, node_id);
CREATE INDEX IF NOT EXISTS idx_automation_step_events_run ON email_automation_step_events(run_id);
SQL);

        self::seedEmailAutomations($pdo);
        self::migrateEmailTemplatesLogo($pdo);
    }

    private static function seedEmailAutomations(PDO $pdo): void
    {
        $stmt = $pdo->query('SELECT COUNT(*) FROM email_automations');
        if ($stmt && (int)$stmt->fetchColumn() > 0) {
            return;
        }

        $path = WWM_ROOT . '/data/automations/elke-en-demo-subscription.v1.json';
        if (!is_readable($path)) {
            return;
        }

        $definition = file_get_contents($path);
        if ($definition === false || trim($definition) === '') {
            return;
        }

        $now = gmdate('c');
        $pdo->prepare(
            'INSERT INTO email_automations (slug, title, description, course_slug, is_active, definition_json, avo_export_json, created_at, updated_at)
             VALUES (?, ?, ?, ?, 0, ?, NULL, ?, ?)'
        )->execute([
            'elke-en-demo-subscription',
            'Elke demo funnel (subscription ENG)',
            'Cabinet automation migrated from AVO business process v.3',
            'elke-en',
            $definition,
            $now,
            $now,
        ]);
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
